<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$user = currentUser();
$currentPage = 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Claims — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Admin Claim Review</h1>
            <span class="badge badge-admin">Admin</span>
        </div>

        <div class="search-bar-row">
            <select id="statusFilter" class="search-select" style="max-width:220px">
                <option value="pending">Pending</option>
                <option value="">All statuses</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="canceled">Canceled</option>
            </select>
            <div class="search-input-wrap" style="max-width:420px">
                <span class="search-icon">🔍</span>
                <input type="text" id="claimsSearchQ" placeholder="Search representative, email, profile..." class="search-input">
            </div>
            <button class="btn btn-secondary" id="searchBtn">Search</button>
            <a href="/app/admin/" class="btn btn-secondary">Back to Admin Panel</a>
        </div>

        <div class="card">
            <div style="overflow-x:auto">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Profile</th>
                            <th>Claimant</th>
                            <th>Dedupe</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="claimsBody">
                        <tr><td colspan="6" class="empty-state">Loading claim requests…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="toast" class="toast"></div>
    <script>window.APP_IS_ADMIN = true;</script>
    <script src="/app/assets/js/app.js"></script>
    <script>
    (function() {
        const statusEl = document.getElementById('statusFilter');
        const searchEl = document.getElementById('claimsSearchQ');
        const bodyEl = document.getElementById('claimsBody');
        const searchBtn = document.getElementById('searchBtn');

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

        function dedupeBlock(claim) {
            if (!claim.dedupe_score) {
                return '<span style="color:var(--text-muted)">Low</span>';
            }
            const level = claim.dedupe_score >= 70 ? 'High' : (claim.dedupe_score >= 45 ? 'Medium' : 'Low');
            const note = claim.dedupe_notes ? `<div style="margin-top:.35rem;color:var(--text-muted);font-size:.78rem">${esc(claim.dedupe_notes)}</div>` : '';
            return `<div><strong>${esc(level)}</strong> (${claim.dedupe_score})${note}</div>`;
        }

        async function loadClaims() {
            bodyEl.innerHTML = '<tr><td colspan="6" class="empty-state">Loading claim requests…</td></tr>';

            const params = new URLSearchParams({ limit: '200' });
            if (statusEl.value) params.set('status', statusEl.value);
            if (searchEl.value.trim()) params.set('q', searchEl.value.trim());

            try {
                const resp = await fetch(`/api/admin/claims?${params.toString()}`, {
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    bodyEl.innerHTML = `<tr><td colspan="6" class="empty-state">${esc(data.error || 'Failed to load claims')}</td></tr>`;
                    return;
                }
                const claims = data.claims || [];
                if (!claims.length) {
                    bodyEl.innerHTML = '<tr><td colspan="6" class="empty-state">No claim requests found.</td></tr>';
                    return;
                }

                bodyEl.innerHTML = claims.map((claim) => {
                    const name = claim.entity_name || `${claim.entity_type} #${claim.entity_user_id}`;
                    const rep = claim.representative_name || claim.claimant_email;
                    const profileEditHref = `/app/profile.php?edit_id=${claim.entity_user_id}&edit_type=${claim.entity_type}`;
                    let actions = `
                        <a class="btn btn-sm" href="${profileEditHref}">View Profile</a>
                    `;
                    if (claim.status === 'pending') {
                        actions += `
                            <button class="btn btn-primary btn-sm" onclick="approveClaim(${claim.id})">Approve</button>
                            <button class="btn btn-danger btn-sm" onclick="rejectClaim(${claim.id})">Reject</button>
                        `;
                    }

                    return `
                        <tr>
                            <td>
                                <div style="font-weight:600">${esc(name)}</div>
                                <div style="font-size:.8rem;color:var(--text-muted)">${esc(claim.entity_type)} · id ${claim.entity_user_id}</div>
                            </td>
                            <td>
                                <div style="font-weight:600">${esc(rep)}</div>
                                <div style="font-size:.8rem;color:var(--text-muted)">${esc(claim.contact_email || claim.claimant_email)}</div>
                                ${claim.representative_role ? `<div style="font-size:.78rem;color:var(--text-muted)">${esc(claim.representative_role)}</div>` : ''}
                            </td>
                            <td>${dedupeBlock(claim)}</td>
                            <td>${statusBadge(claim.status)}</td>
                            <td>${esc((claim.created_at || '').replace('T',' ').slice(0,19))}</td>
                            <td style="text-align:right;white-space:nowrap">${actions}</td>
                        </tr>
                    `;
                }).join('');
            } catch (err) {
                bodyEl.innerHTML = '<tr><td colspan="6" class="empty-state">Network error loading claims.</td></tr>';
            }
        }

        window.approveClaim = async function(id) {
            const notes = prompt('Approval notes (optional):', '') || '';
            try {
                const resp = await fetch(`/api/admin/claims/${id}/approve`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ review_notes: notes.trim() }),
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    showToast(data.error || 'Failed to approve claim', 'error');
                    return;
                }
                showToast('Claim approved', 'success');
                loadClaims();
            } catch (err) {
                showToast('Network error', 'error');
            }
        };

        window.rejectClaim = async function(id) {
            const notes = prompt('Rejection reason (required):', '');
            if (!notes || !notes.trim()) return;
            try {
                const resp = await fetch(`/api/admin/claims/${id}/reject`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ review_notes: notes.trim() }),
                });
                const data = await resp.json();
                if (!resp.ok || data.error) {
                    showToast(data.error || 'Failed to reject claim', 'error');
                    return;
                }
                showToast('Claim rejected', 'success');
                loadClaims();
            } catch (err) {
                showToast('Network error', 'error');
            }
        };

        statusEl.addEventListener('change', loadClaims);
        searchBtn.addEventListener('click', loadClaims);
        searchEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') loadClaims();
        });
        loadClaims();
    })();
    </script>
</body>
</html>
