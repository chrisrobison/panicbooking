<?php

require_once __DIR__ . '/../../lib/ticketing.php';

function paymentMode(): string {
    $mode = strtolower(trim((string)(getenv('PB_PAYMENT_MODE') ?: 'demo')));
    if (!in_array($mode, ['demo', 'stripe'], true)) {
        $mode = 'demo';
    }
    return $mode;
}

function paymentConfig(): array {
    $baseUrl = rtrim((string)(getenv('PB_PUBLIC_BASE_URL') ?: ''), '/');
    if ($baseUrl === '') {
        $baseUrl = ticketingBaseUrl();
    }

    return [
        'mode' => paymentMode(),
        'public_base_url' => $baseUrl,
        'stripe_secret_key' => trim((string)(getenv('PB_STRIPE_SECRET_KEY') ?: '')),
        'stripe_publishable_key' => trim((string)(getenv('PB_STRIPE_PUBLISHABLE_KEY') ?: '')),
        'stripe_webhook_secret' => trim((string)(getenv('PB_STRIPE_WEBHOOK_SECRET') ?: '')),
        'stripe_api_base' => rtrim((string)(getenv('PB_STRIPE_API_BASE') ?: 'https://api.stripe.com/v1'), '/'),
        'stripe_webhook_tolerance' => max(60, (int)(getenv('PB_STRIPE_WEBHOOK_TOLERANCE') ?: 300)),
    ];
}

function paymentCreateOrder(PDO $pdo, array $orderInput): array {
    return ticketingCreateOrder($pdo, $orderInput);
}

function paymentFinalizeSuccessfulOrder(PDO $pdo, int $orderId, ?string $paymentReference = null): array {
    $mode = paymentMode();

    if ($mode === 'demo') {
        return ticketingMarkOrderPaid($pdo, $orderId, 'demo', $paymentReference ?: ('demo_' . time()));
    }

    return ticketingMarkOrderPaid($pdo, $orderId, 'stripe', $paymentReference);
}

function paymentOrderById(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['event_id'] = (int)$row['event_id'];
    $row['total_cents'] = (int)$row['total_cents'];
    return $row;
}

