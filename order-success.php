<?php
require_once __DIR__ . '/app/includes/db.php';
require_once __DIR__ . '/lib/ticketing.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$receipt = trim((string)($_GET['receipt'] ?? ''));

if ($orderId <= 0 || $receipt === '') {
    http_response_code(400);
    exit('Invalid receipt link');
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

if (($order['status'] ?? '') !== 'paid') {
    http_response_code(409);
    exit('Order is not paid');
}

function fmtDate(?string $value): string {
    if (!$value) {
        return 'TBA';
    }
    $ts = strtotime($value);
    return $ts ? date('l, F j, Y g:i A', $ts) : $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Complete — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<main class="main-content" style="margin-left:0;max-width:980px;padding:1.5rem;">
    <div class="card-form" style="margin-bottom:1rem;">
        <h1 class="page-title">Order Complete</h1>
        <div class="alert alert-success" style="margin:1rem 0;">Your tickets are ready.</div>
        <p><strong>Event:</strong> <?= htmlspecialchars((string)$order['event_title']) ?></p>
        <p><strong>Venue:</strong> <?= htmlspecialchars((string)$order['venue_name']) ?></p>
        <p><strong>When:</strong> <?= htmlspecialchars(fmtDate((string)($order['start_at'] ?? ''))) ?></p>
        <p><strong>Buyer:</strong> <?= htmlspecialchars((string)$order['buyer_name']) ?> · <?= htmlspecialchars((string)$order['buyer_email']) ?></p>
        <p><strong>Total:</strong> <?= htmlspecialchars(ticketingFormatCents((int)$order['total_cents'])) ?></p>
    </div>

    <div class="cards-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));">
        <?php foreach (($order['tickets'] ?? []) as $ticket): ?>
            <article class="card venue-card" style="cursor:default;">
                <h3 class="card-title" style="margin-bottom:.4rem;"><?= htmlspecialchars((string)$ticket['ticket_type_name']) ?></h3>
                <p class="card-meta" style="margin-bottom:.4rem;"><strong>Code:</strong> <code><?= htmlspecialchars((string)$ticket['short_code']) ?></code></p>
                <div class="ticket-qr" data-qr="<?= htmlspecialchars((string)$ticket['validation_url']) ?>" style="width:170px;height:170px;background:#fff;padding:8px;border-radius:8px;margin:.6rem auto;"></div>
                <p class="card-desc" style="font-size:.78rem;word-break:break-word;">Scan URL: <?= htmlspecialchars((string)$ticket['validation_url']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</main>

<script src="/app/assets/js/vendor/qrcode.min.js"></script>
<script>
(() => {
    document.querySelectorAll('.ticket-qr').forEach((el) => {
        const value = el.getAttribute('data-qr') || '';
        if (!value || typeof QRCode === 'undefined') {
            return;
        }
        // Generate a compact QR per admission ticket.
        new QRCode(el, {
            text: value,
            width: 150,
            height: 150,
            correctLevel: QRCode.CorrectLevel.M,
        });
    });
})();
</script>
</body>
</html>
