<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard.php');
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password — Panic Booking</title>
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
            <h1 class="auth-title">Set a new password</h1>
            <p class="auth-subtitle">Choose a new password for your account.</p>

            <div id="statusBox" class="alert alert-info" style="display:none;"></div>

            <?php if ($token === ''): ?>
                <div class="alert alert-error">Missing reset token. Use the full reset link from your request.</div>
                <p class="auth-switch"><a href="/app/password-reset-request.php">Request a new reset link</a></p>
            <?php else: ?>
                <form id="resetForm" class="auth-form" method="post" action="">
                    <?= csrfInputField() ?>
                    <div class="form-group">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
                </form>
            <?php endif; ?>

            <p class="auth-switch">
                <a href="/app/login.php">Back to login</a>
            </p>
        </div>
    </div>

    <?php if ($token !== ''): ?>
    <script>
    (function () {
        const form = document.getElementById('resetForm');
        const statusBox = document.getElementById('statusBox');
        const token = <?= json_encode($token) ?>;
        const csrfToken = <?= json_encode(csrfToken()) ?>;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const newPassword = form.new_password.value;
            const confirmPassword = form.confirm_password.value;

            if (newPassword.length < 8) {
                statusBox.style.display = '';
                statusBox.className = 'alert alert-error';
                statusBox.textContent = 'Password must be at least 8 characters.';
                return;
            }
            if (newPassword !== confirmPassword) {
                statusBox.style.display = '';
                statusBox.className = 'alert alert-error';
                statusBox.textContent = 'Passwords do not match.';
                return;
            }

            statusBox.style.display = '';
            statusBox.className = 'alert alert-info';
            statusBox.textContent = 'Resetting password...';

            try {
                const resp = await fetch('/api/auth/password-reset-confirm', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        token,
                        new_password: newPassword,
                    }),
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    statusBox.className = 'alert alert-error';
                    statusBox.textContent = data.error || 'Failed to reset password';
                    return;
                }

                statusBox.className = 'alert alert-success';
                statusBox.innerHTML = 'Password reset successful. <a href="/app/login.php">Log in</a>.';
                form.reset();
            } catch (err) {
                statusBox.className = 'alert alert-error';
                statusBox.textContent = 'Network error';
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
