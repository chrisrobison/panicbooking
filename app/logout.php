<?php
require_once __DIR__ . '/includes/auth.php';
logout();
header('Location: /app/login.php');
exit;
