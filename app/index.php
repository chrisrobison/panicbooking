<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard.php');
} else {
    header('Location: /app/login.php');
}
exit;
