<?php

require_once __DIR__ . '/security.php';

function ticketingLog(string $message, array $context = []): void {
    $suffix = $context ? ' ' . json_encode($context) : '';
    error_log('[ticketing] ' . $message . $suffix);
}

function ticketingNow(): string {
    return date('Y-m-d H:i:s');
}

function ticketingNormalizeEmail(string $email): string {
    return strtolower(trim($email));
}

function ticketingNormalizeDateTime(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    // Accept datetime-local style input and SQL datetime strings.
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function ticketingSlugify(string $title): string {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');
    return $slug !== '' ? $slug : 'event';
}

function ticketingEnsureUniqueEventSlug(PDO $pdo, string $baseSlug, ?int $excludeEventId = null): string {
    $slug = $baseSlug;
    $i = 2;

    while (true) {
        if ($excludeEventId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM events WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute([':slug' => $slug, ':id' => $excludeEventId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM events WHERE slug = :slug LIMIT 1');
            $stmt->execute([':slug' => $slug]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $i;
        $i++;
    }
}

/**
 * Can the user create/list events at all?
 * Venues and admins always yes. Promoters yes if they have ≥1 active delegation.
 * Bands and agents yes — they create 'listing'-type drafts.
 */
function ticketingUserCanManageEvents(array $user, ?PDO $pdo = null): bool {
    $type = $user['type'] ?? '';
    if (!empty($user['is_admin']) || $type === 'venue') {
        return true;
    }
    if ($type === 'promoter' && $pdo !== null) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM venue_promoter_delegations
             WHERE promoter_user_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([(int)$user['id']]);
        return (bool)$stmt->fetchColumn();
    }
    // Bands and booking agents can create show-listing drafts.
    return in_array($type, ['band', 'agent'], true);
}

function ticketingAssertManager(array $user, ?PDO $pdo = null): void {
    if (!ticketingUserCanManageEvents($user, $pdo)) {
        throw new RuntimeException('Forbidden');
    }
}

/**
 * Can the user edit/manage this specific event?
 * - Admin: always.
 * - Venue: owns the event's venue_id.
 * - Promoter: promoted this event (promoted_by_user_id).
 * - Band / Agent: created the event (show listing they submitted).
 */
function ticketingUserCanManageEvent(array $user, array $event): bool {
    if (!empty($user['is_admin'])) {
        return true;
    }
    $type = $user['type'] ?? '';
    $uid  = (int)$user['id'];
    if ($type === 'venue')    return (int)$event['venue_id']             === $uid;
    if ($type === 'promoter') return (int)($event['promoted_by_user_id'] ?? 0) === $uid;
    // band / agent: must be the original creator
    return (int)($event['created_by_user_id'] ?? 0) === $uid;
}

/**
 * Returns venues a user is allowed to attach an event to.
 * Venue users → their own venue.
 * Promoters   → their delegated venues.
 * Bands/Agents → all venues (for show-listing requests).
 * Admins      → all venues.
 */
function ticketingGetVenuesForUser(PDO $pdo, array $user): array {
    $type = $user['type'] ?? '';

    if ($type === 'venue') {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.email, p.data
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    }

    if ($type === 'promoter') {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.email, p.data
             FROM venue_promoter_delegations vpd
             JOIN  users    u ON u.id = vpd.venue_user_id
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE vpd.promoter_user_id = ? AND vpd.status = 'active'
             ORDER BY u.email ASC"
        );
        $stmt->execute([(int)$user['id']]);
        return $stmt->fetchAll();
    }

    // admin, band, agent: full venue list
    $stmt = $pdo->query(
        "SELECT u.id, u.email, p.data
         FROM users u
         LEFT JOIN profiles p ON p.user_id = u.id
         WHERE u.type = 'venue'
         ORDER BY u.email ASC"
    );
    return $stmt->fetchAll();
}

function ticketingEnsureVenueExists(PDO $pdo, int $venueId): bool {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND type = 'venue' LIMIT 1");
    $stmt->execute([$venueId]);
    return (bool)$stmt->fetchColumn();
}

function ticketingGetEventById(PDO $pdo, int $eventId): ?array {
    $stmt = $pdo->prepare("\n        SELECT e.*, u.email AS venue_email, p.data AS venue_profile_json\n        FROM events e\n        JOIN users u ON u.id = e.venue_id\n        LEFT JOIN profiles p ON p.user_id = e.venue_id\n        WHERE e.id = :id\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        return null;
    }

    $profile = json_decode($event['venue_profile_json'] ?? '{}', true) ?: [];
    $event['id'] = (int)$event['id'];
    $event['venue_id'] = (int)$event['venue_id'];
    $event['created_by_user_id'] = (int)$event['created_by_user_id'];
    $event['capacity'] = $event['capacity'] !== null ? (int)$event['capacity'] : null;
    $event['venue_name'] = trim((string)($profile['name'] ?? ''));
    if ($event['venue_name'] === '') {
        $event['venue_name'] = $event['venue_email'];
    }

    return $event;
}

function ticketingListManageableEvents(PDO $pdo, array $user, int $limit = 200): array {
    ticketingAssertManager($user);

    $params = [':limit' => max(1, min(500, $limit))];
    $where = '';

    if (empty($user['is_admin'])) {
        $where = 'WHERE e.venue_id = :venue_id';
        $params[':venue_id'] = (int)$user['id'];
    }

    $sql = "\n        SELECT e.*, p.data AS venue_profile_json\n        FROM events e\n        LEFT JOIN profiles p ON p.user_id = e.venue_id\n        {$where}\n        ORDER BY e.start_at DESC\n        LIMIT :limit\n    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':limit' || $k === ':venue_id') {
            $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v);
        }
    }
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $profile = json_decode($row['venue_profile_json'] ?? '{}', true) ?: [];
        $row['id'] = (int)$row['id'];
        $row['venue_id'] = (int)$row['venue_id'];
        $row['capacity'] = $row['capacity'] !== null ? (int)$row['capacity'] : null;
        $row['venue_name'] = trim((string)($profile['name'] ?? ''));
    }
    unset($row);

    return $rows;
}

