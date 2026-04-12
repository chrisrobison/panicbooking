<?php

require_once __DIR__ . '/../../lib/ticketing.php';

function paymentNormalizeProvider(string $provider): string {
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['demo', 'stripe', 'square'], true)) {
        return 'demo';
    }
    return $provider;
}

function paymentProvider(): string {
    $raw = (string)(panicEnv('PB_PAYMENT_PROVIDER', panicEnv('PB_PAYMENT_MODE', 'demo')) ?? 'demo');
    return paymentNormalizeProvider($raw);
}

// Backward-compatible name used elsewhere in the codebase.
function paymentMode(): string {
    return paymentProvider();
}

function paymentConfig(): array {
    $baseUrl = rtrim((string)panicPublicBaseUrl(), '/');
    if ($baseUrl === '') {
        $baseUrl = ticketingBaseUrl();
    }

    return [
        'provider' => paymentProvider(),
        'mode' => paymentProvider(),
        'public_base_url' => $baseUrl,
        'stripe_secret_key' => trim((string)panicEnv('PB_STRIPE_SECRET_KEY', '')),
        'stripe_publishable_key' => trim((string)panicEnv('PB_STRIPE_PUBLISHABLE_KEY', '')),
        'stripe_webhook_secret' => trim((string)panicEnv('PB_STRIPE_WEBHOOK_SECRET', '')),
        'stripe_api_base' => rtrim((string)panicEnv('PB_STRIPE_API_BASE', 'https://api.stripe.com/v1'), '/'),
        'stripe_webhook_tolerance' => max(60, (int)panicEnv('PB_STRIPE_WEBHOOK_TOLERANCE', '300')),
        'square_access_token' => trim((string)panicEnv('PB_SQUARE_ACCESS_TOKEN', '')),
        'square_application_id' => trim((string)panicEnv('PB_SQUARE_APPLICATION_ID', '')),
        'square_location_id' => trim((string)panicEnv('PB_SQUARE_LOCATION_ID', '')),
        'square_webhook_signature_key' => trim((string)panicEnv('PB_SQUARE_WEBHOOK_SIGNATURE_KEY', '')),
        'square_api_base' => rtrim((string)panicEnv('PB_SQUARE_API_BASE', 'https://connect.squareup.com'), '/'),
        'square_api_version' => trim((string)panicEnv('PB_SQUARE_API_VERSION', '')),
        'square_webhook_url' => trim((string)panicEnv('PB_SQUARE_WEBHOOK_URL', $baseUrl . '/square-webhook.php')),
    ];
}

function paymentCreateOrder(PDO $pdo, array $orderInput): array {
    return ticketingCreateOrder($pdo, $orderInput);
}

