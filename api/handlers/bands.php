<?php
// Band handlers: list, get, update, delete

function handleBandsList(PDO $pdo): void {
    $q         = trim($_GET['q'] ?? '');
    $genre     = trim($_GET['genre'] ?? '');
    $sort      = trim($_GET['sort'] ?? 'name');
    $seeking   = isset($_GET['seeking']) && $_GET['seeking'] === '1';
    $scoreMin  = isset($_GET['score_min']) ? (int)$_GET['score_min'] : 0;
    $drawMin   = isset($_GET['draw_min'])  ? (int)$_GET['draw_min']  : 0;
    $offset    = max(0, (int)($_GET['offset'] ?? 0));
    $limit     = min(50, max(1, (int)($_GET['limit'] ?? 24)));

    $params = [];
    $where  = ["u.type = 'band'", "COALESCE(p.is_archived, 0) = 0"];

    if ($q !== '') {
        $where[]      = "p.data LIKE :q";
        $params[':q'] = '%' . $q . '%';
    }
    if ($genre !== '') {
        $where[]          = "p.data LIKE :genre";
        $params[':genre'] = '%' . $genre . '%';
    }
    if ($seeking) {
        $where[] = "json_extract(p.data, '$.seeking_gigs') = 1";
    }

    // Score/draw filters always need the JOIN
    $needsScoreJoin = in_array($sort, ['score','shows','draw']) || $scoreMin > 0 || $drawMin > 0;

    // Sort order
    $join    = '';
    $orderBy = "json_extract(p.data, '$.name') COLLATE NOCASE ASC";
    $allowedSorts = ['name','name_desc','score','shows','draw','recent','lastminute'];
    if (!in_array($sort, $allowedSorts)) $sort = 'name';

    if ($needsScoreJoin) {
        $join = "LEFT JOIN performer_scores ps ON ps.band_name = json_extract(p.data, '$.name')";
        $orderBy = match($sort) {
            'score' => 'COALESCE(ps.composite_score, 0) DESC, json_extract(p.data, \'$.name\') COLLATE NOCASE ASC',
            'shows' => 'COALESCE(ps.shows_tracked, 0) DESC, json_extract(p.data, \'$.name\') COLLATE NOCASE ASC',
            'draw'  => 'COALESCE(ps.estimated_draw, 0) DESC, json_extract(p.data, \'$.name\') COLLATE NOCASE ASC',
            default => 'json_extract(p.data, \'$.name\') COLLATE NOCASE ASC',
        };
        // Inline numeric literals — PDO binds as TEXT by default which breaks
        // SQLite REAL >= TEXT comparisons due to type affinity rules.
        if ($scoreMin > 0) {
            $where[] = "COALESCE(ps.composite_score, 0) >= {$scoreMin}";
        }
        if ($drawMin > 0) {
            $where[] = "COALESCE(ps.estimated_draw, 0) >= {$drawMin}";
        }
    } elseif ($sort === 'name_desc') {
        $orderBy = "json_extract(p.data, '$.name') COLLATE NOCASE DESC";
    } elseif ($sort === 'recent') {
        $orderBy = "u.created_at DESC";
    } elseif ($sort === 'lastminute') {
        $orderBy = "json_extract(p.data, '$.available_last_minute') DESC, json_extract(p.data, '$.name') COLLATE NOCASE ASC";
    }

    $whereClause = implode(' AND ', $where);

    $sql = "
        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        {$join}
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    // Total count (for pagination)
    $countSql = "SELECT COUNT(*) FROM users u JOIN profiles p ON p.user_id = u.id {$join} WHERE {$whereClause}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $bands = array_map(function($row) {
        $data = json_decode($row['data'], true) ?: [];
        return array_merge([
            'id'         => (int)$row['id'],
            'email'      => $row['email'],
            'created_at' => $row['created_at'],
            'is_generic' => (bool)$row['is_generic'],
            'is_claimed' => (bool)$row['is_claimed'],
            'is_archived' => (bool)$row['is_archived'],
        ], $data);
    }, $rows);

    jsonResponse(['bands' => $bands, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

function handleBandsGet(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.type = 'band' AND COALESCE(p.is_archived, 0) = 0
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('Band not found', 404);
    }

    $data = json_decode($row['data'], true) ?: [];
    $band = array_merge([
        'id'         => (int)$row['id'],
        'email'      => $row['email'],
        'created_at' => $row['created_at'],
        'is_generic' => (bool)$row['is_generic'],
        'is_claimed' => (bool)$row['is_claimed'],
        'is_archived' => (bool)$row['is_archived'],
    ], $data);

    jsonResponse(['band' => $band]);
}

function handleBandsUpdate(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($id <= 0) {
        errorResponse('Invalid band id', 422);
    }

    $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'band' LIMIT 1");
    $targetStmt->execute([$id]);
    if (!$targetStmt->fetchColumn()) {
        errorResponse('Band not found', 404);
    }

    // Own profile or admin
    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'band')) {
        errorResponse('Forbidden', 403);
    }

    $body = apiReadJsonBody();

    // Sanitize / whitelist fields
    $allowed = [
        'name','genres','members','description','contact_email','contact_phone',
        'website','facebook','instagram','spotify','youtube','location','experience',
        'set_length_min','set_length_max','has_own_equipment','available_last_minute','notes',
        'seeking_gigs','available_days','next_available','gig_radius','booking_contact'
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $data[$field] = $body[$field];
        }
    }

    // Type coercions
    if (isset($data['set_length_min'])) $data['set_length_min'] = (int)$data['set_length_min'];
    if (isset($data['set_length_max'])) $data['set_length_max'] = (int)$data['set_length_max'];
    if (isset($data['has_own_equipment'])) $data['has_own_equipment'] = (bool)$data['has_own_equipment'];
    if (isset($data['available_last_minute'])) $data['available_last_minute'] = (bool)$data['available_last_minute'];
    if (isset($data['genres']) && !is_array($data['genres'])) $data['genres'] = [];
    if (isset($data['members']) && !is_array($data['members'])) $data['members'] = [];
    if (isset($data['seeking_gigs'])) $data['seeking_gigs'] = (bool)$data['seeking_gigs'];
    if (isset($data['available_days']) && !is_array($data['available_days'])) $data['available_days'] = [];
    if (isset($data['contact_email'])) {
        $data['contact_email'] = strtolower(trim((string)$data['contact_email']));
        if ($data['contact_email'] !== '' && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('contact_email is invalid', 422);
        }
    }
    if (isset($data['set_length_min']) && ($data['set_length_min'] < 0 || $data['set_length_min'] > 600)) {
        errorResponse('set_length_min is out of range', 422);
    }
    if (isset($data['set_length_max']) && ($data['set_length_max'] < 0 || $data['set_length_max'] > 600)) {
        errorResponse('set_length_max is out of range', 422);
    }
    if (isset($data['set_length_min'], $data['set_length_max']) && $data['set_length_max'] > 0 && $data['set_length_min'] > $data['set_length_max']) {
        errorResponse('set_length_min cannot exceed set_length_max', 422);
    }

    // Load existing, merge
    $stmt = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ?");
    $stmt->execute([$id]);
    $existing = json_decode($stmt->fetchColumn() ?: '{}', true) ?: [];
    $merged   = array_merge($existing, $data);

    $stmt = $pdo->prepare("
        UPDATE profiles SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?
    ");
    $stmt->execute([json_encode($merged), $id]);

    jsonResponse(['success' => true, 'band' => array_merge(['id' => $id], $merged)]);
}

function handleBandsDelete(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($id <= 0) {
        errorResponse('Invalid band id', 422);
    }

    $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'band' LIMIT 1");
    $targetStmt->execute([$id]);
    if (!$targetStmt->fetchColumn()) {
        errorResponse('Band not found', 404);
    }

    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'band')) {
        errorResponse('Forbidden', 403);
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        errorResponse('Band not found', 404);
    }

    // Only log out if the user deleted their own account
    if ($current['id'] === $id) {
        apiLogout();
    }
    jsonResponse(['success' => true]);
}
