<?php
// Booking interest handlers

/**
 * POST /api/bookings/interest — PUBLIC, no auth required
 */
function handleBookingInterestCreate(PDO $pdo): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $venue_name      = trim($body['venue_name']      ?? '');
    $venue_city      = trim($body['venue_city']      ?? 'S.F.');
    $event_date      = trim($body['event_date']      ?? '');
    $requester_type  = trim($body['requester_type']  ?? 'band');
    $requester_name  = trim($body['requester_name']  ?? '');
    $requester_email = trim($body['requester_email'] ?? '');
    $message         = trim($body['message']         ?? '');
    $band_profile_id = isset($body['band_profile_id']) ? (int)$body['band_profile_id'] : null;

    // Validate required fields
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

    // Normalize requester_type
    if (!in_array($requester_type, ['band', 'promoter', 'other'], true)) {
        $requester_type = 'band';
    }

    $stmt = $pdo->prepare("
        INSERT INTO booking_interests
            (venue_name, venue_city, event_date, requester_type, requester_name, requester_email, message, band_profile_id)
        VALUES
            (:venue_name, :venue_city, :event_date, :requester_type, :requester_name, :requester_email, :message, :band_profile_id)
    ");
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
 * GET /api/bookings/interests — requires auth
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
        $where[]              = 'venue_name LIKE :venue_name';
        $params[':venue_name'] = '%' . $venue_name . '%';
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[]             = 'event_date >= :date_from';
        $params[':date_from'] = $date_from;
    }
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[]           = 'event_date <= :date_to';
        $params[':date_to'] = $date_to;
    }
    if (in_array($status, ['new', 'seen', 'responded'], true)) {
        $where[]         = 'status = :status';
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

    // Cast id
    $interests = array_map(function($row) {
        $row['id'] = (int)$row['id'];
        if ($row['band_profile_id'] !== null) {
            $row['band_profile_id'] = (int)$row['band_profile_id'];
        }
        return $row;
    }, $rows);

    jsonResponse(['interests' => $interests, 'limit' => $limit, 'offset' => $offset]);
}