function paymentOrderForCheckout(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare("\n        SELECT
            o.*,
            e.title AS event_title,
            e.slug AS event_slug,
            e.start_at AS event_start_at
        FROM orders o
        JOIN events e ON e.id = o.event_id
        WHERE o.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $itemStmt = $pdo->prepare("\n        SELECT oi.*, tt.name AS ticket_type_name
        FROM order_items oi
        JOIN ticket_types tt ON tt.id = oi.ticket_type_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ");
    $itemStmt->execute([':order_id' => $orderId]);
    $items = $itemStmt->fetchAll();

    $order['id'] = (int)$order['id'];
    $order['event_id'] = (int)$order['event_id'];
    $order['total_cents'] = (int)$order['total_cents'];
    $order['items'] = array_map(static function (array $item): array {
        $item['id'] = (int)$item['id'];
        $item['ticket_type_id'] = (int)$item['ticket_type_id'];
        $item['quantity'] = (int)$item['quantity'];
        $item['unit_price_cents'] = (int)$item['unit_price_cents'];
        $item['line_total_cents'] = (int)$item['line_total_cents'];
        return $item;
    }, $items ?: []);

    return $order;
}

function paymentSetOrderProviderReference(PDO $pdo, int $orderId, string $provider, string $reference): void {
    $stmt = $pdo->prepare("\n        UPDATE orders
        SET payment_provider = :provider,
            payment_reference = :reference,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :order_id
    ");
    $stmt->execute([
        ':provider' => $provider,
        ':reference' => $reference,
        ':order_id' => $orderId,
    ]);
}

function paymentStripeEnsureConfigured(): void {
    $cfg = paymentConfig();
    if ($cfg['stripe_secret_key'] === '') {
        throw new RuntimeException('Stripe secret key is not configured');
    }
}

function paymentStripeRequest(string $method, string $path, array $params = []): array {
    paymentStripeEnsureConfigured();
    $cfg = paymentConfig();

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for Stripe API requests');
    }

    $method = strtoupper(trim($method));
    $url = $cfg['stripe_api_base'] . '/' . ltrim($path, '/');

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize Stripe request');
    }

    $headers = [
        'Authorization: Bearer ' . $cfg['stripe_secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Stripe request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stripe returned non-JSON response');
    }

    if ($statusCode >= 400) {
        $message = (string)($decoded['error']['message'] ?? 'Stripe API error');
        throw new RuntimeException($message);
    }

    return $decoded;
}

function paymentStripeBuildCheckoutParams(array $order): array {
    $cfg = paymentConfig();
    $orderId = (int)$order['id'];
    $receipt = ticketingBuildReceiptToken($orderId, (string)$order['buyer_email']);

    $successUrl = $cfg['public_base_url'] . '/checkout-success.php?order_id=' . $orderId . '&receipt=' . urlencode($receipt) . '&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $cfg['public_base_url'] . '/checkout-cancel.php?order_id=' . $orderId . '&receipt=' . urlencode($receipt);

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string)$orderId,
        'customer_email' => (string)$order['buyer_email'],
        'metadata' => [
            'order_id' => (string)$orderId,
            'event_id' => (string)$order['event_id'],
            'event_slug' => (string)($order['event_slug'] ?? ''),
        ],
        'payment_intent_data' => [
            'metadata' => [
                'order_id' => (string)$orderId,
                'event_id' => (string)$order['event_id'],
            ],
        ],
    ];

    $lineItems = [];
    foreach (($order['items'] ?? []) as $item) {
        $lineItems[] = [
            'quantity' => (int)$item['quantity'],
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => (int)$item['unit_price_cents'],
                'product_data' => [
                    'name' => (string)$item['ticket_type_name'],
                    'description' => (string)($order['event_title'] ?? 'Panic Booking Event'),
                ],
            ],
        ];
    }

    if (empty($lineItems)) {
        throw new RuntimeException('Order has no line items');
    }

    $params['line_items'] = $lineItems;
    return $params;
}

