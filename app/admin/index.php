<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$user        = currentUser();
$currentPage = 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
    <style>
        .admin-tabs { display:flex; gap:.5rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .admin-tab  { padding:.45rem 1.1rem; border-radius:6px; border:1px solid var(--border);
                      background:var(--surface); cursor:pointer; font-size:.9rem; color:var(--text-muted);
                      transition:all .15s; }
        .admin-tab.active, .admin-tab:hover { background:var(--accent); color:#fff; border-color:var(--accent); }

        .admin-table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .admin-table th { text-align:left; padding:.55rem .75rem; border-bottom:2px solid var(--border);
                          color:var(--text-muted); font-weight:600; font-size:.8rem; text-transform:uppercase;
                          letter-spacing:.04em; }
        .admin-table td { padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
        .admin-table tr:hover td { background:var(--surface-hover,rgba(255,255,255,.03)); }
        .admin-table .col-name  { font-weight:600; }
        .admin-table .col-email { color:var(--text-muted); font-size:.82rem; }
        .admin-table .col-actions { white-space:nowrap; text-align:right; }
        .admin-table .col-actions a,
        .admin-table .col-actions button { margin-left:.4rem; opacity:0; transition:opacity .15s; }
        .admin-table tr:hover .col-actions a,
        .admin-table tr:hover .col-actions button { opacity:1; }

        .add-form-card { background:var(--surface); border:1px solid var(--border); border-radius:10px;
                         padding:1.5rem; margin-bottom:1.5rem; }
        .add-form-card h3 { margin:0 0 1rem; font-size:1.05rem; }
        .add-form-row { display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; }
        .add-form-row .form-group { flex:1; min-width:160px; margin:0; }
        .add-form-row .form-group label { font-size:.8rem; margin-bottom:.25rem; display:block; color:var(--text-muted); }
        .add-form-row .form-group input,
        .add-form-row .form-group select { width:100%; }

        .admin-section { margin-bottom:2.5rem; }
        .admin-section-title { font-size:1.05rem; font-weight:700; margin-bottom:1rem;
                               padding-bottom:.5rem; border-bottom:1px solid var(--border); }

        .badge-admin { background:#9c27b0; color:#fff; padding:.15rem .45rem; border-radius:4px;
                       font-size:.72rem; font-weight:700; }

        .search-row { display:flex; gap:.5rem; margin-bottom:1rem; }
        .search-row input { flex:1; max-width:300px; }
        .search-row select { width:140px; }

        .pagination { display:flex; gap:.4rem; margin-top:1rem; }
        .pagination button { padding:.35rem .7rem; border-radius:5px; border:1px solid var(--border);
                             background:var(--surface); cursor:pointer; font-size:.83rem; }
        .pagination button.active { background:var(--accent); color:#fff; border-color:var(--accent); }
        .pagination button:disabled { opacity:.4; cursor:default; }

        #loadingRow td { text-align:center; padding:2rem; color:var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Admin Panel</h1>
            <div style="display:flex;gap:.6rem;align-items:center">
                <a href="/app/admin/claims.php" class="btn btn-sm">Review Claims</a>
                <span class="badge badge-admin">Admin</span>
            </div>
        </div>

        <!-- Tabs -->
        <div class="admin-tabs">
            <button class="admin-tab active" data-tab="venues">Venues</button>
            <button class="admin-tab" data-tab="bands">Bands</button>
            <button class="admin-tab" data-tab="labels">Recording Labels</button>
            <button class="admin-tab" data-tab="admins">Admin Users</button>
        </div>

        <!-- ===== VENUES TAB ===== -->
        <div id="tab-venues" class="tab-panel">
            <div class="add-form-card" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                <div>
                    <h3 style="margin:0 0 .25rem;">Add New Venue</h3>
                    <p style="margin:0;font-size:.85rem;color:var(--text-muted);">
                        Create a venue account with a complete profile — name, address, genres, equipment, and more.
                    </p>
                </div>
                <a href="/app/admin/venue-new.php" class="btn btn-primary btn-sm" style="white-space:nowrap;">
                    + New Venue
                </a>
            </div>

            <div class="admin-section">
                <div class="search-row">
                    <input type="text" id="venueSearch" placeholder="Search venues…" class="search-input">
                    <button class="btn btn-sm" id="venueSearchBtn">Search</button>
                </div>
                <div class="admin-section-title">All Venues <span id="venueTotalBadge" style="font-weight:400;color:var(--text-muted)"></span></div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Neighborhood</th>
                                <th>Capacity</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="venuesTableBody">
                            <tr id="loadingRow"><td colspan="5">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="venuesPagination"></div>
            </div>
        </div>

        <!-- ===== BANDS TAB ===== -->
        <div id="tab-bands" class="tab-panel" style="display:none">
            <div class="add-form-card">
                <h3>Add New Band</h3>
                <div class="add-form-row">
                    <div class="form-group">
                        <label>Band Name</label>
                        <input type="text" id="newBandName" placeholder="e.g. The Static">
                    </div>
                    <div class="form-group">
                        <label>Login Email</label>
                        <input type="email" id="newBandEmail" placeholder="band@example.com">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="newBandPassword" placeholder="Set a strong password">
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm" id="addBandBtn">Add Band</button>
                    </div>
                </div>
            </div>

            <div class="admin-section">
                <div class="search-row">
                    <input type="text" id="bandSearch" placeholder="Search bands…" class="search-input">
                    <button class="btn btn-sm" id="bandSearchBtn">Search</button>
                </div>
                <div class="admin-section-title">All Bands <span id="bandTotalBadge" style="font-weight:400;color:var(--text-muted)"></span></div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Genres</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bandsTableBody">
                            <tr><td colspan="4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="bandsPagination"></div>
            </div>
        </div>

        <!-- ===== LABELS TAB ===== -->
        <div id="tab-labels" class="tab-panel" style="display:none">
            <div class="add-form-card">
                <h3>Add New Recording Label</h3>
                <div class="add-form-row">
                    <div class="form-group">
                        <label>Label Name</label>
                        <input type="text" id="newLabelName" placeholder="e.g. Tidal Current Records">
                    </div>
                    <div class="form-group">
                        <label>Login Email</label>
                        <input type="email" id="newLabelEmail" placeholder="label@example.com">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="newLabelPassword" placeholder="Set a strong password">
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm" id="addLabelBtn">Add Label</button>
                    </div>
                </div>
            </div>

            <div class="admin-section">
                <div class="search-row">
                    <input type="text" id="labelSearch" placeholder="Search labels…" class="search-input">
                    <button class="btn btn-sm" id="labelSearchBtn">Search</button>
                </div>
                <div class="admin-section-title">All Recording Labels <span id="labelTotalBadge" style="font-weight:400;color:var(--text-muted)"></span></div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Genre Focus</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="labelsTableBody">
                            <tr><td colspan="4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="labelsPagination"></div>
            </div>
        </div>

        <!-- ===== ADMINS TAB ===== -->
        <div id="tab-admins" class="tab-panel" style="display:none">

            <!-- Grant existing user -->
            <div class="add-form-card">
                <h3>Grant Admin by Email</h3>
                <p style="font-size:.85rem;color:var(--text-muted);margin:-.5rem 0 .75rem">Promote an existing band or venue account to global admin.</p>
                <div class="add-form-row">
                    <div class="form-group">
                        <label>User Email</label>
                        <input type="email" id="delegateEmail" placeholder="user@example.com">
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm" id="grantAdminBtn">Grant Admin</button>
                    </div>
                </div>
            </div>

            <!-- Create new admin account -->
            <div class="add-form-card">
                <h3>Create New Admin Account</h3>
                <p style="font-size:.85rem;color:var(--text-muted);margin:-.5rem 0 .75rem">Creates a new account and immediately grants admin access.</p>
                <div class="add-form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="newAdminEmail" placeholder="admin@example.com">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="newAdminPassword" placeholder="Min 8 characters">
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <label>Type</label>
                        <select id="newAdminType" style="height:38px">
                            <option value="band">Band</option>
                            <option value="venue">Venue</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm" id="createAdminBtn">Create Admin</button>
                    </div>
                </div>
            </div>

            <div class="admin-section">
                <div class="admin-section-title">Current Admins</div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Type</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="adminsTableBody">
                            <tr><td colspan="4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <div id="toast" class="toast"></div>
    <script>window.APP_IS_ADMIN = true;</script>
    <script src="/app/assets/js/app.js"></script>
    <script>
    (function() {
        const csrfToken = <?= json_encode(csrfToken()) ?>;
        const LIMIT = 50;
        const CURRENT_USER_ID = <?= (int)($user['account_id'] ?? $user['id']) ?>;

        // ── Tabs ──────────────────────────────────────────────────────────
        document.querySelectorAll('.admin-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).style.display = '';
                if (btn.dataset.tab === 'bands')  loadBands(true);
                if (btn.dataset.tab === 'labels') loadLabels(true);
                if (btn.dataset.tab === 'admins') loadAdmins();
            });
        });

        // ── Escape HTML ───────────────────────────────────────────────────
        function esc(s) {
            if (!s) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Toast (uses app.js showToast) ─────────────────────────────────

        // ── Venues ────────────────────────────────────────────────────────
        let venueOffset = 0, venueTotal = 0;

        function loadVenues(reset = false) {
            if (reset) venueOffset = 0;
            const q = document.getElementById('venueSearch').value.trim();
            fetch(`/api/admin/users?type=venue&q=${encodeURIComponent(q)}&limit=${LIMIT}&offset=${venueOffset}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    venueTotal = data.total || 0;
                    document.getElementById('venueTotalBadge').textContent = `(${venueTotal.toLocaleString()})`;
                    renderUsersTable(data.users || [], 'venuesTableBody', 'venue');
                    renderPagination('venuesPagination', venueTotal, venueOffset, LIMIT, (off) => {
                        venueOffset = off;
                        loadVenues();
                    });
                })
                .catch(() => showToast('Failed to load venues', 'error'));
        }

        document.getElementById('venueSearchBtn').addEventListener('click', () => loadVenues(true));
        document.getElementById('venueSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadVenues(true); });

        // ── Bands ─────────────────────────────────────────────────────────
        let bandOffset = 0, bandTotal = 0;

        function loadBands(reset = false) {
            if (reset) bandOffset = 0;
            const q = document.getElementById('bandSearch').value.trim();
            fetch(`/api/admin/users?type=band&q=${encodeURIComponent(q)}&limit=${LIMIT}&offset=${bandOffset}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    bandTotal = data.total || 0;
                    document.getElementById('bandTotalBadge').textContent = `(${bandTotal.toLocaleString()})`;
                    renderUsersTable(data.users || [], 'bandsTableBody', 'band');
                    renderPagination('bandsPagination', bandTotal, bandOffset, LIMIT, (off) => {
                        bandOffset = off;
                        loadBands();
                    });
                })
                .catch(() => showToast('Failed to load bands', 'error'));
        }

        document.getElementById('bandSearchBtn').addEventListener('click', () => loadBands(true));
        document.getElementById('bandSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadBands(true); });

        // ── Recording labels ─────────────────────────────────────────────
        let labelOffset = 0, labelTotal = 0;

        function loadLabels(reset = false) {
            if (reset) labelOffset = 0;
            const q = document.getElementById('labelSearch').value.trim();
            fetch(`/api/admin/users?type=recording_label&q=${encodeURIComponent(q)}&limit=${LIMIT}&offset=${labelOffset}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    labelTotal = data.total || 0;
                    document.getElementById('labelTotalBadge').textContent = `(${labelTotal.toLocaleString()})`;
                    renderUsersTable(data.users || [], 'labelsTableBody', 'recording_label');
                    renderPagination('labelsPagination', labelTotal, labelOffset, LIMIT, (off) => {
                        labelOffset = off;
                        loadLabels();
                    });
                })
                .catch(() => showToast('Failed to load recording labels', 'error'));
        }

        document.getElementById('labelSearchBtn').addEventListener('click', () => loadLabels(true));
        document.getElementById('labelSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadLabels(true); });

        // ── Render table rows ─────────────────────────────────────────────
        function renderUsersTable(users, tbodyId, type) {
            const tbody = document.getElementById(tbodyId);
            const cols  = type === 'venue' ? 6 : 5;
            if (!users.length) {
                tbody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:var(--text-muted);padding:2rem">No ${type.replace('_', ' ')}s found</td></tr>`;
                return;
            }
            tbody.innerHTML = users.map(u => {
                const name  = esc(u.profile_name || '—');
                const email = esc(u.email);
                const adminBadge = u.is_admin ? ' <span class="badge-admin">admin</span>' : '';

                let extras = '';
                if (type === 'venue') {
                    extras = `<td class="col-email">${esc(u.neighborhood || '—')}</td><td>${esc(u.capacity ? String(u.capacity) : '—')}</td>`;
                } else if (type === 'recording_label') {
                    extras = `<td class="col-email">${esc((u.genres || []).join(', ') || '—')}</td>`;
                } else {
                    extras = `<td class="col-email">${esc((u.genres || []).join(', ') || '—')}</td>`;
                }

                const adminToggle = u.id !== CURRENT_USER_ID
                    ? (u.is_admin
                        ? `<button class="btn btn-sm" onclick="toggleAdmin(${u.id}, false)" title="Revoke admin">Revoke Admin</button>`
                        : `<button class="btn btn-sm" onclick="toggleAdmin(${u.id}, true)" title="Grant admin">Make Admin</button>`)
                    : '';

                return `<tr>
                    <td class="col-name">${name}${adminBadge}</td>
                    <td class="col-email">${email}</td>
                    ${extras}
                    <td class="col-actions">
                        <a href="/app/profile.php?edit_id=${u.id}&edit_type=${type}" class="btn btn-sm">Edit</a>
                        ${adminToggle}
                        <button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id}, '${type}')">Delete</button>
                    </td>
                </tr>`;
            }).join('');
        }

        // ── Pagination ────────────────────────────────────────────────────
        function renderPagination(containerId, total, offset, limit, onPage) {
            const container = document.getElementById(containerId);
            const pages = Math.ceil(total / limit);
            const current = Math.floor(offset / limit);
            if (pages <= 1) { container.innerHTML = ''; return; }

            let html = '';
            const maxVisible = 7;
            const half = Math.floor(maxVisible / 2);
            let start = Math.max(0, current - half);
            let end   = Math.min(pages - 1, start + maxVisible - 1);
            if (end - start < maxVisible - 1) start = Math.max(0, end - maxVisible + 1);

            if (start > 0) html += `<button onclick="goPage(${containerId.replace('Pagination','')},0)">1</button>`;
            if (start > 1) html += `<button disabled>…</button>`;

            for (let i = start; i <= end; i++) {
                html += `<button class="${i === current ? 'active' : ''}" onclick="goPage_${containerId}(${i})">${i + 1}</button>`;
            }

            if (end < pages - 2) html += `<button disabled>…</button>`;
            if (end < pages - 1) html += `<button onclick="goPage_${containerId}(${pages-1})">${pages}</button>`;

            container.innerHTML = html;

            // Attach click handlers via window
            window['goPage_' + containerId] = (page) => onPage(page * limit);
        }

        // ── Delete user ───────────────────────────────────────────────────
        window.deleteUser = function(id, type) {
            if (!confirm(`Delete this ${type}? This cannot be undone.`)) return;
            const endpoint = type === 'band'
                ? `/api/bands/${id}`
                : (type === 'recording_label' ? `/api/labels/${id}` : `/api/venues/${id}`);
            fetch(endpoint, {
                method: 'DELETE',
                headers: { 'X-CSRF-Token': csrfToken },
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const label = type === 'recording_label' ? 'Recording label' : `${type.charAt(0).toUpperCase()}${type.slice(1)}`;
                        showToast(`${label} deleted.`, 'success');
                        if (type === 'venue') loadVenues();
                        else if (type === 'recording_label') loadLabels();
                        else loadBands();
                    } else {
                        showToast(data.error || 'Delete failed', 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        };

        // ── Add band ──────────────────────────────────────────────────────
        document.getElementById('addBandBtn').addEventListener('click', () => {
            const name  = document.getElementById('newBandName').value.trim();
            const email = document.getElementById('newBandEmail').value.trim();
            const pass  = document.getElementById('newBandPassword').value.trim();
            if (!email || !pass) { showToast('Email and password are required', 'error'); return; }
            addUser('band', name, email, pass, () => {
                document.getElementById('newBandName').value  = '';
                document.getElementById('newBandEmail').value = '';
                document.getElementById('newBandPassword').value = '';
                loadBands();
            });
        });

        // ── Add recording label ──────────────────────────────────────────
        document.getElementById('addLabelBtn').addEventListener('click', () => {
            const name  = document.getElementById('newLabelName').value.trim();
            const email = document.getElementById('newLabelEmail').value.trim();
            const pass  = document.getElementById('newLabelPassword').value.trim();
            if (!email || !pass) { showToast('Email and password are required', 'error'); return; }
            addUser('recording_label', name, email, pass, () => {
                document.getElementById('newLabelName').value = '';
                document.getElementById('newLabelEmail').value = '';
                document.getElementById('newLabelPassword').value = '';
                loadLabels();
            });
        });

        function addUser(type, name, email, pass, onSuccess) {
            fetch('/api/admin/users', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ type, name, email, password: pass })
            })
            .then(r => r.json())
            .then(data => {
                if (data.id) {
                    const label = type === 'recording_label' ? 'Recording label' : `${type.charAt(0).toUpperCase()}${type.slice(1)}`;
                    showToast(`${label} created!`, 'success');
                    onSuccess();
                } else {
                    showToast(data.error || 'Create failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
        }

        // ── Admins tab ────────────────────────────────────────────────────
        function loadAdmins() {
            fetch('/api/admin/users?limit=100', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const admins = (data.users || []).filter(u => u.is_admin);
                    const tbody = document.getElementById('adminsTableBody');
                    if (!admins.length) {
                        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:2rem">No admins found</td></tr>';
                        return;
                    }
                    tbody.innerHTML = admins.map(u => `
                        <tr>
                            <td>${esc(u.email)}</td>
                            <td><span class="badge badge-${esc(u.type)}">${esc(String(u.type).replace('_', ' '))}</span></td>
                            <td class="col-actions">
                                ${u.id !== CURRENT_USER_ID
                                    ? `<button class="btn btn-sm btn-danger" onclick="revokeAdmin(${u.id})">Revoke Admin</button>`
                                    : '<span style="color:var(--text-muted);font-size:.82rem">You</span>'}
                            </td>
                        </tr>
                    `).join('');
                })
                .catch(() => showToast('Failed to load admins', 'error'));
        }

        document.getElementById('grantAdminBtn').addEventListener('click', () => {
            const email = document.getElementById('delegateEmail').value.trim();
            if (!email) { showToast('Enter an email address', 'error'); return; }

            // Look up user by email via admin list
            fetch(`/api/admin/users?q=${encodeURIComponent(email)}&limit=10`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const match = (data.users || []).find(u => u.email === email.toLowerCase());
                    if (!match) { showToast('User not found', 'error'); return; }
                    setAdminFlag(match.id, true, () => {
                        document.getElementById('delegateEmail').value = '';
                        showToast(`Admin granted to ${email}`, 'success');
                        loadAdmins();
                    });
                });
        });

        window.revokeAdmin = function(id) {
            if (!confirm('Revoke admin access for this user?')) return;
            setAdminFlag(id, false, () => {
                showToast('Admin revoked', 'success');
                loadAdmins();
            });
        };

        // ── Admin flag helpers ────────────────────────────────────────────
        function setAdminFlag(id, flag, onSuccess) {
            fetch(`/api/admin/users/${id}/admin`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                credentials: 'same-origin',
                body: JSON.stringify({ is_admin: flag })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) onSuccess();
                else showToast(data.error || 'Operation failed', 'error');
            })
            .catch(() => showToast('Network error', 'error'));
        }

        window.toggleAdmin = function(id, grant) {
            const action = grant ? 'Grant admin to' : 'Revoke admin from';
            if (!confirm(`${action} this user?`)) return;
            setAdminFlag(id, grant, () => {
                showToast(grant ? 'Admin granted' : 'Admin revoked', 'success');
                // Refresh whichever tab is visible
                loadVenues(true);
                if (document.getElementById('tab-bands').style.display !== 'none') loadBands(true);
                loadAdmins();
            });
        };

        // ── Admins tab ────────────────────────────────────────────────────
        function loadAdmins() {
            // Server-side filter — no client-side filtering, no result-limit guessing
            fetch('/api/admin/users?is_admin=1&limit=500', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const admins = data.users || [];
                    const tbody  = document.getElementById('adminsTableBody');
                    if (!admins.length) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:2rem">No admin accounts yet. Use the forms above to grant or create one.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = admins.map(u => `
                        <tr>
                            <td class="col-name">${esc(u.profile_name || '—')}</td>
                            <td class="col-email">${esc(u.email)}</td>
                            <td><span class="badge badge-${esc(u.type)}">${esc(u.type)}</span></td>
                            <td class="col-actions">
                                ${u.id !== CURRENT_USER_ID
                                    ? `<button class="btn btn-sm btn-danger" onclick="toggleAdmin(${u.id}, false)">Revoke Admin</button>`
                                    : '<span style="color:var(--text-muted);font-size:.82rem">You</span>'}
                            </td>
                        </tr>
                    `).join('');
                })
                .catch(() => showToast('Failed to load admins', 'error'));
        }

        // Grant admin to existing account by email
        document.getElementById('grantAdminBtn').addEventListener('click', () => {
            const email = document.getElementById('delegateEmail').value.trim();
            if (!email) { showToast('Enter an email address', 'error'); return; }

            fetch(`/api/admin/users?q=${encodeURIComponent(email)}&limit=5`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const match = (data.users || []).find(u => u.email === email.toLowerCase());
                    if (!match) { showToast('No account found for that email', 'error'); return; }
                    if (match.id === CURRENT_USER_ID) { showToast('That\'s you — already admin', 'error'); return; }
                    setAdminFlag(match.id, true, () => {
                        document.getElementById('delegateEmail').value = '';
                        showToast(`Admin granted to ${email}`, 'success');
                        loadAdmins();
                    });
                })
                .catch(() => showToast('Network error', 'error'));
        });

        // Create new account with admin flag already set
        document.getElementById('createAdminBtn').addEventListener('click', () => {
            const email = document.getElementById('newAdminEmail').value.trim();
            const pass  = document.getElementById('newAdminPassword').value.trim();
            const type  = document.getElementById('newAdminType').value;
            if (!email || !pass) { showToast('Email and password required', 'error'); return; }

            fetch('/api/admin/users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                credentials: 'same-origin',
                body: JSON.stringify({ email, password: pass, type, name: '', is_admin: true })
            })
            .then(r => r.json())
            .then(data => {
                if (data.id) {
                    document.getElementById('newAdminEmail').value    = '';
                    document.getElementById('newAdminPassword').value = '';
                    showToast(`Admin account created for ${email}`, 'success');
                    loadAdmins();
                } else {
                    showToast(data.error || 'Create failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
        });

        // ── Init ──────────────────────────────────────────────────────────
        loadVenues();
        // bands tab loads on click; admins tab loads on click
    })();
    </script>
</body>
</html>
