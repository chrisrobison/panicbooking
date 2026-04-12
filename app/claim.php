<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$type = trim((string)($_GET['type'] ?? ''));
$entityId = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['band', 'venue'], true) || $entityId <= 0) {
    http_response_code(400);
    exit('Invalid claim target');
}

if (!isLoggedIn()) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/app/claim.php');
    header('Location: /app/login.php?next=' . $next);
    exit;
}

$user = currentUser();
$currentPage = 'claims';

$entity = null;
$claimError = '';
$stmt = $pdo->prepare("
    SELECT u.id, u.email, u.type, p.data, p.is_generic, p.is_claimed, COALESCE(p.is_archived, 0) AS is_archived
    FROM users u
    JOIN profiles p ON p.user_id = u.id
    WHERE u.id = ? AND u.type = ? AND COALESCE(p.is_archived, 0) = 0
    LIMIT 1
");
$stmt->execute([$entityId, $type]);
$row = $stmt->fetch();
if (!$row) {
    $claimError = 'Profile was not found.';
} else {
    $entity = [
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'type' => (string)$row['type'],
        'data' => json_decode((string)$row['data'], true) ?: [],
        'is_generic' => (bool)$row['is_generic'],
        'is_claimed' => (bool)$row['is_claimed'],
        'is_archived' => (bool)$row['is_archived'],
    ];
}

if (!$claimError && $user['id'] === $entityId) {
    $claimError = 'This profile already belongs to your account.';
}
if (!$claimError && $user['type'] !== $type && !$user['is_admin']) {
    $claimError = 'Your account type does not match this profile.';
}
if (!$claimError && $entity && !$entity['is_generic']) {
    $claimError = 'This profile is already managed and cannot be claimed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Profile — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Claim <?= $type === 'band' ? 'Band' : 'Venue' ?> Profile</h1>
            <span class="page-subtitle">Request ownership of seeded data</span>
        </div>

        <?php if ($claimError): ?>
            <div class="alert alert-warning" style="max-width:860px">
                <?= htmlspecialchars($claimError) ?>
                <div style="margin-top:.8rem">
                    <a href="<?= $type === 'band' ? '/app/bands.php' : '/app/venues' ?>" class="btn btn-secondary btn-sm">Back to list</a>
                    <a href="/app/claims.php" class="btn btn-primary btn-sm">My Claims</a>
                </div>
            </div>
        <?php else: ?>
            <?php
            $entityName = trim((string)($entity['data']['name'] ?? ''));
            $entityWebsite = trim((string)($entity['data']['website'] ?? ''));
            $entityCity = $type === 'band'
                ? trim((string)($entity['data']['location'] ?? 'San Francisco, CA'))
                : trim((string)($entity['data']['neighborhood'] ?? 'San Francisco, CA'));
            $defaultRepName = strstr((string)$user['email'], '@', true) ?: (string)$user['email'];
            ?>
            <div class="card claim-page-card" style="max-width:860px">
                <div class="claim-page-top">
                    <div>
                        <h2 class="claim-page-title"><?= htmlspecialchars($entityName !== '' ? $entityName : 'Unnamed Profile') ?></h2>
                        <p class="claim-page-subtitle">
                            <?= htmlspecialchars(ucfirst($type)) ?> · <?= htmlspecialchars($entityCity) ?>
                        </p>
                    </div>
                    <span class="badge badge-unclaimed">Unclaimed Seeded Profile</span>
                </div>

                <p class="claim-page-help">
                    Provide enough detail for review. Claims are approved by admin or strict automated rules.
                </p>

                <form id="claimForm" class="claim-form-grid">
                    <div class="form-group">
                        <label for="representative_name">Your Name</label>
                        <input type="text" id="representative_name" name="representative_name" required
                               value="<?= htmlspecialchars($defaultRepName) ?>">
                    </div>
                    <div class="form-group">
                        <label for="representative_role">Role</label>
                        <input type="text" id="representative_role" name="representative_role" required
                               placeholder="<?= $type === 'band' ? 'Manager, band member, booking rep' : 'Owner, GM, booking manager' ?>">
                    </div>
                    <div class="form-group">
                        <label for="contact_email">Contact Email</label>
                        <input type="email" id="contact_email" name="contact_email" required
                               value="<?= htmlspecialchars($user['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="contact_phone">Contact Phone (optional)</label>
                        <input type="text" id="contact_phone" name="contact_phone" placeholder="(415) 555-0101">
                    </div>
                    <div class="form-group">
                        <label for="website">Official Website (optional)</label>
                        <input type="url" id="website" name="website"
                               value="<?= htmlspecialchars($entityWebsite) ?>"
                               placeholder="https://example.com">
                    </div>
                    <div class="form-group">
                        <label for="evidence_links">Evidence Links (optional)</label>
                        <input type="text" id="evidence_links" name="evidence_links"
                               placeholder="Website, social links, booking contact page">
                    </div>
                    <div class="form-group form-group-full">
                        <label for="supporting_info">Supporting Details</label>
                        <textarea id="supporting_info" name="supporting_info" rows="6" required
                                  placeholder="Explain your relationship to this profile, how ownership can be verified, and any merge notes."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Submit Claim Request</button>
                        <a href="/app/claims.php" class="btn btn-secondary">View My Claims</a>
                        <span id="claimSaveStatus" class="form-save-status"></span>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <div id="toast" class="toast"></div>
    <script>window.APP_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;</script>
    <script src="/app/assets/js/app.js"></script>
    <?php if (!$claimError): ?>
    <script>
    (function() {
        const form = document.getElementById('claimForm');
        const statusEl = document.getElementById('claimSaveStatus');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            statusEl.textContent = 'Submitting...';

            const payload = {
                entity_type: <?= json_encode($type) ?>,
                entity_user_id: <?= (int)$entityId ?>,
                representative_name: form.representative_name.value.trim(),
                representative_role: form.representative_role.value.trim(),
                contact_email: form.contact_email.value.trim(),
                contact_phone: form.contact_phone.value.trim(),
                website: form.website.value.trim(),
                evidence_links: form.evidence_links.value.trim(),
                supporting_info: form.supporting_info.value.trim(),
            };

            try {
                const resp = await fetch('/api/claims', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    statusEl.textContent = '';
                    showToast(data.error || 'Failed to submit claim', 'error');
                    return;
                }

                statusEl.textContent = data.auto_approved ? 'Auto-approved.' : 'Submitted.';
                showToast(data.message || 'Claim submitted', 'success');
                setTimeout(() => { window.location.href = '/app/claims.php'; }, 700);
            } catch (err) {
                statusEl.textContent = '';
                showToast('Network error while submitting claim', 'error');
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
