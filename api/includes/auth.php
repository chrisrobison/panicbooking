<?php
require_once __DIR__ . '/../../lib/session.php';
require_once __DIR__ . '/../../lib/user_roles.php';

panicStartSession();

function apiEnsureRoleContextSession(?PDO $pdo = null): void {
    panicHydrateLegacySessionFields();
    if (!apiIsLoggedIn()) {
        return;
    }
    if (panicSessionHasRoleContextData()) {
        return;
    }

    $pdo = $pdo ?: panicDb();
    $authUserId = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($authUserId <= 0) {
        return;
    }

    $ctx = panicBuildSessionContext($pdo, $authUserId, !empty($_SESSION['user_is_admin']), (string)($_SESSION['active_role_key'] ?? ''));
    panicWriteSessionContext($ctx);
}

function apiRequireAuth(): void {
    if (!apiIsLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    apiEnsureRoleContextSession();
}

function apiIsLoggedIn(): bool {
    return !empty($_SESSION['auth_user_id']) || !empty($_SESSION['user_id']);
}

function apiCurrentUser(): ?array {
    if (!apiIsLoggedIn()) return null;
    apiEnsureRoleContextSession();
    $contexts = is_array($_SESSION['user_role_contexts'] ?? null) ? $_SESSION['user_role_contexts'] : [];
    $badges = is_array($_SESSION['user_role_badges'] ?? null) ? $_SESSION['user_role_badges'] : [];
    return [
        'id'       => (int)($_SESSION['active_user_id'] ?? $_SESSION['user_id'] ?? 0),
        'email'    => (string)($_SESSION['active_user_email'] ?? $_SESSION['user_email'] ?? ''),
        'type'     => (string)($_SESSION['active_user_type'] ?? $_SESSION['user_type'] ?? ''),
        'is_admin' => (bool)($_SESSION['user_is_admin'] ?? false),
        'account_id' => (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0),
        'account_email' => (string)($_SESSION['auth_user_email'] ?? $_SESSION['user_email'] ?? ''),
        'account_type' => (string)($_SESSION['auth_user_type'] ?? $_SESSION['user_type'] ?? ''),
        'active_role_key' => (string)($_SESSION['active_role_key'] ?? ''),
        'role_contexts' => $contexts,
        'role_badges' => $badges,
    ];
}

function apiIsAdmin(): bool {
    return apiIsLoggedIn() && !empty($_SESSION['user_is_admin']);
}

function apiAuthUserId(): int {
    return (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
}

function apiAuthUserEmail(): string {
    return (string)($_SESSION['auth_user_email'] ?? $_SESSION['user_email'] ?? '');
}

function apiRequireAdmin(): void {
    apiRequireAuth();
    if (!apiIsAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

function apiRequireType(string $type): void {
    apiRequireAuth();
    $user = apiCurrentUser();
    if (!apiIsAdmin() && ($user['type'] ?? '') !== $type) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

function apiLogin(int $userId, string $email, string $type, bool $isAdmin = false): void {
    session_regenerate_id(true);
    $ctx = panicBuildSessionContext(panicDb(), $userId, $isAdmin);
    panicWriteSessionContext($ctx);
    $_SESSION['_auth_at']      = time();
    $_SESSION['_last_activity'] = time();
    $_SESSION['_last_regenerated_at'] = time();
}

function apiLogout(): void {
    panicDestroySession('api_logout');
}

function apiCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function apiValidateCsrfToken(?string $token): bool {
    if (!is_string($token) || $token === '') {
        return false;
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function apiRequireCsrf(): void {
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $body = null;

    if ($headerToken === '') {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
                $headerToken = (string)($decoded['csrf_token'] ?? '');
            }
        }
    }

    if ($headerToken === '' && isset($_POST['csrf_token'])) {
        $headerToken = (string)$_POST['csrf_token'];
    }

    if (!apiValidateCsrfToken($headerToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    if ($body !== null) {
        $GLOBALS['API_PARSED_JSON_BODY'] = $body;
    }
}
