<?php
// Foopee "The List" scraper — SF shows only
// CLI: php scrape_foopee.php
// Web: disabled by default; set PB_ALLOW_WEB_MAINTENANCE=1 and PB_MAINTENANCE_TOKEN.

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('scrape_foopee.php');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/api/includes/db.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

function foopee_fetch(string $url): string|false {
    $opts = [
        'http' => [
            'method'     => 'GET',
            'header'     => "User-Agent: Mozilla/5.0 (compatible; PanicBookingScraper/1.0)\r\n",
            'timeout'    => 30,
        ],
    ];
    $ctx = stream_context_create($opts);
    return @file_get_contents($url, false, $ctx);
}

/**
 * Parse "Mon Mar 23" -> "2026-MM-DD"
 * The List covers Mar 2026 – Dec 2026.
 */
function foopee_parse_date(string $dayStr): string {
    // e.g. "Mon Mar 23" or "Tue Apr 7"
    $parts = preg_split('/\s+/', trim($dayStr));
    // $parts[0] = day-of-week, $parts[1] = month abbr, $parts[2] = day num
    if (count($parts) < 3) return '';
    $months = [
        'Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
        'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12,
    ];
    $mon = $months[$parts[1]] ?? 0;
    $day = (int)$parts[2];
    if (!$mon || !$day) return '';
    // Site covers Mar 2026 – Dec 2026
    $year = 2026;
    return sprintf('%04d-%02d-%02d', $year, $mon, $day);
}

/**
 * Normalise whitespace and trim a string.
 */