function paymentFinalizeSuccessfulOrder(PDO $pdo, int $orderId, ?string $paymentReference = null, ?string $provider = null): array {
    $resolvedProvider = $provider !== null
        ? paymentNormalizeProvider($provider)
        : paymentProvider();

    if ($resolvedProvider === 'demo') {
        return ticketingMarkOrderPaid($pdo, $orderId, 'demo', $paymentReference ?: ('demo_' . time()));
    }

    return ticketingMarkOrderPaid($pdo, $orderId, $resolvedProvider, $paymentReference);
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
    $provider = paymentNormalizeProvider($provider);

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

function paymentFindOrderIdByProviderReference(PDO $pdo, string $provider, string $reference): int {
    $provider = paymentNormalizeProvider($provider);
    $reference = trim($reference);
    if ($reference === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id FROM orders WHERE payment_provider = :provider AND payment_reference = :ref LIMIT 1');
    $stmt->execute([
        ':provider' => $provider,
        ':ref' => $reference,
    ]);

    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : 0;
}

function paymentFindOrderIdByStripeReference(PDO $pdo, string $reference): int {
    return paymentFindOrderIdByProviderReference($pdo, 'stripe', $reference);
}

function paymentFindOrderIdBySquareReference(PDO $pdo, string $reference): int {
    return paymentFindOrderIdByProviderReference($pdo, 'square', $reference);
}

function paymentMarkPendingOrderStatus(PDO $pdo, int $orderId, string $status, ?string $reference = null, string $provider = 'stripe'): bool {
    if ($orderId <= 0) {
        return false;
    }

    if (!in_array($status, ['failed', 'canceled'], true)) {
        throw new InvalidArgumentException('Invalid order status transition');
    }

    $provider = paymentNormalizeProvider($provider);

    $stmt = $pdo->prepare("\n        UPDATE orders
        SET status = :status,
            payment_provider = :provider,
            payment_reference = COALESCE(:reference, payment_reference),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :order_id
          AND status = 'pending'
    ");

    $stmt->execute([
        ':status' => $status,
        ':provider' => $provider,
        ':reference' => $reference,
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() === 1;
}

function paymentApplyRefundHook(PDO $pdo, int $orderId, ?string $reference = null, string $provider = 'stripe'): bool {
    if ($orderId <= 0) {
        return false;
    }

    $provider = paymentNormalizeProvider($provider);

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
                payment_provider = :provider,
                payment_reference = COALESCE(:reference, payment_reference),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :order_id
        ");
        $updateOrder->execute([
            ':provider' => $provider,
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

function paymentSquareEnsureConfigured(): void {
    $cfg = paymentConfig();
    if ($cfg['square_access_token'] === '') {
        throw new RuntimeException('Square access token is not configured');
    }
    if ($cfg['square_location_id'] === '') {
        throw new RuntimeException('Square location id is not configured');
    }
}

function paymentSquareRequest(string $method, string $path, ?array $payload = null): array {
    paymentSquareEnsureConfigured();
    $cfg = paymentConfig();

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for Square API requests');
    }

    $method = strtoupper(trim($method));
    $url = $cfg['square_api_base'] . '/' . ltrim($path, '/');

    $headers = [
        'Authorization: Bearer ' . $cfg['square_access_token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if ($cfg['square_api_version'] !== '') {
        $headers[] = 'Square-Version: ' . $cfg['square_api_version'];
    }

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize Square request');
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Square request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Square returned non-JSON response');
    }

    if ($statusCode >= 400) {
        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        $message = 'Square API error';
        if (!empty($errors[0]['detail'])) {
            $message = (string)$errors[0]['detail'];
        }
        throw new RuntimeException($message);
    }

    return $decoded;
}

function paymentSquareBuildCheckoutPayload(array $order): array {
    $cfg = paymentConfig();
    $orderId = (int)$order['id'];
    $receipt = ticketingBuildReceiptToken($orderId, (string)$order['buyer_email']);
    $successUrl = $cfg['public_base_url'] . '/checkout-success.php?order_id=' . $orderId . '&receipt=' . urlencode($receipt);

    $lineItems = [];
    foreach (($order['items'] ?? []) as $item) {
        $lineItems[] = [
            'name' => (string)$item['ticket_type_name'],
            'quantity' => (string)((int)$item['quantity']),
            'base_price_money' => [
                'amount' => (int)$item['unit_price_cents'],
                'currency' => 'USD',
            ],
            'note' => (string)($order['event_title'] ?? 'Panic Booking Event'),
        ];
    }

    if (empty($lineItems)) {
        throw new RuntimeException('Order has no line items');
    }

    $payload = [
        'idempotency_key' => 'pb-order-' . $orderId . '-' . bin2hex(random_bytes(6)),
        'order' => [
            'location_id' => $cfg['square_location_id'],
            'reference_id' => 'panicbooking-order-' . $orderId,
            'line_items' => $lineItems,
        ],
        'checkout_options' => [
            'redirect_url' => $successUrl,
            'ask_for_shipping_address' => false,
        ],
    ];

    $buyerEmail = trim((string)($order['buyer_email'] ?? ''));
    if ($buyerEmail !== '') {
        $payload['pre_populated_data'] = [
            'buyer_email' => $buyerEmail,
        ];
    }

    return $payload;
}

function paymentCreateSquareCheckoutSession(PDO $pdo, int $orderId): array {
    $order = paymentOrderForCheckout($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    if (($order['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Order is not pending payment');
    }

    $payload = paymentSquareBuildCheckoutPayload($order);
    $response = paymentSquareRequest('POST', 'v2/online-checkout/payment-links', $payload);

    $paymentLink = is_array($response['payment_link'] ?? null) ? $response['payment_link'] : [];
    $linkId = trim((string)($paymentLink['id'] ?? ''));
    $linkUrl = trim((string)($paymentLink['url'] ?? ''));
    $squareOrderId = trim((string)($paymentLink['order_id'] ?? ''));

    if ($squareOrderId === '' && is_array($response['related_resources']['orders'][0] ?? null)) {
        $squareOrderId = trim((string)($response['related_resources']['orders'][0]['id'] ?? ''));
    }

    if ($linkUrl === '') {
        throw new RuntimeException('Square payment link is invalid');
    }

    $reference = $squareOrderId !== '' ? $squareOrderId : $linkId;
    if ($reference !== '') {
        paymentSetOrderProviderReference($pdo, $orderId, 'square', $reference);
    }

    return [
        'id' => $linkId,
        'url' => $linkUrl,
        'order_id' => $orderId,
        'square_order_id' => $squareOrderId,
    ];
}

function paymentBeginCheckout(PDO $pdo, int $orderId): array {
    $provider = paymentProvider();

    if ($provider === 'demo') {
        $order = paymentOrderById($pdo, $orderId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        $finalized = paymentFinalizeSuccessfulOrder($pdo, $orderId, 'demo_' . bin2hex(random_bytes(6)), 'demo');
        $finalOrderId = (int)($finalized['id'] ?? $orderId);
        $receipt = ticketingBuildReceiptToken($finalOrderId, (string)$order['buyer_email']);

        return [
            'provider' => 'demo',
            'order_id' => $finalOrderId,
            'redirect_url' => '/order-success.php?order_id=' . $finalOrderId . '&receipt=' . urlencode($receipt),
        ];
    }

    if ($provider === 'stripe') {
        $session = paymentCreateStripeCheckoutSession($pdo, $orderId);

        return [
            'provider' => 'stripe',
            'order_id' => $orderId,
            'checkout_session_id' => $session['id'],
            'redirect_url' => $session['url'],
        ];
    }

    if ($provider === 'square') {
        $session = paymentCreateSquareCheckoutSession($pdo, $orderId);

        return [
            'provider' => 'square',
            'order_id' => $orderId,
            'checkout_session_id' => $session['id'],
            'square_order_id' => $session['square_order_id'],
            'redirect_url' => $session['url'],
        ];
    }

    throw new RuntimeException('Unsupported payment provider');
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

function paymentVerifySquareWebhookSignature(string $payload, string $signatureHeader): bool {
    $cfg = paymentConfig();

    $secret = (string)($cfg['square_webhook_signature_key'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('Square webhook signature key is not configured');
    }

    $notificationUrl = trim((string)($cfg['square_webhook_url'] ?? ''));
    if ($notificationUrl === '') {
        throw new RuntimeException('Square webhook URL is not configured');
    }

    $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $payload, $secret, true));
    return hash_equals($expected, trim($signatureHeader));
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
        ticketingLog('payment_webhook_payload_hash_mismatch', [
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
            paymentFinalizeSuccessfulOrder($pdo, $orderId, $reference !== '' ? $reference : null, 'stripe');
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
                paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $reference !== '' ? $reference : null, 'stripe');
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
            paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $sessionId !== '' ? $sessionId : null, 'stripe');
        }
        return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'async_payment_failed'];
    }

    if ($type === 'checkout.session.expired') {
        $sessionId = trim((string)($object['id'] ?? ''));
        if ($orderId <= 0 && $sessionId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $sessionId);
        }
        if ($orderId > 0) {
            paymentMarkPendingOrderStatus($pdo, $orderId, 'canceled', $sessionId !== '' ? $sessionId : null, 'stripe');
        }
        return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'session_expired'];
    }

    if ($type === 'payment_intent.payment_failed') {
        $intentId = trim((string)($object['id'] ?? ''));
        if ($orderId <= 0 && $intentId !== '') {
            $orderId = paymentFindOrderIdByStripeReference($pdo, $intentId);
        }
        if ($orderId > 0) {
            paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $intentId !== '' ? $intentId : null, 'stripe');
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
            paymentApplyRefundHook($pdo, $orderId, $intentId !== '' ? $intentId : ($chargeId !== '' ? $chargeId : null), 'stripe');
            return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'refunded'];
        }

        return ['status' => 'ignored', 'order_id' => null, 'note' => 'refund_missing_order_id'];
    }

    return ['status' => 'ignored', 'order_id' => $orderId ?: null, 'note' => 'unhandled_event_type'];
}

function paymentExtractOrderIdFromReferencePattern(string $reference): int {
    $reference = trim($reference);
    if ($reference === '') {
        return 0;
    }

    if (preg_match('/panicbooking-order-(\d+)/i', $reference, $m)) {
        return (int)$m[1];
    }

    if (preg_match('/order[_:-]?(\d+)/i', $reference, $m)) {
        return (int)$m[1];
    }

    if (ctype_digit($reference)) {
        return (int)$reference;
    }

    return 0;
}

function paymentExtractOrderIdFromSquareMetadata(array $object): int {
    $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
    $orderId = (int)($metadata['order_id'] ?? 0);
    if ($orderId > 0) {
        return $orderId;
    }

    $referenceId = trim((string)($object['reference_id'] ?? ''));
    return paymentExtractOrderIdFromReferencePattern($referenceId);
}

function paymentResolveOrderIdFromSquarePayment(PDO $pdo, array $payment): int {
    $orderId = paymentExtractOrderIdFromSquareMetadata($payment);
    if ($orderId > 0) {
        return $orderId;
    }

    $paymentId = trim((string)($payment['id'] ?? ''));
    if ($paymentId !== '') {
        $orderId = paymentFindOrderIdBySquareReference($pdo, $paymentId);
        if ($orderId > 0) {
            return $orderId;
        }
    }

    $squareOrderId = trim((string)($payment['order_id'] ?? ''));
    if ($squareOrderId !== '') {
        $orderId = paymentFindOrderIdBySquareReference($pdo, $squareOrderId);
        if ($orderId > 0) {
            return $orderId;
        }
    }

    return 0;
}

function paymentResolveOrderIdFromSquareRefund(PDO $pdo, array $refund): int {
    $orderId = paymentExtractOrderIdFromSquareMetadata($refund);
    if ($orderId > 0) {
        return $orderId;
    }

    $paymentId = trim((string)($refund['payment_id'] ?? ''));
    if ($paymentId !== '') {
        $orderId = paymentFindOrderIdBySquareReference($pdo, $paymentId);
        if ($orderId > 0) {
            return $orderId;
        }
    }

    $squareOrderId = trim((string)($refund['order_id'] ?? ''));
    if ($squareOrderId !== '') {
        $orderId = paymentFindOrderIdBySquareReference($pdo, $squareOrderId);
        if ($orderId > 0) {
            return $orderId;
        }
    }

    return 0;
}

function paymentProcessSquareEvent(PDO $pdo, array $event): array {
    $eventType = (string)($event['type'] ?? '');
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $objectType = (string)($data['type'] ?? '');
    $object = is_array($data['object'] ?? null) ? $data['object'] : [];

    $isPaymentEvent = $objectType === 'payment' || str_starts_with($eventType, 'payment.');
    if ($isPaymentEvent) {
        $payment = is_array($object['payment'] ?? null) ? $object['payment'] : $object;
        if (!is_array($payment) || empty($payment)) {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'payment_payload_missing'];
        }

        $orderId = paymentResolveOrderIdFromSquarePayment($pdo, $payment);
        $status = strtoupper(trim((string)($payment['status'] ?? '')));

        if ($status === 'COMPLETED') {
            if ($orderId <= 0) {
                return ['status' => 'ignored', 'order_id' => null, 'note' => 'missing_order_id'];
            }

            $reference = trim((string)($payment['id'] ?? ''));
            if ($reference === '') {
                $reference = trim((string)($payment['order_id'] ?? ''));
            }

            try {
                paymentFinalizeSuccessfulOrder($pdo, $orderId, $reference !== '' ? $reference : null, 'square');
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
                    paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $reference !== '' ? $reference : null, 'square');
                    ticketingLog('square_paid_order_finalization_failed', [
                        'order_id' => $orderId,
                        'error' => $message,
                    ]);
                    return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'finalization_failed_manual_refund_needed'];
                }

                throw $e;
            }
        }

        if ($status === 'FAILED') {
            if ($orderId > 0) {
                $reference = trim((string)($payment['id'] ?? ''));
                paymentMarkPendingOrderStatus($pdo, $orderId, 'failed', $reference !== '' ? $reference : null, 'square');
            }
            return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'payment_failed'];
        }

        if ($status === 'CANCELED') {
            if ($orderId > 0) {
                $reference = trim((string)($payment['id'] ?? ''));
                paymentMarkPendingOrderStatus($pdo, $orderId, 'canceled', $reference !== '' ? $reference : null, 'square');
            }
            return ['status' => 'processed', 'order_id' => $orderId ?: null, 'note' => 'payment_canceled'];
        }

        return ['status' => 'ignored', 'order_id' => $orderId ?: null, 'note' => 'payment_not_final'];
    }

    $isRefundEvent = $objectType === 'refund' || str_starts_with($eventType, 'refund.');
    if ($isRefundEvent) {
        $refund = is_array($object['refund'] ?? null) ? $object['refund'] : $object;
        if (!is_array($refund) || empty($refund)) {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'refund_payload_missing'];
        }

        $status = strtoupper(trim((string)($refund['status'] ?? '')));
        if ($status !== 'COMPLETED') {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'refund_not_completed'];
        }

        $orderId = paymentResolveOrderIdFromSquareRefund($pdo, $refund);
        if ($orderId <= 0) {
            return ['status' => 'ignored', 'order_id' => null, 'note' => 'refund_missing_order_id'];
        }

        $reference = trim((string)($refund['payment_id'] ?? ''));
        if ($reference === '') {
            $reference = trim((string)($refund['id'] ?? ''));
        }

        paymentApplyRefundHook($pdo, $orderId, $reference !== '' ? $reference : null, 'square');
        return ['status' => 'processed', 'order_id' => $orderId, 'note' => 'refunded'];
    }

    return ['status' => 'ignored', 'order_id' => null, 'note' => 'unhandled_event_type'];
}

