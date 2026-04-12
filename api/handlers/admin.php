<?php
// Admin handlers: user management, delegation

/**
 * GET /api/admin/users
 * List all users with profile name and type.
 */
function handleAdminListUsers(PDO $pdo): void {
    apiRequireAdmin();

    $type   = trim($_GET['type'] ?? '');
    $q      = trim($_GET['q']    ?? '');
    $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    $where  = [];
    $params = [];
    $profileNameExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');
    $neighborhoodExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.neighborhood');
    $capacityExpr = panicSqlJsonIntExpr($pdo, 'p.data', '$.capacity');
    $genresExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.genres');
    $genresWelcomedExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.genres_welcomed');
    $orderByName = panicSqlOrderByCi($profileNameExpr, 'ASC');

    if ($type !== '' && in_array($type, ['band', 'venue'])) {
        $where[]           = "u.type = :type";
        $params[':type']   = $type;
    }
    if ($q !== '') {
        $where[]       = "(u.email LIKE :q OR p.data LIKE :q2)";
        $params[':q']  = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
    }
    if (!$includeArchived) {
        $where[] = "COALESCE(p.is_archived, 0) = 0";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        {$whereClause}
    ");
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.type, u.is_admin, u.created_at,
               {$profileNameExpr} AS profile_name,
               {$neighborhoodExpr} AS neighborhood,
               {$capacityExpr} AS capacity,
               {$genresExpr} AS genres_json,
               {$genresWelcomedExpr} AS genres_welcomed_json,
               COALESCE(p.is_generic, 0) AS is_generic,
               COALESCE(p.is_claimed, 0) AS is_claimed,
               COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        {$whereClause}
        ORDER BY {$orderByName}, u.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $users = array_map(function($r) {
        $genres = [];
        if (!empty($r['genres_json'])) {
            $genres = json_decode($r['genres_json'], true) ?: [];
        } elseif (!empty($r['genres_welcomed_json'])) {
            $genres = json_decode($r['genres_welcomed_json'], true) ?: [];
        }
        return [
            'id'           => (int)$r['id'],
            'email'        => $r['email'],
            'type'         => $r['type'],
            'is_admin'     => (bool)$r['is_admin'],
            'profile_name' => $r['profile_name'] ?? '',
            'neighborhood' => $r['neighborhood'] ?? '',
            'capacity'     => (int)($r['capacity'] ?? 0),
            'genres'       => $genres,
            'is_generic'   => (bool)$r['is_generic'],
            'is_claimed'   => (bool)$r['is_claimed'],
            'is_archived'  => (bool)$r['is_archived'],
            'created_at'   => $r['created_at'],
        ];
    }, $rows);

    jsonResponse(['users' => $users, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

/**
 * POST /api/admin/users
 * Create a new band or venue user + empty profile.
 */
function handleAdminCreateUser(PDO $pdo): void {
    apiRequireAdmin();
    apiRequireCsrf();

    $body  = apiReadJsonBody();
    $email = apiRequireEmail($body['email'] ?? '', 'email');
    $type  = apiRequireEnum($body['type'] ?? '', ['band', 'venue'], 'type');
    $name  = apiSanitizeText($body['name'] ?? '', 180);
    $pass  = (string)($body['password'] ?? '');

    if (strlen($pass) < 8) {
        errorResponse('Password must be at least 8 characters', 422);
    }

    // Check duplicate
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        errorResponse('Email already exists', 409);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare("INSERT INTO users (email, password_hash, type) VALUES (?, ?, ?)");
    $ins->execute([$email, $hash, $type]);
    $userId = (int)$pdo->lastInsertId();

    // Default profile data
    if ($type === 'band') {
        $profileData = json_encode([
            'name' => $name, 'genres' => [], 'members' => [],
            'description' => '', 'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'facebook' => '', 'instagram' => '', 'spotify' => '', 'youtube' => '',
            'location' => 'San Francisco, CA', 'experience' => '',
            'set_length_min' => 45, 'set_length_max' => 90,
            'has_own_equipment' => false, 'available_last_minute' => false, 'notes' => '',
        ]);
    } else {
        $profileData = json_encode([
            'name' => $name, 'address' => '', 'neighborhood' => '', 'capacity' => 0,
            'description' => '', 'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'facebook' => '', 'instagram' => '',
            'genres_welcomed' => [], 'has_pa' => false, 'has_drums' => false,
            'has_backline' => false, 'stage_size' => '', 'cover_charge' => false,
            'bar_service' => false, 'open_to_last_minute' => false,
            'booking_lead_time_days' => 0, 'notes' => '',
        ]);
    }

    $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, ?, ?)")
        ->execute([$userId, $type, $profileData]);

    jsonResponse(['success' => true, 'id' => $userId, 'email' => $email, 'type' => $type], 201);
}

/**
 * DELETE /api/admin/users/{id}
 * Delete any user account.
 */
function handleAdminDeleteUser(PDO $pdo, int $id): void {
    apiRequireAdmin();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($current['id'] === $id) {
        errorResponse('Cannot delete your own admin account', 400);
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        errorResponse('User not found', 404);
    }

    jsonResponse(['success' => true]);
}

/**
 * PUT /api/admin/users/{id}/admin
 * Grant or revoke admin flag on a user.
 * Body: { "is_admin": true|false }
 */
function handleAdminSetAdminFlag(PDO $pdo, int $id): void {
    apiRequireAdmin();
    apiRequireCsrf();
    $current = apiCurrentUser();

    if ($current['id'] === $id) {
        errorResponse('Cannot modify your own admin flag', 400);
    }

    $body    = apiReadJsonBody();
    $isAdmin = !empty($body['is_admin']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->execute([$isAdmin, $id]);

    if ($stmt->rowCount() === 0) {
        errorResponse('User not found', 404);
    }

    jsonResponse(['success' => true, 'id' => $id, 'is_admin' => (bool)$isAdmin]);
}
