<?php
require_once __DIR__ . '/../../lib/session.php';

panicStartSession();

function requireAuth() {
    if (!isLoggedIn()) {
        $next = urlencode((string)($_SERVER['REQUEST_URI'] ?? '/app/dashboard.php'));
        header('Location: /app/login.php?next=' . $next);
        exit;
    }
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'email'    => $_SESSION['user_email'],
        'type'     => $_SESSION['user_type'],
        'is_admin' => (bool)($_SESSION['user_is_admin'] ?? false),
    ];
}

function isAdmin(): bool {
    return isLoggedIn() && !empty($_SESSION['user_is_admin']);
}

function requireAdmin(): void {
    if (!isAdmin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function login(int $userId, string $email, string $type, bool $isAdmin = false): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $userId;
    $_SESSION['user_email']    = $email;
    $_SESSION['user_type']     = $type;
    $_SESSION['user_is_admin'] = $isAdmin;
    $_SESSION['_auth_at']      = time();
    $_SESSION['_last_activity'] = time();
    $_SESSION['_last_regenerated_at'] = time();
}

function logout(): void {
    panicDestroySession('logout');
}
