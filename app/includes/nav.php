<?php
// $currentPage must be set by the including file
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../../lib/user_roles.php';

$user = currentUser();
$currentPageKey = isset($currentPage) ? (string)$currentPage : '';
$isAdminUser = isAdmin();

if ($user) {
    $userType = (string)($user['type'] ?? '');
    $isBand = $userType === 'band';
    $isVenue = $userType === 'venue';
    $isRecordingLabel = $userType === 'recording_label';

    $roleContexts = is_array($user['role_contexts'] ?? null) ? $user['role_contexts'] : [];
    $roleBadgesRaw = is_array($user['role_badges'] ?? null) ? $user['role_badges'] : [];
    $activeRoleKey = (string)($user['active_role_key'] ?? '');

    // In phase 1, admin users follow venue-primary flows for shared pages.
    if ($isBand && !$isAdminUser) {
        $primaryGroupId = 'bands';
    } elseif ($isRecordingLabel && !$isAdminUser) {
        $primaryGroupId = 'labels';
    } else {
        $primaryGroupId = 'venues';
    }

    $sharedPagePrimaryGroup = [
        'opportunities' => $primaryGroupId,
        'bookings' => $primaryGroupId,
    ];

    if ($isBand && !$isAdminUser) {
        $quickStart = [
            'icon' => '🎯',
            'label' => 'Find a Gig',
            'href' => '/app/opportunities.php',
            'activeKey' => 'opportunities',
        ];
    } elseif ($isRecordingLabel && !$isAdminUser) {
        $quickStart = [
            'icon' => '🧭',
            'label' => 'Scout Bands',
            'href' => '/app/bands.php',
            'activeKey' => 'bands',
        ];
    } else {
        $quickStart = [
            'icon' => '📌',
            'label' => 'Post Open Date',
            'href' => '/app/opportunities.php#post-open-date',
            'activeKey' => 'opportunities',
        ];
    }

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
                [
                    'id' => 'venues-dark-nights',
                    'group' => 'venues',
                    'icon' => '🌑',
                    'label' => 'My Dark Nights',
                    'href' => '/app/venue-dark-nights.php',
                    'activeKey' => 'venue-dark-nights',
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
        [
            'id' => 'labels',
            'title' => 'For Recording Labels',
            'items' => [
                [
                    'id' => 'labels-scout-bands',
                    'group' => 'labels',
                    'icon' => '🎧',
                    'label' => 'Scout Bands',
                    'href' => '/app/bands.php',
                    'activeKey' => 'bands',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'labels-calendar',
                    'group' => 'labels',
                    'icon' => '📅',
                    'label' => 'Live Calendar',
                    'href' => '/app/calendar.php',
                    'activeKey' => 'calendar',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'labels-opportunities',
                    'group' => 'labels',
                    'icon' => '🗂️',
                    'label' => 'Opportunity Feed',
                    'href' => '/app/opportunities.php',
                    'activeKey' => 'opportunities',
                    'enabled' => true,
                    'hint' => 'Always available',
                ],
                [
                    'id' => 'labels-submissions',
                    'group' => 'labels',
                    'icon' => '📥',
                    'label' => 'Submission Inbox',
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

    $typeLabel = panicRoleLabel($userType);

    $badgeSeen = [];
    $roleBadges = [];
    foreach ($roleBadgesRaw as $rawBadge) {
        $badge = trim((string)$rawBadge);
        if ($badge === '' || isset($badgeSeen[$badge])) {
            continue;
        }
        $badgeSeen[$badge] = true;
        $roleBadges[] = [
            'key' => $badge,
            'label' => panicRoleLabel($badge),
        ];
    }
    if (empty($roleBadges)) {
        $roleBadges[] = ['key' => $userType, 'label' => $typeLabel];
    }

    $roleContextOptions = [];
    foreach ($roleContexts as $ctx) {
        $ctxKey = trim((string)($ctx['key'] ?? ''));
        if ($ctxKey === '') {
            continue;
        }
        $ctxType = trim((string)($ctx['type'] ?? ''));
        $ctxName = trim((string)($ctx['name'] ?? ''));
        if ($ctxName === '') {
            $ctxName = (string)($ctx['email'] ?? 'Account');
        }
        $roleContextOptions[] = [
            'key' => $ctxKey,
            'label' => panicRoleLabel($ctxType) . ' · ' . $ctxName,
        ];
    }
    $canSwitchRole = count($roleContextOptions) > 1;
} else {
    $publicNavItems = [
        ['icon' => '🌑', 'label' => 'Dark Nights', 'href' => '/app/dark-nights.php', 'key' => 'dark-nights'],
        ['icon' => '🗓️', 'label' => 'Venue Calendar', 'href' => '/app/venue-dark-nights.php', 'key' => 'venue-dark-nights'],
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
                <?php foreach ($roleBadges as $badge): ?>
                    <span class="user-type-badge badge-<?= htmlspecialchars((string)$badge['key']) ?>"><?= htmlspecialchars((string)$badge['label']) ?></span>
                <?php endforeach; ?>
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
            <div class="sidebar-user-email"><?= htmlspecialchars((string)($user['account_email'] ?? $user['email'] ?? '')) ?></div>
            <div class="role-badges">
                <?php foreach ($roleBadges as $badge): ?>
                    <span class="badge badge-<?= htmlspecialchars((string)$badge['key']) ?>"><?= htmlspecialchars((string)$badge['label']) ?></span>
                <?php endforeach; ?>
            </div>

            <?php if ($canSwitchRole): ?>
                <form method="post" action="/app/switch-role.php" class="role-switcher-form">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="next" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/app/dashboard.php')) ?>">
                    <label for="role_switcher" class="role-switcher-label">Active role</label>
                    <select id="role_switcher" name="role_key" class="role-switcher-select" onchange="this.form.submit()">
                        <?php foreach ($roleContextOptions as $opt): ?>
                            <option value="<?= htmlspecialchars((string)$opt['key']) ?>" <?= ($activeRoleKey === (string)$opt['key']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
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