function foopee_clean(string $s): string {
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Extract the plain-text of a DOMNode, stripping all inner tags.
 */
function foopee_node_text(DOMNode $node): string {
    return foopee_clean($node->textContent);
}

/**
 * Decode HTML entities generously.
 */
function foopee_decode(string $s): string {
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Parse meta string (everything after venue + band names stripped out).
 * Returns array with keys: age_restriction, price, doors_time, show_time,
 *                          is_ticketed, is_sold_out, notes.
 */
function foopee_parse_meta(string $meta): array {
    $result = [
        'age_restriction' => '',
        'price'           => '',
        'doors_time'      => '',
        'show_time'       => '',
        'is_ticketed'     => 0,
        'is_sold_out'     => 0,
        'notes'           => '',
    ];

    // Ticketed marker
    if (str_contains($meta, '#')) {
        $result['is_ticketed'] = 1;
        $meta = str_replace('#', '', $meta);
    }

    // Capacity / misc flag
    $meta = str_replace('@', '', $meta);
    // Under-21 surcharge flag
    $meta = str_replace('^', '', $meta);

    // Extract parenthesised notes
    $noteParts = [];
    $meta = preg_replace_callback('/\(([^)]*)\)/i', function($m) use (&$noteParts) {
        $noteParts[] = trim($m[1]);
        return ' ';
    }, $meta);

    // Sold out
    foreach ($noteParts as $np) {
        if (stripos($np, 'sold out') !== false) {
            $result['is_sold_out'] = 1;
        }
    }
    if (stripos($meta, 'sold out') !== false) {
        $result['is_sold_out'] = 1;
        $meta = preg_replace('/sold\s+out/i', '', $meta);
    }

    $result['notes'] = implode('; ', array_filter($noteParts));

    $meta = foopee_clean($meta);

    // Age restriction: must be first substantial token
    // Patterns: a/a, all ages, 21+, 18+, 16+, 12+, 5+, ?/?
    if (preg_match('/^(a\/a|all\s+ages|21\+|18\+|16\+|12\+|5\+|\?\/\?)/i', $meta, $m)) {
        $result['age_restriction'] = foopee_clean($m[1]);
        $meta = foopee_clean(substr($meta, strlen($m[0])));
    }

    // Time pattern: e.g. "7pm/8pm", "6:30pm/7:30pm", "8pm", "7:30pm"
    // doors/show or just show
    if (preg_match('/(\d{1,2}(?::\d{2})?(?:am|pm))\/(\d{1,2}(?::\d{2})?(?:am|pm))/i', $meta, $m)) {
        $result['doors_time'] = strtolower($m[1]);
        $result['show_time']  = strtolower($m[2]);
        $meta = str_replace($m[0], '', $meta);
    } elseif (preg_match('/(\d{1,2}(?::\d{2})?(?:am|pm))/i', $meta, $m)) {
        $result['show_time'] = strtolower($m[1]);
        $meta = str_replace($m[0], '', $meta);
    }

    $meta = foopee_clean($meta);

    // Price: "free", "$18/$22", "$18", "$65.10", "$50"
    if (preg_match('/\bfree\b/i', $meta, $m)) {
        $result['price'] = 'free';
        $meta = preg_replace('/\bfree\b/i', '', $meta);
    } elseif (preg_match('/(\$[\d.]+(?:\/\$[\d.]+)?)/i', $meta, $m)) {
        $result['price'] = $m[1];
        $meta = str_replace($m[0], '', $meta);
    }

    return $result;
}

// ------------------------------------------------------------
// Main scrape loop
// ------------------------------------------------------------

$baseUrl    = 'http://www.foopee.com/punk/the-list/';
$totalPages = 38;
$inserted   = 0;
$skipped    = 0;
$skippedProtected = 0;
$errors     = 0;

$stmt = $pdo->prepare(panicScrapedEventsUpsertSql($pdo));
$protectedVenueNames = panicLoadProtectedProfileNameSet($pdo, 'venue');
$protectedBandNames  = panicLoadProtectedProfileNameSet($pdo, 'band');

for ($page = 1; $page <= $totalPages; $page++) {
    $url  = $baseUrl . "by-date.{$page}.html";
    echo "Fetching page {$page}/{$totalPages}: {$url}\n";
    flush();

    $html = foopee_fetch($url);
    if ($html === false) {
        echo "  ERROR: failed to fetch {$url}\n";
        $errors++;
        sleep(1);
        continue;
    }

    // Parse with DOMDocument
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Find all <li> elements that contain a date anchor: <a name="mon_23">
    // The structure is: <li><a name="..."><b>Day Mon DD</b></a><ul>..shows..</ul></li>
    // We look for <li> containing a direct <a> with a @name attribute followed by a <b>
    $dateLis = $xpath->query('//li[a[@name]][b or a/b]');

    $pageInserted = 0;
    $pageSkipped  = 0;
    $pageProtected = 0;

    foreach ($dateLis as $dateLi) {
        // Get the date text from <b> inside the <a name="...">
        $bNodes = $xpath->query('.//a[@name]//b | .//b', $dateLi);
        $dateText = '';
        foreach ($bNodes as $bn) {
            $t = foopee_clean($bn->textContent);
            // Must look like "Mon Mar 23"
            if (preg_match('/^[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}$/', $t)) {
                $dateText = $t;
                break;
            }
        }
        if (!$dateText) continue;

        $eventDate = foopee_parse_date($dateText);
        if (!$eventDate) continue;

        // Find the nested <ul> directly inside this date <li>
        $showUls = $xpath->query('./ul', $dateLi);
        if ($showUls->length === 0) continue;

        foreach ($showUls as $showUl) {
            $showLis = $xpath->query('./li', $showUl);

            foreach ($showLis as $showLi) {
                // Venue: first <b><a> text
                $venueNodes = $xpath->query('./b/a | .//b//a', $showLi);
                if ($venueNodes->length === 0) continue;
                $venueRaw = foopee_decode(foopee_node_text($venueNodes->item(0)));
                if (!$venueRaw) continue;

                // Split venue into name + city on last ", "
                $lastComma = strrpos($venueRaw, ', ');
                if ($lastComma !== false) {
                    $venueName = trim(substr($venueRaw, 0, $lastComma));
                    $venueCity = trim(substr($venueRaw, $lastComma + 2));
                } else {
                    $venueName = $venueRaw;
                    $venueCity = '';
                }
                $venueName = panicNormalizeVenueName($venueName);

                // Only SF venues
                if ($venueCity !== 'S.F.') {
                    $pageSkipped++;
                    continue;
                }

                // Bands: all <a href="by-band..."> elements
                $bandNodes = $xpath->query('.//a[contains(@href,"by-band")]', $showLi);
                $bands = [];
                foreach ($bandNodes as $bn) {
                    $bName = foopee_decode(foopee_node_text($bn));
                    if ($bName) $bands[] = $bName;
                }
                $bands = panicNormalizeBandList($bands);
                if (panicEventTouchesProtectedProfiles($venueName, $bands, $protectedVenueNames, $protectedBandNames)) {
                    $pageProtected++;
                    continue;
                }
                $bandsJson = panicResolveScrapedEventBandsJson($pdo, $eventDate, $venueName, $bands);

                // Raw meta: strip_tags of full li text, then remove venue and band strings
                $liText = foopee_decode(strip_tags($showLi->ownerDocument->saveHTML($showLi)));
                $liText = foopee_clean($liText);

                // Remove venue string
                $liText = str_replace($venueRaw, '', $liText);
                // Remove each band name
                foreach ($bands as $b) {
                    $liText = str_replace($b, '', $liText);
                }
                // Clean up comma/separator cruft
                $rawMeta = foopee_clean(preg_replace('/^[\s,;]+|[\s,;]+$/', '', $liText));
                $rawMeta = preg_replace('/\s*,\s*,\s*/', ', ', $rawMeta);
                $rawMeta = foopee_clean($rawMeta);

                $meta = foopee_parse_meta($rawMeta);

                try {
                    $stmt->execute([
                        ':event_date'      => $eventDate,
                        ':venue_name'      => $venueName,
                        ':venue_city'      => $venueCity,
                        ':bands'           => $bandsJson,
                        ':age_restriction' => $meta['age_restriction'],
                        ':price'           => $meta['price'],
                        ':doors_time'      => $meta['doors_time'],
                        ':show_time'       => $meta['show_time'],
                        ':is_sold_out'     => $meta['is_sold_out'],
                        ':is_ticketed'     => $meta['is_ticketed'],
                        ':notes'           => $meta['notes'],
                        ':raw_meta'        => $rawMeta,
                        ':source_url'      => $url,
                        ':source'          => 'foopee',
                    ]);
                    $pageInserted++;
                } catch (PDOException $e) {
                    echo "  DB error: " . $e->getMessage() . "\n";
                    $errors++;
                }
            }
        }
    }

    $inserted += $pageInserted;
    $skipped  += $pageSkipped;
    $skippedProtected += $pageProtected;
    echo "  SF shows inserted/replaced: {$pageInserted}, non-SF skipped: {$pageSkipped}, protected skipped: {$pageProtected}\n";
    flush();

    if ($page < $totalPages) {
        sleep(1);
    }
}

echo "\n=====================\n";
echo "Scrape complete.\n";
echo "Total SF shows inserted/replaced: {$inserted}\n";
echo "Non-SF shows skipped: {$skipped}\n";
echo "Protected venue/band matches skipped: {$skippedProtected}\n";
echo "Errors: {$errors}\n";
