<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireReworkKioskLogin();

$kioskUser    = getReworkKioskUser();
$storageReady = ensureReworkTables();
$projects     = getReworkProjects();
$reasons      = getReworkReasons();
$actions      = getReworkActions();

$flashOk  = '';
$flashErr = '';
$lastDmc  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    if ((string)post('action') === 'logout') {
        clearReworkKioskSession();
        redirect(APP_URL . '/admin/rework_kiosk_login.php');
    }

    if ((string)post('action') === 'save' && $storageReady) {
        $projectId      = max(0, (int)post('project_id'));
        $reasonId       = max(0, (int)post('reason_id'));
        $actionId       = max(0, (int)post('action_id'));
        $dmc            = trim((string)post('dmc'));
        $replacementDmc = trim((string)post('replacement_dmc'));
        $note           = trim((string)post('note'));
        $lastDmc        = $dmc;

        if ($projectId <= 0 || $reasonId <= 0 || $actionId <= 0 || $dmc === '') {
            $flashErr = t('required');
        } else {
            try {
                $entryId = createReworkEntry([
                    'project_id'      => $projectId,
                    'reason_id'       => $reasonId,
                    'action_id'       => $actionId,
                    'dmc'             => $dmc,
                    'replacement_dmc' => $replacementDmc,
                    'note'            => $note,
                    'recorded_at'     => date('Y-m-d H:i:s'),
                ], (int)$kioskUser['id']);

                logAction('REWORK_KIOSK_ENTRY', 'rework_entries', $entryId, [], [
                    'project_id' => $projectId,
                    'reason_id'  => $reasonId,
                    'action_id'  => $actionId,
                    'dmc'        => $dmc,
                    'kiosk_user' => $kioskUser['username'],
                ], t('rework_saved'));

                redirect(APP_URL . '/admin/rework_kiosk_form.php?ok=1');
            } catch (Throwable $e) {
                $flashErr = t('rework_save_failed') . ': ' . $e->getMessage();
            }
        }
    }
}

if ((string)get('ok') === '1') {
    $flashOk = t('rework_saved');
}