function paymentHandleStripeWebhook(PDO $pdo, string $payload, string $signatureHeader): array {
    if (paymentProvider() !== 'stripe') {
        throw new RuntimeException('Stripe webhook received while payment provider is not stripe');
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

function paymentHandleSquareWebhook(PDO $pdo, string $payload, string $signatureHeader): array {
    if (paymentProvider() !== 'square') {
        throw new RuntimeException('Square webhook received while payment provider is not square');
    }

    if (!paymentVerifySquareWebhookSignature($payload, $signatureHeader)) {
        throw new RuntimeException('Square signature verification failed');
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new RuntimeException('Invalid Square webhook payload');
    }

    $eventId = trim((string)($event['event_id'] ?? $event['id'] ?? ''));
    $eventType = trim((string)($event['type'] ?? ''));
    if ($eventId === '' || $eventType === '') {
        throw new RuntimeException('Square webhook missing id or type');
    }

    $payloadHash = hash('sha256', $payload);
    $acquired = paymentAcquireWebhookEvent($pdo, 'square', $eventId, $eventType, $payloadHash);
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
        $result = paymentProcessSquareEvent($pdo, $event);
        $status = (string)($result['status'] ?? 'ignored');
        $orderId = isset($result['order_id']) ? (int)$result['order_id'] : null;
        $note = isset($result['note']) ? (string)$result['note'] : null;

        paymentCompleteWebhookEvent($pdo, $eventRowId, $status, $orderId, $note);

        ticketingLog('square_webhook_processed', [
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
