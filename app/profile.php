<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
$user    = currentUser();

// Admin edit mode: ?edit_id=N&edit_type=band|venue|recording_label
$editId   = null;
$editType = null;
$isAdminEdit = false;
if (isAdmin() && isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $editId      = (int)$_GET['edit_id'];
    $editType    = in_array($_GET['edit_type'] ?? '', ['band', 'venue', 'recording_label']) ? $_GET['edit_type'] : null;
    $isAdminEdit = true;

    // If edit_type not provided via URL, look it up from the DB
    if ($editType === null) {
        $typeRow = $pdo->prepare("SELECT type FROM users WHERE id = ?");
        $typeRow->execute([$editId]);
        $typeResult = $typeRow->fetchColumn();
        $editType = $typeResult ?: 'band';
    }

    // Temporarily override user context for the form
    $profile = getProfile($pdo, $editId);
    if (!$profile) {
        // Create empty profile context
        $profile = ['data' => [], 'type' => $editType];
    }
    $pd = $profile['data'] ?? [];

    // Override type for form rendering
    $editUser = ['id' => $editId, 'type' => $editType, 'email' => '', 'is_admin' => false];
    // Fetch the actual email
    $emailRow = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $emailRow->execute([$editId]);
    $editUser['email'] = $emailRow->fetchColumn() ?: '';
} else {
    $profile = getProfile($pdo, $user['id']);
    $pd      = $profile['data'] ?? [];
    $editUser = null;
}

$profileUser = $isAdminEdit ? $editUser : $user;
$isNew   = isset($_GET['new']) && $_GET['new'] === '1';

$currentPage = 'profile';

