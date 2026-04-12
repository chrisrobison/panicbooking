<?php
// Compute performer scores from scraped show history
// CLI: php compute_scores.php [--band="Name"]
// Web: disabled by default; set PB_ALLOW_WEB_MAINTENANCE=1 and PB_MAINTENANCE_TOKEN.

require_once __DIR__ . '/lib/security.php';
panicScriptGuard('compute_scores.php');

// =====================================================
// TOKEN AUTH (web mode)
// =====================================================
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// =====================================================
// VENUE TIER DEFINITIONS
// =====================================================
const VENUE_TIERS = [
    // Tier 5: Major (1500+ cap)
    'Bill Graham Civic Auditorium' => 5,
    'Chase Center'                 => 5,
    // Tier 4: Large (500-1500)
    'The Fillmore'                 => 4,
    'The Warfield'                 => 4,
    'Fox Theater'                  => 4,
    'Masonic'                      => 4,
    'Regency Ballroom'             => 4,
    // Tier 3: Mid (200-500)
    'Great American Music Hall'    => 3,
    'August Hall'                  => 3,
    'Independent'                  => 3,
    'Rickshaw Stop'                => 3,
    'Chapel'                       => 3,
    'DNA Lounge'                   => 3,
    // Tier 2: Small (100-200)
    'Bottom of the Hill'           => 2,
    'Neck of the Woods'            => 2,
    'Thee Parkside'                => 2,
    'Kilowatt'                     => 2,
    'Knockout'                     => 2,
    'Makeout Room'                 => 2,
    'Cafe Du Nord'                 => 2,
    'Black Cat'                    => 2,
    // Tier 1: DIY/Small (<100)
    'default'                      => 1,
];

// Estimated capacities by tier for draw estimation
const TIER_CAPACITY = [5 => 2500, 4 => 900, 3 => 350, 2 => 150, 1 => 60];
// Conservative fill rate: acts fill ~35% of a venue on average
const FILL_RATE = 0.35;

// =====================================================
// BOOTSTRAP DB
// =====================================================
// Ensure connection + schema bootstrap (sqlite/mysql) are available.
require_once __DIR__ . '/api/includes/db.php';

// =====================================================
// PARSE ARGS
// =====================================================
$singleBand = null;

if ($isCli) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--band=') === 0) {
            $singleBand = substr($arg, 7);
        }
    }
} else {
    if (!empty($_GET['band'])) {
        $singleBand = trim($_GET['band']);
    }
}

// =====================================================
// LOAD ALL SCRAPED EVENTS IN ONE PASS
// =====================================================
$output = function(string $line) use ($isCli) {
    echo $line . "\n";
    if (!$isCli) flush();
};

$output("Loading scraped events...");

$allEventsStmt = $pdo->query(
    "SELECT event_date, venue_name, bands, is_sold_out, is_ticketed FROM scraped_events ORDER BY event_date DESC"
);
$allEvents = $allEventsStmt->fetchAll();

$output("Loaded " . count($allEvents) . " scraped events.");

// Build an index: band_name -> [events...]
// Each event row: event_date, venue_name, is_sold_out, is_ticketed
$bandEvents = []; // band_name (string) => array of rows

foreach ($allEvents as $row) {
    $bandsRaw = $row['bands'];
    if (!$bandsRaw || $bandsRaw === '[]') continue;
    $bands = json_decode($bandsRaw, true);
    if (!is_array($bands)) continue;
    foreach ($bands as $bandName) {
        $bandName = trim($bandName);
        if ($bandName === '') continue;
        if (!isset($bandEvents[$bandName])) {
            $bandEvents[$bandName] = [];
        }
        $bandEvents[$bandName][] = [
            'event_date'  => $row['event_date'],
            'venue_name'  => $row['venue_name'],
            'is_sold_out' => (int)$row['is_sold_out'],
            'is_ticketed' => (int)$row['is_ticketed'],
        ];
    }
}

$output("Found " . count($bandEvents) . " unique band names.");

// If single band requested, restrict
if ($singleBand !== null) {
    if (!isset($bandEvents[$singleBand])) {
        $output("No events found for band: $singleBand");
        exit(0);
    }
    $bandEvents = [$singleBand => $bandEvents[$singleBand]];
    $output("Recomputing single band: $singleBand");
}

