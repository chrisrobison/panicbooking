<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../lib/ticketing.php';

requireAuth();
$user       = currentUser();
$userType   = $user['type'] ?? '';
$currentPage = 'events';

if (!ticketingUserCanManageEvents($user, $pdo)) {
    http_response_code(403);
    exit('Forbidden');
}

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $eventId > 0;
$error   = '';

// ── Defaults ──────────────────────────────────────────────────────────────
$event = [
    'title'       => '',
    'slug'        => '',
    'description' => '',
    'doors_at'    => '',
    'start_at'    => '',
    'end_at'      => '',
    'status'      => 'draft',
    'event_type'  => 'ticketed',
    'capacity'    => '',
    'visibility'  => 'public',
    'venue_id'    => ($userType === 'venue') ? (int)$user['id'] : 0,
];
$existingLineup      = [];
$existingTicketTypes = [];

if ($editing) {
    $loaded = ticketingGetEventById($pdo, $eventId);
    if (!$loaded) { http_response_code(404); exit('Event not found'); }
    if (!ticketingUserCanManageEvent($user, $loaded)) { http_response_code(403); exit('Forbidden'); }
    $event               = array_merge($event, $loaded);
    $existingLineup      = ticketingGetEventLineup($pdo, $eventId);
    $existingTicketTypes = ticketingGetTicketTypes($pdo, $eventId, true);
}

// ── Role-aware venue list ──────────────────────────────────────────────────
$venues = ticketingGetVenuesForUser($pdo, $user);

// Is this user locked to a single venue (no picker shown)?
$venueIsFixed = ($userType === 'venue');
$isListingOnly = in_array($userType, ['band', 'agent'], true) && empty($user['is_admin']);

// ── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid($_POST['csrf_token'] ?? '');

    $payload = [
        'title'       => trim((string)($_POST['title']       ?? '')),
        'slug'        => trim((string)($_POST['slug']        ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'doors_at'    => (string)($_POST['doors_at']  ?? ''),
        'start_at'    => (string)($_POST['start_at']  ?? ''),
        'end_at'      => (string)($_POST['end_at']    ?? ''),
        'status'      => (string)($_POST['status']    ?? 'draft'),
        'event_type'  => (string)($_POST['event_type'] ?? 'ticketed'),
        'capacity'    => (string)($_POST['capacity']  ?? ''),
        'visibility'  => (string)($_POST['visibility'] ?? 'public'),
        'venue_id'    => (int)($_POST['venue_id'] ?? 0),
    ];

    // Raw lineup rows from form
    $rawLineup  = is_array($_POST['lineup']  ?? null) ? $_POST['lineup']  : [];
    // Raw ticket type rows from form (only relevant for new events or adds)
    $rawTickets = is_array($_POST['tickets'] ?? null) ? $_POST['tickets'] : [];

    try {
        $pdo->beginTransaction();

        if ($editing) {
            ticketingUpdateEvent($pdo, $user, $eventId, $payload);
            ticketingSyncEventLineup($pdo, $user, $eventId, $rawLineup);
            $pdo->commit();
            header('Location: /app/event-edit.php?id=' . $eventId . '&saved=1');
            exit;
        }

        $newId = ticketingCreateEvent($pdo, $user, $payload);
        ticketingSyncEventLineup($pdo, $user, $newId, $rawLineup);
        ticketingCreateTicketTypes($pdo, $user, $newId, $rawTickets);

        $pdo->commit();
        header('Location: /app/event-edit.php?id=' . $newId . '&created=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        $event = array_merge($event, $payload);
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function dtLocalVal(?string $v): string {
    if (!$v) return '';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function timeVal(?string $v): string {
    if (!$v) return '';
    // HH:MM:SS → HH:MM for <input type="time">
    return substr((string)$v, 0, 5);
}

function venueDisplayName(array $venue): string {
    $d = json_decode($venue['data'] ?? '{}', true) ?: [];
    $n = trim((string)($d['name'] ?? ''));
    return $n !== '' ? $n : (string)($venue['email'] ?? '');
}

$BILLING_LABELS = [
    'headliner'      => 'Headliner',
    'direct_support' => 'Direct Support',
    'support'        => 'Support',
    'opener'         => 'Opener',
    'special_guest'  => 'Special Guest',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editing ? 'Edit Event' : 'New Event' ?> — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="main-content">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= $editing ? 'Edit Event' : 'New Event' ?></h1>
            <?php if ($isListingOnly): ?>
                <p class="page-subtitle" style="color:var(--accent-2);">
                    You're creating a <strong>show listing</strong> — saved as a draft for the venue to confirm before publishing.
                </p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;">
            <?php if ($editing && ($event['status'] ?? '') === 'published'): ?>
                <a href="/event/<?= htmlspecialchars((string)$event['slug']) ?>" class="btn btn-sm btn-outline" target="_blank">View Live ↗</a>
            <?php endif; ?>
            <a href="/app/events.php" class="btn btn-sm">← Events</a>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Event created! Add more details or ticket types below.</div>
    <?php elseif (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Changes saved.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Two-column layout: sticky nav + form -->
    <div class="event-form-layout">

        <!-- Section nav (desktop sidebar / mobile pills) -->
        <nav class="event-form-nav" id="eventFormNav" aria-label="Form sections">
            <a href="#section-details"  class="efn-link active" data-section="details">Details</a>
            <a href="#section-schedule" class="efn-link"        data-section="schedule">Schedule</a>
            <?php if (!$venueIsFixed): ?>
            <a href="#section-venue"    class="efn-link"        data-section="venue">Venue</a>
            <?php endif; ?>
            <a href="#section-lineup"   class="efn-link"        data-section="lineup">Lineup</a>
            <?php if (!$isListingOnly): ?>
            <a href="#section-tickets"  class="efn-link"        data-section="tickets">Tickets</a>
            <?php endif; ?>
            <a href="#section-settings" class="efn-link"        data-section="settings">Settings</a>
        </nav>

        <!-- Main form -->
        <form method="post" action="" id="eventForm" class="event-form-body">
            <?= csrfInputField() ?>

            <?php if ($venueIsFixed): ?>
                <input type="hidden" name="venue_id" value="<?= (int)$user['id'] ?>">
            <?php endif; ?>
            <?php if ($isListingOnly): ?>
                <input type="hidden" name="event_type" value="listing">
                <input type="hidden" name="status" value="draft">
            <?php endif; ?>

            <!-- ══ SECTION: Details ══════════════════════════════════════ -->
            <section class="event-section" id="section-details" data-section="details">
                <h2 class="event-section-title">
                    <span class="event-section-num">1</span> Event Details
                </h2>

                <div class="form-group">
                    <label for="title">Event title <span class="req">*</span></label>
                    <input type="text" id="title" name="title" required autocomplete="off"
                           placeholder="e.g. Saturday Night Mayhem"
                           value="<?= htmlspecialchars((string)$event['title']) ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="5"
                              placeholder="Tell people what to expect — bands, vibe, cover, age restrictions…"><?= htmlspecialchars((string)$event['description']) ?></textarea>
                </div>
            </section>

            <!-- ══ SECTION: Schedule ═════════════════════════════════════ -->
            <section class="event-section" id="section-schedule" data-section="schedule">
                <h2 class="event-section-title">
                    <span class="event-section-num">2</span> Schedule
                </h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="doors_at">Doors open</label>
                        <input type="time" id="doors_at" name="doors_at"
                               value="<?= htmlspecialchars(timeVal($event['doors_at'] ?? '')) ?>">
                        <span class="field-hint">Optional — when doors open to the public</span>
                    </div>
                    <div class="form-group">
                        <label for="start_at">Show starts <span class="req">*</span></label>
                        <input type="datetime-local" id="start_at" name="start_at" required
                               value="<?= htmlspecialchars(dtLocalVal($event['start_at'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_at">Ends / curfew</label>
                        <input type="datetime-local" id="end_at" name="end_at"
                               value="<?= htmlspecialchars(dtLocalVal($event['end_at'] ?? '')) ?>">
                    </div>
                </div>
            </section>

            <!-- ══ SECTION: Venue ════════════════════════════════════════ -->
            <?php if (!$venueIsFixed): ?>
            <section class="event-section" id="section-venue" data-section="venue">
                <h2 class="event-section-title">
                    <span class="event-section-num">3</span> Venue
                </h2>

                <?php if ($userType === 'promoter' && empty($venues)): ?>
                    <div class="alert alert-info">
                        You don't have any venue delegations yet. Ask a venue to grant you access,
                        or contact an admin. You can still save a draft.
                    </div>
                <?php endif; ?>

                <?php if ($isListingOnly): ?>
                    <p class="field-hint" style="margin-bottom:.75rem;">
                        Select the venue where your show will take place. The venue will need to confirm before this listing is published.
                    </p>
                <?php endif; ?>

                <?php if (!empty($venues)): ?>
                <div class="form-group">
                    <label for="venue_id">Venue <span class="req">*</span></label>
                    <select id="venue_id" name="venue_id" required>
                        <option value="">— Select a venue —</option>
                        <?php foreach ($venues as $v):
                            $vId = (int)$v['id'];
                            $vName = venueDisplayName($v);
                        ?>
                            <option value="<?= $vId ?>" <?= ((int)$event['venue_id'] === $vId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="venue_id" value="0">
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- ══ SECTION: Lineup ═══════════════════════════════════════ -->
            <section class="event-section" id="section-lineup" data-section="lineup">
                <h2 class="event-section-title">
                    <span class="event-section-num"><?= $venueIsFixed ? 3 : ($isListingOnly ? 3 : 4) ?></span> Lineup
                </h2>
                <p class="field-hint" style="margin-bottom:1rem;">
                    Search for bands on the platform, or add an external act by name.
                    Drag rows to reorder.
                </p>

                <!-- Band search -->
                <div class="lineup-search-wrap" id="lineupSearchWrap">
                    <div style="display:flex;gap:.5rem;">
                        <input type="text" id="lineupSearch" class="search-input" autocomplete="off"
                               placeholder="Search bands by name…" style="flex:1;">
                        <button type="button" class="btn btn-sm btn-outline" id="addExternalAct">+ External Act</button>
                    </div>
                    <ul class="lineup-results hidden" id="lineupResults"></ul>
                </div>

                <!-- Lineup list -->
                <div class="lineup-list" id="lineupList">
                    <?php foreach ($existingLineup as $i => $act): ?>
                    <div class="lineup-item" draggable="true" data-index="<?= $i ?>">
                        <input type="hidden" name="lineup[<?= $i ?>][profile_id]"    value="<?= htmlspecialchars((string)($act['profile_id'] ?? '')) ?>">
                        <input type="hidden" name="lineup[<?= $i ?>][external_name]" value="<?= htmlspecialchars((string)($act['external_name'] ?? '')) ?>">
                        <input type="hidden" name="lineup[<?= $i ?>][sort_order]"   value="<?= $i ?>">
                        <span class="lineup-handle" title="Drag to reorder">⠿</span>
                        <span class="lineup-act-name"><?= htmlspecialchars($act['act_name'] ?? $act['external_name'] ?? '') ?></span>
                        <?php if (($act['profile_id'] ?? null) === null): ?>
                            <span class="badge" style="font-size:.65rem;background:var(--bg-input);color:var(--text-muted);">external</span>
                        <?php endif; ?>
                        <select name="lineup[<?= $i ?>][billing]" class="lineup-billing">
                            <?php foreach ($BILLING_LABELS as $bk => $bl): ?>
                                <option value="<?= $bk ?>" <?= ($act['billing'] ?? 'support') === $bk ? 'selected' : '' ?>><?= $bl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="time" name="lineup[<?= $i ?>][set_start]" class="lineup-time"
                               value="<?= htmlspecialchars(timeVal($act['set_start'] ?? '')) ?>"
                               placeholder="Set start" title="Set start time">
                        <span class="lineup-time-sep">–</span>
                        <input type="time" name="lineup[<?= $i ?>][set_end]"   class="lineup-time"
                               value="<?= htmlspecialchars(timeVal($act['set_end'] ?? '')) ?>"
                               placeholder="Set end" title="Set end time">
                        <button type="button" class="lineup-remove btn-icon" title="Remove act">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($existingLineup)): ?>
                    <p class="empty-state" id="lineupEmpty" style="padding:.75rem 0;">No acts added yet.</p>
                <?php else: ?>
                    <p class="empty-state hidden" id="lineupEmpty" style="padding:.75rem 0;">No acts added yet.</p>
                <?php endif; ?>
            </section>

            <!-- ══ SECTION: Tickets ══════════════════════════════════════ -->
            <?php if (!$isListingOnly): ?>
            <section class="event-section" id="section-tickets" data-section="tickets">
                <h2 class="event-section-title">
                    <span class="event-section-num">5</span> Tickets
                    <label class="event-type-toggle" title="Toggle ticketing">
                        <input type="checkbox" id="ticketingToggle"
                               <?= (($event['event_type'] ?? 'ticketed') === 'ticketed') ? 'checked' : '' ?>
                               onchange="document.getElementById('ticketBuilder').hidden=!this.checked;
                                         document.querySelector('[name=event_type]').value=this.checked?'ticketed':'listing';">
                        <span>Sell tickets</span>
                    </label>
                    <input type="hidden" name="event_type" value="<?= htmlspecialchars((string)($event['event_type'] ?? 'ticketed')) ?>">
                </h2>

                <div id="ticketBuilder" <?= (($event['event_type'] ?? 'ticketed') !== 'ticketed') ? 'hidden' : '' ?>>
                    <?php if ($editing && !empty($existingTicketTypes)): ?>
                        <p class="field-hint" style="margin-bottom:.75rem;">
                            Existing ticket types are managed on the
                            <a href="/app/event-tickets.php?id=<?= (int)$event['id'] ?>">Ticket Types page</a>.
                            Add <em>new</em> types below.
                        </p>
                    <?php endif; ?>

                    <!-- Existing ticket types summary (edit mode) -->
                    <?php if (!empty($existingTicketTypes)): ?>
                    <div class="ticket-summary">
                        <?php foreach ($existingTicketTypes as $tt): ?>
                        <div class="ticket-summary-row">
                            <span class="ticket-summary-name"><?= htmlspecialchars($tt['name']) ?></span>
                            <span class="ticket-summary-meta">
                                <?= ticketingFormatCents((int)$tt['price_cents']) ?>
                                &middot; <?= (int)$tt['quantity_sold'] ?>/<?= (int)$tt['quantity_available'] ?> sold
                                <?= ((int)$tt['is_active'] === 0) ? '<span class="badge" style="font-size:.65rem;background:var(--bg-input);color:var(--text-muted);">inactive</span>' : '' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Inline new ticket type builder -->
                    <div class="ticket-list" id="ticketList">
                        <!-- JS inserts rows here -->
                    </div>

                    <button type="button" class="btn btn-sm btn-outline" id="addTicketType" style="margin-top:.75rem;">
                        + Add ticket type
                    </button>
                    <p class="field-hint" style="margin-top:.5rem;">
                        Leave blank to create a free show. You can add or edit ticket types after saving.
                    </p>
                </div>
            </section>
            <?php endif; ?>

            <!-- ══ SECTION: Settings ═════════════════════════════════════ -->
            <section class="event-section" id="section-settings" data-section="settings">
                <h2 class="event-section-title">
                    <span class="event-section-num"><?= $isListingOnly ? 4 : 6 ?></span> Settings
                </h2>

                <?php if (!$isListingOnly): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'canceled' => 'Canceled'] as $sk => $sl): ?>
                                <option value="<?= $sk ?>" <?= ($event['status'] ?? 'draft') === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint">Draft = not visible publicly. Publish when ready.</span>
                    </div>
                    <div class="form-group">
                        <label for="visibility">Visibility</label>
                        <select id="visibility" name="visibility">
                            <?php foreach (['public' => 'Public', 'unlisted' => 'Unlisted (link only)', 'private' => 'Private'] as $vk => $vl): ?>
                                <option value="<?= $vk ?>" <?= ($event['visibility'] ?? 'public') === $vk ? 'selected' : '' ?>><?= $vl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="capacity">Capacity</label>
                        <input type="number" id="capacity" name="capacity" min="1" placeholder="Optional"
                               value="<?= htmlspecialchars((string)($event['capacity'] ?? '')) ?>">
                        <span class="field-hint">Overrides venue capacity. Leave blank for venue default.</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="slug">
                        URL slug
                        <?php if ($editing): ?>
                            <span class="field-hint" style="display:inline;">— /event/<?= htmlspecialchars((string)($event['slug'] ?? '')) ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="text" id="slug" name="slug"
                           pattern="[a-z0-9\-]+"
                           placeholder="auto-generated-from-title"
                           value="<?= htmlspecialchars((string)($event['slug'] ?? '')) ?>">
                    <span class="field-hint">Lowercase letters, numbers, hyphens only. Leave blank to auto-generate from title.</span>
                </div>
            </section>

            <!-- ══ Save bar ══════════════════════════════════════════════ -->
            <div class="event-save-bar">
                <a href="/app/events.php" class="btn btn-secondary">Cancel</a>
                <div style="display:flex;gap:.5rem;">
                    <?php if (!$isListingOnly): ?>
                        <button type="submit" name="status" value="draft" class="btn btn-outline"
                                onclick="document.getElementById('status').value='draft'">
                            Save draft
                        </button>
                        <button type="submit" class="btn btn-primary"
                                onclick="document.getElementById('status').value='published'">
                            <?= $editing ? 'Save &amp; publish' : 'Create &amp; publish' ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <?= $editing ? 'Save listing' : 'Submit listing' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

        </form><!-- /event-form-body -->
    </div><!-- /event-form-layout -->
</main>

<div id="toast" class="toast"></div>

<script src="/app/assets/js/app.js"></script>
<script>
// ── Billing labels (PHP → JS) ─────────────────────────────────────────────
const BILLING_LABELS = <?= json_encode($BILLING_LABELS) ?>;
const IS_LISTING_ONLY = <?= json_encode($isListingOnly) ?>;

// ══════════════════════════════════════════════════════════════════════════
// Section nav: highlight active section on scroll
// ══════════════════════════════════════════════════════════════════════════
(function () {
    const nav      = document.getElementById('eventFormNav');
    const links    = nav ? [...nav.querySelectorAll('.efn-link')] : [];
    const sections = links.map(l => document.querySelector(l.getAttribute('href'))).filter(Boolean);
    if (!sections.length) return;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id.replace('section-', '');
                links.forEach(l => l.classList.toggle('active', l.dataset.section === id));
            }
        });
    }, { rootMargin: '-30% 0px -60% 0px' });

    sections.forEach(s => observer.observe(s));
})();

