<?php

/**
 * File-based structured logger for Panic Booking.
 *
 * All events are written to log/app.log (combined / catch-all).
 * Events are also routed to a category-specific file:
 *
 *   log/payment.log  — webhooks, orders, payment processing
 *   log/auth.log     — password resets, sessions, access guards
 *   log/db.log       — DB connections, migrations, bootstrap
 *   log/api.log      — API errors, ticket ops, check-ins
 *   log/admin.log    — admin actions (venue creation, promotions, etc.)
 *   log/app.log      — every entry regardless of category
 *
 * Falls back to error_log() if the log directory is not writable.
 *
 * Optional env var:
 *   PB_LOG_DIR  — override the default log directory (./log)
 */

function panicLogDir(): string {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $configured = trim((string)(getenv('PB_LOG_DIR') ?: ''));
    $dir = $configured !== '' ? rtrim($configured, '/') : __DIR__ . '/../log';
    return $dir;
}

/**
 * Generate (or reuse) a short random ID that is stable for the lifetime of
 * the current PHP process/request.  Lets you correlate multiple log lines
 * that belong to the same request without any external middleware.
 */
function panicRequestId(): string {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(6));
    }
    return $id;
}

/**
 * Route an event name to its category log file.
 * Returns the filename only — panicWriteLog() prepends the directory.
 * Every event is *also* written to app.log regardless of this return value.
 */
function panicLogCategory(string $event): string {
    // Rules are evaluated top-to-bottom; first prefix match wins.
    // More-specific prefixes must come before broader ones (e.g.
    // 'api_create_order' before 'api_').
    static $rules = [
        // ── Payment / webhooks ────────────────────────────────────────────
        'stripe_'               => 'payment.log',
        'square_'               => 'payment.log',
        'payment_'              => 'payment.log',
        'create_order'          => 'payment.log',
        'mark_order'            => 'payment.log',
        'api_create_order'      => 'payment.log',
        'api_mark_order'        => 'payment.log',

        // ── Auth / sessions ───────────────────────────────────────────────
        'password_reset'        => 'auth.log',
        'session_'              => 'auth.log',
        'maintenance_'          => 'auth.log',

        // ── Database / bootstrap ──────────────────────────────────────────
        'db_'                   => 'db.log',
        'bootstrap_'            => 'db.log',
        'demo_seed'             => 'db.log',

        // ── Admin actions ─────────────────────────────────────────────────
        'admin_'                => 'admin.log',

        // ── API / ticket ops (broad api_ catch comes last) ────────────────
        'api_'                  => 'api.log',
        'check_in'              => 'api.log',
        'booking_api'           => 'api.log',
    ];

    foreach ($rules as $prefix => $file) {
        if (str_starts_with($event, $prefix)) {
            return $file;
        }
    }

    return 'app.log';   // unknown events stay in the catch-all only
}

/**
 * Infer a log level from the event name when the caller does not supply one.
 * Used by ticketingLog() which has no level parameter.
 */
function panicLogInferLevel(string $event): string {
    if (
        str_ends_with($event, '_failed')
        || str_ends_with($event, '_failure')
        || str_ends_with($event, '_error')
        || str_contains($event, '_mismatch')
    ) {
        return 'error';
    }
    if (
        str_ends_with($event, '_warning')
        || str_ends_with($event, '_warn')
        || str_ends_with($event, '_forbidden')
    ) {
        return 'warning';
    }
    return 'info';
}

/**
 * Write a structured JSON log line to the appropriate category file and to
 * app.log.  Falls back to error_log() if neither file is writable.
 *
 * @param string $event   Machine-readable event name, e.g. 'square_webhook_received'
 * @param array  $context Arbitrary key/value pairs
 * @param string $level   'info' | 'warning' | 'error'
 */
function panicWriteLog(string $event, array $context = [], string $level = 'info'): void {
    $dir  = panicLogDir();
    $line = json_encode([
        'ts'      => date('c'),
        'level'   => $level,
        'req'     => panicRequestId(),
        'event'   => $event,
        'context' => $context ?: (object)[],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $category = panicLogCategory($event);
    $appLog   = $dir . '/app.log';
    $catLog   = $dir . '/' . $category;

    // Use umask 0002 so newly created log files get 0664 permissions,
    // allowing both the owner (cdr) and group (www-data) to append.
    $prev = umask(0002);

    $catOk = ($catLog === $appLog) || (@file_put_contents($catLog, $line, FILE_APPEND | LOCK_EX) !== false);
    $appOk = @file_put_contents($appLog, $line, FILE_APPEND | LOCK_EX) !== false;

    umask($prev);

    // If both writes failed, fall back to PHP's error_log so nothing is silently lost
    if (!$catOk && !$appOk) {
        error_log('[panicbooking:' . $level . '] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}
