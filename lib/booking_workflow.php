<?php

function bookingWorkflowStatuses(): array {
    return ['inquiry', 'hold', 'offer_sent', 'accepted', 'contracted', 'canceled', 'completed'];
}

function bookingWorkflowActiveStatuses(): array {
    return ['inquiry', 'hold', 'offer_sent', 'accepted', 'contracted'];
}

function bookingWorkflowTransitionMap(): array {
    return [
        'inquiry' => ['hold', 'offer_sent', 'canceled'],
        'hold' => ['offer_sent', 'canceled'],
        'offer_sent' => ['accepted', 'canceled'],
        'accepted' => ['contracted', 'canceled'],
        'contracted' => ['completed', 'canceled'],
        'canceled' => [],
        'completed' => [],
    ];
}

function bookingWorkflowStatusLabel(string $status): string {
    return match ($status) {
        'offer_sent' => 'Offer Sent',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function bookingWorkflowCanManageOpportunities(array $actor): bool {
    return !empty($actor['is_admin']) || (($actor['type'] ?? '') === 'venue');
}

function bookingWorkflowCanSubmitInquiries(array $actor): bool {
    return !empty($actor['is_admin']) || (($actor['type'] ?? '') === 'band');
}

function bookingWorkflowCanManageOpportunity(array $actor, array $opportunity): bool {
    return !empty($actor['is_admin']) || ((int)($actor['id'] ?? 0) === (int)$opportunity['venue_user_id']);
}

function bookingWorkflowCanManageVenueSide(array $actor, array $booking): bool {
    return !empty($actor['is_admin']) || ((int)($actor['id'] ?? 0) === (int)$booking['venue_user_id']);
}

function bookingWorkflowCanManageBandSide(array $actor, array $booking): bool {
    return !empty($actor['is_admin']) || ((int)($actor['id'] ?? 0) === (int)$booking['band_user_id']);
}

function bookingWorkflowCanViewBooking(array $actor, array $booking): bool {
    return !empty($actor['is_admin'])
        || ((int)($actor['id'] ?? 0) === (int)$booking['venue_user_id'])
        || ((int)($actor['id'] ?? 0) === (int)$booking['band_user_id']);
}

function bookingWorkflowDecodeProfileData(?string $profileJson): array {
    if (!is_string($profileJson) || $profileJson === '') {
        return [];
    }
    return json_decode($profileJson, true) ?: [];
}

function bookingWorkflowBuildDisplayNameFromProfileData(array $profileData, string $fallback): string {
    $name = trim((string)($profileData['name'] ?? ''));
    return $name !== '' ? $name : $fallback;
}

function bookingWorkflowFetchUserSnapshot(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("\n        SELECT u.id, u.email, u.type, p.data AS profile_json\n        FROM users u\n        LEFT JOIN profiles p ON p.user_id = u.id\n        WHERE u.id = :id\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $profileData = bookingWorkflowDecodeProfileData($row['profile_json'] ?? null);
    $row['id'] = (int)$row['id'];
    $row['display_name'] = bookingWorkflowBuildDisplayNameFromProfileData($profileData, (string)$row['email']);
    return $row;
}

function bookingWorkflowValidateDate(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $ts = strtotime($value . ' 00:00:00');
    return $ts !== false && date('Y-m-d', $ts) === $value;
}

function bookingWorkflowValidateTime(string $value): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
}

function bookingWorkflowNormalizeOpportunityInput(array $input): array {
    $title = trim((string)($input['title'] ?? ''));
    $eventDate = trim((string)($input['event_date'] ?? ''));
    $startTime = trim((string)($input['start_time'] ?? ''));
    $endTime = trim((string)($input['end_time'] ?? ''));
    $genreTags = trim((string)($input['genre_tags'] ?? ''));
    $compensationNotes = trim((string)($input['compensation_notes'] ?? ''));
    $constraintsNotes = trim((string)($input['constraints_notes'] ?? ''));

    if ($title === '' || strlen($title) > 180) {
        throw new InvalidArgumentException('title is required (max 180 chars)');
    }
    if (!bookingWorkflowValidateDate($eventDate)) {
        throw new InvalidArgumentException('event_date must be YYYY-MM-DD');
    }
    if ($startTime !== '' && !bookingWorkflowValidateTime($startTime)) {
        throw new InvalidArgumentException('start_time must be HH:MM (24h)');
    }
    if ($endTime !== '' && !bookingWorkflowValidateTime($endTime)) {
        throw new InvalidArgumentException('end_time must be HH:MM (24h)');
    }
    if ($startTime !== '' && $endTime !== '' && strcmp($endTime, $startTime) < 0) {
        throw new InvalidArgumentException('end_time must be after start_time');
    }
    if (strlen($genreTags) > 600) {
        throw new InvalidArgumentException('genre_tags is too long');
    }
    if (strlen($compensationNotes) > 4000) {
        throw new InvalidArgumentException('compensation_notes is too long');
    }
    if (strlen($constraintsNotes) > 4000) {
        throw new InvalidArgumentException('constraints_notes is too long');
    }

    return [
        'title' => $title,
        'event_date' => $eventDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'genre_tags' => $genreTags,
        'compensation_notes' => $compensationNotes,
        'constraints_notes' => $constraintsNotes,
    ];
}

function bookingWorkflowCreateOpportunity(PDO $pdo, array $actor, array $input): int {
    if (!bookingWorkflowCanManageOpportunities($actor)) {
        throw new RuntimeException('Only venue users can create opportunities');
    }

    $normalized = bookingWorkflowNormalizeOpportunityInput($input);
    $venueUserId = !empty($actor['is_admin'])
        ? (int)($input['venue_user_id'] ?? $actor['id'])
        : (int)$actor['id'];

    $venue = bookingWorkflowFetchUserSnapshot($pdo, $venueUserId);
    if (!$venue || ($venue['type'] ?? '') !== 'venue') {
        throw new InvalidArgumentException('Invalid venue_user_id');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO opportunities\n          (venue_user_id, created_by_user_id, title, event_date, start_time, end_time, genre_tags, compensation_notes, constraints_notes, status, created_at, updated_at)\n        VALUES\n          (:venue_user_id, :created_by_user_id, :title, :event_date, :start_time, :end_time, :genre_tags, :compensation_notes, :constraints_notes, 'open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n    ");
    $stmt->execute([
        ':venue_user_id' => $venueUserId,
        ':created_by_user_id' => (int)$actor['id'],
        ':title' => $normalized['title'],
        ':event_date' => $normalized['event_date'],
        ':start_time' => $normalized['start_time'] !== '' ? $normalized['start_time'] : null,
        ':end_time' => $normalized['end_time'] !== '' ? $normalized['end_time'] : null,
        ':genre_tags' => $normalized['genre_tags'],
        ':compensation_notes' => $normalized['compensation_notes'],
        ':constraints_notes' => $normalized['constraints_notes'],
    ]);

    return (int)$pdo->lastInsertId();
}

function bookingWorkflowUpdateOpportunityStatus(PDO $pdo, array $actor, int $opportunityId, string $status): array {
    $status = trim($status);
    if (!in_array($status, ['open', 'closed', 'canceled'], true)) {
        throw new InvalidArgumentException('Invalid opportunity status');
    }

    $opportunity = bookingWorkflowGetOpportunity($pdo, $opportunityId);
    if (!$opportunity) {
        throw new RuntimeException('Opportunity not found');
    }
    if (!bookingWorkflowCanManageOpportunity($actor, $opportunity)) {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $pdo->prepare('UPDATE opportunities SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $opportunityId,
    ]);

    $updated = bookingWorkflowGetOpportunity($pdo, $opportunityId);
    if (!$updated) {
        throw new RuntimeException('Opportunity not found after update');
    }
    return $updated;
}

function bookingWorkflowListOpportunities(PDO $pdo, array $actor, array $options = []): array {
    $limit = max(1, min(200, (int)($options['limit'] ?? 100)));
    $offset = max(0, (int)($options['offset'] ?? 0));
    $mineOnly = !empty($options['mine_only']);
    $openOnly = !empty($options['open_only']);
    $venueUserIdFilter = isset($options['venue_user_id']) ? (int)$options['venue_user_id'] : 0;
    $q = trim((string)($options['q'] ?? ''));

    $where = [];
    $params = [];

    if ($mineOnly && empty($actor['is_admin'])) {
        $where[] = 'o.venue_user_id = :mine_venue_id';
        $params[':mine_venue_id'] = (int)$actor['id'];
    } elseif ($venueUserIdFilter > 0) {
        $where[] = 'o.venue_user_id = :venue_user_id';
        $params[':venue_user_id'] = $venueUserIdFilter;
    }

    if ($openOnly) {
        $where[] = "o.status = 'open'";
        $where[] = 'o.event_date >= :today';
        $params[':today'] = date('Y-m-d');
    }

    if ($q !== '') {
        $where[] = '(o.title LIKE :q OR o.genre_tags LIKE :q2 OR o.compensation_notes LIKE :q3 OR o.constraints_notes LIKE :q4)';
        $params[':q'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "\n        SELECT o.*,\n               vu.email AS venue_email,\n               vp.data AS venue_profile_json,\n               (SELECT COUNT(*) FROM booking_requests br WHERE br.opportunity_id = o.id) AS inquiry_count,\n               (SELECT COUNT(*) FROM bookings b2 WHERE b2.opportunity_id = o.id AND b2.status IN ('inquiry','hold','offer_sent','accepted','contracted')) AS active_booking_count\n        FROM opportunities o\n        JOIN users vu ON vu.id = o.venue_user_id\n        LEFT JOIN profiles vp ON vp.user_id = o.venue_user_id\n        {$whereSql}\n        ORDER BY o.event_date ASC, COALESCE(o.start_time, '23:59') ASC, o.id DESC\n        LIMIT :limit OFFSET :offset\n    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $opportunities = [];
    foreach ($rows as $row) {
        $profile = bookingWorkflowDecodeProfileData($row['venue_profile_json'] ?? null);
        $venueName = bookingWorkflowBuildDisplayNameFromProfileData($profile, (string)$row['venue_email']);

        $row['id'] = (int)$row['id'];
        $row['venue_user_id'] = (int)$row['venue_user_id'];
        $row['created_by_user_id'] = (int)$row['created_by_user_id'];
        $row['inquiry_count'] = (int)($row['inquiry_count'] ?? 0);
        $row['active_booking_count'] = (int)($row['active_booking_count'] ?? 0);
        $row['venue_name'] = $venueName;

        $opportunities[] = $row;
    }

    if (($actor['type'] ?? '') === 'band' || !empty($options['include_band_context'])) {
        $bandUserId = isset($options['band_user_id']) ? (int)$options['band_user_id'] : (int)($actor['id'] ?? 0);
        if ($bandUserId > 0 && !empty($opportunities)) {
            $mapStmt = $pdo->prepare("\n                SELECT opportunity_id, id, status\n                FROM bookings\n                WHERE band_user_id = :band_user_id\n                ORDER BY id DESC\n            ");
            $mapStmt->execute([':band_user_id' => $bandUserId]);
            $bookings = $mapStmt->fetchAll();
            $bookingMap = [];
            foreach ($bookings as $booking) {
                $oppId = (int)$booking['opportunity_id'];
                if (!isset($bookingMap[$oppId])) {
                    $bookingMap[$oppId] = [
                        'booking_id' => (int)$booking['id'],
                        'status' => (string)$booking['status'],
                    ];
                }
            }

            foreach ($opportunities as &$opportunity) {
                $info = $bookingMap[(int)$opportunity['id']] ?? null;
                $opportunity['my_booking_id'] = $info['booking_id'] ?? null;
                $opportunity['my_booking_status'] = $info['status'] ?? null;
            }
            unset($opportunity);
        }
    }

    return $opportunities;
}

function bookingWorkflowGetOpportunity(PDO $pdo, int $opportunityId): ?array {
    $stmt = $pdo->prepare("\n        SELECT o.*,\n               vu.email AS venue_email,\n               vp.data AS venue_profile_json,\n               (SELECT COUNT(*) FROM booking_requests br WHERE br.opportunity_id = o.id) AS inquiry_count,\n               (SELECT COUNT(*) FROM bookings b2 WHERE b2.opportunity_id = o.id AND b2.status IN ('inquiry','hold','offer_sent','accepted','contracted')) AS active_booking_count\n        FROM opportunities o\n        JOIN users vu ON vu.id = o.venue_user_id\n        LEFT JOIN profiles vp ON vp.user_id = o.venue_user_id\n        WHERE o.id = :id\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $opportunityId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $profile = bookingWorkflowDecodeProfileData($row['venue_profile_json'] ?? null);
    $row['venue_name'] = bookingWorkflowBuildDisplayNameFromProfileData($profile, (string)$row['venue_email']);
    $row['id'] = (int)$row['id'];
    $row['venue_user_id'] = (int)$row['venue_user_id'];
    $row['created_by_user_id'] = (int)$row['created_by_user_id'];
    $row['inquiry_count'] = (int)($row['inquiry_count'] ?? 0);
    $row['active_booking_count'] = (int)($row['active_booking_count'] ?? 0);
    return $row;
}

function bookingWorkflowCreateInquiry(PDO $pdo, array $actor, int $opportunityId, array $input): array {
    if (!bookingWorkflowCanSubmitInquiries($actor)) {
        throw new RuntimeException('Only band users can submit inquiries');
    }

    $opportunity = bookingWorkflowGetOpportunity($pdo, $opportunityId);
    if (!$opportunity) {
        throw new RuntimeException('Opportunity not found');
    }

    if (($opportunity['status'] ?? '') !== 'open') {
        throw new InvalidArgumentException('Opportunity is not open for inquiries');
    }

    $bandUserId = !empty($actor['is_admin'])
        ? (int)($input['band_user_id'] ?? $actor['id'])
        : (int)$actor['id'];

    $band = bookingWorkflowFetchUserSnapshot($pdo, $bandUserId);
    if (!$band || ($band['type'] ?? '') !== 'band') {
        throw new InvalidArgumentException('Invalid band user');
    }

    $message = trim((string)($input['message'] ?? ''));
    if (strlen($message) > 4000) {
        throw new InvalidArgumentException('Inquiry message is too long');
    }

    $activeStatuses = bookingWorkflowActiveStatuses();
    $ph = implode(', ', array_fill(0, count($activeStatuses), '?'));
    $existingStmt = $pdo->prepare("\n        SELECT id\n        FROM bookings\n        WHERE opportunity_id = ?\n          AND band_user_id = ?\n          AND status IN ({$ph})\n        LIMIT 1\n    ");
    $existingStmt->execute(array_merge([(int)$opportunity['id'], $bandUserId], $activeStatuses));
    if ($existingStmt->fetch()) {
        throw new RuntimeException('You already have an active inquiry for this opportunity');
    }

    $pdo->beginTransaction();
    try {
        $reqStmt = $pdo->prepare("\n            INSERT INTO booking_requests\n              (opportunity_id, venue_user_id, band_user_id, message, status, created_by_user_id, created_at, updated_at)\n            VALUES\n              (:opportunity_id, :venue_user_id, :band_user_id, :message, 'inquiry', :created_by_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n        ");
        $reqStmt->execute([
            ':opportunity_id' => (int)$opportunity['id'],
            ':venue_user_id' => (int)$opportunity['venue_user_id'],
            ':band_user_id' => $bandUserId,
            ':message' => $message,
            ':created_by_user_id' => (int)$actor['id'],
        ]);
        $bookingRequestId = (int)$pdo->lastInsertId();

        $bookingStmt = $pdo->prepare("\n            INSERT INTO bookings\n              (opportunity_id, booking_request_id, venue_user_id, band_user_id, status, event_date, start_time, end_time, genre_tags, compensation_notes, constraints_notes, offer_notes, created_by_user_id, created_at, updated_at)\n            VALUES\n              (:opportunity_id, :booking_request_id, :venue_user_id, :band_user_id, 'inquiry', :event_date, :start_time, :end_time, :genre_tags, :compensation_notes, :constraints_notes, '', :created_by_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n        ");
        $bookingStmt->execute([
            ':opportunity_id' => (int)$opportunity['id'],
            ':booking_request_id' => $bookingRequestId,
            ':venue_user_id' => (int)$opportunity['venue_user_id'],
            ':band_user_id' => $bandUserId,
            ':event_date' => (string)$opportunity['event_date'],
            ':start_time' => ($opportunity['start_time'] ?? '') !== '' ? $opportunity['start_time'] : null,
            ':end_time' => ($opportunity['end_time'] ?? '') !== '' ? $opportunity['end_time'] : null,
            ':genre_tags' => (string)($opportunity['genre_tags'] ?? ''),
            ':compensation_notes' => (string)($opportunity['compensation_notes'] ?? ''),
            ':constraints_notes' => (string)($opportunity['constraints_notes'] ?? ''),
            ':created_by_user_id' => (int)$actor['id'],
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $historyStmt = $pdo->prepare("\n            INSERT INTO booking_status_history\n              (booking_id, from_status, to_status, changed_by_user_id, note, created_at)\n            VALUES\n              (:booking_id, NULL, 'inquiry', :changed_by_user_id, :note, CURRENT_TIMESTAMP)\n        ");
        $historyStmt->execute([
            ':booking_id' => $bookingId,
            ':changed_by_user_id' => (int)$actor['id'],
            ':note' => 'Inquiry created',
        ]);

        if ($message !== '') {
            $noteStmt = $pdo->prepare("\n                INSERT INTO booking_notes\n                  (booking_id, author_user_id, note, created_at)\n                VALUES\n                  (:booking_id, :author_user_id, :note, CURRENT_TIMESTAMP)\n            ");
            $noteStmt->execute([
                ':booking_id' => $bookingId,
                ':author_user_id' => (int)$actor['id'],
                ':note' => $message,
            ]);
        }

        $pdo->commit();

        $booking = bookingWorkflowGetBookingDetailForActor($pdo, $actor, $bookingId);
        if (!$booking) {
            throw new RuntimeException('Failed to load booking');
        }
        return $booking;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bookingWorkflowHydrateBookingRows(array $rows): array {
    $bookings = [];
    foreach ($rows as $row) {
        $venueProfile = bookingWorkflowDecodeProfileData($row['venue_profile_json'] ?? null);
        $bandProfile = bookingWorkflowDecodeProfileData($row['band_profile_json'] ?? null);

        $row['id'] = (int)$row['id'];
        $row['opportunity_id'] = (int)$row['opportunity_id'];
        $row['booking_request_id'] = $row['booking_request_id'] !== null ? (int)$row['booking_request_id'] : null;
        $row['venue_user_id'] = (int)$row['venue_user_id'];
        $row['band_user_id'] = (int)$row['band_user_id'];
        $row['created_by_user_id'] = (int)$row['created_by_user_id'];
        $row['venue_name'] = bookingWorkflowBuildDisplayNameFromProfileData($venueProfile, (string)$row['venue_email']);
        $row['band_name'] = bookingWorkflowBuildDisplayNameFromProfileData($bandProfile, (string)$row['band_email']);
        $row['status_label'] = bookingWorkflowStatusLabel((string)$row['status']);
        $row['opportunity_title'] = (string)($row['opportunity_title'] ?? 'Opportunity');

        $bookings[] = $row;
    }
    return $bookings;
}

function bookingWorkflowListBookings(PDO $pdo, array $actor, array $options = []): array {
    $limit = max(1, min(300, (int)($options['limit'] ?? 200)));
    $offset = max(0, (int)($options['offset'] ?? 0));
    $status = trim((string)($options['status'] ?? ''));
    $opportunityId = isset($options['opportunity_id']) ? (int)$options['opportunity_id'] : 0;

    $where = [];
    $params = [];

    if (empty($actor['is_admin'])) {
        if (($actor['type'] ?? '') === 'venue') {
            $where[] = 'b.venue_user_id = :venue_user_id';
            $params[':venue_user_id'] = (int)$actor['id'];
        } elseif (($actor['type'] ?? '') === 'band') {
            $where[] = 'b.band_user_id = :band_user_id';
            $params[':band_user_id'] = (int)$actor['id'];
        } else {
            return [];
        }
    }

    if ($status !== '') {
        if (!in_array($status, bookingWorkflowStatuses(), true)) {
            throw new InvalidArgumentException('Invalid booking status filter');
        }
        $where[] = 'b.status = :status';
        $params[':status'] = $status;
    }

    if ($opportunityId > 0) {
        $where[] = 'b.opportunity_id = :opportunity_id';
        $params[':opportunity_id'] = $opportunityId;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "\n        SELECT b.*,\n               o.title AS opportunity_title,\n               o.status AS opportunity_status,\n               vu.email AS venue_email,\n               bu.email AS band_email,\n               vp.data AS venue_profile_json,\n               bp.data AS band_profile_json\n        FROM bookings b\n        JOIN opportunities o ON o.id = b.opportunity_id\n        JOIN users vu ON vu.id = b.venue_user_id\n        JOIN users bu ON bu.id = b.band_user_id\n        LEFT JOIN profiles vp ON vp.user_id = b.venue_user_id\n        LEFT JOIN profiles bp ON bp.user_id = b.band_user_id\n        {$whereSql}\n        ORDER BY b.event_date ASC, COALESCE(b.start_time, '23:59') ASC, b.id DESC\n        LIMIT :limit OFFSET :offset\n    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return bookingWorkflowHydrateBookingRows($stmt->fetchAll());
}

function bookingWorkflowListBookingRequests(PDO $pdo, array $actor, array $options = []): array {
    $limit = max(1, min(200, (int)($options['limit'] ?? 100)));
    $offset = max(0, (int)($options['offset'] ?? 0));
    $where = [];
    $params = [];

    if (empty($actor['is_admin'])) {
        if (($actor['type'] ?? '') === 'venue') {
            $where[] = 'br.venue_user_id = :venue_user_id';
            $params[':venue_user_id'] = (int)$actor['id'];
        } elseif (($actor['type'] ?? '') === 'band') {
            $where[] = 'br.band_user_id = :band_user_id';
            $params[':band_user_id'] = (int)$actor['id'];
        } else {
            return [];
        }
    }

    $status = trim((string)($options['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, ['inquiry', 'withdrawn', 'converted', 'closed'], true)) {
            throw new InvalidArgumentException('Invalid request status filter');
        }
        $where[] = 'br.status = :status';
        $params[':status'] = $status;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("\n        SELECT br.*,\n               o.title AS opportunity_title,\n               o.event_date,\n               vu.email AS venue_email,\n               bu.email AS band_email,\n               vp.data AS venue_profile_json,\n               bp.data AS band_profile_json\n        FROM booking_requests br\n        JOIN opportunities o ON o.id = br.opportunity_id\n        JOIN users vu ON vu.id = br.venue_user_id\n        JOIN users bu ON bu.id = br.band_user_id\n        LEFT JOIN profiles vp ON vp.user_id = br.venue_user_id\n        LEFT JOIN profiles bp ON bp.user_id = br.band_user_id\n        {$whereSql}\n        ORDER BY br.created_at DESC, br.id DESC\n        LIMIT :limit OFFSET :offset\n    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $venueProfile = bookingWorkflowDecodeProfileData($row['venue_profile_json'] ?? null);
        $bandProfile = bookingWorkflowDecodeProfileData($row['band_profile_json'] ?? null);
        $row['id'] = (int)$row['id'];
        $row['opportunity_id'] = (int)$row['opportunity_id'];
        $row['venue_user_id'] = (int)$row['venue_user_id'];
        $row['band_user_id'] = (int)$row['band_user_id'];
        $row['created_by_user_id'] = (int)$row['created_by_user_id'];
        $row['venue_name'] = bookingWorkflowBuildDisplayNameFromProfileData($venueProfile, (string)$row['venue_email']);
        $row['band_name'] = bookingWorkflowBuildDisplayNameFromProfileData($bandProfile, (string)$row['band_email']);
        $items[] = $row;
    }

    return $items;
}

function bookingWorkflowGetBookingCore(PDO $pdo, int $bookingId): ?array {
    $stmt = $pdo->prepare("\n        SELECT b.*,\n               o.title AS opportunity_title,\n               o.status AS opportunity_status,\n               o.venue_user_id AS opportunity_venue_user_id,\n               vu.email AS venue_email,\n               bu.email AS band_email,\n               vp.data AS venue_profile_json,\n               bp.data AS band_profile_json\n        FROM bookings b\n        JOIN opportunities o ON o.id = b.opportunity_id\n        JOIN users vu ON vu.id = b.venue_user_id\n        JOIN users bu ON bu.id = b.band_user_id\n        LEFT JOIN profiles vp ON vp.user_id = b.venue_user_id\n        LEFT JOIN profiles bp ON bp.user_id = b.band_user_id\n        WHERE b.id = :id\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $bookingId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $hydrated = bookingWorkflowHydrateBookingRows([$row]);
    return $hydrated[0] ?? null;
}

function bookingWorkflowListHistory(PDO $pdo, int $bookingId): array {
    $stmt = $pdo->prepare("\n        SELECT h.*, u.email AS changed_by_email, p.data AS changed_by_profile_json\n        FROM booking_status_history h\n        JOIN users u ON u.id = h.changed_by_user_id\n        LEFT JOIN profiles p ON p.user_id = h.changed_by_user_id\n        WHERE h.booking_id = :booking_id\n        ORDER BY h.created_at ASC, h.id ASC\n    ");
    $stmt->execute([':booking_id' => $bookingId]);
    $rows = $stmt->fetchAll();

    $history = [];
    foreach ($rows as $row) {
        $profile = bookingWorkflowDecodeProfileData($row['changed_by_profile_json'] ?? null);
        $history[] = [
            'id' => (int)$row['id'],
            'booking_id' => (int)$row['booking_id'],
            'from_status' => $row['from_status'],
            'to_status' => (string)$row['to_status'],
            'changed_by_user_id' => (int)$row['changed_by_user_id'],
            'changed_by_name' => bookingWorkflowBuildDisplayNameFromProfileData($profile, (string)$row['changed_by_email']),
            'note' => (string)($row['note'] ?? ''),
            'created_at' => (string)$row['created_at'],
            'to_status_label' => bookingWorkflowStatusLabel((string)$row['to_status']),
            'from_status_label' => $row['from_status'] ? bookingWorkflowStatusLabel((string)$row['from_status']) : null,
        ];
    }

    return $history;
}

function bookingWorkflowListNotes(PDO $pdo, int $bookingId): array {
    $stmt = $pdo->prepare("\n        SELECT n.*, u.email AS author_email, p.data AS author_profile_json\n        FROM booking_notes n\n        JOIN users u ON u.id = n.author_user_id\n        LEFT JOIN profiles p ON p.user_id = n.author_user_id\n        WHERE n.booking_id = :booking_id\n        ORDER BY n.created_at ASC, n.id ASC\n    ");
    $stmt->execute([':booking_id' => $bookingId]);
    $rows = $stmt->fetchAll();

    $notes = [];
    foreach ($rows as $row) {
        $profile = bookingWorkflowDecodeProfileData($row['author_profile_json'] ?? null);
        $notes[] = [
            'id' => (int)$row['id'],
            'booking_id' => (int)$row['booking_id'],
            'author_user_id' => (int)$row['author_user_id'],
            'author_name' => bookingWorkflowBuildDisplayNameFromProfileData($profile, (string)$row['author_email']),
            'note' => (string)($row['note'] ?? ''),
            'created_at' => (string)$row['created_at'],
        ];
    }

    return $notes;
}

function bookingWorkflowGetBookingDetailForActor(PDO $pdo, array $actor, int $bookingId): ?array {
    $booking = bookingWorkflowGetBookingCore($pdo, $bookingId);
    if (!$booking) {
        return null;
    }
    if (!bookingWorkflowCanViewBooking($actor, $booking)) {
        return null;
    }

    $booking['history'] = bookingWorkflowListHistory($pdo, $bookingId);
    $booking['notes'] = bookingWorkflowListNotes($pdo, $bookingId);
    $booking['allowed_transitions'] = bookingWorkflowAllowedTransitionsForActor($actor, $booking);

    return $booking;
}

function bookingWorkflowAllowedTransitionsForActor(array $actor, array $booking): array {
    $current = (string)($booking['status'] ?? '');
    if (!in_array($current, bookingWorkflowStatuses(), true)) {
        return [];
    }

    if (!bookingWorkflowCanViewBooking($actor, $booking)) {
        return [];
    }

    if (!empty($actor['is_admin'])) {
        return array_values(array_filter(
            bookingWorkflowStatuses(),
            fn(string $status): bool => $status !== $current
        ));
    }

    $map = bookingWorkflowTransitionMap();
    $allowed = $map[$current] ?? [];

    $roleTargets = [];
    if (bookingWorkflowCanManageVenueSide($actor, $booking)) {
        $roleTargets = array_merge($roleTargets, ['hold', 'offer_sent', 'contracted', 'canceled', 'completed']);
    }
    if (bookingWorkflowCanManageBandSide($actor, $booking)) {
        $roleTargets = array_merge($roleTargets, ['accepted', 'canceled']);
    }

    $roleTargets = array_values(array_unique($roleTargets));
    $allowed = array_values(array_intersect($allowed, $roleTargets));

    if (in_array('completed', $allowed, true) && ((string)$booking['event_date']) > date('Y-m-d')) {
        $allowed = array_values(array_filter($allowed, fn(string $status): bool => $status !== 'completed'));
    }

    return $allowed;
}

function bookingWorkflowTransitionBooking(PDO $pdo, array $actor, int $bookingId, string $toStatus, string $note = ''): array {
    $toStatus = trim($toStatus);
    if (!in_array($toStatus, bookingWorkflowStatuses(), true)) {
        throw new InvalidArgumentException('Invalid status');
    }

    $booking = bookingWorkflowGetBookingCore($pdo, $bookingId);
    if (!$booking) {
        throw new RuntimeException('Booking not found');
    }
    if (!bookingWorkflowCanViewBooking($actor, $booking)) {
        throw new RuntimeException('Forbidden');
    }

    $currentStatus = (string)$booking['status'];
    if ($currentStatus === $toStatus) {
        throw new InvalidArgumentException('Booking is already in that status');
    }

    $allowed = bookingWorkflowAllowedTransitionsForActor($actor, $booking);
    if (!in_array($toStatus, $allowed, true)) {
        throw new RuntimeException('Illegal status transition');
    }

    if ($toStatus === 'completed' && empty($actor['is_admin']) && ((string)$booking['event_date']) > date('Y-m-d')) {
        throw new RuntimeException('Cannot complete booking before event date');
    }

    $note = trim($note);
    if (strlen($note) > 2000) {
        throw new InvalidArgumentException('Transition note is too long');
    }

    $pdo->beginTransaction();
    try {
        if (in_array($toStatus, ['accepted', 'contracted', 'completed'], true)) {
            $conflictStmt = $pdo->prepare("\n                SELECT id, status\n                FROM bookings\n                WHERE opportunity_id = :opportunity_id\n                  AND id != :id\n                  AND status IN ('accepted', 'contracted', 'completed')\n                LIMIT 1\n            ");
            $conflictStmt->execute([
                ':opportunity_id' => (int)$booking['opportunity_id'],
                ':id' => (int)$booking['id'],
            ]);
            $conflict = $conflictStmt->fetch();
            if ($conflict) {
                throw new RuntimeException('Another booking is already committed for this opportunity');
            }
        }

        $setSql = 'status = :status, updated_at = CURRENT_TIMESTAMP';
        $params = [
            ':status' => $toStatus,
            ':id' => (int)$booking['id'],
        ];

        if ($toStatus === 'accepted') {
            $setSql .= ', accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)';
        }
        if ($toStatus === 'contracted') {
            $setSql .= ', contracted_at = COALESCE(contracted_at, CURRENT_TIMESTAMP)';
        }
        if ($toStatus === 'canceled') {
            $setSql .= ', canceled_at = COALESCE(canceled_at, CURRENT_TIMESTAMP)';
        }
        if ($toStatus === 'completed') {
            $setSql .= ', completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)';
        }

        $updateStmt = $pdo->prepare("UPDATE bookings SET {$setSql} WHERE id = :id");
        $updateStmt->execute($params);

        $historyStmt = $pdo->prepare("\n            INSERT INTO booking_status_history\n              (booking_id, from_status, to_status, changed_by_user_id, note, created_at)\n            VALUES\n              (:booking_id, :from_status, :to_status, :changed_by_user_id, :note, CURRENT_TIMESTAMP)\n        ");
        $historyStmt->execute([
            ':booking_id' => (int)$booking['id'],
            ':from_status' => $currentStatus,
            ':to_status' => $toStatus,
            ':changed_by_user_id' => (int)$actor['id'],
            ':note' => $note,
        ]);

        if (!empty($booking['booking_request_id'])) {
            $requestStatus = 'inquiry';
            if ($toStatus === 'canceled') {
                $requestStatus = bookingWorkflowCanManageBandSide($actor, $booking) ? 'withdrawn' : 'closed';
            } elseif (in_array($toStatus, ['accepted', 'contracted', 'completed'], true)) {
                $requestStatus = 'converted';
            }

            $requestStmt = $pdo->prepare('UPDATE booking_requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $requestStmt->execute([
                ':status' => $requestStatus,
                ':id' => (int)$booking['booking_request_id'],
            ]);
        }

        if (in_array($toStatus, ['accepted', 'contracted', 'completed'], true)) {
            $oppStmt = $pdo->prepare("\n                UPDATE opportunities\n                SET status = CASE WHEN status = 'canceled' THEN status ELSE 'closed' END,\n                    updated_at = CURRENT_TIMESTAMP\n                WHERE id = :id\n            ");
            $oppStmt->execute([':id' => (int)$booking['opportunity_id']]);
        }

        $pdo->commit();

        $updated = bookingWorkflowGetBookingDetailForActor($pdo, $actor, $bookingId);
        if (!$updated) {
            throw new RuntimeException('Failed to load updated booking');
        }
        return $updated;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bookingWorkflowAddNote(PDO $pdo, array $actor, int $bookingId, string $note): array {
    $note = trim($note);
    if ($note === '') {
        throw new InvalidArgumentException('note is required');
    }
    if (strlen($note) > 4000) {
        throw new InvalidArgumentException('note is too long');
    }

    $booking = bookingWorkflowGetBookingCore($pdo, $bookingId);
    if (!$booking) {
        throw new RuntimeException('Booking not found');
    }
    if (!bookingWorkflowCanViewBooking($actor, $booking)) {
        throw new RuntimeException('Forbidden');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO booking_notes\n          (booking_id, author_user_id, note, created_at)\n        VALUES\n          (:booking_id, :author_user_id, :note, CURRENT_TIMESTAMP)\n    ");
    $stmt->execute([
        ':booking_id' => $bookingId,
        ':author_user_id' => (int)$actor['id'],
        ':note' => $note,
    ]);

    $notes = bookingWorkflowListNotes($pdo, $bookingId);
    return $notes[count($notes) - 1] ?? [];
}