// ══════════════════════════════════════════════════════════════════════════
// Slug auto-generation from title
// ══════════════════════════════════════════════════════════════════════════
(function () {
    const title = document.getElementById('title');
    const slug  = document.getElementById('slug');
    if (!title || !slug) return;

    let userEdited = slug.value !== '';

    slug.addEventListener('input', () => { userEdited = slug.value !== ''; });

    title.addEventListener('input', debounce(() => {
        if (userEdited) return;
        slug.value = title.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }, 250));
})();

// ══════════════════════════════════════════════════════════════════════════
// Lineup builder
// ══════════════════════════════════════════════════════════════════════════
(function () {
    const list        = document.getElementById('lineupList');
    const emptyMsg    = document.getElementById('lineupEmpty');
    const searchInput = document.getElementById('lineupSearch');
    const results     = document.getElementById('lineupResults');
    const addExtBtn   = document.getElementById('addExternalAct');
    if (!list) return;

    let lineupCount = list.querySelectorAll('.lineup-item').length;

    function reindex() {
        list.querySelectorAll('.lineup-item').forEach((row, i) => {
            row.dataset.index = i;
            row.querySelectorAll('[name^="lineup["]').forEach(el => {
                el.name = el.name.replace(/lineup\[\d+\]/, `lineup[${i}]`);
            });
            row.querySelector('[name$="[sort_order]"]').value = i;
        });
        lineupCount = list.querySelectorAll('.lineup-item').length;
        emptyMsg.classList.toggle('hidden', lineupCount > 0);
    }

    function addAct({ profileId = '', name = '', isExternal = false }) {
        const i   = lineupCount++;
        const row = document.createElement('div');
        row.className   = 'lineup-item';
        row.draggable   = true;
        row.dataset.index = i;

        const billingOpts = Object.entries(BILLING_LABELS)
            .map(([v, l]) => `<option value="${v}">${l}</option>`).join('');

        row.innerHTML = `
            <input type="hidden" name="lineup[${i}][profile_id]"    value="${escHtml(profileId)}">
            <input type="hidden" name="lineup[${i}][external_name]" value="${isExternal ? escHtml(name) : ''}">
            <input type="hidden" name="lineup[${i}][sort_order]"   value="${i}">
            <span class="lineup-handle" title="Drag to reorder">⠿</span>
            <span class="lineup-act-name">${escHtml(name)}</span>
            ${isExternal ? '<span class="badge" style="font-size:.65rem;background:var(--bg-input);color:var(--text-muted);">external</span>' : ''}
            <select name="lineup[${i}][billing]" class="lineup-billing">${billingOpts}</select>
            <input type="time" name="lineup[${i}][set_start]" class="lineup-time" title="Set start time">
            <span class="lineup-time-sep">–</span>
            <input type="time" name="lineup[${i}][set_end]"   class="lineup-time" title="Set end time">
            <button type="button" class="lineup-remove btn-icon" title="Remove">✕</button>
        `;
        list.appendChild(row);
        emptyMsg.classList.add('hidden');
    }

    // Remove act
    list.addEventListener('click', e => {
        if (e.target.classList.contains('lineup-remove')) {
            e.target.closest('.lineup-item').remove();
            reindex();
        }
    });

    // Band search
    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 2) { results.classList.add('hidden'); return; }
        searchTimer = setTimeout(() => {
            fetch(`/api/index.php?route=profiles&type=band&q=${encodeURIComponent(q)}&limit=8`, {
                headers: appCsrfHeaders()
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                results.innerHTML = '';
                const profiles = data?.profiles ?? data?.data ?? [];
                if (!profiles.length) {
                    results.innerHTML = '<li class="lineup-result-empty">No bands found</li>';
                } else {
                    profiles.forEach(p => {
                        const li = document.createElement('li');
                        li.className = 'lineup-result-item';
                        const genres = (p.genres ?? []).slice(0, 3).join(', ');
                        li.innerHTML = `<strong>${escHtml(p.name || p.display_name || '')}</strong>${genres ? `<span class="lineup-result-genre">${escHtml(genres)}</span>` : ''}`;
                        li.addEventListener('click', () => {
                            addAct({ profileId: String(p.id ?? p.profile_id ?? ''), name: p.name || p.display_name || '' });
                            searchInput.value = '';
                            results.classList.add('hidden');
                        });
                        results.appendChild(li);
                    });
                }
                results.classList.remove('hidden');
            })
            .catch(() => results.classList.add('hidden'));
        }, 300);
    });

    // Close results on outside click
    document.addEventListener('click', e => {
        if (!e.target.closest('#lineupSearchWrap')) {
            results.classList.add('hidden');
        }
    });

    // Add external act
    addExtBtn.addEventListener('click', () => {
        const name = prompt('External act name:')?.trim() ?? '';
        if (name) addAct({ name, isExternal: true });
    });

    // ── Drag-to-reorder ─────────────────────────────────────────────────
    let dragging = null;

    list.addEventListener('dragstart', e => {
        dragging = e.target.closest('.lineup-item');
        if (dragging) {
            setTimeout(() => dragging.classList.add('dragging'), 0);
        }
    });
    list.addEventListener('dragend', () => {
        if (dragging) dragging.classList.remove('dragging');
        dragging = null;
        reindex();
    });
    list.addEventListener('dragover', e => {
        e.preventDefault();
        const over = e.target.closest('.lineup-item');
        if (!over || over === dragging) return;
        const rect = over.getBoundingClientRect();
        const mid  = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            list.insertBefore(dragging, over);
        } else {
            list.insertBefore(dragging, over.nextSibling);
        }
    });
})();

