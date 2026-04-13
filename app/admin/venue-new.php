<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$user        = currentUser();
$currentPage = 'admin';

$genres        = ['Alternative','Classic Rock','Punk','Indie','Rock','Metal','Country','Blues','Jazz','Other'];
$neighborhoods = ['SoMa','Mission','Castro','Haight-Ashbury','North Beach','Tenderloin','Richmond','Sunset','SOMA','Downtown','Other'];
$stageSizes    = ['Small <10ft','Medium 10-20ft','Large 20ft+'];

$error = '';
$fd = [
    'email'                  => '',
    'name'                   => '',
    'neighborhood'           => '',
    'address'                => '',
    'capacity'               => '',
    'description'            => '',
    'genres_welcomed'        => [],
    'contact_email'          => '',
    'contact_phone'          => '',
    'website'                => '',
    'facebook'               => '',
    'instagram'              => '',
    'stage_size'             => '',
    'has_pa'                 => false,
    'has_drums'              => false,
    'has_backline'           => false,
    'cover_charge'           => false,
    'bar_service'            => false,
    'open_to_last_minute'    => false,
    'booking_lead_time_days' => 0,
    'notes'                  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');

    $email           = trim(strtolower((string)($_POST['email']            ?? '')));
    $password        = (string)($_POST['password']         ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    $profile = [
        'name'                   => trim((string)($_POST['name']                   ?? '')),
        'neighborhood'           => trim((string)($_POST['neighborhood']           ?? '')),
        'address'                => trim((string)($_POST['address']                ?? '')),
        'capacity'               => max(0, (int)($_POST['capacity']                ?? 0)),
        'description'            => trim((string)($_POST['description']            ?? '')),
        'genres_welcomed'        => array_values(array_filter(array_map('trim', (array)($_POST['genres_welcomed'] ?? [])))),
        'contact_email'          => trim((string)($_POST['contact_email']          ?? '')),
        'contact_phone'          => trim((string)($_POST['contact_phone']          ?? '')),
        'website'                => trim((string)($_POST['website']                ?? '')),
        'facebook'               => trim((string)($_POST['facebook']               ?? '')),
        'instagram'              => trim((string)($_POST['instagram']              ?? '')),
        'stage_size'             => trim((string)($_POST['stage_size']             ?? '')),
        'has_pa'                 => !empty($_POST['has_pa']),
        'has_drums'              => !empty($_POST['has_drums']),
        'has_backline'           => !empty($_POST['has_backline']),
        'cover_charge'           => !empty($_POST['cover_charge']),
        'bar_service'            => !empty($_POST['bar_service']),
        'open_to_last_minute'    => !empty($_POST['open_to_last_minute']),
        'booking_lead_time_days' => max(0, (int)($_POST['booking_lead_time_days'] ?? 0)),
        'notes'                  => trim((string)($_POST['notes']                  ?? '')),
    ];

    // Repopulate form on validation failure
    $fd = array_merge($fd, $profile, ['email' => $email]);

    // ── Validate ──────────────────────────────────────────────────────────
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid login email address is required.';
    } elseif ($profile['name'] === '') {
        $error = 'Venue name is required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $dup->execute([$email]);
        if ($dup->fetch()) {
            $error = 'An account with that email already exists.';
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            // Default contact_email to login email when left blank
            if ($profile['contact_email'] === '') {
                $profile['contact_email'] = $email;
            }

            $pdo->prepare("INSERT INTO users (email, password_hash, type) VALUES (?, ?, 'venue')")
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, 'venue', ?)")
                ->execute([$newId, json_encode($profile, JSON_UNESCAPED_UNICODE)]);

            $pdo->commit();

            panicLog('admin_venue_created', [
                'by_user_id' => (int)$user['id'],
                'new_user_id' => $newId,
                'venue_name'  => $profile['name'],
            ]);

            header('Location: /app/profile.php?edit_id=' . $newId . '&edit_type=venue&new=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not create venue: ' . $e->getMessage();
        }
    }
}

