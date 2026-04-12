<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInputField(): string {
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function csrfValidate(?string $token): bool {
    if (!is_string($token) || $token === '') {
        return false;
    }
    $current = $_SESSION['csrf_token'] ?? '';
    return is_string($current) && hash_equals($current, $token);
}

function csrfRequireValid(?string $token = null): void {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (!csrfValidate($token)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}
