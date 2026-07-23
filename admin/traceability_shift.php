<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'traceability', 'writer', 'read']);

$pageTitle = t('traceability_shift_title');
$activePage = 'traceability_shift';

// Load projects and their data (same as TV mode)
$traceabilityTvHours = 13;
$activityHours = 12;
$projects = getTraceabilityProjects();
$allData  = [];
foreach ($projects as $proj) {
    $allData[$proj] = [
        'limits' => getTraceabilityProjectLimits($proj),
        'hourly' => getTraceabilityHourlyProduction($proj, $traceabilityTvHours),
        'worker' => getTraceabilityProjectActiveWorker($proj),
    ];
}

require APP_ROOT . '/includes/layout.php';

/**
 * Render hourly rows HTML for a project.
 */
function renderShiftHourlyRows(array $hourly): string
{
    $counts = array_column($hourly, 'count');
    $max    = max(1, ...$counts);
    $html   = '';
    foreach ($hourly as $row) {
        $pct  = (int)round((int)$row['count'] / $max * 100);
        $html .= '<div class="shift-hourly-row">'
               . '<span class="shift-h-label">' . htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8') . '</span>'
               . '<span class="shift-h-bar-wrap"><span class="shift-h-bar" style="width:' . $pct . '%"></span></span>'
               . '<span class="shift-h-count">' . (int)$row['count'] . '</span>'
               . '</div>';
    }
    return $html;
}
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-clock-history me-2"></i><?= t('traceability_shift_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="traceability_terminal.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-upc me-1"></i><?= t('traceability_terminal_title') ?></a>
        <a href="traceability.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-graph-up me-1"></i><?= t('traceability_title') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

<style>
/* ── Shift bubble grid ── */
.shift-bubbles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }

/* ── Bubble card ── */
.shift-bubble { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 8px; transition: border-color .3s; }
.shift-bubble.palette-full { border-color: var(--danger); }

.shift-proj-name { font-size: 1.05rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--accent); border-bottom: 1px solid var(--border); padding-bottom: 6px; }

