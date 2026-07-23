<?php
/**
 * admin/traceability_tv.php
 * Traceability TV dashboard – all projects side by side.
 * Public TV dashboard. Auto-refreshes live data every 30 s via AJAX.
 */
require_once dirname(__DIR__) . '/includes/config.php';

$isKioskLoggedIn = isTraceabilityKioskLoggedIn();
$kioskUser = $isKioskLoggedIn ? getTraceabilityKioskUser() : null;

// Handle lang switch
if (isset($_GET['lang']) && in_array($_GET['lang'], ['cs', 'en', 'uk'], true)) {
    LanguageLoader::reload($_GET['lang']);
    redirect(APP_URL . '/admin/traceability_tv.php');
}

// Handle logout POST
if ($isKioskLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrfToken((string)post('_csrf')) && (string)post('action') === 'logout') {
        clearTraceabilityKioskSession();
        redirect(APP_URL . '/admin/traceability_tv.php');
    }
}

// Initial data load (PHP-rendered first paint)
$traceabilityTvHours = 13;
$activityHours = 12;
$projects = getTraceabilityProjects();
$allData  = [];
foreach ($projects as $proj) {
    $allData[$proj] = [
        'limits'      => getTraceabilityProjectLimits($proj),
        'hourly'      => getTraceabilityHourlyProduction($proj, $traceabilityTvHours),
        'worker'      => getTraceabilityProjectActiveWorker($proj),
        'active'      => traceabilityProjectHasRecentActivity($proj, $activityHours),
        'shift_count' => getTraceabilityShiftProduction($proj), // default 8h; JS overrides from localStorage
    ];
}

// Group definitions – order of groups and which DB projects belong to each
$projectGroups = [
    '48W'  => ['48w', '48w200', '48w200_2'],
    'GEN3' => ['gen3', 'gen3manual'],
];

// Build ordered data respecting group order; append any ungrouped projects at end
$grouped   = [];
$ungrouped = [];
foreach ($projectGroups as $groupLabel => $members) {
    $cols = [];
    foreach ($members as $m) {
        if (isset($allData[$m])) {
            $cols[$m] = $allData[$m];
        }
    }
    if ($cols) {
        $grouped[$groupLabel] = $cols;
    }
}
foreach ($allData as $proj => $d) {
    $inGroup = false;
    foreach ($projectGroups as $members) {
        if (in_array($proj, $members, true)) { $inGroup = true; break; }
    }
    if (!$inGroup) {
        $ungrouped[$proj] = $d;
    }
}
if ($ungrouped) {
    $grouped['Ostatní'] = $ungrouped;
}

$tvTitle      = (string)t('traceability_tv_title');
$tvTitleFirst = function_exists('mb_substr') ? (string)mb_substr($tvTitle, 0, 1, 'UTF-8') : (string)substr($tvTitle, 0, 1);
$tvTitleRest  = function_exists('mb_substr') ? (string)mb_substr($tvTitle, 1, null, 'UTF-8') : (string)substr($tvTitle, 1);

/**
 * Render hourly rows HTML for a project.
 */
