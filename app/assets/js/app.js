/* =====================================================
   Panic Booking — App JavaScript
   ===================================================== */

// Populated by PHP on pages that include user context
const APP_IS_ADMIN = window.APP_IS_ADMIN || false;
const APP_USER = window.APP_USER || { loggedIn: false, type: '' };
const APP_IS_LOGGED_IN = !!APP_USER.loggedIn;
const APP_CSRF_TOKEN = window.APP_CSRF_TOKEN || '';

function appCsrfHeaders(existing = {}) {
    const headers = Object.assign({}, existing || {});
    if (APP_CSRF_TOKEN) {
        headers['X-CSRF-Token'] = APP_CSRF_TOKEN;
    }
    return headers;
}

function claimProfileHref(type, id) {
    return `/app/claim.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(String(id))}`;
}

// --- Utility: HTML escape ---
function escHtml(str) {
    if (!str && str !== 0) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// --- Utility: Debounce ---
function debounce(fn, delay) {
    let timer;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

// --- Toast Notifications ---
let toastTimer = null;

function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'toast show toast-' + type;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 3500);
}

// --- Mobile Nav Toggle ---
(function() {
    const hamburger = document.getElementById('hamburger');
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sidebarOverlay');

    if (!hamburger) return;

    hamburger.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    });

    sidebar.querySelectorAll('a.nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    });
})();

// --- Grouped Sidebar Navigation ---
(function() {
    const groups = Array.from(document.querySelectorAll('.nav-group[data-collapsible="1"]'));
    if (!groups.length) return;

    const mobileQuery = window.matchMedia('(max-width: 768px)');

    function setExpanded(group, expanded) {
        group.classList.toggle('expanded', expanded);
        const toggle = group.querySelector('.nav-group-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    groups.forEach((group) => {
        group.dataset.mobileExpanded = group.classList.contains('expanded') ? '1' : '0';
    });

    function applyViewportState() {
        groups.forEach((group) => {
            if (mobileQuery.matches) {
                setExpanded(group, group.dataset.mobileExpanded === '1');
            } else {
                setExpanded(group, true);
            }
        });
    }

    groups.forEach((group) => {
        const toggle = group.querySelector('.nav-group-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', () => {
            if (!mobileQuery.matches) return;
            const nextExpanded = group.dataset.mobileExpanded !== '1';
            group.dataset.mobileExpanded = nextExpanded ? '1' : '0';
            setExpanded(group, nextExpanded);
        });
    });

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', applyViewportState);
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(applyViewportState);
    }

    applyViewportState();
})();

// --- Modal ---
function openDetailModal(type, id) {
    const modal   = document.getElementById('detailModal');
    const content = document.getElementById('modalContent');
    if (!modal) return;

    content.innerHTML = '<div class="spinner" style="margin:2rem auto"></div>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    const endpoint = type === 'band' ? `/api/bands/${id}` : `/api/venues/${id}`;

    fetch(endpoint, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = `<p class="empty-state">${escHtml(data.error)}</p>`;
                return;
            }
            const item = data.band || data.venue;
            if (!item) {
                content.innerHTML = '<p class="empty-state">Not found.</p>';
                return;
            }
            content.innerHTML = type === 'band' ? renderBandDetail(item) : renderVenueDetail(item);
            if (type === 'band' && item.name) {
                const panelId = 'score-panel-' + (item.id || 0);
                fetchAndRenderScore(item.name, document.getElementById(panelId));
            }
        })
        .catch(() => {
            content.innerHTML = '<p class="empty-state">Failed to load details.</p>';
        });
}

