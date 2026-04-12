<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function apiRequireAuth(): void {
    if (!apiIsLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function apiIsLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function apiCurrentUser(): ?array {
    if (!apiIsLoggedIn()) return null;
    return [
        'id'       => (int)$_SESSION['user_id'],
        'email'    => $_SESSION['user_email'],
        'type'     => $_SESSION['user_type'],
        'is_admin' => (bool)($_SESSION['user_is_admin'] ?? false),
    ];
}

function apiIsAdmin(): bool {
    return apiIsLoggedIn() && !empty($_SESSION['user_is_admin']);
}

function apiRequireAdmin(): void {
    apiRequireAuth();
    if (!apiIsAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

function apiLogin(int $userId, string $email, string $type, bool $isAdmin = false): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $userId;
    $_SESSION['user_email']    = $email;
    $_SESSION['user_type']     = $type;
    $_SESSION['user_is_admin'] = $isAdmin;
}

function apiLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
