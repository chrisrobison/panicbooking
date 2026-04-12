<?php

function getProfile(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['data'] = json_decode($row['data'], true) ?: [];
    return $row;
}

function saveProfile(PDO $pdo, int $userId, string $type, array $data): void {
    $json = json_encode($data);
    $stmt = $pdo->prepare("
        INSERT INTO profiles (user_id, type, data, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(user_id) DO UPDATE SET
            type = excluded.type,
            data = excluded.data,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $type, $json]);
}

function getBands(PDO $pdo, string $q = '', string $genre = ''): array {
    $params = [];
    $where  = ["u.type = 'band'", "COALESCE(p.is_archived, 0) = 0"];

    if ($q !== '') {
        $where[]  = "(p.data LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    if ($genre !== '') {
        $where[]       = "p.data LIKE :genre";
        $params[':genre'] = '%' . $genre . '%';
    }

    $sql = "
        SELECT u.id, u.email, u.created_at, p.data
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.updated_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(function($row) {
        $data = json_decode($row['data'], true) ?: [];
        return array_merge(['id' => $row['id'], 'email' => $row['email'], 'created_at' => $row['created_at']], $data);
    }, $rows);
}

function getVenues(PDO $pdo, string $q = '', string $neighborhood = ''): array {
    $params = [];
    $where  = ["u.type = 'venue'", "COALESCE(p.is_archived, 0) = 0"];

    if ($q !== '') {
        $where[]      = "(p.data LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    if ($neighborhood !== '') {
        $where[]              = "p.data LIKE :nb";
        $params[':nb'] = '%' . $neighborhood . '%';
    }

    $sql = "
        SELECT u.id, u.email, u.created_at, p.data
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.updated_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(function($row) {
        $data = json_decode($row['data'], true) ?: [];
        return array_merge(['id' => $row['id'], 'email' => $row['email'], 'created_at' => $row['created_at']], $data);
    }, $rows);
}