function closeModal() {
    const modal = document.getElementById('detailModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
    const overlay = document.getElementById('detailModal');
    if (overlay && e.target === overlay) closeModal();
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

function renderBandDetail(band) {
    const genres  = (band.genres || []).map(g => `<span class="tag">${escHtml(g)}</span>`).join('');
    const members = (band.members || []).filter(Boolean);
    const lm = band.available_last_minute
        ? '<span class="badge badge-lastminute">⚡ Available Last Minute</span>' : '';
    const setLen = (band.set_length_min || band.set_length_max)
        ? `${band.set_length_min || '?'}–${band.set_length_max || '?'} min` : 'Not specified';

    const links = buildLinks([
        { label: '🌐 Website', url: band.website },
        { label: '📘 Facebook', url: band.facebook },
        { label: '📸 Instagram', url: band.instagram },
        { label: '🎵 Spotify', url: band.spotify },
        { label: '▶ YouTube', url: band.youtube },
    ]);

    const claimBanner = (band.is_generic && !band.is_claimed) ? `
    <div class="claim-banner">
        <div class="claim-banner-text">
            <strong>Are you ${escHtml(band.name || 'this band')}?</strong>
            <span>Claim this profile to edit your info, add booking details, and get discovered.</span>
        </div>
        <a href="${claimProfileHref('band', band.id)}" class="btn btn-primary btn-sm">${APP_IS_LOGGED_IN ? 'Claim Profile →' : 'Log In to Claim →'}</a>
    </div>` : '';

    return `
    ${claimBanner}
    <div class="modal-title">${escHtml(band.name || 'Unnamed Band')}</div>
    <div class="modal-subtitle">📍 ${escHtml(band.location || 'San Francisco, CA')}</div>
    <div class="modal-tags">${genres} ${lm}</div>

    ${band.description ? `
    <div class="modal-section">
        <div class="modal-section-title">About</div>
        <p class="modal-desc">${escHtml(band.description)}</p>
    </div>` : ''}

    <div class="modal-section">
        <div class="modal-section-title">Details</div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Experience</span>
            <span class="modal-detail-value">${escHtml(band.experience || 'Not specified')}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Set Length</span>
            <span class="modal-detail-value">${escHtml(setLen)}</span>
        </div>
        <div class="modal-detail-row">
            <span class="modal-detail-label">Own Equipment</span>
            <span class="modal-detail-value">${band.has_own_equipment ? '✅ Yes' : '❌ No'}</span>
        </div>
    </div>

    ${members.length ? `
    <div class="modal-section">
        <div class="modal-section-title">Members</div>
        <div class="modal-tags">${members.map(m => `<span class="tag">${escHtml(m)}</span>`).join('')}</div>
    </div>` : ''}

    <div class="modal-section">
        <div class="modal-section-title">Contact</div>
        ${band.contact_email ? `<div class="modal-detail-row"><span class="modal-detail-label">Email</span><span class="modal-detail-value"><a href="mailto:${escHtml(band.contact_email)}">${escHtml(band.contact_email)}</a></span></div>` : ''}
        ${band.contact_phone ? `<div class="modal-detail-row"><span class="modal-detail-label">Phone</span><span class="modal-detail-value">${escHtml(band.contact_phone)}</span></div>` : ''}
        ${links ? `<div class="modal-links">${links}</div>` : ''}
    </div>

    ${band.notes ? `
    <div class="modal-section">
        <div class="modal-section-title">Notes</div>
        <p class="modal-desc">${escHtml(band.notes)}</p>
    </div>` : ''}

    <div id="score-panel-${band.id || 0}" class="score-panel-wrap"></div>

    ${APP_IS_ADMIN ? `
    <div class="modal-section modal-admin-actions">
        <a href="/app/profile.php?edit_id=${band.id}&edit_type=band" class="btn btn-admin btn-sm">🔧 Edit Profile</a>
        <button class="btn btn-danger btn-sm" onclick="adminDeleteUser(${band.id}, 'band')">🗑 Delete</button>
    </div>` : ''}
    `;
}

// Fetch scores for a band and render into containerEl
function fetchAndRenderScore(bandName, containerEl) {
    if (!containerEl || !bandName) return;
    const encoded = encodeURIComponent(bandName);
    fetch(`/api/scores/${encoded}`, { credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) return null;
            return r.json();
        })
        .then(data => {
            if (!data || !data.score) return;
            const s = data.score;
            const meter = (label, cls, val) => `
            <div class="score-meter">
                <span class="score-label">${escHtml(label)}</span>
                <div class="score-bar"><div class="score-fill ${escHtml(cls)}" style="width:${Math.min(100, Math.max(0, Math.round(val)))}%"></div></div>
                <span class="score-val">${Math.round(val)}/100</span>
            </div>`;
            containerEl.innerHTML = `
            <div class="modal-section score-panel">
                <div class="modal-section-title">📊 Performance Scores</div>
                <div class="score-meters">
                    ${meter('Draw',        'score-draw',        s.draw_score)}
                    ${meter('Revenue',     'score-revenue',     s.revenue_score)}
                    ${meter('Reliability', 'score-reliability', s.reliability_score)}
                    ${meter('Momentum',    'score-momentum',    s.momentum_score)}
                </div>
                <div class="score-insights">
                    ${s.insight_draw        ? `<p class="insight-line">🎯 ${escHtml(s.insight_draw)}</p>` : ''}
                    ${s.insight_revenue     ? `<p class="insight-line">💰 ${escHtml(s.insight_revenue)}</p>` : ''}
                    ${s.insight_reliability ? `<p class="insight-line">⚡ ${escHtml(s.insight_reliability)}</p>` : ''}
                    ${s.insight_momentum    ? `<p class="insight-line">📈 ${escHtml(s.insight_momentum)}</p>` : ''}
                </div>
                <div class="score-meta">Based on ${escHtml(String(s.shows_tracked))} tracked shows · Last computed ${escHtml(s.last_computed || '')}</div>
            </div>`;
        })
        .catch(() => { /* no-op: scores are optional */ });
}

function renderVenueDetail(v) {
    const genres = (v.genres_welcomed || []).map(g => `<span class="tag">${escHtml(g)}</span>`).join('');
    const lm = v.open_to_last_minute
        ? '<span class="badge badge-lastminute">⚡ Open to Last Minute</span>' : '';
    const claimBanner = (v.is_generic && !v.is_claimed) ? `
    <div class="claim-banner">
        <div class="claim-banner-text">
            <strong>Do you manage ${escHtml(v.name || 'this venue')}?</strong>
            <span>Claim this seeded venue profile to update details and manage bookings.</span>
        </div>
        <a href="${claimProfileHref('venue', v.id)}" class="btn btn-primary btn-sm">${APP_IS_LOGGED_IN ? 'Claim Profile →' : 'Log In to Claim →'}</a>
    </div>` : '';

    const equipment = [];
    if (v.has_pa)      equipment.push('PA System');
    if (v.has_drums)   equipment.push('Drum Kit');
    if (v.has_backline) equipment.push('Backline');

    const amenities = [];
    if (v.cover_charge) amenities.push('Cover Charge');
    if (v.bar_service)  amenities.push('Bar Service');

    const links = buildLinks([
        { label: '🌐 Website', url: v.website },
        { label: '📘 Facebook', url: v.facebook },
        { label: '📸 Instagram', url: v.instagram },
    ]);

    return `
    ${claimBanner}
    <div class="modal-title">${escHtml(v.name || 'Unnamed Venue')}</div>
    <div class="modal-subtitle">
        ${v.neighborhood ? `🏘 ${escHtml(v.neighborhood)}` : ''}
        ${v.address ? ` · 📍 ${escHtml(v.address)}` : ''}
    </div>
    <div class="modal-tags">
        ${v.neighborhood ? `<span class="tag tag-venue">${escHtml(v.neighborhood)}</span>` : ''}
        ${genres} ${lm}
    </div>

    ${v.description ? `
    <div class="modal-section">
        <div class="modal-section-title">About</div>
        <p class="modal-desc">${escHtml(v.description)}</p>
    </div>` : ''}

    <div class="modal-section">
        <div class="modal-section-title">Details</div>
        ${v.capacity ? `<div class="modal-detail-row"><span class="modal-detail-label">Capacity</span><span class="modal-detail-value">👥 ${escHtml(String(v.capacity))}</span></div>` : ''}
        ${v.stage_size ? `<div class="modal-detail-row"><span class="modal-detail-label">Stage Size</span><span class="modal-detail-value">🎭 ${escHtml(v.stage_size)}</span></div>` : ''}
        <div class="modal-detail-row">
            <span class="modal-detail-label">Booking Lead Time</span>
            <span class="modal-detail-value">${v.booking_lead_time_days === 0 ? 'Same day OK' : escHtml(v.booking_lead_time_days + ' days')}</span>
        </div>
    </div>

    ${equipment.length ? `
    <div class="modal-section">
        <div class="modal-section-title">Equipment Provided</div>
        <div class="modal-tags">${equipment.map(e => `<span class="tag">${escHtml(e)}</span>`).join('')}</div>
    </div>` : ''}

    ${amenities.length ? `
    <div class="modal-section">
        <div class="modal-section-title">Amenities</div>
        <div class="modal-tags">${amenities.map(a => `<span class="tag">${escHtml(a)}</span>`).join('')}</div>
    </div>` : ''}

    <div class="modal-section">
        <div class="modal-section-title">Contact</div>
        ${v.contact_email ? `<div class="modal-detail-row"><span class="modal-detail-label">Email</span><span class="modal-detail-value"><a href="mailto:${escHtml(v.contact_email)}">${escHtml(v.contact_email)}</a></span></div>` : ''}
        ${v.contact_phone ? `<div class="modal-detail-row"><span class="modal-detail-label">Phone</span><span class="modal-detail-value">${escHtml(v.contact_phone)}</span></div>` : ''}
        ${links ? `<div class="modal-links">${links}</div>` : ''}
    </div>

    ${v.notes ? `
    <div class="modal-section">
        <div class="modal-section-title">Notes</div>
        <p class="modal-desc">${escHtml(v.notes)}</p>
    </div>` : ''}

    ${APP_IS_ADMIN ? `
    <div class="modal-section modal-admin-actions">
        <a href="/app/profile.php?edit_id=${v.id}&edit_type=venue" class="btn btn-admin btn-sm">🔧 Edit Profile</a>
        <button class="btn btn-danger btn-sm" onclick="adminDeleteUser(${v.id}, 'venue')">🗑 Delete</button>
    </div>` : ''}
    `;
}

function buildLinks(items) {
    const filtered = items.filter(item => item.url && item.url.trim());
    if (!filtered.length) return '';
    return filtered.map(item =>
        `<a href="${escHtml(item.url)}" target="_blank" rel="noopener noreferrer" class="modal-link">${item.label}</a>`
    ).join('');
}

// --- Admin: delete user from modal ---
function adminDeleteUser(id, type) {
    const label = type === 'band' ? 'band' : 'venue';
    if (!confirm(`Delete this ${label}? This cannot be undone.`)) return;

    const endpoint = type === 'band' ? `/api/bands/${id}` : `/api/venues/${id}`;
    fetch(endpoint, {
        method: 'DELETE',
        headers: appCsrfHeaders(),
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal();
                showToast(`${label.charAt(0).toUpperCase() + label.slice(1)} deleted.`, 'success');
                // Reload the list
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.error || 'Delete failed', 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'));
}

// --- Dynamic member add/remove (profile page) ---
(function() {
    const addBtn    = document.getElementById('addMemberBtn');
    const container = document.getElementById('membersContainer');

    if (!addBtn || !container) return;

    addBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'member-row';
        row.innerHTML = `
            <input type="text" name="members[]" class="member-input" placeholder="Member name / instrument">
            <button type="button" class="btn btn-danger btn-sm remove-member">✕</button>
        `;
        container.appendChild(row);
        row.querySelector('input').focus();
    });

    container.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-member')) {
            const row = e.target.closest('.member-row');
            // Keep at least one row
            if (container.querySelectorAll('.member-row').length > 1) {
                row.remove();
            } else {
                row.querySelector('input').value = '';
            }
        }
    });
})();

