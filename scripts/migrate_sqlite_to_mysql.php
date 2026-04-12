#!/usr/bin/env php
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/security.php';

function panicUsage(): void {
    echo <<<TXT
Usage:
  php scripts/migrate_sqlite_to_mysql.php [--sqlite=/path/to/source.db] [--append]

Description:
  Copies table data from a SQLite database into the configured MySQL database.
  By default, destination tables are cleared before copy.

Options:
  --sqlite=PATH   Source SQLite file path. Defaults to PB_DB_PATH or data/booking.db.
  --append        Keep destination rows and append source rows (INSERT IGNORE).
  --help          Show this help.

Required MySQL env vars:
  PB_DB_HOST, PB_DB_PORT, PB_DB_NAME, PB_DB_USER, PB_DB_PASS, PB_DB_CHARSET

TXT;
}

function panicParseArgs(array $argv): array {
    $out = [
        'sqlite' => '',
        'append' => false,
        'help' => false,
    ];

    foreach ($argv as $i => $arg) {
        if ($i === 0) {
            continue;
        }
        if ($arg === '--append') {
            $out['append'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--sqlite=')) {
            $out['sqlite'] = trim((string)substr($arg, 9));
            continue;
        }

        fwrite(STDERR, "Unknown option: {$arg}\n");
        $out['help'] = true;
        return $out;
    }

    return $out;
}

function panicSqliteIdent(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

function panicMysqlIdent(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function panicMysqlConfigFromEnv(): array {
    $host = (string)panicEnv('PB_DB_HOST', '127.0.0.1');
    $port = (int)panicEnv('PB_DB_PORT', '3306');
    $dbName = (string)panicEnv('PB_DB_NAME', '');
    $user = (string)panicEnv('PB_DB_USER', '');
    $pass = (string)panicEnv('PB_DB_PASS', '');
    $charset = (string)panicEnv('PB_DB_CHARSET', 'utf8mb4');

    if ($dbName === '' || $user === '') {
        throw new RuntimeException('PB_DB_NAME and PB_DB_USER must be set for MySQL migration');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

    return [
        'driver' => 'mysql',
        'dsn' => $dsn,
        'username' => $user,
        'password' => $pass,
    ];
}

function panicTableExistsInMysql(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("\n        SELECT 1\n        FROM information_schema.tables\n        WHERE table_schema = DATABASE()\n          AND table_name = :table\n        LIMIT 1\n    ");
    $stmt->execute([':table' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function panicSqliteTableColumns(PDO $pdo, string $tableName): array {
    $rows = $pdo->query('PRAGMA table_info(' . panicSqliteIdent($tableName) . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $columns = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            $columns[] = $name;
        }
    }
    return $columns;
}

function panicMysqlTableColumns(PDO $pdo, string $tableName): array {
    $stmt = $pdo->prepare("\n        SELECT column_name\n        FROM information_schema.columns\n        WHERE table_schema = DATABASE()\n          AND table_name = :table\n        ORDER BY ordinal_position ASC\n    ");
    $stmt->execute([':table' => $tableName]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return array_values(array_filter(array_map('strval', $rows)));
}

function panicIntersectColumns(array $sourceColumns, array $destColumns): array {
    $destSet = array_fill_keys($destColumns, true);
    $out = [];
    foreach ($sourceColumns as $col) {
        if (isset($destSet[$col])) {
            $out[] = $col;
        }
    }
    return $out;
}

function panicFetchSqliteTables(PDO $sqlite): array {
    $stmt = $sqlite->query("\n        SELECT name\n        FROM sqlite_master\n        WHERE type = 'table'\n          AND name NOT LIKE 'sqlite_%'\n        ORDER BY name ASC\n    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $tables = [];
    foreach ($rows as $name) {
        $tableName = trim((string)$name);
        if ($tableName !== '') {
            $tables[] = $tableName;
        }
    }
    return $tables;
}

function panicCopyTable(PDO $sqlite, PDO $mysql, string $tableName, bool $append): array {
    if (!panicTableExistsInMysql($mysql, $tableName)) {
        return ['table' => $tableName, 'copied' => 0, 'skipped' => true, 'reason' => 'missing_in_mysql'];
    }

    $sourceColumns = panicSqliteTableColumns($sqlite, $tableName);
    $destColumns = panicMysqlTableColumns($mysql, $tableName);
    $columns = panicIntersectColumns($sourceColumns, $destColumns);
    if (empty($columns)) {
        return ['table' => $tableName, 'copied' => 0, 'skipped' => true, 'reason' => 'no_matching_columns'];
    }

    $sourceCountStmt = $sqlite->query('SELECT COUNT(*) FROM ' . panicSqliteIdent($tableName));
    $sourceCount = $sourceCountStmt ? (int)$sourceCountStmt->fetchColumn() : 0;
    if ($sourceCount === 0) {
        return ['table' => $tableName, 'copied' => 0, 'skipped' => false, 'reason' => 'empty_source'];
    }

    if (!$append) {
        $mysql->exec('DELETE FROM ' . panicMysqlIdent($tableName));
        $mysql->exec('ALTER TABLE ' . panicMysqlIdent($tableName) . ' AUTO_INCREMENT = 1');
    }

    $selectSql = 'SELECT ' . implode(', ', array_map('panicSqliteIdent', $columns)) . ' FROM ' . panicSqliteIdent($tableName);
    $selectStmt = $sqlite->query($selectSql);
    if (!$selectStmt) {
        throw new RuntimeException('Failed to read source table: ' . $tableName);
    }

    $insertPrefix = $append ? 'INSERT IGNORE INTO ' : 'INSERT INTO ';
    $insertSql = $insertPrefix . panicMysqlIdent($tableName)
        . ' (' . implode(', ', array_map('panicMysqlIdent', $columns)) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $insertStmt = $mysql->prepare($insertSql);

    $copied = 0;
    while (($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $values = [];
        foreach ($columns as $col) {
            $values[] = $row[$col] ?? null;
        }
        $insertStmt->execute($values);
        $copied += (int)$insertStmt->rowCount();
    }

    return ['table' => $tableName, 'copied' => $copied, 'skipped' => false, 'reason' => 'ok'];
}

function panicMain(array $argv): int {
    $args = panicParseArgs($argv);
    if (!empty($args['help'])) {
        panicUsage();
        return 0;
    }

    $sqlitePath = trim((string)$args['sqlite']);
    if ($sqlitePath === '') {
        $sqlitePath = (string)panicEnv('PB_DB_PATH', __DIR__ . '/../data/booking.db');
    }

    if (!is_file($sqlitePath)) {
        throw new RuntimeException('SQLite source file not found: ' . $sqlitePath);
    }

    $append = !empty($args['append']);

    $sqlite = panicConnectPdo([
        'driver' => 'sqlite',
        'dsn' => 'sqlite:' . $sqlitePath,
        'username' => null,
        'password' => null,
        'path' => $sqlitePath,
    ]);
    $mysql = panicConnectPdo(panicMysqlConfigFromEnv());

    $tables = panicFetchSqliteTables($sqlite);
    if (empty($tables)) {
        echo "No source tables found in SQLite.\n";
        return 0;
    }

    $mysql->exec('SET FOREIGN_KEY_CHECKS = 0');

    $copiedTotal = 0;
    $skipped = 0;

    try {
        foreach ($tables as $table) {
            $result = panicCopyTable($sqlite, $mysql, $table, $append);
            if (!empty($result['skipped'])) {
                $skipped++;
                echo '[skip] ' . $table . ' (' . $result['reason'] . ')' . PHP_EOL;
                continue;
            }

            $copied = (int)($result['copied'] ?? 0);
            $copiedTotal += $copied;
            echo '[ok]   ' . $table . ' copied rows: ' . $copied . PHP_EOL;
        }
    } finally {
        $mysql->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    echo "Done. Total copied rows: {$copiedTotal}; skipped tables: {$skipped}\n";
    return 0;
}

try {
    exit(panicMain($argv));
} catch (Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
