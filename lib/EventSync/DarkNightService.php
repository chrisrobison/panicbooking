<?php

namespace PanicBooking\EventSync;

use PDO;

class DarkNightService {
    private PDO $pdo;
    private string $timezone;

    public function __construct(PDO $pdo, string $timezone = 'America/Los_Angeles') {
        $this->pdo = $pdo;
        $this->timezone = $timezone;
    }

    /**
     * @return array{venues:int,dark_nights:int,high_confidence:int,medium_confidence:int,low_confidence:int}
     */
    public function compute(int $days = 60, ?string $dateFrom = null, bool $dryRun = false): array {
        $dateFrom = $dateFrom ?: (new \DateTime('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
        $start = new \DateTimeImmutable($dateFrom . ' 00:00:00', new \DateTimeZone($this->timezone));
        $end = $start->modify('+' . max(1, $days - 1) . ' day');

        $venues = $this->pdo->query('SELECT * FROM event_sync_venues WHERE sync_enabled = 1 ORDER BY display_name ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = [
            'venues' => count($venues),
            'dark_nights' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
        ];

        if (!$dryRun) {
            $deleteStmt = $this->pdo->prepare('DELETE FROM venue_dark_nights WHERE dark_date >= :from_date AND dark_date <= :to_date');
            $deleteStmt->execute([
                ':from_date' => $start->format('Y-m-d'),
                ':to_date' => $end->format('Y-m-d'),
            ]);
        }

        foreach ($venues as $venue) {
            $slug = (string)$venue['slug'];
            if ($slug === '') {
                continue;
            }

            $bookedDates = $this->loadBookedDates($slug, $start->format('Y-m-d'), $end->format('Y-m-d'));
            $cadence = $this->loadCadence($slug, 180);
            $coverageBase = $this->coverageBase($venue, $slug);

            $cursor = $start;
            while ($cursor <= $end) {
                $day = $cursor->format('Y-m-d');
                if (isset($bookedDates[$day])) {
                    $cursor = $cursor->modify('+1 day');
                    continue;
                }

                $dow = (int)$cursor->format('w');
                $cadenceWeight = $cadence[$dow] ?? 0.0;

                $score = min(0.95, max(0.20, $coverageBase + ($cadenceWeight * 0.5)));
                $level = 'low';
                if ($score >= 0.75) {
                    $level = 'high';
                } elseif ($score >= 0.50) {
                    $level = 'medium';
                }

                $reason = 'No known event for this date';
                if ($cadenceWeight >= 0.18) {
                    $reason = 'No known event on a historically active night';
                }

                $stats['dark_nights']++;
                $stats[$level . '_confidence']++;

                if (!$dryRun) {
                    $this->upsertDarkNight($slug, (int)$venue['id'], $day, $level, $score, $reason);
                }

                $cursor = $cursor->modify('+1 day');
            }
        }

        return $stats;
    }

    /**
     * @return array<string,bool>
     */
    private function loadBookedDates(string $slug, string $fromDate, string $toDate): array {
        $stmt = $this->pdo->prepare("SELECT event_date
            FROM scraped_events
            WHERE canonical_venue_slug = :slug
              AND event_date >= :from_date
              AND event_date <= :to_date
            GROUP BY event_date");
        $stmt->execute([
            ':slug' => $slug,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ]);

        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
            $set[(string)$date] = true;
        }

        return $set;
    }

    /**
     * @return array<int,float>
     */
    private function loadCadence(string $slug, int $lookbackDays): array {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT DAYOFWEEK(event_date) AS dow, COUNT(*) AS c
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                  AND event_date <= CURDATE()
                GROUP BY DAYOFWEEK(event_date)");
            $stmt->execute([':slug' => $slug, ':days' => $lookbackDays]);
        } else {
            $stmt = $this->pdo->prepare("SELECT CAST(strftime('%w', event_date) AS INTEGER) AS dow, COUNT(*) AS c
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE('now', :window)
                  AND event_date <= DATE('now')
                GROUP BY strftime('%w', event_date)");
            $stmt->execute([
                ':slug' => $slug,
                ':window' => '-' . $lookbackDays . ' day',
            ]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = 0;
        $counts = array_fill(0, 7, 0.0);

        foreach ($rows as $row) {
            $dow = (int)$row['dow'];
            if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
                $dow = ($dow + 6) % 7;
            }
            if ($dow < 0 || $dow > 6) {
                continue;
            }

            $count = (float)$row['c'];
            $counts[$dow] += $count;
            $total += $count;
        }

        if ($total <= 0) {
            return array_fill(0, 7, 0.0);
        }

        $weights = [];
        for ($i = 0; $i < 7; $i++) {
            $weights[$i] = $counts[$i] / $total;
        }

        return $weights;
    }

    private function coverageBase(array $venue, string $slug): float {
        $hasOfficial = ((int)($venue['has_official_sync'] ?? 0) === 1);
        $base = $hasOfficial ? 0.55 : 0.35;

        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT source) FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
                  AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute([':slug' => $slug]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT source) FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE('now', '-120 day')
                  AND event_date <= DATE('now', '+30 day')");
            $stmt->execute([':slug' => $slug]);
        }

        $sourceCount = (int)$stmt->fetchColumn();
        $base += min(0.25, $sourceCount * 0.08);

        return min(0.85, $base);
    }

    private function upsertDarkNight(string $slug, int $venueSyncId, string $date, string $level, float $score, string $reason): void {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $sql = "
                INSERT INTO venue_dark_nights
                  (venue_sync_id, venue_slug, dark_date, confidence_level, confidence_score, reason, is_likely_open, computed_at)
                VALUES
                  (:venue_sync_id, :venue_slug, :dark_date, :confidence_level, :confidence_score, :reason, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                  confidence_level = VALUES(confidence_level),
                  confidence_score = VALUES(confidence_score),
                  reason = VALUES(reason),
                  is_likely_open = VALUES(is_likely_open),
                  computed_at = CURRENT_TIMESTAMP
            ";
        } else {
            $sql = "
                INSERT INTO venue_dark_nights
                  (venue_sync_id, venue_slug, dark_date, confidence_level, confidence_score, reason, is_likely_open, computed_at)
                VALUES
                  (:venue_sync_id, :venue_slug, :dark_date, :confidence_level, :confidence_score, :reason, 1, CURRENT_TIMESTAMP)
                ON CONFLICT(venue_slug, dark_date) DO UPDATE SET
                  confidence_level = excluded.confidence_level,
                  confidence_score = excluded.confidence_score,
                  reason = excluded.reason,
                  is_likely_open = excluded.is_likely_open,
                  computed_at = CURRENT_TIMESTAMP
            ";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':venue_sync_id' => $venueSyncId,
            ':venue_slug' => $slug,
            ':dark_date' => $date,
            ':confidence_level' => $level,
            ':confidence_score' => round($score, 4),
            ':reason' => $reason,
        ]);
    }
}