// =====================================================
// CALENDAR / SHOWS PAGE
// =====================================================

// Active view state ('list' | 'cal' | 'stats')
let calActiveView = 'list';
// Current week start (Monday, YYYY-MM-DD string)
let calWeekStart = '';

function initCalendarPage() {
    // Parse ?week= from URL
    const urlParams = new URLSearchParams(window.location.search);
    const weekParam = urlParams.get('week');
    calWeekStart = weekParam && /^\d{4}-\d{2}-\d{2}$/.test(weekParam)
        ? weekParam
        : getMondayOfCurrentWeek();

    updateWeekDisplay(calWeekStart);

    // View tab handlers
    document.querySelectorAll('.view-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            calActiveView = btn.dataset.view;
            if (calActiveView === 'stats') {
                renderStatsView(calWeekStart);
            } else {
                loadCalendarData(calWeekStart, getCalSearchQuery());
            }
        });
    });

    // Week nav
    document.getElementById('prevWeekBtn').addEventListener('click', (e) => {
        e.preventDefault();
        calWeekStart = prevWeek(calWeekStart);
        updateWeekDisplay(calWeekStart);
        calActiveView === 'stats'
            ? renderStatsView(calWeekStart)
            : loadCalendarData(calWeekStart, getCalSearchQuery());
    });

    document.getElementById('nextWeekBtn').addEventListener('click', (e) => {
        e.preventDefault();
        calWeekStart = nextWeek(calWeekStart);
        updateWeekDisplay(calWeekStart);
        calActiveView === 'stats'
            ? renderStatsView(calWeekStart)
            : loadCalendarData(calWeekStart, getCalSearchQuery());
    });

    // Search
    const searchInput = document.getElementById('calSearchQ');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
            if (calActiveView !== 'stats') {
                loadCalendarData(calWeekStart, getCalSearchQuery());
            }
        }, 350));
    }

    // SF Only
    const sfCheck = document.getElementById('sfOnlyCheck');
    if (sfCheck) {
        sfCheck.addEventListener('change', () => {
            calActiveView === 'stats'
                ? renderStatsView(calWeekStart)
                : loadCalendarData(calWeekStart, getCalSearchQuery());
        });
    }

    // Initial load
    loadCalendarData(calWeekStart, getCalSearchQuery());
}