function ticketingCreateEvent(PDO $pdo, array $actor, array $input): int {
    ticketingAssertManager($actor, $pdo);

    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Title is required');
    }

    $actorType = $actor['type'] ?? '';

    // Resolve venue_id based on actor role.
    if (!empty($actor['is_admin'])) {
        $venueId = (int)($input['venue_id'] ?? 0);
    } elseif ($actorType === 'venue') {
        $venueId = (int)$actor['id'];
    } elseif ($actorType === 'promoter') {
        $venueId = (int)($input['venue_id'] ?? 0);
        $chk = $pdo->prepare(
            "SELECT 1 FROM venue_promoter_delegations
             WHERE promoter_user_id = ? AND venue_user_id = ? AND status = 'active' LIMIT 1"
        );
        $chk->execute([(int)$actor['id'], $venueId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('You are not authorized to create events for this venue');
        }
    } else {
        // band / agent: show listing at any venue
        $venueId = (int)($input['venue_id'] ?? 0);
    }

    if ($venueId <= 0 || !ticketingEnsureVenueExists($pdo, $venueId)) {
        throw new InvalidArgumentException('A valid venue is required');
    }

    // Bands and agents can only save listing drafts — not publish ticketed events.
    $isListingOnly = in_array($actorType, ['band', 'agent'], true);

    $status = (string)($input['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published', 'canceled'], true)) {
        $status = 'draft';
    }
    if ($isListingOnly) {
        $status = 'draft';
    }

    $eventType = $isListingOnly ? 'listing' : (string)($input['event_type'] ?? 'ticketed');
    if (!in_array($eventType, ['ticketed', 'listing'], true)) {
        $eventType = 'ticketed';
    }

    $visibility = (string)($input['visibility'] ?? 'public');
    if (!in_array($visibility, ['public', 'private', 'unlisted'], true)) {
        $visibility = 'public';
    }

    $startAt = ticketingNormalizeDateTime($input['start_at'] ?? null);
    if ($startAt === null) {
        throw new InvalidArgumentException('start_at is required');
    }

    $endAt = ticketingNormalizeDateTime($input['end_at'] ?? null);
    if ($endAt !== null && strtotime($endAt) < strtotime($startAt)) {
        throw new InvalidArgumentException('end_at must be after start_at');
    }

    $doorsAt = ticketingNormalizeDateTime($input['doors_at'] ?? null);
    $promotedBy = ($actorType === 'promoter') ? (int)$actor['id'] : null;

    $capacity = isset($input['capacity']) && $input['capacity'] !== '' ? (int)$input['capacity'] : null;
    if ($capacity !== null && $capacity < 1) {
        throw new InvalidArgumentException('capacity must be positive');
    }

    $slugBase = ticketingSlugify((string)($input['slug'] ?? $title));
    $slug = ticketingEnsureUniqueEventSlug($pdo, $slugBase);

    $stmt = $pdo->prepare("
        INSERT INTO events
          (venue_id, created_by_user_id, promoted_by_user_id,
           title, slug, description,
           doors_at, start_at, end_at,
           event_type, status, capacity, visibility,
           created_at, updated_at)
        VALUES
          (:venue_id, :created_by_user_id, :promoted_by_user_id,
           :title, :slug, :description,
           :doors_at, :start_at, :end_at,
           :event_type, :status, :capacity, :visibility,
           CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");

    $stmt->execute([
        ':venue_id'            => $venueId,
        ':created_by_user_id'  => (int)$actor['id'],
        ':promoted_by_user_id' => $promotedBy,
        ':title'               => $title,
        ':slug'                => $slug,
        ':description'         => trim((string)($input['description'] ?? '')),
        ':doors_at'            => $doorsAt,
        ':start_at'            => $startAt,
        ':end_at'              => $endAt,
        ':event_type'          => $eventType,
        ':status'              => $status,
        ':capacity'            => $capacity,
        ':visibility'          => $visibility,
    ]);

    return (int)$pdo->lastInsertId();
}

function ticketingUpdateEvent(PDO $pdo, array $actor, int $eventId, array $input): array {
    $event = ticketingGetEventById($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found');
    }
    if (!ticketingUserCanManageEvent($actor, $event)) {
        throw new RuntimeException('Forbidden');
    }

    $title = trim((string)($input['title'] ?? $event['title']));
    if ($title === '') {
        throw new InvalidArgumentException('Title is required');
    }

    $startAt = ticketingNormalizeDateTime($input['start_at'] ?? $event['start_at']);
    if ($startAt === null) {
        throw new InvalidArgumentException('start_at is required');
    }

    $endAt = ticketingNormalizeDateTime($input['end_at'] ?? $event['end_at']);
    if ($endAt !== null && strtotime($endAt) < strtotime($startAt)) {
        throw new InvalidArgumentException('end_at must be after start_at');
    }

    $status = (string)($input['status'] ?? $event['status']);
    if (!in_array($status, ['draft', 'published', 'canceled'], true)) {
        throw new InvalidArgumentException('Invalid status');
    }

    $visibility = (string)($input['visibility'] ?? $event['visibility']);
    if (!in_array($visibility, ['public', 'private', 'unlisted'], true)) {
        throw new InvalidArgumentException('Invalid visibility');
    }

    $capacity = array_key_exists('capacity', $input)
        ? (($input['capacity'] === '' || $input['capacity'] === null) ? null : (int)$input['capacity'])
        : $event['capacity'];
    if ($capacity !== null && $capacity < 1) {
        throw new InvalidArgumentException('capacity must be positive');
    }

    // Venue_id: only admin and the owning venue can reassign.
    $venueId = array_key_exists('venue_id', $input) ? (int)$input['venue_id'] : (int)$event['venue_id'];
    if (empty($actor['is_admin']) && ($actor['type'] ?? '') !== 'venue') {
        $venueId = (int)$event['venue_id']; // non-venue creators can't change the venue
    }
    if ($venueId <= 0 || !ticketingEnsureVenueExists($pdo, $venueId)) {
        throw new InvalidArgumentException('Invalid venue');
    }

    $doorsAt  = ticketingNormalizeDateTime($input['doors_at']  ?? $event['doors_at']  ?? null);

    $slug = $event['slug'];
    if (isset($input['slug']) && trim((string)$input['slug']) !== '') {
        $slug = ticketingEnsureUniqueEventSlug($pdo, ticketingSlugify((string)$input['slug']), $eventId);
    }

    $stmt = $pdo->prepare("
        UPDATE events
        SET
          venue_id    = :venue_id,
          title       = :title,
          slug        = :slug,
          description = :description,
          doors_at    = :doors_at,
          start_at    = :start_at,
          end_at      = :end_at,
          status      = :status,
          capacity    = :capacity,
          visibility  = :visibility,
          updated_at  = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $stmt->execute([
        ':venue_id'    => $venueId,
        ':title'       => $title,
        ':slug'        => $slug,
        ':description' => trim((string)($input['description'] ?? $event['description'])),
        ':doors_at'    => $doorsAt,
        ':start_at'    => $startAt,
        ':end_at'      => $endAt,
        ':status'      => $status,
        ':capacity'    => $capacity,
        ':visibility'  => $visibility,
        ':id'          => $eventId,
    ]);

    return ticketingGetEventById($pdo, $eventId) ?: $event;
}

function ticketingGetTicketTypes(PDO $pdo, int $eventId, bool $includeInactive = true): array {
    $sql = 'SELECT * FROM ticket_types WHERE event_id = :event_id';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY created_at ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['event_id'] = (int)$row['event_id'];
        $row['price_cents'] = (int)$row['price_cents'];
        $row['quantity_available'] = (int)$row['quantity_available'];
        $row['quantity_sold'] = (int)$row['quantity_sold'];
        $row['max_per_order'] = (int)$row['max_per_order'];
        $row['is_active'] = (int)$row['is_active'];
        $row['quantity_remaining'] = max(0, $row['quantity_available'] - $row['quantity_sold']);
    }
    unset($row);

    return $rows;
}

function ticketingCreateTicketType(PDO $pdo, array $actor, int $eventId, array $input): int {
    $event = ticketingGetEventById($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found');
    }
    if (!ticketingUserCanManageEvent($actor, $event)) {
        throw new RuntimeException('Forbidden');
    }

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Ticket type name is required');
    }

    $priceCents = isset($input['price_cents']) ? (int)$input['price_cents'] : 0;
    $quantityAvailable = isset($input['quantity_available']) ? (int)$input['quantity_available'] : 0;
    $maxPerOrder = isset($input['max_per_order']) ? (int)$input['max_per_order'] : 10;
    $isActive = !empty($input['is_active']) ? 1 : 0;

    if ($priceCents < 0) {
        throw new InvalidArgumentException('price_cents must be >= 0');
    }
    if ($quantityAvailable < 0) {
        throw new InvalidArgumentException('quantity_available must be >= 0');
    }
    if ($maxPerOrder < 1 || $maxPerOrder > 50) {
        throw new InvalidArgumentException('max_per_order must be between 1 and 50');
    }

    $salesStart = ticketingNormalizeDateTime($input['sales_start'] ?? null);
    $salesEnd = ticketingNormalizeDateTime($input['sales_end'] ?? null);
    if ($salesStart !== null && $salesEnd !== null && strtotime($salesEnd) < strtotime($salesStart)) {
        throw new InvalidArgumentException('sales_end must be after sales_start');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO ticket_types\n          (event_id, name, description, price_cents, quantity_available, quantity_sold, sales_start, sales_end, max_per_order, is_active, created_at, updated_at)\n        VALUES\n          (:event_id, :name, :description, :price_cents, :quantity_available, 0, :sales_start, :sales_end, :max_per_order, :is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':name' => $name,
        ':description' => trim((string)($input['description'] ?? '')),
        ':price_cents' => $priceCents,
        ':quantity_available' => $quantityAvailable,
        ':sales_start' => $salesStart,
        ':sales_end' => $salesEnd,
        ':max_per_order' => $maxPerOrder,
        ':is_active' => $isActive,
    ]);

    return (int)$pdo->lastInsertId();
}

