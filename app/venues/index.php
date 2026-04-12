<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user        = currentUser();
$currentPage = 'venues';

$neighborhoods = ['SoMa','Mission','Castro','Haight-Ashbury','North Beach','Tenderloin','Richmond','Sunset','Downtown','Other'];
$genres        = ['Alternative','Classic Rock','Punk','Indie','Rock','Metal','Country','Blues','Jazz','Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venues — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Venues</h1>
            <span class="page-subtitle" id="venuesSubtitle">Loading…</span>
        </div>

        <div class="search-bar-row">
            <div class="search-input-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchQ" placeholder="Search by name…" class="search-input" autocomplete="off">
            </div>
            <select id="neighborhoodFilter" class="search-select">
                <option value="">All Neighborhoods</option>
                <?php foreach ($neighborhoods as $n): ?>
                    <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sortSelect" class="search-select">
                <option value="name">Name A→Z</option>
                <option value="name_desc">Name Z→A</option>
                <option value="capacity_desc">Largest first</option>
                <option value="capacity">Smallest first</option>
                <option value="recent">Recently Added</option>
            </select>
            <label class="toggle-label" title="Show only venues open to last-minute bookings">
                <input type="checkbox" id="lastMinuteFilter">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-text">Last minute OK</span>
            </label>
        </div>

        <div class="filter-chips-row">
            <select id="genreFilter" class="filter-chip">
                <option value="">Any genre</option>
                <?php foreach ($genres as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="capFilter" class="filter-chip">
                <option value="">Any capacity</option>
                <option value="0:99">Under 100</option>
                <option value="100:299">100 – 299</option>
                <option value="300:599">300 – 599</option>
                <option value="600:1499">600 – 1,499</option>
                <option value="1500:">1,500+</option>
            </select>
            <button class="filter-chip filter-chip-reset" id="resetFilters" style="display:none">✕ Clear filters</button>
        </div>

        <div id="venuesGrid" class="cards-grid"></div>

        <div id="scrollSentinel" class="scroll-sentinel">
            <div class="sentinel-spinner" id="sentinelSpinner" style="display:none">
                <div class="spinner"></div>
            </div>
            <p class="sentinel-end" id="sentinelEnd" style="display:none">All venues loaded</p>
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
    <script>
        window.APP_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;
        window.APP_USER = {
            loggedIn: <?= $user ? 'true' : 'false' ?>,
            type: <?= json_encode($user['type'] ?? '') ?>
        };
    </script>
    <script src="/app/assets/js/app.js"></script>
    <script>
    (function () {
        const LIMIT   = 24;
        let offset    = 0;
        let total     = 0;
        let isLoading = false;
        let allLoaded = false;

        const grid     = document.getElementById('venuesGrid');
        const subtitle = document.getElementById('venuesSubtitle');
        const spinner  = document.getElementById('sentinelSpinner');
        const endMsg   = document.getElementById('sentinelEnd');

        // ── Controls ─────────────────────────────────────────────────────
        const searchQ    = document.getElementById('searchQ');
        const nbEl       = document.getElementById('neighborhoodFilter');
        const sortEl     = document.getElementById('sortSelect');
        const lmEl       = document.getElementById('lastMinuteFilter');
        const genreEl    = document.getElementById('genreFilter');
        const capEl      = document.getElementById('capFilter');
        const resetBtn   = document.getElementById('resetFilters');

        function hasActiveFilters() {
            return nbEl.value || lmEl.checked || genreEl.value || capEl.value;
        }
        function updateResetBtn() {
            resetBtn.style.display = hasActiveFilters() ? 'inline-flex' : 'none';
        }

        resetBtn.addEventListener('click', () => {
            nbEl.value    = '';
            lmEl.checked  = false;
            genreEl.value = '';
            capEl.value   = '';
            updateResetBtn();
            loadVenues(true);
        });

        function getParams() {
            const p = new URLSearchParams({
                q:     searchQ.value.trim(),
                sort:  sortEl.value,
                limit: LIMIT,
                offset,
            });
            if (nbEl.value)    p.set('neighborhood', nbEl.value);
            if (genreEl.value) p.set('genre', genreEl.value);
            if (lmEl.checked)  p.set('last_minute', '1');
            const cap = capEl.value;
            if (cap) {
                const [mn, mx] = cap.split(':');
                if (mn) p.set('cap_min', mn);
                if (mx) p.set('cap_max', mx);
            }
            return p.toString();
        }

        // ── Load ─────────────────────────────────────────────────────────
        function loadVenues(reset = false) {
            if (isLoading) return;
            if (!reset && allLoaded) return;

            if (reset) {
                offset    = 0;
                allLoaded = false;
                grid.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading…</p></div>';
                endMsg.style.display = 'none';
            }

            isLoading = true;
            spinner.style.display = 'flex';

            fetch(`/api/venues?${getParams()}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (reset) grid.innerHTML = '';

                    total = data.total || 0;
                    const venues = data.venues || [];

                    if (venues.length === 0 && reset) {
                        grid.innerHTML = '<p class="empty-state">No venues found matching your search.</p>';
                    } else {
                        venues.forEach(v => grid.insertAdjacentHTML('beforeend', renderVenueCard(v)));
                        offset += venues.length;
                    }

                    allLoaded = offset >= total;
                    spinner.style.display = 'none';
                    endMsg.style.display  = allLoaded && offset > 0 ? 'block' : 'none';
                    subtitle.textContent  = total
                        ? `${total.toLocaleString()} venue${total !== 1 ? 's' : ''} listed`
                        : 'No venues found';
                })
                .catch(() => {
                    if (reset) grid.innerHTML = '<p class="empty-state">Failed to load venues.</p>';
                    spinner.style.display = 'none';
                })
                .finally(() => { isLoading = false; });
        }

        // ── Render card ───────────────────────────────────────────────────
        function renderVenueCard(v) {
            const genres = (v.genres_welcomed || []).map(g => `<span class="tag">${escHtml(g)}</span>`).join('');
            const lm     = v.open_to_last_minute
                ? '<span class="badge badge-lastminute">⚡ Last Minute</span>' : '';
            const unclaimedBadge = v.is_generic && !v.is_claimed
                ? '<span class="badge badge-unclaimed">Unclaimed</span>' : '';
            const claimedBadge = v.is_claimed
                ? '<span class="badge badge-claimed">✓ Claimed</span>' : '';

            const equip = [];
            if (v.has_pa)       equip.push('PA');
            if (v.has_drums)    equip.push('Drums');
            if (v.has_backline) equip.push('Backline');

            const capLabel = v.capacity
                ? `<span class="cap-pill cap-${capTier(v.capacity)}">👥 ${v.capacity.toLocaleString()}</span>` : '';
            const claimBtn = (v.is_generic && !v.is_claimed)
                ? `<a href="/app/claim.php?type=venue&id=${v.id}" class="btn btn-primary btn-sm card-inline-claim" onclick="event.stopPropagation()">${window.APP_USER && window.APP_USER.loggedIn ? 'Claim' : 'Log In to Claim'}</a>`
                : '';

            return `
            <div class="card venue-card" onclick="openDetailModal('venue',${v.id})">
                <div class="card-header">
                    <h3 class="card-title">${escHtml(v.name || 'Unnamed Venue')}</h3>
                    <div class="card-badges">${lm}${unclaimedBadge}${claimedBadge}</div>
                </div>
                <div class="card-tags">
                    ${v.neighborhood ? `<span class="tag tag-venue">${escHtml(v.neighborhood)}</span>` : ''}
                    ${genres}
                </div>
                <div class="card-meta">
                    ${capLabel}
                    ${v.stage_size   ? `<span>🎭 ${escHtml(v.stage_size)}</span>` : ''}
                    ${equip.length   ? `<span>🔊 ${escHtml(equip.join(', '))}</span>` : ''}
                </div>
                ${v.description ? `<p class="card-desc">${escHtml(v.description.substring(0,110))}${v.description.length > 110 ? '…' : ''}</p>` : ''}
                <div class="card-cta-row">
                    <span class="card-cta">View details →</span>
                    ${claimBtn}
                </div>
            </div>`;
        }

        function capTier(cap) {
            if (cap < 100)  return 'xs';
            if (cap < 300)  return 'sm';
            if (cap < 600)  return 'md';
            if (cap < 1500) return 'lg';
            return 'xl';
        }

        // ── Infinite scroll ───────────────────────────────────────────────
        const sentinel = document.getElementById('scrollSentinel');
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !allLoaded && !isLoading) loadVenues(false);
        }, { rootMargin: '200px' });
        observer.observe(sentinel);

        // ── Events ───────────────────────────────────────────────────────
        const debouncedReset = debounce(() => loadVenues(true), 300);
        searchQ.addEventListener('input',    debouncedReset);
        sortEl.addEventListener('change',    () => loadVenues(true));
        nbEl.addEventListener('change',      () => { updateResetBtn(); loadVenues(true); });
        lmEl.addEventListener('change',      () => { updateResetBtn(); loadVenues(true); });
        genreEl.addEventListener('change',   () => { updateResetBtn(); loadVenues(true); });
        capEl.addEventListener('change',     () => { updateResetBtn(); loadVenues(true); });

        loadVenues(true);
    })();
    </script>
</body>
</html>
