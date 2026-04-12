<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$user        = currentUser();
$currentPage = 'bands';

$genres = ['Alternative','Classic Rock','Punk','Indie','Rock','Metal','Country','Blues','Jazz','Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bands — Panic Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/app/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Bands</h1>
            <span class="page-subtitle" id="bandsSubtitle">Loading…</span>
        </div>

        <div class="search-bar-row">
            <div class="search-input-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchQ" placeholder="Search by name…" class="search-input" autocomplete="off">
            </div>
            <select id="genreFilter" class="search-select">
                <option value="">All Genres</option>
                <?php foreach ($genres as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sortSelect" class="search-select">
                <option value="name">Name A→Z</option>
                <option value="name_desc">Name Z→A</option>
                <option value="score">Top Score</option>
                <option value="shows">Most Shows</option>
                <option value="draw">Highest Draw</option>
                <option value="lastminute">Last Minute Available</option>
                <option value="recent">Recently Added</option>
            </select>
            <label class="toggle-label" title="Show only bands actively seeking gigs">
                <input type="checkbox" id="seekingFilter">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-text">Seeking gigs</span>
            </label>
        </div>

        <div class="filter-chips-row">
            <select id="scoreFilter" class="filter-chip">
                <option value="0">Any score</option>
                <option value="50">Score 50+</option>
                <option value="60">Score 60+</option>
                <option value="70">Score 70+</option>
                <option value="80">Score 80+</option>
                <option value="90">Score 90+</option>
            </select>
            <select id="drawFilter" class="filter-chip">
                <option value="0">Any draw</option>
                <option value="50">Draw 50+</option>
                <option value="100">Draw 100+</option>
                <option value="200">Draw 200+</option>
                <option value="500">Draw 500+</option>
                <option value="1000">Draw 1,000+</option>
            </select>
            <button class="filter-chip filter-chip-reset" id="resetFilters" style="display:none">✕ Clear filters</button>
        </div>

        <div id="bandsGrid" class="cards-grid"></div>

        <!-- Infinite scroll sentinel -->
        <div id="scrollSentinel" class="scroll-sentinel">
            <div class="sentinel-spinner" id="sentinelSpinner" style="display:none">
                <div class="spinner"></div>
            </div>
            <p class="sentinel-end" id="sentinelEnd" style="display:none">All bands loaded</p>
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
    <script>window.APP_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;</script>
    <script src="/app/assets/js/app.js"></script>
    <script>
    (function () {
        const LIMIT     = 24;
        let offset      = 0;
        let total       = 0;
        let isLoading   = false;
        let allLoaded   = false;

        const grid      = document.getElementById('bandsGrid');
        const subtitle  = document.getElementById('bandsSubtitle');
        const spinner   = document.getElementById('sentinelSpinner');
        const endMsg    = document.getElementById('sentinelEnd');

        // ── Filters ──────────────────────────────────────────────────────
        const searchQ   = document.getElementById('searchQ');
        const genreEl   = document.getElementById('genreFilter');
        const sortEl    = document.getElementById('sortSelect');
        const seekingEl = document.getElementById('seekingFilter');
        const scoreEl   = document.getElementById('scoreFilter');
        const drawEl    = document.getElementById('drawFilter');
        const resetBtn  = document.getElementById('resetFilters');

        function hasActiveFilters() {
            return genreEl.value || seekingEl.checked ||
                   scoreEl.value !== '0' || drawEl.value !== '0';
        }

        function updateResetBtn() {
            resetBtn.style.display = hasActiveFilters() ? 'inline-flex' : 'none';
        }

        resetBtn.addEventListener('click', () => {
            genreEl.value  = '';
            seekingEl.checked = false;
            scoreEl.value  = '0';
            drawEl.value   = '0';
            updateResetBtn();
            loadBands(true);
        });

        function getParams() {
            const p = new URLSearchParams({
                q:       searchQ.value.trim(),
                genre:   genreEl.value,
                sort:    sortEl.value,
                limit:   LIMIT,
                offset:  offset,
            });
            if (seekingEl.checked)       p.set('seeking',   '1');
            if (scoreEl.value !== '0')   p.set('score_min', scoreEl.value);
            if (drawEl.value  !== '0')   p.set('draw_min',  drawEl.value);
            return p.toString();
        }

        // ── Load ─────────────────────────────────────────────────────────
        function loadBands(reset = false) {
            if (isLoading) return;
            if (!reset && allLoaded) return;

            if (reset) {
                offset    = 0;
                allLoaded = false;
                grid.innerHTML = '<div class="loading-state"><div class="spinner"></div><p>Loading…</p></div>';
                endMsg.style.display  = 'none';
            }

            isLoading = true;
            spinner.style.display = 'flex';

            fetch(`/api/bands?${getParams()}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (reset) grid.innerHTML = '';

                    total = data.total || 0;
                    const bands = data.bands || [];

                    if (bands.length === 0 && reset) {
                        grid.innerHTML = '<p class="empty-state">No bands found matching your search.</p>';
                    } else {
                        bands.forEach(band => {
                            grid.insertAdjacentHTML('beforeend', renderBandCard(band));
                        });
                        offset += bands.length;
                    }

                    allLoaded = offset >= total;
                    spinner.style.display = 'none';
                    endMsg.style.display  = allLoaded && offset > 0 ? 'block' : 'none';

                    subtitle.textContent = total
                        ? `${total.toLocaleString()} band${total !== 1 ? 's' : ''} in the SF scene`
                        : 'No bands found';

                    // Trigger score badges
                    if (typeof initBandScores === 'function') initBandScores();
                })
                .catch(() => {
                    if (reset) grid.innerHTML = '<p class="empty-state">Failed to load bands.</p>';
                    spinner.style.display = 'none';
                })
                .finally(() => { isLoading = false; });
        }

        // ── Render card ───────────────────────────────────────────────────
        function renderBandCard(band) {
            const genres = (band.genres || []).map(g => `<span class="tag">${escHtml(g)}</span>`).join('');
            const setLen = (band.set_length_min && band.set_length_max)
                ? `${band.set_length_min}–${band.set_length_max} min` : '';
            const lm = band.available_last_minute
                ? '<span class="badge badge-lastminute">⚡ Last Minute</span>' : '';
            const seeking = band.seeking_gigs
                ? '<span class="badge badge-seeking">🎸 Seeking Gigs</span>' : '';
            const unclaimedBadge = band.is_generic && !band.is_claimed
                ? '<span class="badge badge-unclaimed">Unclaimed</span>' : '';
            const claimedBadge = band.is_claimed
                ? '<span class="badge badge-claimed">✓ Claimed</span>' : '';

            let scoreLine = '';
            if (band.is_generic) {
                const parts = [];
                if (band.shows_tracked) parts.push(`${band.shows_tracked} show${band.shows_tracked !== 1 ? 's' : ''}`);
                if (band.estimated_draw) parts.push(`~${band.estimated_draw} draw`);
                if (band.composite_score) parts.push(`Score ${Math.round(band.composite_score)}`);
                if (parts.length) scoreLine = `<p class="card-desc card-score-line">📊 ${escHtml(parts.join(' · '))}</p>`;
            }

            const desc = band.description
                ? `<p class="card-desc">${escHtml(band.description.substring(0, 110))}${band.description.length > 110 ? '…' : ''}</p>`
                : scoreLine;

            return `
            <div class="card band-card" onclick="openDetailModal('band',${band.id})">
                <div class="card-header">
                    <h3 class="card-title band-card-name">${escHtml(band.name || 'Unnamed Band')}</h3>
                    <div class="card-badges">${seeking}${lm}${unclaimedBadge}${claimedBadge}</div>
                </div>
                <div class="card-tags">${genres}</div>
                <div class="card-meta">
                    ${band.location ? `<span>📍 ${escHtml(band.location)}</span>` : ''}
                    ${setLen       ? `<span>⏱ ${escHtml(setLen)}</span>` : ''}
                    ${band.experience ? `<span class="tag tag-exp">${escHtml(band.experience)}</span>` : ''}
                </div>
                ${desc}
                <span class="card-cta">View profile →</span>
            </div>`;
        }

        // ── Infinite scroll ───────────────────────────────────────────────
        const sentinel = document.getElementById('scrollSentinel');
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !allLoaded && !isLoading) {
                loadBands(false);
            }
        }, { rootMargin: '200px' });
        observer.observe(sentinel);

        // ── Filter/sort events ────────────────────────────────────────────
        const debouncedReset = debounce(() => loadBands(true), 300);
        searchQ.addEventListener('input', debouncedReset);
        genreEl.addEventListener('change',   () => { updateResetBtn(); loadBands(true); });
        sortEl.addEventListener('change',    () => loadBands(true));
        seekingEl.addEventListener('change', () => { updateResetBtn(); loadBands(true); });
        scoreEl.addEventListener('change',   () => { updateResetBtn(); loadBands(true); });
        drawEl.addEventListener('change',    () => { updateResetBtn(); loadBands(true); });

        // ── Init ──────────────────────────────────────────────────────────
        loadBands(true);
    })();
    </script>
</body>
</html>
