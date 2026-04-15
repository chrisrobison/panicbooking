<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$user    = currentUser();
$isOwner = false;
$venueId = 0;
$venueData   = [];
$venueName   = '';
$venueGenres = [];
$venueEmail  = '';
$allVenues   = [];

if ($user && ($user['type'] === 'venue' || ($user['is_admin'] ?? false))) {
    // Logged-in venue owner or admin — show their own calendar
    $isOwner = true;
    $venueId = (int)$user['id'];

    $pStmt = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ? AND type = 'venue' LIMIT 1");
    $pStmt->execute([$venueId]);
    $pRow      = $pStmt->fetch();
    $venueData = $pRow ? (json_decode($pRow['data'], true) ?: []) : [];
    $venueName   = htmlspecialchars(trim((string)($venueData['name'] ?? 'Your Venue')));
    $venueGenres = (array)($venueData['genres_welcomed'] ?? []);
    $venueEmail  = (string)($venueData['contact_email'] ?? '');
} else {
    // Guest or non-venue user — load all venues for the selector
    $nameExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');
    $vStmt = $pdo->prepare("
        SELECT u.id, {$nameExpr} AS venue_name
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE u.type = 'venue' AND COALESCE(p.is_archived, 0) = 0
        ORDER BY LOWER(COALESCE({$nameExpr}, '')) ASC
    ");
    $vStmt->execute();
    foreach ($vStmt->fetchAll() as $vRow) {
        $allVenues[] = [
            'id'   => (int)$vRow['id'],
            'name' => trim((string)($vRow['venue_name'] ?? 'Unnamed Venue')),
        ];
    }

    // Pick the venue from the URL param, defaulting to the first in the list
    $venueId = max(0, (int)($_GET['venue_id'] ?? 0));
    if ($venueId === 0 && !empty($allVenues)) {
        $venueId = (int)$allVenues[0]['id'];
    }

    // Load the selected venue's profile for display
    if ($venueId > 0) {
        $selStmt = $pdo->prepare("
            SELECT p.data FROM users u
            JOIN profiles p ON p.user_id = u.id
            WHERE u.id = ? AND u.type = 'venue' LIMIT 1
        ");
        $selStmt->execute([$venueId]);
        $selRow = $selStmt->fetch();
        if ($selRow) {
            $venueData   = json_decode($selRow['data'], true) ?: [];
            $venueName   = htmlspecialchars(trim((string)($venueData['name'] ?? 'Venue')));
            $venueGenres = (array)($venueData['genres_welcomed'] ?? []);
        }
    }
}

$currentPage = 'venue-dark-nights';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isOwner ? "My Dark Nights — $venueName — Panic Booking" : "Venue Calendar — Panic Booking" ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">

        <!-- View switcher -->
        <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
            <a href="/app/dark-nights.php" class="btn btn-secondary btn-sm">🎸 Band View</a>
            <span class="btn btn-primary btn-sm" style="cursor:default">📅 Venue View</span>
        </div>

        <?php if (!$isOwner && !empty($allVenues)): ?>
        <!-- Venue selector for public/guest view -->
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;padding:.75rem 1rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg)">
            <label for="vdnVenueSelect" style="font-size:.85rem;font-weight:600;color:var(--text-muted);white-space:nowrap">Viewing venue:</label>
            <select id="vdnVenueSelect" class="search-select" style="flex:1;min-width:180px;max-width:320px">
                <?php foreach ($allVenues as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $venueId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$v['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span style="font-size:.8rem;color:var(--text-dim)">
                Read-only ·
                <a href="/app/login.php" style="color:var(--accent)">Log in</a> or
                <a href="/app/signup.php?type=venue" style="color:var(--accent)">sign up</a> to book bands
            </span>
        </div>
        <?php elseif (!$isOwner): ?>
        <div style="margin-bottom:1.25rem;padding:.75rem 1rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);font-size:.875rem;color:var(--text-muted)">
            No registered venues yet. <a href="/app/signup.php?type=venue" style="color:var(--accent)">Sign up as a venue</a> to get started.
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">🌑 <?= $isOwner ? 'My Dark Nights' : 'Venue Dark Nights' ?></h1>
                <span class="page-subtitle">
                    <?php if ($isOwner): ?>
                        <?= $venueName ?> — your calendar at a glance. Click any dark night to find a band.
                    <?php else: ?>
                        <?= $venueName ?: 'Select a venue above' ?> — calendar view. Click a dark night to express interest.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- Controls row -->
        <div class="vdn-controls">
            <div class="vdn-nav-btns">
                <button class="btn btn-secondary btn-sm" id="vdnPrevBtn">← Prev</button>
                <button class="btn btn-secondary btn-sm" id="vdnTodayBtn">Today</button>
                <button class="btn btn-secondary btn-sm" id="vdnNextBtn">Next →</button>
            </div>
            <div class="vdn-stats" id="vdnStats">Loading...</div>
        </div>

        <!-- Calendar container -->
        <div id="vdnCalContainer">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading calendar...</p>
            </div>
        </div>

        <!-- Legend -->
        <div class="vdn-legend">
            <span class="vdn-legend-item"><span class="vdn-legend-dot booked"></span> Booked</span>
            <span class="vdn-legend-item"><span class="vdn-legend-dot dark"></span>
                <?= $isOwner ? 'Dark night — click to find a band' : 'Dark night — click to express interest' ?>
            </span>
            <span class="vdn-legend-item"><span class="vdn-legend-dot past"></span> Past</span>
        </div>
    </main>

    <!-- Band Browser Modal (used by venue owners) -->
    <div id="vdnBrowserModal" class="modal-overlay" style="display:none">
        <div class="modal-box vdn-browser-box">
            <button class="modal-close" id="vdnBrowserClose">✕</button>

            <!-- Main browser view -->
            <div id="vdnBrowserMain">
                <div class="vdn-browser-header">
                    <div>
                        <div class="modal-title">🎸 Find a Band</div>
                        <div class="vdn-browser-date" id="vdnBrowserDate"></div>
                    </div>
                    <button class="btn btn-primary btn-sm" id="vdnPostDateBtn">
                        + Post as Open Date
                    </button>
                </div>

                <div class="vdn-browser-controls">
                    <div class="search-input-wrap" style="flex:1;min-width:180px">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="vdnBandSearch" placeholder="Search bands..." class="search-input">
                    </div>
                    <div class="vdn-genre-chips" id="vdnGenreChips"></div>
                </div>

                <div class="vdn-browser-summary" id="vdnBrowserSummary"></div>

                <div class="vdn-band-grid" id="vdnBandGrid">
                    <div class="loading-state"><div class="spinner"></div></div>
                </div>

                <div class="vdn-load-more-wrap" id="vdnLoadMoreWrap" style="display:none">
                    <button class="btn btn-secondary" id="vdnLoadMoreBtn">Load more bands</button>
                </div>
            </div>

            <!-- Invite form view -->
            <div id="vdnInviteView" style="display:none">
                <button class="btn btn-secondary btn-sm vdn-back-btn" id="vdnInviteBack">← Back</button>
                <div class="modal-title" style="margin-top:.75rem">Invite to Play</div>
                <div class="vdn-invite-subtitle">
                    <strong id="vdnInviteBandName"></strong>
                    &mdash; <span id="vdnInviteDateLabel"></span>
                </div>
                <form id="vdnInviteForm" class="vdn-invite-form">
                    <input type="hidden" id="vdnInviteBandId">
                    <input type="hidden" id="vdnInviteProfileId">
                    <div class="form-group">
                        <label for="vdnInviteMessage">Message to band <span class="req">*</span></label>
                        <textarea id="vdnInviteMessage" rows="4"
                            placeholder="Hi! We'd love to have you play on this date. We have a PA, backline, and an engaged audience that loves your genre..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="vdnInviteSubmitBtn">
                            Send Invite
                        </button>
                        <button type="button" class="btn btn-secondary" id="vdnInviteCancelBtn">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Interest Modal (used by guests clicking dark nights) -->
    <div id="interestModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <button class="modal-close" onclick="closeInterestModal()">✕</button>
            <div class="modal-content">
                <div id="interestModalBody">
                    <div class="modal-title">I Want to Play Here</div>
                    <div class="modal-subtitle" id="interestModalSubtitle"></div>
                    <form class="interest-form" id="interestForm">
                        <input type="hidden" id="interestVenueName" name="venue_name">
                        <input type="hidden" id="interestEventDate" name="event_date">

                        <div class="form-group">
                            <label>I am a</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="requester_type" value="band" checked>
                                    <span>Band</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="requester_type" value="promoter">
                                    <span>Promoter</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="requester_type" value="other">
                                    <span>Other</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="interestName">Your name / band name *</label>
                            <input type="text" id="interestName" name="requester_name" required placeholder="The Fog City Ramblers">
                        </div>

                        <div class="form-group">
                            <label for="interestEmail">Email *</label>
                            <input type="email" id="interestEmail" name="requester_email" required placeholder="booking@yourband.com">
                        </div>

                        <div class="form-group">
                            <label for="interestMessage">Message</label>
                            <textarea id="interestMessage" name="message" rows="4"
                                placeholder="Tell them a bit about you, your sound, your draw..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="interestSubmitBtn">Send Booking Interest</button>
                        </div>
                    </form>
                </div>
                <div id="interestThankYou" style="display:none">
                    <div class="modal-title">Request Sent!</div>
                    <p style="margin-top:1rem;color:var(--text-muted)">Your booking interest has been logged. Good luck!</p>
                    <button class="btn btn-secondary" style="margin-top:1.5rem" onclick="closeInterestModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()">✕</button>
            <div id="modalContent" class="modal-content"></div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    window.VDN_CONFIG = <?= json_encode([
        'venueGenres' => $venueGenres,
        'venueName'   => trim((string)($venueData['name'] ?? '')),
        'venueEmail'  => $venueEmail,
        'isOwner'     => $isOwner,
        'venueId'     => $venueId,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="/app/assets/js/app.js"></script>
</body>
</html>
