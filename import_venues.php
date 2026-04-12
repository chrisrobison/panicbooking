<?php
/**
 * import_venues.php
 * One-time (idempotent) import of venues.json into the booking.db SQLite database.
 *
 * Run from the project root:
 *   php import_venues.php
 *
 * Safe to re-run — uses INSERT OR IGNORE on a unique generated email address.
 */

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('import_venues.php');

$isCli = (php_sapi_name() === 'cli');

function out(string $msg): void {
    global $isCli;
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

// ── DB connection ─────────────────────────────────────────────────────────────
$dbPath = __DIR__ . '/data/booking.db';
if (!file_exists($dbPath)) {
    out("ERROR: Database not found at $dbPath");
    out("Visit the app in a browser first to initialise the database, then re-run this script.");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    out("ERROR: DB connection failed: " . $e->getMessage());
    exit(1);
}

// ── Load venues.json ──────────────────────────────────────────────────────────
$jsonPath = __DIR__ . '/venues.json';
if (!file_exists($jsonPath)) {
    out("ERROR: venues.json not found at $jsonPath");
    exit(1);
}

$venues = json_decode(file_get_contents($jsonPath), true);
if (!is_array($venues)) {
    out("ERROR: Failed to parse venues.json");
    exit(1);
}

out("Found " . count($venues) . " venues in venues.json");
out("Starting import...");
out(str_repeat('-', 60));

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Turn a venue name into a safe email-local-part slug.
 * e.g. "Bimbo's 365 Club" → "bimbos-365-club@venue.panicbooking.local"
 */
function nameToEmail(string $name): string {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug . '@venue.panicbooking.local';
}

/**
 * Map typical_genres strings from the JSON (which may be freeform) into the
 * canonical genre list used by the app, keeping any that match and labelling
 * the rest as "Other".
 */
function mapGenres(array $rawGenres): array {
    $canonical = [
        'Alternative', 'Classic Rock', 'Punk', 'Indie', 'Rock',
        'Metal', 'Country', 'Blues', 'Jazz', 'Other',
    ];
    $mapped = [];
    foreach ($rawGenres as $g) {
        $found = false;
        foreach ($canonical as $c) {
            if (stripos($g, $c) !== false || stripos($c, $g) !== false) {
                $mapped[] = $c;
                $found = true;
                break;
            }
        }
        if (!$found && !empty(trim($g))) {
            $mapped[] = 'Other';
        }
    }
    return array_values(array_unique($mapped));
}

/**
 * Guess neighborhood from address string when the JSON field is null.
 * Very rough heuristic based on common SF street names.
 */
function guessNeighborhood(?string $address): string {
    if (empty($address)) return 'Other';

    $map = [
        'Mission'          => ['Mission St', 'Valencia St', '16th St', '24th St', 'Guerrero'],
        'SoMa'             => ['Folsom St', 'Harrison St', 'Bryant St', 'SoMa', '11th St', 'Howard St'],
        'Castro'           => ['Castro St', 'Market St', '18th St'],
        'Haight-Ashbury'   => ['Haight St', 'Ashbury', 'Fillmore St', 'Geary Blvd'],
        'North Beach'      => ['Columbus Ave', 'Broadway', 'Grant Ave', 'Green St', 'North Beach'],
        'Tenderloin'       => ['Turk St', 'Eddy St', 'Jones St', 'Tenderloin'],
        'Richmond'         => ['Clement St', 'Geary Blvd', 'Richmond'],
        'Sunset'           => ['Irving St', 'Judah St', 'Noriega St', 'Sunset'],
        'Downtown'         => ['Market St', 'Montgomery St', 'Kearny St', 'Post St', 'Sutter St'],
    ];

    foreach ($map as $neighborhood => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($address, $kw) !== false) {
                return $neighborhood;
            }
        }
    }
    return 'Other';
}

// ── Prepared statements ───────────────────────────────────────────────────────
$checkUser    = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$insertUser   = $pdo->prepare(
    "INSERT OR IGNORE INTO users (email, password_hash, type) VALUES (?, ?, 'venue')"
);
$insertProfile = $pdo->prepare(
    "INSERT OR IGNORE INTO profiles (user_id, type, data, is_generic, is_claimed) VALUES (?, 'venue', ?, 1, 0)"
);

$genericHash = '*GENERIC*' . bin2hex(random_bytes(16));

// ── Import loop ───────────────────────────────────────────────────────────────
$imported = 0;
$skipped  = 0;

foreach ($venues as $v) {
    $name = trim($v['name'] ?? '');
    if (empty($name)) {
        $skipped++;
        continue;
    }

    $email = nameToEmail($name);

    // Check if already imported
    $checkUser->execute([$email]);
    if ($checkUser->fetchColumn()) {
        out("  SKIP (exists): $name");
        $skipped++;
        continue;
    }

    // Determine neighborhood
    $neighborhood = $v['neighborhood'] ?? null;
    if (empty($neighborhood)) {
        $neighborhood = guessNeighborhood($v['address'] ?? '');
    }

    // Build the profile data blob matching the app's venue JSON schema
    $profileData = [
        'name'                    => $name,
        'address'                 => $v['address'] ?? '',
        'neighborhood'            => $neighborhood,
        'capacity'                => (int)($v['capacity'] ?? 0),
        'description'             => '',          // not in source data
        'contact_email'           => '',          // not in source data
        'contact_phone'           => $v['phone'] ?? '',
        'website'                 => $v['website'] ?? '',
        'facebook'                => '',
        'instagram'               => '',
        'genres_welcomed'         => mapGenres($v['typical_genres'] ?? []),
        'has_pa'                  => false,
        'has_drums'               => false,
        'has_backline'            => false,
        'stage_size'              => '',
        'cover_charge'            => false,
        'bar_service'             => false,
        'open_to_last_minute'     => false,       // set per-venue manually
        'booking_lead_time_days'  => 0,
        'notes'                   => trim(
            ($v['notes'] ?? '') .
            (
                !empty($v['status']) && $v['status'] !== 'open'
                    ? ' [Status: ' . $v['status'] . ']'
                    : ''
            )
        ),
        // Extra fields from source data (useful for future map feature)
        'lat'                     => $v['lat'] ?? null,
        'lon'                     => $v['lon'] ?? null,
        'sources'                 => $v['sources'] ?? [],
    ];

    // Insert user row
    $insertUser->execute([$email, $genericHash]);
    $userId = $pdo->lastInsertId();

    if (!$userId) {
        out("  ERROR: Could not insert user for $name");
        $skipped++;
        continue;
    }

    // Insert profile row
    $insertProfile->execute([$userId, json_encode($profileData)]);

    out("  IMPORTED: $name  →  $email  (neighborhood: $neighborhood)");
    $imported++;
}

out(str_repeat('-', 60));
out("Done. Imported: $imported  |  Skipped/existing: $skipped");
out("");
out("Imported venues are seeded as unclaimed profiles and require claim approval.");
out("Login emails follow the pattern: venue-name-slug@venue.panicbooking.local (not directly usable).");