// =====================================================
// LOAD SHOW REPORTS (all at once for efficiency)
// =====================================================
$reportsByBand = [];
$reportsStmt = $pdo->query(
    "SELECT band_name, bar_impact, would_rebook FROM show_reports"
);
foreach ($reportsStmt->fetchAll() as $rep) {
    $bn = $rep['band_name'];
    if (!isset($reportsByBand[$bn])) {
        $reportsByBand[$bn] = [];
    }
    $reportsByBand[$bn][] = $rep;
}

// =====================================================
// HELPER: Get venue tier by name (partial match)
// =====================================================
function getVenueTier(string $venueName): int {
    foreach (VENUE_TIERS as $knownName => $tier) {
        if ($knownName === 'default') continue;
        if (stripos($venueName, $knownName) !== false) {
            return $tier;
        }
    }
    return VENUE_TIERS['default'];
}

// =====================================================
// PREPARE UPSERT STATEMENT
// =====================================================
$upsertBase = "
INSERT INTO performer_scores
  (band_name, draw_score, revenue_score, reliability_score, momentum_score,
   composite_score, avg_attendance, estimated_draw, shows_tracked,
   shows_last_30, shows_last_90, best_day, best_venue_tier, venue_tier_max,
   is_ticketed_ratio, sold_out_count, last_show_date,
   insight_draw, insight_revenue, insight_reliability, insight_momentum,
   last_computed)
VALUES
  (:band_name, :draw_score, :revenue_score, :reliability_score, :momentum_score,
   :composite_score, :avg_attendance, :estimated_draw, :shows_tracked,
   :shows_last_30, :shows_last_90, :best_day, :best_venue_tier, :venue_tier_max,
   :is_ticketed_ratio, :sold_out_count, :last_show_date,
   :insight_draw, :insight_revenue, :insight_reliability, :insight_momentum,
   CURRENT_TIMESTAMP)
";

if (panicDbIsMysql($pdo)) {
    $upsertSql = $upsertBase . "
ON DUPLICATE KEY UPDATE
  draw_score = VALUES(draw_score),
  revenue_score = VALUES(revenue_score),
  reliability_score = VALUES(reliability_score),
  momentum_score = VALUES(momentum_score),
  composite_score = VALUES(composite_score),
  avg_attendance = VALUES(avg_attendance),
  estimated_draw = VALUES(estimated_draw),
  shows_tracked = VALUES(shows_tracked),
  shows_last_30 = VALUES(shows_last_30),
  shows_last_90 = VALUES(shows_last_90),
  best_day = VALUES(best_day),
  best_venue_tier = VALUES(best_venue_tier),
  venue_tier_max = VALUES(venue_tier_max),
  is_ticketed_ratio = VALUES(is_ticketed_ratio),
  sold_out_count = VALUES(sold_out_count),
  last_show_date = VALUES(last_show_date),
  insight_draw = VALUES(insight_draw),
  insight_revenue = VALUES(insight_revenue),
  insight_reliability = VALUES(insight_reliability),
  insight_momentum = VALUES(insight_momentum),
  last_computed = CURRENT_TIMESTAMP
";
} else {
    $upsertSql = $upsertBase . "
ON CONFLICT(band_name) DO UPDATE SET
  draw_score = excluded.draw_score,
  revenue_score = excluded.revenue_score,
  reliability_score = excluded.reliability_score,
  momentum_score = excluded.momentum_score,
  composite_score = excluded.composite_score,
  avg_attendance = excluded.avg_attendance,
  estimated_draw = excluded.estimated_draw,
  shows_tracked = excluded.shows_tracked,
  shows_last_30 = excluded.shows_last_30,
  shows_last_90 = excluded.shows_last_90,
  best_day = excluded.best_day,
  best_venue_tier = excluded.best_venue_tier,
  venue_tier_max = excluded.venue_tier_max,
  is_ticketed_ratio = excluded.is_ticketed_ratio,
  sold_out_count = excluded.sold_out_count,
  last_show_date = excluded.last_show_date,
  insight_draw = excluded.insight_draw,
  insight_revenue = excluded.insight_revenue,
  insight_reliability = excluded.insight_reliability,
  insight_momentum = excluded.insight_momentum,
  last_computed = CURRENT_TIMESTAMP
";
}
$upsertStmt = $pdo->prepare($upsertSql);

// =====================================================
// COMPUTE SCORES FOR EACH BAND
// =====================================================
$today      = date('Y-m-d');
$todayTs    = strtotime($today);
$processed  = 0;
$skipped    = 0;
$total      = count($bandEvents);

