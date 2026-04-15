<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
$user    = currentUser();
$profile = getProfile($pdo, $user['id']);
$profileData = $profile['data'] ?? [];
$userTypeLabel = ucwords(str_replace('_', ' ', (string)$user['type']));

$displayName = $profileData['name'] ?? '';

// Stats
$totalBands  = $pdo->query("SELECT COUNT(*) FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.type='band' AND COALESCE(p.is_archived, 0) = 0")->fetchColumn();
$totalVenues = $pdo->query("SELECT COUNT(*) FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.type='venue' AND COALESCE(p.is_archived, 0) = 0")->fetchColumn();

// Recent bands (last 5)
$recentBands = $pdo->query("
    SELECT u.id, p.data, p.updated_at FROM users u
    JOIN profiles p ON p.user_id = u.id
    WHERE u.type = 'band' AND COALESCE(p.is_archived, 0) = 0
    ORDER BY p.updated_at DESC LIMIT 5
")->fetchAll();

// Recent venues (last 5)
$recentVenues = $pdo->query("
    SELECT u.id, p.data, p.updated_at FROM users u
    JOIN profiles p ON p.user_id = u.id
    WHERE u.type = 'venue' AND COALESCE(p.is_archived, 0) = 0
    ORDER BY p.updated_at DESC LIMIT 5
")->fetchAll();

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">
                Welcome back<?= $displayName ? ', ' . htmlspecialchars($displayName) : '' ?>
            </h1>
            <span class="badge badge-<?= htmlspecialchars((string)$user['type']) ?>"><?= htmlspecialchars($userTypeLabel) ?></span>
        </div>

        <?php if (empty($displayName)): ?>
        <div class="alert alert-warning cta-banner">
            <span>🎯 Your profile is incomplete — add your details so <?= $user['type'] === 'band' ? 'venues' : 'bands' ?> can find you!</span>
            <a href="/app/profile.php" class="btn btn-primary btn-sm">Complete Profile</a>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🎸</div>
                <div class="stat-number"><?= (int)$totalBands ?></div>
                <div class="stat-label">Bands Listed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏛</div>
                <div class="stat-number"><?= (int)$totalVenues ?></div>
                <div class="stat-label">Venues Listed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔥</div>
                <div class="stat-number"><?= (int)$totalBands + (int)$totalVenues ?></div>
                <div class="stat-label">Total Listings</div>
            </div>
        </div>

        <div class="dashboard-sections">
            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Recent Bands</h2>
                    <a href="/app/bands.php" class="section-link">View all →</a>
                </div>
                <div class="cards-list">
                    <?php foreach ($recentBands as $row):
                        $d = json_decode($row['data'], true) ?: [];
                        $name = $d['name'] ?: 'Unnamed Band';
                        $genres = $d['genres'] ?? [];
                        $location = $d['location'] ?? 'San Francisco, CA';
                        $lastMinute = $d['available_last_minute'] ?? false;
                    ?>
                    <div class="list-card" data-id="<?= (int)$row['id'] ?>" data-type="band" style="cursor:pointer" onclick="openDetailModal('band', <?= (int)$row['id'] ?>)">
                        <div class="list-card-main">
                            <div class="list-card-name"><?= htmlspecialchars($name) ?></div>
                            <div class="list-card-meta">
                                <?php foreach (array_slice($genres, 0, 3) as $g): ?>
                                    <span class="tag"><?= htmlspecialchars($g) ?></span>
                                <?php endforeach; ?>
                                <span class="meta-location">📍 <?= htmlspecialchars($location) ?></span>
                            </div>
                        </div>
                        <?php if ($lastMinute): ?>
                            <span class="badge badge-lastminute">Last Minute OK</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentBands)): ?>
                        <p class="empty-state">No bands listed yet.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Recent Venues</h2>
                    <a href="/app/venues" class="section-link">View all →</a>
                </div>
                <div class="cards-list">
                    <?php foreach ($recentVenues as $row):
                        $d = json_decode($row['data'], true) ?: [];
                        $name = $d['name'] ?: 'Unnamed Venue';
                        $neighborhood = $d['neighborhood'] ?? '';
                        $capacity = $d['capacity'] ?? 0;
                        $genres = $d['genres_welcomed'] ?? [];
                        $lastMinute = $d['open_to_last_minute'] ?? false;
                    ?>
                    <div class="list-card" data-id="<?= (int)$row['id'] ?>" data-type="venue" style="cursor:pointer" onclick="openDetailModal('venue', <?= (int)$row['id'] ?>)">
                        <div class="list-card-main">
                            <div class="list-card-name"><?= htmlspecialchars($name) ?></div>
                            <div class="list-card-meta">
                                <?php if ($neighborhood): ?>
                                    <span class="tag tag-venue"><?= htmlspecialchars($neighborhood) ?></span>
                                <?php endif; ?>
                                <?php if ($capacity > 0): ?>
                                    <span class="meta-cap">👥 <?= (int)$capacity ?> cap</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($lastMinute): ?>
                            <span class="badge badge-lastminute">Last Minute OK</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentVenues)): ?>
                        <p class="empty-state">No venues listed yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <!-- Detail Modal -->
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
