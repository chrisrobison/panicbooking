<?php
// Venue handlers: list, get, update, delete

function handleVenuesList(PDO $pdo): void {
    $q            = trim($_GET['q'] ?? '');
    $neighborhood = trim($_GET['neighborhood'] ?? '');
    $genre        = trim($_GET['genre'] ?? '');
    $sort         = trim($_GET['sort'] ?? 'name');
    $capMin       = isset($_GET['cap_min']) ? (int)$_GET['cap_min'] : 0;
    $capMax       = isset($_GET['cap_max']) ? (int)$_GET['cap_max'] : 0;
    $lastMinute   = isset($_GET['last_minute']) && $_GET['last_minute'] === '1';
    $offset       = max(0, (int)($_GET['offset'] ?? 0));
    $limit        = min(50, max(1, (int)($_GET['limit'] ?? 24)));

    $params = [];
    $where  = ["u.type = 'venue'", "COALESCE(p.is_archived, 0) = 0"];

    if ($q !== '') {
        $where[]      = "p.data LIKE :q";
        $params[':q'] = '%' . $q . '%';
    }
    if ($neighborhood !== '') {
        $where[]       = "p.data LIKE :nb";
        $params[':nb'] = '%' . $neighborhood . '%';
    }
    if ($genre !== '') {
        $where[]          = "p.data LIKE :genre";
        $params[':genre'] = '%' . $genre . '%';
    }
    if ($capMin > 0) {
        $where[] = "CAST(json_extract(p.data, '$.capacity') AS INTEGER) >= {$capMin}";
    }
    if ($capMax > 0) {
        $where[] = "CAST(json_extract(p.data, '$.capacity') AS INTEGER) <= {$capMax}";
    }
    if ($lastMinute) {
        $where[] = "json_extract(p.data, '$.open_to_last_minute') = 1";
    }

    $allowedSorts = ['name','name_desc','capacity','capacity_desc','recent'];
    if (!in_array($sort, $allowedSorts)) $sort = 'name';
    $orderBy = match($sort) {
        'name'          => "json_extract(p.data, '$.name') COLLATE NOCASE ASC",
        'name_desc'     => "json_extract(p.data, '$.name') COLLATE NOCASE DESC",
        'capacity'      => "CAST(json_extract(p.data, '$.capacity') AS INTEGER) ASC",
        'capacity_desc' => "CAST(json_extract(p.data, '$.capacity') AS INTEGER) DESC",
        'recent'        => "u.created_at DESC",
    };

    $whereClause = implode(' AND ', $where);

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN profiles p ON p.user_id = u.id WHERE {$whereClause}");
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $venues = array_map(function($row) {
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

    jsonResponse(['venues' => $venues, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

function handleVenuesGet(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.type = 'venue' AND COALESCE(p.is_archived, 0) = 0
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('Venue not found', 404);
    }

    $data  = json_decode($row['data'], true) ?: [];
    $venue = array_merge([
        'id'         => (int)$row['id'],
        'email'      => $row['email'],
        'created_at' => $row['created_at'],
        'is_generic' => (bool)$row['is_generic'],
        'is_claimed' => (bool)$row['is_claimed'],
        'is_archived' => (bool)$row['is_archived'],
    ], $data);

    jsonResponse(['venue' => $venue]);
}

function handleVenuesUpdate(PDO $pdo, int $id): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'venue')) {
        errorResponse('Forbidden', 403);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $allowed = [
        'name','address','neighborhood','capacity','description','contact_email','contact_phone',
        'website','facebook','instagram','genres_welcomed','has_pa','has_drums','has_backline',
        'stage_size','cover_charge','bar_service','open_to_last_minute','booking_lead_time_days','notes'
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $data[$field] = $body[$field];
        }
    }

    // Type coercions
    if (isset($data['capacity'])) $data['capacity'] = (int)$data['capacity'];
    if (isset($data['booking_lead_time_days'])) $data['booking_lead_time_days'] = (int)$data['booking_lead_time_days'];
    foreach (['has_pa','has_drums','has_backline','cover_charge','bar_service','open_to_last_minute'] as $f) {
        if (isset($data[$f])) $data[$f] = (bool)$data[$f];
    }
    if (isset($data['genres_welcomed']) && !is_array($data['genres_welcomed'])) {
        $data['genres_welcomed'] = [];
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

    jsonResponse(['success' => true, 'venue' => array_merge(['id' => $id], $merged)]);
}

function handleVenuesDelete(PDO $pdo, int $id): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'venue')) {
        errorResponse('Forbidden', 403);
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    // Only log out if the user deleted their own account
    if ($current['id'] === $id) {
        apiLogout();
    }
    jsonResponse(['success' => true]);
}
