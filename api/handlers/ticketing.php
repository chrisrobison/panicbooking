<?php

require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../../lib/ticketing.php';

function ticketingApiBody(): array {
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

function ticketingApiEnsureManagerForEvent(PDO $pdo, array $user, int $eventId): array {
    $event = ticketingGetEventById($pdo, $eventId);
    if (!$event) {
        errorResponse('Event not found', 404);
    }

    if (!ticketingUserCanManageEvent($user, $event)) {
        errorResponse('Forbidden', 403);
    }

    return $event;
}

function handleTicketingCreateEvent(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();

    try {
        $eventId = ticketingCreateEvent($pdo, $user, $body);
        $event = ticketingGetEventById($pdo, $eventId);
        jsonResponse(['success' => true, 'event' => $event], 201);
    } catch (InvalidArgumentException $e) {
        errorResponse($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            errorResponse('Forbidden', 403);
        }
        errorResponse($e->getMessage(), 400);
    } catch (Throwable $e) {
        ticketingLog('api_create_event_failed', ['error' => $e->getMessage()]);
        errorResponse('Failed to create event', 500);
    }
}

function handleTicketingUpdateEvent(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        errorResponse('event_id is required', 422);
    }

    try {
        $event = ticketingUpdateEvent($pdo, $user, $eventId, $body);
        jsonResponse(['success' => true, 'event' => $event]);
    } catch (InvalidArgumentException $e) {
        errorResponse($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            errorResponse('Forbidden', 403);
        }
        if ($e->getMessage() === 'Event not found') {
            errorResponse('Event not found', 404);
        }
        errorResponse($e->getMessage(), 400);
    } catch (Throwable $e) {
        ticketingLog('api_update_event_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        errorResponse('Failed to update event', 500);
    }
}

function handleTicketingCreateTicketType(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();
    $eventId = (int)($body['event_id'] ?? 0);

    if ($eventId <= 0) {
        errorResponse('event_id is required', 422);
    }

    try {
        $ticketTypeId = ticketingCreateTicketType($pdo, $user, $eventId, $body);
        $types = ticketingGetTicketTypes($pdo, $eventId, true);
        $ticketType = null;
        foreach ($types as $tt) {
            if ((int)$tt['id'] === $ticketTypeId) {
                $ticketType = $tt;
                break;
            }
        }
        jsonResponse(['success' => true, 'ticket_type' => $ticketType], 201);
    } catch (InvalidArgumentException $e) {
        errorResponse($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            errorResponse('Forbidden', 403);
        }
        if ($e->getMessage() === 'Event not found') {
            errorResponse('Event not found', 404);
        }
        errorResponse($e->getMessage(), 400);
    } catch (Throwable $e) {
        ticketingLog('api_create_ticket_type_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        errorResponse('Failed to create ticket type', 500);
    }
}

function handleTicketingUpdateTicketType(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();
    $ticketTypeId = (int)($body['ticket_type_id'] ?? 0);

    if ($ticketTypeId <= 0) {
        errorResponse('ticket_type_id is required', 422);
    }

    try {
        $ticketType = ticketingUpdateTicketType($pdo, $user, $ticketTypeId, $body);
        jsonResponse(['success' => true, 'ticket_type' => $ticketType]);
    } catch (InvalidArgumentException $e) {
        errorResponse($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Forbidden') {
            errorResponse('Forbidden', 403);
        }
        if ($e->getMessage() === 'Ticket type not found') {
            errorResponse('Ticket type not found', 404);
        }
        errorResponse($e->getMessage(), 400);
    } catch (Throwable $e) {
        ticketingLog('api_update_ticket_type_failed', ['ticket_type_id' => $ticketTypeId, 'error' => $e->getMessage()]);
        errorResponse('Failed to update ticket type', 500);
    }
}

function handleTicketingCreateOrder(PDO $pdo): void {
    // Public endpoint; CSRF still required for browser form posts.
    apiRequireCsrf();

    $body = ticketingApiBody();
    $user = apiCurrentUser();
    if ($user) {
        $body['user_id'] = (int)$user['id'];
    }

    try {
        $order = paymentCreateOrder($pdo, $body);
        jsonResponse(['success' => true, 'order' => $order], 201);
    } catch (InvalidArgumentException $e) {
        errorResponse($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        ticketingLog('api_create_order_failed', ['error' => $e->getMessage()]);
        errorResponse('Failed to create order', 500);
    }
}

function handleTicketingMarkOrderPaid(PDO $pdo): void {
    // Public endpoint for demo mode completion flow.
    apiRequireCsrf();

    $body = ticketingApiBody();
    $orderId = (int)($body['order_id'] ?? 0);
    if ($orderId <= 0) {
        errorResponse('order_id is required', 422);
    }

    $reference = trim((string)($body['payment_reference'] ?? ''));
    if ($reference === '') {
        $reference = 'demo_' . bin2hex(random_bytes(6));
    }

    try {
        $order = paymentFinalizeSuccessfulOrder($pdo, $orderId, $reference);
        jsonResponse(['success' => true, 'order' => $order]);
    } catch (RuntimeException $e) {
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        ticketingLog('api_mark_order_paid_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        errorResponse('Failed to finalize order', 500);
    }
}

function handleTicketingValidateTicket(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();
    $eventId = (int)($body['event_id'] ?? 0);
    $scanInput = (string)($body['token_or_code'] ?? $body['scan_input'] ?? '');

    if ($eventId <= 0) {
        errorResponse('event_id is required', 422);
    }

    ticketingApiEnsureManagerForEvent($pdo, $user, $eventId);

    try {
        $result = ticketingValidateTicket($pdo, $eventId, $scanInput);
        jsonResponse(['success' => true, 'validation' => $result]);
    } catch (Throwable $e) {
        ticketingLog('api_validate_ticket_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        errorResponse('Failed to validate ticket', 500);
    }
}

function handleTicketingCheckInTicket(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();

    $user = apiCurrentUser();
    $body = ticketingApiBody();
    $eventId = (int)($body['event_id'] ?? 0);
    $scanInput = (string)($body['token_or_code'] ?? $body['scan_input'] ?? '');
    $note = isset($body['note']) ? trim((string)$body['note']) : null;

    if ($eventId <= 0) {
        errorResponse('event_id is required', 422);
    }

    ticketingApiEnsureManagerForEvent($pdo, $user, $eventId);

    try {
        $result = ticketingCheckInTicket($pdo, $eventId, $scanInput, (int)$user['id'], $note);
        jsonResponse(['success' => true, 'checkin' => $result]);
    } catch (Throwable $e) {
        ticketingLog('api_checkin_ticket_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        errorResponse('Failed to check in ticket', 500);
    }
}

function handleTicketingSearchTicketByCode(PDO $pdo): void {
    apiRequireAuth();

    $user = apiCurrentUser();
    $eventId = (int)($_GET['event_id'] ?? 0);
    $query = trim((string)($_GET['q'] ?? ''));

    if ($eventId <= 0) {
        errorResponse('event_id is required', 422);
    }

    ticketingApiEnsureManagerForEvent($pdo, $user, $eventId);

    try {
        $results = ticketingSearchTicketByCode($pdo, $eventId, $query);
        jsonResponse(['success' => true, 'results' => $results]);
    } catch (Throwable $e) {
        ticketingLog('api_search_ticket_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        errorResponse('Failed to search tickets', 500);
    }
}
