<?php
// User handlers: getMe, updateMe, deleteMe

function handleUsersGetMe(PDO $pdo): void {
    apiRequireAuth();
    $user = apiCurrentUser();
    $authUserId = apiAuthUserId();

    $stmt = $pdo->prepare("SELECT id, email, type, created_at FROM users WHERE id = ?");
    $stmt->execute([$authUserId]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('User not found', 404);
    }

    // Also get profile
    $stmt2 = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ?");
    $stmt2->execute([$authUserId]);
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
    apiRequireCsrf();
    $user = apiCurrentUser();
    $authUserId = apiAuthUserId();
    $body = apiReadJsonBody();

    $currentPassword = $body['current_password'] ?? '';
    $newEmail        = apiNormalizeEmail($body['new_email'] ?? '');
    $newPassword     = $body['new_password'] ?? '';

    if ($currentPassword === '') {
        errorResponse('current_password is required');
    }

    // Verify current password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$authUserId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        errorResponse('Current password is incorrect', 403);
    }

    if ($newEmail !== '') {
        $newEmail = apiRequireEmail($newEmail, 'new_email');
        // Check not taken
        $stmt2 = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt2->execute([$newEmail, $authUserId]);
        if ($stmt2->fetch()) {
            errorResponse('Email already in use', 409);
        }
        $stmt3 = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt3->execute([$newEmail, $authUserId]);
        $_SESSION['auth_user_email'] = $newEmail;
        if ((int)($_SESSION['active_user_id'] ?? 0) === $authUserId) {
            $_SESSION['active_user_email'] = $newEmail;
            $_SESSION['user_email'] = $newEmail;
        }
    }

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            errorResponse('Password must be at least 8 characters');
        }
        $hash  = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt4 = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt4->execute([$hash, $authUserId]);
    }

    jsonResponse(['success' => true]);
}

function handleUsersDeleteMe(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();
    $authUserId = apiAuthUserId();

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$authUserId]);

    apiLogout();
    jsonResponse(['success' => true]);
}
