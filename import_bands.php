<?php
// Import generic band profiles from scraped_events into the users/profiles tables.
// Bands are marked is_generic=1, is_claimed=0 so real bands can later claim them.
//
// CLI:  php import_bands.php [--dry-run] [--quiet]
// Web:  disabled by default; set PB_ALLOW_WEB_MAINTENANCE=1 and PB_MAINTENANCE_TOKEN.

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('import_bands.php');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$dryRun = in_array('--dry-run', $argv ?? []) || isset($_GET['dry']);
$quiet  = in_array('--quiet',   $argv ?? []);

require_once __DIR__ . '/api/includes/db.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function band_slug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug ?: 'band';
}

function band_email(string $slug, int $suffix = 0): string {
    $base = $suffix ? "{$slug}-{$suffix}" : $slug;
    return "{$base}@bands.panicbooking.internal";
}

// ── Collect all unique band names from scraped_events ─────────────────────────

$allBands = [];
$rows = $pdo->query("SELECT DISTINCT bands FROM scraped_events WHERE bands != '[]'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $json) {
    $arr = json_decode($json, true);
    if (!is_array($arr)) continue;
    foreach ($arr as $name) {
        $name = trim($name);
        $key = panicCanonicalNameKey($name);
        if ($name !== '' && $key !== '' && !isset($allBands[$key])) {
            $allBands[$key] = $name;
        }
    }
}
$allBands = array_values($allBands);
natcasesort($allBands);
$allBands = array_values($allBands);

if (!$quiet) {
    echo "Found " . count($allBands) . " unique band names in scraped_events.\n";
}

// ── Load existing generic profiles to avoid duplication ───────────────────────

// Key: normalised name (lowercase) => user_id
$existing = [];
$rows = $pdo->query("
    SELECT p.user_id,
           p.data,
           p.created_at,
           p.updated_at,
           COALESCE(p.is_claimed, 0) AS is_claimed,
           COALESCE(p.is_generic, 0) AS is_generic
    FROM profiles p
    JOIN users u ON u.id = p.user_id
    WHERE u.type = 'band'
      AND p.type = 'band'
      AND COALESCE(p.is_archived, 0) = 0
")->fetchAll();
foreach ($rows as $r) {
    $data = json_decode($r['data'], true) ?: [];
    $key = panicCanonicalNameKey((string)($data['name'] ?? ''));
    if ($key !== '') {
        $existing[$key] = [
            'user_id' => (int)$r['user_id'],
            'is_protected' => panicProfileIsProtected($r),
        ];
    }
}

$protectedBandKeys = panicLoadProtectedProfileNameSet($pdo, 'band');

// ── Load performer_scores for enrichment ─────────────────────────────────────

$scores = [];
$scoreRows = $pdo->query("SELECT * FROM performer_scores")->fetchAll();
foreach ($scoreRows as $s) {
    $key = panicCanonicalNameKey((string)($s['band_name'] ?? ''));
    if ($key !== '') {
        $scores[$key] = $s;
    }
}

// ── Collect used emails/slugs to handle collisions ───────────────────────────

$usedEmails = [];
$emailRows = $pdo->query("SELECT email FROM users")->fetchAll(PDO::FETCH_COLUMN);
foreach ($emailRows as $e) {
    $usedEmails[strtolower($e)] = true;
}

// ── Import ────────────────────────────────────────────────────────────────────

$insertUser    = $pdo->prepare("
    INSERT INTO users (email, password_hash, type)
    VALUES (?, ?, 'band')
");
$insertProfile = $pdo->prepare("
    INSERT INTO profiles (user_id, type, data, is_generic, is_claimed)
    VALUES (?, 'band', ?, 1, 0)
");
$updateScore = $pdo->prepare("
    UPDATE performer_scores
    SET band_profile_id = ?
    WHERE LOWER(TRIM(band_name)) = LOWER(TRIM(?))
");

// Backfill band_profile_id for any existing profiles not yet linked
if (!$dryRun) {
    $profileNameExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');
    $pdo->exec("
        UPDATE performer_scores
        SET band_profile_id = (
            SELECT u.id FROM users u
            JOIN profiles p ON p.user_id = u.id
            WHERE LOWER(TRIM({$profileNameExpr})) = LOWER(TRIM(performer_scores.band_name))
              AND u.type = 'band'
              AND p.type = 'band'
            LIMIT 1
        )
        WHERE band_profile_id IS NULL
    ");
    if (!$quiet) echo "Backfilled performer_scores.band_profile_id.\n";
}

// Impossible password hash — nobody can log in as a generic band
$genericHash = '*GENERIC*' . bin2hex(random_bytes(16));

$inserted = 0;
$skipped  = 0;
$skippedProtected = 0;

foreach ($allBands as $bandName) {
    $key = panicCanonicalNameKey($bandName);
    if ($key === '') {
        $skipped++;
        continue;
    }

    if (isset($protectedBandKeys[$key])) {
        $skippedProtected++;
        continue;
    }

    // Skip if a profile with this name already exists
    if (isset($existing[$key])) {
        $skipped++;
        continue;
    }

    // Build a unique email slug
    $slug   = band_slug($bandName);
    $suffix = 0;
    $email  = band_email($slug, 0);
    while (isset($usedEmails[strtolower($email)])) {
        $suffix++;
        $email = band_email($slug, $suffix);
    }

    // Build profile data JSON
    $score = $scores[$key] ?? null;
    $data = ['name' => $bandName, 'is_generic' => true];

    if ($score) {
        $data['shows_tracked']   = (int)$score['shows_tracked'];
        $data['estimated_draw']  = (int)$score['estimated_draw'];
        $data['composite_score'] = round((float)$score['composite_score'], 2);
        if (!empty($score['last_show_date'])) {
            $data['last_show_date'] = $score['last_show_date'];
        }
    }

    $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);

    if (!$quiet) {
        $tag = $dryRun ? '[DRY]' : '[ADD]';
        echo "{$tag} {$bandName} → {$email}\n";
    }

    if (!$dryRun) {
        try {
            $insertUser->execute([$email, $genericHash]);
            $userId = (int)$pdo->lastInsertId();
            $insertProfile->execute([$userId, $dataJson]);
            $usedEmails[strtolower($email)] = true;
            $existing[$key] = [
                'user_id' => $userId,
                'is_protected' => false,
            ];
            // Link performer_scores → profile
            $updateScore->execute([$userId, $bandName]);
            $inserted++;
        } catch (PDOException $e) {
            echo "  ERROR inserting '{$bandName}': " . $e->getMessage() . "\n";
        }
    } else {
        $inserted++;
    }
}

echo "\n";
if ($dryRun) {
    echo "DRY RUN — no changes written.\n";
}
echo "Done. Imported: {$inserted}, Already existed: {$skipped}, Protected skipped: {$skippedProtected}.\n";
