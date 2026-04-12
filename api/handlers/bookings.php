<?php
// Booking workflow + legacy booking interest handlers

require_once __DIR__ . '/../../lib/booking_workflow.php';

function bookingApiReadBody(): array {
    if (isset($GLOBALS['API_PARSED_JSON_BODY']) && is_array($GLOBALS['API_PARSED_JSON_BODY'])) {
        return $GLOBALS['API_PARSED_JSON_BODY'];
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bookingApiErrorStatus(Throwable $e): int {
    if ($e instanceof InvalidArgumentException) {
        return 422;
    }

    $message = strtolower($e->getMessage());
    if (str_contains($message, 'forbidden')) {
        return 403;
    }
    if (str_contains($message, 'not found')) {
        return 404;
    }

    return 400;
}

/**
 * POST /api/bookings/interest — LEGACY PUBLIC endpoint
 */
function handleBookingInterestCreate(PDO $pdo): void {
    $body = bookingApiReadBody();

    $venue_name      = trim($body['venue_name']      ?? '');
    $venue_city      = trim($body['venue_city']      ?? 'S.F.');
    $event_date      = trim($body['event_date']      ?? '');
    $requester_type  = trim($body['requester_type']  ?? 'band');
    $requester_name  = trim($body['requester_name']  ?? '');
    $requester_email = trim($body['requester_email'] ?? '');
    $message         = trim($body['message']         ?? '');
    $band_profile_id = isset($body['band_profile_id']) ? (int)$body['band_profile_id'] : null;

    $errors = [];
    if ($venue_name === '') {
        $errors[] = 'venue_name is required';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) {
        $errors[] = 'event_date must be in YYYY-MM-DD format';
    }
    if ($requester_name === '') {
        $errors[] = 'requester_name is required';
    }
    if ($requester_email === '') {
        $errors[] = 'requester_email is required';
    } elseif (!filter_var($requester_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'requester_email is not a valid email address';
    }

    if (!empty($errors)) {
        errorResponse(implode('; ', $errors), 422);
        return;
    }

    if (!in_array($requester_type, ['band', 'promoter', 'other'], true)) {
        $requester_type = 'band';
    }

    $stmt = $pdo->prepare("\n        INSERT INTO booking_interests\n            (venue_name, venue_city, event_date, requester_type, requester_name, requester_email, message, band_profile_id)\n        VALUES\n            (:venue_name, :venue_city, :event_date, :requester_type, :requester_name, :requester_email, :message, :band_profile_id)\n    ");
    $stmt->execute([
        ':venue_name'      => $venue_name,
        ':venue_city'      => $venue_city,
        ':event_date'      => $event_date,
        ':requester_type'  => $requester_type,
        ':requester_name'  => $requester_name,
        ':requester_email' => $requester_email,
        ':message'         => $message,
        ':band_profile_id' => $band_profile_id,
    ]);

    $id = (int)$pdo->lastInsertId();
    jsonResponse(['success' => true, 'id' => $id]);
}

/**
 * GET /api/bookings/interests — LEGACY requires auth
 */
function handleBookingInterestsList(PDO $pdo): void {
    apiRequireAuth();

    $venue_name = trim($_GET['venue_name'] ?? '');
    $date_from  = trim($_GET['date_from']  ?? '');
    $date_to    = trim($_GET['date_to']    ?? '');
    $status     = trim($_GET['status']     ?? '');
    $limit      = max(1, min(200, (int)($_GET['limit']  ?? 50)));
    $offset     = max(0, (int)($_GET['offset'] ?? 0));

    $where  = [];
    $params = [];

    if ($venue_name !== '') {
        $where[]               = 'venue_name LIKE :venue_name';
        $params[':venue_name'] = '%' . $venue_name . '%';
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[]              = 'event_date >= :date_from';
        $params[':date_from'] = $date_from;
    }
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[]            = 'event_date <= :date_to';
        $params[':date_to'] = $date_to;
    }
    if (in_array($status, ['new', 'seen', 'responded'], true)) {
        $where[]          = 'status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql  = "SELECT * FROM booking_interests {$whereSQL} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $interests = array_map(function($row) {
        $row['id'] = (int)$row['id'];
        if ($row['band_profile_id'] !== null) {
            $row['band_profile_id'] = (int)$row['band_profile_id'];
        }
        return $row;
    }, $rows);

    jsonResponse(['interests' => $interests, 'limit' => $limit, 'offset' => $offset]);
}

/**
 * GET /api/bookings/opportunities
 */
function handleBookingOpportunityList(PDO $pdo): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $mineOnly = isset($_GET['mine']) && $_GET['mine'] === '1';
    $openOnly = isset($_GET['open']) && $_GET['open'] === '1';
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $opportunities = bookingWorkflowListOpportunities($pdo, $current, [
        'mine_only' => $mineOnly,
        'open_only' => $openOnly,
        'limit' => $limit,
        'offset' => $offset,
        'q' => trim((string)($_GET['q'] ?? '')),
        'include_band_context' => (($current['type'] ?? '') === 'band'),
    ]);

    jsonResponse([
        'opportunities' => $opportunities,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

/**
 * POST /api/bookings/opportunities
 */
function handleBookingOpportunityCreate(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = bookingApiReadBody();

    try {
        $newId = bookingWorkflowCreateOpportunity($pdo, $current, $body);
        $opportunity = bookingWorkflowGetOpportunity($pdo, $newId);
        jsonResponse([
            'success' => true,
            'opportunity' => $opportunity,
        ], 201);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * GET /api/bookings/opportunities/{id}
 */
function handleBookingOpportunityGet(PDO $pdo, int $id): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $opportunity = bookingWorkflowGetOpportunity($pdo, $id);
    if (!$opportunity) {
        errorResponse('Opportunity not found', 404);
    }

    $canManage = bookingWorkflowCanManageOpportunity($current, $opportunity);
    if (!$canManage && ($opportunity['status'] ?? '') !== 'open') {
        errorResponse('Forbidden', 403);
    }

    if (($current['type'] ?? '') === 'band') {
        $myBookings = bookingWorkflowListBookings($pdo, $current, [
            'opportunity_id' => $id,
            'limit' => 20,
        ]);
        if (!empty($myBookings)) {
            $opportunity['my_booking_id'] = (int)$myBookings[0]['id'];
            $opportunity['my_booking_status'] = (string)$myBookings[0]['status'];
        }
    }

    jsonResponse(['opportunity' => $opportunity]);
}

/**
 * POST /api/bookings/opportunities/{id}/status
 */
function handleBookingOpportunityStatusUpdate(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = bookingApiReadBody();

    try {
        $updated = bookingWorkflowUpdateOpportunityStatus($pdo, $current, $id, (string)($body['status'] ?? ''));
        jsonResponse([
            'success' => true,
            'opportunity' => $updated,
        ]);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * POST /api/bookings/opportunities/{id}/inquiries
 */
function handleBookingInquiryCreate(PDO $pdo, int $opportunityId): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = bookingApiReadBody();

    try {
        $booking = bookingWorkflowCreateInquiry($pdo, $current, $opportunityId, $body);
        jsonResponse([
            'success' => true,
            'booking' => $booking,
        ], 201);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * GET /api/bookings/mine
 */
function handleBookingMineList(PDO $pdo): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $status = trim((string)($_GET['status'] ?? ''));
    $opportunityId = isset($_GET['opportunity_id']) ? (int)$_GET['opportunity_id'] : 0;
    $limit = max(1, min(300, (int)($_GET['limit'] ?? 200)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    try {
        $bookings = bookingWorkflowListBookings($pdo, $current, [
            'status' => $status,
            'opportunity_id' => $opportunityId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        jsonResponse([
            'bookings' => $bookings,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * GET /api/bookings/requests
 */
function handleBookingRequestsList(PDO $pdo): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $status = trim((string)($_GET['status'] ?? ''));

    try {
        $requests = bookingWorkflowListBookingRequests($pdo, $current, [
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        jsonResponse([
            'requests' => $requests,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * GET /api/bookings/{id}
 */
function handleBookingGet(PDO $pdo, int $id): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $core = bookingWorkflowGetBookingCore($pdo, $id);
    if (!$core) {
        errorResponse('Booking not found', 404);
    }
    if (!bookingWorkflowCanViewBooking($current, $core)) {
        errorResponse('Forbidden', 403);
    }

    $booking = bookingWorkflowGetBookingDetailForActor($pdo, $current, $id);
    jsonResponse(['booking' => $booking]);
}

/**
 * POST /api/bookings/{id}/transition
 */
function handleBookingTransition(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = bookingApiReadBody();

    $toStatus = trim((string)($body['to_status'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    try {
        $booking = bookingWorkflowTransitionBooking($pdo, $current, $id, $toStatus, $note);
        jsonResponse([
            'success' => true,
            'booking' => $booking,
        ]);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}

/**
 * POST /api/bookings/{id}/notes
 */
function handleBookingNoteCreate(PDO $pdo, int $id): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = bookingApiReadBody();

    try {
        $note = bookingWorkflowAddNote($pdo, $current, $id, (string)($body['note'] ?? ''));
        jsonResponse([
            'success' => true,
            'note' => $note,
        ], 201);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), bookingApiErrorStatus($e));
    }
}
