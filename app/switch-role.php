<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /app/dashboard.php');
    exit;
}

csrfRequireValid($_POST['csrf_token'] ?? '');
$roleKey = trim((string)($_POST['role_key'] ?? ''));
$next = trim((string)($_POST['next'] ?? '/app/dashboard.php'));
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
    $next = '/app/dashboard.php';
}

switchActiveRole($roleKey, $pdo);

header('Location: ' . $next);
exit;
