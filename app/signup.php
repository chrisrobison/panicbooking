<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

function normalizeNextPath(?string $next): string {
    $next = trim((string)$next);
    if ($next === '' || $next[0] !== '/' || substr($next, 0, 2) === '//') {
        return '/app/profile.php?new=1';
    }
    return $next;
}

$nextPath = normalizeNextPath($_GET['next'] ?? $_POST['next'] ?? '/app/profile.php?new=1');
$defaultType = $_GET['type'] ?? '';
if (!in_array($defaultType, ['band', 'venue'], true)) {
    $defaultType = '';
}
$selectedType = $_POST['type'] ?? $defaultType;

if (isLoggedIn()) {
    header('Location: /app/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $type     = $selectedType;

    if ($email === '' || $password === '' || $confirm === '' || $type === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($type, ['band', 'venue'])) {
        $error = 'Please select an account type.';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, type) VALUES (?, ?, ?)");
            $stmt->execute([$email, $hash, $type]);
            $userId = (int)$pdo->lastInsertId();

            // Create empty profile
            $defaultData = $type === 'band' ? json_encode([
                'name' => '', 'genres' => [], 'members' => [], 'description' => '',
                'contact_email' => $email, 'contact_phone' => '', 'website' => '',
                'facebook' => '', 'instagram' => '', 'spotify' => '', 'youtube' => '',
                'location' => 'San Francisco, CA', 'experience' => '',
                'set_length_min' => 45, 'set_length_max' => 90,
                'has_own_equipment' => false, 'available_last_minute' => true, 'notes' => ''
            ]) : json_encode([
                'name' => '', 'address' => '', 'neighborhood' => '', 'capacity' => 0,
                'description' => '', 'contact_email' => $email, 'contact_phone' => '',
                'website' => '', 'facebook' => '', 'instagram' => '',
                'genres_welcomed' => [], 'has_pa' => false, 'has_drums' => false,
                'has_backline' => false, 'stage_size' => '', 'cover_charge' => false,
                'bar_service' => false, 'open_to_last_minute' => true,
                'booking_lead_time_days' => 0, 'notes' => ''
            ]);

            $stmt = $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $type, $defaultData]);

            login($userId, $email, $type);
            header('Location: ' . $nextPath);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-logo">
            <span class="logo-icon">⚡</span>
            <span class="logo-text">Panic Booking</span>
        </div>
        <div class="auth-card">
            <h1 class="auth-title">Join Panic Booking</h1>
            <p class="auth-subtitle">Connect bands and venues across San Francisco</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/app/signup.php" class="auth-form">
                <input type="hidden" name="next" value="<?= htmlspecialchars($nextPath) ?>">
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="you@example.com" autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           placeholder="Minimum 8 characters" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           placeholder="Repeat your password" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>I am a...</label>
                    <div class="type-selector">
                        <label class="type-option <?= ($selectedType === 'band') ? 'selected' : '' ?>">
                            <input type="radio" name="type" value="band"
                                   <?= ($selectedType === 'band') ? 'checked' : '' ?>>
                            <span class="type-icon">🎸</span>
                            <span class="type-label">Band / Artist</span>
                            <span class="type-desc">I'm looking for gigs</span>
                        </label>
                        <label class="type-option <?= ($selectedType === 'venue') ? 'selected' : '' ?>">
                            <input type="radio" name="type" value="venue"
                                   <?= ($selectedType === 'venue') ? 'checked' : '' ?>>
                            <span class="type-icon">🏛</span>
                            <span class="type-label">Venue</span>
                            <span class="type-desc">I'm booking artists</span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Create Account</button>
            </form>

            <p class="auth-switch">
                Already have an account? <a href="/app/login.php?next=<?= urlencode($nextPath) ?>">Sign in</a>
            </p>
        </div>
    </div>
    <script>
        // Highlight selected type option
        document.querySelectorAll('.type-option input').forEach(radio => {
            radio.addEventListener('change', () => {
                document.querySelectorAll('.type-option').forEach(el => el.classList.remove('selected'));
                radio.closest('.type-option').classList.add('selected');
            });
        });
    </script>
</body>
</html>
