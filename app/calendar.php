<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$currentPage = 'calendar';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF Shows — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">SF Shows</h1>
        </div>

        <!-- Calendar controls -->
        <div class="calendar-header" id="calendarRoot">
            <div class="week-nav">
                <a href="#" id="prevWeekBtn" class="btn btn-secondary btn-sm">&larr; Prev Week</a>
                <span class="week-label" id="weekLabel">Loading&hellip;</span>
                <a href="#" id="nextWeekBtn" class="btn btn-secondary btn-sm">Next Week &rarr;</a>
            </div>

            <div class="cal-controls-row">
                <div class="view-tabs" id="viewTabs">
                    <button class="view-tab active" data-view="list">📋 List View</button>
                    <button class="view-tab" data-view="cal">📅 Calendar View</button>
                    <button class="view-tab" data-view="stats">🏛 Venue Stats</button>
                </div>

                <div class="cal-search-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="calSearchQ" placeholder="Search band or venue&hellip;" class="search-input">
                </div>

                <label class="sf-only-toggle">
                    <input type="checkbox" id="sfOnlyCheck" checked>
                    <span>SF Only</span>
                </label>
            </div>
        </div>

        <!-- Main view container -->
        <div id="calViewContainer">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading shows&hellip;</p>
            </div>
        </div>
    </main>

    <!-- Show Detail Modal -->
    <div id="showDetailModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <button class="modal-close" onclick="closeShowModal()">&#10005;</button>
            <div id="showModalContent" class="modal-content">
                <div class="spinner" style="margin:2rem auto"></div>
            </div>
        </div>
    </div>

    <!-- toast, modal divs -->
    <div id="detailModal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal()">&#10005;</button>
            <div id="modalContent" class="modal-content">
                <div class="spinner"></div>
            </div>
        </div>
    </div>
    <div id="toast" class="toast"></div>

    <script src="/app/assets/js/app.js"></script>
</body>
</html>
