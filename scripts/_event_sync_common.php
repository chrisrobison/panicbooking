<?php

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/db_bootstrap.php';
require_once __DIR__ . '/../lib/EventSync/bootstrap.php';

use PanicBooking\EventSync\ConsoleLogger;
use PanicBooking\EventSync\SourcePriority;
use PanicBooking\EventSync\SyncRepository;
use PanicBooking\EventSync\VenueCatalog;

/**
 * @return array<string,mixed>
 */
function panicSyncBootstrap(bool $verbose): array {
    $pdo = panicDb();
    panicDbBootstrap($pdo);

    $syncConfig = require __DIR__ . '/../config/event_sync.php';
    $venueConfig = require __DIR__ . '/../config/venues.php';

    $logger = new ConsoleLogger($verbose);
    $catalog = new VenueCatalog($venueConfig);
    $repo = new SyncRepository($pdo);
    $priority = new SourcePriority((array)($syncConfig['source_priorities'] ?? []));

    return [
        'pdo' => $pdo,
        'sync_config' => $syncConfig,
        'venue_config' => $venueConfig,
        'logger' => $logger,
        'catalog' => $catalog,
        'repo' => $repo,
        'priority' => $priority,
    ];
}

/**
 * @param array<string,mixed> $options
 */
function panicSyncOptionBool(array $options, string $name): bool {
    return array_key_exists($name, $options);
}

function panicSyncUsage(string $scriptName, array $lines): void {
    echo 'Usage: php ' . $scriptName . ' ' . PHP_EOL;
    foreach ($lines as $line) {
        echo '  ' . $line . PHP_EOL;
    }
}

/**
 * @param array<int,string> $argv
 * @return array<string,mixed>
 */
function panicParseLongOptions(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $raw = substr($arg, 2);
        if ($raw === '') {
            continue;
        }

        $eq = strpos($raw, '=');
        if ($eq === false) {
            $out[$raw] = true;
            continue;
        }

        $key = substr($raw, 0, $eq);
        $value = substr($raw, $eq + 1);
        $out[$key] = $value;
    }

    return $out;
}
