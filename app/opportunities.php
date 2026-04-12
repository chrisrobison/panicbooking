<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../lib/booking_workflow.php';

requireAuth();
$user = currentUser();
$currentPage = 'opportunities';

function opportunitiesSetFlash(string $type, string $message): void {
    $_SESSION['opportunities_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function opportunitiesPullFlash(): ?array {
    $flash = $_SESSION['opportunities_flash'] ?? null;
    unset($_SESSION['opportunities_flash']);
    return is_array($flash) ? $flash : null;
}

function opportunitiesRedirect(string $query = ''): void {
    $url = '/app/opportunities.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url);
    exit;
}

function opportunitiesFmtDate(string $date): string {
    $ts = strtotime($date . ' 00:00:00');
    return $ts ? date('M j, Y', $ts) : $date;
}

function opportunitiesFmtTime(?string $time): string {
    $time = trim((string)$time);
    if ($time === '') {
        return '—';
    }
    $ts = strtotime('1970-01-01 ' . $time . ':00');
    return $ts ? date('g:i A', $ts) : $time;
}

$returnQuery = trim((string)($_GET['return'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_opportunity') {
            $payload = [
                'title' => trim((string)($_POST['title'] ?? '')),
                'event_date' => trim((string)($_POST['event_date'] ?? '')),
                'start_time' => trim((string)($_POST['start_time'] ?? '')),
                'end_time' => trim((string)($_POST['end_time'] ?? '')),
                'genre_tags' => trim((string)($_POST['genre_tags'] ?? '')),
                'compensation_notes' => trim((string)($_POST['compensation_notes'] ?? '')),
                'constraints_notes' => trim((string)($_POST['constraints_notes'] ?? '')),
            ];
            if (!empty($user['is_admin'])) {
                $payload['venue_user_id'] = (int)($_POST['venue_user_id'] ?? 0);
            }

            $opportunityId = bookingWorkflowCreateOpportunity($pdo, $user, $payload);
            opportunitiesSetFlash('success', 'Opportunity posted.');
            opportunitiesRedirect('created=' . $opportunityId);
        }

        if ($action === 'update_opportunity_status') {
            $opportunityId = (int)($_POST['opportunity_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            bookingWorkflowUpdateOpportunityStatus($pdo, $user, $opportunityId, $status);
            opportunitiesSetFlash('success', 'Opportunity status updated.');
            opportunitiesRedirect($returnQuery);
        }

        if ($action === 'submit_inquiry') {
            $opportunityId = (int)($_POST['opportunity_id'] ?? 0);
            $message = trim((string)($_POST['message'] ?? ''));
            $booking = bookingWorkflowCreateInquiry($pdo, $user, $opportunityId, [
                'message' => $message,
            ]);
            opportunitiesSetFlash('success', 'Inquiry submitted.');
            opportunitiesRedirect('booking_id=' . (int)$booking['id']);
        }

        opportunitiesSetFlash('error', 'Unknown action.');
        opportunitiesRedirect($returnQuery);
    } catch (Throwable $e) {
        opportunitiesSetFlash('error', $e->getMessage());
        opportunitiesRedirect($returnQuery);
    }
}

$flash = opportunitiesPullFlash();

$canManage = bookingWorkflowCanManageOpportunities($user);
$canInquire = bookingWorkflowCanSubmitInquiries($user);

$myOpportunities = [];
if ($canManage) {
    $myOpportunities = bookingWorkflowListOpportunities($pdo, $user, [
        'mine_only' => true,
        'limit' => 200,
    ]);
}

$openOpportunities = bookingWorkflowListOpportunities($pdo, $user, [
    'open_only' => true,
    'include_band_context' => true,
    'limit' => 200,
]);

$venueOptions = [];
if (!empty($user['is_admin'])) {
    $stmt = $pdo->query("\n        SELECT u.id, u.email, p.data AS profile_json\n        FROM users u\n        LEFT JOIN profiles p ON p.user_id = u.id\n        WHERE u.type = 'venue'\n        ORDER BY u.email ASC\n    ");
    foreach ($stmt->fetchAll() as $row) {
        $profile = bookingWorkflowDecodeProfileData($row['profile_json'] ?? null);
        $venueOptions[] = [
            'id' => (int)$row['id'],
            'name' => bookingWorkflowBuildDisplayNameFromProfileData($profile, (string)$row['email']),
        ];
    }
}

$activeStatuses = bookingWorkflowActiveStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opportunities — Panic Booking</title>
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
            <h1 class="page-title">Opportunities</h1>
            <span class="page-subtitle">Post open dates and submit booking inquiries.</span>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:1rem;">
            <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <section class="card-form" style="margin-bottom:1rem;max-width:1000px;">
            <h2 class="form-section-title">Post Open Date</h2>
            <form method="post" action="/app/opportunities.php">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="create_opportunity">

                <?php if (!empty($user['is_admin'])): ?>
                    <div class="form-group">
                        <label for="venue_user_id">Venue</label>
                        <select id="venue_user_id" name="venue_user_id" required>
                            <?php foreach ($venueOptions as $venue): ?>
                                <option value="<?= (int)$venue['id'] ?>"><?= htmlspecialchars($venue['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" required maxlength="180" placeholder="Friday Open Date">
                    </div>
                    <div class="form-group">
                        <label for="event_date">Date *</label>
                        <input type="date" id="event_date" name="event_date" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_time">Start Time</label>
                        <input type="time" id="start_time" name="start_time">
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time</label>
                        <input type="time" id="end_time" name="end_time">
                    </div>
                    <div class="form-group">
                        <label for="genre_tags">Genre / Tags</label>
                        <input type="text" id="genre_tags" name="genre_tags" maxlength="600" placeholder="Punk, Indie, 3-band bill">
                    </div>
                </div>

                <div class="form-group">
                    <label for="compensation_notes">Compensation Notes</label>
                    <textarea id="compensation_notes" name="compensation_notes" rows="3" maxlength="4000" placeholder="Door split, guarantee, bar percentage..."></textarea>
                </div>

                <div class="form-group">
                    <label for="constraints_notes">Constraints / Requirements</label>
                    <textarea id="constraints_notes" name="constraints_notes" rows="3" maxlength="4000" placeholder="Load-in, backline, age limits, set length..."></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary">Post Opportunity</button>
                </div>
            </form>
        </section>

        <section class="card-form" style="margin-bottom:1rem;overflow-x:auto;">
            <h2 class="form-section-title">My Opportunities</h2>
            <?php if (empty($myOpportunities)): ?>
                <p class="empty-state">No opportunities posted yet.</p>
            <?php else: ?>
                <table class="admin-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Inquiries</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myOpportunities as $opportunity): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars(opportunitiesFmtDate((string)$opportunity['event_date'])) ?></strong>
                                    <div style="font-size:.8rem;color:var(--text-muted)">
                                        <?= htmlspecialchars(opportunitiesFmtTime($opportunity['start_time'] ?? null)) ?>
                                        <?php if (!empty($opportunity['end_time'])): ?>
                                            – <?= htmlspecialchars(opportunitiesFmtTime($opportunity['end_time'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars((string)$opportunity['title']) ?></div>
                                    <?php if (!empty($opportunity['genre_tags'])): ?>
                                        <div style="font-size:.8rem;color:var(--text-muted)"><?= htmlspecialchars((string)$opportunity['genre_tags']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge"><?= htmlspecialchars(ucfirst((string)$opportunity['status'])) ?></span></td>
                                <td>
                                    <strong><?= (int)$opportunity['inquiry_count'] ?></strong>
                                    <div style="font-size:.8rem;color:var(--text-muted)"><?= (int)$opportunity['active_booking_count'] ?> active</div>
                                </td>
                                <td style="white-space:nowrap;display:flex;gap:.35rem;flex-wrap:wrap;">
                                    <a class="btn btn-sm" href="/app/bookings.php?opportunity_id=<?= (int)$opportunity['id'] ?>">Review</a>

                                    <?php if (($opportunity['status'] ?? '') !== 'open'): ?>
                                        <form method="post" action="/app/opportunities.php" style="display:inline;">
                                            <?= csrfInputField() ?>
                                            <input type="hidden" name="action" value="update_opportunity_status">
                                            <input type="hidden" name="return" value="">
                                            <input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['id'] ?>">
                                            <input type="hidden" name="status" value="open">
                                            <button type="submit" class="btn btn-sm">Reopen</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (($opportunity['status'] ?? '') !== 'closed'): ?>
                                        <form method="post" action="/app/opportunities.php" style="display:inline;">
                                            <?= csrfInputField() ?>
                                            <input type="hidden" name="action" value="update_opportunity_status">
                                            <input type="hidden" name="return" value="">
                                            <input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['id'] ?>">
                                            <input type="hidden" name="status" value="closed">
                                            <button type="submit" class="btn btn-sm">Close</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (($opportunity['status'] ?? '') !== 'canceled'): ?>
                                        <form method="post" action="/app/opportunities.php" style="display:inline;">
                                            <?= csrfInputField() ?>
                                            <input type="hidden" name="action" value="update_opportunity_status">
                                            <input type="hidden" name="return" value="">
                                            <input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['id'] ?>">
                                            <input type="hidden" name="status" value="canceled">
                                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card-form" style="overflow-x:auto;">
        <h2 class="form-section-title">Open Opportunities</h2>
        <?php if (empty($openOpportunities)): ?>
            <p class="empty-state">No open opportunities right now.</p>
        <?php else: ?>
            <table class="admin-table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($openOpportunities as $opportunity): ?>
                        <?php
                        $myBookingId = isset($opportunity['my_booking_id']) ? (int)$opportunity['my_booking_id'] : 0;
                        $myBookingStatus = (string)($opportunity['my_booking_status'] ?? '');
                        $hasActiveInquiry = $myBookingId > 0 && in_array($myBookingStatus, $activeStatuses, true);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars(opportunitiesFmtDate((string)$opportunity['event_date'])) ?></strong>
                                <div style="font-size:.8rem;color:var(--text-muted)">
                                    <?= htmlspecialchars(opportunitiesFmtTime($opportunity['start_time'] ?? null)) ?>
                                    <?php if (!empty($opportunity['end_time'])): ?>
                                        – <?= htmlspecialchars(opportunitiesFmtTime($opportunity['end_time'])) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars((string)$opportunity['venue_name']) ?></div>
                                <div style="font-size:.8rem;color:var(--text-muted)"><?= htmlspecialchars((string)$opportunity['title']) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($opportunity['genre_tags'])): ?>
                                    <div><strong>Tags:</strong> <?= htmlspecialchars((string)$opportunity['genre_tags']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($opportunity['compensation_notes'])): ?>
                                    <div style="font-size:.85rem;"><strong>Comp:</strong> <?= htmlspecialchars((string)$opportunity['compensation_notes']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($opportunity['constraints_notes'])): ?>
                                    <div style="font-size:.85rem;"><strong>Constraints:</strong> <?= htmlspecialchars((string)$opportunity['constraints_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= htmlspecialchars(ucfirst((string)$opportunity['status'])) ?></span></td>
                            <td style="min-width:280px;">
                                <?php if ($hasActiveInquiry): ?>
                                    <div class="alert alert-info" style="padding:.45rem .6rem;margin:0;">
                                        Inquiry in progress (<?= htmlspecialchars(bookingWorkflowStatusLabel($myBookingStatus)) ?>)
                                        <a href="/app/bookings.php?id=<?= $myBookingId ?>">View</a>
                                    </div>
                                <?php elseif ($canInquire): ?>
                                    <form method="post" action="/app/opportunities.php">
                                        <?= csrfInputField() ?>
                                        <input type="hidden" name="action" value="submit_inquiry">
                                        <input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['id'] ?>">
                                        <div class="form-group" style="margin-bottom:.45rem;">
                                            <textarea name="message" rows="2" maxlength="4000" placeholder="Optional inquiry note"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Send Inquiry</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:.9rem;color:var(--text-muted);">Band accounts can submit inquiries.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>

<script src="/app/assets/js/app.js"></script>
</body>
</html>
