<?php

require_once __DIR__ . '/_event_sync_common.php';

use PanicBooking\EventSync\Adapters\FillmoreAdapter;
use PanicBooking\EventSync\Adapters\FoopeeAdapter;
use PanicBooking\EventSync\Adapters\GamhAdapter;
use PanicBooking\EventSync\Adapters\RegencyAdapter;
use PanicBooking\EventSync\Adapters\TicketmasterAdapter;
use PanicBooking\EventSync\Adapters\WarfieldAdapter;
use PanicBooking\EventSync\BaseAdapter;
use PanicBooking\EventSync\EventSyncPipeline;

/**
 * @param array<int,string> $argv
 */
function panicSyncEventsMain(array $argv): int {
    panicScriptGuard('scripts/sync_events.php');

    $options = panicParseLongOptions($argv);

    if (isset($options['help'])) {
        panicSyncUsage('scripts/sync_events.php', [
            '--venue=<slug|all>       Limit sync to one canonical venue slug (default: all)',
            '--adapter=<key|all>      One adapter (`foopee`, `gamh`, `warfield`, `regency`, `fillmore`, `ticketmaster`, `official`, `all`)',
            '--dry-run                Preview event merges without DB writes',
            '--verbose                Verbose logging',
            '--skip-venue-sync        Do not refresh canonical venue registry before sync',
        ]);
        return 0;
    }

    $verbose = panicSyncOptionBool($options, 'verbose');
    $dryRun = panicSyncOptionBool($options, 'dry-run');
    $skipVenueSync = panicSyncOptionBool($options, 'skip-venue-sync');
    $venueFilter = trim((string)($options['venue'] ?? 'all'));
    if ($venueFilter === '') {
        $venueFilter = 'all';
    }
    $adapterFilter = strtolower(trim((string)($options['adapter'] ?? 'all')));
    if ($adapterFilter === '') {
        $adapterFilter = 'all';
    }

    $ctx = panicSyncBootstrap($verbose);
    /** @var PanicBooking\EventSync\ConsoleLogger $logger */
    $logger = $ctx['logger'];
    /** @var PanicBooking\EventSync\VenueCatalog $catalog */
    $catalog = $ctx['catalog'];
    /** @var PanicBooking\EventSync\SyncRepository $repo */
    $repo = $ctx['repo'];
    /** @var PanicBooking\EventSync\SourcePriority $priority */
    $priority = $ctx['priority'];
    $syncConfig = (array)$ctx['sync_config'];

    if (!$skipVenueSync) {
        panicSyncEnsureCanonicalVenues($catalog, $repo, $logger, $dryRun, true);
    }

    $adapters = panicBuildSyncAdapters($catalog->all(), $logger, (string)($syncConfig['timezone'] ?? 'America/Los_Angeles'), $adapterFilter, $venueFilter);

    if (empty($adapters)) {
        $logger->warn('No adapters matched filters.');
        return 1;
    }

    $pipeline = new EventSyncPipeline(
        $catalog,
        $repo,
        $priority,
        $logger,
        (string)($syncConfig['timezone'] ?? 'America/Los_Angeles')
    );

    $report = $pipeline->run($adapters, $venueFilter, $dryRun);

    $logger->info('Event sync complete' . ($dryRun ? ' (dry-run)' : ''));
    $logger->info('Fetched: ' . $report->rawFetched);
    $logger->info('Ingestion inserted: ' . $report->ingestionInserted . ', updated: ' . $report->ingestionUpdated);
    $logger->info('Canonical inserted: ' . $report->canonicalInserted . ', updated: ' . $report->canonicalUpdated . ', duplicate merges: ' . $report->duplicateMerges);

    if (!empty($report->perVenue)) {
        $logger->info('Per-venue summary:');
        foreach ($report->perVenue as $slug => $stats) {
            $logger->info(sprintf(
                '  %s -> fetched:%d inserted:%d updated:%d merged:%d errors:%d',
                $slug,
                (int)$stats['fetched'],
                (int)$stats['inserted'],
                (int)$stats['updated'],
                (int)$stats['merged'],
                (int)$stats['errors']
            ));
        }
    }

    if (!empty($report->errorMessages)) {
        $logger->warn('Errors: ' . count($report->errorMessages));
        foreach ($report->errorMessages as $msg) {
            $logger->warn('  ' . $msg);
        }
        return 2;
    }

    return 0;
}

/**
 * @param array<int,array<string,mixed>> $venues
 * @return array<int,BaseAdapter>
 */
function panicBuildSyncAdapters(array $venues, PanicBooking\EventSync\ConsoleLogger $logger, string $timezone, string $adapterFilter, string $venueFilter): array {
    $adapters = [];

    $addFoopee = in_array($adapterFilter, ['all', 'foopee'], true);

    if ($addFoopee) {
        $adapters[] = new FoopeeAdapter($logger, ['timezone' => $timezone]);
    }

    foreach ($venues as $venue) {
        if (!(bool)($venue['sync_enabled'] ?? true)) {
            continue;
        }

        $slug = (string)($venue['slug'] ?? '');
        if ($slug === '') {
            continue;
        }

        if ($venueFilter !== 'all' && $slug !== $venueFilter) {
            continue;
        }

        $class = $venue['adapter_class'] ?? null;
        if (!is_string($class) || $class === '') {
            continue;
        }

        if (!class_exists($class)) {
            continue;
        }

        /** @var BaseAdapter $instance */
        $instance = new $class($logger, [
            'timezone' => $timezone,
            'slug' => $slug,
            'display_name' => (string)$venue['display_name'],
            'ticketmaster_venue_id' => (string)($venue['ticketmaster_venue_id'] ?? ''),
        ]);

        $key = $instance->key();
        if ($adapterFilter !== 'all' && $adapterFilter !== 'official' && $adapterFilter !== $key) {
            continue;
        }
        if ($adapterFilter === 'official' && $key === 'foopee') {
            continue;
        }

        $adapters[] = $instance;
    }

    return $adapters;
}

function panicSyncEnsureCanonicalVenues(
    PanicBooking\EventSync\VenueCatalog $catalog,
    PanicBooking\EventSync\SyncRepository $repo,
    PanicBooking\EventSync\ConsoleLogger $logger,
    bool $dryRun,
    bool $includeDiscovered
): void {
    foreach ($catalog->all() as $venue) {
        $repo->upsertVenue($venue, $dryRun);
    }

    if (!$includeDiscovered) {
        return;
    }

    foreach ($repo->listDistinctVenuesFromScrapedEvents() as $row) {
        $name = trim((string)($row['venue_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $resolved = $catalog->resolveByName($name, 'San Francisco', 'CA');
        $repo->upsertVenue($resolved, $dryRun);
    }

    $logger->verbose('Canonical venue registry refreshed' . ($dryRun ? ' (dry-run)' : ''));
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(panicSyncEventsMain($argv));
}
