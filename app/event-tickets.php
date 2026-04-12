<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../lib/ticketing.php';

requireAuth();
$user = currentUser();
$currentPage = 'events';

if (!ticketingUserCanManageEvents($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event id');
}

$event = ticketingGetEventById($pdo, $eventId);
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}
if (!ticketingUserCanManageEvent($user, $event)) {
    http_response_code(403);
    exit('Forbidden');
}

$error = '';
$success = '';

function dollarsToCents(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    return (int)round(((float)$value) * 100);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_ticket_type') {
            ticketingCreateTicketType($pdo, $user, $eventId, [
                'name' => (string)($_POST['name'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'price_cents' => dollarsToCents((string)($_POST['price'] ?? '0')),
                'quantity_available' => (int)($_POST['quantity_available'] ?? 0),
                'max_per_order' => (int)($_POST['max_per_order'] ?? 10),
                'sales_start' => (string)($_POST['sales_start'] ?? ''),
                'sales_end' => (string)($_POST['sales_end'] ?? ''),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            $success = 'Ticket type created.';
        } elseif ($action === 'update_ticket_type') {
            $ticketTypeId = (int)($_POST['ticket_type_id'] ?? 0);
            ticketingUpdateTicketType($pdo, $user, $ticketTypeId, [
                'name' => (string)($_POST['name'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'price_cents' => dollarsToCents((string)($_POST['price'] ?? '0')),
                'quantity_available' => (int)($_POST['quantity_available'] ?? 0),
                'max_per_order' => (int)($_POST['max_per_order'] ?? 10),
                'sales_start' => (string)($_POST['sales_start'] ?? ''),
                'sales_end' => (string)($_POST['sales_end'] ?? ''),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            $success = 'Ticket type updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$ticketTypes = ticketingGetTicketTypes($pdo, $eventId, true);
$summary = ticketingGetEventSummary($pdo, $eventId);

function dtLocal(?string $value): string {
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Types — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Ticket Types</h1>
            <span class="page-subtitle"><?= htmlspecialchars($event['title']) ?> · <?= htmlspecialchars($event['venue_name']) ?></span>
        </div>
        <div style="display:flex;gap:.5rem;">
            <a href="/app/event-edit.php?id=<?= (int)$event['id'] ?>" class="btn btn-sm">Edit Event</a>
            <a href="/app/events.php" class="btn btn-sm">Back</a>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Event created. Add ticket types below.</div>
    <?php elseif (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Event updated.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="stats-grid" style="margin-bottom:1rem;">
        <div class="stat-card"><div class="stat-number"><?= (int)$summary['paid_orders'] ?></div><div class="stat-label">Paid Orders</div></div>
        <div class="stat-card"><div class="stat-number"><?= (int)$summary['tickets_total'] ?></div><div class="stat-label">Tickets Issued</div></div>
        <div class="stat-card"><div class="stat-number"><?= (int)$summary['tickets_checked_in'] ?></div><div class="stat-label">Checked In</div></div>
    </div>

    <div class="card-form" style="max-width:900px;margin-bottom:1rem;">
        <h2 class="form-section-title">Add Ticket Type</h2>
        <form method="post" action="">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="create_ticket_type">
            <div class="form-row">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" required placeholder="General Admission">
                </div>
                <div class="form-group">
                    <label>Price (USD)</label>
                    <input type="number" step="0.01" min="0" name="price" value="20.00" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" min="0" name="quantity_available" value="100" required>
                </div>
                <div class="form-group">
                    <label>Max / Order</label>
                    <input type="number" min="1" max="50" name="max_per_order" value="10" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Sales Start</label>
                    <input type="datetime-local" name="sales_start">
                </div>
                <div class="form-group">
                    <label>Sales End</label>
                    <input type="datetime-local" name="sales_end">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;gap:.5rem;">
                    <label><input type="checkbox" name="is_active" checked> Active</label>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" placeholder="Optional description">
            </div>
            <button type="submit" class="btn btn-primary">Add Ticket Type</button>
        </form>
    </div>

    <div class="card-form" style="overflow-x:auto;max-width:1100px;">
        <h2 class="form-section-title">Current Ticket Types</h2>
        <?php if (empty($ticketTypes)): ?>
            <p class="empty-state">No ticket types yet.</p>
        <?php else: ?>
            <table class="admin-table" style="width:100%;">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Sold / Total</th>
                    <th>Active</th>
                    <th>Sales Window</th>
                    <th style="text-align:right;">Save</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ticketTypes as $tt): ?>
                    <tr>
                        <td colspan="6">
                            <form method="post" action="" style="display:grid;grid-template-columns:2fr 1fr 1fr auto 2fr auto;gap:.5rem;align-items:center;">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="update_ticket_type">
                                <input type="hidden" name="ticket_type_id" value="<?= (int)$tt['id'] ?>">

                                <div>
                                    <input type="text" name="name" value="<?= htmlspecialchars($tt['name']) ?>" required>
                                    <input type="text" name="description" value="<?= htmlspecialchars((string)($tt['description'] ?? '')) ?>" placeholder="Description" style="margin-top:.35rem;">
                                </div>
                                <div>
                                    <input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars(number_format(((int)$tt['price_cents']) / 100, 2, '.', '')) ?>" required>
                                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem;">USD</div>
                                </div>
                                <div>
                                    <input type="number" min="0" name="quantity_available" value="<?= (int)$tt['quantity_available'] ?>" required>
                                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem;"><?= (int)$tt['quantity_sold'] ?> sold</div>
                                </div>
                                <div>
                                    <label><input type="checkbox" name="is_active" <?= ((int)$tt['is_active'] === 1) ? 'checked' : '' ?>> Active</label>
                                    <input type="number" min="1" max="50" name="max_per_order" value="<?= (int)$tt['max_per_order'] ?>" style="margin-top:.35rem;" title="Max per order">
                                </div>
                                <div>
                                    <input type="datetime-local" name="sales_start" value="<?= htmlspecialchars(dtLocal($tt['sales_start'] ?? null)) ?>">
                                    <input type="datetime-local" name="sales_end" value="<?= htmlspecialchars(dtLocal($tt['sales_end'] ?? null)) ?>" style="margin-top:.35rem;">
                                </div>
                                <div style="text-align:right;">
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

<script src="/app/assets/js/app.js"></script>
</body>
</html>
