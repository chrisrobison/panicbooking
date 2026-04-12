<?php
// Events handler — reads from Event Sync canonical rows in scraped_events.

/**
 * GET /api/events
 * Params: date (YYYY-MM-DD, default today), days (1–30, default 7),
 *         q (search bands/venue), sf_only (bool, default 1),
 *         offset (int, default 0), limit (int, default 100)
 */
function handleEventsList(PDO $pdo): void {

    $today    = date('Y-m-d');
    $dateFrom = $_GET['date']    ?? $today;
    $days     = max(1, min(30, (int)($_GET['days'] ?? 7)));
    $q        = trim($_GET['q']  ?? '');
    $sfOnly   = isset($_GET['sf_only']) ? (bool)(int)$_GET['sf_only'] : true;
    $source   = trim($_GET['source'] ?? '');
    $offset   = max(0, (int)($_GET['offset'] ?? 0));
    $limit    = max(1, min(200, (int)($_GET['limit'] ?? 100)));

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }

    $dateTo = date('Y-m-d', strtotime($dateFrom . ' +' . ($days - 1) . ' days'));

    $where  = ['event_date >= :date_from', 'event_date <= :date_to'];
    $params = [':date_from' => $dateFrom, ':date_to' => $dateTo];

    if ($sfOnly) {
        $where[] = "venue_city = 'S.F.'";
    }

    if ($q !== '') {
        $where[]        = "(venue_name LIKE :q OR bands LIKE :q2)";
        $params[':q']   = '%' . $q . '%';
        $params[':q2']  = '%' . $q . '%';
    }

    if ($source !== '') {
        $where[]           = "source = :source";
        $params[':source'] = $source;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $countSql  = "SELECT COUNT(*) FROM scraped_events {$whereSQL}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch rows
    $sql  = "SELECT * FROM scraped_events {$whereSQL}
             ORDER BY event_date ASC, venue_name ASC
             LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $events = array_map('eventsDecodeRow', $rows);

    jsonResponse([
        'events'    => $events,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'total'     => $total,
    ]);
}

/**
 * GET /api/events/{id}
 */
function handleEventsGet(PDO $pdo, int $id): void {

    $stmt = $pdo->prepare("SELECT * FROM scraped_events WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        errorResponse('Event not found', 404);
        return;
    }

    jsonResponse(['event' => eventsDecodeRow($row)]);
}

/**
 * GET /api/events/stats
 * Params: date_from (YYYY-MM-DD, default today), date_to (default +30 days)
 * Returns per-venue stats for SF venues.
 */
function handleEventsStats(PDO $pdo): void {

    $today    = date('Y-m-d');
    $dateFrom = $_GET['date_from'] ?? $today;
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d', strtotime($today . ' +30 days'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d', strtotime($today . ' +30 days'));

    $stmt = $pdo->prepare("
        SELECT venue_name, venue_city, event_date
        FROM scraped_events
        WHERE venue_city = 'S.F.'
          AND event_date >= :date_from
          AND event_date <= :date_to
        ORDER BY venue_name ASC, event_date ASC
    ");
    $stmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $rows = $stmt->fetchAll();

    // Group by venue
    $venues = [];
    foreach ($rows as $row) {
        $key = $row['venue_name'];
        if (!isset($venues[$key])) {
            $venues[$key] = [
                'venue_name'  => $row['venue_name'],
                'venue_city'  => $row['venue_city'],
                'total_shows' => 0,
                'dates'       => [],
            ];
        }
        $venues[$key]['total_shows']++;
        if (!in_array($row['event_date'], $venues[$key]['dates'], true)) {
            $venues[$key]['dates'][] = $row['event_date'];
        }
    }

    // Sort by total shows desc
    usort($venues, fn($a, $b) => $b['total_shows'] - $a['total_shows']);

    jsonResponse([
        'stats'     => array_values($venues),
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
    ]);
}

/**
 * GET /api/dark-nights — PUBLIC, no auth required
 * Params: days (int, default 30, max 90), date_from (default today)
 * Returns venues with their booked and dark dates in the window.
 */