function paymentCreateStripeCheckoutSession(PDO $pdo, int $orderId): array {
    $order = paymentOrderForCheckout($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    if (($order['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Order is not pending payment');
    }

    $params = paymentStripeBuildCheckoutParams($order);
    $session = paymentStripeRequest('POST', 'checkout/sessions', $params);

    $sessionId = trim((string)($session['id'] ?? ''));
    $sessionUrl = trim((string)($session['url'] ?? ''));

    if ($sessionId === '' || $sessionUrl === '') {
        throw new RuntimeException('Stripe Checkout session is invalid');
    }

    paymentSetOrderProviderReference($pdo, $orderId, 'stripe', $sessionId);

    return [
        'id' => $sessionId,
        'url' => $sessionUrl,
        'order_id' => $orderId,
    ];
}

function paymentBeginCheckout(PDO $pdo, int $orderId): array {
    if (paymentMode() === 'demo') {
        $order = paymentOrderById($pdo, $orderId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        $finalized = paymentFinalizeSuccessfulOrder($pdo, $orderId, 'demo_' . bin2hex(random_bytes(6)));
        $finalOrderId = (int)($finalized['id'] ?? $orderId);
        $receipt = ticketingBuildReceiptToken($finalOrderId, (string)$order['buyer_email']);

        return [
            'provider' => 'demo',
            'order_id' => $finalOrderId,
            'redirect_url' => '/order-success.php?order_id=' . $finalOrderId . '&receipt=' . urlencode($receipt),
        ];
    }

    $session = paymentCreateStripeCheckoutSession($pdo, $orderId);

    return [
        'provider' => 'stripe',
        'order_id' => $orderId,
        'checkout_session_id' => $session['id'],
        'redirect_url' => $session['url'],
    ];
}

function paymentExtractOrderIdFromStripeObject(array $object): int {
    $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

    $orderId = (int)($metadata['order_id'] ?? 0);
    if ($orderId > 0) {
        return $orderId;
    }

    $clientReference = trim((string)($object['client_reference_id'] ?? ''));
    if (ctype_digit($clientReference)) {
        return (int)$clientReference;
    }

    return 0;
}

function paymentFindOrderIdByStripeReference(PDO $pdo, string $reference): int {
    $reference = trim($reference);
    if ($reference === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id FROM orders WHERE payment_provider = :provider AND payment_reference = :ref LIMIT 1');
    $stmt->execute([
        ':provider' => 'stripe',
        ':ref' => $reference,
    ]);

    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : 0;
}

function paymentMarkPendingOrderStatus(PDO $pdo, int $orderId, string $status, ?string $reference = null): bool {
    if ($orderId <= 0) {
        return false;
    }

    if (!in_array($status, ['failed', 'canceled'], true)) {
        throw new InvalidArgumentException('Invalid order status transition');
    }

    $stmt = $pdo->prepare("\n        UPDATE orders
        SET status = :status,
            payment_provider = 'stripe',
            payment_reference = COALESCE(:reference, payment_reference),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :order_id
          AND status = 'pending'
    ");

    $stmt->execute([
        ':status' => $status,
        ':reference' => $reference,
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() === 1;
}

function paymentApplyRefundHook(PDO $pdo, int $orderId, ?string $reference = null): bool {
    if ($orderId <= 0) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
        $orderStmt->execute([':id' => $orderId]);
        $currentStatus = (string)$orderStmt->fetchColumn();
        if ($currentStatus === '') {
            $pdo->rollBack();
            return false;
        }

        if ($currentStatus !== 'paid' && $currentStatus !== 'refunded') {
            $pdo->rollBack();
            return false;
        }

        $updateOrder = $pdo->prepare("\n            UPDATE orders
            SET status = 'refunded',
                payment_provider = 'stripe',
                payment_reference = COALESCE(:reference, payment_reference),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :order_id
        ");
        $updateOrder->execute([
            ':reference' => $reference,
            ':order_id' => $orderId,
        ]);

        $updateTickets = $pdo->prepare("\n            UPDATE tickets
            SET status = 'refunded',
                updated_at = CURRENT_TIMESTAMP
            WHERE order_id = :order_id
              AND status = 'valid'
        ");
        $updateTickets->execute([':order_id' => $orderId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function paymentStripeParseSignatureHeader(string $header): array {
    $parts = array_filter(array_map('trim', explode(',', $header)));
    $out = ['t' => null, 'v1' => []];

    foreach ($parts as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) {
            continue;
        }
        [$k, $v] = $kv;
        if ($k === 't') {
            $out['t'] = ctype_digit($v) ? (int)$v : null;
        } elseif ($k === 'v1' && $v !== '') {
            $out['v1'][] = $v;
        }
    }

    return $out;
}

function paymentVerifyStripeWebhookSignature(string $payload, string $signatureHeader): bool {
    $cfg = paymentConfig();
    $secret = $cfg['stripe_webhook_secret'];
    if ($secret === '') {
        throw new RuntimeException('Stripe webhook secret is not configured');
    }

    $parsed = paymentStripeParseSignatureHeader($signatureHeader);
    $timestamp = $parsed['t'];
    $signatures = $parsed['v1'];

    if ($timestamp === null || empty($signatures)) {
        return false;
    }

    $age = abs(time() - $timestamp);
    if ($age > (int)$cfg['stripe_webhook_tolerance']) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $candidate) {
        if (hash_equals($expected, $candidate)) {
            return true;
        }
    }

    return false;
}

function paymentIsDuplicateKeyException(PDOException $e): bool {
    $code = (string)$e->getCode();
    $message = strtolower($e->getMessage());

    if ($code === '23000') {
        return true;
    }

    return str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry');
}

function paymentLoadWebhookEvent(PDO $pdo, string $provider, string $eventId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM payment_webhook_events WHERE provider = :provider AND event_id = :event_id LIMIT 1');
    $stmt->execute([
        ':provider' => $provider,
        ':event_id' => $eventId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function paymentAcquireWebhookEvent(PDO $pdo, string $provider, string $eventId, string $eventType, string $payloadHash): array {
    try {
        $insert = $pdo->prepare("\n            INSERT INTO payment_webhook_events
              (provider, event_id, event_type, payload_hash, status, related_order_id, note, created_at, updated_at, processed_at)
            VALUES
              (:provider, :event_id, :event_type, :payload_hash, 'processing', NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL)
        ");
        $insert->execute([
            ':provider' => $provider,
            ':event_id' => $eventId,
            ':event_type' => $eventType,
            ':payload_hash' => $payloadHash,
        ]);

        return [
            'row' => paymentLoadWebhookEvent($pdo, $provider, $eventId),
            'should_process' => true,
        ];
    } catch (PDOException $e) {
        if (!paymentIsDuplicateKeyException($e)) {
            throw $e;
        }
    }

    $existing = paymentLoadWebhookEvent($pdo, $provider, $eventId);
    if (!$existing) {
        throw new RuntimeException('Unable to load webhook event state');
    }

    if (($existing['payload_hash'] ?? '') !== $payloadHash) {
        ticketingLog('stripe_webhook_payload_hash_mismatch', [
            'event_id' => $eventId,
            'provider' => $provider,
        ]);
    }

    if (in_array((string)$existing['status'], ['processed', 'ignored'], true)) {
        return ['row' => $existing, 'should_process' => false];
    }

    $update = $pdo->prepare("\n        UPDATE payment_webhook_events
        SET event_type = :event_type,
            payload_hash = :payload_hash,
            status = 'processing',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $update->execute([
        ':event_type' => $eventType,
        ':payload_hash' => $payloadHash,
        ':id' => (int)$existing['id'],
    ]);

    $reloaded = paymentLoadWebhookEvent($pdo, $provider, $eventId);
    return ['row' => $reloaded, 'should_process' => true];
}

function paymentCompleteWebhookEvent(PDO $pdo, int $eventRowId, string $status, ?int $orderId = null, ?string $note = null): void {
    if (!in_array($status, ['processed', 'ignored', 'error'], true)) {
        throw new InvalidArgumentException('Invalid webhook completion status');
    }

    $stmt = $pdo->prepare("\n        UPDATE payment_webhook_events
        SET status = :status,
            related_order_id = COALESCE(:order_id, related_order_id),
            note = :note,
            updated_at = CURRENT_TIMESTAMP,
            processed_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $status,
        ':order_id' => $orderId,
        ':note' => $note,
        ':id' => $eventRowId,
    ]);
}

function paymentProcessStripeEvent(PDO $pdo, array $event): array {
    $type = (string)($event['type'] ?? '');
    $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
    $orderId = paymentExtractOrderIdFromStripeObject($object);

    if ($type === 'checkout.session.completed') {
        $paymentStatus = (string)($object['payment_status'] ?? '');
        if ($paymentStatus !== 'paid') {
            return ['status' => 'ignored', 'order_id' => $orderId ?: null, 'note' => 'session_not_paid'];
        }

        if ($orderId <= 0) {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'missing_order_id'];
        }

        $reference = trim((string)($object['payment_intent'] ?? ''));
        if ($reference === '') {
            $reference = trim((string)($object['id'] ?? ''));
        }

        try {
            paymentFinalizeSuccessfulOrder($pdo, $orderId, $reference !== '' ? $reference : null);
            return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'paid'];
        } catch (RuntimeException $e) {
            $message = (string)$e->getMessage();
            $knownHardFailures = [
                'Inventory unavailable for one or more ticket types',
                'Event capacity reached',
                'Order has no items',
                'Order is not payable',
                'Order not found',
            ];

            if (in_array($message, $knownHardFailures, true)) {
                paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $reference !== '' ? $reference : null);
                ticketingLog('stripe_paid_order_finalization_failed', [
                    'order_id' => $orderId,
                    'error' => $message,
                ]);
                return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'finalization_failed_manual_refund_needed'];
            }

            throw $e;
        }
    }

    if ($type === 'checkout.session.async_payment_failed') {
        $sessionId = trim((string)($object['id'] ?? ''));
        if ($orderId <= 0 && $sessionId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $sessionId);
        }
        if ($orderId > 0) {
            paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $sessionId !== '' ? $sessionId : null);
        }
        return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'async_payment_failed'];
    }

    if ($type === 'checkout.session.expired') {
        $sessionId = trim((string)($object['id'] ?? ''));
        if ($orderId <= 0 && $sessionId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $sessionId);
        }
        if ($orderId > 0) {
            paymentMarkPendingOrderStatus($pdo, $orderId, 'canceled', $sessionId !== '' ? $sessionId : null);
        }
        return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'session_expired'];
    }

    if ($type === 'payment_intent.payment_failed') {
        $intentId = trim((string)($object['id'] ?? ''));
        if ($orderId <= 0 && $intentId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $intentId);
        }
        if ($orderId > 0) {
            paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $intentId !== '' ? $intentId : null);
        }
        return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'payment_intent_failed'];
    }

    if ($type === 'charge.refunded') {
        $isFullyRefunded = !empty($object['refunded']);
        if (!$isFullyRefunded) {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'partial_refund_ignored'];
        }

        $chargeId = trim((string)($object['id'] ?? ''));
        $intentId = trim((string)($object['payment_intent'] ?? ''));

        if ($orderId <= 0 && $intentId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $intentId);
        }

        if ($orderId > 0) {
            paymentApplyRefundHook($pdo, $orderId, $intentId !== '' ? $intentId : ($chargeId !== '' ? $chargeId : null));
            return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'refunded'];
        }

        return ['status' => 'ignored', 'order_id' => null, 'note' => 'refund_missing_order_id'];
    }

    return ['status' => 'ignored', 'order_id' => $orderId ?: null, 'note' => 'unhandled_event_type'];
}

