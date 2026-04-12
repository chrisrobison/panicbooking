<?php

require_once __DIR__ . '/_event_sync_common.php';

use PanicBooking\EventSync\DarkNightService;

/**
 * @param array<int,string> $argv
 */
function panicComputeDarkNightsMain(array $argv): int {
    panicScriptGuard('scripts/compute_dark_nights.php');

    $options = panicParseLongOptions($argv);
    if (isset($options['help'])) {
        panicSyncUsage('scripts/compute_dark_nights.php', [
            '--days=<n>         Window size in days (default 60)',
            '--date-from=<date> Window start date (YYYY-MM-DD, default today)',
            '--dry-run          Preview dark-night results without writing DB',
            '--verbose          Verbose logging',
        ]);
        return 0;
    }

    $verbose = panicSyncOptionBool($options, 'verbose');
    $dryRun = panicSyncOptionBool($options, 'dry-run');
    $days = max(1, min(120, (int)($options['days'] ?? 60)));

    $dateFrom = trim((string)($options['date-from'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        echo "Invalid --date-from value. Expected YYYY-MM-DD\n";
        return 1;
    }

    $ctx = panicSyncBootstrap($verbose);
    /** @var PanicBooking\EventSync\ConsoleLogger $logger */
    $logger = $ctx['logger'];
    $syncConfig = (array)$ctx['sync_config'];

    $service = new DarkNightService(
        $ctx['pdo'],
        (string)($syncConfig['timezone'] ?? 'America/Los_Angeles')
    );

    $result = $service->compute($days, $dateFrom !== '' ? $dateFrom : null, $dryRun);

    $logger->info('Dark-night computation complete' . ($dryRun ? ' (dry-run)' : ''));
    $logger->info('Venues analyzed: ' . (int)$result['venues']);
    $logger->info('Dark nights: ' . (int)$result['dark_nights']);
    $logger->info('Confidence -> high: ' . (int)$result['high_confidence']
        . ', medium: ' . (int)$result['medium_confidence']
        . ', low: ' . (int)$result['low_confidence']);

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(panicComputeDarkNightsMain($argv));
}
