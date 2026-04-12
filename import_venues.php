<?php
/**
 * import_venues.php
 * One-time (idempotent) import of venues.json into the configured Panic Booking DB.
 *
 * Run from the project root:
 *   php import_venues.php
 *
 * Safe to re-run — skips existing venues by generated email and by canonical name.
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

// ── DB connection + bootstrap ─────────────────────────────────────────────────
require_once __DIR__ . '/api/includes/db.php';

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
    "INSERT INTO users (email, password_hash, type) VALUES (?, ?, 'venue')"
);
$insertProfile = $pdo->prepare(
    "INSERT INTO profiles (user_id, type, data, is_generic, is_claimed) VALUES (?, 'venue', ?, 1, 0)"
);

// Build a canonical-name index of active venue profiles so we do not create
// duplicate generic rows when names/emails evolve over time.
$existingVenueByName = [];
$existingVenueRows = $pdo->query("
    SELECT p.data,
           p.created_at,
           p.updated_at,
           COALESCE(p.is_claimed, 0) AS is_claimed,
           COALESCE(p.is_generic, 0) AS is_generic
    FROM profiles p
    JOIN users u ON u.id = p.user_id
    WHERE u.type = 'venue'
      AND p.type = 'venue'
      AND COALESCE(p.is_archived, 0) = 0
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($existingVenueRows as $row) {
    $data = json_decode((string)($row['data'] ?? ''), true);
    if (!is_array($data)) {
        continue;
    }
    $key = panicCanonicalNameKey((string)($data['name'] ?? ''));
    if ($key === '') {
        continue;
    }

    $isProtected = panicProfileIsProtected($row);
    if (!isset($existingVenueByName[$key])) {
        $existingVenueByName[$key] = ['is_protected' => $isProtected];
        continue;
    }
    if ($isProtected) {
        $existingVenueByName[$key]['is_protected'] = true;
    }
}

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
    $nameKey = panicCanonicalNameKey($name);

    // Check if an account already exists with this generated import email.
    $checkUser->execute([$email]);
    if ($checkUser->fetchColumn()) {
        out("  SKIP (exists): $name");
        $skipped++;
        continue;
    }

    // Also skip when a venue profile with the same canonical name exists,
    // especially if that profile has been claimed or manually modified.
    if ($nameKey !== '' && isset($existingVenueByName[$nameKey])) {
        $reason = $existingVenueByName[$nameKey]['is_protected']
            ? 'claimed/modified profile exists'
            : 'name already exists';
        out("  SKIP ({$reason}): $name");
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
    try {
        $insertUser->execute([$email, $genericHash]);
    } catch (PDOException $e) {
        if (panicDbIsDuplicateKeyException($e)) {
            out("  SKIP (exists): $name");
            $skipped++;
            continue;
        }
        out("  ERROR inserting user for $name: " . $e->getMessage());
        $skipped++;
        continue;
    }

    $userId = $pdo->lastInsertId();

    if (!$userId) {
        out("  ERROR: Could not insert user for $name");
        $skipped++;
        continue;
    }

    // Insert profile row
    $insertProfile->execute([$userId, json_encode($profileData)]);
    if ($nameKey !== '') {
        $existingVenueByName[$nameKey] = ['is_protected' => false];
    }

    out("  IMPORTED: $name  →  $email  (neighborhood: $neighborhood)");
    $imported++;
}

out(str_repeat('-', 60));
out("Done. Imported: $imported  |  Skipped/existing: $skipped");
out("");
out("Imported venues are seeded as unclaimed profiles and require claim approval.");
out("Login emails follow the pattern: venue-name-slug@venue.panicbooking.local (not directly usable).");
