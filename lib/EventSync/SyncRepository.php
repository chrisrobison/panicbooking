<?php

namespace PanicBooking\EventSync;

use PDO;
use PDOException;

class SyncRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO {
        return $this->pdo;
    }

    /**
     * @param array<string, mixed> $venue
     */
    public function upsertVenue(array $venue, bool $dryRun = false): ?int {
        $slug = (string)$venue['slug'];
        if ($slug === '') {
            return null;
        }

        if ($dryRun) {
            $existing = $this->findVenueBySlug($slug);
            return $existing['id'] ?? null;
        }

        $sql = '';
        if ($this->isMysql()) {
            $sql = "
                INSERT INTO event_sync_venues
                  (slug, name_key, display_name, aliases_json, city, state, venue_type, capacity_estimate,
                   prestige_weight, activity_weight, source_priority_default, sync_enabled,
                   official_calendar_url, adapter_class, is_core_venue, has_official_sync,
                   notoriety_multiplier, updated_at)
                VALUES
                  (:slug, :name_key, :display_name, :aliases_json, :city, :state, :venue_type, :capacity_estimate,
                   :prestige_weight, :activity_weight, :source_priority_default, :sync_enabled,
                   :official_calendar_url, :adapter_class, :is_core_venue, :has_official_sync,
                   :notoriety_multiplier, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                   name_key = VALUES(name_key),
                   display_name = VALUES(display_name),
                   aliases_json = VALUES(aliases_json),
                   city = VALUES(city),
                   state = VALUES(state),
                   venue_type = VALUES(venue_type),
                   capacity_estimate = VALUES(capacity_estimate),
                   prestige_weight = VALUES(prestige_weight),
                   activity_weight = VALUES(activity_weight),
                   source_priority_default = VALUES(source_priority_default),
                   sync_enabled = VALUES(sync_enabled),
                   official_calendar_url = VALUES(official_calendar_url),
                   adapter_class = VALUES(adapter_class),
                   is_core_venue = VALUES(is_core_venue),
                   has_official_sync = VALUES(has_official_sync),
                   notoriety_multiplier = VALUES(notoriety_multiplier),
                   updated_at = CURRENT_TIMESTAMP
            ";
        } else {
            $sql = "
                INSERT INTO event_sync_venues
                  (slug, name_key, display_name, aliases_json, city, state, venue_type, capacity_estimate,
                   prestige_weight, activity_weight, source_priority_default, sync_enabled,
                   official_calendar_url, adapter_class, is_core_venue, has_official_sync,
                   notoriety_multiplier, updated_at)
                VALUES
                  (:slug, :name_key, :display_name, :aliases_json, :city, :state, :venue_type, :capacity_estimate,
                   :prestige_weight, :activity_weight, :source_priority_default, :sync_enabled,
                   :official_calendar_url, :adapter_class, :is_core_venue, :has_official_sync,
                   :notoriety_multiplier, CURRENT_TIMESTAMP)
                ON CONFLICT(slug) DO UPDATE SET
                   name_key = excluded.name_key,
                   display_name = excluded.display_name,
                   aliases_json = excluded.aliases_json,
                   city = excluded.city,
                   state = excluded.state,
                   venue_type = excluded.venue_type,
                   capacity_estimate = excluded.capacity_estimate,
                   prestige_weight = excluded.prestige_weight,
                   activity_weight = excluded.activity_weight,
                   source_priority_default = excluded.source_priority_default,
                   sync_enabled = excluded.sync_enabled,
                   official_calendar_url = excluded.official_calendar_url,
                   adapter_class = excluded.adapter_class,
                   is_core_venue = excluded.is_core_venue,
                   has_official_sync = excluded.has_official_sync,
                   notoriety_multiplier = excluded.notoriety_multiplier,
                   updated_at = CURRENT_TIMESTAMP
            ";
        }

        $params = [
            ':slug' => $slug,
            ':name_key' => Normalizer::canonicalNameKey((string)$venue['display_name']),
            ':display_name' => (string)$venue['display_name'],
            ':aliases_json' => json_encode(array_values((array)$venue['aliases']), JSON_UNESCAPED_UNICODE),
            ':city' => (string)$venue['city'],
            ':state' => (string)$venue['state'],
            ':venue_type' => (string)$venue['venue_type'],
            ':capacity_estimate' => $venue['capacity_estimate'] === null ? null : (int)$venue['capacity_estimate'],
            ':prestige_weight' => (float)$venue['prestige_weight'],
            ':activity_weight' => (float)$venue['activity_weight'],
            ':source_priority_default' => (int)$venue['source_priority_default'],
            ':sync_enabled' => $venue['sync_enabled'] ? 1 : 0,
            ':official_calendar_url' => (string)$venue['official_calendar_url'],
            ':adapter_class' => (string)($venue['adapter_class'] ?? ''),
            ':is_core_venue' => $venue['is_core_venue'] ? 1 : 0,
            ':has_official_sync' => $venue['has_official_sync'] ? 1 : 0,
            ':notoriety_multiplier' => (float)$venue['notoriety_multiplier'],
        ];

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }

            $fallback = $this->pdo->prepare("
                UPDATE event_sync_venues
                SET display_name = :display_name,
                    aliases_json = :aliases_json,
                    city = :city,
                    state = :state,
                    venue_type = :venue_type,
                    capacity_estimate = :capacity_estimate,
                    prestige_weight = :prestige_weight,
                    activity_weight = :activity_weight,
                    source_priority_default = :source_priority_default,
                    sync_enabled = :sync_enabled,
                    official_calendar_url = :official_calendar_url,
                    adapter_class = :adapter_class,
                    is_core_venue = :is_core_venue,
                    has_official_sync = :has_official_sync,
                    notoriety_multiplier = :notoriety_multiplier,
                    updated_at = CURRENT_TIMESTAMP
                WHERE name_key = :name_key
            ");
            $fallback->execute([
                ':name_key' => $params[':name_key'],
                ':display_name' => $params[':display_name'],
                ':aliases_json' => $params[':aliases_json'],
                ':city' => $params[':city'],
                ':state' => $params[':state'],
                ':venue_type' => $params[':venue_type'],
                ':capacity_estimate' => $params[':capacity_estimate'],
                ':prestige_weight' => $params[':prestige_weight'],
                ':activity_weight' => $params[':activity_weight'],
                ':source_priority_default' => $params[':source_priority_default'],
                ':sync_enabled' => $params[':sync_enabled'],
                ':official_calendar_url' => $params[':official_calendar_url'],
                ':adapter_class' => $params[':adapter_class'],
                ':is_core_venue' => $params[':is_core_venue'],
                ':has_official_sync' => $params[':has_official_sync'],
                ':notoriety_multiplier' => $params[':notoriety_multiplier'],
            ]);
        }

        $existing = $this->findVenueBySlug($slug);
        if ($existing === null) {
            $byNameKey = $this->pdo->prepare('SELECT * FROM event_sync_venues WHERE name_key = :name_key LIMIT 1');
            $byNameKey->execute([':name_key' => $params[':name_key']]);
            $existing = $byNameKey->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $existing['id'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVenueBySlug(string $slug): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM event_sync_venues WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array{action:string,id:int|null}
     */
    public function upsertIngestionEvent(array $event, ?int $venueSyncId, bool $dryRun = false): array {
        $fingerprint = (string)$event['ingest_fingerprint'];
        $existingStmt = $this->pdo->prepare('SELECT id FROM event_ingestion_events WHERE ingest_fingerprint = :fingerprint LIMIT 1');
        $existingStmt->execute([':fingerprint' => $fingerprint]);
        $existingId = $existingStmt->fetchColumn();

        if ($dryRun) {
            return [
                'action' => $existingId ? 'updated' : 'inserted',
                'id' => $existingId ? (int)$existingId : null,
            ];
        }

        if ($existingId) {
            $sql = "
                UPDATE event_ingestion_events
                SET venue_sync_id = :venue_sync_id,
                    venue_slug = :venue_slug,
                    venue_name = :venue_name,
                    merge_key = :merge_key,
                    source = :source,
                    source_url = :source_url,
                    source_event_id = :source_event_id,
                    title = :title,
                    subtitle = :subtitle,
                    start_datetime = :start_datetime,
                    doors_datetime = :doors_datetime,
                    age_restriction = :age_restriction,
                    ticket_url = :ticket_url,
                    status = :status,
                    last_seen_at = :last_seen_at,
                    source_priority = :source_priority,
                    raw_payload = :raw_payload,
                    normalized_title = :normalized_title,
                    raw_meta = :raw_meta,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ";
            $stmt = $this->pdo->prepare($sql);
            $params = [
                ':id' => (int)$existingId,
                ':venue_sync_id' => $venueSyncId,
                ':venue_slug' => (string)$event['canonical_venue_slug'],
                ':venue_name' => (string)$event['venue_name'],
                ':merge_key' => (string)$event['merge_key'],
                ':source' => (string)$event['source'],
                ':source_url' => (string)$event['source_url'],
                ':source_event_id' => (string)$event['source_event_id'],
                ':title' => (string)$event['title'],
                ':subtitle' => (string)$event['subtitle'],
                ':start_datetime' => (string)$event['start_datetime'],
                ':doors_datetime' => $event['doors_datetime'],
                ':age_restriction' => (string)$event['age_restriction'],
                ':ticket_url' => (string)$event['ticket_url'],
                ':status' => (string)$event['status'],
                ':last_seen_at' => (string)$event['last_seen_at'],
                ':source_priority' => (int)$event['source_priority'],
                ':raw_payload' => (string)$event['raw_payload'],
                ':normalized_title' => (string)$event['normalized_title'],
                ':raw_meta' => (string)$event['raw_meta'],
            ];
            $stmt->execute($params);

            return ['action' => 'updated', 'id' => (int)$existingId];
        }

        $sql = "
            INSERT INTO event_ingestion_events
              (venue_sync_id, venue_slug, venue_name, merge_key, ingest_fingerprint,
               source, source_url, source_event_id, title, subtitle, start_datetime,
               doors_datetime, age_restriction, ticket_url, status, first_seen_at,
               last_seen_at, source_priority, raw_payload, normalized_title, raw_meta)
            VALUES
              (:venue_sync_id, :venue_slug, :venue_name, :merge_key, :ingest_fingerprint,
               :source, :source_url, :source_event_id, :title, :subtitle, :start_datetime,
               :doors_datetime, :age_restriction, :ticket_url, :status, :first_seen_at,
               :last_seen_at, :source_priority, :raw_payload, :normalized_title, :raw_meta)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->ingestionParams($event, $venueSyncId));
        return ['action' => 'inserted', 'id' => (int)$this->pdo->lastInsertId()];
    }

    /**
     * @return array{action:string,duplicate_merge:bool}
     */
    public function upsertCanonicalEvent(array $event, bool $dryRun = false): array {
        $existing = $this->findCanonicalByKey((string)$event['canonical_event_key']);
        if ($existing === null) {
            $existing = $this->findLegacyDuplicate($event);
        }

        if ($dryRun) {
            return [
                'action' => $existing ? 'updated' : 'inserted',
                'duplicate_merge' => $existing !== null,
            ];
        }

        if ($existing === null) {
            $sql = "
                INSERT INTO scraped_events
                  (event_date, venue_name, venue_city, bands, age_restriction, price, doors_time,
                   show_time, is_sold_out, is_ticketed, notes, raw_meta, source_url, scraped_at,
                   source, source_event_id, title, subtitle, start_datetime, doors_datetime, ticket_url,
                   status, first_seen_at, last_seen_at, source_priority, raw_payload, normalized_title,
                   canonical_event_key, canonical_venue_slug, last_merged_at)
                VALUES
                  (:event_date, :venue_name, :venue_city, :bands, :age_restriction, :price, :doors_time,
                   :show_time, :is_sold_out, :is_ticketed, :notes, :raw_meta, :source_url, CURRENT_TIMESTAMP,
                   :source, :source_event_id, :title, :subtitle, :start_datetime, :doors_datetime, :ticket_url,
                   :status, :first_seen_at, :last_seen_at, :source_priority, :raw_payload, :normalized_title,
                   :canonical_event_key, :canonical_venue_slug, CURRENT_TIMESTAMP)
            ";
            $stmt = $this->pdo->prepare($sql);
            try {
                $stmt->execute($this->canonicalParams($event));
            } catch (PDOException $e) {
                if (!$this->isDuplicateKeyException($e)) {
                    throw $e;
                }
                $existing = $this->findLegacyDuplicate($event);
                if ($existing === null) {
                    throw $e;
                }
                return $this->updateExistingCanonical($existing, $event);
            }

            return ['action' => 'inserted', 'duplicate_merge' => false];
        }

        return $this->updateExistingCanonical($existing, $event);
    }

    public function updateVenueSyncStatus(string $slug, string $status, string $error = ''): void {
        $stmt = $this->pdo->prepare("UPDATE event_sync_venues
            SET last_synced_at = CURRENT_TIMESTAMP,
                last_sync_status = :status,
                last_sync_error = :error,
                updated_at = CURRENT_TIMESTAMP
            WHERE slug = :slug");
        $stmt->execute([
            ':slug' => $slug,
            ':status' => $status,
            ':error' => $error,
        ]);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listDistinctVenuesFromScrapedEvents(): array {
        $sql = 'SELECT venue_name, COALESCE(venue_city, \'\') AS venue_city, COUNT(*) AS event_count
                FROM scraped_events
                GROUP BY venue_name, COALESCE(venue_city, \'\')
                ORDER BY event_count DESC, venue_name ASC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findCanonicalByKey(string $key): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM scraped_events WHERE canonical_event_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    private function findLegacyDuplicate(array $event): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM scraped_events WHERE event_date = :event_date AND venue_name = :venue_name AND bands = :bands LIMIT 1');
        $stmt->execute([
            ':event_date' => $event['event_date'],
            ':venue_name' => $event['venue_name'],
            ':bands' => $event['bands_json'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array{action:string,duplicate_merge:bool}
     */
    private function updateExistingCanonical(array $existing, array $incoming): array {
        $existingPriority = (int)($existing['source_priority'] ?? 0);
        $incomingPriority = (int)$incoming['source_priority'];
        $incomingWins = $incomingPriority > $existingPriority || ((string)($existing['source'] ?? '') === (string)$incoming['source']);

        $existingBands = json_decode((string)($existing['bands'] ?? '[]'), true);
        if (!is_array($existingBands)) {
            $existingBands = [];
        }
        $incomingBands = json_decode((string)$incoming['bands_json'], true);
        if (!is_array($incomingBands)) {
            $incomingBands = [];
        }
        $mergedBands = Normalizer::normalizeBandList(array_merge($existingBands, $incomingBands));
        $mergedBandsJson = json_encode($mergedBands, JSON_UNESCAPED_UNICODE);

        $targetEventDate = $incomingWins ? (string)$incoming['event_date'] : (string)($existing['event_date'] ?? '');
        $targetVenueName = $incomingWins ? (string)$incoming['venue_name'] : (string)($existing['venue_name'] ?? '');
        $bandsForUpdate = $mergedBandsJson;
        if ($this->hasTripleConflict((int)$existing['id'], $targetEventDate, $targetVenueName, $mergedBandsJson)) {
            $bandsForUpdate = (string)($existing['bands'] ?? $incoming['bands_json']);
        }

        $firstSeen = (string)($existing['first_seen_at'] ?? '') !== '' ? (string)$existing['first_seen_at'] : (string)$incoming['first_seen_at'];
        if (strtotime((string)$incoming['first_seen_at']) < strtotime($firstSeen)) {
            $firstSeen = (string)$incoming['first_seen_at'];
        }

        $update = [
            ':id' => (int)$existing['id'],
            ':bands' => $bandsForUpdate,
            ':is_sold_out' => max((int)($existing['is_sold_out'] ?? 0), (int)$incoming['is_sold_out']),
            ':is_ticketed' => max((int)($existing['is_ticketed'] ?? 0), (int)$incoming['is_ticketed']),
            ':first_seen_at' => $firstSeen,
            ':last_seen_at' => (string)$incoming['last_seen_at'],
            ':canonical_event_key' => (string)$incoming['canonical_event_key'],
            ':canonical_venue_slug' => (string)$incoming['canonical_venue_slug'],
            ':source_priority' => $incomingWins ? $incomingPriority : $existingPriority,
            ':source' => $incomingWins ? (string)$incoming['source'] : (string)($existing['source'] ?? ''),
            ':source_url' => $incomingWins ? (string)$incoming['source_url'] : (string)($existing['source_url'] ?? ''),
            ':source_event_id' => $incomingWins ? (string)$incoming['source_event_id'] : (string)($existing['source_event_id'] ?? ''),
            ':title' => $incomingWins ? (string)$incoming['title'] : (string)($existing['title'] ?? ''),
            ':subtitle' => $incomingWins ? (string)$incoming['subtitle'] : (string)($existing['subtitle'] ?? ''),
            ':event_date' => $targetEventDate,
            ':venue_name' => $targetVenueName,
            ':venue_city' => $incomingWins ? (string)$incoming['venue_city'] : (string)($existing['venue_city'] ?? ''),
            ':age_restriction' => $incomingWins ? (string)$incoming['age_restriction'] : (string)($existing['age_restriction'] ?? ''),
            ':price' => $incomingWins ? (string)$incoming['price'] : (string)($existing['price'] ?? ''),
            ':doors_time' => $incomingWins ? (string)$incoming['doors_time'] : (string)($existing['doors_time'] ?? ''),
            ':show_time' => $incomingWins ? (string)$incoming['show_time'] : (string)($existing['show_time'] ?? ''),
            ':notes' => $incomingWins ? (string)$incoming['notes'] : (string)($existing['notes'] ?? ''),
            ':raw_meta' => $incomingWins ? (string)$incoming['raw_meta'] : (string)($existing['raw_meta'] ?? ''),
            ':start_datetime' => $incomingWins ? $incoming['start_datetime'] : ($existing['start_datetime'] ?? null),
            ':doors_datetime' => $incomingWins ? $incoming['doors_datetime'] : ($existing['doors_datetime'] ?? null),
            ':ticket_url' => $incomingWins ? (string)$incoming['ticket_url'] : (string)($existing['ticket_url'] ?? ''),
            ':status' => $incomingWins ? (string)$incoming['status'] : (string)($existing['status'] ?? ''),
            ':raw_payload' => $incomingWins ? (string)$incoming['raw_payload'] : (string)($existing['raw_payload'] ?? ''),
            ':normalized_title' => $incomingWins ? (string)$incoming['normalized_title'] : (string)($existing['normalized_title'] ?? ''),
        ];

        $sql = "
            UPDATE scraped_events
            SET event_date = :event_date,
                venue_name = :venue_name,
                venue_city = :venue_city,
                bands = :bands,
                age_restriction = :age_restriction,
                price = :price,
                doors_time = :doors_time,
                show_time = :show_time,
                is_sold_out = :is_sold_out,
                is_ticketed = :is_ticketed,
                notes = :notes,
                raw_meta = :raw_meta,
                source_url = :source_url,
                source = :source,
                source_event_id = :source_event_id,
                title = :title,
                subtitle = :subtitle,
                start_datetime = :start_datetime,
                doors_datetime = :doors_datetime,
                ticket_url = :ticket_url,
                status = :status,
                first_seen_at = :first_seen_at,
                last_seen_at = :last_seen_at,
                source_priority = :source_priority,
                raw_payload = :raw_payload,
                normalized_title = :normalized_title,
                canonical_event_key = :canonical_event_key,
                canonical_venue_slug = :canonical_venue_slug,
                last_merged_at = CURRENT_TIMESTAMP,
                scraped_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($update);

        return ['action' => 'updated', 'duplicate_merge' => true];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function ingestionParams(array $event, ?int $venueSyncId): array {
        return [
            ':venue_sync_id' => $venueSyncId,
            ':venue_slug' => (string)$event['canonical_venue_slug'],
            ':venue_name' => (string)$event['venue_name'],
            ':merge_key' => (string)$event['merge_key'],
            ':ingest_fingerprint' => (string)$event['ingest_fingerprint'],
            ':source' => (string)$event['source'],
            ':source_url' => (string)$event['source_url'],
            ':source_event_id' => (string)$event['source_event_id'],
            ':title' => (string)$event['title'],
            ':subtitle' => (string)$event['subtitle'],
            ':start_datetime' => (string)$event['start_datetime'],
            ':doors_datetime' => $event['doors_datetime'],
            ':age_restriction' => (string)$event['age_restriction'],
            ':ticket_url' => (string)$event['ticket_url'],
            ':status' => (string)$event['status'],
            ':first_seen_at' => (string)$event['first_seen_at'],
            ':last_seen_at' => (string)$event['last_seen_at'],
            ':source_priority' => (int)$event['source_priority'],
            ':raw_payload' => (string)$event['raw_payload'],
            ':normalized_title' => (string)$event['normalized_title'],
            ':raw_meta' => (string)$event['raw_meta'],
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function canonicalParams(array $event): array {
        return [
            ':event_date' => (string)$event['event_date'],
            ':venue_name' => (string)$event['venue_name'],
            ':venue_city' => (string)$event['venue_city'],
            ':bands' => (string)$event['bands_json'],
            ':age_restriction' => (string)$event['age_restriction'],
            ':price' => (string)$event['price'],
            ':doors_time' => (string)$event['doors_time'],
            ':show_time' => (string)$event['show_time'],
            ':is_sold_out' => (int)$event['is_sold_out'],
            ':is_ticketed' => (int)$event['is_ticketed'],
            ':notes' => (string)$event['notes'],
            ':raw_meta' => (string)$event['raw_meta'],
            ':source_url' => (string)$event['source_url'],
            ':source' => (string)$event['source'],
            ':source_event_id' => (string)$event['source_event_id'],
            ':title' => (string)$event['title'],
            ':subtitle' => (string)$event['subtitle'],
            ':start_datetime' => $event['start_datetime'],
            ':doors_datetime' => $event['doors_datetime'],
            ':ticket_url' => (string)$event['ticket_url'],
            ':status' => (string)$event['status'],
            ':first_seen_at' => (string)$event['first_seen_at'],
            ':last_seen_at' => (string)$event['last_seen_at'],
            ':source_priority' => (int)$event['source_priority'],
            ':raw_payload' => (string)$event['raw_payload'],
            ':normalized_title' => (string)$event['normalized_title'],
            ':canonical_event_key' => (string)$event['canonical_event_key'],
            ':canonical_venue_slug' => (string)$event['canonical_venue_slug'],
        ];
    }

    private function isMysql(): bool {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }

    private function isDuplicateKeyException(PDOException $e): bool {
        $code = (string)$e->getCode();
        $message = strtolower($e->getMessage());

        if ($code === '23000') {
            return true;
        }

        return str_contains($message, 'duplicate') || str_contains($message, 'unique constraint');
    }

    private function hasTripleConflict(int $excludeId, string $eventDate, string $venueName, string $bandsJson): bool {
        if ($eventDate === '' || $venueName === '' || $bandsJson === '') {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM scraped_events
            WHERE event_date = :event_date
              AND venue_name = :venue_name
              AND bands = :bands
              AND id <> :exclude_id
            LIMIT 1
        ");
        $stmt->execute([
            ':event_date' => $eventDate,
            ':venue_name' => $venueName,
            ':bands' => $bandsJson,
            ':exclude_id' => $excludeId,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}
