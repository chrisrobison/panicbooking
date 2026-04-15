<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../lib/ticketing.php';

requireAuth();
$user = currentUser();
$currentPage = 'checkin';

if (!ticketingUserCanManageEvents($user)) {
    http_response_code(403);
    exit('Forbidden');
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$prefillToken = trim((string)($_GET['token'] ?? ''));
$events = ticketingListManageableEvents($pdo, $user, 200);
$event = null;

if ($eventId > 0) {
    $event = ticketingGetEventById($pdo, $eventId);
    if (!$event) {
        http_response_code(404);
        exit('Event not found');
    }
    if (!ticketingUserCanManageEvent($user, $event)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function prettyDate(?string $value): string {
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
    <title>Door Check-In — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">Door Check-In</h1>
    </div>

    <?php if (!$event): ?>
        <div class="card-form" style="max-width:900px;">
            <h2 class="form-section-title">Choose Event</h2>
            <?php if (empty($events)): ?>
                <p class="empty-state">No events available for check-in.</p>
            <?php else: ?>
                <div class="cards-list">
                    <?php foreach ($events as $evt): ?>
                        <a class="list-card" href="/app/checkin.php?event_id=<?= (int)$evt['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div class="list-card-name"><?= htmlspecialchars($evt['title']) ?></div>
                                <div class="list-card-meta"><?= htmlspecialchars(prettyDate($evt['start_at'])) ?></div>
                            </div>
                            <span class="badge badge-<?= ($evt['status'] === 'published') ? 'band' : 'venue' ?>"><?= htmlspecialchars($evt['status']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card-form" style="max-width:950px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2 class="form-section-title" style="margin-bottom:.35rem;"><?= htmlspecialchars($event['title']) ?></h2>
                    <div class="page-subtitle"><?= htmlspecialchars($event['venue_name']) ?> · <?= htmlspecialchars(prettyDate($event['start_at'])) ?></div>
                </div>
                <a href="/event/<?= htmlspecialchars($event['slug']) ?>" target="_blank" rel="noopener" class="btn btn-sm">View Public Page</a>
            </div>

            <div style="margin-top:1rem;display:grid;grid-template-columns:1fr;gap:1rem;">
                <div class="form-group">
                    <label for="scanInput">Scan Token / QR URL / Short Code</label>
                    <input type="text" id="scanInput" value="<?= htmlspecialchars($prefillToken) ?>" placeholder="Paste token, URL, or PB-XXXX-XXXX">
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-secondary" id="validateBtn" type="button">Validate</button>
                    <button class="btn btn-primary" id="checkinBtn" type="button">Check In</button>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <h3 class="form-section-title" style="font-size:1rem;">Camera Scanner</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                    <button type="button" class="btn btn-sm" id="startScannerBtn">Start Camera</button>
                    <button type="button" class="btn btn-sm" id="stopScannerBtn" disabled>Stop Camera</button>
                </div>
                <video id="scannerVideo" autoplay playsinline muted style="width:100%;max-width:460px;border:1px solid var(--border);border-radius:8px;background:#000;"></video>
                <p style="font-size:.8rem;color:var(--text-muted);margin-top:.4rem;">If camera scanning is unavailable, use manual entry below.</p>
            </div>

            <div style="margin-top:1rem;">
                <h3 class="form-section-title" style="font-size:1rem;">Manual Search</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <input type="text" id="searchInput" placeholder="Search short code, buyer name, or email" style="flex:1;min-width:220px;">
                    <button class="btn btn-sm" type="button" id="searchBtn">Search</button>
                </div>
                <div id="searchResults" style="margin-top:.75rem;"></div>
            </div>

            <div id="checkinResult" class="alert" style="display:none;margin-top:1rem;"></div>
        </div>
    <?php endif; ?>
</main>

<script src="/app/assets/js/app.js"></script>
<?php if ($event): ?>
<script>
(() => {
    const eventId = <?= (int)$event['id'] ?>;
    const csrfToken = <?= json_encode(csrfToken()) ?>;

    const scanInput = document.getElementById('scanInput');
    const validateBtn = document.getElementById('validateBtn');
    const checkinBtn = document.getElementById('checkinBtn');
    const resultEl = document.getElementById('checkinResult');
    const searchBtn = document.getElementById('searchBtn');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    const video = document.getElementById('scannerVideo');
    const startScannerBtn = document.getElementById('startScannerBtn');
    const stopScannerBtn = document.getElementById('stopScannerBtn');

    let stream = null;
    let scannerTimer = null;
    let detector = null;
    let lastScanValue = '';
    let lastScanAt = 0;

    function renderResult(payload, kind = 'info') {
        resultEl.style.display = 'block';
        resultEl.className = 'alert';

        if (kind === 'success') {
            resultEl.classList.add('alert-success');
        } else if (kind === 'error') {
            resultEl.classList.add('alert-error');
        } else {
            resultEl.classList.add('alert-info');
        }

        const ticket = payload.ticket || null;
        const parts = [`<strong>${escHtml(payload.message || 'Done')}</strong>`];

        if (ticket) {
            parts.push(`<div style="margin-top:.4rem;">Code: <code>${escHtml(ticket.short_code || '')}</code></div>`);
            parts.push(`<div style="font-size:.9rem;opacity:.9;">${escHtml(ticket.ticket_type_name || '')} · ${escHtml(ticket.buyer_name || ticket.attendee_name || '')}</div>`);
        }

        resultEl.innerHTML = parts.join('');
    }

    async function callApi(action, payload) {
        const res = await fetch(`/api/ticketing/${action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || 'Request failed');
        }
        return data;
    }

    async function validateCurrent() {
        const tokenOrCode = scanInput.value.trim();
        if (!tokenOrCode) {
            renderResult({message: 'Enter or scan a token first.'}, 'error');
            return;
        }

        try {
            const data = await callApi('validate_ticket', {event_id: eventId, token_or_code: tokenOrCode});
            const validation = data.validation || {};
            renderResult(validation, validation.ok ? 'success' : 'error');
        } catch (err) {
            renderResult({message: err.message}, 'error');
        }
    }

    async function checkInCurrent() {
        const tokenOrCode = scanInput.value.trim();
        if (!tokenOrCode) {
            renderResult({message: 'Enter or scan a token first.'}, 'error');
            return;
        }

        try {
            const data = await callApi('check_in_ticket', {event_id: eventId, token_or_code: tokenOrCode});
            const checkin = data.checkin || {};
            renderResult(checkin, checkin.ok ? 'success' : 'error');
        } catch (err) {
            renderResult({message: err.message}, 'error');
        }
    }

    async function runSearch() {
        const q = searchInput.value.trim();
        if (!q) {
            searchResults.innerHTML = '';
            return;
        }

        try {
            const res = await fetch(`/api/ticketing/search_ticket_by_code?event_id=${eventId}&q=${encodeURIComponent(q)}`, {
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || 'Search failed');
            }

            const rows = data.results || [];
            if (!rows.length) {
                searchResults.innerHTML = '<p class="empty-state">No matches</p>';
                return;
            }

            searchResults.innerHTML = rows.map((row) => {
                const statusClass = row.status === 'checked_in' ? 'badge-venue' : 'badge-band';
                return `<button type="button" class="list-card" data-code="${escHtml(row.short_code)}" style="width:100%;text-align:left;margin-bottom:.5rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;">
                        <div>
                            <div class="list-card-name">${escHtml(row.short_code)}</div>
                            <div class="list-card-meta">${escHtml(row.buyer_name || row.attendee_name || '')} · ${escHtml(row.buyer_email || '')}</div>
                        </div>
                        <span class="badge ${statusClass}">${escHtml(row.status)}</span>
                    </div>
                </button>`;
            }).join('');

            searchResults.querySelectorAll('button[data-code]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    scanInput.value = btn.getAttribute('data-code') || '';
                    scanInput.focus();
                });
            });
        } catch (err) {
            searchResults.innerHTML = `<div class="alert alert-error">${escHtml(err.message)}</div>`;
        }
    }

    async function detectFrame() {
        if (!detector || !video || video.readyState < 2) return;

        try {
            const codes = await detector.detect(video);
            if (!codes || !codes.length) return;

            const rawValue = (codes[0].rawValue || '').trim();
            if (!rawValue) return;

            const now = Date.now();
            if (rawValue === lastScanValue && now - lastScanAt < 2500) {
                return;
            }

            lastScanValue = rawValue;
            lastScanAt = now;
            scanInput.value = rawValue;
            await checkInCurrent();
        } catch (_) {
            // ignore single-frame detector errors
        }
    }

    async function startScanner() {
        if (!('BarcodeDetector' in window)) {
            renderResult({message: 'Camera QR scanning is not supported on this browser. Use manual entry.'}, 'error');
            return;
        }

        try {
            detector = new BarcodeDetector({formats: ['qr_code']});
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
            video.srcObject = stream;
            await video.play();

            scannerTimer = window.setInterval(detectFrame, 450);
            startScannerBtn.disabled = true;
            stopScannerBtn.disabled = false;
        } catch (err) {
            renderResult({message: 'Unable to start camera: ' + err.message}, 'error');
        }
    }

    function stopScanner() {
        if (scannerTimer) {
            clearInterval(scannerTimer);
            scannerTimer = null;
        }

        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }

        if (video) {
            video.srcObject = null;
        }

        startScannerBtn.disabled = false;
        stopScannerBtn.disabled = true;
    }

    validateBtn.addEventListener('click', validateCurrent);
    checkinBtn.addEventListener('click', checkInCurrent);
    searchBtn.addEventListener('click', runSearch);
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            runSearch();
        }
    });
    startScannerBtn.addEventListener('click', startScanner);
    stopScannerBtn.addEventListener('click', stopScanner);

    window.addEventListener('beforeunload', stopScanner);

    if (scanInput.value.trim() !== '') {
        validateCurrent();
    }
})();
</script>
<?php endif; ?>
</body>
</html>
