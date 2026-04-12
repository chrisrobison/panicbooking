<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../lib/ticketing.php';

requireAuth();
$user = currentUser();
$currentPage = 'events';

if (!ticketingUserCanManageEvents($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$events = ticketingListManageableEvents($pdo, $user, 200);
$eventRows = [];
foreach ($events as $event) {
    $summary = ticketingGetEventSummary($pdo, (int)$event['id']);
    $eventRows[] = [
        'event' => $event,
        'summary' => $summary,
    ];
}

function fmtDateTime(?string $value): string {
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticketing — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">Ticketing Events</h1>
        <a href="/app/event-edit.php" class="btn btn-primary btn-sm">+ Create Event</a>
    </div>

    <?php if (empty($eventRows)): ?>
        <div class="card-form">
            <p class="empty-state">No ticketed events yet. Create your first event to start selling tickets.</p>
        </div>
    <?php else: ?>
        <div class="card-form" style="overflow-x:auto;">
            <table class="admin-table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Start</th>
                        <th>Status</th>
                        <th>Tickets</th>
                        <th>Check-Ins</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($eventRows as $row):
                    $event = $row['event'];
                    $summary = $row['summary'];
                    $publicUrl = '/event.php?slug=' . urlencode($event['slug']);
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($event['title']) ?></div>
                            <div style="font-size:.8rem;color:var(--text-muted)">
                                <?= htmlspecialchars($event['venue_name'] ?: 'Venue') ?>
                                <?php if (!empty($event['capacity'])): ?>
                                    · Cap <?= (int)$event['capacity'] ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars(fmtDateTime($event['start_at'])) ?></td>
                        <td>
                            <span class="badge <?= $event['status'] === 'published' ? 'badge-band' : 'badge-venue' ?>">
                                <?= htmlspecialchars(ucfirst($event['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= (int)$summary['tickets_total'] ?></strong>
                            <div style="font-size:.8rem;color:var(--text-muted)"><?= (int)$summary['paid_orders'] ?> paid orders</div>
                        </td>
                        <td><?= (int)$summary['tickets_checked_in'] ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <a class="btn btn-sm" href="/app/event-edit.php?id=<?= (int)$event['id'] ?>">Edit</a>
                            <a class="btn btn-sm" href="/app/event-tickets.php?id=<?= (int)$event['id'] ?>">Ticket Types</a>
                            <a class="btn btn-sm" href="/app/checkin.php?event_id=<?= (int)$event['id'] ?>">Check-In</a>
                            <a class="btn btn-sm" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">Public Page</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<script src="/app/assets/js/app.js"></script>
</body>
</html>
