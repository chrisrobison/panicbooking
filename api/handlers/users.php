<?php
// User handlers: getMe, updateMe, deleteMe

function handleUsersGetMe(PDO $pdo): void {
    apiRequireAuth();
    $user = apiCurrentUser();

    $stmt = $pdo->prepare("SELECT id, email, type, created_at FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('User not found', 404);
    }

    // Also get profile
    $stmt2 = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ?");
    $stmt2->execute([$user['id']]);
    $profileData = json_decode($stmt2->fetchColumn() ?: '{}', true) ?: [];

    jsonResponse([
        'user' => [
            'id'         => (int)$row['id'],
            'email'      => $row['email'],
            'type'       => $row['type'],
            'created_at' => $row['created_at'],
        ],
        'profile' => $profileData
    ]);
}

function handleUsersUpdateMe(PDO $pdo): void {
    apiRequireAuth();
    $user = apiCurrentUser();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $currentPassword = $body['current_password'] ?? '';
    $newEmail        = trim($body['new_email'] ?? '');
    $newPassword     = $body['new_password'] ?? '';

    if ($currentPassword === '') {
        errorResponse('current_password is required');
    }

    // Verify current password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        errorResponse('Current password is incorrect', 403);
    }

    if ($newEmail !== '') {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            errorResponse('Invalid email address');
        }
        // Check not taken
        $stmt2 = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt2->execute([$newEmail, $user['id']]);
        if ($stmt2->fetch()) {
            errorResponse('Email already in use', 409);
        }
        $stmt3 = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt3->execute([$newEmail, $user['id']]);
        $_SESSION['user_email'] = $newEmail;
    }

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            errorResponse('Password must be at least 8 characters');
        }
        $hash  = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt4 = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt4->execute([$hash, $user['id']]);
    }

    jsonResponse(['success' => true]);
}

function handleUsersDeleteMe(PDO $pdo): void {
    apiRequireAuth();
    $user = apiCurrentUser();

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);

    apiLogout();
    jsonResponse(['success' => true]);
}