function ticketingUpdateTicketType(PDO $pdo, array $actor, int $ticketTypeId, array $input): array {
    $stmt = $pdo->prepare('SELECT * FROM ticket_types WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $ticketTypeId]);
    $tt = $stmt->fetch();
    if (!$tt) {
        throw new RuntimeException('Ticket type not found');
    }

    $event = ticketingGetEventById($pdo, (int)$tt['event_id']);
    if (!$event) {
        throw new RuntimeException('Event not found');
    }
    if (!ticketingUserCanManageEvent($actor, $event)) {
        throw new RuntimeException('Forbidden');
    }

    $name = trim((string)($input['name'] ?? $tt['name']));
    if ($name === '') {
        throw new InvalidArgumentException('Ticket type name is required');
    }

    $priceCents = array_key_exists('price_cents', $input) ? (int)$input['price_cents'] : (int)$tt['price_cents'];
    $quantityAvailable = array_key_exists('quantity_available', $input) ? (int)$input['quantity_available'] : (int)$tt['quantity_available'];
    $quantitySold = (int)$tt['quantity_sold'];
    $maxPerOrder = array_key_exists('max_per_order', $input) ? (int)$input['max_per_order'] : (int)$tt['max_per_order'];
    $isActive = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int)$tt['is_active'];

    if ($priceCents < 0) {
        throw new InvalidArgumentException('price_cents must be >= 0');
    }
    if ($quantityAvailable < $quantitySold) {
        throw new InvalidArgumentException('quantity_available cannot be lower than quantity_sold');
    }
    if ($maxPerOrder < 1 || $maxPerOrder > 50) {
        throw new InvalidArgumentException('max_per_order must be between 1 and 50');
    }

    $salesStart = array_key_exists('sales_start', $input)
        ? ticketingNormalizeDateTime($input['sales_start'])
        : ticketingNormalizeDateTime($tt['sales_start']);
    $salesEnd = array_key_exists('sales_end', $input)
        ? ticketingNormalizeDateTime($input['sales_end'])
        : ticketingNormalizeDateTime($tt['sales_end']);
    if ($salesStart !== null && $salesEnd !== null && strtotime($salesEnd) < strtotime($salesStart)) {
        throw new InvalidArgumentException('sales_end must be after sales_start');
    }

    $update = $pdo->prepare("\n        UPDATE ticket_types\n        SET\n          name = :name,\n          description = :description,\n          price_cents = :price_cents,\n          quantity_available = :quantity_available,\n          sales_start = :sales_start,\n          sales_end = :sales_end,\n          max_per_order = :max_per_order,\n          is_active = :is_active,\n          updated_at = CURRENT_TIMESTAMP\n        WHERE id = :id\n    ");

    $update->execute([
        ':name' => $name,
        ':description' => trim((string)($input['description'] ?? $tt['description'] ?? '')),
        ':price_cents' => $priceCents,
        ':quantity_available' => $quantityAvailable,
        ':sales_start' => $salesStart,
        ':sales_end' => $salesEnd,
        ':max_per_order' => $maxPerOrder,
        ':is_active' => $isActive,
        ':id' => $ticketTypeId,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM ticket_types WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $ticketTypeId]);
    $row = $stmt->fetch() ?: $tt;
    $row['id'] = (int)$row['id'];
    $row['event_id'] = (int)$row['event_id'];
    $row['price_cents'] = (int)$row['price_cents'];
    $row['quantity_available'] = (int)$row['quantity_available'];
    $row['quantity_sold'] = (int)$row['quantity_sold'];
    $row['max_per_order'] = (int)$row['max_per_order'];
    $row['is_active'] = (int)$row['is_active'];

    return $row;
}

