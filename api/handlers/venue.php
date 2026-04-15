<?php
// Venue-specific API handlers: calendar + recommended bands

/**
 * GET /api/venue/calendar
 * Returns the authenticated venue's calendar: booked dates, dark nights, show metadata.
 */
function handleVenueCalendar(PDO $pdo): void {
    $user    = apiIsLoggedIn() ? apiCurrentUser() : null;
    $isOwner = false;
    $userId  = 0;

    if ($user && ($user['type'] === 'venue' || ($user['is_admin'] ?? false))) {
        $userId  = (int)$user['id'];
        $isOwner = true;
    } elseif (isset($_GET['venue_id']) && (int)$_GET['venue_id'] > 0) {
        $vid = (int)$_GET['venue_id'];
        $chkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'venue' LIMIT 1");
        $chkStmt->execute([$vid]);
        if (!$chkStmt->fetchColumn()) {
            errorResponse('Venue not found', 404);
            return;
        }
        $userId = $vid;
    } else {
        errorResponse('Venue access required', 403);
        return;
    }

    $today    = date('Y-m-d');
    $months   = max(1, min(6, (int)($_GET['months'] ?? 2)));
    $dateFrom = trim($_GET['date_from'] ?? $today);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = $today;
    }

    // Snap to first of the requested month
    $fromDT = new DateTime($dateFrom . 'T00:00:00');
    $fromDT->modify('first day of this month');
    $dateFrom = $fromDT->format('Y-m-d');

    $toDT = clone $fromDT;
    $toDT->modify("+{$months} month")->modify('-1 day');
    $dateTo = $toDT->format('Y-m-d');

    // Build full date list
    $allDates = [];
    $cursor   = clone $fromDT;
    while ($cursor->format('Y-m-d') <= $dateTo) {
        $allDates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }

    // Venue profile
    $pStmt = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ? AND type = 'venue' LIMIT 1");
    $pStmt->execute([$userId]);
    $pRow      = $pStmt->fetch();
    $venueData = $pRow ? (json_decode($pRow['data'], true) ?: []) : [];
    $venueName = trim((string)($venueData['name'] ?? ''));

    // Canonical slug lookup
    $venueSlug = '';
    if ($venueName !== '') {
        $slugStmt = $pdo->prepare(
            "SELECT slug FROM event_sync_venues
             WHERE LOWER(display_name) = LOWER(:n) OR LOWER(name_key) = LOWER(:n)
             LIMIT 1"
        );
        $slugStmt->execute([':n' => $venueName]);
        $slugRow = $slugStmt->fetch();
        if ($slugRow) $venueSlug = (string)$slugRow['slug'];
    }

    $bookedDates = [];
    $showsByDate = [];

    // 1. Scraped events
    if ($venueName !== '' || $venueSlug !== '') {
        $sql    = "SELECT se.event_date, se.title, se.bands, se.source, se.ticket_url
                   FROM scraped_events se
                   WHERE se.event_date >= :df AND se.event_date <= :dt";
        $params = [':df' => $dateFrom, ':dt' => $dateTo];

        if ($venueSlug !== '') {
            $sql .= " AND se.canonical_venue_slug = :slug";
            $params[':slug'] = $venueSlug;
        } else {
            $sql .= " AND LOWER(se.venue_name) = LOWER(:vname)";
            $params[':vname'] = $venueName;
        }
        $sql .= " ORDER BY se.event_date ASC";

        $seStmt = $pdo->prepare($sql);
        $seStmt->execute($params);
        foreach ($seStmt->fetchAll() as $seRow) {
            $d = $seRow['event_date'];
            if (!in_array($d, $bookedDates, true)) $bookedDates[] = $d;
            $bands = [];
            if (!empty($seRow['bands'])) {
                $decoded = json_decode($seRow['bands'], true);
                if (is_array($decoded)) $bands = $decoded;
            }
            if (!isset($showsByDate[$d])) $showsByDate[$d] = [];
            $showsByDate[$d][] = [
                'title'      => (string)($seRow['title'] ?? (implode(', ', $bands) ?: 'Show')),
                'bands'      => $bands,
                'source'     => (string)($seRow['source'] ?? ''),
                'ticket_url' => (string)($seRow['ticket_url'] ?? ''),
                'type'       => 'scraped',
            ];
        }
    }

    // 2. Bookings table (venue-initiated)
    $bkStmt = $pdo->prepare(
        "SELECT b.event_date FROM bookings b
         WHERE b.venue_user_id = ? AND b.event_date >= ? AND b.event_date <= ?
           AND b.status NOT IN ('canceled')
         ORDER BY b.event_date ASC"
    );
    $bkStmt->execute([$userId, $dateFrom, $dateTo]);
    foreach ($bkStmt->fetchAll() as $bkRow) {
        $d = $bkRow['event_date'];
        if (!in_array($d, $bookedDates, true)) $bookedDates[] = $d;
    }

    // 3. Ticketed events (events table)
    $evStmt = $pdo->prepare(
        "SELECT DATE(start_at) AS event_date, title, slug
         FROM events
         WHERE venue_id = ? AND DATE(start_at) >= ? AND DATE(start_at) <= ?
           AND status != 'canceled'
         ORDER BY start_at ASC"
    );
    $evStmt->execute([$userId, $dateFrom, $dateTo]);
    foreach ($evStmt->fetchAll() as $evRow) {
        $d = $evRow['event_date'];
        if (!in_array($d, $bookedDates, true)) $bookedDates[] = $d;
        if (!isset($showsByDate[$d])) $showsByDate[$d] = [];
        $showsByDate[$d][] = [
            'title'  => (string)$evRow['title'],
            'bands'  => [],
            'source' => 'ticketed',
            'slug'   => (string)$evRow['slug'],
            'type'   => 'ticketed',
        ];
    }

    $darkDates = array_values(array_diff($allDates, $bookedDates));

    jsonResponse([
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'dates'        => $allDates,
        'venue_name'   => $venueName,
        'venue_slug'   => $venueSlug,
        'genres'       => array_values((array)($venueData['genres_welcomed'] ?? [])),
        'booked_dates' => $bookedDates,
        'dark_dates'   => $darkDates,
        'shows'        => $showsByDate,
        'is_owner'     => $isOwner,
    ]);
}

