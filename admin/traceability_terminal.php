<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'writer', 'traceability']);

$pageTitle = t('traceability_terminal_title');
$activePage = 'traceability_terminal';
$canTerminalScan = hasAnyRole(['superadmin', 'admin', 'traceability']);
$canPaletteReset = hasAnyRole(['superadmin', 'admin', 'writer']);
$projects = getTraceabilityProjects();
$currentProject = getTraceabilitySelectedProject();
$flashOk = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $action = (string) post('action');
    if ($action === 'select_project') {
        $selectedProject = trim((string) post('project'));
        if (isTraceabilityProject($selectedProject)) {
            setTraceabilitySelectedProject($selectedProject);
            redirect(APP_URL . '/admin/traceability_terminal.php');
        }
        $flashErr = t('traceability_project_invalid');
    }

    if ($action === 'scan') {
        $selectedProject = trim((string) post('project'));
        if (isTraceabilityProject($selectedProject)) {
            setTraceabilitySelectedProject($selectedProject);
            $currentProject = $selectedProject;
        }

        $scanValue = trim((string) post('scan_value'));
        $isReset = $scanValue === 'ResetPalety';
        if (!$canTerminalScan && (!$canPaletteReset || !$isReset)) {
            $flashErr = t('superadmin_only');
        } else {
            $traceUserId = getCurrentTraceabilityUserId();
            $result = handleTraceabilityScanAction($currentProject, (string) post('scan_value'), $traceUserId);
            if (($result['action'] ?? '') === 'logout') {
                redirect(APP_URL . '/admin/logout.php');
            }
            if ($result['ok']) {
                $flashOk = (string) $result['message'];
            } else {
                $flashErr = (string) $result['message'];
            }
        }
    }
}

