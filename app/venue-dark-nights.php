<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
$user = currentUser();

if (!$user || ($user['type'] !== 'venue' && !($user['is_admin'] ?? false))) {
    header('Location: /app/dashboard.php');
    exit;
}

$currentPage = 'venue-dark-nights';

// Load venue profile for display + passing genres to JS
$pStmt = $pdo->prepare("SELECT data FROM profiles WHERE user_id = ? AND type = 'venue' LIMIT 1");
$pStmt->execute([(int)$user['id']]);
$pRow      = $pStmt->fetch();
$venueData = $pRow ? (json_decode($pRow['data'], true) ?: []) : [];
$venueName = htmlspecialchars(trim((string)($venueData['name'] ?? 'Your Venue')));
$venueGenres = (array)($venueData['genres_welcomed'] ?? []);
$venueEmail  = (string)($venueData['contact_email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dark Nights — <?= $venueName ?> — Panic Booking</title>
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
                <h1 class="page-title">🌑 My Dark Nights</h1>
                <span class="page-subtitle"><?= $venueName ?> — your calendar at a glance. Click any dark night to find a band.</span>
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
            <span class="vdn-legend-item"><span class="vdn-legend-dot dark"></span> Dark night — click to find a band</span>
            <span class="vdn-legend-item"><span class="vdn-legend-dot past"></span> Past</span>
        </div>
    </main>

    <!-- Band Browser Modal -->
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

    <!-- Reuse existing detail modal for show info -->
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
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="/app/assets/js/app.js"></script>
</body>
</html>
