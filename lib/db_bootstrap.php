<?php

require_once __DIR__ . '/db_compat.php';
require_once __DIR__ . '/security.php';

function panicDbBootstrapDebugEnabled(): bool {
    return panicEnvBool('PB_DB_BOOTSTRAP_DEBUG', false) || (function_exists('panicDbDebugEnabled') && panicDbDebugEnabled());
}

function panicDbBootstrapLog(string $event, array $context = [], string $level = 'info'): void {
    if ($level !== 'error' && !panicDbBootstrapDebugEnabled()) {
        return;
    }
    panicLog($event, $context, $level);
}

function panicDbBootstrap(PDO $pdo): void {
    panicDbBootstrapLog('db_bootstrap_start', [
        'driver' => panicDbDriver($pdo),
    ]);

    panicDbEnsureMigrationsTable($pdo);
    panicDbApplyMigrations($pdo);
    panicDbApplyLegacyColumnPatches($pdo);

    panicDbBootstrapLog('db_bootstrap_complete', [
        'driver' => panicDbDriver($pdo),
    ]);
}

function panicDbEnsureMigrationsTable(PDO $pdo): void {
    if (panicDbIsMysql($pdo)) {
        panicDbBootstrapLog('db_migrations_table_ensure', ['driver' => 'mysql']);
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS schema_migrations (\n              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n              migration_key VARCHAR(255) NOT NULL UNIQUE,\n              applied_at DATETIME DEFAULT CURRENT_TIMESTAMP\n            )\n        ");
        return;
    }

    panicDbBootstrapLog('db_migrations_table_ensure', ['driver' => 'sqlite']);
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS schema_migrations (\n          id INTEGER PRIMARY KEY AUTOINCREMENT,\n          migration_key TEXT NOT NULL UNIQUE,\n          applied_at DATETIME DEFAULT CURRENT_TIMESTAMP\n        )\n    ");
}

function panicDbApplyMigrations(PDO $pdo): void {
    $driver = panicDbDriver($pdo);
    $dir = __DIR__ . '/../db/migrations/' . $driver;

    if (!is_dir($dir)) {
        panicDbBootstrapLog('db_migration_dir_missing', [
            'driver' => $driver,
            'dir' => $dir,
        ], 'error');
        throw new RuntimeException('Missing migration directory for driver: ' . $driver);
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    panicDbBootstrapLog('db_migrations_discovered', [
        'driver' => $driver,
        'dir' => $dir,
        'count' => count($files),
    ]);

    foreach ($files as $path) {
        $key = basename($path);
        if (panicDbHasMigration($pdo, $key)) {
            panicDbBootstrapLog('db_migration_skip_already_applied', [
                'migration' => $key,
            ]);
            continue;
        }

        panicDbBootstrapLog('db_migration_apply_start', [
            'migration' => $key,
        ]);

        $sql = file_get_contents($path);
        if ($sql === false) {
            panicDbBootstrapLog('db_migration_read_failed', [
                'migration' => $key,
                'path' => $path,
            ], 'error');
            throw new RuntimeException('Unable to read migration file: ' . $path);
        }

        try {
            panicDbExecSqlBatch($pdo, $sql, $key);

            $mark = $pdo->prepare('INSERT INTO schema_migrations (migration_key) VALUES (:key)');
            $mark->execute([':key' => $key]);

            panicDbBootstrapLog('db_migration_apply_success', [
                'migration' => $key,
            ]);
        } catch (Throwable $e) {
            panicDbBootstrapLog('db_migration_apply_failed', [
                'migration' => $key,
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ], 'error');
            throw $e;
        }
    }
}

function panicDbHasMigration(PDO $pdo, string $key): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    return (bool)$stmt->fetchColumn();
}

function panicDbExecSqlBatch(PDO $pdo, string $sql, string $migrationKey = ''): void {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? $sql;

    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $statementIndex = 0;
    foreach ($parts as $part) {
        $statement = trim($part);
        if ($statement === '') {
            continue;
        }

        $statementIndex++;
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            panicDbBootstrapLog('db_migration_statement_failed', [
                'migration' => $migrationKey,
                'statement_index' => $statementIndex,
                'statement_preview' => substr($statement, 0, 160),
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ], 'error');
            throw $e;
        }
    }
}

function panicDbApplyLegacyColumnPatches(PDO $pdo): void {
    // Keep these as idempotent compatibility patches for pre-migration DBs.
    $patches = [
        "ALTER TABLE scraped_events ADD COLUMN source TEXT NOT NULL DEFAULT 'foopee'",
        "ALTER TABLE profiles ADD COLUMN is_claimed INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE profiles ADD COLUMN is_generic INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE profiles ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE profiles ADD COLUMN archived_at DATETIME",
        "ALTER TABLE profiles ADD COLUMN archived_reason TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE profiles ADD COLUMN claimed_by_user_id INTEGER",
        "ALTER TABLE profiles ADD COLUMN claimed_at DATETIME",
        "ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0",
    ];

    foreach ($patches as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore duplicate-column errors on already-migrated databases.
            panicDbBootstrapLog('db_legacy_patch_skip', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);
        }
    }

    panicDbEnsureIndex($pdo, 'profiles', 'idx_profiles_type_archived', 'type, is_archived, is_generic, is_claimed');
    panicDbEnsureIndex($pdo, 'profiles', 'idx_profiles_claimed_by', 'claimed_by_user_id');
}

function panicDbEnsureIndex(PDO $pdo, string $table, string $indexName, string $columnsSql): void {
    if (panicDbIsMysql($pdo)) {
        $check = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.statistics\n            WHERE table_schema = DATABASE()\n              AND table_name = :table_name\n              AND index_name = :index_name\n            LIMIT 1\n        ");
        $check->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);

        if ($check->fetchColumn()) {
            return;
        }

        $pdo->exec("CREATE INDEX {$indexName} ON {$table}({$columnsSql})");
        return;
    }

    $indexes = $pdo->query('PRAGMA index_list(' . $table . ')');
    if ($indexes) {
        foreach ($indexes->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            if (($idx['name'] ?? '') === $indexName) {
                return;
            }
        }
    }

    $pdo->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table}({$columnsSql})");
}