// Default selections from session
$defaultProjectId = (int)($_SESSION['rework_kiosk_project_id'] ?? (empty($projects) ? 0 : (int)$projects[0]['id']));
$defaultReasonId  = (int)($_SESSION['rework_kiosk_reason_id'] ?? 0);
$defaultActionId  = (int)($_SESSION['rework_kiosk_action_id'] ?? 0);
$recentRows       = getRecentReworkEntries(14);
$hourlyRows       = getReworkHourlyStats(13);
$todayStats       = getReworkTodayStats();
?>
<!DOCTYPE html>
<html lang="<?= h($_SESSION['lang'] ?? 'cs') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle(t('rework_kiosk_form_title'), t('app_name'))) ?></title>
<script>
(() => {
    try {
        const saved = localStorage.getItem('dvd_theme');
        const theme = (saved === 'light' || saved === 'dark') ? saved : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
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
.stats{display:grid;grid-template-columns:1fr;gap:12px;width:100%}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px;text-align:center}
.stat .v{font-size:3.2rem;font-weight:700;line-height:1.1}
.stat .l{font-size:.78rem;color:var(--muted)}
.kiosk-main{display:grid;grid-template-columns:1fr 1fr;gap:12px;min-height:0}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px;display:flex;flex-direction:column;min-height:0;min-width:0;overflow:hidden}
.panel-title{font-size:1rem;font-weight:600;margin-bottom:8px}
.msg-ok,.msg-err{padding:8px 10px;border-radius:8px;margin-bottom:8px;font-size:.88rem}
.msg-ok{background:#123123;color:#86efac;border:1px solid #166534}
.msg-err{background:#3b1212;color:#fecaca;border:1px solid #7f1d1d}
.kf-label{font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.35rem}
.kf-row{display:grid;gap:10px;margin-bottom:10px}
.kf-input,.kf-select,.kf-textarea{display:block;max-width:100%;width:100%;min-height:56px;border-radius:12px;border:1px solid var(--border2);background:var(--bg);color:var(--text)}
.kf-input{font-size:1.6rem;padding:.7rem .95rem;font-family:var(--mono)}
.kf-select{font-size:1.1rem;padding:.7rem .95rem;line-height:1.2}
.kf-textarea{font-size:1rem;padding:.7rem .95rem;line-height:1.35;resize:vertical;min-height:88px;font-family:var(--font)}
.kf-input:focus,.kf-select:focus,.kf-textarea:focus{outline:2px solid var(--accent);border-color:var(--accent)}
.kf-submit{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;width:100%;font-size:1.15rem;padding:.72rem 1rem;border-radius:10px;background:var(--accent);color:#000;border:none;font-weight:600;cursor:pointer}
.kf-submit:hover{opacity:.88}
.table-wrap{overflow:auto;min-height:0}
.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th,.tbl td{padding:7px 8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.tbl th{position:sticky;top:0;background:var(--surface2)}
@media (max-width: 1400px){
    .kiosk-main{grid-template-columns:1fr 1fr;}
}
@media (max-width: 1200px){
    .kiosk-main{grid-template-columns:1fr;}
}
@media (max-width: 900px){
    .header{flex-direction:column;align-items:flex-start}
    .header-right{width:100%;justify-content:space-between}
}
@media (max-width: 760px){
    .stats{grid-template-columns:1fr;}
    .stat .v{font-size:2.6rem;}
}
@media(min-width:640px){.kf-row-2{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="kiosk-shell">
    <div class="header">
        <div>
            <div class="title"><a class="title-admin-link" href="<?= APP_URL ?>/index.php"><?= h(t('rework_kiosk_form_title')) ?></a></div>
            <div class="meta"><?= h(t('rework_kiosk_welcome')) ?>: <strong><?= h($kioskUser['username']) ?></strong></div>
        </div>
        <div class="header-right">
            <div class="clock-kiosk"><span id="clock-time-kiosk"></span> <span id="clock-date-kiosk"></span></div>
            <div class="kiosk-tools">
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
                <a class="lang-link <?= $lang === 'cs' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/rework_kiosk_form.php?lang=cs">CZ</a>
                <a class="lang-link <?= $lang === 'en' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/rework_kiosk_form.php?lang=en">EN</a>
                <a class="lang-link <?= $lang === 'uk' ? 'active' : '' ?>" href="<?= APP_URL ?>/admin/rework_kiosk_form.php?lang=uk">UA</a>
                <form method="post" class="kiosk-logout-form">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="kiosk-logout-btn"><i class="bi bi-box-arrow-right"></i><?= h(t('rework_kiosk_logout')) ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="v"><?= (int)($todayStats['total'] ?? 0) ?></div>
            <div class="l"><?= h(t('rework_today')) ?></div>
        </div>
    </div>

    <div class="kiosk-main">
        <div class="panel">
            <div class="panel-title"><?= h(t('rework_kiosk_form_title')) ?></div>
            <?php if ($flashOk !== ''): ?><div class="msg-ok">✓ <?= h($flashOk) ?></div><?php endif; ?>
            <?php if ($flashErr !== ''): ?><div class="msg-err">✗ <?= h($flashErr) ?></div><?php endif; ?>
            <?php if (!$storageReady): ?>
            <div class="msg-err"><?= h(t('rework_kiosk_storage_unavailable')) ?></div>
            <?php else: ?>

            <form method="post" id="reworkForm" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="save">

                <!-- Project + DMC -->
                <div class="kf-row kf-row-2">
                    <div>
                        <div class="kf-label"><?= t('rework_project') ?> <span style="color:var(--danger)">*</span></div>
                        <select name="project_id" id="projectSel" class="kf-select" required>
                            <option value=""><?= t('select') ?>…</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defaultProjectId ? 'selected' : '' ?>>
                                <?= h((string)$p['code']) ?><?= !empty($p['label']) ? ' – ' . h((string)$p['label']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <div class="kf-label"><?= t('rework_internal_code') ?> <span style="color:var(--danger)">*</span></div>
                        <input type="text" name="dmc" id="dmcInput" class="kf-input" required autofocus
                            autocomplete="off" value="<?= h($lastDmc) ?>">
                    </div>
                </div>

                <!-- Reason + Action -->
                <div class="kf-row kf-row-2">
                    <div>
                        <div class="kf-label"><?= t('rework_reason') ?> <span style="color:var(--danger)">*</span></div>
                        <select name="reason_id" id="reasonSel" class="kf-select" required>
                            <option value=""><?= t('select') ?>…</option>
                            <?php foreach ($reasons as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $defaultReasonId ? 'selected' : '' ?>>
                                <?= h((string)$r['code'] . ' – ' . (string)$r['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <div class="kf-label"><?= t('rework_action') ?> <span style="color:var(--danger)">*</span></div>
                        <select name="action_id" id="actionSel" class="kf-select" required>
                            <option value=""><?= t('select') ?>…</option>
                            <?php foreach ($actions as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $defaultActionId ? 'selected' : '' ?>>
                                <?= h((string)$a['code'] . ' – ' . (string)$a['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Optional fields -->
                <div class="kf-row">
                    <div style="width:100%">
                        <div class="kf-label"><?= t('rework_replacement_code') ?></div>
                        <input type="text" name="replacement_dmc" class="kf-input" autocomplete="off">
                    </div>
                </div>

                <div class="kf-row">
                    <div style="width:100%">
                        <div class="kf-label"><?= t('rework_note') ?></div>
                        <textarea name="note" class="kf-textarea" autocomplete="off"></textarea>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="kf-btn-row">
                    <button type="submit" class="kf-submit">
                        <i class="bi bi-check2-circle me-1"></i><?= h(t('rework_kiosk_submit')) ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div style="display:flex;flex-direction:column;gap:12px;min-height:0">
            <div class="panel">
                <div class="panel-title"><?= h(t('rework_recent')) ?></div>
                <div class="table-wrap" style="margin-bottom:10px">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?= h(t('rework_project')) ?></th>
                                <th><?= h(t('rework_dmc')) ?></th>
                                <th><?= h(t('rework_reason')) ?></th>
                                <th><?= h(t('rework_action')) ?></th>
                                <th><?= h(t('datetime')) ?></th>
                                <th><?= h(t('user')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentRows): ?>
                            <tr><td colspan="7"><?= h(t('no_data')) ?></td></tr>
                        <?php else: foreach ($recentRows as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= h((string)$row['project_code']) ?></td>
                                <td><?= h((string)$row['dmc']) ?></td>
                                <td><?= h((string)$row['reason_code']) ?></td>
                                <td><?= h((string)$row['action_code']) ?></td>
                                <td><?= h((string)$row['recorded_at']) ?></td>
                                <td><?= h((string)$row['recorded_by']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title"><?= h(t('rework_hourly_title')) ?></div>
                <div class="table-wrap">
                    <table class="tbl">
                        <thead><tr><th><?= h(t('hour')) ?></th><th><?= h(t('pocet')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($hourlyRows as $row): ?>
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
</div>

<script>
(() => {
    const form      = document.getElementById('reworkForm');
    const dmcInput  = document.getElementById('dmcInput');
    const reasonSel = document.getElementById('reasonSel');
    const actionSel = document.getElementById('actionSel');
    const timeEl    = document.getElementById('clock-time-kiosk');
    const dateEl    = document.getElementById('clock-date-kiosk');

    // Save selections to session via hidden fields is not needed — use sessionStorage
    const saveSelections = () => {
        try {
            sessionStorage.setItem('rework_kiosk_project', document.getElementById('projectSel')?.value ?? '');
            sessionStorage.setItem('rework_kiosk_reason',  reasonSel?.value ?? '');
            sessionStorage.setItem('rework_kiosk_action',  actionSel?.value ?? '');
        } catch (e) {}
    };

    // Restore selections from sessionStorage
    const restoreSelections = () => {
        try {
            const p = sessionStorage.getItem('rework_kiosk_project');
            const r = sessionStorage.getItem('rework_kiosk_reason');
            const a = sessionStorage.getItem('rework_kiosk_action');
            if (p) { const el = document.getElementById('projectSel'); if (el) el.value = p; }
            if (r && reasonSel) reasonSel.value = r;
            if (a && actionSel) actionSel.value = a;
        } catch (e) {}
    };

    restoreSelections();
    if (form) form.addEventListener('change', saveSelections);

    const locale = '<?= ($_SESSION['lang'] ?? 'cs') === 'uk' ? 'uk-UA' : ((($_SESSION['lang'] ?? 'cs') === 'en') ? 'en-GB' : 'cs-CZ') ?>';
    const renderClock = () => {
        if (!timeEl || !dateEl) return;
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        dateEl.textContent = now.toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

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
        if (dmcInput) dmcInput.focus({ preventScroll: true });
    });

    // Focus DMC after load
    if (dmcInput) {
        dmcInput.focus();
        dmcInput.select();
    }

    renderClock();
    setInterval(renderClock, 1000);
    applyTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

    // On Enter in DMC field, move focus to reason if not set
    if (dmcInput) {
        dmcInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (reasonSel && reasonSel.value === '') {
                    reasonSel.focus();
                } else if (actionSel && actionSel.value === '') {
                    actionSel.focus();
                } else {
                    saveSelections();
                    form?.requestSubmit();
                }
            }
        });
    }
})();
</script>
</body>
</html>