function getCalSearchQuery() {
    const el = document.getElementById('calSearchQ');
    return el ? el.value.trim() : '';
}

function getSfOnly() {
    const el = document.getElementById('sfOnlyCheck');
    return el ? (el.checked ? 1 : 0) : 1;
}

function loadCalendarData(weekStart, query) {
    const container = document.getElementById('calViewContainer');
    if (!container) return;
    container.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading shows&hellip;</p></div>';

    const sfOnly = getSfOnly();
    const url = `/api/events?date=${encodeURIComponent(weekStart)}&days=7&q=${encodeURIComponent(query)}&sf_only=${sfOnly}&limit=200`;

    fetch(url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<p class="empty-state">${escHtml(data.error)}</p>`;
                return;
            }
            const events = data.events || [];
            if (calActiveView === 'cal') {
                renderCalView(events, weekStart);
            } else {
                renderListView(events, weekStart);
            }
        })
        .catch(() => {
            container.innerHTML = '<p class="empty-state">Failed to load shows. Are you logged in?</p>';
        });
}

function renderListView(events, weekStart) {
    const container = document.getElementById('calViewContainer');
    if (!container) return;

    const days = getWeekDates(weekStart);

    // Group events by date
    const byDate = {};
    events.forEach(ev => {
        if (!byDate[ev.event_date]) byDate[ev.event_date] = [];
        byDate[ev.event_date].push(ev);
    });

    let html = '';
    days.forEach(dateObj => {
        const dateStr = formatDateISO(dateObj);
        const dayEvents = byDate[dateStr] || [];
        html += `<div class="day-section">`;
        html += `<div class="day-heading">${escHtml(formatDate(dateStr))}</div>`;
        html += `<div class="day-shows">`;
        if (dayEvents.length === 0) {
            html += `<p class="no-shows">No SF shows listed.</p>`;
        } else {
            dayEvents.forEach(ev => {
                html += renderShowCard(ev);
            });
        }
        html += `</div></div>`;
    });

    container.innerHTML = html || '<p class="empty-state">No shows found for this week.</p>';

    // Attach click handlers
    container.querySelectorAll('.show-card[data-id]').forEach(card => {
        card.addEventListener('click', () => openShowModal(parseInt(card.dataset.id, 10)));
    });
}

function renderCalView(events, weekStart) {
    const container = document.getElementById('calViewContainer');
    if (!container) return;

    const days = getWeekDates(weekStart);
    const today = formatDateISO(new Date());

    // Group by date
    const byDate = {};
    events.forEach(ev => {
        if (!byDate[ev.event_date]) byDate[ev.event_date] = [];
        byDate[ev.event_date].push(ev);
    });

    let html = '<div class="cal-grid">';
    days.forEach(dateObj => {
        const dateStr = formatDateISO(dateObj);
        const dayEvents = byDate[dateStr] || [];
        const isToday = dateStr === today;
        const dow = dateObj.toLocaleDateString('en-US', { weekday: 'short' });
        const dayNum = dateObj.getDate();

        html += `<div class="cal-day${isToday ? ' cal-today' : ''}">`;
        html += `<div class="cal-day-head">
            <div class="cal-day-dow">${escHtml(dow)}</div>
            <div class="cal-day-num">${dayNum}</div>
        </div>`;
        html += `<div class="cal-day-body">`;

        if (dayEvents.length === 0) {
            html += `<span class="no-shows" style="font-size:0.7rem;padding:0.2rem 0.4rem">—</span>`;
        } else {
            const shown = dayEvents.slice(0, 4);
            shown.forEach(ev => {
                html += renderShowCardCompact(ev);
            });
            if (dayEvents.length > 4) {
                html += `<span class="cal-more">+${dayEvents.length - 4} more</span>`;
            }
        }

        html += `</div></div>`;
    });
    html += '</div>';

    container.innerHTML = html;

    // Click handlers on compact show items
    container.querySelectorAll('.cal-show-item[data-id]').forEach(el => {
        el.addEventListener('click', () => openShowModal(parseInt(el.dataset.id, 10)));
    });
}

function renderStatsView(weekStart) {
    const container = document.getElementById('calViewContainer');
    if (!container) return;
    container.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading stats&hellip;</p></div>';

    const days = getWeekDates(weekStart);
    const dateFrom = formatDateISO(days[0]);
    const dateTo   = formatDateISO(days[6]);

    fetch(`/api/events/stats?date_from=${dateFrom}&date_to=${dateTo}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                container.innerHTML = `<p class="empty-state">${escHtml(data.error)}</p>`;
                return;
            }
            const stats = data.stats || [];
            if (!stats.length) {
                container.innerHTML = '<p class="empty-state">No venue data for this week.</p>';
                return;
            }

            let html = `<div class="stats-shows-grid">`;
            stats.forEach(v => {
                const allDates = days.map(d => formatDateISO(d));
                const dots = allDates.map(d => {
                    const hasShow = v.dates.includes(d);
                    const label = formatDate(d);
                    return `<span class="stat-dot ${hasShow ? 'dot-show' : 'dot-dark'}" title="${escHtml(label)}"></span>`;
                }).join('');

                html += `
                <div class="stat-card-venue">
                    <div class="stat-venue-name">${escHtml(v.venue_name)}</div>
                    <div class="stat-venue-count">${v.total_shows} show${v.total_shows !== 1 ? 's' : ''} this week</div>
                    <div class="stat-dots">${dots}</div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        })
        .catch(() => {
            container.innerHTML = '<p class="empty-state">Failed to load stats.</p>';
        });
}

function renderShowCard(ev) {
    const bands = (ev.bands || []).map(b => `<span class="tag">${escHtml(b)}</span>`).join('');
    const meta  = buildShowMeta(ev);
    const sourceBadge = ev.source && ev.source !== 'foopee'
        ? `<span class="badge-source badge-source-${escHtml(ev.source)}">${escHtml(ev.source.toUpperCase())}</span>`
        : '';
    return `
    <div class="show-card" data-id="${ev.id}">
        <div class="show-venue">${escHtml(ev.venue_name)}${sourceBadge}</div>
        ${bands ? `<div class="show-bands">${bands}</div>` : ''}
        <div class="show-meta">${meta}</div>
    </div>`;
}

function renderShowCardCompact(ev) {
    const firstBand = (ev.bands || [])[0] || '';
    return `
    <div class="cal-show-item" data-id="${ev.id}" title="${escHtml(ev.venue_name)}${firstBand ? ' — ' + firstBand : ''}">
        <span class="cal-show-venue">${escHtml(ev.venue_name)}</span>
        ${firstBand ? `<span class="cal-show-band">${escHtml(firstBand)}</span>` : ''}
    </div>`;
}

function buildShowMeta(ev) {
    let parts = [];
    if (ev.doors_time || ev.show_time) {
        const t = ev.doors_time && ev.show_time
            ? `${ev.doors_time} / ${ev.show_time}`
            : (ev.show_time || ev.doors_time);
        parts.push(`<span class="badge-time">&#9200; ${escHtml(t)}</span>`);
    }
    if (ev.age_restriction) {
        parts.push(`<span class="badge-age">${escHtml(ev.age_restriction)}</span>`);
    }
    if (ev.price) {
        parts.push(`<span class="badge-price">${escHtml(ev.price)}</span>`);
    }
    if (ev.is_sold_out) {
        parts.push(`<span class="badge-soldout">Sold Out</span>`);
    }
    if (ev.is_ticketed) {
        parts.push(`<span class="badge-ticketed">Ticketed</span>`);
    }
    return parts.join('');
}

function openShowModal(id) {
    const modal   = document.getElementById('showDetailModal');
    const content = document.getElementById('showModalContent');
    if (!modal) return;
    content.innerHTML = '<div class="spinner" style="margin:2rem auto"></div>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    fetch(`/api/events/${id}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = `<p class="empty-state">${escHtml(data.error)}</p>`;
                return;
            }
            const ev = data.event;
            if (!ev) { content.innerHTML = '<p class="empty-state">Not found.</p>'; return; }

            const bands = (ev.bands || []).map(b => `<span class="tag">${escHtml(b)}</span>`).join('');
            const meta  = buildShowMeta(ev);

            content.innerHTML = `
            <div class="modal-title">${escHtml(ev.venue_name)}</div>
            <div class="modal-subtitle">
                ${escHtml(formatDate(ev.event_date))}
                ${ev.venue_city ? ' &middot; ' + escHtml(ev.venue_city) : ''}
            </div>
            ${bands ? `<div class="modal-tags show-bands" style="margin-top:0.75rem">${bands}</div>` : ''}
            <div class="modal-section">
                <div class="show-meta" style="margin-top:0.75rem">${meta}</div>
            </div>
            ${ev.notes ? `
            <div class="modal-section">
                <div class="modal-section-title">Notes</div>
                <p class="modal-desc">${escHtml(ev.notes)}</p>
            </div>` : ''}`;
        })
        .catch(() => {
            content.innerHTML = '<p class="empty-state">Failed to load event.</p>';
        });
}