// ══════════════════════════════════════════════════════════════════════════
// Inline ticket type builder (new events only)
// ══════════════════════════════════════════════════════════════════════════
(function () {
    if (IS_LISTING_ONLY) return;
    const addBtn  = document.getElementById('addTicketType');
    const ticketList = document.getElementById('ticketList');
    if (!addBtn || !ticketList) return;

    let ticketCount = 0;

    function addTicketRow() {
        const i   = ticketCount++;
        const row = document.createElement('div');
        row.className = 'ticket-builder-row';
        row.innerHTML = `
            <div class="ticket-builder-fields">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="tickets[${i}][name]" required placeholder="General Admission">
                </div>
                <div class="form-group">
                    <label>Price (USD)</label>
                    <input type="number" name="tickets[${i}][price]" step="0.01" min="0" value="0.00" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="tickets[${i}][quantity]" min="0" value="100" required>
                </div>
                <div class="form-group">
                    <label>Max / order</label>
                    <input type="number" name="tickets[${i}][max_per_order]" min="1" max="50" value="10" required>
                </div>
            </div>
            <div class="ticket-builder-fields">
                <div class="form-group">
                    <label>Description <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                    <input type="text" name="tickets[${i}][description]" placeholder="e.g. Includes early entry">
                </div>
                <div class="form-group">
                    <label>Sales open</label>
                    <input type="datetime-local" name="tickets[${i}][sales_start]">
                </div>
                <div class="form-group">
                    <label>Sales close</label>
                    <input type="datetime-local" name="tickets[${i}][sales_end]">
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="button" class="btn btn-sm btn-danger ticket-remove">Remove</button>
                </div>
            </div>
        `;
        ticketList.appendChild(row);
        row.querySelector('[name$="[name]"]').focus();
    }

    addBtn.addEventListener('click', addTicketRow);

    ticketList.addEventListener('click', e => {
        if (e.target.classList.contains('ticket-remove')) {
            e.target.closest('.ticket-builder-row').remove();
        }
    });
})();
</script>
</body>
</html>