function ticketingGetEventSummary(PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare("\n        SELECT\n          (SELECT COUNT(*) FROM orders WHERE event_id = :event_id AND status = 'paid') AS paid_orders,\n          (SELECT COUNT(*) FROM tickets WHERE event_id = :event_id) AS tickets_total,\n          (SELECT COUNT(*) FROM tickets WHERE event_id = :event_id AND status = 'checked_in') AS tickets_checked_in\n    ");
    $stmt->execute([':event_id' => $eventId]);
    $row = $stmt->fetch() ?: ['paid_orders' => 0, 'tickets_total' => 0, 'tickets_checked_in' => 0];

    return [
        'paid_orders' => (int)$row['paid_orders'],
        'tickets_total' => (int)$row['tickets_total'],
        'tickets_checked_in' => (int)$row['tickets_checked_in'],
    ];
}

function ticketingGetPublicEventBySlug(PDO $pdo, string $slug): ?array {
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    $stmt = $pdo->prepare("\n        SELECT e.*, p.data AS venue_profile_json\n        FROM events e\n        LEFT JOIN profiles p ON p.user_id = e.venue_id\n        WHERE e.slug = :slug\n          AND e.status = 'published'\n          AND e.visibility IN ('public','unlisted')\n        LIMIT 1\n    ");
    $stmt->execute([':slug' => $slug]);
    $event = $stmt->fetch();
    if (!$event) {
        return null;
    }

    $profile = json_decode($event['venue_profile_json'] ?? '{}', true) ?: [];
    $event['id'] = (int)$event['id'];
    $event['venue_id'] = (int)$event['venue_id'];
    $event['capacity'] = $event['capacity'] !== null ? (int)$event['capacity'] : null;
    $event['venue_name'] = trim((string)($profile['name'] ?? 'Venue'));

    return $event;
}

function ticketingIsTypeOnSale(array $ticketType, int $quantity): bool {
    if ((int)$ticketType['is_active'] !== 1) {
        return false;
    }

    if ($quantity < 1 || $quantity > (int)$ticketType['max_per_order']) {
        return false;
    }

    $remaining = (int)$ticketType['quantity_available'] - (int)$ticketType['quantity_sold'];
    if ($quantity > $remaining) {
        return false;
    }

    $now = time();
    $salesStart = $ticketType['sales_start'] ? strtotime((string)$ticketType['sales_start']) : null;
    $salesEnd = $ticketType['sales_end'] ? strtotime((string)$ticketType['sales_end']) : null;

    if ($salesStart !== null && $salesStart > $now) {
        return false;
    }
    if ($salesEnd !== null && $salesEnd < $now) {
        return false;
    }

    return true;
}

