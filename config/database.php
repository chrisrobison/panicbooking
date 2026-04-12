<?php

require_once __DIR__ . '/env.php';
panicLoadEnvFiles();
require_once __DIR__ . '/../lib/security.php';

if (!panicDebugEnabled()) {
    ini_set('display_errors', '0');
}

/**
 * Central DB configuration + connection helper.
 *
 * Environment variables:
 * - PB_DB_DRIVER=sqlite|mysql
 * - PB_DB_PATH=/abs/path/to/db.sqlite (sqlite)
 * - PB_DB_HOST=127.0.0.1
 * - PB_DB_PORT=3306
 * - PB_DB_NAME=panicbooking
 * - PB_DB_USER=root
 * - PB_DB_PASS=secret
 * - PB_DB_CHARSET=utf8mb4
 */

function panicDbConfig(): array {
    $driver = strtolower(trim((string)(getenv('PB_DB_DRIVER') ?: 'sqlite')));
    if ($driver !== 'mysql') {
        $driver = 'sqlite';
    }

    if ($driver === 'mysql') {
        $host    = (string)(getenv('PB_DB_HOST') ?: '127.0.0.1');
        $port    = (int)(getenv('PB_DB_PORT') ?: 3306);
        $dbName  = (string)(getenv('PB_DB_NAME') ?: 'panicbooking');
        $user    = (string)(getenv('PB_DB_USER') ?: 'root');
        $pass    = (string)(getenv('PB_DB_PASS') ?: '');
        $charset = (string)(getenv('PB_DB_CHARSET') ?: 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

        return [
            'driver'   => 'mysql',
            'dsn'      => $dsn,
            'username' => $user,
            'password' => $pass,
        ];
    }

    $dbPath = (string)(getenv('PB_DB_PATH') ?: (__DIR__ . '/../data/booking.db'));
    return [
        'driver'   => 'sqlite',
        'dsn'      => 'sqlite:' . $dbPath,
        'username' => null,
        'password' => null,
        'path'     => $dbPath,
    ];
}

function panicDbDebugEnabled(): bool {
    return panicEnvBool('PB_DB_DEBUG', false) || panicEnvBool('PB_DB_BOOTSTRAP_DEBUG', false) || panicDebugEnabled();
}

function panicDbRedactedConfig(array $config): array {
    $safe = $config;
    if (isset($safe['password']) && $safe['password'] !== null && $safe['password'] !== '') {
        $safe['password'] = '[redacted]';
    }
    return $safe;
}

function panicConnectPdo(?array $config = null): PDO {
    $config = $config ?: panicDbConfig();

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    if (($config['driver'] ?? '') === 'mysql' && defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }

    if (panicDbDebugEnabled()) {
        panicLog('db_connect_attempt', [
            'config' => panicDbRedactedConfig($config),
        ]);
    }

    try {
        $pdo = new PDO($config['dsn'], $config['username'] ?? null, $config['password'] ?? null, $options);
    } catch (PDOException $e) {
        panicLog('db_connect_failed', [
            'config' => panicDbRedactedConfig($config),
            'error' => $e->getMessage(),
            'code' => (string)$e->getCode(),
        ], 'error');
        throw $e;
    }

    if (($config['driver'] ?? '') === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    if (panicDbDebugEnabled()) {
        panicLog('db_connect_success', [
            'driver' => (string)($config['driver'] ?? ''),
        ]);
    }

    return $pdo;
}

function panicDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = panicConnectPdo();
    return $pdo;
}
