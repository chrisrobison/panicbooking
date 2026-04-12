<?php
/**
 * update_venues.php
 * Syncs enriched data from venues.json into existing profile rows in booking.db.
 * Updates: address, neighborhood, capacity, description, contact_email,
 *          contact_phone, website, genres_welcomed, lat, lon, notes, sources.
 *
 * Run from the project root:
 *   php update_venues.php
 */

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('update_venues.php');

$isCli = (php_sapi_name() === 'cli');

function out(string $msg): void {
    global $isCli;
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

// ── DB connection ──────────────────────────────────────────────────────────────
$dbPath = __DIR__ . '/data/booking.db';
if (!file_exists($dbPath)) {
    out("ERROR: Database not found at $dbPath");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON;');

// ── Load venues.json ───────────────────────────────────────────────────────────
$jsonPath = __DIR__ . '/venues.json';
$venues   = json_decode(file_get_contents($jsonPath), true);
if (!is_array($venues)) {
    out("ERROR: Failed to parse venues.json");
    exit(1);
}

out("Found " . count($venues) . " venues in venues.json");
out("Syncing to database...");
out(str_repeat('-', 60));

// ── Helpers (must match import_venues.php) ─────────────────────────────────────
function nameToEmail(string $name): string {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug . '@venue.lastcallsf.local';
}

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
                $found    = true;
                break;
            }
        }
        if (!$found && !empty(trim($g))) {
            $mapped[] = 'Other';
        }
    }
    return array_values(array_unique($mapped));
}

// ── Prepared statements ────────────────────────────────────────────────────────
$findUser    = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$findProfile = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ?");
$updateProfile = $pdo->prepare(
    "UPDATE profiles SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?"
);

$updated  = 0;
$notFound = 0;

foreach ($venues as $v) {
    $name = trim($v['name'] ?? '');
    if (empty($name)) continue;

    $email = nameToEmail($name);

    // Find the user row
    $findUser->execute([$email]);
    $userId = $findUser->fetchColumn();
    if (!$userId) {
        out("  NOT FOUND: $name  ($email)");
        $notFound++;
        continue;
    }

    // Load existing profile JSON
    $findProfile->execute([$userId]);
    $existing = json_decode($findProfile->fetchColumn() ?: '{}', true) ?: [];

    // Merge in updated fields from venues.json
    $existing['address']       = $v['address']      ?? $existing['address']      ?? '';
    $existing['neighborhood']  = $v['neighborhood']  ?? $existing['neighborhood'] ?? '';
    $existing['capacity']      = (int)($v['capacity'] ?? $existing['capacity'] ?? 0);
    $existing['description']   = $v['description']   ?? $existing['description'] ?? '';
    $existing['contact_email'] = $v['email']         ?? $existing['contact_email'] ?? '';
    $existing['contact_phone'] = $v['phone']         ?? $existing['contact_phone'] ?? '';
    $existing['website']       = $v['website']       ?? $existing['website']      ?? '';
    $existing['lat']           = $v['lat']            ?? $existing['lat']          ?? null;
    $existing['lon']           = $v['lon']            ?? $existing['lon']          ?? null;
    $existing['sources']       = $v['sources']        ?? $existing['sources']      ?? [];

    // Re-map genres from the enriched typical_genres list
    if (!empty($v['typical_genres'])) {
        $existing['genres_welcomed'] = mapGenres($v['typical_genres']);
    }

    // Rebuild notes: preserve existing operational notes, append status if non-open
    $noteBase = trim($v['notes'] ?? '');
    if (!empty($v['status']) && $v['status'] !== 'open' && $v['status'] !== 'likely_open') {
        $noteBase = trim($noteBase . ' [Status: ' . $v['status'] . ']');
    }
    if (!empty($noteBase)) {
        $existing['notes'] = $noteBase;
    }

    $updateProfile->execute([json_encode($existing), $userId]);
    out("  UPDATED: $name");
    $updated++;
}

out(str_repeat('-', 60));
out("Done. Updated: $updated  |  Not found in DB: $notFound");
