<?php
// Venue scraper: GAMH, Warfield, Regency Ballroom, Fillmore
// CLI: php scrape_venues.php [venue]
// Web: disabled by default; set PB_ALLOW_WEB_MAINTENANCE=1 and PB_MAINTENANCE_TOKEN.

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('scrape_venues.php');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/api/includes/db.php';
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// TM_API_KEY: prefer config.local.php, fall back to environment variable
if (!defined('TM_API_KEY')) {
    define('TM_API_KEY', defined('TM_CONSUMER_KEY') ? TM_CONSUMER_KEY : (getenv('TM_API_KEY') ?: ''));
}

const TM_VENUES = [
    'KovZpZAE6eeA' => ['name' => 'The Fillmore',               'city' => 'S.F.'],
    'KovZpaKope'   => ['name' => 'Bill Graham Civic Auditorium', 'city' => 'S.F.'],
    'KovZpZAJe6lA' => ['name' => 'The Warfield',               'city' => 'S.F.'],
];

// ------------------------------------------------------------
// Shared helpers
// ------------------------------------------------------------

function venues_fetch(string $url): string|false {
    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: Mozilla/5.0 (compatible; PanicBookingScraper/1.0)\r\n",
            'timeout' => 30,
        ],
    ];
    $ctx = stream_context_create($opts);
    return @file_get_contents($url, false, $ctx);
}

