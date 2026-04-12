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
$editing = $eventId > 0;
$error = '';

$event = [
    'title' => '',
    'slug' => '',
    'description' => '',
    'start_at' => '',
    'end_at' => '',
    'status' => 'draft',
    'capacity' => '',
    'visibility' => 'public',
    'venue_id' => (int)$user['id'],
];

if ($editing) {
    $loaded = ticketingGetEventById($pdo, $eventId);
    if (!$loaded) {
        http_response_code(404);
        exit('Event not found');
    }
    if (!ticketingUserCanManageEvent($user, $loaded)) {
        http_response_code(403);
        exit('Forbidden');
    }
    $event = array_merge($event, $loaded);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');

    $payload = [
        'title' => trim((string)($_POST['title'] ?? '')),
        'slug' => trim((string)($_POST['slug'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'start_at' => (string)($_POST['start_at'] ?? ''),
        'end_at' => (string)($_POST['end_at'] ?? ''),
        'status' => (string)($_POST['status'] ?? 'draft'),
        'capacity' => (string)($_POST['capacity'] ?? ''),
        'visibility' => (string)($_POST['visibility'] ?? 'public'),
    ];

    if (!empty($user['is_admin'])) {
        $payload['venue_id'] = (int)($_POST['venue_id'] ?? 0);
    }

    try {
        if ($editing) {
            $updated = ticketingUpdateEvent($pdo, $user, $eventId, $payload);
            header('Location: /app/event-tickets.php?id=' . (int)$updated['id'] . '&saved=1');
            exit;
        }

        $newId = ticketingCreateEvent($pdo, $user, $payload);
        header('Location: /app/event-tickets.php?id=' . $newId . '&created=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $event = array_merge($event, $payload);
    }
}

$venues = [];
if (!empty($user['is_admin'])) {
    $stmt = $pdo->query("\n        SELECT u.id, u.email, p.data\n        FROM users u\n        LEFT JOIN profiles p ON p.user_id = u.id\n        WHERE u.type = 'venue'\n        ORDER BY u.email ASC\n    ");
    $venues = $stmt->fetchAll();
}

function datetimeLocalValue(?string $value): string {
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
    <title><?= $editing ? 'Edit Event' : 'Create Event' ?> — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><?= $editing ? 'Edit Event' : 'Create Event' ?></h1>
        <a href="/app/events.php" class="btn btn-sm">Back to Events</a>
    </div>

    <div class="card-form" style="max-width:900px;">
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= csrfInputField() ?>

            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title" required value="<?= htmlspecialchars((string)$event['title']) ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="start_at">Start *</label>
                    <input type="datetime-local" id="start_at" name="start_at" required value="<?= htmlspecialchars(datetimeLocalValue($event['start_at'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="end_at">End</label>
                    <input type="datetime-local" id="end_at" name="end_at" value="<?= htmlspecialchars(datetimeLocalValue($event['end_at'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'canceled' => 'Canceled'] as $key => $label): ?>
                            <option value="<?= $key ?>" <?= (($event['status'] ?? 'draft') === $key) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="visibility">Visibility</label>
                    <select id="visibility" name="visibility">
                        <?php foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private'] as $key => $label): ?>
                            <option value="<?= $key ?>" <?= (($event['visibility'] ?? 'public') === $key) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="capacity">Capacity</label>
                    <input type="number" id="capacity" name="capacity" min="1" placeholder="Optional" value="<?= htmlspecialchars((string)($event['capacity'] ?? '')) ?>">
                </div>
            </div>

            <?php if (!empty($user['is_admin'])): ?>
                <div class="form-group">
                    <label for="venue_id">Venue</label>
                    <select id="venue_id" name="venue_id" required>
                        <?php foreach ($venues as $venue):
                            $profile = json_decode($venue['data'] ?? '{}', true) ?: [];
                            $name = trim((string)($profile['name'] ?? '')) ?: $venue['email'];
                            ?>
                            <option value="<?= (int)$venue['id'] ?>" <?= ((int)$event['venue_id'] === (int)$venue['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="venue_id" value="<?= (int)$user['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="slug">Slug (optional)</label>
                <input type="text" id="slug" name="slug" value="<?= htmlspecialchars((string)($event['slug'] ?? '')) ?>" placeholder="auto-generated-from-title">
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?= htmlspecialchars((string)($event['description'] ?? '')) ?></textarea>
            </div>

            <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                <a href="/app/events.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Event' : 'Create Event' ?></button>
            </div>
        </form>
    </div>
</main>

<script src="/app/assets/js/app.js"></script>
</body>
</html>
