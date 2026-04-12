<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireAuth();
$user        = currentUser();
$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Settings</h1>
        </div>

        <div class="settings-grid">
            <div class="card-form settings-card">
                <h2 class="form-section-title">Account Email</h2>
                <p class="settings-current">Current email: <strong><?= htmlspecialchars($user['email']) ?></strong></p>
                <form id="emailForm">
                    <div class="form-group">
                        <label for="new_email">New Email Address</label>
                        <input type="email" id="new_email" name="new_email"
                               placeholder="new@example.com" autocomplete="email">
                    </div>
                    <div class="form-group">
                        <label for="email_password">Current Password (to confirm)</label>
                        <input type="password" id="email_password" name="current_password"
                               placeholder="Your current password" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Email</button>
                </form>
            </div>

            <div class="card-form settings-card">
                <h2 class="form-section-title">Change Password</h2>
                <form id="passwordForm">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                               placeholder="Your current password" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="Minimum 8 characters" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_new_password">Confirm New Password</label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password"
                               placeholder="Repeat new password" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>

            <div class="card-form settings-card settings-card-danger">
                <h2 class="form-section-title danger-title">Danger Zone</h2>
                <p class="settings-warning">Deleting your account is permanent and cannot be undone. All your data will be removed.</p>
                <button class="btn btn-danger" id="deleteAccountBtn">Delete My Account</button>
            </div>
        </div>
    </main>

    <div id="toast" class="toast"></div>
    <script src="/app/assets/js/app.js"></script>
    <script>
        document.getElementById('emailForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const email = document.getElementById('new_email').value.trim();
            const password = document.getElementById('email_password').value;
            if (!email || !password) {
                showToast('Please fill in all fields', 'error');
                return;
            }
            try {
                const resp = await fetch('/api/users/me', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ new_email: email, current_password: password })
                });
                const data = await resp.json();
                if (resp.ok) {
                    showToast('Email updated!', 'success');
                    document.querySelector('.settings-current strong').textContent = email;
                    this.reset();
                } else {
                    showToast(data.error || 'Update failed', 'error');
                }
            } catch {
                showToast('Network error', 'error');
            }
        });

        document.getElementById('passwordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const current = document.getElementById('current_password').value;
            const newPw   = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_new_password').value;
            if (!current || !newPw || !confirm) {
                showToast('Please fill in all fields', 'error');
                return;
            }
            if (newPw !== confirm) {
                showToast('Passwords do not match', 'error');
                return;
            }
            if (newPw.length < 8) {
                showToast('Password must be at least 8 characters', 'error');
                return;
            }
            try {
                const resp = await fetch('/api/users/me', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ new_password: newPw, current_password: current })
                });
                const data = await resp.json();
                if (resp.ok) {
                    showToast('Password updated!', 'success');
                    this.reset();
                } else {
                    showToast(data.error || 'Update failed', 'error');
                }
            } catch {
                showToast('Network error', 'error');
            }
        });

        document.getElementById('deleteAccountBtn').addEventListener('click', async function() {
            if (!confirm('Are you sure? This will permanently delete your account and all data.')) return;
            if (!confirm('Really? This cannot be undone.')) return;
            try {
                const resp = await fetch('/api/users/me', {
                    method: 'DELETE',
                    credentials: 'same-origin'
                });
                if (resp.ok) {
                    window.location.href = '/app/login.php';
                } else {
                    const data = await resp.json();
                    showToast(data.error || 'Delete failed', 'error');
                }
            } catch {
                showToast('Network error', 'error');
            }
        });
    </script>
</body>
</html>