function venues_decode(string $s): string {
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function venues_clean(string $s): string {
    return trim(preg_replace('/\s+/', ' ', $s));
}

function venues_insert(PDO $pdo, PDOStatement $stmt, array $data): bool {
    try {
        $stmt->execute($data);
        return true;
    } catch (PDOException $e) {
        echo "  DB error: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Convert a 24h time string like "19:30:00" or "19:30" to "7:30pm".
 */
function venues_time24to12(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    // Already in 12h format?
    if (preg_match('/\d{1,2}(?::\d{2})?\s*(?:am|pm)/i', $t)) {
        return strtolower(preg_replace('/\s+/', '', $t));
    }
    // Parse HH:MM or HH:MM:SS
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) return strtolower($t);
    $h = (int)$m[1];
    $min = $m[2];
    $suffix = $h >= 12 ? 'pm' : 'am';
    $h12 = $h % 12;
    if ($h12 === 0) $h12 = 12;
    return $min === '00' ? "{$h12}{$suffix}" : "{$h12}:{$min}{$suffix}";
}

/**
 * Parse a date string like "Tue Mar 24" into "YYYY-MM-DD".
 * Uses current year; rolls over to next year if month < current month and we're in Oct+.
 */
function venues_parse_short_date(string $s): string {
    $s = venues_clean($s);
    // e.g. "Tue Mar 24" — strip day-of-week if present
    $months = [
        'Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
        'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12,
    ];
    if (!preg_match('/([A-Za-z]{3})\s+(\d{1,2})/', $s, $m)) return '';
    $mon = $months[ucfirst(strtolower($m[1]))] ?? 0;
    $day = (int)$m[2];
    if (!$mon || !$day) return '';
    $curMonth = (int)date('n');
    $curYear  = (int)date('Y');
    $year = $curYear;
    if ($mon < $curMonth && $curMonth >= 10) {
        $year++;
    }
    return sprintf('%04d-%02d-%02d', $year, $mon, $day);
}

// ------------------------------------------------------------
// Shared INSERT statement (all columns including source)
// ------------------------------------------------------------

$insertStmt = $pdo->prepare(panicScrapedEventsUpsertSql($pdo));

// ------------------------------------------------------------
// CLI / web argument
// ------------------------------------------------------------

$venueArg = 'all';
if (PHP_SAPI === 'cli') {
    $venueArg = $argv[1] ?? 'all';
} else {
    $venueArg = $_GET['venue'] ?? 'all';
}
$venueArg = strtolower(trim($venueArg));

$totals = [];

// ============================================================
// Scraper A: Great American Music Hall (GAMH)
// ============================================================

function scrape_gamh(PDO $pdo, PDOStatement $stmt): int {
    $calUrl = 'https://gamh.com/calendar/';
    echo "\n[GAMH] Fetching: {$calUrl}\n";
    flush();

    $html = venues_fetch($calUrl);
    if ($html === false) {
        echo "[GAMH] ERROR: failed to fetch page.\n";
        return 0;
    }

    // Extract nonce from inline JS: seetickets_ajax_obj = {"ajax_url":"...","nonce":"..."}
    $nonce   = '';
    $ajaxUrl = 'https://gamh.com/wp-admin/admin-ajax.php';
    if (preg_match('/seetickets_ajax_obj\s*=\s*\{[^}]*"ajax_url"\s*:\s*"([^"]+)"[^}]*"nonce"\s*:\s*"([^"]+)"/s', $html, $nm)) {
        $ajaxUrl = $nm[1];
        $nonce   = $nm[2];
    } elseif (preg_match('/seetickets_ajax_obj\s*=\s*\{[^}]*"nonce"\s*:\s*"([^"]+)"/s', $html, $nm)) {
        $nonce = $nm[1];
    }

    // Total pages from data-see-total-pages attribute
    $totalPages = 1;
    if (preg_match('/data-see-total-pages=["\'](\d+)["\']/', $html, $pm)) {
        $totalPages = max(1, (int)$pm[1]);
    }
    echo "[GAMH] Nonce: {$nonce}, Pages: {$totalPages}\n";

    // Collect all HTML chunks: initial page + AJAX pages 2..N
    $htmlChunks = [$html];
    for ($page = 2; $page <= $totalPages; $page++) {
        $pageUrl = $ajaxUrl . '?action=get_seetickets_events'
            . '&nonce=' . urlencode($nonce)
            . '&seeAjaxPage=' . $page
            . '&listType=list';
        echo "[GAMH] Fetching page {$page}: {$pageUrl}\n";
        flush();
        $chunk = venues_fetch($pageUrl);
        if ($chunk !== false && strlen($chunk) > 50) {
            $htmlChunks[] = $chunk;
        }
    }

    $count = 0;
    foreach ($htmlChunks as $chunk) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $chunk . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Each event card
        $containers = $xpath->query('//div[contains(@class,"seetickets-list-event-container")]');
        if ($containers === false || $containers->length === 0) continue;

        foreach ($containers as $container) {
            // Title from p.event-title > a
            $titleNodes = $xpath->query('.//p[contains(@class,"event-title")]//a | .//h2[contains(@class,"event-title")]//a', $container);
            if (!$titleNodes || $titleNodes->length === 0) continue;
            $titleText = venues_decode(venues_clean($titleNodes->item(0)->textContent));
            if ($titleText === '') continue;

            // Ticket URL
            $ticketUrl = 'https://gamh.com/calendar/';
            $href = $titleNodes->item(0)->getAttribute('href');
            if ($href) $ticketUrl = $href;

            // Headliners from p.headliners (plain text names, comma-separated)
            $bands = [];
            $headNodes = $xpath->query('.//p[contains(@class,"headliners")]', $container);
            if ($headNodes && $headNodes->length > 0) {
                $headText = venues_decode(venues_clean($headNodes->item(0)->textContent));
                foreach (array_filter(array_map('trim', explode(',', $headText))) as $b) {
                    $b = trim($b);
                    if ($b !== '') $bands[] = $b;
                }
            }
            // Supporting acts from p.supporting-talent — strip leading "with "
            $suppNodes = $xpath->query('.//p[contains(@class,"supporting-talent")]', $container);
            if ($suppNodes && $suppNodes->length > 0) {
                $suppText = venues_decode(venues_clean($suppNodes->item(0)->textContent));
                $suppText = preg_replace('/^\s*with\s+/i', '', $suppText);
                foreach (array_filter(array_map('trim', explode(',', $suppText))) as $b) {
                    $b = trim($b);
                    if ($b !== '') $bands[] = $b;
                }
            }
            if (empty($bands)) {
                $bands = [$titleText];
            }

            // Date from p.event-date e.g. "Tue Mar 24"
            $dateNodes = $xpath->query('.//p[contains(@class,"event-date")]', $container);
            $dateRaw   = $dateNodes && $dateNodes->length > 0
                ? venues_clean($dateNodes->item(0)->textContent) : '';
            $eventDate = venues_parse_short_date($dateRaw);
            if ($eventDate === '') continue;

            // Doors time from span.see-doortime, show time from span.see-showtime
            $doorsTime = '';
            $showTime  = '';
            $doorSpan = $xpath->query('.//*[contains(@class,"see-doortime")]', $container);
            if ($doorSpan && $doorSpan->length > 0) {
                $doorsTime = strtolower(venues_clean($doorSpan->item(0)->textContent));
            }
            $showSpan = $xpath->query('.//*[contains(@class,"see-showtime")]', $container);
            if ($showSpan && $showSpan->length > 0) {
                $showTime = strtolower(venues_clean($showSpan->item(0)->textContent));
            }

            // Price from span.price
            $price = '';
            $priceNodes = $xpath->query('.//span[contains(@class,"price")]', $container);
            if ($priceNodes && $priceNodes->length > 0) {
                $price = venues_clean($priceNodes->item(0)->textContent);
            }

            // Age restriction — look for "All Ages" or "21+" in event-header or genre
            $ageRestriction = '';
            $headerNodes = $xpath->query('.//p[contains(@class,"event-header")]', $container);
            if ($headerNodes && $headerNodes->length > 0) {
                $headerText = venues_clean($headerNodes->item(0)->textContent);
                if (preg_match('/all\s*ages|18\+|21\+/i', $headerText, $am)) {
                    $ageRestriction = $am[0];
                }
            }

            // Sold out: buy button text
            $isSoldOut = 0;
            $btnNodes = $xpath->query('.//a[contains(@class,"seetickets-buy-btn")]', $container);
            if ($btnNodes && $btnNodes->length > 0) {
                if (stripos(venues_clean($btnNodes->item(0)->textContent), 'sold out') !== false) {
                    $isSoldOut = 1;
                }
            }

            $bandsJson = json_encode(array_values(array_unique($bands)), JSON_UNESCAPED_UNICODE);

            $ok = venues_insert($pdo, $stmt, [
                ':event_date'      => $eventDate,
                ':venue_name'      => 'Great American Music Hall',
                ':venue_city'      => 'S.F.',
                ':bands'           => $bandsJson,
                ':age_restriction' => $ageRestriction,
                ':price'           => $price,
                ':doors_time'      => $doorsTime,
                ':show_time'       => $showTime,
                ':is_sold_out'     => $isSoldOut,
                ':is_ticketed'     => 1,
                ':notes'           => '',
                ':raw_meta'        => '',
                ':source_url'      => $ticketUrl,
                ':source'          => 'gamh',
            ]);
            if ($ok) $count++;
        }
    }

    echo "[GAMH] Inserted/replaced: {$count}\n";
    return $count;
}

// ============================================================
// Scraper B: The Warfield
// ============================================================

function scrape_warfield(PDO $pdo, PDOStatement $stmt): int {
    $url = 'https://www.thewarfieldtheatre.com/events';
    echo "\n[Warfield] Fetching: {$url}\n";
    flush();

    $html = venues_fetch($url);
    if ($html === false) {
        echo "[Warfield] ERROR: failed to fetch page.\n";
        return 0;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $items = $xpath->query('//div[contains(@class,"event-item")]');
    if ($items === false || $items->length === 0) {
        echo "[Warfield] No event items found.\n";
        return 0;
    }

    $monthMap = [
        'Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
        'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12,
        'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
        'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
    ];

    $count = 0;
    foreach ($items as $item) {
        // Artist: h3 a
        $h3aNodes = $xpath->query('.//h3//a', $item);
        if ($h3aNodes === false || $h3aNodes->length === 0) continue;
        $artist = venues_decode(venues_clean($h3aNodes->item(0)->textContent));
        if ($artist === '') continue;

        // Support acts: h4 text, strip leading "with "
        $bands = [$artist];
        $h4Nodes = $xpath->query('.//h4', $item);
        if ($h4Nodes && $h4Nodes->length > 0) {
            $h4Text = venues_clean($h4Nodes->item(0)->textContent);
            $h4Text = preg_replace('/^with\s+/i', '', $h4Text);
            if ($h4Text !== '') {
                $supports = preg_split('/\s*[,&]\s*/', $h4Text);
                foreach ($supports as $s) {
                    $s = venues_decode(venues_clean($s));
                    if ($s !== '') $bands[] = $s;
                }
            }
        }

        // Date/time: look for a <p> matching the date pattern
        // Pattern: "Tue, Mar 31, 2026 Show 7:30 PM"
        $eventDate = '';
        $showTime  = '';
        $pNodes = $xpath->query('.//p', $item);
        if ($pNodes) {
            foreach ($pNodes as $pNode) {
                $pText = venues_clean($pNode->textContent);
                // Match: "Weekday, Month Day, Year Show H:MM AM/PM" or variants
                if (preg_match('/([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})\s+Show\s+(\d{1,2}:\d{2}\s*[AP]M)/i', $pText, $m)) {
                    $mon = $monthMap[$m[1]] ?? 0;
                    if (!$mon) {
                        // Try with weekday prefix stripped: "Tue, Mar 31, 2026..."
                        if (preg_match('/[A-Za-z]{2,4},\s+([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})\s+Show\s+(\d{1,2}:\d{2}\s*[AP]M)/i', $pText, $m2)) {
                            $mon = $monthMap[$m2[1]] ?? 0;
                            $day = (int)$m2[2];
                            $yr  = (int)$m2[3];
                            $showTime = strtolower(preg_replace('/\s+/', '', $m2[4]));
                            if ($mon && $day && $yr) {
                                $eventDate = sprintf('%04d-%02d-%02d', $yr, $mon, $day);
                            }
                        }
                    } else {
                        $day = (int)$m[2];
                        $yr  = (int)$m[3];
                        $showTime = strtolower(preg_replace('/\s+/', '', $m[4]));
                        if ($day && $yr) {
                            $eventDate = sprintf('%04d-%02d-%02d', $yr, $mon, $day);
                        }
                    }
                    if ($eventDate !== '') break;
                }
            }
        }
        if ($eventDate === '') continue;

        // Sold out: check ticket link text
        $isSoldOut = 0;
        $linkNodes = $xpath->query('.//a', $item);
        if ($linkNodes) {
            foreach ($linkNodes as $link) {
                $lt = venues_clean($link->textContent);
                if (stripos($lt, 'sold out') !== false) {
                    $isSoldOut = 1;
                    break;
                }
            }
            if (!$isSoldOut && $linkNodes->length === 0) {
                $isSoldOut = 1;
            }
        }

        $bandsJson = json_encode($bands, JSON_UNESCAPED_UNICODE);

        $ok = venues_insert($pdo, $stmt, [
            ':event_date'      => $eventDate,
            ':venue_name'      => 'The Warfield',
            ':venue_city'      => 'S.F.',
            ':bands'           => $bandsJson,
            ':age_restriction' => '',
            ':price'           => '',
            ':doors_time'      => '',
            ':show_time'       => $showTime,
            ':is_sold_out'     => $isSoldOut,
            ':is_ticketed'     => 1,
            ':notes'           => '',
            ':raw_meta'        => '',
            ':source_url'      => 'https://www.thewarfieldtheatre.com/events',
            ':source'          => 'warfield',
        ]);
        if ($ok) $count++;
    }

    echo "[Warfield] Inserted/replaced: {$count}\n";
    return $count;
}

// ============================================================
// Scraper C: Regency Ballroom
// ============================================================

function scrape_regency(PDO $pdo, PDOStatement $stmt): int {
    $pageUrl = 'https://theregencyballroom.com/shows/';
    echo "\n[Regency] Fetching page: {$pageUrl}\n";
    flush();

    $html = venues_fetch($pageUrl);
    if ($html === false) {
        echo "[Regency] ERROR: failed to fetch page.\n";
        return 0;
    }

    // Dynamic JSON discovery
    $jsonUrl = '';
    if (preg_match('/data-file="([^"]+)"/i', $html, $m)) {
        $jsonUrl = $m[1];
        // Make absolute if relative
        if (strpos($jsonUrl, 'http') !== 0) {
            $jsonUrl = 'https://theregencyballroom.com' . $jsonUrl;
        }
        echo "[Regency] Found JSON feed: {$jsonUrl}\n";
    } else {
        // Fallback URLs
        $fallbacks = [
            'https://aegwebprod.blob.core.windows.net/json/events/9/events.json',
            'https://theregencyballroom.com/shows/events.json',
            'https://theregencyballroom.com/wp-content/uploads/events.json',
        ];
        foreach ($fallbacks as $fb) {
            echo "[Regency] Trying fallback: {$fb}\n";
            $test = venues_fetch($fb);
            if ($test !== false && strlen($test) > 10) {
                $jsonUrl = $fb;
                break;
            }
        }
    }

    if ($jsonUrl === '') {
        echo "[Regency] ERROR: could not find JSON feed URL.\n";
        return 0;
    }

    echo "[Regency] Fetching JSON: {$jsonUrl}\n";
    flush();

    $jsonRaw = venues_fetch($jsonUrl);
    if ($jsonRaw === false) {
        echo "[Regency] ERROR: failed to fetch JSON feed.\n";
        return 0;
    }

    $data = json_decode($jsonRaw, true);
    if (!is_array($data)) {
        echo "[Regency] ERROR: invalid JSON response.\n";
        return 0;
    }

    // Expect {"events": [...]}
    $events = $data['events'] ?? null;
    if (!is_array($events)) {
        echo "[Regency] ERROR: unexpected JSON structure (no 'events' key).\n";
        return 0;
    }

    $count = 0;
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;

        // Skip inactive/private events
        if (empty($ev['active'])) continue;

        // Primary headliner name from title.headlinersText
        $titleText = $ev['title']['headlinersText'] ?? '';
        if ($titleText === '') continue;

        // Bands: headliners + supporting acts (strip leading "with ")
        $bands = array_filter(array_map('trim', explode(',', $titleText)));
        $supportingText = $ev['title']['supportingText'] ?? '';
        if ($supportingText !== '') {
            // Strip leading "with " prefix
            $supportingText = preg_replace('/^\s*with\s+/i', '', $supportingText);
            foreach (array_filter(array_map('trim', explode(',', $supportingText))) as $s) {
                if ($s !== '') $bands[] = $s;
            }
        }
        $bands = array_values(array_unique($bands));
        if (empty($bands)) {
            $bands = [$titleText];
        }

        // Date/time from eventDateTime (ISO 8601, local time)
        $startDt = $ev['eventDateTime'] ?? '';
        if ($startDt === '') continue;
        $dt = @strtotime($startDt);
        if ($dt === false || $dt === -1) continue;
        $eventDate = date('Y-m-d', $dt);
        $showTime  = venues_time24to12(date('H:i', $dt));

        // Doors time from doorDateTime
        $doorsTime = '';
        $doorDt = $ev['doorDateTime'] ?? '';
        if ($doorDt !== '') {
            $ddt = @strtotime($doorDt);
            if ($ddt !== false && $ddt !== -1) {
                $doorsTime = venues_time24to12(date('H:i', $ddt));
            }
        }

        // Age restriction
        $ageRestriction = $ev['age'] ?? '';

        // Price — use low/high range if available
        $priceLow  = trim($ev['ticketPriceLow'] ?? '');
        $priceHigh = trim($ev['ticketPriceHigh'] ?? '');
        $price = '';
        if ($priceLow !== '' && $priceLow !== '$0' && $priceLow !== '$0.00') {
            $price = ($priceHigh !== '' && $priceHigh !== $priceLow) ? "{$priceLow}–{$priceHigh}" : $priceLow;
        }

        // Sold out: statusId 3 = Sold Out, 4 = Cancelled; active=false also indicates unavailable
        $statusId  = $ev['ticketing']['statusId'] ?? 1;
        $isSoldOut = ($statusId === 3) ? 1 : 0;

        // Ticket URL
        $sourceUrl = $ev['ticketing']['ticketURL'] ?? $ev['ticketing']['url'] ?? 'https://theregencyballroom.com/shows/';

        $bandsJson = json_encode($bands, JSON_UNESCAPED_UNICODE);

        $ok = venues_insert($pdo, $stmt, [
            ':event_date'      => $eventDate,
            ':venue_name'      => 'Regency Ballroom',
            ':venue_city'      => 'S.F.',
            ':bands'           => $bandsJson,
            ':age_restriction' => $ageRestriction,
            ':price'           => $price,
            ':doors_time'      => $doorsTime,
            ':show_time'       => $showTime,
            ':is_sold_out'     => $isSoldOut,
            ':is_ticketed'     => 1,
            ':notes'           => '',
            ':raw_meta'        => '',
            ':source_url'      => $sourceUrl,
            ':source'          => 'regency',
        ]);
        if ($ok) $count++;
    }

    echo "[Regency] Inserted/replaced: {$count}\n";
    return $count;
}

// ============================================================
// Scraper D: The Fillmore (Next.js SSR payload)
// ============================================================

/**
 * Parse a Fillmore event title into headliner + support bands.
 * e.g. "Jeff Tweedy with special guest Macie Stewart" → ["Jeff Tweedy", "Macie Stewart"]
 *      "Los Lobos with Philthy Dronez"                → ["Los Lobos", "Philthy Dronez"]
 */
function fillmore_parse_bands(string $name): array {
    if (preg_match('/^(.+?)\s+(?:with(?:\s+special\s+guests?)?|w\/)\s+(.+)$/i', $name, $m)) {
        $bands = [trim($m[1])];
        foreach (preg_split('/\s*[,&]\s*/', trim($m[2])) as $s) {
            $s = trim($s);
            if ($s !== '') $bands[] = $s;
        }
        return $bands;
    }
    return [$name];
}

function scrape_fillmore(PDO $pdo, PDOStatement $stmt): int {
    $url = 'https://www.thefillmore.com/shows';
    echo "\n[Fillmore] Fetching: {$url}\n";
    flush();

    $html = venues_fetch($url);
    if ($html === false) {
        echo "[Fillmore] ERROR: failed to fetch page.\n";
        return 0;
    }

    // The Fillmore is a Next.js app. Event data is embedded in the SSR payload as
    // JSON-encoded strings inside self.__next_f.push([N,"..."]) calls.
    // Each event object has: name, start_date_local, start_time_local, status_code, url, genre
    $events = [];

    // 1. Extract all push payloads that mention start_date_local
    preg_match_all('/self\.__next_f\.push\(\[\d+,"((?:[^"\\\\]|\\\\.)*)"\]\)/s', $html, $chunks);
    foreach ($chunks[1] as $raw) {
        // Decode the JSON-escaped string (it's a JSON string literal without outer quotes)
        $decoded = json_decode('"' . $raw . '"');
        if ($decoded === null || strpos($decoded, 'start_date_local') === false) continue;

        // 2a. Try to extract the "data" array directly (preferred)
        if (preg_match('/"data"\s*:\s*(\[.+\])/s', $decoded, $dm)) {
            $arr = json_decode($dm[1], true);
            if (is_array($arr) && !empty($arr)) {
                $events = array_merge($events, $arr);
                continue;
            }
        }

        // 2b. Fallback: match individual event objects by their fixed field sequence
        preg_match_all(
            '/"name"\s*:\s*"([^"\\\\](?:[^"\\\\]|\\\\.)*)"\s*,\s*'
            . '"slug"\s*:\s*"[^"]*"\s*,\s*'
            . '"url"\s*:\s*"([^"\\\\](?:[^"\\\\]|\\\\.)*?)"\s*,\s*'
            . '"type"\s*:\s*"[^"]*"\s*,\s*'
            . '"start_date_local"\s*:\s*"(\d{4}-\d{2}-\d{2})"\s*,\s*'
            . '"start_time_local"\s*:\s*"(\d{2}:\d{2}:\d{2})"\s*,\s*'
            . '"timezone"\s*:\s*"[^"]*"\s*,\s*'
            . '"start_datetime_utc"\s*:\s*"[^"]*"\s*,\s*'
            . '"status_code"\s*:\s*"([^"]*)"/',
            $decoded,
            $fm,
            PREG_SET_ORDER
        );
        foreach ($fm as $match) {
            $events[] = [
                'name'             => json_decode('"' . $match[1] . '"') ?? $match[1],
                'url'              => json_decode('"' . $match[2] . '"') ?? $match[2],
                'start_date_local' => $match[3],
                'start_time_local' => $match[4],
                'status_code'      => $match[5],
            ];
        }
    }

    if (empty($events)) {
        echo "[Fillmore] No events found in SSR payload.\n";
        return 0;
    }

    $count = 0;
    $seen  = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;

        $name = trim($ev['name'] ?? '');
        if ($name === '') continue;

        $eventDate = $ev['start_date_local'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) continue;

        // Deduplicate (the payload may repeat events across multiple push() chunks)
        $dedupeKey = $eventDate . '|' . $name;
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;

        $showTime  = venues_time24to12($ev['start_time_local'] ?? '');
        $status    = strtolower($ev['status_code'] ?? 'onsale');
        $isSoldOut = ($status === 'offsale' || $status === 'cancelled') ? 1 : 0;
        $sourceUrl = $ev['url'] ?? 'https://www.thefillmore.com/shows';

        $bands     = fillmore_parse_bands(venues_decode($name));
        $bandsJson = json_encode($bands, JSON_UNESCAPED_UNICODE);

        $ok = venues_insert($pdo, $stmt, [
            ':event_date'      => $eventDate,
            ':venue_name'      => 'The Fillmore',
            ':venue_city'      => 'S.F.',
            ':bands'           => $bandsJson,
            ':age_restriction' => '',
            ':price'           => '',
            ':doors_time'      => '',
            ':show_time'       => $showTime,
            ':is_sold_out'     => $isSoldOut,
            ':is_ticketed'     => 1,
            ':notes'           => '',
            ':raw_meta'        => '',
            ':source_url'      => $sourceUrl,
            ':source'          => 'fillmore',
        ]);
        if ($ok) $count++;
    }

    echo "[Fillmore] Inserted/replaced: {$count}\n";
    return $count;
}

