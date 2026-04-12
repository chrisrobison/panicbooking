<?php
// $currentPage must be set by the including file
$user = currentUser();

$navItems = [];
if ($user) {
    $navItems['dashboard'] = ['icon' => '🏠', 'label' => 'Dashboard', 'href' => '/app/dashboard.php'];
    $navItems['opportunities'] = ['icon' => '🗓️', 'label' => 'Opportunities', 'href' => '/app/opportunities.php'];
    $navItems['bookings'] = ['icon' => '🤝', 'label' => 'Bookings', 'href' => '/app/bookings.php'];
    if ($user['type'] === 'venue' || isAdmin()) {
        $navItems['events'] = ['icon' => '🎟️', 'label' => 'Ticketing', 'href' => '/app/events.php'];
        $navItems['checkin'] = ['icon' => '📲', 'label' => 'Check-In', 'href' => '/app/checkin.php'];
    }
}
$navItems['dark-nights'] = ['icon' => '🌑', 'label' => 'Dark Nights', 'href' => '/app/dark-nights.php'];
$navItems['bands']       = ['icon' => '🎸', 'label' => 'Bands',       'href' => '/app/bands.php'];
$navItems['venues']      = ['icon' => '🏛',  'label' => 'Venues',      'href' => '/app/venues'];
$navItems['calendar']    = ['icon' => '📅', 'label' => 'Shows',       'href' => '/app/calendar.php'];
if ($user) {
    $navItems['profile']  = ['icon' => '👤', 'label' => 'Profile',  'href' => '/app/profile.php'];
    $navItems['settings'] = ['icon' => '⚙️',  'label' => 'Settings', 'href' => '/app/settings.php'];
}
if (isAdmin()) {
    $navItems['admin'] = ['icon' => '🔧', 'label' => 'Admin', 'href' => '/app/admin/'];
}
?>
<div class="app-layout">
    <!-- Mobile top bar -->
    <header class="topbar">
        <button class="hamburger" id="hamburger" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
        <a href="<?= $user ? '/app/dashboard.php' : '/app/dark-nights.php' ?>" class="topbar-logo">
            <span class="logo-icon">⚡</span>
            <span class="logo-text">Panic Booking</span>
        </a>
        <div class="topbar-user">
            <?php if ($user): ?>
            <span class="user-type-badge badge-<?= htmlspecialchars($user['type']) ?>"><?= ucfirst(htmlspecialchars($user['type'])) ?></span>
            <?php endif; ?>
        </div>
    </header>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <a href="<?= $user ? '/app/dashboard.php' : '/app/dark-nights.php' ?>" class="logo-link">
                <span class="logo-icon">⚡</span>
                <span class="logo-text">Panic Booking</span>
            </a>
        </div>

        <?php if ($user): ?>
        <div class="sidebar-user">
            <div class="sidebar-user-email"><?= htmlspecialchars($user['email']) ?></div>
            <span class="badge badge-<?= htmlspecialchars($user['type']) ?>"><?= ucfirst(htmlspecialchars($user['type'])) ?></span>
        </div>
        <?php endif; ?>

        <ul class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
            <li class="nav-item">
                <a href="<?= $item['href'] ?>"
                   class="nav-link <?= (isset($currentPage) && $currentPage === $key) ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $item['icon'] ?></span>
                    <span class="nav-label"><?= $item['label'] ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-footer">
            <?php if ($user): ?>
            <a href="/app/logout.php" class="nav-link nav-logout">
                <span class="nav-icon">🚪</span>
                <span class="nav-label">Logout</span>
            </a>
            <?php else: ?>
            <a href="/app/login.php" class="nav-link">
                <span class="nav-icon">🔑</span>
                <span class="nav-label">Log In</span>
            </a>
            <a href="/app/signup.php" class="nav-link">
                <span class="nav-icon">✨</span>
                <span class="nav-label">Sign Up</span>
            </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
</div><!-- /.app-layout -->
