<?php
// Auth handlers: login, signup, logout, me

function handleAuthLogin(PDO $pdo): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?: [];
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if ($email === '' || $password === '') {
        errorResponse('Email and password are required');
    }

    $stmt = $pdo->prepare("SELECT id, email, password_hash, type, is_admin FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        errorResponse('Invalid email or password', 401);
    }

    apiLogin((int)$user['id'], $user['email'], $user['type'], (bool)$user['is_admin']);
    jsonResponse([
        'success' => true,
        'user' => [
            'id'       => (int)$user['id'],
            'email'    => $user['email'],
            'type'     => $user['type'],
            'is_admin' => (bool)$user['is_admin'],
        ]
    ]);
}

function handleAuthSignup(PDO $pdo): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?: [];
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $type     = $body['type'] ?? '';

    if ($email === '' || $password === '' || $type === '') {
        errorResponse('email, password, and type are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email address');
    }
    if (strlen($password) < 8) {
        errorResponse('Password must be at least 8 characters');
    }
    if (!in_array($type, ['band', 'venue'])) {
        errorResponse('type must be band or venue');
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        errorResponse('Email already registered', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, type) VALUES (?, ?, ?)");
    $stmt->execute([$email, $hash, $type]);
    $userId = (int)$pdo->lastInsertId();

    // Empty profile
    $defaultData = $type === 'band' ? json_encode([
        'name' => '', 'genres' => [], 'members' => [], 'description' => '',
        'contact_email' => $email, 'contact_phone' => '', 'website' => '',
        'facebook' => '', 'instagram' => '', 'spotify' => '', 'youtube' => '',
        'location' => 'San Francisco, CA', 'experience' => '',
        'set_length_min' => 45, 'set_length_max' => 90,
        'has_own_equipment' => false, 'available_last_minute' => true, 'notes' => ''
    ]) : json_encode([
        'name' => '', 'address' => '', 'neighborhood' => '', 'capacity' => 0,
        'description' => '', 'contact_email' => $email, 'contact_phone' => '',
        'website' => '', 'facebook' => '', 'instagram' => '',
        'genres_welcomed' => [], 'has_pa' => false, 'has_drums' => false,
        'has_backline' => false, 'stage_size' => '', 'cover_charge' => false,
        'bar_service' => false, 'open_to_last_minute' => true,
        'booking_lead_time_days' => 0, 'notes' => ''
    ]);

    $stmt = $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $type, $defaultData]);

    apiLogin($userId, $email, $type);
    jsonResponse([
        'success' => true,
        'user' => ['id' => $userId, 'email' => $email, 'type' => $type]
    ], 201);
}

function handleAuthLogout(): void {
    apiLogout();
    jsonResponse(['success' => true]);
}

function handleAuthMe(): void {
    apiRequireAuth();
    $user = apiCurrentUser();
    jsonResponse(['user' => $user]); // already includes is_admin via apiCurrentUser()
}
