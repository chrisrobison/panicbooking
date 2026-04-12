<?php

namespace PanicBooking\EventSync;

use PDO;

class VenueScorer {
    private PDO $pdo;
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $pdo, array $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * @return array{scored:int,tiers:array<string,int>}
     */
    public function compute(bool $dryRun = false): array {
        $rows = $this->pdo->query('SELECT * FROM event_sync_venues')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tiers = [
            'Tier 1' => 0,
            'Tier 2' => 0,
            'Tier 3' => 0,
            'Tier 4' => 0,
        ];

        foreach ($rows as $venue) {
            $slug = (string)$venue['slug'];
            if ($slug === '') {
                continue;
            }

            $metrics = $this->loadActivityMetrics($slug);
            $score = $this->scoreVenue($venue, $metrics);
            $tier = $this->tierForScore($score);
            $tiers[$tier]++;

            if ($dryRun) {
                continue;
            }

            $stmt = $this->pdo->prepare("UPDATE event_sync_venues
                SET venue_score = :score,
                    venue_tier = :tier,
                    coverage_confidence = :coverage_confidence,
                    last_scored_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE slug = :slug");

            $stmt->execute([
                ':score' => $score,
                ':tier' => $tier,
                ':coverage_confidence' => $metrics['coverage_confidence'],
                ':slug' => $slug,
            ]);
        }

        return [
            'scored' => count($rows),
            'tiers' => $tiers,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function loadActivityMetrics(string $slug): array {
        $upcoming30 = $this->countDistinctEventDays($slug, 30);
        $upcoming60 = $this->countDistinctEventDays($slug, 60);
        $recent90 = $this->countDistinctEventDaysLookback($slug, 90);
        $activeWeeks = $this->countActiveWeeksLookback($slug, 180);
        $sourceCount = $this->countDistinctSourcesLookback($slug, 120);

        $consistency = min(1.0, $activeWeeks / 12.0);
        $coverage = min(1.0, 0.2 + ($sourceCount * 0.15) + min(0.4, $recent90 / 30.0));

        return [
            'upcoming_30' => (float)$upcoming30,
            'upcoming_60' => (float)$upcoming60,
            'recent_90' => (float)$recent90,
            'active_weeks' => (float)$activeWeeks,
            'consistency' => $consistency,
            'coverage_confidence' => $coverage,
        ];
    }

    /**
     * @param array<string,mixed> $venue
     * @param array<string,float> $metrics
     */
    private function scoreVenue(array $venue, array $metrics): float {
        $cfg = (array)($this->config['scoring'] ?? []);

        $prestigeWeight = (float)($venue['prestige_weight'] ?? 0.5);
        $activityWeight = max(0.2, (float)($venue['activity_weight'] ?? 0.7));
        $capacity = (int)($venue['capacity_estimate'] ?? 0);
        $isCore = (int)($venue['is_core_venue'] ?? 0) === 1;
        $hasOfficial = (int)($venue['has_official_sync'] ?? 0) === 1;
        $notorietyMultiplier = max(0.8, (float)($venue['notoriety_multiplier'] ?? 1.0));

        $prestigeComponent = $prestigeWeight * (float)($cfg['prestige_multiplier'] ?? 35.0);
        $capacityComponent = $capacity > 0
            ? min(25.0, log($capacity + 1, 2) * (float)($cfg['capacity_log_multiplier'] ?? 6.0))
            : 0.0;

        $activity30 = $metrics['upcoming_30'] * (float)($cfg['upcoming_30_multiplier'] ?? 2.5);
        $activityTail = max(0.0, ($metrics['upcoming_60'] - $metrics['upcoming_30']))
            * (float)($cfg['upcoming_60_multiplier'] ?? 1.2);
        $activityComponent = min(30.0, ($activity30 + $activityTail) * $activityWeight);

        $consistencyComponent = $metrics['consistency'] * (float)($cfg['consistency_multiplier'] ?? 15.0) * $activityWeight;

        $bonus = 0.0;
        if ($isCore) {
            $bonus += (float)($cfg['core_venue_bonus'] ?? 12.0);
        }
        if ($hasOfficial) {
            $bonus += (float)($cfg['official_sync_bonus'] ?? 10.0);
        }

        $score = ($prestigeComponent + $capacityComponent + $activityComponent + $consistencyComponent + $bonus) * $notorietyMultiplier;

        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function tierForScore(float $score): string {
        $thresholds = (array)(($this->config['scoring'] ?? [])['tier_thresholds'] ?? []);

        if ($score >= (float)($thresholds['Tier 1'] ?? 85.0)) {
            return 'Tier 1';
        }
        if ($score >= (float)($thresholds['Tier 2'] ?? 65.0)) {
            return 'Tier 2';
        }
        if ($score >= (float)($thresholds['Tier 3'] ?? 45.0)) {
            return 'Tier 3';
        }

        return 'Tier 4';
    }

    private function countDistinctEventDays(string $slug, int $days): int {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT event_date)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= CURDATE()
                  AND event_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)");
            $stmt->execute([
                ':slug' => $slug,
                ':days' => $days,
            ]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT event_date)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE('now')
                  AND event_date <= DATE('now', :window)");
            $stmt->execute([
                ':slug' => $slug,
                ':window' => '+' . $days . ' day',
            ]);
        }
        return (int)$stmt->fetchColumn();
    }

    private function countDistinctEventDaysLookback(string $slug, int $days): int {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT event_date)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                  AND event_date <= CURDATE()");
            $stmt->execute([
                ':slug' => $slug,
                ':days' => $days,
            ]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT event_date)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE('now', :window)
                  AND event_date <= DATE('now')");
            $stmt->execute([
                ':slug' => $slug,
                ':window' => '-' . $days . ' day',
            ]);
        }
        return (int)$stmt->fetchColumn();
    }

    private function countActiveWeeksLookback(string $slug, int $days): int {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM (
                SELECT YEARWEEK(event_date, 3) AS yweek
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                  AND event_date <= CURDATE()
                GROUP BY YEARWEEK(event_date, 3)
            ) t");
            $stmt->execute([':slug' => $slug, ':days' => $days]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM (
            SELECT strftime('%Y-%W', event_date) AS yweek
            FROM scraped_events
            WHERE canonical_venue_slug = :slug
              AND event_date >= DATE('now', :window)
              AND event_date <= DATE('now')
            GROUP BY strftime('%Y-%W', event_date)
        ) t");
        $stmt->execute([
            ':slug' => $slug,
            ':window' => '-' . $days . ' day',
        ]);
        return (int)$stmt->fetchColumn();
    }

    private function countDistinctSourcesLookback(string $slug, int $days): int {
        if (strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT source)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                  AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute([
                ':slug' => $slug,
                ':days' => $days,
            ]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT source)
                FROM scraped_events
                WHERE canonical_venue_slug = :slug
                  AND event_date >= DATE('now', :window)
                  AND event_date <= DATE('now', '+30 day')");
            $stmt->execute([
                ':slug' => $slug,
                ':window' => '-' . $days . ' day',
            ]);
        }
        return (int)$stmt->fetchColumn();
    }
}