function ticketingCreateOrder(PDO $pdo, array $input): array {
    $eventId = isset($input['event_id']) ? (int)$input['event_id'] : 0;
    $buyerName = trim((string)($input['buyer_name'] ?? ''));
    $buyerEmail = ticketingNormalizeEmail((string)($input['buyer_email'] ?? ''));
    $itemsInput = is_array($input['items'] ?? null) ? $input['items'] : [];
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;

    if ($eventId <= 0) {
        throw new InvalidArgumentException('event_id is required');
    }
    if ($buyerName === '') {
        throw new InvalidArgumentException('buyer_name is required');
    }
    if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('buyer_email is invalid');
    }
    if (empty($itemsInput)) {
        throw new InvalidArgumentException('At least one ticket selection is required');
    }

    $event = ticketingGetPublicEventBySlug($pdo, (string)($input['event_slug'] ?? ''));
    if ($event === null) {
        // Fallback by ID for API calls.
        $eventStmt = $pdo->prepare("SELECT * FROM events WHERE id = :id LIMIT 1");
        $eventStmt->execute([':id' => $eventId]);
        $event = $eventStmt->fetch() ?: null;
    }

    if (!$event || (int)$event['id'] !== $eventId) {
        throw new RuntimeException('Event not available');
    }

    if (($event['status'] ?? '') !== 'published') {
        throw new RuntimeException('Event is not on sale');
    }
    if (!in_array((string)($event['visibility'] ?? 'public'), ['public', 'unlisted'], true)) {
        throw new RuntimeException('Event is not publicly available');
    }

    $quantities = [];
    foreach ($itemsInput as $item) {
        $ticketTypeId = (int)($item['ticket_type_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($ticketTypeId <= 0 || $quantity <= 0) {
            continue;
        }
        if (!isset($quantities[$ticketTypeId])) {
            $quantities[$ticketTypeId] = 0;
        }
        $quantities[$ticketTypeId] += $quantity;
    }

    if (empty($quantities)) {
        throw new InvalidArgumentException('No valid ticket quantity selected');
    }

    $typeIds = array_keys($quantities);
    $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
    $typeStmt = $pdo->prepare("SELECT * FROM ticket_types WHERE event_id = ? AND id IN ({$placeholders})");
    $typeStmt->execute(array_merge([$eventId], $typeIds));
    $typeRows = $typeStmt->fetchAll();

    $typesById = [];
    foreach ($typeRows as $row) {
        $typesById[(int)$row['id']] = $row;
    }

    $orderItems = [];
    $totalCents = 0;
    foreach ($quantities as $ticketTypeId => $quantity) {
        if (!isset($typesById[$ticketTypeId])) {
            throw new RuntimeException('Selected ticket type does not exist');
        }

        $tt = $typesById[$ticketTypeId];
        if (!ticketingIsTypeOnSale($tt, $quantity)) {
            throw new RuntimeException('Ticket type unavailable or quantity exceeds limits');
        }

        $unit = (int)$tt['price_cents'];
        $lineTotal = $unit * $quantity;
        $totalCents += $lineTotal;

        $orderItems[] = [
            'ticket_type_id' => $ticketTypeId,
            'quantity' => $quantity,
            'unit_price_cents' => $unit,
            'line_total_cents' => $lineTotal,
        ];
    }

    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare("\n            INSERT INTO orders\n              (event_id, user_id, buyer_name, buyer_email, total_cents, currency, status, payment_provider, payment_reference, created_at, updated_at)\n            VALUES\n              (:event_id, :user_id, :buyer_name, :buyer_email, :total_cents, 'USD', 'pending', NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n        ");
        $orderStmt->execute([
            ':event_id' => $eventId,
            ':user_id' => $userId,
            ':buyer_name' => $buyerName,
            ':buyer_email' => $buyerEmail,
            ':total_cents' => $totalCents,
        ]);

        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("\n            INSERT INTO order_items\n              (order_id, ticket_type_id, quantity, unit_price_cents, line_total_cents, created_at)\n            VALUES\n              (:order_id, :ticket_type_id, :quantity, :unit_price_cents, :line_total_cents, CURRENT_TIMESTAMP)\n        ");

        foreach ($orderItems as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':ticket_type_id' => $item['ticket_type_id'],
                ':quantity' => $item['quantity'],
                ':unit_price_cents' => $item['unit_price_cents'],
                ':line_total_cents' => $item['line_total_cents'],
            ]);
        }

        $pdo->commit();

        return [
            'order_id' => $orderId,
            'event_id' => $eventId,
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'total_cents' => $totalCents,
            'status' => 'pending',
            'items' => $orderItems,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ticketingLog('create_order_failed', ['error' => $e->getMessage(), 'event_id' => $eventId]);
        throw $e;
    }
}

function ticketingGenerateQrToken(PDO $pdo): string {
    for ($i = 0; $i < 10; $i++) {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $check = $pdo->prepare('SELECT id FROM tickets WHERE qr_token = :token LIMIT 1');
        $check->execute([':token' => $token]);
        if (!$check->fetch()) {
            return $token;
        }
    }
    throw new RuntimeException('Could not generate unique qr token');
}

function ticketingGenerateShortCode(PDO $pdo): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 20; $i++) {
        $bytes = random_bytes(8);
        $code = 'PB-';
        for ($j = 0; $j < 8; $j++) {
            $code .= $alphabet[ord($bytes[$j]) % strlen($alphabet)];
            if ($j === 3) {
                $code .= '-';
            }
        }

        $check = $pdo->prepare('SELECT id FROM tickets WHERE short_code = :code LIMIT 1');
        $check->execute([':code' => $code]);
        if (!$check->fetch()) {
            return $code;
        }
    }

    throw new RuntimeException('Could not generate unique short code');
}