// ============================================================
// Scraper E: Fillmore + Bill Graham via Ticketmaster API
//   (optional — only runs if TM_API_KEY env var is set)
// ============================================================

function scrape_ticketmaster(PDO $pdo, PDOStatement $stmt): int {
    if (!defined('TM_API_KEY') || TM_API_KEY === '') {
        echo "\n[Ticketmaster] Skipped: TM_API_KEY is not set.\n";
        echo "[Ticketmaster] To enable, set the TM_API_KEY environment variable:\n";
        echo "[Ticketmaster]   export TM_API_KEY=your_key_here\n";
        echo "[Ticketmaster] Get a free key at https://developer.ticketmaster.com/\n";
        return 0;
    }

    $venueIds = implode(',', array_keys(TM_VENUES));
    $baseEndpoint = 'https://app.ticketmaster.com/discovery/v2/events.json';

    $count = 0;
    $page  = 0;
    $totalPages = 1; // Will be updated after first request

    echo "\n[Ticketmaster] Starting scrape for venues: " . implode(', ', array_column(TM_VENUES, 'name')) . "\n";
    flush();

    do {
        $params = http_build_query([
            'apikey'             => TM_API_KEY,
            'venueId'            => $venueIds,
            'classificationName' => 'Music',
            'size'               => 100,
            'sort'               => 'date,asc',
            'countryCode'        => 'US',
            'page'               => $page,
        ]);
        $url = $baseEndpoint . '?' . $params;
        echo "[Ticketmaster] Fetching page {$page}" . ($totalPages > 1 ? "/{$totalPages}" : '') . "\n";
        flush();

        $raw = venues_fetch($url);
        if ($raw === false) {
            echo "[Ticketmaster] ERROR: failed to fetch page {$page}.\n";
            break;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo "[Ticketmaster] ERROR: invalid JSON on page {$page}.\n";
            break;
        }

        // Update total pages
        $totalPages = (int)($data['page']['totalPages'] ?? 1);

        $events = $data['_embedded']['events'] ?? [];
        if (!is_array($events)) {
            echo "[Ticketmaster] No events on page {$page}.\n";
            $page++;
            continue;
        }

        foreach ($events as $ev) {
            if (!is_array($ev)) continue;

            $name = trim($ev['name'] ?? '');
            if ($name === '') continue;

            // Bands from attractions
            $bands = [];
            $attractions = $ev['_embedded']['attractions'] ?? [];
            if (is_array($attractions)) {
                foreach ($attractions as $att) {
                    $aName = trim($att['name'] ?? '');
                    if ($aName !== '') $bands[] = $aName;
                }
            }
            if (empty($bands)) $bands = [$name];

            // Date and time
            $localDate = $ev['dates']['start']['localDate'] ?? '';
            $localTime = $ev['dates']['start']['localTime'] ?? '';
            if ($localDate === '') continue;
            $showTime = $localTime !== '' ? venues_time24to12($localTime) : '';

            // Venue lookup
            $evVenues = $ev['_embedded']['venues'] ?? [];
            $venueId  = $evVenues[0]['id'] ?? '';
            $venueInfo = TM_VENUES[$venueId] ?? null;
            if ($venueInfo === null) {
                // Use the venue name from the response as fallback
                $venueName = trim($evVenues[0]['name'] ?? $name);
                $venueCity = 'S.F.';
            } else {
                $venueName = $venueInfo['name'];
                $venueCity = $venueInfo['city'];
            }

            // Price
            $price = '';
            $priceRanges = $ev['priceRanges'] ?? [];
            if (is_array($priceRanges) && !empty($priceRanges)) {
                $pr  = $priceRanges[0];
                $min = $pr['min'] ?? null;
                $max = $pr['max'] ?? null;
                if ($min !== null && $max !== null) {
                    $price = '$' . (int)$min . '–$' . (int)$max;
                } elseif ($min !== null) {
                    $price = '$' . (int)$min;
                }
            }

            // Sold out
            $statusCode = strtolower($ev['statusCode'] ?? $ev['dates']['status']['code'] ?? '');
            $isSoldOut  = ($statusCode === 'offsale') ? 1 : 0;

            // Event URL
            $sourceUrl = $ev['url'] ?? '';

            $bandsJson = json_encode($bands, JSON_UNESCAPED_UNICODE);

            $ok = venues_insert($pdo, $stmt, [
                ':event_date'      => $localDate,
                ':venue_name'      => $venueName,
                ':venue_city'      => $venueCity,
                ':bands'           => $bandsJson,
                ':age_restriction' => '',
                ':price'           => $price,
                ':doors_time'      => '',
                ':show_time'       => $showTime,
                ':is_sold_out'     => $isSoldOut,
                ':is_ticketed'     => 1,
                ':notes'           => '',
                ':raw_meta'        => '',
                ':source_url'      => $sourceUrl,
                ':source'          => 'ticketmaster',
            ]);
            if ($ok) $count++;
        }

        $page++;
        if ($page < $totalPages) {
            usleep(250000); // 0.25s between paginated requests
        }
    } while ($page < $totalPages);

    echo "[Ticketmaster] Inserted/replaced: {$count}\n";
    return $count;
}