// AJAX endpoint for live refresh (must run before page rendering logic)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $proj = trim((string)($_GET['project'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');

    if (!$proj || !isTraceabilityProject($proj)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid project'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $projLimits = getTraceabilityProjectLimits($proj);
        $projShiftCount = getTraceabilityShiftProduction($proj);
        $projRecent = getTraceabilityProjectRecentRows($proj, 12);
        echo json_encode([
            'ok' => true,
            'limits' => $projLimits,
            'shift_count' => $projShiftCount,
            'recent' => $projRecent,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$currentProject = getTraceabilitySelectedProject();
$traceUserId = getCurrentTraceabilityUserId();
$limits = $currentProject !== '' ? getTraceabilityProjectLimits($currentProject) : ['limit' => 0, 'counter' => 0, 'project' => ''];
$shiftCount = $currentProject !== '' ? getTraceabilityShiftProduction($currentProject) : 0;
$hourlyProduction = $currentProject !== '' ? getTraceabilityHourlyProduction($currentProject, 9) : [];
$recentRows = $currentProject !== '' ? getTraceabilityProjectRecentRows($currentProject, 12) : [];
$currentUsername = (string)db_val(
    'SELECT COALESCE(NULLIF(skutecneJmeno, ""), username) FROM users WHERE id = ? LIMIT 1',
    [(int)($_SESSION['admin_user_id'] ?? 0)]
);
if ($currentUsername === '') {
    $currentUsername = (string) ($_SESSION['admin_user'] ?? '');
}
$paletteFull = $limits['limit'] > 0 && $limits['counter'] >= $limits['limit'];

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-upc me-2"></i><?= t('traceability_terminal_title') ?></span>
        <?php if ($currentProject !== ''): ?>
        <span class="topbar-meta"><i class="bi bi-box-seam me-1"></i><?= h(traceabilityProjectLabel($currentProject)) ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="traceability.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-graph-up me-1"></i><?= t('traceability_title') ?></a>
        <a href="traceability_shift.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-clock-history me-1"></i><?= t('traceability_shift_title') ?></a>
        <a href="traceability_kiosk_login.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-fullscreen me-1"></i><?= t('nav_traceability_kiosk') ?></a>
        <?php if (canTraceabilityEdit()): ?>
        <a href="traceability_records.php?src_table=<?= urlencode($currentProject) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-table me-1"></i><?= t('traceability_editor_link') ?></a>
        <?php endif; ?>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <?php if ($flashOk): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✓ <?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✗ <?= h($flashErr) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <div class="card-dvd h-100">
                <div class="card-header-dvd"><span class="s-num"><i class="bi bi-upc-scan"></i></span><span class="s-title"><?= t('traceability_terminal_title') ?></span></div>
                <div class="card-body-dvd">
                    <form method="post" class="mb-4 no-print">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="select_project">
                        <label class="form-label-dvd"><?= t('traceability_project_select') ?></label>
                        <div class="d-flex gap-2 flex-wrap">
                            <select name="project" class="form-select" style="max-width:260px" onchange="this.form.requestSubmit()">
                                <?php foreach ($projects as $project): ?>
                                <option value="<?= h($project) ?>" <?= $currentProject === $project ? 'selected' : '' ?>><?= h(traceabilityProjectLabel($project)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-check2 me-1"></i><?= t('select') ?></button>
                        </div>
                    </form>

                    <div class="mb-4" style="font-size:.9rem;color:var(--muted)">
                        <strong style="color:var(--text)"><?= t('user') ?>:</strong> <?= h($currentUsername) ?>
                        <?php if ($traceUserId > 0): ?>
                        <span class="ms-3"><strong style="color:var(--text)">Traceability ID:</strong> #<?= (int) $traceUserId ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--accent)" id="term-counter"><?= (int) $limits['counter'] ?></div><div class="stat-label"><?= t('traceability_palette_counter') ?></div></div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--danger)"><?= (int) $limits['limit'] ?></div><div class="stat-label"><?= t('traceability_palette_limit') ?></div></div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--accent3)" id="term-shift"><?= (int) $shiftCount ?></div><div class="stat-label"><?= t('traceability_shift_count') ?></div></div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--warn)" id="term-norm"><?= h((string) $limits['without_break']) ?></div><div class="stat-label"><?= t('traceability_norm_without_break') ?></div></div>
                        </div>
                    </div>

                    <div id="term-refresh-status" style="font-size:.74rem;color:var(--muted);margin:-.15rem 0 .9rem 0">
                        Live refresh: inicializace…
                    </div>

                    <?php if ($paletteFull): ?>
                    <div style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem">
                        <?= t('traceability_palette_reset_required') ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!$canTerminalScan && !$canPaletteReset): ?>
                    <div style="background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.3);color:var(--warn);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem">
                        <?= t('superadmin_only') ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" class="no-print">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="scan">
                        <input type="hidden" name="project" value="<?= h($currentProject) ?>">
                        <label class="form-label-dvd"><?= t('traceability_scan_input') ?></label>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <input type="text" name="scan_value" class="form-control" style="max-width:360px" <?= $canTerminalScan ? 'autofocus' : '' ?> autocomplete="off" placeholder="<?= h(t('traceability_scan_placeholder')) ?>" <?= $canTerminalScan ? '' : 'disabled' ?>>
                            <button type="submit" class="btn-dvd" <?= $canTerminalScan ? '' : 'disabled' ?>><i class="bi bi-box-arrow-in-right me-1"></i><?= t('save') ?></button>
                            <button type="submit" name="scan_value" value="ResetPalety" data-confirm="<?= h(t('traceability_palette_reset_confirm')) ?>" class="btn-outline" style="padding:.55rem 1rem;font-size:.82rem" <?= $canPaletteReset ? '' : 'disabled' ?>><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('traceability_palette_reset') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card-dvd h-100">
                <div class="card-header-dvd"><span class="s-num"><i class="bi bi-clock-history"></i></span><span class="s-title"><?= t('traceability_hourly_title') ?></span></div>
                <div class="card-body-dvd" style="padding:0">
                    <div style="overflow-x:auto">
                        <table class="table-dvd" style="width:100%;font-size:.78rem">
                            <thead><tr><th><?= t('hour') ?></th><th style="text-align:right"><?= t('pocet') ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($hourlyProduction as $row): ?>
                                <tr>
                                    <td><?= h($row['label']) ?></td>
                                    <td style="text-align:right"><?= (int) $row['count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-dvd">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-list-ul"></i></span><span class="s-title"><?= t('traceability_recent_scans') ?></span></div>
        <div class="card-body-dvd" style="padding:0">
            <div style="overflow-x:auto">
                    <table class="table-dvd" style="width:100%;font-size:.78rem">
                    <thead><tr><th>ID</th><th><?= t('traceability_serial') ?></th><th><?= t('datetime') ?></th><th><?= t('user') ?></th></tr></thead>
                    <tbody id="recent-rows-body">
                    <?php if (!$recentRows): ?>
                        <tr><td colspan="4" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                    <?php else: foreach ($recentRows as $row): ?>
                        <tr>
                            <td class="mono"><?= (int) $row['id'] ?></td>
                            <td class="mono"><?= h((string) $row['snDil']) ?></td>
                            <td class="mono"><?= h((string) $row['timestamp']) ?></td>
                            <td><?= h((string) $row['username']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

<script>
(function() {
    var currentProject = '<?= h($currentProject) ?>';
    var currentProjectLabel = '<?= h(traceabilityProjectLabel($currentProject)) ?>';
    if (!currentProject) return;

    var refreshInFlight = false;
    var statusEl = document.getElementById('term-refresh-status');

    // Auto-refresh every 5 seconds
    function renderRecentRows(rows) {
        var body = document.getElementById('recent-rows-body');
        if (!body) return;
        if (!rows || !rows.length) {
            body.innerHTML = '<tr><td colspan="4" style="padding:.9rem 1rem;color:var(--muted)"><?= h(t('no_data')) ?></td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(r) {
            var id = parseInt(r.id) || 0;
            var sn = (r.snDil || r.sn || '').toString();
            var ts = (r.timestamp || '').toString();
            var user = (r.username || '').toString();
            html += '<tr>'
                + '<td class="mono">' + id + '</td>'
                + '<td class="mono">' + (escapeHtml(sn)) + '</td>'
                + '<td class="mono">' + (escapeHtml(ts)) + '</td>'
                + '<td>' + (escapeHtml(user)) + '</td>'
                + '</tr>';
        });
        body.innerHTML = html;
    }

    function escapeHtml(s){
        return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function nowTimeLabel() {
        var d = new Date();
        return d.toLocaleTimeString('cs-CZ', { hour12: false });
    }

    function setRefreshStatus(ok, text) {
        if (!statusEl) return;
        if (ok) {
            statusEl.style.color = 'var(--success)';
            statusEl.textContent = 'Live refresh: OK (' + nowTimeLabel() + ')' + (text ? ' · ' + text : '');
            return;
        }

        statusEl.style.color = 'var(--danger)';
        statusEl.textContent = 'Live refresh: chyba (' + nowTimeLabel() + ')' + (text ? ' · ' + text : '');
    }

    function buildAjaxUrl() {
        var sep = window.location.pathname.indexOf('?') === -1 ? '?' : '&';
        return window.location.pathname + sep
            + 'ajax=1&project=' + encodeURIComponent(currentProject)
            + '&_ts=' + Date.now();
    }

    function refreshTerminal() {
        if (refreshInFlight) return;
        refreshInFlight = true;

        fetch(buildAjaxUrl(), {
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function(data) {
                if (!data || !data.ok) {
                    setRefreshStatus(false, 'neplatna odpoved');
                    return;
                }

                if (data.limits) {
                    var counterEl = document.getElementById('term-counter');
                    if (counterEl) {
                        var counter = data.limits.counter;
                        if (typeof counter === 'undefined' || counter === null) {
                            counter = data.limits.counterOnPallete;
                        }
                        if (typeof counter === 'undefined' || counter === null) {
                            counter = data.limits.counter_on_palette;
                        }
                        counterEl.textContent = String(parseInt(counter, 10) || 0);
                    }

                    var normEl = document.getElementById('term-norm');
                    if (normEl && typeof data.limits.without_break !== 'undefined') {
                        normEl.textContent = String(data.limits.without_break || '');
                    }
                }

                if (typeof data.shift_count !== 'undefined') {
                    var shiftEl = document.getElementById('term-shift');
                    if (shiftEl) shiftEl.textContent = String(parseInt(data.shift_count, 10) || 0);
                }

                if (Array.isArray(data.recent)) {
                    renderRecentRows(data.recent);
                }

                setRefreshStatus(true, 'projekt ' + currentProjectLabel);
            })
            .catch(function(err) {
                var detail = (err && err.message) ? err.message : 'network/API';
                setRefreshStatus(false, detail);
            })
            .finally(function() {
                refreshInFlight = false;
            });
    }

    refreshTerminal();
    setInterval(refreshTerminal, 5000);
    window.addEventListener('focus', refreshTerminal);
    window.addEventListener('online', refreshTerminal);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) refreshTerminal();
    });
})();
</script>
