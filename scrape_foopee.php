<?php

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('scrape_foopee.php');
require_once __DIR__ . '/scripts/sync_events.php';

$args = ['sync_events.php', '--adapter=foopee'];

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '--verbose' || str_starts_with($arg, '--venue=')) {
            $args[] = $arg;
        }
    }
}

$_SERVER['argv'] = $args;
$GLOBALS['argv'] = $args;

exit(panicSyncEventsMain($args));