/**
 * GET /api/venue/recommended-bands
 * Returns bands ranked by genre match, past history, and performance score.
 */
function handleVenueRecommendedBands(PDO $pdo): void {
    $user    = apiIsLoggedIn() ? apiCurrentUser() : null;
    $isOwner = false;
    $userId  = 0;

    if ($user && ($user['type'] === 'venue' || ($user['is_admin'] ?? false))) {
        $userId  = (int)$user['id'];
        $isOwner = true;
    } elseif (isset($_GET['venue_id']) && (int)$_GET['venue_id'] > 0) {
        $vid = (int)$_GET['venue_id'];
        $chkStmt2 = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'venue' LIMIT 1");
        $chkStmt2->execute([$vid]);
        if (!$chkStmt2->fetchColumn()) {
            errorResponse('Venue not found', 404);
            return;
        }
        $userId = $vid;
    } else {
        errorResponse('Venue access required', 403);
        return;
    }

    $q           = trim($_GET['q'] ?? '');
    $genreFilter = trim($_GET['genre'] ?? '');
    $offset      = max(0, (int)($_GET['offset'] ?? 0));
    $limit       = min(50, max(1, (int)($_GET['limit'] ?? 24)));

    // Venue profile
    $pStmt = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ? AND type = 'venue' LIMIT 1");
    $pStmt->execute([$userId]);
    $pRow       = $pStmt->fetch();
    $venueData  = $pRow ? (json_decode($pRow['data'], true) ?: []) : [];
    $genresWelc = array_values(array_filter(array_map('trim', (array)($venueData['genres_welcomed'] ?? []))));
    $genresLow  = array_map('strtolower', $genresWelc);
    $venueName  = trim((string)($venueData['name'] ?? ''));
    $venueEmail = trim((string)($venueData['contact_email'] ?? ''));

    // Canonical slug
    $venueSlug = '';
    if ($venueName !== '') {
        $slugStmt = $pdo->prepare(
            "SELECT slug FROM event_sync_venues
             WHERE LOWER(display_name) = LOWER(:n) OR LOWER(name_key) = LOWER(:n) LIMIT 1"
        );
        $slugStmt->execute([':n' => $venueName]);
        $slugRow = $slugStmt->fetch();
        if ($slugRow) $venueSlug = (string)$slugRow['slug'];
    }

    // Past bands from scraped events at this venue
    $pastBandKeys = [];
    if ($venueName !== '' || $venueSlug !== '') {
        $pSql    = "SELECT se.bands FROM scraped_events se WHERE se.bands IS NOT NULL AND se.bands != 'null'";
        $pParams = [];
        if ($venueSlug !== '') {
            $pSql .= " AND se.canonical_venue_slug = ?";
            $pParams[] = $venueSlug;
        } else {
            $pSql .= " AND LOWER(se.venue_name) = LOWER(?)";
            $pParams[] = $venueName;
        }
        $pStmt2 = $pdo->prepare($pSql);
        $pStmt2->execute($pParams);
        foreach ($pStmt2->fetchAll() as $pbRow) {
            $bArr = json_decode($pbRow['bands'], true);
            if (!is_array($bArr)) continue;
            foreach ($bArr as $bn) {
                $key = panicCanonicalNameKey((string)$bn);
                if ($key !== '') $pastBandKeys[$key] = true;
            }
        }
    }

    // Past band user IDs via bookings
    $pastBandUserIds = [];
    $bkStmt = $pdo->prepare(
        "SELECT DISTINCT band_user_id FROM bookings
         WHERE venue_user_id = ? AND status IN ('completed','contracted','accepted','hold')"
    );
    $bkStmt->execute([$userId]);
    foreach ($bkStmt->fetchAll() as $bkRow) {
        $pastBandUserIds[(int)$bkRow['band_user_id']] = true;
    }

    // Fetch bands with performance scores
    $nameExpr    = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');
    $where       = ["u.type = 'band'", "COALESCE(p.is_archived, 0) = 0"];
    $params      = [];

    if ($q !== '') {
        $where[]      = "p.data LIKE :q";
        $params[':q'] = '%' . $q . '%';
    }
    if ($genreFilter !== '') {
        $where[]          = "p.data LIKE :genre";
        $params[':genre'] = '%' . $genreFilter . '%';
    }

    $whereClause = implode(' AND ', $where);
    $sql = "
        SELECT u.id AS user_id, p.id AS profile_id, p.data, p.is_generic, p.is_claimed,
               COALESCE(ps.composite_score, 0) AS composite_score,
               COALESCE(ps.estimated_draw, 0)  AS estimated_draw,
               COALESCE(ps.shows_tracked, 0)   AS shows_tracked,
               COALESCE(ps.last_show_date, '')  AS last_show_date
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        LEFT JOIN performer_scores ps ON ps.band_name = {$nameExpr}
        WHERE {$whereClause}
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Score and annotate each band
    $scored = [];
    foreach ($rows as $bandRow) {
        $bData      = json_decode($bandRow['data'], true) ?: [];
        $bandName   = trim((string)($bData['name'] ?? ''));
        $bandUserId = (int)$bandRow['user_id'];
        $profileId  = (int)$bandRow['profile_id'];
        $bGenres    = array_map('trim', (array)($bData['genres'] ?? []));

        // Genre overlap score (15 pts per matching genre)
        $genreMatches = [];
        foreach ($genresLow as $vg) {
            foreach ($bGenres as $bg) {
                if (strtolower($bg) === $vg) {
                    $genreMatches[] = $bg;
                }
            }
        }
        $genreScore = count($genreMatches) * 15;

        // Past-played bonus (35 pts)
        $playedHere = isset($pastBandUserIds[$bandUserId]);
        if (!$playedHere && $bandName !== '') {
            $nameKey    = panicCanonicalNameKey($bandName);
            $playedHere = $nameKey !== '' && isset($pastBandKeys[$nameKey]);
        }
        $playedScore = $playedHere ? 35 : 0;

        // Last-minute availability bonus (5 pts)
        $lastMinute = !empty($bData['available_last_minute']);
        $lmScore    = $lastMinute ? 5 : 0;

        $relevance = $genreScore + $playedScore + $lmScore + (float)$bandRow['composite_score'];

        $scored[] = [
            'id'                    => $bandUserId,
            'profile_id'            => $profileId,
            'name'                  => $bandName,
            'genres'                => array_values($bGenres),
            'description'           => (string)($bData['description'] ?? ''),
            'location'              => (string)($bData['location'] ?? ''),
            'website'               => (string)($bData['website'] ?? ''),
            'instagram'             => (string)($bData['instagram'] ?? ''),
            'has_own_equipment'     => !empty($bData['has_own_equipment']),
            'available_last_minute' => $lastMinute,
            'set_length_min'        => (int)($bData['set_length_min'] ?? 0),
            'set_length_max'        => (int)($bData['set_length_max'] ?? 0),
            'contact_email'         => (string)($bData['contact_email'] ?? ''),
            'booking_contact'       => (string)($bData['booking_contact'] ?? ''),
            'is_generic'            => (bool)$bandRow['is_generic'],
            'is_claimed'            => (bool)$bandRow['is_claimed'],
            'composite_score'       => round((float)$bandRow['composite_score'], 1),
            'estimated_draw'        => (int)$bandRow['estimated_draw'],
            'shows_tracked'         => (int)$bandRow['shows_tracked'],
            'last_show_date'        => (string)$bandRow['last_show_date'],
            'relevance_score'       => round($relevance, 1),
            'genre_matches'         => array_values(array_unique($genreMatches)),
            'played_here'           => $playedHere,
        ];
    }

    // Sort by relevance descending
    usort($scored, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

    $total = count($scored);
    $paged = array_values(array_slice($scored, $offset, $limit));

    jsonResponse([
        'bands'        => $paged,
        'total'        => $total,
        'offset'       => $offset,
        'limit'        => $limit,
        'venue_genres' => $genresWelc,
        'venue_name'   => $venueName,
        'venue_email'  => $venueEmail,
    ]);
}
