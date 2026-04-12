<?php

require_once __DIR__ . '/security.php';

function panicSessionConfig(): array {
    return [
        'name' => panicEnv('PB_SESSION_NAME', 'panicbooking_sid'),
        'idle_timeout' => max(300, (int)(panicEnv('PB_SESSION_IDLE_TIMEOUT', '7200') ?? '7200')),
        'absolute_timeout' => max(900, (int)(panicEnv('PB_SESSION_ABSOLUTE_TIMEOUT', '86400') ?? '86400')),
        'regen_interval' => max(60, (int)(panicEnv('PB_SESSION_REGEN_INTERVAL', '900') ?? '900')),
        'samesite' => panicEnv('PB_SESSION_SAMESITE', 'Lax'),
    ];
}

function panicIsHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function panicStartSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        panicEnforceSessionLifetimes();
        return;
    }

    $cfg = panicSessionConfig();
    $secure = panicIsHttpsRequest();
    $samesite = in_array($cfg['samesite'], ['Lax', 'Strict', 'None'], true) ? $cfg['samesite'] : 'Lax';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    session_name((string)$cfg['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $samesite,
    ]);

    session_start();
    panicEnforceSessionLifetimes();
}

function panicEnforceSessionLifetimes(): void {
    $cfg = panicSessionConfig();
    $now = time();

    $lastActivity = isset($_SESSION['_last_activity']) ? (int)$_SESSION['_last_activity'] : 0;
    if ($lastActivity > 0 && ($now - $lastActivity) > $cfg['idle_timeout']) {
        panicDestroySession('idle_timeout');
        return;
    }

    $createdAt = isset($_SESSION['_created_at']) ? (int)$_SESSION['_created_at'] : 0;
    if ($createdAt > 0 && ($now - $createdAt) > $cfg['absolute_timeout']) {
        panicDestroySession('absolute_timeout');
        return;
    }

    if ($createdAt <= 0) {
        $_SESSION['_created_at'] = $now;
    }

    $lastRegeneratedAt = isset($_SESSION['_last_regenerated_at']) ? (int)$_SESSION['_last_regenerated_at'] : 0;
    if ($lastRegeneratedAt <= 0 || ($now - $lastRegeneratedAt) > $cfg['regen_interval']) {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated_at'] = $now;
    }

    $_SESSION['_last_activity'] = $now;
}

function panicDestroySession(string $reason = ''): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    if ($reason !== '') {
        panicLog('session_destroyed', ['reason' => $reason]);
    }
}
