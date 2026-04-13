<?php
// $currentPage must be set by the including file
require_once __DIR__ . '/csrf.php';

$user = currentUser();
$currentPageKey = isset($currentPage) ? (string)$currentPage : '';
$isAdminUser = isAdmin();

if ($user) {
    $userType = (string)($user['type'] ?? '');
    $isBand = $userType === 'band';
    $isVenue = $userType === 'venue';

    // In phase 1, admin users follow venue-primary flows for shared pages.
    $primaryGroupId = ($isBand && !$isAdminUser) ? 'bands' : 'venues';
    $sharedPagePrimaryGroup = [
        'opportunities' => $primaryGroupId,
        'bookings' => $primaryGroupId,
    ];

    $quickStart = [
        'icon' => ($isBand && !$isAdminUser) ? '🎯' : '📌',
        'label' => ($isBand && !$isAdminUser) ? 'Find a Gig' : 'Post Open Date',
        'href' => ($isBand && !$isAdminUser) ? '/app/opportunities.php' : '/app/opportunities.php#post-open-date',
        'activeKey' => 'opportunities',
    ];

    $groupedNav = [
        [
            'id' => 'bands',
            'title' => 'For Bands',
            'items' => [
                [
                    'id' => 'bands-find-gig',
                    'group' => 'bands',
                    'icon' => '🎯',
                    'label' => 'Find a Gig',
                    'href' => '/app/opportunities.php',
                    'activeKey' => 'opportunities',
                    'enabled' => $isBand || $isAdminUser,
                    'hint' => 'Band workflow',
                ],
                [
                    'id' => 'bands-bookings',
                    'group' => 'bands',
                    'icon' => '🤝',
                    'label' => 'My Bookings',
                    'href' => '/app/bookings.php',
                    'activeKey' => 'bookings',
                    'enabled' => $isBand || $isAdminUser,
                    'hint' => 'Band workflow',
                ],
                [
                    'id' => 'bands-find-venue',
                    'group' => 'bands',
                    'icon' => '🏛',
                    'label' => 'Find a Venue',
                    'href' => '/app/venues',
                    'activeKey' => 'venues',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'bands-dark-nights',
                    'group' => 'bands',
                    'icon' => '🌑',
                    'label' => 'Dark Nights',
                    'href' => '/app/dark-nights.php',
                    'activeKey' => 'dark-nights',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
            ],
        ],
        [
            'id' => 'venues',
            'title' => 'For Venues',
            'items' => [
                [
                    'id' => 'venues-find-band',
                    'group' => 'venues',
                    'icon' => '🎸',
                    'label' => 'Find a Band',
                    'href' => '/app/bands.php',
                    'activeKey' => 'bands',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'venues-open-dates',
                    'group' => 'venues',
                    'icon' => '🗓️',
                    'label' => 'Open Dates & Inquiries',
                    'href' => '/app/opportunities.php#post-open-date',
                    'activeKey' => 'opportunities',
                    'enabled' => $isVenue || $isAdminUser,
                    'hint' => 'Venue workflow',
                ],
                [
                    'id' => 'venues-bookings',
                    'group' => 'venues',
                    'icon' => '📋',
                    'label' => 'Bookings',
                    'href' => '/app/bookings.php',
                    'activeKey' => 'bookings',
                    'enabled' => $isVenue || $isAdminUser,
                    'hint' => 'Venue workflow',
                ],
                [
                    'id' => 'venues-calendar',
                    'group' => 'venues',
                    'icon' => '📅',
                    'label' => 'Event Calendar',
                    'href' => '/app/calendar.php',
                    'activeKey' => 'calendar',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'venues-ticketing',
                    'group' => 'venues',
                    'icon' => '🎟️',
                    'label' => 'Ticketing',
                    'href' => '/app/events.php',
                    'activeKey' => 'events',
                    'enabled' => $isVenue || $isAdminUser,
                    'hint' => 'Venue workflow',
                ],
                [
                    'id' => 'venues-checkin',
                    'group' => 'venues',
                    'icon' => '📲',
                    'label' => 'Door Check-In',
                    'href' => '/app/checkin.php',
                    'activeKey' => 'checkin',
                    'enabled' => $isVenue || $isAdminUser,
                    'hint' => 'Venue workflow',
                ],
            ],
        ],
        [
            'id' => 'promoters',
            'title' => 'For Promoters',
            'items' => [
                [
                    'id' => 'promoters-book-venue',
                    'group' => 'promoters',
                    'icon' => '🏟️',
                    'label' => 'Book a Venue',
                    'href' => '#',
                    'activeKey' => '',
                    'enabled' => false,
                    'hint' => 'Coming soon',
                ],
                [
                    'id' => 'promoters-bookings',
                    'group' => 'promoters',
                    'icon' => '🧾',
                    'label' => 'Bookings',
                    'href' => '#',
                    'activeKey' => '',
                    'enabled' => false,
                    'hint' => 'Coming soon',
                ],
                [
                    'id' => 'promoters-ticketing',
                    'group' => 'promoters',
                    'icon' => '🎫',
                    'label' => 'Ticketing',
                    'href' => '#',
                    'activeKey' => '',
                    'enabled' => false,
                    'hint' => 'Coming soon',
                ],
                [
                    'id' => 'promoters-promote-show',
                    'group' => 'promoters',
                    'icon' => '📣',
                    'label' => 'Promote a Show',
                    'href' => '#',
                    'activeKey' => '',
                    'enabled' => false,
                    'hint' => 'Coming soon',
                ],
            ],
        ],
    ];

    $accountItems = [
        ['key' => 'dashboard', 'icon' => '🏠', 'label' => 'Dashboard', 'href' => '/app/dashboard.php'],
        ['key' => 'profile', 'icon' => '👤', 'label' => 'Profile', 'href' => '/app/profile.php'],
        ['key' => 'settings', 'icon' => '⚙️', 'label' => 'Settings', 'href' => '/app/settings.php'],
        ['key' => 'claims', 'icon' => '🧾', 'label' => 'Claims', 'href' => '/app/claims.php'],
    ];
    if ($isAdminUser) {
        $accountItems[] = ['key' => 'admin', 'icon' => '🔧', 'label' => 'Admin', 'href' => '/app/admin/'];
    }

    $isNavItemActive = static function(array $item) use ($currentPageKey, $sharedPagePrimaryGroup): bool {
        $activeKey = (string)($item['activeKey'] ?? '');
        if ($activeKey === '' || $activeKey !== $currentPageKey) {
            return false;
        }

        if (isset($sharedPagePrimaryGroup[$activeKey])) {
            return (($item['group'] ?? '') === $sharedPagePrimaryGroup[$activeKey]);
        }

        return true;
    };
} else {
    $publicNavItems = [
        ['icon' => '🌑', 'label' => 'Dark Nights', 'href' => '/app/dark-nights.php', 'key' => 'dark-nights'],
        ['icon' => '🎸', 'label' => 'Bands', 'href' => '/app/bands.php', 'key' => 'bands'],
        ['icon' => '🏛', 'label' => 'Venues', 'href' => '/app/venues', 'key' => 'venues'],
        ['icon' => '📅', 'label' => 'Shows', 'href' => '/app/calendar.php', 'key' => 'calendar'],
    ];
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

        <div class="sidebar-nav" aria-label="Primary Navigation">
            <div class="nav-quick-start">
                <div class="nav-section-title">Quick Start</div>
                <a href="<?= htmlspecialchars($quickStart['href']) ?>"
                   class="nav-link nav-link-quickstart <?= $currentPageKey === $quickStart['activeKey'] ? 'active' : '' ?>">
                    <span class="nav-link-main">
                        <span class="nav-icon"><?= $quickStart['icon'] ?></span>
                        <span class="nav-label-wrap">
                            <span class="nav-label"><?= htmlspecialchars($quickStart['label']) ?></span>
                            <span class="nav-hint">Get moving fast</span>
                        </span>
                    </span>
                </a>
            </div>

            <?php foreach ($groupedNav as $group): ?>
                <?php
                $groupId = (string)$group['id'];
                $isPrimaryGroup = ($groupId === $primaryGroupId);
                $groupClass = 'nav-group';
                if ($isPrimaryGroup) {
                    $groupClass .= ' primary expanded';
                }
                ?>
                <section class="<?= $groupClass ?>" data-collapsible="1" data-group-id="<?= htmlspecialchars($groupId) ?>">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $isPrimaryGroup ? 'true' : 'false' ?>">
                        <span class="nav-group-title"><?= htmlspecialchars((string)$group['title']) ?></span>
                        <span class="nav-group-chevron" aria-hidden="true">⌄</span>
                    </button>
                    <ul class="nav-group-items">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php
                            $enabled = !empty($item['enabled']);
                            $active = $enabled && $isNavItemActive($item);
                            ?>
                            <li class="nav-item">
                                <?php if ($enabled): ?>
                                    <a href="<?= htmlspecialchars((string)$item['href']) ?>" class="nav-link <?= $active ? 'active' : '' ?>">
                                        <span class="nav-link-main">
                                            <span class="nav-icon"><?= $item['icon'] ?></span>
                                            <span class="nav-label-wrap">
                                                <span class="nav-label"><?= htmlspecialchars((string)$item['label']) ?></span>
                                            </span>
                                        </span>
                                    </a>
                                <?php else: ?>
                                    <span class="nav-link nav-link-disabled" aria-disabled="true" tabindex="-1">
                                        <span class="nav-link-main">
                                            <span class="nav-icon"><?= $item['icon'] ?></span>
                                            <span class="nav-label-wrap">
                                                <span class="nav-label"><?= htmlspecialchars((string)$item['label']) ?></span>
                                                <span class="nav-hint"><?= htmlspecialchars((string)($item['hint'] ?? 'Coming soon')) ?></span>
                                            </span>
                                        </span>
                                        <span class="nav-lock" aria-hidden="true">🔒</span>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="sidebar-footer">
            <div class="nav-section-title">Account</div>
            <?php foreach ($accountItems as $item): ?>
                <a href="<?= htmlspecialchars((string)$item['href']) ?>" class="nav-link <?= $currentPageKey === (string)$item['key'] ? 'active' : '' ?>">
                    <span class="nav-link-main">
                        <span class="nav-icon"><?= $item['icon'] ?></span>
                        <span class="nav-label-wrap">
                            <span class="nav-label"><?= htmlspecialchars((string)$item['label']) ?></span>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
            <form method="post" action="/app/logout.php" style="margin:0;">
                <?= csrfInputField() ?>
                <button type="submit" class="nav-link nav-logout" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;">
                    <span class="nav-link-main">
                        <span class="nav-icon">🚪</span>
                        <span class="nav-label-wrap">
                            <span class="nav-label">Logout</span>
                        </span>
                    </span>
                </button>
            </form>
        </div>

        <?php else: ?>
        <ul class="sidebar-nav">
            <?php foreach ($publicNavItems as $item): ?>
            <li class="nav-item">
                <a href="<?= $item['href'] ?>" class="nav-link <?= $currentPageKey === $item['key'] ? 'active' : '' ?>">
                    <span class="nav-link-main">
                        <span class="nav-icon"><?= $item['icon'] ?></span>
                        <span class="nav-label-wrap">
                            <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
                        </span>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-footer">
            <a href="/app/login.php" class="nav-link">
                <span class="nav-link-main">
                    <span class="nav-icon">🔑</span>
                    <span class="nav-label-wrap">
                        <span class="nav-label">Log In</span>
                    </span>
                </span>
            </a>
            <a href="/app/signup.php" class="nav-link">
                <span class="nav-link-main">
                    <span class="nav-icon">✨</span>
                    <span class="nav-label-wrap">
                        <span class="nav-label">Sign Up</span>
                    </span>
                </span>
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
</div><!-- /.app-layout -->
<script>
window.APP_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
</script>
