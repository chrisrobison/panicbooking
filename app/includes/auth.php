<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /app/login.php');
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
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
