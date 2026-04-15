<?php
require_once __DIR__ . '/../../lib/session.php';
require_once __DIR__ . '/../../lib/user_roles.php';

panicStartSession();

function ensureRoleContextSession(?PDO $pdo = null): void {
    panicHydrateLegacySessionFields();
    if (!isLoggedIn()) {
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

function requireAuth() {
    if (!isLoggedIn()) {
        $next = urlencode((string)($_SERVER['REQUEST_URI'] ?? '/app/dashboard.php'));
        header('Location: /app/login.php?next=' . $next);
        exit;
    }

    ensureRoleContextSession();
}

function isLoggedIn(): bool {
    return !empty($_SESSION['auth_user_id']) || !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    ensureRoleContextSession();
    $roleContexts = is_array($_SESSION['user_role_contexts'] ?? null) ? $_SESSION['user_role_contexts'] : [];
    $roleBadges = is_array($_SESSION['user_role_badges'] ?? null) ? $_SESSION['user_role_badges'] : [];
    return [
        'id'       => (int)($_SESSION['active_user_id'] ?? $_SESSION['user_id'] ?? 0),
        'email'    => (string)($_SESSION['active_user_email'] ?? $_SESSION['user_email'] ?? ''),
        'type'     => (string)($_SESSION['active_user_type'] ?? $_SESSION['user_type'] ?? ''),
        'is_admin' => (bool)($_SESSION['user_is_admin'] ?? false),
        'account_id' => (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0),
        'account_email' => (string)($_SESSION['auth_user_email'] ?? $_SESSION['user_email'] ?? ''),
        'account_type' => (string)($_SESSION['auth_user_type'] ?? $_SESSION['user_type'] ?? ''),
        'active_role_key' => (string)($_SESSION['active_role_key'] ?? ''),
        'role_contexts' => $roleContexts,
        'role_badges' => $roleBadges,
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
    $ctx = panicBuildSessionContext(panicDb(), $userId, $isAdmin);
    panicWriteSessionContext($ctx);
    $_SESSION['_auth_at']      = time();
    $_SESSION['_last_activity'] = time();
    $_SESSION['_last_regenerated_at'] = time();
}

function switchActiveRole(string $roleKey, ?PDO $pdo = null): bool {
    if (!isLoggedIn()) {
        return false;
    }
    ensureRoleContextSession($pdo);

    $roleKey = trim($roleKey);
    if ($roleKey === '') {
        return false;
    }

    $contexts = is_array($_SESSION['user_role_contexts'] ?? null) ? $_SESSION['user_role_contexts'] : [];
    $selected = null;
    foreach ($contexts as $ctx) {
        if (((string)($ctx['key'] ?? '')) === $roleKey) {
            $selected = $ctx;
            break;
        }
    }

    if ($selected === null) {
        $pdo = $pdo ?: panicDb();
        $bundle = panicBuildSessionContext($pdo, (int)($_SESSION['auth_user_id'] ?? 0), !empty($_SESSION['user_is_admin']), $roleKey);
        panicWriteSessionContext($bundle);
        return ((string)($_SESSION['active_role_key'] ?? '')) === $roleKey;
    }

    $_SESSION['active_role_key'] = (string)$selected['key'];
    $_SESSION['active_user_id'] = (int)$selected['target_user_id'];
    $_SESSION['active_user_email'] = (string)$selected['email'];
    $_SESSION['active_user_type'] = (string)$selected['type'];

    $_SESSION['user_id'] = (int)$_SESSION['active_user_id'];
    $_SESSION['user_email'] = (string)$_SESSION['active_user_email'];
    $_SESSION['user_type'] = (string)$_SESSION['active_user_type'];

    return true;
}

function logout(): void {
    panicDestroySession('logout');
}