function ticketingMarkOrderPaid(PDO $pdo, int $orderId, string $provider = 'demo', ?string $paymentReference = null): array {
    if ($orderId <= 0) {
        throw new InvalidArgumentException('Invalid order id');
    }

    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $orderStmt->execute([':id' => $orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        if ($order['status'] === 'paid') {
            $pdo->commit();
            return ticketingGetOrderWithTickets($pdo, $orderId, null) ?: ['order_id' => $orderId, 'status' => 'paid'];
        }

        if ($order['status'] !== 'pending') {
            throw new RuntimeException('Order is not payable');
        }

        $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
        $itemStmt->execute([':order_id' => $orderId]);
        $items = $itemStmt->fetchAll();
        if (!$items) {
            throw new RuntimeException('Order has no items');
        }

        $totalQty = 0;
        foreach ($items as $item) {
            $totalQty += (int)$item['quantity'];
        }

        $capacityStmt = $pdo->prepare('SELECT capacity FROM events WHERE id = :event_id LIMIT 1');
        $capacityStmt->execute([':event_id' => (int)$order['event_id']]);
        $capacityRow = $capacityStmt->fetch();
        if ($capacityRow && $capacityRow['capacity'] !== null) {
            $capacity = (int)$capacityRow['capacity'];
            if ($capacity > 0) {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE event_id = :event_id AND status IN ('valid','checked_in')");
                $countStmt->execute([':event_id' => (int)$order['event_id']]);
                $currentSold = (int)$countStmt->fetchColumn();
                if ($currentSold + $totalQty > $capacity) {
                    throw new RuntimeException('Event capacity reached');
                }
            }
        }

        $inventoryStmt = $pdo->prepare("\n            UPDATE ticket_types\n            SET quantity_sold = quantity_sold + :qty, updated_at = CURRENT_TIMESTAMP\n            WHERE id = :ticket_type_id\n              AND event_id = :event_id\n              AND is_active = 1\n              AND quantity_sold + :qty <= quantity_available\n        ");

        foreach ($items as $item) {
            $inventoryStmt->execute([
                ':qty' => (int)$item['quantity'],
                ':ticket_type_id' => (int)$item['ticket_type_id'],
                ':event_id' => (int)$order['event_id'],
            ]);
            if ($inventoryStmt->rowCount() !== 1) {
                throw new RuntimeException('Inventory unavailable for one or more ticket types');
            }
        }

        $updateOrder = $pdo->prepare("\n            UPDATE orders\n            SET status = 'paid',\n                payment_provider = :provider,\n                payment_reference = :payment_reference,\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = :order_id\n        ");
        $updateOrder->execute([
            ':provider' => $provider,
            ':payment_reference' => $paymentReference,
            ':order_id' => $orderId,
        ]);

        $insertTicket = $pdo->prepare("\n            INSERT INTO tickets\n              (order_id, order_item_id, event_id, ticket_type_id, user_id, attendee_name, attendee_email, qr_token, short_code, status, checked_in_at, created_at, updated_at)\n            VALUES\n              (:order_id, :order_item_id, :event_id, :ticket_type_id, :user_id, :attendee_name, :attendee_email, :qr_token, :short_code, 'valid', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)\n        ");

        foreach ($items as $item) {
            $qty = (int)$item['quantity'];
            for ($i = 0; $i < $qty; $i++) {
                $insertTicket->execute([
                    ':order_id' => $orderId,
                    ':order_item_id' => (int)$item['id'],
                    ':event_id' => (int)$order['event_id'],
                    ':ticket_type_id' => (int)$item['ticket_type_id'],
                    ':user_id' => $order['user_id'] !== null ? (int)$order['user_id'] : null,
                    ':attendee_name' => $order['buyer_name'],
                    ':attendee_email' => $order['buyer_email'],
                    ':qr_token' => ticketingGenerateQrToken($pdo),
                    ':short_code' => ticketingGenerateShortCode($pdo),
                ]);
            }
        }

        $pdo->commit();

        return ticketingGetOrderWithTickets($pdo, $orderId, null) ?: ['order_id' => $orderId, 'status' => 'paid'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ticketingLog('mark_order_paid_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        throw $e;
    }
}

function ticketingBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function ticketingValidationUrl(string $qrToken, int $eventId): string {
    return ticketingBaseUrl() . '/app/checkin.php?event_id=' . $eventId . '&token=' . rawurlencode($qrToken);
}

function ticketingGetOrderWithTickets(PDO $pdo, int $orderId, ?string $buyerEmail = null): ?array {
    $stmt = $pdo->prepare("\n        SELECT o.*, e.title AS event_title, e.slug AS event_slug, e.start_at, e.end_at, e.venue_id, p.data AS venue_profile_json\n        FROM orders o\n        JOIN events e ON e.id = o.event_id\n        LEFT JOIN profiles p ON p.user_id = e.venue_id\n        WHERE o.id = :id\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    if ($buyerEmail !== null && ticketingNormalizeEmail((string)$order['buyer_email']) !== ticketingNormalizeEmail($buyerEmail)) {
        return null;
    }

    $profile = json_decode($order['venue_profile_json'] ?? '{}', true) ?: [];

    $ticketStmt = $pdo->prepare("\n        SELECT t.*, tt.name AS ticket_type_name\n        FROM tickets t\n        JOIN ticket_types tt ON tt.id = t.ticket_type_id\n        WHERE t.order_id = :order_id\n        ORDER BY t.id ASC\n    ");
    $ticketStmt->execute([':order_id' => $orderId]);
    $tickets = $ticketStmt->fetchAll();

    foreach ($tickets as &$ticket) {
        $ticket['id'] = (int)$ticket['id'];
        $ticket['event_id'] = (int)$ticket['event_id'];
        $ticket['ticket_type_id'] = (int)$ticket['ticket_type_id'];
        $ticket['validation_url'] = ticketingValidationUrl($ticket['qr_token'], (int)$ticket['event_id']);
    }
    unset($ticket);

    $order['id'] = (int)$order['id'];
    $order['event_id'] = (int)$order['event_id'];
    $order['total_cents'] = (int)$order['total_cents'];
    $order['venue_name'] = trim((string)($profile['name'] ?? 'Venue'));
    $order['tickets'] = $tickets;

    return $order;
}

function ticketingParseScanInput(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        $parts = parse_url($value);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['token'])) {
                return trim((string)$query['token']);
            }
            if (!empty($query['code'])) {
                return strtoupper(trim((string)$query['code']));
            }
        }
    }

    return strtoupper($value);
}

