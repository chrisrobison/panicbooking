<?php
require_once __DIR__ . '/app/includes/db.php';
require_once __DIR__ . '/lib/ticketing.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$receipt = trim((string)($_GET['receipt'] ?? ''));
$sessionId = trim((string)($_GET['session_id'] ?? ''));

if ($orderId <= 0 || $receipt === '') {
    http_response_code(400);
    exit('Invalid confirmation link');
}

$order = ticketingGetOrderWithTickets($pdo, $orderId, null);
if (!$order) {
    http_response_code(404);
    exit('Order not found');
}

if (!ticketingVerifyReceiptToken($orderId, (string)$order['buyer_email'], $receipt)) {
    http_response_code(403);
    exit('Invalid receipt token');
}

$status = (string)($order['status'] ?? 'pending');
if ($status === 'paid') {
    header('Location: /order-success.php?order_id=' . $orderId . '&receipt=' . urlencode($receipt), true, 302);
    exit;
}

$eventUrl = '/event.php?slug=' . urlencode((string)($order['event_slug'] ?? ''));

function pbProviderLabel(string $provider): string {
    $provider = strtolower(trim($provider));
    if ($provider === 'stripe') {
        return 'Stripe';
    }
    if ($provider === 'square') {
        return 'Square';
    }
    if ($provider === 'demo') {
        return 'demo';
    }
    return 'payment';
}

function pbStatusMessage(string $status, string $providerLabel): array {
    if ($status === 'pending') {
        return ['type' => 'info', 'title' => 'Payment received', 'body' => 'We are waiting for ' . $providerLabel . ' webhook confirmation. Your tickets will appear automatically once payment is finalized.'];
    }
    if ($status === 'failed') {
        return ['type' => 'error', 'title' => 'Payment failed', 'body' => 'The payment did not complete. You can return to the event page and try again.'];
    }
    if ($status === 'canceled') {
        return ['type' => 'warning', 'title' => 'Checkout canceled', 'body' => 'Checkout was canceled before payment completion. You can retry from the event page.'];
    }
    if ($status === 'refunded') {
        return ['type' => 'warning', 'title' => 'Payment refunded', 'body' => 'This order is marked refunded. Tickets are not active for entry.'];
    }

    return ['type' => 'info', 'title' => 'Order update pending', 'body' => 'Please refresh this page in a few seconds.'];
}

$providerLabel = pbProviderLabel((string)($order['payment_provider'] ?? ''));
$state = pbStatusMessage($status, $providerLabel);
$autoRefresh = $status === 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
    <?php if ($autoRefresh): ?>
        <meta http-equiv="refresh" content="4">
    <?php endif; ?>
</head>
<body>
<main class="main-content" style="margin-left:0;max-width:840px;padding:1.5rem;">
    <div class="card-form">
        <h1 class="page-title">Payment Status</h1>
        <div class="alert alert-<?= htmlspecialchars($state['type']) ?>" style="margin:1rem 0;">
            <strong><?= htmlspecialchars($state['title']) ?>.</strong>
            <?= htmlspecialchars($state['body']) ?>
        </div>

        <p><strong>Order:</strong> #<?= (int)$order['id'] ?></p>
        <p><strong>Event:</strong> <?= htmlspecialchars((string)$order['event_title']) ?></p>
        <p><strong>Buyer:</strong> <?= htmlspecialchars((string)$order['buyer_email']) ?></p>
        <?php if ($sessionId !== ''): ?>
            <p><strong>Stripe Session:</strong> <code><?= htmlspecialchars($sessionId) ?></code></p>
        <?php endif; ?>

        <div style="margin-top:1.2rem;display:flex;gap:.7rem;flex-wrap:wrap;">
            <?php if ($status === 'pending'): ?>
                <a class="btn btn-secondary" href="">Refresh Status</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="<?= htmlspecialchars($eventUrl) ?>">Back to Event</a>
        </div>
    </div>
</main>
</body>
</html>
