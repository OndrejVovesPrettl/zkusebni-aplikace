<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireTraceabilityKioskLogin();

$kioskUser = getTraceabilityKioskUser();
$project = getTraceabilitySelectedProject();
if ($project === '' || !isTraceabilityProject($project)) {
    redirect(APP_URL . '/admin/traceability_kiosk_project.php');
}

$ok = '';
$error = '';
$themeOverride = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        $error = t('traceability_edit_failed');
    } else {
        if ((string)post('action') === 'logout') {
            clearTraceabilityKioskSession();
            redirect(APP_URL . '/admin/traceability_kiosk_login.php');
        }

        $value = trim((string)post('scan_value'));
        $command = strtolower($value);

        if ($command === 'project') {
            redirect(APP_URL . '/admin/traceability_kiosk_project.php');
        }

        if ($command === 'editor') {
            if ($kioskUser['rights'] >= 90) {
                redirect(APP_URL . '/admin/traceability_records.php?src_table=' . urlencode($project));
            }
            $error = t('traceability_kiosk_rights_denied');
        } elseif ($command === 'users') {
            if ($kioskUser['rights'] >= 100) {
                redirect(APP_URL . '/admin/users.php');
            }
            $error = t('traceability_kiosk_rights_denied');
        } else {
            $result = handleTraceabilityScanAction($project, $value, $kioskUser['id']);
            if (($result['action'] ?? '') === 'logout') {
                clearTraceabilityKioskSession();
                redirect(APP_URL . '/admin/traceability_kiosk_login.php');
            }
            if (($result['action'] ?? '') === 'set_theme') {
                $themeOverride = (string)($result['theme'] ?? '');
            }
            if ($result['ok']) {
                $ok = (string)$result['message'];
            } else {
                $error = (string)$result['message'];
            }
        }
    }
}