function renderTvHourlyRows(array $hourly): string
{
    $counts = array_column($hourly, 'count');
    $max    = max(1, ...$counts);
    $html   = '';
    foreach ($hourly as $row) {
        $pct  = (int)round((int)$row['count'] / $max * 100);
        $html .= '<div class="tv-hourly-row">'
               . '<span class="tv-h-label">' . htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8') . '</span>'
               . '<span class="tv-h-bar-wrap"><span class="tv-h-bar" style="width:' . $pct . '%"></span></span>'
               . '<span class="tv-h-count">' . (int)$row['count'] . '</span>'
               . '</div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="<?= h($_SESSION['lang'] ?? 'cs') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle((string)$tvTitle)) ?></title>
<script>
(() => {
    try {
        const stored = localStorage.getItem('dvd_theme');
        const sessionTheme = '<?= h((string)($_SESSION['trace_kiosk_theme'] ?? '')) ?>';
        const theme = (stored === 'light' || stored === 'dark') ? stored
                    : ((sessionTheme === 'light' || sessionTheme === 'dark') ? sessionTheme : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= urlencode(APP_VERSION) ?>">
<style>
* { box-sizing: border-box; }
html { font-size: 200%; }
html, body { height: 100%; margin: 0; font-family: var(--font); background: var(--bg); color: var(--text); overflow: hidden; }

/* ── Shell layout ── */
.tv-shell { height: 100%; display: grid; grid-template-rows: auto 1fr; padding: 12px; gap: 10px; }

/* ── Header ── */
.tv-header { display: flex; justify-content: space-between; align-items: center; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 8px 14px; gap: 12px; }
.tv-title { font-size: 1.3rem; font-weight: 700; }
.tv-title-link, .tv-title-link:visited { color: inherit; text-decoration: none; }
.tv-title-link:hover, .tv-title-link:focus { color: inherit; text-decoration: none; outline: none; }
.tv-meta { font-size: .82rem; color: var(--muted); }
.tv-header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.live-badge { background: rgba(255,107,53,.2); border: 1px solid #ff6b35; color: #ff6b35; font-family: var(--mono); font-size: .65rem; padding: .2rem .6rem; border-radius: 4px; animation: tv-live-pulse 2s infinite; white-space: nowrap; }
.live-badge.not-live { background: rgba(248,113,113,.16); border-color: #ef4444; color: #ef4444; }
.clock-tv { font-family: var(--mono); color: var(--accent); font-size: .98rem; white-space: nowrap; }
.tv-refresh-badge { font-size: .72rem; color: var(--muted); white-space: nowrap; }
.kiosk-tools { display: flex; align-items: center; gap: 8px; }
.kiosk-tools .theme-switch-wrap { margin-bottom: 0; }
.lang-link { color: var(--muted); text-decoration: none; font-size: .88rem; line-height: 1; }
.lang-link.active { color: var(--accent); }
.kiosk-logout-form { display: inline-flex; align-items: center; margin: 0; }
.kiosk-logout-btn { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid var(--border2); background: rgba(255,255,255,.03); color: var(--text); padding: .24rem .5rem; border-radius: 8px; font-size: .72rem; line-height: 1; cursor: pointer; }
.kiosk-logout-btn:hover { border-color: var(--accent); color: var(--accent); }
@keyframes tv-live-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }

/* ── Projects grid ── */
.tv-projects { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; min-height: 0; overflow: hidden; }

/* ── Project column ── */
.tv-col { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 8px; min-height: 0; overflow: hidden; transition: border-color .3s; min-width: 0; }
.tv-col.palette-full { border-color: var(--danger); }

.tv-proj-name { font-size: 1.05rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--accent); border-bottom: 1px solid var(--border); padding-bottom: 6px; }

/* ── Palette block ── */
.tv-palette { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px; }
.tv-pal-cell { text-align: center; background: var(--surface2); border-radius: 8px; padding: 6px 4px; }
.tv-pal-val { font-size: 2.8rem; font-weight: 700; line-height: 1; font-family: var(--mono); }
.tv-pal-val.danger { color: var(--danger); }
.tv-pal-label { font-size: .68rem; color: var(--muted); margin-top: 2px; }

/* ── Progress bar ── */
.tv-progress-wrap { grid-column: 1 / -1; background: var(--bg); border-radius: 6px; height: 8px; overflow: hidden; }
.tv-progress-bar { height: 100%; background: var(--accent); border-radius: 6px; transition: width .5s ease; }
.tv-progress-bar.warning { background: #f59e0b; }
.tv-progress-bar.danger  { background: var(--danger); }

/* ── Palette full alert ── */
.tv-palette-alert { grid-column: 1 / -1; font-size: 1.37rem; color: #fecaca; background: #3b1212; border: 1px solid #7f1d1d; border-radius: 6px; padding: 6px 8px; text-align: center; margin-top: auto; }

/* ── Hourly production ── */
.tv-hourly-title { font-size: .72rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.tv-hourly { display: flex; flex-direction: column; gap: 3px; flex: 1; min-height: 0; overflow: hidden; }
.tv-hourly-row { display: grid; grid-template-columns: 8rem 1fr 3rem; align-items: center; gap: 5px; font-size: 1.26rem; }
.tv-h-label { color: var(--muted); font-family: var(--mono); font-size: .96rem; line-height: 1; white-space: nowrap; overflow: hidden; text-overflow: clip; }
.tv-h-bar-wrap { background: var(--bg); border-radius: 4px; height: 1.26rem; overflow: hidden; }
.tv-h-bar { height: 100%; background: var(--accent); border-radius: 3px; transition: width .4s; }
.tv-h-count { text-align: right; font-family: var(--mono); font-weight: 600; }
.tv-worker { font-size: .82rem; color: var(--muted); border-top: 1px solid var(--border); padding-top: 6px; }

/* ── Shift toggle (same as kiosk) ── */
.shift-toggle-wrap{display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:var(--muted);cursor:pointer}
.shift-toggle-pill{position:relative;display:inline-flex;align-items:center;background:var(--border2);border-radius:999px;padding:2px;gap:0;border:1px solid var(--border)}
.shift-toggle-pill span{padding:2px 8px;border-radius:999px;font-size:.74rem;font-weight:600;line-height:1.6;transition:background .18s,color .18s;color:var(--muted);white-space:nowrap}
.shift-toggle-pill span.active{background:var(--accent);color:#fff}

/* ── Responsive: at very small widths show scroll ── */
@media (max-width: 600px) {
    .tv-projects { overflow-y: auto; }
}

/* ── Group layout ── */
.tv-groups { display: grid; gap: 10px; min-height: 0; overflow: hidden; height: 100%; align-items: stretch; }
.tv-group { display: contents; } /* transparent – cols go directly into grid */
.tv-group-sep { width: 2px; background: var(--border); border-radius: 2px; align-self: stretch; margin: 0 2px; }
.tv-group-label { display: none; }
.tv-empty { display: none; align-items: center; justify-content: center; text-align: center; border: 1px dashed var(--border); border-radius: 12px; color: var(--muted); font-size: 1rem; min-height: 180px; }
</style>
</head>
<body>
<div class="tv-shell">

    <!-- ── Header ── -->
    <div class="tv-header">
        <div>
            <div class="tv-title">
                <a class="tv-title-link" href="<?= APP_URL ?>/index.php"><?= h($tvTitleFirst) ?></a><?= h($tvTitleRest) ?>
            </div>
            <?php if ($isKioskLoggedIn): ?>
            <div class="tv-meta"><?= h((string)$kioskUser['username']) ?></div>
            <?php endif; ?>
        </div>
        <div class="tv-header-right">
            <span class="live-badge" id="tv-live-badge">● Live</span>
            <div class="clock-tv"><span id="clock-time-tv"></span> <span id="clock-date-tv"></span></div>
            <div class="kiosk-tools">
                <div class="shift-toggle-wrap" id="shift-mode-toggle" title="8h / 12h">
                    <div class="shift-toggle-pill">
                        <span id="shift-lbl-8h" class="active">8h</span>
                        <span id="shift-lbl-12h">12h</span>
                    </div>
                </div>
                <div class="theme-switch-wrap" title="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
                    <label class="theme-pill-switch" for="theme-toggle-checkbox">
                        <input type="checkbox" id="theme-toggle-checkbox" aria-label="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
                        <span class="theme-pill-track">
                            <span class="theme-pill-icon theme-pill-icon-sun" aria-hidden="true"><i class="bi bi-brightness-high"></i></span>
                            <span class="theme-pill-icon theme-pill-icon-moon" aria-hidden="true"><i class="bi bi-moon"></i></span>
                            <span class="theme-pill-thumb" id="theme-pill-thumb" aria-hidden="true"></span>
                        </span>
                    </label>
                </div>
                <?php $lang = $_SESSION['lang'] ?? 'cs'; ?>
                <a class="lang-link <?= $lang === 'cs' ? 'active' : '' ?>" href="?lang=cs">CZ</a>
                <a class="lang-link <?= $lang === 'en' ? 'active' : '' ?>" href="?lang=en">EN</a>
                <a class="lang-link <?= $lang === 'uk' ? 'active' : '' ?>" href="?lang=uk">UA</a>
                <?php if ($isKioskLoggedIn): ?>
                <form method="post" class="kiosk-logout-form">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="kiosk-logout-btn">
                        <i class="bi bi-box-arrow-right"></i><?= h(t('traceability_kiosk_logout')) ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Projects grid ── -->
    <?php
    // Count total columns + separators to build grid-template-columns
    $totalCols = array_sum(array_map('count', $grouped));
    $numGroups = count($grouped);
    // grid: N×1fr cols interleaved with (numGroups-1) separator columns (18px)
    $gridCols = [];
    $gi = 0;
    foreach ($grouped as $cols) {
        if ($gi > 0) $gridCols[] = '18px';
        foreach ($cols as $ignored => $ignored2) $gridCols[] = '1fr';
        $gi++;
    }
    $gridTemplate = implode(' ', $gridCols);
    ?>
    <div class="tv-groups" id="tv-projects" style="grid-template-columns:<?= h($gridTemplate) ?>">
    <?php $isFirst = true; foreach ($grouped as $groupLabel => $cols): ?>
        <?php if (!$isFirst): ?>
        <div class="tv-group-sep" aria-hidden="true"></div>
        <?php endif; $isFirst = false; ?>
        <div class="tv-group" data-group="<?= h($groupLabel) ?>">
        <?php foreach ($cols as $proj => $d):
            $lim      = $d['limits'];
            $hourly   = $d['hourly'];
            $isActive = !empty($d['active']);
            $pct      = $lim['limit'] > 0 ? min(100, (int)round($lim['counter'] / $lim['limit'] * 100)) : 0;
            $barClass = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : '');
            $isFull   = $lim['limit'] > 0 && $lim['counter'] >= $lim['limit'];
            $colClass = $isFull ? 'tv-col palette-full' : 'tv-col';
        ?>
        <div class="<?= $colClass ?>" id="tv-col-<?= h($proj) ?>" data-project="<?= h($proj) ?>" data-group="<?= h($groupLabel) ?>" style="<?= $isActive ? '' : 'display:none' ?>">

            <div class="tv-proj-name"><?= strtoupper(h(traceabilityProjectLabel($proj))) ?></div>

            <div class="tv-palette">
                <div class="tv-pal-cell">
                    <div class="tv-pal-val" id="tv-counter-<?= h($proj) ?>"><?= (int)$lim['counter'] ?></div>
                    <div class="tv-pal-label"><?= h(t('traceability_palette_counter')) ?></div>
                </div>
                <div class="tv-pal-cell">
                    <div class="tv-pal-val danger" id="tv-limit-<?= h($proj) ?>"><?= $lim['limit'] > 0 ? $lim['limit'] : '∞' ?></div>
                    <div class="tv-pal-label"><?= h(t('traceability_palette_limit')) ?></div>
                </div>
                <div class="tv-pal-cell">
                    <div class="tv-pal-val" style="color:var(--accent3)" id="tv-shift-<?= h($proj) ?>"><?= (int)$d['shift_count'] ?></div>
                    <div class="tv-pal-label"><?= h(t('traceability_shift_count')) ?></div>
                </div>
                <?php if ($lim['limit'] > 0): ?>
                <div class="tv-progress-wrap">
                    <div class="tv-progress-bar <?= $barClass ?>" id="tv-progress-<?= h($proj) ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="tv-hourly-title"><?= h(t('traceability_hourly_title')) ?></div>
            <div class="tv-hourly" id="tv-hourly-<?= h($proj) ?>">
                <?= renderTvHourlyRows($hourly) ?>
            </div>

            <?php if ($isFull): ?>
            <div class="tv-palette-alert" id="tv-alert-<?= h($proj) ?>"><?= h(t('traceability_palette_reset_required')) ?></div>
            <?php endif; ?>

            <?php if (!empty($d['worker'])): ?>
            <div class="tv-worker" id="tv-worker-<?= h($proj) ?>">Pracovník: <?= h((string)$d['worker']) ?></div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="tv-empty" id="tv-empty-state">Žádný projekt nemá za posledních 12 hodin záznam.</div>

</div>
<script>
(() => {
    // ── Clock ──────────────────────────────────────────────────────────────
    const timeEl = document.getElementById('clock-time-tv');
    const dateEl = document.getElementById('clock-date-tv');
    const LOCALE = '<?= ($_SESSION['lang'] ?? 'cs') === 'uk' ? 'uk-UA' : (($_SESSION['lang'] ?? 'cs') === 'en' ? 'en-GB' : 'cs-CZ') ?>';
    const renderClock = () => {
        const n = new Date();
        if (timeEl) timeEl.textContent = n.toLocaleTimeString(LOCALE, {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        if (dateEl) dateEl.textContent = n.toLocaleDateString(LOCALE, {day:'2-digit', month:'2-digit', year:'numeric'});
    };
    renderClock();
    setInterval(renderClock, 1000);

    // ── Theme toggle ────────────────────────────────────────────────────────
    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
        const toggle    = document.getElementById('theme-toggle-checkbox');
        if (toggle) toggle.checked = (theme === 'light');
    };
    applyTheme(document.documentElement.getAttribute('data-theme') || 'dark');
    const toggle = document.getElementById('theme-toggle-checkbox');
    if (toggle) toggle.addEventListener('change', () => applyTheme(toggle.checked ? 'light' : 'dark'));

    // ── Shift mode toggle ──────────────────────────────────────────────────
    const shiftToggle = document.getElementById('shift-mode-toggle');
    const shiftLbl8   = document.getElementById('shift-lbl-8h');
    const shiftLbl12  = document.getElementById('shift-lbl-12h');
    const TOTAL_HOURLY_ROWS = 13;
    const ROWS_8H = 9;

    const applyHourlyRows = (mode) => {
        document.querySelectorAll('.tv-hourly').forEach(hourlyEl => {
            const rows = hourlyEl.querySelectorAll('.tv-hourly-row');
            const hideCount = mode === '8h' ? (TOTAL_HOURLY_ROWS - ROWS_8H) : 0;
            rows.forEach((row, idx) => {
                row.style.display = idx < hideCount ? 'none' : '';
            });
        });
    };

    let shiftMode = localStorage.getItem('trace_shift_mode') === '12h' ? '12h' : '8h';
    const applyShiftMode = (mode, doRefresh = true) => {
        shiftMode = mode;
        localStorage.setItem('trace_shift_mode', mode);
        if (shiftLbl8)  shiftLbl8.classList.toggle('active', mode === '8h');
        if (shiftLbl12) shiftLbl12.classList.toggle('active', mode === '12h');
        applyHourlyRows(mode);
        if (doRefresh) refresh();
    };
    if (shiftToggle) {
        shiftToggle.addEventListener('click', () => applyShiftMode(shiftMode === '8h' ? '12h' : '8h'));
    }
    applyShiftMode(shiftMode, false);

    // ── Live data refresh ──────────────────────────────────────────────────
    const API_URL   = '<?= APP_URL ?>/api/traceability_tv_data.php';
    const INTERVAL  = 5000; // ms
    const RESET_MSG = '<?= h(t('traceability_palette_reset_required')) ?>';
    const WORKER_LABEL = 'Pracovník';
    const projectsEl = document.getElementById('tv-projects');
    const emptyStateEl = document.getElementById('tv-empty-state');
    const liveBadgeEl = document.getElementById('tv-live-badge');

    const setLiveState = (isLive) => {
        if (!liveBadgeEl) return;
        if (isLive) {
            liveBadgeEl.classList.remove('not-live');
            liveBadgeEl.textContent = '● Live';
            return;
        }

        liveBadgeEl.classList.add('not-live');
        liveBadgeEl.textContent = '● Not Live';
    };

    const applyVisibilityAndLayout = (projectsData) => {
        // Show project only if last hour had production (count > 0)
        const activeProjects = new Set();
        for (const [proj, d] of Object.entries(projectsData || {})) {
            const hourly = d && d.hourly && Array.isArray(d.hourly) ? d.hourly : [];
            const lastHour = hourly.length > 0 ? hourly[hourly.length - 1] : null;
            if (lastHour && lastHour.count > 0) {
                activeProjects.add(proj);
            }
        }

        document.querySelectorAll('.tv-col[data-project]').forEach((col) => {
            const proj = col.getAttribute('data-project') || '';
            col.style.display = activeProjects.has(proj) ? '' : 'none';
        });

        if (!projectsEl) return;

        let hasAnyActive = false;
        const tracks = [];
        const childNodes = Array.from(projectsEl.children);

        const groupStates = new Map();
        childNodes.forEach((node) => {
            if (!node.classList.contains('tv-group')) return;
            const cols = Array.from(node.querySelectorAll('.tv-col[data-project]'));
            const activeCols = cols.filter((c) => c.style.display !== 'none');
            groupStates.set(node, activeCols.length);
            if (activeCols.length > 0) hasAnyActive = true;
        });

        childNodes.forEach((node, idx) => {
            if (node.classList.contains('tv-group')) {
                const count = groupStates.get(node) || 0;
                for (let i = 0; i < count; i++) tracks.push('1fr');
                return;
            }

            if (node.classList.contains('tv-group-sep')) {
                let prevActive = false;
                let nextActive = false;

                for (let i = idx - 1; i >= 0; i--) {
                    if (!childNodes[i].classList.contains('tv-group')) continue;
                    prevActive = (groupStates.get(childNodes[i]) || 0) > 0;
                    break;
                }
                for (let i = idx + 1; i < childNodes.length; i++) {
                    if (!childNodes[i].classList.contains('tv-group')) continue;
                    nextActive = (groupStates.get(childNodes[i]) || 0) > 0;
                    break;
                }

                const showSep = prevActive && nextActive;
                node.style.display = showSep ? '' : 'none';
                if (showSep) tracks.push('18px');
            }
        });

        projectsEl.style.display = hasAnyActive ? 'grid' : 'none';
        projectsEl.style.gridTemplateColumns = tracks.length ? tracks.join(' ') : '1fr';
        if (emptyStateEl) emptyStateEl.style.display = hasAnyActive ? 'none' : 'flex';
    };

    const renderHourly = (hourly) => {
        if (!hourly || !hourly.length) return '';
        const max = Math.max(1, ...hourly.map(r => r.count));
        return hourly.map(r => {
            const pct = Math.round(r.count / max * 100);
            return `<div class="tv-hourly-row">`
                 + `<span class="tv-h-label">${r.label}</span>`
                 + `<span class="tv-h-bar-wrap"><span class="tv-h-bar" style="width:${pct}%"></span></span>`
                 + `<span class="tv-h-count">${r.count}</span>`
                 + `</div>`;
        }).join('');
    };

    const updateCol = (proj, d) => {
        const lim = d.limits;
        const col = document.getElementById('tv-col-' + proj);
        if (!col) return;

        // counter + limit labels
        const counterEl = document.getElementById('tv-counter-' + proj);
        if (counterEl) counterEl.textContent = lim.counter;

        const limitEl = document.getElementById('tv-limit-' + proj);
        if (limitEl) limitEl.textContent = lim.limit > 0 ? lim.limit : '∞';

        // shift count
        const shiftEl = document.getElementById('tv-shift-' + proj);
        if (shiftEl) shiftEl.textContent = String(d.shift_count ?? 0);

        // progress bar
        const progEl = document.getElementById('tv-progress-' + proj);
        if (progEl && lim.limit > 0) {
            const pct = Math.min(100, Math.round(lim.counter / lim.limit * 100));
            progEl.style.width = pct + '%';
            progEl.className = 'tv-progress-bar' + (pct >= 100 ? ' danger' : (pct >= 80 ? ' warning' : ''));
        }

        // palette-full state
        const isFull = lim.limit > 0 && lim.counter >= lim.limit;
        col.classList.toggle('palette-full', isFull);

        let alertEl = document.getElementById('tv-alert-' + proj);
        if (isFull && !alertEl) {
            const div = document.createElement('div');
            div.className = 'tv-palette-alert';
            div.id = 'tv-alert-' + proj;
            div.textContent = RESET_MSG;
            col.appendChild(div);
        } else if (!isFull && alertEl) {
            alertEl.remove();
        }

        // hourly bars
        const hourlyEl = document.getElementById('tv-hourly-' + proj);
        if (hourlyEl) {
            hourlyEl.innerHTML = renderHourly(d.hourly);
            applyHourlyRows(shiftMode);
        }

        // active worker in last hour
        let workerEl = document.getElementById('tv-worker-' + proj);
        const worker = (d.worker || '').toString().trim();
        if (worker !== '') {
            if (!workerEl) {
                workerEl = document.createElement('div');
                workerEl.className = 'tv-worker';
                workerEl.id = 'tv-worker-' + proj;
                col.appendChild(workerEl);
            }
            workerEl.textContent = `${WORKER_LABEL}: ${worker}`;
        } else if (workerEl) {
            workerEl.remove();
        }
    };

    let refreshInFlight = false;

    const buildApiUrl = () => {
        const sep = API_URL.includes('?') ? '&' : '?';
        return `${API_URL}${sep}shift_mode=${encodeURIComponent(shiftMode)}&_ts=${Date.now()}`;
    };

    const refresh = async () => {
        if (refreshInFlight) return;
        refreshInFlight = true;
        try {
            const resp = await fetch(buildApiUrl(), {
                cache: 'no-store',
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            if (!json.ok) throw new Error('API error');
            for (const [proj, data] of Object.entries(json.projects)) {
                updateCol(proj, data);
            }
            applyVisibilityAndLayout(json.projects || {});
            setLiveState(true);
        } catch (e) {
            setLiveState(false);
        } finally {
            refreshInFlight = false;
        }
    };

    applyVisibilityAndLayout(<?= json_encode($allData, JSON_UNESCAPED_UNICODE) ?>);
    refresh();
    setInterval(refresh, INTERVAL);
    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
})();
</script>
</body>
</html>
