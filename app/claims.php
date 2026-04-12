<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
$user = currentUser();
$currentPage = 'claims';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claims — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Claim Requests</h1>
            <span class="page-subtitle">Track your seeded-profile claim submissions</span>
        </div>

        <div class="search-bar-row">
            <select id="claimStatusFilter" class="search-select" style="max-width:220px">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="canceled">Canceled</option>
            </select>
            <button id="refreshClaimsBtn" class="btn btn-secondary">Refresh</button>
        </div>

        <div class="card">
            <div style="overflow-x:auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Profile</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Review Notes</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="claimsTableBody">
                        <tr><td colspan="5" class="empty-state">Loading claims…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="toast" class="toast"></div>
    <script>window.APP_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;</script>
    <script src="/app/assets/js/app.js"></script>
    <script>
    (function() {
        const bodyEl = document.getElementById('claimsTableBody');
        const statusEl = document.getElementById('claimStatusFilter');
        const refreshBtn = document.getElementById('refreshClaimsBtn');

        function esc(v) {
            if (!v) return '';
            return String(v)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function statusBadge(status) {
            const cls = status === 'approved'
                ? 'badge-claimed'
                : (status === 'pending' ? 'badge-unclaimed' : 'badge');
            return `<span class="badge ${cls}">${esc(status)}</span>`;
        }

        async function loadClaims() {
            bodyEl.innerHTML = '<tr><td colspan="5" class="empty-state">Loading claims…</td></tr>';
            const params = new URLSearchParams({ limit: '200' });
            if (statusEl.value) params.set('status', statusEl.value);

            try {
                const resp = await fetch(`/api/claims/mine?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    bodyEl.innerHTML = `<tr><td colspan="5" class="empty-state">${esc(data.error || 'Failed to load claims')}</td></tr>`;
                    return;
                }
                const claims = data.claims || [];
                if (!claims.length) {
                    bodyEl.innerHTML = '<tr><td colspan="5" class="empty-state">No claim requests found.</td></tr>';
                    return;
                }

                bodyEl.innerHTML = claims.map((c) => {
                    const profile = c.entity_name || `${c.entity_type} #${c.entity_user_id}`;
                    const review = c.review_notes
                        ? esc(c.review_notes)
                        : '<span style="color:var(--text-muted)">—</span>';
                    const actions = c.status === 'pending'
                        ? `<button class="btn btn-danger btn-sm" onclick="cancelClaim(${c.id})">Cancel</button>`
                        : '<span style="color:var(--text-muted);font-size:.82rem">No actions</span>';
                    return `
                        <tr>
                            <td>
                                <div style="font-weight:600">${esc(profile)}</div>
                                <div style="font-size:.8rem;color:var(--text-muted)">${esc(c.entity_type)} · ${esc(c.contact_email || c.claimant_email)}</div>
                            </td>
                            <td>${statusBadge(c.status)}</td>
                            <td>${esc((c.created_at || '').replace('T', ' ').slice(0, 19))}</td>
                            <td>${review}</td>
                            <td style="text-align:right">${actions}</td>
                        </tr>
                    `;
                }).join('');
            } catch (err) {
                bodyEl.innerHTML = '<tr><td colspan="5" class="empty-state">Network error loading claims.</td></tr>';
            }
        }

        window.cancelClaim = async function(id) {
            if (!confirm('Cancel this pending claim request?')) return;
            try {
                const resp = await fetch(`/api/claims/${id}/cancel`, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    showToast(data.error || 'Failed to cancel claim', 'error');
                    return;
                }
                showToast('Claim canceled', 'success');
                loadClaims();
            } catch (err) {
                showToast('Network error', 'error');
            }
        };

        statusEl.addEventListener('change', loadClaims);
        refreshBtn.addEventListener('click', loadClaims);
        loadClaims();
    })();
    </script>
</body>
</html>
