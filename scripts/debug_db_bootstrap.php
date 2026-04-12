<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/db_bootstrap.php';

function panicDebugPrint(string $label, $value): void {
    echo $label . ': ';
    if (is_scalar($value) || $value === null) {
        echo (string)$value . PHP_EOL;
        return;
    }
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

try {
    $cfg = panicDbConfig();
    if (function_exists('panicDbRedactedConfig')) {
        $cfg = panicDbRedactedConfig($cfg);
    } elseif (isset($cfg['password'])) {
        $cfg['password'] = '[redacted]';
    }

    panicDebugPrint('config', $cfg);

    $pdo = panicDb();

    $driver = panicDbDriver($pdo);
    panicDebugPrint('driver', $driver);

    if ($driver === 'mysql') {
        $activeDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        panicDebugPrint('active_database', $activeDb);
    } else {
        panicDebugPrint('active_database', (string)($cfg['path'] ?? 'sqlite'));
    }

    panicDbBootstrap($pdo);
    panicDebugPrint('bootstrap', 'ok');

    if ($driver === 'mysql') {
        $tableCount = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    } else {
        $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
    }
    panicDebugPrint('table_count', $tableCount);

    $migrations = $pdo->query('SELECT migration_key, applied_at FROM schema_migrations ORDER BY migration_key ASC')->fetchAll(PDO::FETCH_ASSOC);
    panicDebugPrint('migrations', $migrations);
} catch (Throwable $e) {
    panicDebugPrint('error', $e->getMessage());
    panicDebugPrint('type', get_class($e));
    exit(1);
}
