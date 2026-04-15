<?php
// Recording label handlers: list, get, update, delete

function handleLabelsList(PDO $pdo): void {
    $q       = trim($_GET['q'] ?? '');
    $genre   = trim($_GET['genre'] ?? '');
    $sort    = trim($_GET['sort'] ?? 'name');
    $signing = isset($_GET['signing']) && $_GET['signing'] === '1';
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $limit   = min(50, max(1, (int)($_GET['limit'] ?? 24)));

    $params = [];
    $where  = ["u.type = 'recording_label'", "COALESCE(p.is_archived, 0) = 0"];
    $nameExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');
    $signingExpr = panicSqlJsonIntExpr($pdo, 'p.data', '$.actively_signing');
    $orderByNameAsc = panicSqlOrderByCi($nameExpr, 'ASC');
    $orderByNameDesc = panicSqlOrderByCi($nameExpr, 'DESC');

    if ($q !== '') {
        $where[] = 'p.data LIKE :q';
        $params[':q'] = '%' . $q . '%';
    }
    if ($genre !== '') {
        $where[] = 'p.data LIKE :genre';
        $params[':genre'] = '%' . $genre . '%';
    }
    if ($signing) {
        $where[] = "{$signingExpr} = 1";
    }

    $allowedSorts = ['name', 'name_desc', 'recent', 'signing'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'name';
    }
    $orderBy = match($sort) {
        'name_desc' => $orderByNameDesc,
        'recent' => 'u.created_at DESC',
        'signing' => "{$signingExpr} DESC, {$orderByNameAsc}",
        default => $orderByNameAsc,
    };

    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN profiles p ON p.user_id = u.id WHERE {$whereClause}");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("\n        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived\n        FROM users u\n        JOIN profiles p ON p.user_id = u.id\n        WHERE {$whereClause}\n        ORDER BY {$orderBy}\n        LIMIT :limit OFFSET :offset\n    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $labels = array_map(static function(array $row): array {
        $data = json_decode($row['data'], true) ?: [];
        return array_merge([
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'created_at' => (string)$row['created_at'],
            'is_generic' => (bool)$row['is_generic'],
            'is_claimed' => (bool)$row['is_claimed'],
            'is_archived' => (bool)$row['is_archived'],
        ], $data);
    }, $rows);

    jsonResponse(['labels' => $labels, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

function handleLabelsGet(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("\n        SELECT u.id, u.email, u.created_at, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived\n        FROM users u\n        JOIN profiles p ON p.user_id = u.id\n        WHERE u.id = ? AND u.type = 'recording_label' AND COALESCE(p.is_archived, 0) = 0\n    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('Recording label not found', 404);
    }

    $data = json_decode($row['data'], true) ?: [];
    $label = array_merge([
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'created_at' => (string)$row['created_at'],
        'is_generic' => (bool)$row['is_generic'],
        'is_claimed' => (bool)$row['is_claimed'],
        'is_archived' => (bool)$row['is_archived'],
    ], $data);

    jsonResponse(['label' => $label]);
}

function handleLabelsUpdate(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($id <= 0) {
        errorResponse('Invalid recording label id', 422);
    }

    $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'recording_label' LIMIT 1");
    $targetStmt->execute([$id]);
    if (!$targetStmt->fetchColumn()) {
        errorResponse('Recording label not found', 404);
    }

    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'recording_label')) {
        errorResponse('Forbidden', 403);
    }

    $body = apiReadJsonBody();

    $allowed = [
        'name', 'city', 'description', 'contact_email', 'contact_phone',
        'website', 'instagram', 'genres_focus', 'roster_highlights',
        'submission_email', 'submission_url', 'preferred_venue_sizes',
        'actively_signing', 'attends_live_shows', 'notes',
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $data[$field] = $body[$field];
        }
    }

    foreach (['genres_focus', 'roster_highlights', 'preferred_venue_sizes'] as $arrayField) {
        if (isset($data[$arrayField]) && !is_array($data[$arrayField])) {
            $data[$arrayField] = [];
        }
    }
    foreach (['actively_signing', 'attends_live_shows'] as $boolField) {
        if (isset($data[$boolField])) {
            $data[$boolField] = (bool)$data[$boolField];
        }
    }

    foreach (['contact_email', 'submission_email'] as $emailField) {
        if (isset($data[$emailField])) {
            $data[$emailField] = strtolower(trim((string)$data[$emailField]));
            if ($data[$emailField] !== '' && !filter_var($data[$emailField], FILTER_VALIDATE_EMAIL)) {
                errorResponse($emailField . ' is invalid', 422);
            }
        }
    }

    $stmt = $pdo->prepare('SELECT data FROM profiles WHERE user_id = ?');
    $stmt->execute([$id]);
    $existing = json_decode($stmt->fetchColumn() ?: '{}', true) ?: [];
    $merged = array_merge($existing, $data);

    $updateStmt = $pdo->prepare('UPDATE profiles SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
    $updateStmt->execute([json_encode($merged), $id]);

    jsonResponse(['success' => true, 'label' => array_merge(['id' => $id], $merged)]);
}

function handleLabelsDelete(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($id <= 0) {
        errorResponse('Invalid recording label id', 422);
    }

    $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'recording_label' LIMIT 1");
    $targetStmt->execute([$id]);
    if (!$targetStmt->fetchColumn()) {
        errorResponse('Recording label not found', 404);
    }

    if (!$current['is_admin'] && ($current['id'] !== $id || $current['type'] !== 'recording_label')) {
        errorResponse('Forbidden', 403);
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        errorResponse('Recording label not found', 404);
    }

    if ($current['id'] === $id) {
        apiLogout();
    }

    jsonResponse(['success' => true]);
}