function ticketingFetchTicketForValidation(PDO $pdo, string $tokenOrCode): ?array {
    $raw = trim($tokenOrCode);
    if ($raw === '') {
        return null;
    }

    $stmt = $pdo->prepare("\n        SELECT\n          t.*,\n          o.status AS order_status,\n          o.buyer_name,\n          o.buyer_email,\n          tt.name AS ticket_type_name,\n          e.title AS event_title\n        FROM tickets t\n        JOIN orders o ON o.id = t.order_id\n        JOIN ticket_types tt ON tt.id = t.ticket_type_id\n        JOIN events e ON e.id = t.event_id\n        WHERE t.qr_token = :raw OR t.short_code = :code\n        LIMIT 1\n    ");
    $stmt->execute([
        ':raw' => $raw,
        ':code' => strtoupper($raw),
    ]);

    $ticket = $stmt->fetch();
    if (!$ticket) {
        return null;
    }

    $ticket['id'] = (int)$ticket['id'];
    $ticket['event_id'] = (int)$ticket['event_id'];
    $ticket['ticket_type_id'] = (int)$ticket['ticket_type_id'];

    return $ticket;
}

function ticketingValidateTicket(PDO $pdo, int $eventId, string $scanInput): array {
    $parsed = ticketingParseScanInput($scanInput);
    if ($parsed === '') {
        return [
            'ok' => false,
            'result_status' => 'invalid_input',
            'message' => 'Scan token or code is required',
            'ticket' => null,
        ];
    }

    $ticket = ticketingFetchTicketForValidation($pdo, $parsed);
    if (!$ticket) {
        return [
            'ok' => false,
            'result_status' => 'not_found',
            'message' => 'Ticket not found',
            'ticket' => null,
            'parsed_input' => $parsed,
        ];
    }

    if ((int)$ticket['event_id'] !== $eventId) {
        return [
            'ok' => false,
            'result_status' => 'wrong_event',
            'message' => 'Ticket is for a different event',
            'ticket' => $ticket,
            'parsed_input' => $parsed,
        ];
    }

    if (($ticket['order_status'] ?? '') !== 'paid') {
        return [
            'ok' => false,
            'result_status' => 'order_not_paid',
            'message' => 'Order is not paid',
            'ticket' => $ticket,
            'parsed_input' => $parsed,
        ];
    }

    if (($ticket['status'] ?? '') === 'checked_in' || !empty($ticket['checked_in_at'])) {
        return [
            'ok' => false,
            'result_status' => 'already_checked_in',
            'message' => 'Ticket already checked in',
            'ticket' => $ticket,
            'parsed_input' => $parsed,
        ];
    }

    if (($ticket['status'] ?? '') !== 'valid') {
        return [
            'ok' => false,
            'result_status' => 'invalid_status',
            'message' => 'Ticket is not valid for entry',
            'ticket' => $ticket,
            'parsed_input' => $parsed,
        ];
    }

    return [
        'ok' => true,
        'result_status' => 'valid',
        'message' => 'Ticket is valid',
        'ticket' => $ticket,
        'parsed_input' => $parsed,
    ];
}

function ticketingWriteCheckin(PDO $pdo, ?int $ticketId, int $eventId, int $staffUserId, string $resultStatus, ?string $note = null): void {
    $stmt = $pdo->prepare("\n        INSERT INTO checkins\n          (ticket_id, event_id, checked_in_by_user_id, result_status, note, created_at)\n        VALUES\n          (:ticket_id, :event_id, :checked_in_by_user_id, :result_status, :note, CURRENT_TIMESTAMP)\n    ");

    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':event_id' => $eventId,
        ':checked_in_by_user_id' => $staffUserId,
        ':result_status' => $resultStatus,
        ':note' => $note,
    ]);
}

function ticketingCheckInTicket(PDO $pdo, int $eventId, string $scanInput, int $staffUserId, ?string $note = null): array {
    $validation = ticketingValidateTicket($pdo, $eventId, $scanInput);
    $ticket = $validation['ticket'] ?? null;
    $ticketId = is_array($ticket) ? (int)$ticket['id'] : null;

    $pdo->beginTransaction();
    try {
        if (!$validation['ok']) {
            ticketingWriteCheckin($pdo, $ticketId, $eventId, $staffUserId, (string)$validation['result_status'], $note);
            $pdo->commit();
            return $validation;
        }

        $update = $pdo->prepare("\n            UPDATE tickets\n            SET status = 'checked_in', checked_in_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP\n            WHERE id = :id\n              AND event_id = :event_id\n              AND status = 'valid'\n              AND checked_in_at IS NULL\n        ");
        $update->execute([
            ':id' => $ticketId,
            ':event_id' => $eventId,
        ]);

        if ($update->rowCount() !== 1) {
            ticketingWriteCheckin($pdo, $ticketId, $eventId, $staffUserId, 'already_checked_in', $note);
            $pdo->commit();
            return [
                'ok' => false,
                'result_status' => 'already_checked_in',
                'message' => 'Ticket already checked in',
                'ticket' => $ticket,
            ];
        }

        ticketingWriteCheckin($pdo, $ticketId, $eventId, $staffUserId, 'checked_in', $note);
        $pdo->commit();

        return [
            'ok' => true,
            'result_status' => 'checked_in',
            'message' => 'Ticket checked in successfully',
            'ticket' => $ticket,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ticketingLog('check_in_failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        throw $e;
    }
}

function ticketingSearchTicketByCode(PDO $pdo, int $eventId, string $query): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $like = '%' . strtoupper($query) . '%';
    $stmt = $pdo->prepare("\n        SELECT\n          t.id, t.short_code, t.status, t.checked_in_at,\n          t.attendee_name, t.attendee_email,\n          o.buyer_name, o.buyer_email,\n          tt.name AS ticket_type_name\n        FROM tickets t\n        JOIN orders o ON o.id = t.order_id\n        JOIN ticket_types tt ON tt.id = t.ticket_type_id\n        WHERE t.event_id = :event_id\n          AND (UPPER(t.short_code) LIKE :like OR UPPER(o.buyer_email) LIKE :like OR UPPER(o.buyer_name) LIKE :like)\n        ORDER BY t.created_at DESC\n        LIMIT 20\n    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':like' => $like,
    ]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
    }
    unset($row);

    return $rows;
}

