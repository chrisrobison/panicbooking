<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Panic Booking</title>
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
            <h1 class="auth-title">Reset your password</h1>
            <p class="auth-subtitle">Enter your email and we will generate a reset link.</p>

            <div id="statusBox" class="alert alert-info" style="display:none;"></div>

            <form id="resetRequestForm" class="auth-form" method="post" action="">
                <?= csrfInputField() ?>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" required placeholder="you@example.com" autocomplete="email">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Generate Reset Link</button>
            </form>

            <p class="auth-switch">
                Remembered your password? <a href="/app/login.php">Back to login</a>
            </p>
        </div>
    </div>

    <script>
    (function () {
        const form = document.getElementById('resetRequestForm');
        const statusBox = document.getElementById('statusBox');
        const csrfToken = <?= json_encode(csrfToken()) ?>;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = form.email.value.trim();
            if (!email) return;

            statusBox.style.display = '';
            statusBox.className = 'alert alert-info';
            statusBox.textContent = 'Submitting...';

            try {
                const resp = await fetch('/api/auth/password-reset-request', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({ email }),
                });
                const data = await resp.json();

                if (!resp.ok || data.error) {
                    statusBox.className = 'alert alert-error';
                    statusBox.textContent = data.error || 'Failed to submit request';
                    return;
                }

                statusBox.className = 'alert alert-success';
                statusBox.textContent = data.message || 'If that account exists, reset instructions are ready.';

                if (data.debug_reset_url) {
                    const link = document.createElement('a');
                    link.href = String(data.debug_reset_url);
                    link.textContent = 'Open reset link';
                    link.style.marginLeft = '0.5rem';
                    statusBox.appendChild(link);
                }
            } catch (err) {
                statusBox.className = 'alert alert-error';
                statusBox.textContent = 'Network error';
            }
        });
    })();
    </script>
</body>
</html>