function paymentHandleStripeWebhook(PDO $pdo, string $payload, string $signatureHeader): array {
    if (paymentMode() !== 'stripe') {
        throw new RuntimeException('Stripe webhook received while payment mode is not stripe');
    }

    if (!paymentVerifyStripeWebhookSignature($payload, $signatureHeader)) {
        throw new RuntimeException('Stripe signature verification failed');
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new RuntimeException('Invalid Stripe webhook payload');
    }

    $eventId = trim((string)($event['id'] ?? ''));
    $eventType = trim((string)($event['type'] ?? ''));
    if ($eventId === '' || $eventType === '') {
        throw new RuntimeException('Stripe webhook missing id or type');
    }

    $payloadHash = hash('sha256', $payload);
    $acquired = paymentAcquireWebhookEvent($pdo, 'stripe', $eventId, $eventType, $payloadHash);
    $eventRow = $acquired['row'] ?? null;

    if (!$eventRow || empty($eventRow['id'])) {
        throw new RuntimeException('Webhook event state not available');
    }

    if (!$acquired['should_process']) {
        return [
            'ok' => true,
            'duplicate' => true,
            'event_id' => $eventId,
            'event_type' => $eventType,
        ];
    }

    $eventRowId = (int)$eventRow['id'];

    try {
        $result = paymentProcessStripeEvent($pdo, $event);
        $status = (string)($result['status'] ?? 'ignored');
        $orderId = isset($result['order_id']) ? (int)$result['order_id'] : null;
        $note = isset($result['note']) ? (string)$result['note'] : null;

        paymentCompleteWebhookEvent($pdo, $eventRowId, $status, $orderId, $note);

        ticketingLog('stripe_webhook_processed', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'order_id' => $orderId,
            'note' => $note,
        ]);

        return [
            'ok' => true,
            'duplicate' => false,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'order_id' => $orderId,
        ];
    } catch (Throwable $e) {
        paymentCompleteWebhookEvent($pdo, $eventRowId, 'error', null, $e->getMessage());
        throw $e;
    }
}
