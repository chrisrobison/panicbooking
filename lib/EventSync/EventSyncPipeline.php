<?php

namespace PanicBooking\EventSync;

class EventSyncPipeline {
    private VenueCatalog $catalog;
    private SyncRepository $repo;
    private SourcePriority $sourcePriority;
    private ConsoleLogger $logger;
    private string $timezone;

    public function __construct(
        VenueCatalog $catalog,
        SyncRepository $repo,
        SourcePriority $sourcePriority,
        ConsoleLogger $logger,
        string $timezone = 'America/Los_Angeles'
    ) {
        $this->catalog = $catalog;
        $this->repo = $repo;
        $this->sourcePriority = $sourcePriority;
        $this->logger = $logger;
        $this->timezone = $timezone;
    }

    /**
     * @param array<int, BaseAdapter> $adapters
     */
    public function run(array $adapters, string $venueFilter = 'all', bool $dryRun = false): SyncReport {
        $report = new SyncReport();

        foreach ($adapters as $adapter) {
            $source = $adapter->key();
            $this->logger->info('Syncing source: ' . $source);

            try {
                $events = $adapter->fetchEvents();
            } catch (\Throwable $e) {
                $report->addError($source . ' fetch failed: ' . $e->getMessage());
                $this->logger->error($source . ' failed: ' . $e->getMessage());
                $failedSlug = trim((string)$adapter->option('slug', ''));
                if ($failedSlug !== '') {
                    $this->repo->updateVenueSyncStatus($failedSlug, 'error', $e->getMessage());
                    $report->addVenueStat($failedSlug, 'errors');
                }
                continue;
            }

            $this->logger->info($source . ' fetched ' . count($events) . ' events');
            $report->rawFetched += count($events);

            $touchedSlugs = [];

            foreach ($events as $rawEvent) {
                $venue = null;
                try {
                    $venueName = Normalizer::cleanText((string)($rawEvent['venue_name'] ?? ''));
                    if ($venueName === '') {
                        continue;
                    }

                    $city = (string)($rawEvent['venue_city'] ?? 'San Francisco');
                    $state = (string)($rawEvent['venue_state'] ?? 'CA');
                    $venue = $this->catalog->resolveByName($venueName, $city, $state);

                    if ($venueFilter !== 'all' && $venue['slug'] !== $venueFilter) {
                        continue;
                    }

                    $venueId = $this->repo->upsertVenue($venue, $dryRun);
                    $touchedSlugs[$venue['slug']] = true;

                    $event = $this->normalizeEvent($rawEvent, $venue, $source);
                    if ($event === null) {
                        continue;
                    }

                    $ingestion = $this->repo->upsertIngestionEvent($event, $venueId, $dryRun);
                    if ($ingestion['action'] === 'inserted') {
                        $report->ingestionInserted++;
                    } else {
                        $report->ingestionUpdated++;
                    }

                    $canonical = $this->repo->upsertCanonicalEvent($event, $dryRun);
                    if ($canonical['action'] === 'inserted') {
                        $report->canonicalInserted++;
                        $report->addVenueStat($venue['slug'], 'inserted');
                    } else {
                        $report->canonicalUpdated++;
                        $report->addVenueStat($venue['slug'], 'updated');
                    }

                    if ($canonical['duplicate_merge']) {
                        $report->duplicateMerges++;
                        $report->addVenueStat($venue['slug'], 'merged');
                    }

                    $report->addVenueStat($venue['slug'], 'fetched');
                } catch (\Throwable $e) {
                    $report->addError($source . ' event error: ' . $e->getMessage());
                    $this->logger->warn($source . ' event error: ' . $e->getMessage());
                    $slug = is_array($venue) ? trim((string)($venue['slug'] ?? '')) : '';
                    if ($slug !== '') {
                        $report->addVenueStat($slug, 'errors');
                        $this->repo->updateVenueSyncStatus($slug, 'error', $e->getMessage());
                    }
                }
            }

            foreach (array_keys($touchedSlugs) as $slug) {
                $this->repo->updateVenueSyncStatus($slug, 'ok', '');
            }
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $rawEvent
     * @param array<string,mixed> $venue
     * @return array<string,mixed>|null
     */
    private function normalizeEvent(array $rawEvent, array $venue, string $source): ?array {
        $title = Normalizer::cleanText((string)($rawEvent['title'] ?? ''));
        $subtitle = Normalizer::cleanText((string)($rawEvent['subtitle'] ?? ''));
        $eventDate = trim((string)($rawEvent['event_date'] ?? ''));
        $sourceEventId = trim((string)($rawEvent['source_event_id'] ?? ''));

        $startDateTime = trim((string)($rawEvent['start_datetime'] ?? ''));
        if ($startDateTime === '') {
            $startDateTime = (string)Normalizer::combineDateTime($eventDate, (string)($rawEvent['show_time'] ?? ''), $this->timezone);
        }
        if ($startDateTime === '' || !preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $startDateTime)) {
            return null;
        }

        if ($eventDate === '') {
            $eventDate = substr($startDateTime, 0, 10);
        }

        if ($title === '') {
            $bands = is_array($rawEvent['bands'] ?? null) ? $rawEvent['bands'] : [];
            $bands = Normalizer::normalizeBandList($bands);
            if (!empty($bands)) {
                $title = $bands[0];
            }
        }
        if ($title === '') {
            return null;
        }

        $doorsDateTime = trim((string)($rawEvent['doors_datetime'] ?? ''));
        if ($doorsDateTime === '' && !empty($rawEvent['doors_time'])) {
            $doorsDateTime = (string)Normalizer::combineDateTime($eventDate, (string)$rawEvent['doors_time'], $this->timezone);
        }

        $showTime = Normalizer::cleanText((string)($rawEvent['show_time'] ?? ''));
        if ($showTime === '') {
            $showTime = strtolower((string)date('g:ia', strtotime($startDateTime)));
        }

        $doorsTime = Normalizer::cleanText((string)($rawEvent['doors_time'] ?? ''));
        if ($doorsTime === '' && $doorsDateTime !== '') {
            $doorsTime = strtolower((string)date('g:ia', strtotime($doorsDateTime)));
        }

        $normalizedTitle = Normalizer::normalizeTitle($title);
        $mergeKey = $venue['slug'] . '|' . $startDateTime . '|' . $normalizedTitle;
        $canonicalEventKey = sha1($mergeKey);

        $sourceUrl = Normalizer::cleanText((string)($rawEvent['source_url'] ?? ''));
        $ticketUrl = Normalizer::cleanText((string)($rawEvent['ticket_url'] ?? ''));
        if ($sourceUrl === '') {
            $sourceUrl = $ticketUrl;
        }

        $sourcePriority = isset($rawEvent['source_priority'])
            ? (int)$rawEvent['source_priority']
            : max((int)$venue['source_priority_default'], $this->sourcePriority->forSource($source, (int)$venue['source_priority_default']));

        $bands = is_array($rawEvent['bands'] ?? null) ? $rawEvent['bands'] : Normalizer::inferBands($title, $subtitle);
        $bands = Normalizer::normalizeBandList($bands);
        if (empty($bands)) {
            $bands = [$title];
        }

        $rawPayload = $rawEvent['raw_payload'] ?? $rawEvent;
        if (is_array($rawPayload) || is_object($rawPayload)) {
            $rawPayload = json_encode($rawPayload, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($rawPayload)) {
            $rawPayload = '';
        }

        $now = date('Y-m-d H:i:s');
        $ingestFingerprintSeed = $sourceEventId !== ''
            ? $source . '|' . $sourceEventId
            : $source . '|' . $mergeKey . '|' . $sourceUrl;

        $venueCity = (string)$venue['city'];
        if (strtolower($venueCity) === 'san francisco') {
            $venueCity = 'S.F.';
        }

        return [
            'source' => $source,
            'source_url' => $sourceUrl,
            'source_event_id' => $sourceEventId,
            'title' => $title,
            'subtitle' => $subtitle,
            'event_date' => $eventDate,
            'start_datetime' => $startDateTime,
            'doors_datetime' => $doorsDateTime !== '' ? $doorsDateTime : null,
            'show_time' => $showTime,
            'doors_time' => $doorsTime,
            'age_restriction' => Normalizer::cleanText((string)($rawEvent['age_restriction'] ?? '')),
            'ticket_url' => $ticketUrl,
            'status' => Normalizer::cleanText((string)($rawEvent['status'] ?? '')),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'source_priority' => $sourcePriority,
            'raw_payload' => $rawPayload,
            'normalized_title' => $normalizedTitle,
            'raw_meta' => Normalizer::cleanText((string)($rawEvent['raw_meta'] ?? '')),
            'notes' => Normalizer::cleanText((string)($rawEvent['notes'] ?? '')),
            'price' => Normalizer::cleanText((string)($rawEvent['price'] ?? '')),
            'is_ticketed' => (int)($rawEvent['is_ticketed'] ?? ($ticketUrl !== '' ? 1 : 0)),
            'is_sold_out' => (int)($rawEvent['is_sold_out'] ?? 0),
            'merge_key' => $mergeKey,
            'canonical_event_key' => $canonicalEventKey,
            'canonical_venue_slug' => (string)$venue['slug'],
            'venue_name' => (string)$venue['display_name'],
            'venue_city' => $venueCity,
            'bands_json' => json_encode($bands, JSON_UNESCAPED_UNICODE),
            'ingest_fingerprint' => sha1($ingestFingerprintSeed),
        ];
    }
}