// ============================================================
// Run scrapers based on $venueArg
// ============================================================

// 'all' runs these four in order; 'ticketmaster' is opt-in only
$scrapers = [
    'gamh'         => 'scrape_gamh',
    'warfield'     => 'scrape_warfield',
    'regency'      => 'scrape_regency',
    'fillmore'     => 'scrape_fillmore',
    'ticketmaster' => 'scrape_ticketmaster',  // opt-in: requires TM_API_KEY
];

$ran = false;
foreach ($scrapers as $key => $fn) {
    if ($venueArg === 'all' || $venueArg === $key) {
        // Don't run ticketmaster automatically — it needs an API key
        if ($venueArg === 'all' && $key === 'ticketmaster') continue;
        if (!isset($totals[$fn])) {
            $totals[$fn] = $fn($pdo, $insertStmt);
        }
        $ran = true;
    }
}

if (!$ran) {
    echo "Unknown venue '{$venueArg}'. Valid options: gamh, warfield, regency, fillmore, all\n";
}

echo "\n=====================\n";
echo "Venue scrape complete.\n";
$grandTotal = array_sum($totals);
echo "Total events inserted/replaced: {$grandTotal}\n";

$labelMap = [
    'scrape_gamh'          => 'GAMH',
    'scrape_warfield'      => 'Warfield',
    'scrape_regency'       => 'Regency Ballroom',
    'scrape_fillmore'      => 'The Fillmore',
    'scrape_ticketmaster'  => 'Ticketmaster (Bill Graham + more)',
];
foreach ($totals as $fn => $n) {
    $label = $labelMap[$fn] ?? $fn;
    echo "  {$label}: {$n}\n";
}
