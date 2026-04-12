<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/db.php';
require_once __DIR__ . '/app/includes/csrf.php';
require_once __DIR__ . '/api/includes/payment.php';
require_once __DIR__ . '/lib/ticketing.php';

$user = currentUser();
$slug = trim((string)($_GET['slug'] ?? ''));
$event = ticketingGetPublicEventBySlug($pdo, $slug);

if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

$ticketTypes = ticketingGetTicketTypes($pdo, (int)$event['id'], false);
$error = '';

$defaultName = '';
$defaultEmail = '';
if ($user) {
    $defaultEmail = $user['email'] ?? '';
    $stmt = $pdo->prepare('SELECT data FROM profiles WHERE user_id = ?');
    $stmt->execute([(int)$user['id']]);
    $profile = json_decode((string)$stmt->fetchColumn(), true) ?: [];
    $defaultName = trim((string)($profile['name'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');

    $buyerName = trim((string)($_POST['buyer_name'] ?? ''));
    $buyerEmail = trim((string)($_POST['buyer_email'] ?? ''));
    $items = [];

    foreach ($ticketTypes as $tt) {
        $qty = (int)($_POST['qty'][(int)$tt['id']] ?? 0);
        if ($qty > 0) {
            $items[] = [
                'ticket_type_id' => (int)$tt['id'],
                'quantity' => $qty,
            ];
        }
    }

    try {
        $orderPayload = [
            'event_id' => (int)$event['id'],
            'event_slug' => $event['slug'],
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'items' => $items,
        ];
        if ($user) {
            $orderPayload['user_id'] = (int)$user['id'];
        }

        $order = paymentCreateOrder($pdo, $orderPayload);
        $finalized = paymentFinalizeSuccessfulOrder($pdo, (int)$order['order_id']);

        $orderId = (int)($finalized['id'] ?? $order['order_id']);
        if ($orderId <= 0) {
            $orderId = (int)$order['order_id'];
        }

        $receiptToken = ticketingBuildReceiptToken($orderId, $buyerEmail);
        header('Location: /order-success.php?order_id=' . $orderId . '&receipt=' . urlencode($receiptToken));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function money(int $cents): string {
    return '$' . number_format($cents / 100, 2);
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
    <title><?= htmlspecialchars($event['title']) ?> — Tickets</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<main class="main-content" style="margin-left:0;max-width:920px;padding:1.5rem;">
    <div class="card-form">
        <h1 class="page-title" style="margin-bottom:.5rem;"><?= htmlspecialchars($event['title']) ?></h1>
        <div class="page-subtitle" style="margin-bottom:.75rem;"><?= htmlspecialchars($event['venue_name']) ?> · <?= htmlspecialchars(fmtDate($event['start_at'])) ?></div>
        <?php if (!empty($event['description'])): ?>
            <p style="margin-bottom:1rem;"><?= nl2br(htmlspecialchars((string)$event['description'])) ?></p>
        <?php endif; ?>

        <div class="alert alert-info" style="margin-bottom:1rem;">
            Demo payment mode is active. Submitting this form creates a paid order and issues tickets immediately.
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= csrfInputField() ?>

            <h2 class="form-section-title">Select Tickets</h2>
            <?php if (empty($ticketTypes)): ?>
                <p class="empty-state">No ticket types currently on sale.</p>
            <?php else: ?>
                <div class="cards-list" style="margin-bottom:1rem;">
                    <?php foreach ($ticketTypes as $tt):
                        $remaining = max(0, (int)$tt['quantity_available'] - (int)$tt['quantity_sold']);
                        $maxQty = min((int)$tt['max_per_order'], $remaining);
                        $isAvailable = (int)$tt['is_active'] === 1 && $remaining > 0;
                        ?>
                        <div class="list-card" style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:center;">
                            <div>
                                <div class="list-card-name"><?= htmlspecialchars($tt['name']) ?></div>
                                <div class="list-card-meta"><?= htmlspecialchars(money((int)$tt['price_cents'])) ?> · <?= (int)$remaining ?> remaining</div>
                                <?php if (!empty($tt['description'])): ?>
                                    <div style="font-size:.85rem;color:var(--text-muted);margin-top:.25rem;"><?= htmlspecialchars((string)$tt['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <input type="number"
                                       name="qty[<?= (int)$tt['id'] ?>]"
                                       min="0"
                                       max="<?= (int)$maxQty ?>"
                                       value="0"
                                       <?= $isAvailable ? '' : 'disabled' ?>
                                       style="width:90px;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="form-section-title">Buyer Info</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="buyer_name">Name *</label>
                    <input type="text" id="buyer_name" name="buyer_name" required value="<?= htmlspecialchars((string)($_POST['buyer_name'] ?? $defaultName)) ?>">
                </div>
                <div class="form-group">
                    <label for="buyer_email">Email *</label>
                    <input type="email" id="buyer_email" name="buyer_email" required value="<?= htmlspecialchars((string)($_POST['buyer_email'] ?? $defaultEmail)) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" <?= empty($ticketTypes) ? 'disabled' : '' ?>>Purchase Tickets</button>
        </form>
    </div>
</main>
</body>
</html>
