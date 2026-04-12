<?php

require_once __DIR__ . '/_event_sync_common.php';

use PanicBooking\EventSync\VenueScorer;

/**
 * @param array<int,string> $argv
 */
function panicComputeVenueScoresMain(array $argv): int {
    panicScriptGuard('scripts/compute_venue_scores.php');

    $options = panicParseLongOptions($argv);
    if (isset($options['help'])) {
        panicSyncUsage('scripts/compute_venue_scores.php', [
            '--dry-run    Preview score updates',
            '--verbose    Verbose logging',
        ]);
        return 0;
    }

    $verbose = panicSyncOptionBool($options, 'verbose');
    $dryRun = panicSyncOptionBool($options, 'dry-run');

    $ctx = panicSyncBootstrap($verbose);
    /** @var PanicBooking\EventSync\ConsoleLogger $logger */
    $logger = $ctx['logger'];
    $pdo = $ctx['pdo'];
    $syncConfig = (array)$ctx['sync_config'];

    $scorer = new VenueScorer($pdo, $syncConfig);
    $result = $scorer->compute($dryRun);

    $logger->info('Venue score computation complete' . ($dryRun ? ' (dry-run)' : ''));
    $logger->info('Venues scored: ' . (int)$result['scored']);
    foreach ((array)$result['tiers'] as $tier => $count) {
        $logger->info('  ' . $tier . ': ' . (int)$count);
    }

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(panicComputeVenueScoresMain($argv));
}