/* ── Palette block ── */
.shift-palette { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
.shift-pal-cell { text-align: center; background: var(--surface2); border-radius: 8px; padding: 6px 4px; }
.shift-pal-val { font-size: 2.8rem; font-weight: 700; line-height: 1; font-family: var(--mono); }
.shift-pal-val.danger { color: var(--danger); }
.shift-pal-label { font-size: .68rem; color: var(--muted); margin-top: 2px; }

/* ── Progress bar ── */
.shift-progress-wrap { grid-column: 1 / -1; background: var(--bg); border-radius: 6px; height: 8px; overflow: hidden; }
.shift-progress-bar { height: 100%; background: var(--accent); border-radius: 6px; transition: width .5s ease; }
.shift-progress-bar.warning { background: #f59e0b; }
.shift-progress-bar.danger  { background: var(--danger); }

/* ── Palette full alert ── */
.shift-palette-alert { grid-column: 1 / -1; font-size: 1.37rem; color: #fecaca; background: #3b1212; border: 1px solid #7f1d1d; border-radius: 6px; padding: 6px 8px; text-align: center; margin-top: auto; }

/* ── Hourly production ── */
.shift-hourly-title { font-size: .72rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.shift-hourly { display: flex; flex-direction: column; gap: 3px; flex: 1; min-height: 0; overflow: hidden; }
.shift-hourly-row { display: grid; grid-template-columns: 8rem 1fr 3rem; align-items: center; gap: 5px; font-size: 1.26rem; }
.shift-h-label { color: var(--muted); font-family: var(--mono); font-size: .96rem; line-height: 1; white-space: nowrap; overflow: hidden; text-overflow: clip; }
.shift-h-bar-wrap { background: var(--bg); border-radius: 4px; height: 1.26rem; overflow: hidden; }
.shift-h-bar { height: 100%; background: var(--accent); border-radius: 3px; transition: width .4s; }
.shift-h-count { text-align: right; font-family: var(--mono); font-weight: 600; }
.shift-worker { font-size: .82rem; color: var(--muted); border-top: 1px solid var(--border); padding-top: 6px; }
</style>

<div class="shift-bubbles">
    <?php foreach ($projects as $proj):
        $d        = $allData[$proj];
        $lim      = $d['limits'];
        $hourly   = $d['hourly'];
        $pct      = $lim['limit'] > 0 ? min(100, (int)round($lim['counter'] / $lim['limit'] * 100)) : 0;
        $barClass = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : '');
        $isFull   = $lim['limit'] > 0 && $lim['counter'] >= $lim['limit'];
        $colClass = $isFull ? 'shift-bubble palette-full' : 'shift-bubble';
    ?>
    <div class="<?= $colClass ?>" id="shift-col-<?= h($proj) ?>">

        <div class="shift-proj-name"><?= strtoupper(h(traceabilityProjectLabel($proj))) ?></div>

        <div class="shift-palette">
            <div class="shift-pal-cell">
                <div class="shift-pal-val" id="shift-counter-<?= h($proj) ?>"><?= (int)$lim['counter'] ?></div>
                <div class="shift-pal-label"><?= h(t('traceability_palette_counter')) ?></div>
            </div>
            <div class="shift-pal-cell">
                <div class="shift-pal-val danger" id="shift-limit-<?= h($proj) ?>"><?= $lim['limit'] > 0 ? $lim['limit'] : '∞' ?></div>
                <div class="shift-pal-label"><?= h(t('traceability_palette_limit')) ?></div>
            </div>
            <?php if ($lim['limit'] > 0): ?>
            <div class="shift-progress-wrap">
                <div class="shift-progress-bar <?= $barClass ?>" id="shift-progress-<?= h($proj) ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="shift-hourly-title"><?= h(t('traceability_hourly_title')) ?></div>
        <div class="shift-hourly" id="shift-hourly-<?= h($proj) ?>">
            <?= renderShiftHourlyRows($hourly) ?>
        </div>

        <?php if ($isFull): ?>
        <div class="shift-palette-alert" id="shift-alert-<?= h($proj) ?>"><?= h(t('traceability_palette_reset_required')) ?></div>
        <?php endif; ?>

        <?php if (!empty($d['worker'])): ?>
        <div class="shift-worker" id="shift-worker-<?= h($proj) ?>"><?= t('traceability_worker') ?>: <?= h((string)$d['worker']) ?></div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>
</div>

</div>

<script>
(() => {
    const API_URL    = '<?= APP_URL ?>/api/traceability_tv_data.php';
    const INTERVAL   = 10000;
    const RESET_MSG  = <?= json_encode(t('traceability_palette_reset_required')) ?>;
    const WORKER_LBL = <?= json_encode(t('traceability_worker')) ?>;

    const renderHourly = (hourly) => {
        if (!hourly || !hourly.length) return '';
        const max = Math.max(1, ...hourly.map(r => r.count));
        return hourly.map(r => {
            const pct = Math.round(r.count / max * 100);
            return `<div class="shift-hourly-row">`
                 + `<span class="shift-h-label">${r.label}</span>`
                 + `<span class="shift-h-bar-wrap"><span class="shift-h-bar" style="width:${pct}%"></span></span>`
                 + `<span class="shift-h-count">${r.count}</span>`
                 + `</div>`;
        }).join('');
    };

    const updateBubble = (proj, d) => {
        const col = document.getElementById('shift-col-' + proj);
        if (!col) return;
        const lim = d.limits;

        const counterEl = document.getElementById('shift-counter-' + proj);
        if (counterEl) counterEl.textContent = lim.counter;

        const limitEl = document.getElementById('shift-limit-' + proj);
        if (limitEl) limitEl.textContent = lim.limit > 0 ? lim.limit : '∞';

        const progEl = document.getElementById('shift-progress-' + proj);
        if (progEl && lim.limit > 0) {
            const pct = Math.min(100, Math.round(lim.counter / lim.limit * 100));
            progEl.style.width = pct + '%';
            progEl.className = 'shift-progress-bar' + (pct >= 100 ? ' danger' : (pct >= 80 ? ' warning' : ''));
        }

        const isFull = lim.limit > 0 && lim.counter >= lim.limit;
        col.classList.toggle('palette-full', isFull);

        let alertEl = document.getElementById('shift-alert-' + proj);
        if (isFull && !alertEl) {
            const div = document.createElement('div');
            div.className = 'shift-palette-alert';
            div.id = 'shift-alert-' + proj;
            div.textContent = RESET_MSG;
            col.appendChild(div);
        } else if (!isFull && alertEl) {
            alertEl.remove();
        }

        const hourlyEl = document.getElementById('shift-hourly-' + proj);
        if (hourlyEl) hourlyEl.innerHTML = renderHourly(d.hourly);

        let workerEl = document.getElementById('shift-worker-' + proj);
        const worker = (d.worker || '').toString().trim();
        if (worker !== '') {
            if (!workerEl) {
                workerEl = document.createElement('div');
                workerEl.className = 'shift-worker';
                workerEl.id = 'shift-worker-' + proj;
            }
            workerEl.textContent = `${WORKER_LBL}: ${worker}`;
            col.appendChild(workerEl);
        } else if (workerEl) {
            workerEl.remove();
        }
    };

    let inFlight = false;
    const refresh = async () => {
        if (inFlight) return;
        inFlight = true;
        try {
            const resp = await fetch(`${API_URL}?_ts=${Date.now()}`, {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            if (!json.ok) throw new Error('API error');
            for (const [proj, data] of Object.entries(json.projects || {})) {
                updateBubble(proj, data);
            }
        } catch (e) {
        } finally {
            inFlight = false;
        }
    };

    setInterval(refresh, INTERVAL);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
})();
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
