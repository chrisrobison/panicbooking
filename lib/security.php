<?php

function panicEnv(string $name, ?string $default = null): ?string {
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return $default;
    }
    return $value;
}

function panicEnvBool(string $name, bool $default = false): bool {
    $raw = panicEnv($name, null);
    if ($raw === null) {
        return $default;
    }
    $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function panicPublicBaseUrl(): string {
    $configured = panicEnv('PB_PUBLIC_BASE_URL', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (PHP_SAPI === 'cli') {
        return 'http://localhost:8000';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function panicLog(string $event, array $context = [], string $level = 'info'): void {
    $payload = [
        'ts' => date('c'),
        'level' => $level,
        'event' => $event,
        'context' => $context,
    ];
    error_log('[panicbooking] ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function panicDebugEnabled(): bool {
    return panicEnvBool('PB_DEBUG', false);
}

function panicAppKey(): string {
    $envKey = panicEnv('PB_APP_KEY', '');
    if ($envKey !== '') {
        return $envKey;
    }

    $keyFile = panicEnv('PB_APP_KEY_FILE', __DIR__ . '/../data/.app_key');
    if ($keyFile === null || $keyFile === '') {
        throw new RuntimeException('PB_APP_KEY or PB_APP_KEY_FILE must be configured');
    }

    if (is_file($keyFile)) {
        $existing = trim((string)@file_get_contents($keyFile));
        if ($existing !== '') {
            return $existing;
        }
    }

    $dir = dirname($keyFile);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Application key is not configured and key file path is not writable');
    }

    $generated = bin2hex(random_bytes(32));
    if (@file_put_contents($keyFile, $generated . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Failed to persist generated application key');
    }
    @chmod($keyFile, 0600);

    return $generated;
}

function panicScriptGuard(string $scriptName): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $allowWeb = panicEnvBool('PB_ALLOW_WEB_MAINTENANCE', false);
    $expectedToken = panicEnv('PB_MAINTENANCE_TOKEN', '');
    $providedToken = trim((string)(
        $_SERVER['HTTP_X_MAINTENANCE_TOKEN']
        ?? $_GET['token']
        ?? $_POST['token']
        ?? ''
    ));

    $authorized = $allowWeb
        && $expectedToken !== ''
        && $providedToken !== ''
        && hash_equals($expectedToken, $providedToken);

    if (!$authorized) {
        panicLog('maintenance_script_forbidden', [
            'script' => $scriptName,
            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ], 'warning');
        http_response_code(403);
        exit('Forbidden');
    }

    header('Content-Type: text/plain; charset=utf-8');
}