foreach ($bandEvents as $bandName => $events) {
    $showsTracked = count($events);
    if ($showsTracked === 0) {
        $skipped++;
        continue;
    }

    // Sort events by date desc (already sorted from query, but make sure)
    usort($events, fn($a, $b) => strcmp($b['event_date'], $a['event_date']));

    $lastShowDate = $events[0]['event_date'];
    $firstShowDate = $events[$showsTracked - 1]['event_date'];

    // Show counts by recency
    $showsLast30 = 0;
    $showsLast90 = 0;
    foreach ($events as $ev) {
        $daysAgo = ($todayTs - strtotime($ev['event_date'])) / 86400;
        if ($daysAgo <= 30)  $showsLast30++;
        if ($daysAgo <= 90)  $showsLast90++;
    }

    // Venue tier stats, draw estimation, ticketing, sold-out
    $venueTierMax     = 0;
    $bestVenueTier    = '';
    $soldOutCount     = 0;
    $ticketedCount    = 0;
    $drawWeightedSum  = 0.0;
    $drawWeightTotal  = 0.0;
    $rawAttendances   = [];
    $dowCounts        = []; // 'Monday' => N, etc.

    foreach ($events as $ev) {
        $daysAgo = max(0, ($todayTs - strtotime($ev['event_date'])) / 86400);
        $tier    = getVenueTier($ev['venue_name']);

        // Tier tracking
        if ($tier > $venueTierMax) {
            $venueTierMax  = $tier;
            $bestVenueTier = $ev['venue_name'];
        }

        // Sold out / ticketed
        if ($ev['is_sold_out']) $soldOutCount++;
        if ($ev['is_ticketed']) $ticketedCount++;

        // Estimated attendance for this show
        $cap = TIER_CAPACITY[$tier];
        $est = $cap * FILL_RATE;
        if ($ev['is_sold_out']) {
            $est *= 1.5;
        }
        $rawAttendances[] = $est;

        // Weighted draw (more recent = higher weight)
        $weight = 1.0 / (1.0 + $daysAgo / 90.0);
        $drawWeightedSum  += $est * $weight;
        $drawWeightTotal  += $weight;

        // Day-of-week
        $dow = date('l', strtotime($ev['event_date'])); // e.g. 'Monday'
        $dowCounts[$dow] = ($dowCounts[$dow] ?? 0) + 1;
    }

    // Computed fields
    $avgAttendance  = $showsTracked > 0 ? (int)round(array_sum($rawAttendances) / $showsTracked) : 0;
    $estimatedDraw  = $drawWeightTotal > 0 ? (int)round($drawWeightedSum / $drawWeightTotal) : 0;
    $isTicketedRatio = $showsTracked > 0 ? round($ticketedCount / $showsTracked, 4) : 0.0;

    // Best day
    $bestDay = '';
    if (!empty($dowCounts)) {
        arsort($dowCounts);
        $bestDay = array_key_first($dowCounts);
    }

    // ---- DRAW SCORE ----
    $drawScore = min(100, ($venueTierMax * 15) + ($soldOutCount * 5) + ($showsTracked * 2));

    // ---- REVENUE SCORE ----
    $revenueScore = ($venueTierMax * 10) + ($isTicketedRatio * 30) + ($soldOutCount * 8);
    // Factor in show_reports bar_impact if available
    $reports = $reportsByBand[$bandName] ?? [];
    if (!empty($reports)) {
        $barMap = ['high' => 30, 'medium' => 20, 'low' => 5, 'none' => 0, '' => 0];
        $barTotal = 0;
        $barCount = 0;
        foreach ($reports as $rep) {
            $impact = $rep['bar_impact'] ?? '';
            $barTotal += $barMap[$impact] ?? 0;
            $barCount++;
        }
        if ($barCount > 0) {
            $avgBarScore = $barTotal / $barCount;
            // Blend: 70% computed, 30% bar impact score
            $revenueScore = ($revenueScore * 0.7) + ($avgBarScore * 0.3);
        }
    }
    $revenueScore = min(100, max(0, round($revenueScore, 2)));

    // ---- RELIABILITY SCORE ----
    $reliabilityScore = 50;
    $daysSinceLast = ($todayTs - strtotime($lastShowDate)) / 86400;
    if ($daysSinceLast <= 60)  $reliabilityScore += 20; // active
    if ($showsTracked >= 5)    $reliabilityScore += 10;
    if ($showsTracked >= 10)   $reliabilityScore += 10;
    if ($daysSinceLast <= 30)  $reliabilityScore += 10;
    if ($daysSinceLast > 180)  $reliabilityScore -= 20;
    // Factor in would_rebook from show_reports
    if (!empty($reports)) {
        $rebookTotal = 0;
        $rebookCount = 0;
        foreach ($reports as $rep) {
            if (isset($rep['would_rebook'])) {
                $rebookTotal += (int)$rep['would_rebook'];
                $rebookCount++;
            }
        }
        if ($rebookCount > 0) {
            $rebookAvg = $rebookTotal / $rebookCount; // 0-1
            // Adjust reliability by up to ±15 based on rebook sentiment
            $reliabilityScore += (int)round(($rebookAvg - 0.5) * 30);
        }
    }
    $reliabilityScore = min(100, max(0, $reliabilityScore));

    // ---- MOMENTUM SCORE ----
    $firstTs = strtotime($firstShowDate);
    $monthsSinceFirst = max(1, ($todayTs - $firstTs) / (30 * 86400));
    $historicalMonthlyAvg = ($showsTracked - $showsLast30) / max(1, $monthsSinceFirst - 1);
    $momentumScore = min(100, max(0, 50 + ($showsLast30 - $historicalMonthlyAvg) * 20));
    $momentumScore = round($momentumScore, 2);

    // ---- COMPOSITE SCORE ----
    $compositeScore = round(
        ($drawScore * 0.4) + ($revenueScore * 0.25) + ($reliabilityScore * 0.2) + ($momentumScore * 0.15),
        2
    );

    // ---- INSIGHT STRINGS ----
    // Draw insight
    if ($estimatedDraw > 0) {
        $insightDraw = "Draws an estimated {$estimatedDraw} people per show";
    } elseif ($venueTierMax >= 4) {
        $insightDraw = "Has played {$bestVenueTier} — draws major venue crowds";
    } elseif ($soldOutCount > 0) {
        $insightDraw = "Sold out {$soldOutCount} show(s) in our records";
    } else {
        $insightDraw = "Emerging act — {$showsTracked} show(s) tracked";
    }

    // Revenue insight
    if ($isTicketedRatio >= 0.8) {
        $insightRevenue = "Consistently plays ticketed shows — audience pays to see them";
    } elseif ($isTicketedRatio >= 0.4) {
        $insightRevenue = "Mix of ticketed and free shows";
    } else {
        $insightRevenue = "Primarily free/DIY shows — lower cover risk for venues";
    }

    // Reliability insight
    if ($showsLast30 >= 2) {
        $insightReliability = "Active right now — {$showsLast30} show(s) in the last 30 days";
    } elseif ($showsLast90 >= 3) {
        $insightReliability = "Consistent — {$showsLast90} shows in the last 90 days";
    } elseif ($lastShowDate) {
        $insightReliability = "Last seen live: {$lastShowDate}";
    } else {
        $insightReliability = "No recent show history";
    }

    // Momentum insight
    if ($momentumScore >= 70) {
        $insightMomentum = "On the rise — more active recently than their average";
    } elseif ($momentumScore >= 50) {
        $insightMomentum = "Steady pace";
    } else {
        $insightMomentum = "Below their usual activity — may be between cycles";
    }

    // ---- UPSERT ----
    $upsertStmt->execute([
        ':band_name'         => $bandName,
        ':draw_score'        => $drawScore,
        ':revenue_score'     => $revenueScore,
        ':reliability_score' => $reliabilityScore,
        ':momentum_score'    => $momentumScore,
        ':composite_score'   => $compositeScore,
        ':avg_attendance'    => $avgAttendance,
        ':estimated_draw'    => $estimatedDraw,
        ':shows_tracked'     => $showsTracked,
        ':shows_last_30'     => $showsLast30,
        ':shows_last_90'     => $showsLast90,
        ':best_day'          => $bestDay,
        ':best_venue_tier'   => $bestVenueTier,
        ':venue_tier_max'    => $venueTierMax,
        ':is_ticketed_ratio' => $isTicketedRatio,
        ':sold_out_count'    => $soldOutCount,
        ':last_show_date'    => $lastShowDate,
        ':insight_draw'      => $insightDraw,
        ':insight_revenue'   => $insightRevenue,
        ':insight_reliability' => $insightReliability,
        ':insight_momentum'  => $insightMomentum,
    ]);

    $processed++;

    if ($processed % 50 === 0) {
        $output("  Processed {$processed}/{$total} bands...");
    }
}

$output("Done. Processed: {$processed}, Skipped (no shows): {$skipped}, Total unique bands: {$total}.");