$limits = getTraceabilityProjectLimits($project);
$recentRows = getTraceabilityProjectRecentRows($project, 14);
$hourly = getTraceabilityHourlyProduction($project, 13);
$shiftCount = getTraceabilityShiftProduction($project);
$paletteFull = $limits['limit'] > 0 && $limits['counter'] >= $limits['limit'];
$scanTitle = (string)t('traceability_kiosk_scan_title');
$scanTitleFirst = function_exists('mb_substr') ? (string)mb_substr($scanTitle, 0, 1, 'UTF-8') : (string)substr($scanTitle, 0, 1);
$scanTitleRest = function_exists('mb_substr') ? (string)mb_substr($scanTitle, 1, null, 'UTF-8') : (string)substr($scanTitle, 1);
?>
<!DOCTYPE html>
<html lang="<?= h($_SESSION['lang'] ?? 'cs') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle(t('traceability_kiosk_scan_title'))) ?></title>
<script>
(() => {
        try {
        const forcedTheme = '<?= h($themeOverride) ?>';
                const stored = localStorage.getItem('dvd_theme');
                const sessionTheme = '<?= h((string)($_SESSION['trace_kiosk_theme'] ?? '')) ?>';
        const theme = (forcedTheme === 'light' || forcedTheme === 'dark')
            ? forcedTheme
            : ((stored === 'light' || stored === 'dark') ? stored : ((sessionTheme === 'light' || sessionTheme === 'dark') ? sessionTheme : 'dark'));
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
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:var(--font);background:var(--bg);color:var(--text);overflow:hidden}
.kiosk-shell{height:100%;display:grid;grid-template-rows:auto auto 1fr;padding:14px;gap:12px}
.header{display:flex;justify-content:space-between;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:10px 14px;gap:12px}
.title{font-size:1.4rem;font-weight:700}
.title-admin-link,.title-admin-link:visited{color:inherit;text-decoration:none}
.title-admin-link:hover,.title-admin-link:focus{color:inherit;text-decoration:none;outline:none}
.meta{font-size:.95rem;color:var(--muted)}
.header-right{display:flex;align-items:center;gap:12px}
.clock-kiosk{font-family:var(--mono);color:var(--accent);font-size:1.02rem;white-space:nowrap}
.kiosk-tools{display:flex;align-items:center;gap:8px}
.kiosk-tools .theme-switch-wrap{margin-bottom:0}
.lang-link{color:var(--muted);text-decoration:none;font-size:.95rem;line-height:1}
.lang-link.active{color:var(--accent)}
.kiosk-logout-form{display:inline-flex;align-items:center;margin:0}
.kiosk-logout-btn{display:inline-flex;align-items:center;gap:.3rem;border:1px solid var(--border2);background:rgba(255,255,255,.03);color:var(--text);padding:.26rem .55rem;border-radius:8px;font-size:.75rem;line-height:1;cursor:pointer}
.kiosk-logout-btn:hover{border-color:var(--accent);color:var(--accent)}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;width:100%}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px;text-align:center}
.stat .v{font-size:1.8rem;font-weight:700}
.stat .l{font-size:.78rem;color:var(--muted)}
.kiosk-main{display:grid;grid-template-columns:1fr 1fr;gap:12px;min-height:0}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px;display:flex;flex-direction:column;min-height:0;min-width:0;overflow:hidden}
.msg-ok,.msg-err{padding:8px 10px;border-radius:8px;margin-bottom:8px;font-size:.88rem}
.msg-ok{background:#123123;color:#86efac;border:1px solid #166534}
.msg-err{background:#3b1212;color:#fecaca;border:1px solid #7f1d1d}
.scan-input{display:block;max-width:100%;width:100%;font-size:2rem;padding:.75rem 1rem;border-radius:12px;border:1px solid var(--border2);background:var(--bg);color:var(--text);text-align:center}
.hint{margin-top:8px;font-size:.8rem;color:var(--muted)}
.table-wrap{overflow:auto;min-height:0}
.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th,.tbl td{padding:7px 8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.tbl th{position:sticky;top:0;background:var(--surface2)}
.palette-alert{padding:8px 10px;border-radius:8px;background:#3b1212;color:#fecaca;border:1px solid #7f1d1d;margin-bottom:8px}
.shift-toggle-wrap{display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:var(--muted);cursor:pointer}
.shift-toggle-pill{position:relative;display:inline-flex;align-items:center;background:var(--border2);border-radius:999px;padding:2px;gap:0;border:1px solid var(--border)}
.shift-toggle-pill span{padding:2px 8px;border-radius:999px;font-size:.74rem;font-weight:600;line-height:1.6;transition:background .18s,color .18s;color:var(--muted);white-space:nowrap}
.shift-toggle-pill span.active{background:var(--accent);color:#fff}
@media (max-width: 1100px){
    .stats{grid-template-columns:1fr 1fr;}
    .kiosk-main{grid-template-columns:1fr;}
}
@media (max-width: 820px){
    .header{flex-direction:column;align-items:flex-start}
    .header-right{width:100%;justify-content:space-between}
}
</style>
</head>
<body>
<div class="kiosk-shell">
    <div class="header">
        <div>
            <div class="title"><a class="title-admin-link" href="<?= APP_URL ?>/index.php"><?= h($scanTitleFirst) ?></a><?= h($scanTitleRest) ?></div>
            <div class="meta"><?= h(traceabilityProjectLabel($project)) ?> | <?= h($kioskUser['username']) ?></div>
        </div>
        <div class="header-right">
            <div class="clock-kiosk"><span id="clock-time-kiosk"></span> <span id="clock-date-kiosk"></span></div>
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
                <a class="lang-link <?= $lang === 'cs' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/traceability_kiosk_scan.php?lang=cs">CZ</a>
                <a class="lang-link <?= $lang === 'en' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/traceability_kiosk_scan.php?lang=en">EN</a>
                <a class="lang-link <?= $lang === 'uk' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/traceability_kiosk_scan.php?lang=uk">UA</a>
                <form method="post" class="kiosk-logout-form">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="kiosk-logout-btn"><i class="bi bi-box-arrow-right"></i><?= h(t('traceability_kiosk_logout')) ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="stats" style="grid-template-columns:1fr 1fr 1fr">
        <div class="stat"><div class="v" id="kiosk-counter" style="font-size:5.4rem"><?= (int)$limits['counter'] ?></div><div class="l"><?= h(t('traceability_palette_counter')) ?></div></div>
        <div class="stat"><div class="v" id="kiosk-limit" style="font-size:5.4rem;color:var(--danger)"><?= (int)$limits['limit'] ?></div><div class="l"><?= h(t('traceability_palette_limit')) ?></div></div>
        <div class="stat"><div class="v" id="kiosk-shift-count" style="font-size:5.4rem;color:var(--accent3)"><?= (int)$shiftCount ?></div><div class="l"><?= h(t('traceability_shift_count')) ?></div></div>
        <?php /*
        <div class="stat"><div class="v" style="font-size:1.2rem"><?= h((string)$limits['with_break']) ?></div><div class="l"><?= h(t('traceability_norm_with_break')) ?></div></div>
        <div class="stat"><div class="v" style="font-size:1.2rem"><?= h((string)$limits['without_break']) ?></div><div class="l"><?= h(t('traceability_norm_without_break')) ?></div></div>
        */ ?>
    </div>

    <div class="kiosk-main">
        <div class="panel">
            <?php if ($ok !== ''): ?><div class="msg-ok">✓ <?= h($ok) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="msg-err">✗ <?= h($error) ?></div><?php endif; ?>
            <div class="palette-alert" id="kiosk-palette-alert" style="<?= $paletteFull ? '' : 'display:none' ?>"><?= h(t('traceability_palette_reset_required')) ?></div>

            <form method="post" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="text" name="scan_value" class="scan-input" autofocus autocomplete="off" required>
            </form>

            <div class="table-wrap" style="margin-top:10px">
                <table class="tbl">
                    <thead><tr><th>ID</th><th><?= h(t('traceability_serial')) ?></th><th><?= h(t('datetime')) ?></th><th><?= h(t('user')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentRows as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= h((string)$row['snDil']) ?></td>
                        <td><?= h((string)$row['timestamp']) ?></td>
                        <td><?= h((string)$row['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div style="font-size:1rem;font-weight:600;margin-bottom:8px"><?= h(t('traceability_hourly_title')) ?></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th><?= h(t('hour')) ?></th><th><?= h(t('pocet')) ?></th></tr></thead>
                    <tbody id="hourly-tbody">
                    <?php foreach ($hourly as $row): ?>
                    <tr>
                        <td><?= h((string)$row['label']) ?></td>
                        <td><?= (int)$row['count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const timeEl = document.getElementById('clock-time-kiosk');
    const dateEl = document.getElementById('clock-date-kiosk');
    const scanInput = document.querySelector('input[name="scan_value"]');
    const counterEl = document.getElementById('kiosk-counter');
    const limitEl = document.getElementById('kiosk-limit');
    const shiftCountEl = document.getElementById('kiosk-shift-count');
    const paletteAlertEl = document.getElementById('kiosk-palette-alert');
    const API_URL = '<?= APP_URL ?>/api/traceability_kiosk_data.php';
    const shiftToggle = document.getElementById('shift-mode-toggle');
    const shiftLbl8 = document.getElementById('shift-lbl-8h');
    const shiftLbl12 = document.getElementById('shift-lbl-12h');

    const refocusScanInput = () => {
        if (!scanInput) return;
        window.setTimeout(() => {
            scanInput.focus({ preventScroll: true });
        }, 0);
    };

    if (scanInput) {
        scanInput.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (document.activeElement === document.body) {
                    scanInput.focus({ preventScroll: true });
                }
            }, 80);
        });
    }

    const hourlyTbody = document.getElementById('hourly-tbody');
    const TOTAL_HOURLY_ROWS = 13;
    const ROWS_8H = 9;

    const applyHourlyRows = (mode) => {
        if (!hourlyTbody) return;
        const rows = hourlyTbody.querySelectorAll('tr');
        const hideCount = mode === '8h' ? (TOTAL_HOURLY_ROWS - ROWS_8H) : 0;
        rows.forEach((row, idx) => {
            row.style.display = idx < hideCount ? 'none' : '';
        });
    };

    let shiftMode = localStorage.getItem('trace_shift_mode') === '12h' ? '12h' : '8h';
    const applyShiftMode = (mode, refresh = true) => {
        shiftMode = mode;
        localStorage.setItem('trace_shift_mode', mode);
        if (shiftLbl8) shiftLbl8.classList.toggle('active', mode === '8h');
        if (shiftLbl12) shiftLbl12.classList.toggle('active', mode === '12h');
        applyHourlyRows(mode);
        if (refresh) refreshKioskState();
        refocusScanInput();
    };
    if (shiftToggle) {
        shiftToggle.addEventListener('click', () => applyShiftMode(shiftMode === '8h' ? '12h' : '8h'));
    }
    applyShiftMode(shiftMode, false);
    if (!timeEl || !dateEl) return;

    const locale = '<?= ($_SESSION['lang'] ?? 'cs') === 'uk' ? 'uk-UA' : ((($_SESSION['lang'] ?? 'cs') === 'en') ? 'en-GB' : 'cs-CZ') ?>';
    const render = () => {
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        dateEl.textContent = now.toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

    render();
    setInterval(render, 1000);

    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
        const toggle = document.getElementById('theme-toggle-checkbox');
        if (toggle) {
            toggle.checked = theme === 'dark';
        }
    };

    document.getElementById('theme-toggle-checkbox')?.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        applyTheme(target.checked ? 'dark' : 'light');
        refocusScanInput();
    });

    let refreshInFlight = false;
    const refreshKioskState = async () => {
        if (refreshInFlight) return;
        refreshInFlight = true;
        try {
            const sep = API_URL.includes('?') ? '&' : '?';
            const response = await fetch(`${API_URL}${sep}shift_mode=${encodeURIComponent(shiftMode)}&_ts=${Date.now()}`, { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);

            const data = await response.json();
            if (!data.ok || !data.limits) throw new Error('Invalid payload');

            if (counterEl) counterEl.textContent = String(data.limits.counter ?? 0);
            if (limitEl) limitEl.textContent = String(data.limits.limit ?? 0);
            if (shiftCountEl) shiftCountEl.textContent = String(data.shift_count ?? 0);
            if (paletteAlertEl) paletteAlertEl.style.display = data.palette_full ? '' : 'none';
        } catch (e) {
        } finally {
            refreshInFlight = false;
        }
    };

    applyTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    refreshKioskState();
    setInterval(refreshKioskState, 5000);
    window.addEventListener('focus', refreshKioskState);
    window.addEventListener('online', refreshKioskState);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshKioskState();
    });
    refocusScanInput();
})();
</script>
</body>
</html>
