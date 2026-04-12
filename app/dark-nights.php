<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
$user        = currentUser(); // null for guests — that's fine
$currentPage = 'dark-nights';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dark Nights — Panic Booking</title>
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
                <h1 class="page-title">Dark Nights</h1>
                <span class="page-subtitle">SF venues with open nights — connect and fill them.</span>
            </div>
        </div>

        <!-- Controls row -->
        <div class="dn-controls">
            <div class="dn-days-toggle">
                <button class="btn btn-secondary btn-sm dn-days-btn active" data-days="30">🗓 Next 30 days</button>
                <button class="btn btn-secondary btn-sm dn-days-btn" data-days="60">Next 60 days</button>
                <button class="btn btn-secondary btn-sm dn-days-btn" data-days="90">Next 90 days</button>
            </div>
            <div class="search-input-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="dnVenueSearch" placeholder="Filter venues..." class="search-input">
            </div>
            <select id="dnSourceFilter" class="search-select">
                <option value="">All Sources</option>
                <option value="foopee">The List</option>
                <option value="gamh">GAMH</option>
                <option value="warfield">Warfield</option>
                <option value="regency">Regency</option>
                <option value="fillmore">Fillmore</option>
            </select>
        </div>

        <!-- Stats bar -->
        <div class="dn-stats-bar" id="dnStatsBar">Loading...</div>

        <!-- Grid -->
        <div class="dn-grid-wrap">
            <div id="darkNightsGrid">
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>Loading dark nights...</p>
                </div>
            </div>
        </div>
    </main>

    <!-- "I Want to Play Here" Modal -->
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

    <!-- Detail Modal (for showing show info on booked cells) -->
    <div id="detailModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()">✕</button>
            <div id="modalContent" class="modal-content">
                <div class="spinner"></div>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>
    <script src="/app/assets/js/app.js"></script>
</body>
</html>