function handleDarkNights(PDO $pdo): void {
    $today    = date('Y-m-d');
    $dateFrom = trim($_GET['date_from'] ?? $today);
    $days     = max(1, min(90, (int)($_GET['days'] ?? 30)));
    $source   = trim((string)($_GET['source'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }

    $dateTo = date('Y-m-d', strtotime($dateFrom . ' +' . ($days - 1) . ' days'));

    // Build the full list of dates in the window
    $allDates = [];
    $cursor   = new DateTime($dateFrom . 'T00:00:00');
    $end      = new DateTime($dateTo . 'T00:00:00');
    while ($cursor <= $end) {
        $allDates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }

    // Fetch all SF shows in the window grouped by venue + date.
    $sql = "
        SELECT
            COALESCE(NULLIF(se.canonical_venue_slug, ''), se.venue_name) AS venue_key,
            COALESCE(v.slug, se.canonical_venue_slug, '') AS venue_slug,
            COALESCE(v.display_name, se.venue_name) AS venue_name,
            se.venue_city,
            se.source,
            se.event_date,
            COUNT(*) AS show_count
        FROM scraped_events se
        LEFT JOIN event_sync_venues v ON v.slug = se.canonical_venue_slug
        WHERE se.venue_city = 'S.F.'
          AND se.event_date >= :date_from
          AND se.event_date <= :date_to
    ";
    $params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
    if ($source !== '') {
        $sql .= " AND se.source = :source";
        $params[':source'] = $source;
    }
    $sql .= "
        GROUP BY
            COALESCE(NULLIF(se.canonical_venue_slug, ''), se.venue_name),
            COALESCE(v.slug, se.canonical_venue_slug, ''),
            COALESCE(v.display_name, se.venue_name),
            se.venue_city,
            se.source,
            se.event_date
        ORDER BY venue_name ASC, se.event_date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Build venue map: venue_key -> venue stats + booked_dates
    $venueMap = [];
    foreach ($rows as $row) {
        $key = (string)($row['venue_key'] ?? $row['venue_name']);
        if (!isset($venueMap[$key])) {
            $venueMap[$key] = [
                'venue_slug'   => (string)($row['venue_slug'] ?? ''),
                'venue_name'   => $row['venue_name'],
                'venue_city'   => $row['venue_city'],
                'source'       => $row['source'],
                'booked_dates' => [],
            ];
        }
        if (!in_array($row['event_date'], $venueMap[$key]['booked_dates'], true)) {
            $venueMap[$key]['booked_dates'][] = $row['event_date'];
        }
    }

    // Load optional confidence metadata from canonical venue table.
    $metaBySlug = [];
    if (!empty($venueMap)) {
        $slugs = array_values(array_filter(array_map(
            static fn(array $v): string => (string)($v['venue_slug'] ?? ''),
            $venueMap
        )));
        if (!empty($slugs)) {
            $placeholders = implode(',', array_fill(0, count($slugs), '?'));
            $metaStmt = $pdo->prepare("
                SELECT slug, venue_score, venue_tier, coverage_confidence, has_official_sync
                FROM event_sync_venues
                WHERE slug IN ($placeholders)
            ");
            $metaStmt->execute($slugs);
            foreach ($metaStmt->fetchAll() as $m) {
                $metaBySlug[(string)$m['slug']] = $m;
            }
        }
    }

    // Compute dark_dates for each venue and sort venues by booked count desc
    $venues = [];
    foreach ($venueMap as $venue) {
        $booked     = $venue['booked_dates'];
        $darkDates  = array_values(array_diff($allDates, $booked));
        $slug       = (string)($venue['venue_slug'] ?? '');
        $meta       = $slug !== '' ? ($metaBySlug[$slug] ?? null) : null;
        $venues[] = [
            'venue_slug'   => $slug,
            'venue_name'   => $venue['venue_name'],
            'venue_city'   => $venue['venue_city'],
            'source'       => $venue['source'],
            'booked_dates' => $booked,
            'dark_dates'   => $darkDates,
            'venue_score'  => $meta ? (float)($meta['venue_score'] ?? 0) : 0.0,
            'venue_tier'   => $meta['venue_tier'] ?? 'Tier 4',
            'coverage_confidence' => $meta ? (float)($meta['coverage_confidence'] ?? 0) : 0.0,
            'has_official_sync'   => $meta ? (int)($meta['has_official_sync'] ?? 0) : 0,
        ];
    }

    usort($venues, fn($a, $b) => count($b['booked_dates']) - count($a['booked_dates']));

    jsonResponse([
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'dates'     => $allDates,
        'venues'    => $venues,
    ]);
}

/**
 * Decode a DB row: parse bands JSON, cast integers.
 */
function eventsDecodeRow(array $row): array {
    $row['bands']       = json_decode($row['bands'] ?? '[]', true) ?: [];
    $row['is_sold_out'] = (int)($row['is_sold_out'] ?? 0);
    $row['is_ticketed'] = (int)($row['is_ticketed'] ?? 0);
    $row['source_priority'] = (int)($row['source_priority'] ?? 0);
    $row['id']          = (int)$row['id'];
    $row['source']      = $row['source'] ?? 'foopee';
    return $row;
}
