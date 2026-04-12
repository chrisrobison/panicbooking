<?php
// Performer scores API handlers

/**
 * GET /api/scores
 * Public endpoint. Returns a paginated list of performer scores.
 * Query params: q, min_draw, min_composite, limit (default 20, max 100), offset
 */
function handleScoresList(PDO $pdo): void {
    $q            = trim($_GET['q'] ?? '');
    $minDraw      = isset($_GET['min_draw'])      ? (int)$_GET['min_draw']      : null;
    $minComposite = isset($_GET['min_composite']) ? (int)$_GET['min_composite'] : null;
    $limit        = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset       = max(0, (int)($_GET['offset'] ?? 0));

    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[]        = 'band_name LIKE :q';
        $params[':q']   = '%' . $q . '%';
    }
    if ($minDraw !== null) {
        $where[]              = 'draw_score >= :min_draw';
        $params[':min_draw']  = $minDraw;
    }
    if ($minComposite !== null) {
        $where[]                   = 'composite_score >= :min_composite';
        $params[':min_composite']  = $minComposite;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM performer_scores {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch rows
    $params[':limit']  = $limit;
    $params[':offset'] = $offset;
    $stmt = $pdo->prepare(
        "SELECT * FROM performer_scores {$whereClause}
         ORDER BY composite_score DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->execute($params);
    $scores = $stmt->fetchAll();

    jsonResponse(['scores' => $scores, 'total' => $total]);
}

/**
 * GET /api/scores/{bandName}
 * Public endpoint. Returns scores for a single band by name.
 */
function handleScoresGet(PDO $pdo, string $bandName): void {
    $stmt = $pdo->prepare("SELECT * FROM performer_scores WHERE band_name = :band_name LIMIT 1");
    $stmt->execute([':band_name' => $bandName]);
    $score = $stmt->fetch();

    if (!$score) {
        errorResponse('Score not found', 404);
        return;
    }

    jsonResponse(['score' => $score]);
}

/**
 * POST /api/scores/report
 * Requires auth. Allows venue/promoter users to submit a post-show report.
 * Body: band_name, event_date, venue_name, reported_attendance,
 *       bar_impact, cover_collected, would_rebook, notes
 */
function handleShowReportCreate(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = apiReadJsonBody();

    if (!$user['is_admin'] && ($user['type'] ?? '') !== 'venue') {
        errorResponse('Only venue users can submit show reports', 403);
    }

    $bandName = trim($body['band_name'] ?? '');
    $eventDate = trim($body['event_date'] ?? '');

    if ($bandName === '') {
        errorResponse('band_name is required', 422);
        return;
    }
    $eventDate = apiRequireDateYmd($eventDate, 'event_date');

    $venueName          = trim($body['venue_name'] ?? '');
    $reportedAttendance = isset($body['reported_attendance']) ? (int)$body['reported_attendance'] : null;
    $barImpact          = trim($body['bar_impact'] ?? '');
    $coverCollected     = isset($body['cover_collected']) ? (int)$body['cover_collected'] : 0;
    $wouldRebook        = isset($body['would_rebook']) ? (int)(bool)$body['would_rebook'] : 1;
    $notes              = trim($body['notes'] ?? '');
    if ($reportedAttendance !== null && ($reportedAttendance < 0 || $reportedAttendance > 1000000)) {
        errorResponse('reported_attendance is out of range', 422);
    }

    // Validate bar_impact value
    $validBarImpacts = ['', 'high', 'medium', 'low', 'none'];
    if (!in_array($barImpact, $validBarImpacts, true)) {
        $barImpact = '';
    }

    $stmt = $pdo->prepare("
        INSERT INTO show_reports
          (reporter_user_id, band_name, event_date, venue_name,
           reported_attendance, bar_impact, cover_collected, would_rebook, notes)
        VALUES
          (:reporter_user_id, :band_name, :event_date, :venue_name,
           :reported_attendance, :bar_impact, :cover_collected, :would_rebook, :notes)
    ");

    $stmt->execute([
        ':reporter_user_id'   => $user['id'],
        ':band_name'          => $bandName,
        ':event_date'         => $eventDate,
        ':venue_name'         => $venueName,
        ':reported_attendance'=> $reportedAttendance,
        ':bar_impact'         => $barImpact,
        ':cover_collected'    => $coverCollected,
        ':would_rebook'       => $wouldRebook,
        ':notes'              => $notes,
    ]);

    jsonResponse(['success' => true]);
}
