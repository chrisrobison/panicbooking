<?php
require_once __DIR__ . '/app/includes/db.php';
require_once __DIR__ . '/lib/ticketing.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$receipt = trim((string)($_GET['receipt'] ?? ''));

if ($orderId <= 0 || $receipt === '') {
    http_response_code(400);
    exit('Invalid cancel link');
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
$provider = strtolower(trim((string)($order['payment_provider'] ?? 'payment')));
$providerLabel = $provider === 'square' ? 'Square' : ($provider === 'stripe' ? 'Stripe' : 'payment');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout Canceled — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<main class="main-content" style="margin-left:0;max-width:760px;padding:1.5rem;">
    <div class="card-form">
        <h1 class="page-title">Checkout Canceled</h1>
        <div class="alert alert-warning" style="margin:1rem 0;">
            Your payment session was canceled. No tickets are issued unless a successful <?= htmlspecialchars($providerLabel) ?> webhook marks this order paid.
        </div>
        <p><strong>Order:</strong> #<?= (int)$order['id'] ?></p>
        <p><strong>Status:</strong> <?= htmlspecialchars(strtoupper($status)) ?></p>
        <p><strong>Event:</strong> <?= htmlspecialchars((string)$order['event_title']) ?></p>

        <div style="margin-top:1.2rem;display:flex;gap:.7rem;flex-wrap:wrap;">
            <a class="btn btn-primary" href="<?= htmlspecialchars($eventUrl) ?>">Try Again</a>
            <a class="btn btn-outline" href="/">Home</a>
        </div>
    </div>
</main>
</body>
</html>
