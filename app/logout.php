<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

csrfRequireValid($_POST['csrf_token'] ?? '');
logout();
header('Location: /app/login.php');
exit;