function closeShowModal() {
    const modal = document.getElementById('showDetailModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close show modal on overlay click
document.addEventListener('click', function(e) {
    const overlay = document.getElementById('showDetailModal');
    if (overlay && e.target === overlay) closeShowModal();
});

// --- Week / Date helpers ---

function getMondayOfCurrentWeek() {
    const now = new Date();
    const day = now.getDay(); // 0=Sun, 1=Mon...
    const diff = (day === 0) ? -6 : 1 - day; // shift so Monday = 0
    const mon = new Date(now);
    mon.setDate(now.getDate() + diff);
    return formatDateISO(mon);
}

function getWeekDates(weekStart) {
    const base = new Date(weekStart + 'T00:00:00');
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        return d;
    });
}

function formatDateISO(dateObj) {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, '0');
    const d = String(dateObj.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function formatDate(dateStr) {
    // "2026-03-23" -> "Mon Mar 23"
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

function prevWeek(weekStart) {
    const d = new Date(weekStart + 'T00:00:00');
    d.setDate(d.getDate() - 7);
    return formatDateISO(d);
}

function nextWeek(weekStart) {
    const d = new Date(weekStart + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    return formatDateISO(d);
}

function updateWeekDisplay(weekStart) {
    const days  = getWeekDates(weekStart);
    const start = days[0];
    const end   = days[6];
    const label = `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} – ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
    const el = document.getElementById('weekLabel');
    if (el) el.textContent = label;

    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('week', weekStart);
    window.history.replaceState({}, '', url.toString());
}

// Auto-init
(function() {
    if (document.getElementById('calendarRoot')) initCalendarPage();
})();

// =====================================================
// DARK NIGHTS PAGE
// =====================================================

let dnCurrentDays = 30;
let dnData = null;

function initDarkNightsPage() {
    // Days toggle
    document.querySelectorAll('.dn-days-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.dn-days-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            dnCurrentDays = parseInt(btn.dataset.days, 10);
            loadDarkNights(dnCurrentDays, getDnVenueFilter(), getDnSourceFilter());
        });
    });

    // Venue search filter
    const venueSearch = document.getElementById('dnVenueSearch');
    if (venueSearch) {
        venueSearch.addEventListener('input', debounce(() => {
            if (dnData) renderDarkNightsGrid(dnData, getDnVenueFilter(), getDnSourceFilter());
        }, 250));
    }

    // Source filter
    const sourceFilter = document.getElementById('dnSourceFilter');
    if (sourceFilter) {
        sourceFilter.addEventListener('change', () => {
            loadDarkNights(dnCurrentDays, getDnVenueFilter(), getDnSourceFilter());
        });
    }

    // Initial load
    loadDarkNights(30);
}

function getDnVenueFilter() {
    const el = document.getElementById('dnVenueSearch');
    return el ? el.value.trim().toLowerCase() : '';
}

function getDnSourceFilter() {
    const el = document.getElementById('dnSourceFilter');
    return el ? el.value.trim() : '';
}

function loadDarkNights(days, venueFilter, sourceFilter) {
    const grid = document.getElementById('darkNightsGrid');
    if (!grid) return;
    grid.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading dark nights...</p></div>';

    const statsBar = document.getElementById('dnStatsBar');
    if (statsBar) statsBar.textContent = 'Loading...';

    let url = `/api/dark-nights?days=${days}`;
    if (sourceFilter) url += `&source=${encodeURIComponent(sourceFilter)}`;

    fetch(url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                grid.innerHTML = `<p class="empty-state">${escHtml(data.error)}</p>`;
                return;
            }
            dnData = data;
            renderDarkNightsGrid(data, venueFilter || getDnVenueFilter(), sourceFilter || getDnSourceFilter());
        })
        .catch(() => {
            grid.innerHTML = '<p class="empty-state">Failed to load dark nights data.</p>';
        });
}

function renderDarkNightsGrid(data, venueFilter, sourceFilter) {
    const grid = document.getElementById('darkNightsGrid');
    if (!grid) return;

    const today = formatDateISO(new Date());
    const dates = data.dates || [];
    let venues = data.venues || [];

    // Apply venue name filter
    if (venueFilter) {
        venues = venues.filter(v => v.venue_name.toLowerCase().includes(venueFilter));
    }

    // Compute stats
    const weekEnd = new Date(today);
    weekEnd.setDate(weekEnd.getDate() + 6);
    const weekEndStr = formatDateISO(weekEnd);

    let totalVenues = venues.length;
    let bookedThisWeek = 0;
    let darkThisWeek = 0;

    venues.forEach(v => {
        v.booked_dates.forEach(d => {
            if (d >= today && d <= weekEndStr) bookedThisWeek++;
        });
        v.dark_dates.forEach(d => {
            if (d >= today && d <= weekEndStr) darkThisWeek++;
        });
    });

    const statsBar = document.getElementById('dnStatsBar');
    if (statsBar) {
        statsBar.textContent = `${totalVenues} venues tracked · ${bookedThisWeek} shows this week · ${darkThisWeek} dark nights this week`;
    }

    if (venues.length === 0) {
        grid.innerHTML = '<p class="empty-state">No venues found.</p>';
        return;
    }

    // Set CSS variable for column count
    const cols = dates.length;
    grid.style.setProperty('--dn-cols', cols);

    // Build header row
    let html = `<div class="dn-grid" style="--dn-cols:${cols}">`;

    // Top-left corner cell
    html += `<div class="dn-venue-name dn-header-venue"></div>`;

    // Date header cells
    dates.forEach(d => {
        const dateObj = new Date(d + 'T00:00:00');
        const dowShort = dateObj.toLocaleDateString('en-US', { weekday: 'short' }).charAt(0);
        const monthDay = `${dateObj.getMonth() + 1}/${dateObj.getDate()}`;
        const isToday = d === today;
        html += `<div class="dn-header-cell${isToday ? ' dn-today' : ''}">${escHtml(dowShort)} ${escHtml(monthDay)}</div>`;
    });

    // Venue rows
    venues.forEach(venue => {
        const bookedSet = new Set(venue.booked_dates);
        html += `<div class="dn-venue-name" title="${escHtml(venue.venue_name)}">${escHtml(venue.venue_name)}</div>`;

        dates.forEach(d => {
            const isToday = d === today;
            const todayClass = isToday ? ' dn-today' : '';
            if (bookedSet.has(d)) {
                html += `<div class="dn-cell dn-booked${todayClass}" title="${escHtml(venue.venue_name)} — ${escHtml(d)}: show booked">●</div>`;
            } else {
                html += `<div class="dn-cell dn-dark${todayClass}" data-venue="${escHtml(venue.venue_name)}" data-date="${escHtml(d)}" title="Dark — click to express interest">+</div>`;
            }
        });
    });

    html += '</div>';
    grid.innerHTML = html;

    // Attach click handlers on dark cells
    grid.querySelectorAll('.dn-cell.dn-dark[data-venue]').forEach(cell => {
        cell.addEventListener('click', () => {
            openInterestModal(cell.dataset.venue, cell.dataset.date);
        });
    });
}

function openInterestModal(venueName, dateStr) {
    const modal = document.getElementById('interestModal');
    if (!modal) return;

    // Reset form state
    const form = document.getElementById('interestForm');
    if (form) form.reset();
    document.getElementById('interestThankYou').style.display = 'none';
    document.getElementById('interestModalBody').style.display = '';

    // Pre-fill hidden fields
    document.getElementById('interestVenueName').value = venueName;
    document.getElementById('interestEventDate').value = dateStr;

    // Update subtitle
    const subtitle = document.getElementById('interestModalSubtitle');
    if (subtitle) {
        const dateObj = new Date(dateStr + 'T00:00:00');
        const formatted = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        subtitle.textContent = `${venueName} · ${formatted}`;
    }

    // Attach submit handler (replace to avoid duplicates)
    if (form) {
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        document.getElementById('interestForm').addEventListener('submit', submitInterest);
    }

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function submitInterest(e) {
    e.preventDefault();
    const btn = document.getElementById('interestSubmitBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

    const form = document.getElementById('interestForm');
    const formData = new FormData(form);
    const payload = {
        venue_name:      formData.get('venue_name'),
        event_date:      formData.get('event_date'),
        requester_type:  formData.get('requester_type'),
        requester_name:  formData.get('requester_name'),
        requester_email: formData.get('requester_email'),
        message:         formData.get('message') || '',
    };

    fetch('/api/bookings/interest', {
        method: 'POST',
        headers: appCsrfHeaders({ 'Content-Type': 'application/json' }),
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('interestModalBody').style.display = 'none';
                document.getElementById('interestThankYou').style.display = '';
            } else {
                showToast(data.error || 'Failed to send interest', 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Send Booking Interest'; }
            }
        })
        .catch(() => {
            showToast('Network error — please try again', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Send Booking Interest'; }
        });
}

function closeInterestModal() {
    const modal = document.getElementById('interestModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

// Close interest modal on overlay click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('interestModal');
    if (modal && e.target === modal) closeInterestModal();
});

// Auto-init dark nights
(function() {
    if (document.getElementById('darkNightsGrid')) initDarkNightsPage();
})();

// =====================================================
// BAND SCORES — lazy badge loading on band list page
// =====================================================

function initBandScores() {
    const cards = document.querySelectorAll('[data-band-name]');
    if (!cards.length) return;

    if (!('IntersectionObserver' in window)) {
        // Fallback: load all immediately
        cards.forEach(card => loadCompositeBadge(card));
        return;
    }

    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const card = entry.target;
                obs.unobserve(card);
                loadCompositeBadge(card);
            }
        });
    }, { rootMargin: '100px' });

    cards.forEach(card => observer.observe(card));
}

function loadCompositeBadge(card) {
    const bandName = card.dataset.bandName;
    if (!bandName) return;
    const encoded = encodeURIComponent(bandName);
    fetch(`/api/scores/${encoded}`, { credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) return null;
            return r.json();
        })
        .then(data => {
            if (!data || !data.score) return;
            const score = Math.round(data.score.composite_score);
            const badge = document.createElement('span');
            badge.className = 'composite-badge';
            badge.textContent = '⭐ ' + score;
            // Insert after the band name heading or as first child of badge area
            const nameEl = card.querySelector('.band-card-name, h2, h3, .card-name');
            if (nameEl) {
                nameEl.appendChild(badge);
            } else {
                card.appendChild(badge);
            }
        })
        .catch(() => { /* scores are optional */ });
}

// Auto-init band scores on bands page
(function() {
    if (document.querySelector('[data-band-name]')) initBandScores();
})();
