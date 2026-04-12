<?php

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('scrape_venues.php');
require_once __DIR__ . '/scripts/sync_events.php';

$adapter = 'official';
$venue = 'all';
$dryRun = false;
$verbose = false;

if (PHP_SAPI === 'cli') {
    $target = strtolower(trim((string)($argv[1] ?? 'all')));
} else {
    $target = strtolower(trim((string)($_GET['venue'] ?? 'all')));
}

$map = [
    'gamh' => ['adapter' => 'gamh', 'venue' => 'great-american-music-hall'],
    'warfield' => ['adapter' => 'warfield', 'venue' => 'the-warfield'],
    'regency' => ['adapter' => 'regency', 'venue' => 'regency-ballroom'],
    'fillmore' => ['adapter' => 'fillmore', 'venue' => 'the-fillmore'],
    'ticketmaster' => ['adapter' => 'ticketmaster', 'venue' => 'bill-graham-civic-auditorium'],
    'all' => ['adapter' => 'official', 'venue' => 'all'],
];

if (isset($map[$target])) {
    $adapter = $map[$target]['adapter'];
    $venue = $map[$target]['venue'];
}

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 2) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        }
        if ($arg === '--verbose') {
            $verbose = true;
        }
    }
}

$args = ['sync_events.php', '--adapter=' . $adapter, '--venue=' . $venue];
if ($dryRun) {
    $args[] = '--dry-run';
}
if ($verbose) {
    $args[] = '--verbose';
}

$_SERVER['argv'] = $args;
$GLOBALS['argv'] = $args;

exit(panicSyncEventsMain($args));