function checked(mixed $val): string {
    return $val ? ' checked' : '';
}
function sel(string $current, string $option): string {
    return $current === $option ? ' selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Venue — Admin — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="main-content">

    <div class="page-header">
        <div>
            <h1 class="page-title">New Venue</h1>
            <p class="page-subtitle">Creates a venue account and full profile in one step.</p>
        </div>
        <a href="/app/admin/index.php" class="btn btn-sm">← Admin</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" style="max-width:740px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="" class="card-form" style="max-width:740px;">
        <?= csrfInputField() ?>

        <!-- ── Account ─────────────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Account Credentials</h2>
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">
                These are the login details the venue owner will use to access Panic Booking.
            </p>
            <div class="form-row">
                <div class="form-group">
                    <label for="email">Login email <span style="color:var(--accent)">*</span></label>
                    <input type="email" id="email" name="email" required autocomplete="off"
                           placeholder="owner@thevenue.com"
                           value="<?= htmlspecialchars($fd['email']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password <span style="color:var(--accent)">*</span></label>
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password" minlength="8"
                           placeholder="Min. 8 characters">
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm password <span style="color:var(--accent)">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           autocomplete="new-password" minlength="8"
                           placeholder="Re-enter password">
                </div>
            </div>
        </div>

        <!-- ── Basic Info ──────────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Basic Info</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Venue name <span style="color:var(--accent)">*</span></label>
                    <input type="text" id="name" name="name" required
                           placeholder="e.g. The Rusty Nail"
                           value="<?= htmlspecialchars((string)$fd['name']) ?>">
                </div>
                <div class="form-group">
                    <label for="neighborhood">Neighborhood</label>
                    <select id="neighborhood" name="neighborhood">
                        <option value="">— Select —</option>
                        <?php foreach ($neighborhoods as $n): ?>
                            <option value="<?= htmlspecialchars($n) ?>"<?= sel((string)$fd['neighborhood'], $n) ?>><?= htmlspecialchars($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address"
                           placeholder="123 Main St, San Francisco, CA 94103"
                           value="<?= htmlspecialchars((string)$fd['address']) ?>">
                </div>
                <div class="form-group">
                    <label for="capacity">Capacity</label>
                    <input type="number" id="capacity" name="capacity" min="0"
                           placeholder="e.g. 200"
                           value="<?= htmlspecialchars((string)$fd['capacity']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"
                          placeholder="Describe the venue, atmosphere, and what you're looking for in artists…"><?= htmlspecialchars((string)$fd['description']) ?></textarea>
            </div>
        </div>

        <!-- ── Genres ──────────────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Genres Welcomed</h2>
            <div class="checkbox-grid">
                <?php foreach ($genres as $g): ?>
                    <label class="checkbox-option">
                        <input type="checkbox" name="genres_welcomed[]"
                               value="<?= htmlspecialchars($g) ?>"
                               <?= in_array($g, (array)$fd['genres_welcomed'], true) ? 'checked' : '' ?>>
                        <span class="checkbox-label"><?= htmlspecialchars($g) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Contact & Links ────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Contact &amp; Links</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="contact_email">Booking / contact email</label>
                    <input type="email" id="contact_email" name="contact_email"
                           placeholder="booking@thevenue.com"
                           value="<?= htmlspecialchars((string)$fd['contact_email']) ?>">
                </div>
                <div class="form-group">
                    <label for="contact_phone">Phone</label>
                    <input type="tel" id="contact_phone" name="contact_phone"
                           placeholder="415-555-0000"
                           value="<?= htmlspecialchars((string)$fd['contact_phone']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="website">Website</label>
                    <input type="url" id="website" name="website"
                           placeholder="https://thevenue.com"
                           value="<?= htmlspecialchars((string)$fd['website']) ?>">
                </div>
                <div class="form-group">
                    <label for="facebook">Facebook</label>
                    <input type="url" id="facebook" name="facebook"
                           placeholder="https://facebook.com/thevenue"
                           value="<?= htmlspecialchars((string)$fd['facebook']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="instagram">Instagram</label>
                    <input type="url" id="instagram" name="instagram"
                           placeholder="https://instagram.com/thevenue"
                           value="<?= htmlspecialchars((string)$fd['instagram']) ?>">
                </div>
            </div>
        </div>

        <!-- ── Equipment & Stage ──────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Equipment &amp; Stage</h2>
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label for="stage_size">Stage size</label>
                    <select id="stage_size" name="stage_size">
                        <option value="">— Select —</option>
                        <?php foreach ($stageSizes as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"<?= sel((string)$fd['stage_size'], $s) ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="checkbox-group-inline">
                <label class="checkbox-toggle">
                    <input type="checkbox" name="has_pa" value="1"<?= checked($fd['has_pa']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Has PA System</span>
                </label>
                <label class="checkbox-toggle">
                    <input type="checkbox" name="has_drums" value="1"<?= checked($fd['has_drums']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Has Drum Kit</span>
                </label>
                <label class="checkbox-toggle">
                    <input type="checkbox" name="has_backline" value="1"<?= checked($fd['has_backline']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Has Backline</span>
                </label>
            </div>
        </div>

        <!-- ── Venue Details ──────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Venue Details</h2>
            <div class="checkbox-group-inline">
                <label class="checkbox-toggle">
                    <input type="checkbox" name="cover_charge" value="1"<?= checked($fd['cover_charge']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Cover Charge</span>
                </label>
                <label class="checkbox-toggle">
                    <input type="checkbox" name="bar_service" value="1"<?= checked($fd['bar_service']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Bar Service</span>
                </label>
                <label class="checkbox-toggle checkbox-toggle-highlight">
                    <input type="checkbox" name="open_to_last_minute" value="1"<?= checked($fd['open_to_last_minute']) ?>>
                    <span class="toggle-track"></span>
                    <span class="toggle-label">⚡ Open to Last-Minute Bookings</span>
                </label>
            </div>
            <div class="form-row" style="margin-top:1rem;">
                <div class="form-group">
                    <label for="booking_lead_time_days">Booking lead time (days — 0 = same day)</label>
                    <input type="number" id="booking_lead_time_days" name="booking_lead_time_days"
                           min="0" value="<?= (int)$fd['booking_lead_time_days'] ?>">
                </div>
            </div>
        </div>

        <!-- ── Notes ─────────────────────────────────────────────────── -->
        <div class="form-section">
            <h2 class="form-section-title">Notes</h2>
            <div class="form-group">
                <textarea id="notes" name="notes" rows="3"
                          placeholder="Anything else bands should know? Deal terms, load-in info, house rules…"><?= htmlspecialchars((string)$fd['notes']) ?></textarea>
            </div>
        </div>

        <!-- ── Actions ───────────────────────────────────────────────── -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:.5rem;">
            <a href="/app/admin/index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Venue</button>
        </div>

    </form>
</main>

<div id="toast" class="toast"></div>
<script>window.APP_IS_ADMIN = true;</script>
<script src="/app/assets/js/app.js"></script>
<script>
// Client-side password match hint
(function () {
    const pw  = document.getElementById('password');
    const pwc = document.getElementById('password_confirm');
    if (!pw || !pwc) return;

    function check() {
        if (pwc.value === '') { pwc.setCustomValidity(''); return; }
        pwc.setCustomValidity(pw.value !== pwc.value ? 'Passwords do not match.' : '');
    }
    pw.addEventListener('input',  check);
    pwc.addEventListener('input', check);
})();
</script>
</body>
</html>
