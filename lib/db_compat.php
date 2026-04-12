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

function panicCanonicalNameKey(string $name): string {
    $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = strtolower(trim($decoded));
    if ($clean === '') {
        return '';
    }

    $clean = str_replace('&', ' and ', $clean);
    $clean = preg_replace('/^\s*the\s+/i', '', $clean) ?? $clean;
    $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    return str_replace(' ', '', $clean);
}

function panicNormalizeVenueName(string $venueName): string {
    $decoded = html_entity_decode($venueName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = trim(preg_replace('/\s+/', ' ', $decoded) ?? $decoded);
    if ($clean === '') {
        return '';
    }

    $aliases = [
        'gamh' => 'Great American Music Hall',
        'greatamericanmusichall' => 'Great American Music Hall',
        'warfield' => 'The Warfield',
        'warfieldtheatre' => 'The Warfield',
        'fillmore' => 'The Fillmore',
        'fillmoreauditorium' => 'The Fillmore',
        'regency' => 'Regency Ballroom',
        'regencyballroom' => 'Regency Ballroom',
        'theregencyballroom' => 'Regency Ballroom',
        'billgrahamcivicauditorium' => 'Bill Graham Civic Auditorium',
    ];

    $key = panicCanonicalNameKey($clean);
    if ($key !== '' && isset($aliases[$key])) {
        return $aliases[$key];
    }

    return $clean;
}

function panicNormalizeBandList(array $bands): array {
    $normalized = [];
    $seen = [];

    foreach ($bands as $band) {
        $decoded = html_entity_decode((string)$band, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim(preg_replace('/\s+/', ' ', $decoded) ?? $decoded);
        if ($clean === '') {
            continue;
        }

        $key = panicCanonicalNameKey($clean);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $clean;
    }

    return $normalized;
}

function panicBandSignature(array $bands): string {
    $keys = [];
    foreach (panicNormalizeBandList($bands) as $band) {
        $key = panicCanonicalNameKey($band);
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    sort($keys, SORT_STRING);
    return implode('|', $keys);
}

function panicResolveScrapedEventBandsJson(PDO $pdo, string $eventDate, string $venueName, array $bands): string {
    $normalizedBands = panicNormalizeBandList($bands);
    if (empty($normalizedBands)) {
        return json_encode([], JSON_UNESCAPED_UNICODE);
    }

    $incomingSignature = panicBandSignature($normalizedBands);

    $findExisting = $pdo->prepare("
        SELECT bands
        FROM scraped_events
        WHERE event_date = ?
          AND LOWER(venue_name) = LOWER(?)
    ");
    $findExisting->execute([$eventDate, $venueName]);

    foreach ($findExisting->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rawExistingBands = (string)($row['bands'] ?? '');
        $existingBands = json_decode($rawExistingBands, true);
        if (!is_array($existingBands)) {
            continue;
        }
        if (panicBandSignature($existingBands) === $incomingSignature) {
            return $rawExistingBands;
        }
    }

    return json_encode($normalizedBands, JSON_UNESCAPED_UNICODE);
}

function panicProfileIsProtected(array $profileRow): bool {
    $isClaimed = ((int)($profileRow['is_claimed'] ?? 0) === 1);
    $isGeneric = ((int)($profileRow['is_generic'] ?? 0) === 1);
    $createdAt = (string)($profileRow['created_at'] ?? '');
    $updatedAt = (string)($profileRow['updated_at'] ?? '');

    $isModified = false;
    if ($createdAt !== '' && $updatedAt !== '') {
        $createdTs = strtotime($createdAt);
        $updatedTs = strtotime($updatedAt);
        if ($createdTs !== false && $updatedTs !== false && $updatedTs > $createdTs) {
            $isModified = true;
        }
    }

    return $isClaimed || !$isGeneric || $isModified;
}

function panicLoadProtectedProfileNameSet(PDO $pdo, string $type): array {
    $safeType = strtolower(trim($type));
    if ($safeType !== 'band' && $safeType !== 'venue') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT p.data,
               p.created_at,
               p.updated_at,
               COALESCE(p.is_claimed, 0) AS is_claimed,
               COALESCE(p.is_generic, 0) AS is_generic
        FROM profiles p
        JOIN users u ON u.id = p.user_id
        WHERE u.type = :type
          AND p.type = :type
          AND COALESCE(p.is_archived, 0) = 0
    ");
    $stmt->execute([':type' => $safeType]);

    $set = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!panicProfileIsProtected($row)) {
            continue;
        }

        $data = json_decode((string)($row['data'] ?? ''), true);
        if (!is_array($data)) {
            continue;
        }

        $key = panicCanonicalNameKey((string)($data['name'] ?? ''));
        if ($key !== '') {
            $set[$key] = true;
        }
    }

    return $set;
}

function panicEventTouchesProtectedProfiles(
    string $venueName,
    array $bands,
    array $protectedVenueNames,
    array $protectedBandNames
): bool {
    $venueKey = panicCanonicalNameKey($venueName);
    if ($venueKey !== '' && isset($protectedVenueNames[$venueKey])) {
        return true;
    }

    foreach (panicNormalizeBandList($bands) as $bandName) {
        $bandKey = panicCanonicalNameKey($bandName);
        if ($bandKey !== '' && isset($protectedBandNames[$bandKey])) {
            return true;
        }
    }

    return false;
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
