<?php

require_once __DIR__ . '/_event_sync_common.php';

/**
 * @param array<int,string> $argv
 */
function panicSyncVenuesMain(array $argv): int {
    panicScriptGuard('scripts/sync_venues.php');

    $options = panicParseLongOptions($argv);
    if (isset($options['help'])) {
        panicSyncUsage('scripts/sync_venues.php', [
            '--dry-run            Preview changes without writing DB',
            '--verbose            Verbose log output',
            '--include-discovered Also sync venues discovered in scraped_events',
        ]);
        return 0;
    }

    $verbose = panicSyncOptionBool($options, 'verbose');
    $dryRun = panicSyncOptionBool($options, 'dry-run');
    $includeDiscovered = panicSyncOptionBool($options, 'include-discovered');

    $ctx = panicSyncBootstrap($verbose);
    /** @var PanicBooking\EventSync\ConsoleLogger $logger */
    $logger = $ctx['logger'];
    /** @var PanicBooking\EventSync\VenueCatalog $catalog */
    $catalog = $ctx['catalog'];
    /** @var PanicBooking\EventSync\SyncRepository $repo */
    $repo = $ctx['repo'];

    $inserted = 0;
    $updated = 0;

    foreach ($catalog->all() as $venue) {
        $existing = $repo->findVenueBySlug((string)$venue['slug']);
        $repo->upsertVenue($venue, $dryRun);

        if ($existing === null) {
            $inserted++;
            $logger->verbose('Inserted canonical venue: ' . $venue['display_name']);
        } else {
            $updated++;
            $logger->verbose('Updated canonical venue: ' . $venue['display_name']);
        }
    }

    $discoveredInserted = 0;
    $discoveredUpdated = 0;

    if ($includeDiscovered) {
        $rows = $repo->listDistinctVenuesFromScrapedEvents();
        foreach ($rows as $row) {
            $name = trim((string)($row['venue_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $resolved = $catalog->resolveByName($name, 'San Francisco', 'CA');
            $existing = $repo->findVenueBySlug((string)$resolved['slug']);
            $repo->upsertVenue($resolved, $dryRun);

            if ($existing === null) {
                $discoveredInserted++;
            } else {
                $discoveredUpdated++;
            }
        }
    }

    $logger->info('Venue sync complete' . ($dryRun ? ' (dry-run)' : ''));
    $logger->info('Canonical inserted: ' . $inserted . ', canonical updated: ' . $updated);
    if ($includeDiscovered) {
        $logger->info('Discovered inserted: ' . $discoveredInserted . ', discovered updated: ' . $discoveredUpdated);
    }

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(panicSyncVenuesMain($argv));
}
