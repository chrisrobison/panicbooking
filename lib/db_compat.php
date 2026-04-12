<?php

function panicDbDriver(PDO $pdo): string {
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    return $driver === 'mysql' ? 'mysql' : 'sqlite';
}

function panicDbIsMysql(PDO $pdo): bool {
    return panicDbDriver($pdo) === 'mysql';
}

function panicDbIsSqlite(PDO $pdo): bool {
    return panicDbDriver($pdo) === 'sqlite';
}

function panicDbIsDuplicateKeyException(PDOException $e): bool {
    $code = (string)$e->getCode();
    $message = strtolower($e->getMessage());

    if ($code === '23000') {
        return true;
    }

    return str_contains($message, 'unique constraint')
        || str_contains($message, 'duplicate entry')
        || str_contains($message, 'duplicate key');
}

function panicSqlJsonTextExpr(PDO $pdo, string $column, string $path): string {
    if (panicDbIsMysql($pdo)) {
        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }
    return "json_extract({$column}, '{$path}')";
}

function panicSqlJsonIntExpr(PDO $pdo, string $column, string $path): string {
    if (panicDbIsMysql($pdo)) {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}')) AS SIGNED)";
    }
    return "CAST(json_extract({$column}, '{$path}') AS INTEGER)";
}

function panicSqlOrderByCi(string $expr, string $direction = 'ASC'): string {
    $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    return "LOWER(COALESCE({$expr}, '')) {$dir}";
}

function panicScrapedEventsUpsertSql(PDO $pdo): string {
    $base = "
        INSERT INTO scraped_events
            (event_date, venue_name, venue_city, bands, age_restriction, price,
             doors_time, show_time, is_sold_out, is_ticketed, notes, raw_meta, source_url, source)
        VALUES
            (:event_date, :venue_name, :venue_city, :bands, :age_restriction, :price,
             :doors_time, :show_time, :is_sold_out, :is_ticketed, :notes, :raw_meta, :source_url, :source)
    ";

    if (panicDbIsMysql($pdo)) {
        return $base . "
            ON DUPLICATE KEY UPDATE
                venue_city = VALUES(venue_city),
                age_restriction = VALUES(age_restriction),
                price = VALUES(price),
                doors_time = VALUES(doors_time),
                show_time = VALUES(show_time),
                is_sold_out = VALUES(is_sold_out),
                is_ticketed = VALUES(is_ticketed),
                notes = VALUES(notes),
                raw_meta = VALUES(raw_meta),
                source_url = VALUES(source_url),
                source = VALUES(source),
                scraped_at = CURRENT_TIMESTAMP
        ";
    }

    return $base . "
        ON CONFLICT(event_date, venue_name, bands) DO UPDATE SET
            venue_city = excluded.venue_city,
            age_restriction = excluded.age_restriction,
            price = excluded.price,
            doors_time = excluded.doors_time,
            show_time = excluded.show_time,
            is_sold_out = excluded.is_sold_out,
            is_ticketed = excluded.is_ticketed,
            notes = excluded.notes,
            raw_meta = excluded.raw_meta,
            source_url = excluded.source_url,
            source = excluded.source,
            scraped_at = CURRENT_TIMESTAMP
    ";
}