function ticketingReceiptSecret(): string {
    return panicAppKey();
}

function ticketingBuildReceiptToken(int $orderId, string $buyerEmail): string {
    $payload = $orderId . '|' . ticketingNormalizeEmail($buyerEmail);
    return hash_hmac('sha256', $payload, ticketingReceiptSecret());
}

function ticketingVerifyReceiptToken(int $orderId, string $buyerEmail, string $token): bool {
    $expected = ticketingBuildReceiptToken($orderId, $buyerEmail);
    return hash_equals($expected, $token);
}

function ticketingFormatCents(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

// -----------------------------------------------------------------------
// Lineup management
// -----------------------------------------------------------------------

function ticketingGetEventLineup(PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare("
        SELECT el.*,
               p.data  AS profile_json,
               p.type  AS profile_type
        FROM   event_lineup el
        LEFT JOIN profiles p ON p.id = el.profile_id
        WHERE  el.event_id = :event_id
        ORDER  BY el.sort_order ASC, el.id ASC
    ");
    $stmt->execute([':event_id' => $eventId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id']         = (int)$row['id'];
        $row['event_id']   = (int)$row['event_id'];
        $row['profile_id'] = $row['profile_id'] !== null ? (int)$row['profile_id'] : null;
        $row['sort_order'] = (int)$row['sort_order'];
        $profileData = json_decode($row['profile_json'] ?? '{}', true) ?: [];
        $row['act_name']   = trim((string)($profileData['name'] ?? $row['external_name'] ?? ''));
        unset($row['profile_json']);
    }
    unset($row);

    return $rows;
}

/**
 * Replace the entire lineup for an event.
 * $lineup is an array of rows, each with:
 *   profile_id    (int|null)
 *   external_name (string, used when profile_id is null)
 *   billing       (headliner|direct_support|support|opener|special_guest)
 *   set_start     (HH:MM or null)
 *   set_end       (HH:MM or null)
 *   sort_order    (int)
 */
function ticketingSyncEventLineup(PDO $pdo, array $actor, int $eventId, array $lineup): void {
    $event = ticketingGetEventById($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found');
    }
    if (!ticketingUserCanManageEvent($actor, $event)) {
        throw new RuntimeException('Forbidden');
    }

    $validBillings = ['headliner', 'direct_support', 'support', 'opener', 'special_guest'];

    $pdo->prepare("DELETE FROM event_lineup WHERE event_id = ?")->execute([$eventId]);

    $insert = $pdo->prepare("
        INSERT INTO event_lineup
          (event_id, profile_id, external_name, billing, set_start, set_end, sort_order)
        VALUES
          (:event_id, :profile_id, :external_name, :billing, :set_start, :set_end, :sort_order)
    ");

    foreach ($lineup as $i => $act) {
        $profileId   = isset($act['profile_id']) && $act['profile_id'] !== '' ? (int)$act['profile_id'] : null;
        $extName     = trim((string)($act['external_name'] ?? ''));
        $billing     = in_array($act['billing'] ?? '', $validBillings, true) ? $act['billing'] : 'support';
        $setStart    = ticketingNormalizeTime($act['set_start'] ?? null);
        $setEnd      = ticketingNormalizeTime($act['set_end']   ?? null);
        $sortOrder   = (int)($act['sort_order'] ?? $i);

        if ($profileId === null && $extName === '') {
            continue; // skip empty rows
        }

        $insert->execute([
            ':event_id'     => $eventId,
            ':profile_id'   => $profileId,
            ':external_name' => $extName !== '' ? $extName : null,
            ':billing'      => $billing,
            ':set_start'    => $setStart,
            ':set_end'      => $setEnd,
            ':sort_order'   => $sortOrder,
        ]);
    }
}

function ticketingNormalizeTime(?string $value): ?string {
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    // Accept HH:MM or HH:MM:SS; return HH:MM:SS
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }
    return null;
}

/**
 * Create ticket types in bulk from an array of input rows (used by event-edit form).
 * Each row: name, price (dollars string), quantity, max_per_order, description
 */
function ticketingCreateTicketTypes(PDO $pdo, array $actor, int $eventId, array $ticketRows): void {
    foreach ($ticketRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $priceCents = (int)round(((float)($row['price'] ?? 0)) * 100);
        ticketingCreateTicketType($pdo, $actor, $eventId, [
            'name'               => $name,
            'description'        => trim((string)($row['description'] ?? '')),
            'price_cents'        => $priceCents,
            'quantity_available' => max(0, (int)($row['quantity'] ?? 0)),
            'max_per_order'      => max(1, min(50, (int)($row['max_per_order'] ?? 10))),
            'sales_start'        => $row['sales_start'] ?? null,
            'sales_end'          => $row['sales_end']   ?? null,
            'is_active'          => 1,
        ]);
    }
}
