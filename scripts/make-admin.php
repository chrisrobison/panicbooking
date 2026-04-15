#!/usr/bin/env php
<?php
/**
 * make-admin.php — Grant or create an admin account for Panic Booking.
 *
 * Usage:
 *   php scripts/make-admin.php <email>
 *       Grants admin to the existing user with that email.
 *
 *   php scripts/make-admin.php <email> <password>
 *       Creates a new account (type=band) if none exists, then grants admin.
 *       Also works to reset the password + ensure admin on an existing account.
 *
 *   php scripts/make-admin.php --list
 *       Lists all current admin accounts.
 *
 * Exit codes: 0 = success, 1 = error
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('PB_CLI', true);
chdir(dirname(__DIR__));

require_once 'lib/security.php';
require_once 'lib/db_compat.php';
require_once 'lib/db_bootstrap.php';
require_once 'config/database.php';

// ── Connect ────────────────────────────────────────────────────────────────
try {
    $pdo = panicDb();
    panicDbBootstrap($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Parse args ────────────────────────────────────────────────────────────
$args  = array_slice($argv, 1);
$first = $args[0] ?? '';

// --list mode
if ($first === '--list') {
    $stmt = $pdo->query("SELECT u.id, u.email, u.type, u.created_at FROM users u WHERE u.is_admin = 1 ORDER BY u.created_at ASC");
    $admins = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$admins) {
        echo "No admin accounts found.\n";
        exit(0);
    }
    echo str_pad('ID', 6) . str_pad('Type', 8) . str_pad('Email', 40) . "Created\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($admins as $a) {
        echo str_pad((string)$a['id'], 6)
           . str_pad($a['type'], 8)
           . str_pad($a['email'], 40)
           . $a['created_at'] . "\n";
    }
    exit(0);
}

$email = strtolower(trim($first));
$pass  = trim($args[1] ?? '');

if ($email === '') {
    echo "Panic Booking — Admin Management\n\n";
    echo "Usage:\n";
    echo "  php scripts/make-admin.php <email>            Grant admin to existing account\n";
    echo "  php scripts/make-admin.php <email> <password> Create account + grant admin\n";
    echo "  php scripts/make-admin.php --list             List all admin accounts\n";
    exit(0);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: '$email' is not a valid email address.\n");
    exit(1);
}

// ── Look up existing user ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, email, type, is_admin FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // Update password if provided
    if ($pass !== '') {
        if (strlen($pass) < 8) {
            fwrite(STDERR, "Error: password must be at least 8 characters.\n");
            exit(1);
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, (int)$user['id']]);
        echo "   Password updated.\n";
    }

    if ((bool)$user['is_admin']) {
        echo "ℹ  {$email} already has admin access (id={$user['id']}, type={$user['type']}).\n";
    } else {
        $pdo->prepare("UPDATE users SET is_admin = 1 WHERE id = ?")->execute([(int)$user['id']]);
        echo "✅ Admin granted to {$email} (id={$user['id']}, type={$user['type']}).\n";
    }
    exit(0);
}

// ── User not found — create if password given ─────────────────────────────
if ($pass === '') {
    fwrite(STDERR, "No account found for '$email'.\n");
    fwrite(STDERR, "To create a new admin account, provide a password:\n");
    fwrite(STDERR, "  php scripts/make-admin.php $email <password>\n");
    exit(1);
}

if (strlen($pass) < 8) {
    fwrite(STDERR, "Error: password must be at least 8 characters.\n");
    exit(1);
}

try {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare("INSERT INTO users (email, password_hash, type, is_admin) VALUES (?, ?, 'band', 1)");
    $ins->execute([$email, $hash]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO profiles (user_id, type, data) VALUES (?, 'band', ?)")
        ->execute([$newId, json_encode([
            'name'          => '',
            'contact_email' => $email,
            'description'   => '',
        ])]);

    echo "✅ Admin account created:\n";
    echo "   Email : {$email}\n";
    echo "   ID    : {$newId}\n";
    echo "   Type  : band (editable via admin panel)\n";
    echo "\nThis account can now log in at /app/login.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error creating account: " . $e->getMessage() . "\n");
    exit(1);
}
