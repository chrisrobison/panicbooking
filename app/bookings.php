<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../lib/booking_workflow.php';

requireAuth();
$user = currentUser();
$currentPage = 'bookings';

function bookingsSetFlash(string $type, string $message): void {
    $_SESSION['bookings_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function bookingsPullFlash(): ?array {
    $flash = $_SESSION['bookings_flash'] ?? null;
    unset($_SESSION['bookings_flash']);
    return is_array($flash) ? $flash : null;
}

function bookingsBuildQuery(string $status, int $opportunityId, int $bookingId = 0): string {
    $params = [];
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($opportunityId > 0) {
        $params['opportunity_id'] = $opportunityId;
    }
    if ($bookingId > 0) {
        $params['id'] = $bookingId;
    }
    return http_build_query($params);
}

function bookingsRedirect(string $query = ''): void {
    $url = '/app/bookings.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url);
    exit;
}

function bookingsFmtDate(string $date): string {
    $ts = strtotime($date . ' 00:00:00');
    return $ts ? date('M j, Y', $ts) : $date;
}

function bookingsFmtDateTime(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : $value;
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$opportunityFilter = max(0, (int)($_GET['opportunity_id'] ?? 0));
$selectedBookingId = max(0, (int)($_GET['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');
    $action = trim((string)($_POST['action'] ?? ''));

    $returnStatus = trim((string)($_POST['return_status'] ?? ''));
    $returnOpportunityId = max(0, (int)($_POST['return_opportunity_id'] ?? 0));
    $returnBookingId = max(0, (int)($_POST['return_booking_id'] ?? 0));

    try {
        if ($action === 'transition') {
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            $toStatus = trim((string)($_POST['to_status'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $updated = bookingWorkflowTransitionBooking($pdo, $user, $bookingId, $toStatus, $note);

            bookingsSetFlash('success', 'Booking moved to ' . bookingWorkflowStatusLabel((string)$updated['status']) . '.');
            bookingsRedirect(bookingsBuildQuery($returnStatus, $returnOpportunityId, (int)$updated['id']));
        }

        if ($action === 'add_note') {
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            bookingWorkflowAddNote($pdo, $user, $bookingId, (string)($_POST['note'] ?? ''));
            bookingsSetFlash('success', 'Note added.');
            bookingsRedirect(bookingsBuildQuery($returnStatus, $returnOpportunityId, $bookingId));
        }

        bookingsSetFlash('error', 'Unknown action.');
        bookingsRedirect(bookingsBuildQuery($returnStatus, $returnOpportunityId, $returnBookingId));
    } catch (Throwable $e) {
        bookingsSetFlash('error', $e->getMessage());
        bookingsRedirect(bookingsBuildQuery($returnStatus, $returnOpportunityId, $returnBookingId));
    }
}

$flash = bookingsPullFlash();

$bookings = bookingWorkflowListBookings($pdo, $user, [
    'status' => $statusFilter,
    'opportunity_id' => $opportunityFilter,
    'limit' => 300,
]);

if ($selectedBookingId <= 0 && !empty($bookings)) {
    $selectedBookingId = (int)$bookings[0]['id'];
}

$selectedBooking = null;
if ($selectedBookingId > 0) {
    $selectedBooking = bookingWorkflowGetBookingDetailForActor($pdo, $user, $selectedBookingId);
}
if (!$selectedBooking && !empty($bookings)) {
    $selectedBookingId = (int)$bookings[0]['id'];
    $selectedBooking = bookingWorkflowGetBookingDetailForActor($pdo, $user, $selectedBookingId);
}

$activeCount = 0;
$confirmedCount = 0;
$completedCount = 0;
$confirmedGigs = [];
foreach ($bookings as $booking) {
    $status = (string)$booking['status'];
    if (in_array($status, bookingWorkflowActiveStatuses(), true)) {
        $activeCount++;
    }
    if (in_array($status, ['accepted', 'contracted'], true)) {
        $confirmedCount++;
        $confirmedGigs[] = $booking;
    }
    if ($status === 'completed') {
        $completedCount++;
    }
}

$statusOptions = bookingWorkflowStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings — Panic Booking</title>
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
            <h1 class="page-title">Bookings</h1>
            <span class="page-subtitle">Track lifecycle status, transitions, and notes.</span>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:1rem;">
            <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid" style="margin-bottom:1rem;">
        <div class="stat-card">
            <div class="stat-icon">📌</div>
            <div class="stat-number"><?= $activeCount ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-number"><?= $confirmedCount ?></div>
            <div class="stat-label">Accepted / Contracted</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🎉</div>
            <div class="stat-number"><?= $completedCount ?></div>
            <div class="stat-label">Completed</div>
        </div>
    </div>

    <?php if (($user['type'] ?? '') === 'band' && !empty($confirmedGigs)): ?>
        <section class="card-form" style="margin-bottom:1rem;">
            <h2 class="form-section-title">Accepted / Contracted Gigs</h2>
            <div class="cards-list">
                <?php foreach ($confirmedGigs as $gig): ?>
                    <a class="list-card" href="/app/bookings.php?<?= htmlspecialchars(bookingsBuildQuery($statusFilter, $opportunityFilter, (int)$gig['id'])) ?>">
                        <div class="list-card-main">
                            <div class="list-card-name"><?= htmlspecialchars((string)$gig['opportunity_title']) ?></div>
                            <div class="list-card-meta">
                                <?= htmlspecialchars((string)$gig['venue_name']) ?> · <?= htmlspecialchars(bookingsFmtDate((string)$gig['event_date'])) ?>
                            </div>
                        </div>
                        <span class="badge"><?= htmlspecialchars(bookingWorkflowStatusLabel((string)$gig['status'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card-form" style="margin-bottom:1rem;">
        <form method="get" action="/app/bookings.php" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin-bottom:0;min-width:220px;">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars(bookingWorkflowStatusLabel($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;min-width:160px;">
                <label for="opportunity_id">Opportunity ID</label>
                <input type="number" id="opportunity_id" name="opportunity_id" min="0" value="<?= $opportunityFilter > 0 ? (int)$opportunityFilter : '' ?>" placeholder="Optional">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="/app/bookings.php" class="btn btn-secondary btn-sm">Clear</a>
        </form>
    </section>

    <section class="card-form" style="overflow-x:auto;margin-bottom:1rem;">
        <h2 class="form-section-title">My Booking Pipeline</h2>
        <?php if (empty($bookings)): ?>
            <p class="empty-state">No bookings found for current filters.</p>
        <?php else: ?>
            <table class="admin-table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Event Date</th>
                        <th>Opportunity</th>
                        <th>Venue</th>
                        <th>Band</th>
                        <th>Status</th>
                        <th>History</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php $isSelected = (int)$booking['id'] === (int)$selectedBookingId; ?>
                        <tr>
                            <td><?= htmlspecialchars(bookingsFmtDate((string)$booking['event_date'])) ?></td>
                            <td>
                                <a href="/app/bookings.php?<?= htmlspecialchars(bookingsBuildQuery($statusFilter, $opportunityFilter, (int)$booking['id'])) ?>" style="font-weight:600;">
                                    <?= htmlspecialchars((string)$booking['opportunity_title']) ?>
                                </a>
                                <div style="font-size:.8rem;color:var(--text-muted)">#<?= (int)$booking['id'] ?></div>
                            </td>
                            <td><?= htmlspecialchars((string)$booking['venue_name']) ?></td>
                            <td><?= htmlspecialchars((string)$booking['band_name']) ?></td>
                            <td>
                                <span class="badge"><?= htmlspecialchars((string)$booking['status_label']) ?></span>
                                <?php if ($isSelected): ?><span style="font-size:.8rem;color:var(--text-muted);"> selected</span><?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm" href="/app/bookings.php?<?= htmlspecialchars(bookingsBuildQuery($statusFilter, $opportunityFilter, (int)$booking['id'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ($selectedBooking): ?>
        <section class="card-form" style="margin-bottom:1rem;max-width:1100px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 class="form-section-title" style="margin-bottom:.4rem;">Booking #<?= (int)$selectedBooking['id'] ?> · <?= htmlspecialchars((string)$selectedBooking['opportunity_title']) ?></h2>
                    <div class="page-subtitle">
                        <?= htmlspecialchars(bookingsFmtDate((string)$selectedBooking['event_date'])) ?>
                        · Venue: <?= htmlspecialchars((string)$selectedBooking['venue_name']) ?>
                        · Band: <?= htmlspecialchars((string)$selectedBooking['band_name']) ?>
                    </div>
                </div>
                <span class="badge"><?= htmlspecialchars((string)$selectedBooking['status_label']) ?></span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.75rem;margin-top:1rem;">
                <div>
                    <strong>Time</strong>
                    <div><?= htmlspecialchars((string)$selectedBooking['start_time'] ?: '—') ?>
                        <?php if (!empty($selectedBooking['end_time'])): ?>– <?= htmlspecialchars((string)$selectedBooking['end_time']) ?><?php endif; ?>
                    </div>
                </div>
                <div>
                    <strong>Tags</strong>
                    <div><?= htmlspecialchars((string)$selectedBooking['genre_tags']) ?: '—' ?></div>
                </div>
                <div>
                    <strong>Compensation Notes</strong>
                    <div><?= nl2br(htmlspecialchars((string)$selectedBooking['compensation_notes'])) ?: '—' ?></div>
                </div>
                <div>
                    <strong>Constraints</strong>
                    <div><?= nl2br(htmlspecialchars((string)$selectedBooking['constraints_notes'])) ?: '—' ?></div>
                </div>
            </div>

            <?php $allowedTransitions = $selectedBooking['allowed_transitions'] ?? []; ?>
            <?php if (!empty($allowedTransitions)): ?>
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <h3 class="form-section-title" style="font-size:1rem;">Update Status</h3>
                    <form method="post" action="/app/bookings.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;align-items:end;">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="action" value="transition">
                        <input type="hidden" name="booking_id" value="<?= (int)$selectedBooking['id'] ?>">
                        <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                        <input type="hidden" name="return_opportunity_id" value="<?= (int)$opportunityFilter ?>">
                        <input type="hidden" name="return_booking_id" value="<?= (int)$selectedBooking['id'] ?>">

                        <div class="form-group" style="margin-bottom:0;">
                            <label for="to_status">Move To</label>
                            <select id="to_status" name="to_status" required>
                                <?php foreach ($allowedTransitions as $status): ?>
                                    <option value="<?= htmlspecialchars((string)$status) ?>"><?= htmlspecialchars(bookingWorkflowStatusLabel((string)$status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;grid-column:1/-1;">
                            <label for="transition_note">Transition Note (optional)</label>
                            <textarea id="transition_note" name="note" rows="2" maxlength="2000" placeholder="Optional context for this status update"></textarea>
                        </div>

                        <div>
                            <button type="submit" class="btn btn-primary btn-sm">Apply Transition</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                <h3 class="form-section-title" style="font-size:1rem;">Add Note</h3>
                <form method="post" action="/app/bookings.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="booking_id" value="<?= (int)$selectedBooking['id'] ?>">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                    <input type="hidden" name="return_opportunity_id" value="<?= (int)$opportunityFilter ?>">
                    <input type="hidden" name="return_booking_id" value="<?= (int)$selectedBooking['id'] ?>">

                    <div class="form-group" style="margin-bottom:0;flex:1;min-width:280px;">
                        <label for="note">Note</label>
                        <textarea id="note" name="note" rows="2" maxlength="4000" required placeholder="Add booking note"></textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Save Note</button>
                </form>
            </div>
        </section>

        <section class="card-form" style="margin-bottom:1rem;overflow-x:auto;max-width:1100px;">
            <h3 class="form-section-title" style="font-size:1rem;">Status History</h3>
            <?php if (empty($selectedBooking['history'])): ?>
                <p class="empty-state">No history yet.</p>
            <?php else: ?>
                <table class="admin-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>By</th>
                            <th>Transition</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selectedBooking['history'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(bookingsFmtDateTime((string)$item['created_at'])) ?></td>
                                <td><?= htmlspecialchars((string)$item['changed_by_name']) ?></td>
                                <td>
                                    <?php if (!empty($item['from_status_label'])): ?>
                                        <?= htmlspecialchars((string)$item['from_status_label']) ?> →
                                    <?php endif; ?>
                                    <strong><?= htmlspecialchars((string)$item['to_status_label']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars((string)$item['note']) ?: '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card-form" style="max-width:1100px;">
            <h3 class="form-section-title" style="font-size:1rem;">Notes</h3>
            <?php if (empty($selectedBooking['notes'])): ?>
                <p class="empty-state">No notes yet.</p>
            <?php else: ?>
                <div class="cards-list">
                    <?php foreach ($selectedBooking['notes'] as $note): ?>
                        <div class="list-card">
                            <div class="list-card-main">
                                <div class="list-card-name"><?= htmlspecialchars((string)$note['author_name']) ?></div>
                                <div class="list-card-meta"><?= htmlspecialchars(bookingsFmtDateTime((string)$note['created_at'])) ?></div>
                                <div style="margin-top:.35rem;white-space:pre-wrap;"><?= htmlspecialchars((string)$note['note']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script src="/app/assets/js/app.js"></script>
</body>
</html>