$genres        = ['Alternative','Classic Rock','Punk','Indie','Rock','Metal','Country','Blues','Jazz','Other'];
$neighborhoods = ['SoMa','Mission','Castro','Haight-Ashbury','North Beach','Tenderloin','Richmond','Sunset','SOMA','Downtown','Other'];
$stageSizes    = ['Small <10ft','Medium 10-20ft','Large 20ft+'];
$experiences   = ['Hobbyist','Semi-Pro','Professional','Touring'];
$venueSizeTargets = ['DIY / under 100 cap', '100-300 cap clubs', '300-800 cap rooms', '800+ cap theaters'];
$typeLabel = panicRoleLabel((string)$profileUser['type']);
$profileDiscoveryTarget = match ((string)$profileUser['type']) {
    'band' => 'venues and labels',
    'recording_label' => 'bands',
    default => 'bands',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <?php if ($isAdminEdit): ?>
            <div>
                <h1 class="page-title">Edit: <?= htmlspecialchars($pd['name'] ?? $profileUser['email']) ?></h1>
                <span class="page-subtitle" style="font-size:.9rem;opacity:.7">Admin editing <?= htmlspecialchars($profileUser['email']) ?></span>
            </div>
            <a href="/app/admin/" class="btn btn-sm" style="margin-left:auto">← Back to Admin</a>
            <?php else: ?>
            <h1 class="page-title">Your Profile</h1>
            <?php endif; ?>
            <span class="badge badge-<?= htmlspecialchars((string)$profileUser['type']) ?>"><?= htmlspecialchars($typeLabel) ?></span>
        </div>

        <?php if ($isNew): ?>
        <div class="alert alert-info">
            Welcome! Fill in your profile below so <?= htmlspecialchars($profileDiscoveryTarget) ?> can discover you.
        </div>
        <?php endif; ?>

        <form id="profileForm" class="profile-form card-form">

        <?php if ($profileUser['type'] === 'band'): ?>
            <!-- ===== BAND FORM ===== -->
            <div class="form-section">
                <h2 class="form-section-title">Basic Info</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Band Name *</label>
                        <input type="text" id="name" name="name"
                               value="<?= htmlspecialchars($pd['name'] ?? '') ?>"
                               placeholder="Your band's name" required>
                    </div>
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location"
                               value="<?= htmlspecialchars($pd['location'] ?? 'San Francisco, CA') ?>"
                               placeholder="City, State">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4"
                              placeholder="Describe your band's sound, history, and vibe..."><?= htmlspecialchars($pd['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Genres</h2>
                <div class="checkbox-grid">
                    <?php foreach ($genres as $g): ?>
                    <label class="checkbox-option">
                        <input type="checkbox" name="genres[]" value="<?= htmlspecialchars($g) ?>"
                               <?= in_array($g, $pd['genres'] ?? []) ? 'checked' : '' ?>>
                        <span class="checkbox-label"><?= htmlspecialchars($g) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Band Members</h2>
                <div id="membersContainer">
                    <?php foreach (($pd['members'] ?? []) as $i => $member): ?>
                    <div class="member-row">
                        <input type="text" name="members[]" class="member-input"
                               value="<?= htmlspecialchars($member) ?>" placeholder="Member name / instrument">
                        <button type="button" class="btn btn-danger btn-sm remove-member">✕</button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($pd['members'])): ?>
                    <div class="member-row">
                        <input type="text" name="members[]" class="member-input" placeholder="Member name / instrument">
                        <button type="button" class="btn btn-danger btn-sm remove-member">✕</button>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="addMemberBtn">+ Add Member</button>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Contact & Links</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_email">Contact Email</label>
                        <input type="email" id="contact_email" name="contact_email"
                               value="<?= htmlspecialchars($pd['contact_email'] ?? '') ?>"
                               placeholder="booking@yourband.com">
                    </div>
                    <div class="form-group">
                        <label for="contact_phone">Contact Phone</label>
                        <input type="tel" id="contact_phone" name="contact_phone"
                               value="<?= htmlspecialchars($pd['contact_phone'] ?? '') ?>"
                               placeholder="415-555-0000">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website"
                               value="<?= htmlspecialchars($pd['website'] ?? '') ?>"
                               placeholder="https://yourband.com">
                    </div>
                    <div class="form-group">
                        <label for="facebook">Facebook</label>
                        <input type="url" id="facebook" name="facebook"
                               value="<?= htmlspecialchars($pd['facebook'] ?? '') ?>"
                               placeholder="https://facebook.com/yourband">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="instagram">Instagram</label>
                        <input type="url" id="instagram" name="instagram"
                               value="<?= htmlspecialchars($pd['instagram'] ?? '') ?>"
                               placeholder="https://instagram.com/yourband">
                    </div>
                    <div class="form-group">
                        <label for="spotify">Spotify</label>
                        <input type="url" id="spotify" name="spotify"
                               value="<?= htmlspecialchars($pd['spotify'] ?? '') ?>"
                               placeholder="https://open.spotify.com/artist/...">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="youtube">YouTube</label>
                        <input type="url" id="youtube" name="youtube"
                               value="<?= htmlspecialchars($pd['youtube'] ?? '') ?>"
                               placeholder="https://youtube.com/...">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Performance Details</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="experience">Experience Level</label>
                        <select id="experience" name="experience">
                            <option value="">Select level...</option>
                            <?php foreach ($experiences as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>"
                                    <?= (($pd['experience'] ?? '') === $e) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="set_length_min">Set Length Min (minutes)</label>
                        <input type="number" id="set_length_min" name="set_length_min" min="1" max="300"
                               value="<?= (int)($pd['set_length_min'] ?? 45) ?>">
                    </div>
                    <div class="form-group">
                        <label for="set_length_max">Set Length Max (minutes)</label>
                        <input type="number" id="set_length_max" name="set_length_max" min="1" max="300"
                               value="<?= (int)($pd['set_length_max'] ?? 90) ?>">
                    </div>
                </div>
                <div class="checkbox-group-inline">
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="has_own_equipment" name="has_own_equipment" value="1"
                               <?= !empty($pd['has_own_equipment']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Has Own Equipment / PA</span>
                    </label>
                    <label class="checkbox-toggle checkbox-toggle-highlight">
                        <input type="checkbox" id="available_last_minute" name="available_last_minute" value="1"
                               <?= !empty($pd['available_last_minute']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">⚡ Available for Last Minute Bookings</span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Notes</h2>
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Anything else venues should know? Rider requirements, setup needs, etc."><?= htmlspecialchars($pd['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Availability &amp; Booking</h2>
                <div class="checkbox-group-inline">
                    <label class="checkbox-toggle checkbox-toggle-highlight">
                        <input type="checkbox" id="seeking_gigs" name="seeking_gigs" value="1"
                               <?= !empty($pd['seeking_gigs']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Actively seeking gigs</span>
                    </label>
                </div>
                <div class="form-group" style="margin-top:1rem">
                    <label>Available days</label>
                    <div class="day-check-group">
                        <?php
                        $availDays = $pd['available_days'] ?? [];
                        $weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                        foreach ($weekdays as $day):
                        ?>
                        <label class="checkbox-option">
                            <input type="checkbox" name="available_days[]" value="<?= $day ?>"
                                   <?= in_array($day, $availDays) ? 'checked' : '' ?>>
                            <span class="checkbox-label"><?= $day ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-row" style="margin-top:1rem">
                    <div class="form-group">
                        <label for="next_available">Available from</label>
                        <input type="date" id="next_available" name="next_available"
                               value="<?= htmlspecialchars($pd['next_available'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="gig_radius">Touring range</label>
                        <select id="gig_radius" name="gig_radius">
                            <option value="">Select range...</option>
                            <?php foreach (['SF only','Bay Area','CA & NV','National / Touring'] as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"
                                    <?= (($pd['gig_radius'] ?? '') === $r) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-top:1rem">
                    <label for="booking_contact">Booking contact email or URL</label>
                    <input type="text" id="booking_contact" name="booking_contact"
                           value="<?= htmlspecialchars($pd['booking_contact'] ?? '') ?>"
                           placeholder="booking@yourband.com or https://...">
                </div>
            </div>

        <?php elseif ($profileUser['type'] === 'recording_label'): ?>
            <!-- ===== RECORDING LABEL FORM ===== -->
            <div class="form-section">
                <h2 class="form-section-title">Basic Info</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Label Name *</label>
                        <input type="text" id="name" name="name"
                               value="<?= htmlspecialchars($pd['name'] ?? '') ?>"
                               placeholder="Your label name" required>
                    </div>
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city"
                               value="<?= htmlspecialchars($pd['city'] ?? 'San Francisco, CA') ?>"
                               placeholder="City, State">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">A&amp;R Focus</label>
                    <textarea id="description" name="description" rows="4"
                              placeholder="What artists are you actively scouting and signing?"><?= htmlspecialchars($pd['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Genres &amp; Market</h2>
                <div class="checkbox-grid">
                    <?php foreach ($genres as $g): ?>
                    <label class="checkbox-option">
                        <input type="checkbox" name="genres_focus[]" value="<?= htmlspecialchars($g) ?>"
                               <?= in_array($g, $pd['genres_focus'] ?? [], true) ? 'checked' : '' ?>>
                        <span class="checkbox-label"><?= htmlspecialchars($g) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-group" style="margin-top:1rem;">
                    <label for="roster_highlights">Roster Highlights (one artist per line)</label>
                    <textarea id="roster_highlights" name="roster_highlights_text" rows="4"
                              placeholder="Artist Name&#10;Artist Name"><?= htmlspecialchars(implode("\n", (array)($pd['roster_highlights'] ?? []))) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="preferred_venue_sizes">Preferred Live Rooms</label>
                    <div class="day-check-group">
                        <?php foreach ($venueSizeTargets as $size): ?>
                        <label class="checkbox-option">
                            <input type="checkbox" name="preferred_venue_sizes[]" value="<?= htmlspecialchars($size) ?>"
                                   <?= in_array($size, $pd['preferred_venue_sizes'] ?? [], true) ? 'checked' : '' ?>>
                            <span class="checkbox-label"><?= htmlspecialchars($size) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Submissions &amp; Contact</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_email">Contact Email</label>
                        <input type="email" id="contact_email" name="contact_email"
                               value="<?= htmlspecialchars($pd['contact_email'] ?? '') ?>"
                               placeholder="hello@yourlabel.com">
                    </div>
                    <div class="form-group">
                        <label for="contact_phone">Contact Phone</label>
                        <input type="tel" id="contact_phone" name="contact_phone"
                               value="<?= htmlspecialchars($pd['contact_phone'] ?? '') ?>"
                               placeholder="415-555-0000">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website"
                               value="<?= htmlspecialchars($pd['website'] ?? '') ?>"
                               placeholder="https://yourlabel.com">
                    </div>
                    <div class="form-group">
                        <label for="instagram">Instagram</label>
                        <input type="url" id="instagram" name="instagram"
                               value="<?= htmlspecialchars($pd['instagram'] ?? '') ?>"
                               placeholder="https://instagram.com/yourlabel">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="submission_email">Submission Email</label>
                        <input type="email" id="submission_email" name="submission_email"
                               value="<?= htmlspecialchars($pd['submission_email'] ?? '') ?>"
                               placeholder="demos@yourlabel.com">
                    </div>
                    <div class="form-group">
                        <label for="submission_url">Submission Form URL</label>
                        <input type="url" id="submission_url" name="submission_url"
                               value="<?= htmlspecialchars($pd['submission_url'] ?? '') ?>"
                               placeholder="https://yourlabel.com/submit">
                    </div>
                </div>
                <div class="checkbox-group-inline">
                    <label class="checkbox-toggle checkbox-toggle-highlight">
                        <input type="checkbox" id="actively_signing" name="actively_signing" value="1"
                               <?= !empty($pd['actively_signing']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Actively signing</span>
                    </label>
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="attends_live_shows" name="attends_live_shows" value="1"
                               <?= !empty($pd['attends_live_shows']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Attends live shows for scouting</span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Notes</h2>
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Anything bands should know before submitting?"><?= htmlspecialchars($pd['notes'] ?? '') ?></textarea>
                </div>
            </div>

        <?php else: ?>
            <!-- ===== VENUE FORM ===== -->
            <div class="form-section">
                <h2 class="form-section-title">Basic Info</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Venue Name *</label>
                        <input type="text" id="name" name="name"
                               value="<?= htmlspecialchars($pd['name'] ?? '') ?>"
                               placeholder="Your venue's name" required>
                    </div>
                    <div class="form-group">
                        <label for="neighborhood">Neighborhood</label>
                        <select id="neighborhood" name="neighborhood">
                            <option value="">Select neighborhood...</option>
                            <?php foreach ($neighborhoods as $n): ?>
                            <option value="<?= htmlspecialchars($n) ?>"
                                    <?= (($pd['neighborhood'] ?? '') === $n) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($n) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address"
                               value="<?= htmlspecialchars($pd['address'] ?? '') ?>"
                               placeholder="123 Main St, San Francisco, CA 94103">
                    </div>
                    <div class="form-group">
                        <label for="capacity">Capacity</label>
                        <input type="number" id="capacity" name="capacity" min="0"
                               value="<?= (int)($pd['capacity'] ?? 0) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4"
                              placeholder="Describe your venue, atmosphere, and what you're looking for..."><?= htmlspecialchars($pd['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Genres Welcomed</h2>
                <div class="checkbox-grid">
                    <?php foreach ($genres as $g): ?>
                    <label class="checkbox-option">
                        <input type="checkbox" name="genres_welcomed[]" value="<?= htmlspecialchars($g) ?>"
                               <?= in_array($g, $pd['genres_welcomed'] ?? []) ? 'checked' : '' ?>>
                        <span class="checkbox-label"><?= htmlspecialchars($g) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Contact & Links</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_email">Contact Email</label>
                        <input type="email" id="contact_email" name="contact_email"
                               value="<?= htmlspecialchars($pd['contact_email'] ?? '') ?>"
                               placeholder="booking@yourvenue.com">
                    </div>
                    <div class="form-group">
                        <label for="contact_phone">Contact Phone</label>
                        <input type="tel" id="contact_phone" name="contact_phone"
                               value="<?= htmlspecialchars($pd['contact_phone'] ?? '') ?>"
                               placeholder="415-555-0000">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website"
                               value="<?= htmlspecialchars($pd['website'] ?? '') ?>"
                               placeholder="https://yourvenue.com">
                    </div>
                    <div class="form-group">
                        <label for="facebook">Facebook</label>
                        <input type="url" id="facebook" name="facebook"
                               value="<?= htmlspecialchars($pd['facebook'] ?? '') ?>"
                               placeholder="https://facebook.com/yourvenue">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="instagram">Instagram</label>
                        <input type="url" id="instagram" name="instagram"
                               value="<?= htmlspecialchars($pd['instagram'] ?? '') ?>"
                               placeholder="https://instagram.com/yourvenue">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Equipment & Stage</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="stage_size">Stage Size</label>
                        <select id="stage_size" name="stage_size">
                            <option value="">Select size...</option>
                            <?php foreach ($stageSizes as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"
                                    <?= (($pd['stage_size'] ?? '') === $s) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="checkbox-group-inline">
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="has_pa" name="has_pa" value="1"
                               <?= !empty($pd['has_pa']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Has PA System</span>
                    </label>
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="has_drums" name="has_drums" value="1"
                               <?= !empty($pd['has_drums']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Has Drum Kit</span>
                    </label>
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="has_backline" name="has_backline" value="1"
                               <?= !empty($pd['has_backline']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Has Backline</span>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Venue Details</h2>
                <div class="checkbox-group-inline">
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="cover_charge" name="cover_charge" value="1"
                               <?= !empty($pd['cover_charge']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Cover Charge</span>
                    </label>
                    <label class="checkbox-toggle">
                        <input type="checkbox" id="bar_service" name="bar_service" value="1"
                               <?= !empty($pd['bar_service']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">Bar Service</span>
                    </label>
                    <label class="checkbox-toggle checkbox-toggle-highlight">
                        <input type="checkbox" id="open_to_last_minute" name="open_to_last_minute" value="1"
                               <?= !empty($pd['open_to_last_minute']) ? 'checked' : '' ?>>
                        <span class="toggle-track"></span>
                        <span class="toggle-label">⚡ Open to Last Minute Bookings</span>
                    </label>
                </div>
                <div class="form-row" style="margin-top:1rem">
                    <div class="form-group">
                        <label for="booking_lead_time_days">Booking Lead Time (days, 0 = same day)</label>
                        <input type="number" id="booking_lead_time_days" name="booking_lead_time_days" min="0"
                               value="<?= (int)($pd['booking_lead_time_days'] ?? 0) ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="form-section-title">Notes</h2>
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Anything else bands should know? Deal terms, load-in info, etc."><?= htmlspecialchars($pd['notes'] ?? '') ?></textarea>
                </div>
            </div>
        <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg" id="saveProfileBtn">
                    Save Profile
                </button>
                <span class="form-save-status" id="saveStatus"></span>
            </div>
        </form>
    </main>

    <div id="toast" class="toast"></div>
    <script src="/app/assets/js/app.js"></script>
    <script>
        const USER_TYPE = '<?= $profileUser['type'] ?>';
        const USER_ID   = <?= (int)$profileUser['id'] ?>;
        const csrfToken = <?= json_encode(csrfToken()) ?>;

        document.getElementById('profileForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('saveProfileBtn');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            const formData = new FormData(this);
            const data = {};

            // Collect all fields
            for (const [key, value] of formData.entries()) {
                if (key.endsWith('[]')) {
                    const realKey = key.slice(0, -2);
                    if (!data[realKey]) data[realKey] = [];
                    if (value.trim()) data[realKey].push(value);
                } else {
                    data[key] = value;
                }
            }

            // Handle checkboxes that might be unchecked
            const boolFields = USER_TYPE === 'band'
                ? ['has_own_equipment', 'available_last_minute', 'seeking_gigs']
                : (USER_TYPE === 'recording_label'
                    ? ['actively_signing', 'attends_live_shows']
                    : ['has_pa', 'has_drums', 'has_backline', 'cover_charge', 'bar_service', 'open_to_last_minute']);

            boolFields.forEach(f => {
                data[f] = !!formData.get(f);
            });

            // Numeric fields
            if (USER_TYPE === 'band') {
                data.set_length_min = parseInt(data.set_length_min) || 0;
                data.set_length_max = parseInt(data.set_length_max) || 0;
                if (!data.genres) data.genres = [];
                if (!data.members) data.members = [];
                // Filter empty members
                data.members = (data.members || []).filter(m => m.trim() !== '');
                // Availability fields
                if (!data.available_days) data.available_days = [];
            } else if (USER_TYPE === 'recording_label') {
                if (!data.genres_focus) data.genres_focus = [];
                if (!data.preferred_venue_sizes) data.preferred_venue_sizes = [];
                const rosterRaw = (data.roster_highlights_text || '').split('\n');
                data.roster_highlights = rosterRaw
                    .map(v => v.trim())
                    .filter(v => v.length > 0);
                delete data.roster_highlights_text;
            } else {
                data.capacity = parseInt(data.capacity) || 0;
                data.booking_lead_time_days = parseInt(data.booking_lead_time_days) || 0;
                if (!data.genres_welcomed) data.genres_welcomed = [];
            }

            const endpoint = USER_TYPE === 'band'
                ? `/api/bands/${USER_ID}`
                : (USER_TYPE === 'recording_label'
                    ? `/api/labels/${USER_ID}`
                    : `/api/venues/${USER_ID}`);

            try {
                const resp = await fetch(endpoint, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(data)
                });
                const result = await resp.json();
                if (resp.ok) {
                    showToast('Profile saved!', 'success');
                } else {
                    showToast(result.error || 'Save failed', 'error');
                }
            } catch (err) {
                showToast('Network error', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Profile';
            }
        });
    </script>
</body>
</html>
