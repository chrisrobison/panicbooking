<?php
// Auth handlers: login, signup, logout, me

function authBody(): array {
    return apiReadJsonBody();
}

function handleAuthLogin(PDO $pdo): void {
    $body     = authBody();
    $email    = apiNormalizeEmail($body['email'] ?? '');
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

    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $updateStmt->execute([$newHash, (int)$user['id']]);
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
    apiRequireCsrf();
    $body     = authBody();
    $email    = apiNormalizeEmail($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $type     = apiSanitizeText($body['type'] ?? '', 24);

    if ($email === '' || $password === '' || $type === '') {
        errorResponse('email, password, and type are required');
    }
    $email = apiRequireEmail($email, 'email');
    if (strlen($password) < 8) {
        errorResponse('Password must be at least 8 characters');
    }
    $type = apiRequireEnum($type, ['band', 'venue', 'promoter', 'agent', 'recording_label'], 'type');

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

    // Default profile JSON per role
    $defaultData = match ($type) {
        'band' => json_encode([
            'name' => '', 'genres' => [], 'members' => [], 'description' => '',
            'contact_email' => $email, 'contact_phone' => '', 'website' => '',
            'facebook' => '', 'instagram' => '', 'spotify' => '', 'youtube' => '',
            'location' => 'San Francisco, CA', 'experience' => '',
            'set_length_min' => 45, 'set_length_max' => 90,
            'has_own_equipment' => false, 'available_last_minute' => true, 'notes' => '',
            'seeking_gigs' => true, 'available_days' => [],
            'next_available' => '', 'gig_radius' => '', 'booking_contact' => '',
        ]),
        'venue' => json_encode([
            'name' => '', 'address' => '', 'neighborhood' => '', 'capacity' => 0,
            'description' => '', 'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'facebook' => '', 'instagram' => '',
            'genres_welcomed' => [], 'has_pa' => false, 'has_drums' => false,
            'has_backline' => false, 'stage_size' => '', 'cover_charge' => false,
            'bar_service' => false, 'open_to_last_minute' => true,
            'booking_lead_time_days' => 0, 'notes' => '',
        ]),
        'promoter' => json_encode([
            'name' => '', 'company' => '', 'bio' => '',
            'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'instagram' => '', 'facebook' => '',
            'location' => 'San Francisco, CA',
            'genres' => [], 'years_active' => 0, 'notes' => '',
        ]),
        'agent' => json_encode([
            'name' => '', 'agency_name' => '', 'bio' => '',
            'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'instagram' => '',
            'location' => 'San Francisco, CA',
            'represented_genres' => [], 'notes' => '',
        ]),
        'recording_label' => json_encode([
            'name' => '', 'city' => 'San Francisco, CA', 'description' => '',
            'contact_email' => $email, 'contact_phone' => '',
            'website' => '', 'instagram' => '',
            'genres_focus' => [], 'roster_highlights' => [],
            'submission_email' => $email, 'submission_url' => '',
            'preferred_venue_sizes' => [], 'actively_signing' => true,
            'attends_live_shows' => true, 'notes' => '',
        ]),
        default => json_encode([]),
    };

    $stmt = $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $type, $defaultData]);

    apiLogin($userId, $email, $type);
    jsonResponse([
        'success' => true,
        'user' => ['id' => $userId, 'email' => $email, 'type' => $type]
    ], 201);
}

function handleAuthLogout(): void {
    apiRequireCsrf();
    apiLogout();
    jsonResponse(['success' => true]);
}

function handleAuthMe(): void {
    apiRequireAuth();
    $user = apiCurrentUser();
    jsonResponse(['user' => $user]); // already includes is_admin via apiCurrentUser()
}

function handleAuthPasswordResetRequest(PDO $pdo): void {
    apiRequireCsrf();
    $body = authBody();
    $email = apiNormalizeEmail($body['email'] ?? '');

    $response = [
        'success' => true,
        'message' => 'If an account exists for that email, password reset instructions were generated.',
    ];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse($response);
    }

    $userStmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    if (!$user) {
        jsonResponse($response);
    }

    $userId = (int)$user['id'];
    $throttleSeconds = max(30, (int)(panicEnv('PB_PASSWORD_RESET_THROTTLE_SECONDS', '120') ?? '120'));
    $throttleStmt = $pdo->prepare("
        SELECT created_at
        FROM password_reset_tokens
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $throttleStmt->execute([$userId]);
    $lastCreatedAt = (string)($throttleStmt->fetchColumn() ?: '');
    if ($lastCreatedAt !== '') {
        $lastTs = strtotime($lastCreatedAt);
        if ($lastTs !== false && (time() - $lastTs) < $throttleSeconds) {
            jsonResponse($response);
        }
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ttlMinutes = max(5, (int)(panicEnv('PB_PASSWORD_RESET_TTL_MINUTES', '30') ?? '30'));
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $cleanupStmt = $pdo->prepare("
        DELETE FROM password_reset_tokens
        WHERE user_id = ? OR expires_at < CURRENT_TIMESTAMP OR used_at IS NOT NULL
    ");
    $cleanupStmt->execute([$userId]);

    $insertStmt = $pdo->prepare("
        INSERT INTO password_reset_tokens
            (user_id, token_hash, expires_at, request_ip, request_user_agent)
        VALUES
            (?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $userId,
        $tokenHash,
        $expiresAt,
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    $resetUrl = panicPublicBaseUrl() . '/app/password-reset.php?token=' . urlencode($token);
    $debugMode = panicEnvBool('PB_PASSWORD_RESET_DEBUG', false);
    $logContext = [
        'user_id' => $userId,
        'email' => (string)$user['email'],
    ];
    if ($debugMode) {
        $logContext['reset_url'] = $resetUrl;
    }
    panicLog('password_reset_requested', $logContext);

    if ($debugMode) {
        $response['debug_reset_url'] = $resetUrl;
    }

    jsonResponse($response);
}

function handleAuthPasswordResetConfirm(PDO $pdo): void {
    apiRequireCsrf();
    $body = authBody();
    $token = trim((string)($body['token'] ?? ''));
    $newPassword = (string)($body['new_password'] ?? '');

    if ($token === '') {
        errorResponse('token is required', 422);
    }
    if (strlen($newPassword) < 8) {
        errorResponse('Password must be at least 8 characters', 422);
    }

    $tokenHash = hash('sha256', $token);
    $lookupStmt = $pdo->prepare("
        SELECT prt.id, prt.user_id
        FROM password_reset_tokens prt
        WHERE prt.token_hash = ?
          AND prt.used_at IS NULL
          AND prt.expires_at >= CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $lookupStmt->execute([$tokenHash]);
    $row = $lookupStmt->fetch();
    if (!$row) {
        errorResponse('Token is invalid or expired', 400);
    }

    $pdo->beginTransaction();
    try {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateUser = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $updateUser->execute([$hash, (int)$row['user_id']]);

        $markUsed = $pdo->prepare("
            UPDATE password_reset_tokens
            SET used_at = CURRENT_TIMESTAMP
            WHERE id = ? OR user_id = ?
        ");
        $markUsed->execute([(int)$row['id'], (int)$row['user_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        panicLog('password_reset_confirm_failed', [
            'user_id' => (int)($row['user_id'] ?? 0),
            'error' => $e->getMessage(),
        ], 'error');
        errorResponse('Failed to reset password', 500);
    }

    panicLog('password_reset_confirmed', ['user_id' => (int)$row['user_id']]);
    jsonResponse(['success' => true]);
}
