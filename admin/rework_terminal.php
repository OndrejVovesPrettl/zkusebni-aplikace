<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireReworkAccess();

$pageTitle    = t('rework_terminal_title');
$activePage   = 'rework_terminal';
$canEdit      = canReworkAccess();
$flashOk      = null;
$flashErr     = null;

$storageReady = ensureReworkTables();
$projects     = getReworkProjects();
$reasons      = getReworkReasons();
$actions      = getReworkActions();

// ── Resolve selected project from session ────────────────────────────────────
$sessionProjectKey = 'rework_selected_project_id';
if (!isset($_SESSION[$sessionProjectKey]) && !empty($projects)) {
    $_SESSION[$sessionProjectKey] = (int)$projects[0]['id'];
}
$selectedProjectId = (int)($_SESSION[$sessionProjectKey] ?? 0);

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storageReady) {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $postAction = (string)post('action');

    if ($postAction === 'select_project') {
        $pid = max(0, (int)post('project_id'));
        foreach ($projects as $p) {
            if ((int)$p['id'] === $pid) {
                $_SESSION[$sessionProjectKey] = $pid;
                $selectedProjectId = $pid;
                break;
            }
        }
        redirect(APP_URL . '/admin/rework_terminal.php');
    }

    if ($postAction === 'save') {
        if (!$canEdit) {
            $flashErr = t('superadmin_only');
        } else {
            $projectId      = max(0, (int)post('project_id'));
            $reasonId       = max(0, (int)post('reason_id'));
            $actionId       = max(0, (int)post('action_id'));
            $dmc            = trim((string)post('dmc'));
            $replacementDmc = trim((string)post('replacement_dmc'));
            $note           = trim((string)post('note'));

            if ($projectId <= 0 || $reasonId <= 0 || $actionId <= 0 || $dmc === '') {
                $flashErr = t('required');
            } else {
                $_SESSION[$sessionProjectKey] = $projectId;
                $selectedProjectId = $projectId;
                try {
                    $entryId = createReworkEntry([
                        'project_id'      => $projectId,
                        'reason_id'       => $reasonId,
                        'action_id'       => $actionId,
                        'dmc'             => $dmc,
                        'replacement_dmc' => $replacementDmc,
                        'note'            => $note,
                        'recorded_at'     => date('Y-m-d H:i:s'),
                    ], (int)($_SESSION['admin_user_id'] ?? 0));

                    logAction('REWORK_ENTRY_CREATE', 'rework_entries', $entryId, [], [
                        'project_id' => $projectId,
                        'reason_id'  => $reasonId,
                        'action_id'  => $actionId,
                        'dmc'        => $dmc,
                    ], t('rework_saved'));

                    redirect(APP_URL . '/admin/rework_terminal.php?ok=1');
                } catch (Throwable $e) {
                    $flashErr = t('rework_save_failed') . ': ' . $e->getMessage();
                }
            }
        }
    }
}

if ((string)get('ok') === '1') {
    $flashOk = t('rework_saved');
}

$recentRows   = $storageReady ? getRecentReworkEntries(20) : [];
$hourlyStats  = $storageReady ? getReworkHourlyStats(9)    : [];
$todayStats   = $storageReady ? getReworkTodayStats()      : ['total' => 0, 'by_action' => []];

$currentUsername = (string)db_val(
    'SELECT COALESCE(NULLIF(skutecneJmeno, ""), username) FROM users WHERE id = ? LIMIT 1',
    [(int)($_SESSION['admin_user_id'] ?? 0)]
);
if ($currentUsername === '') {
    $currentUsername = (string)($_SESSION['admin_user'] ?? '');
}

// Find selected project label
$selectedProjectLabel = '';
foreach ($projects as $p) {
    if ((int)$p['id'] === $selectedProjectId) {
        $selectedProjectLabel = (string)$p['code'];
        break;
    }
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-wrench me-2"></i><?= t('rework_terminal_title') ?></span>
        <?php if ($selectedProjectLabel !== ''): ?>
        <span class="topbar-meta"><i class="bi bi-box-seam me-1"></i><?= h($selectedProjectLabel) ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="rework_report.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-clipboard-data me-1"></i><?= t('nav_rework_report') ?></a>
        <a href="rework_kiosk_login.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem" target="_blank" rel="noopener noreferrer"><i class="bi bi-fullscreen me-1"></i><?= t('nav_rework_kiosk') ?><i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
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

    <?php if (!$storageReady): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.8rem 1rem;">Rework tabulky se nepodařilo inicializovat.</div>
    <?php else: ?>

    <div class="row g-3 mb-3">
        <!-- ── Left: Entry form ── -->
        <div class="col-12 col-xl-7">
            <div class="card-dvd h-100">
                <div class="card-header-dvd">
                    <span class="s-num"><i class="bi bi-wrench-adjustable"></i></span>
                    <span class="s-title"><?= t('rework_terminal_title') ?></span>
                </div>
                <div class="card-body-dvd">

                    <!-- Project selector -->
                    <form method="post" class="mb-4 no-print">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="select_project">
                        <label class="form-label-dvd"><?= t('rework_project') ?></label>
                        <div class="d-flex gap-2 flex-wrap">
                            <select name="project_id" class="form-select" style="max-width:260px" onchange="this.form.requestSubmit()">
                                <?php foreach ($projects as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $selectedProjectId ? 'selected' : '' ?>>
                                    <?= h((string)$p['code']) ?><?= !empty($p['label']) ? ' – ' . h((string)$p['label']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-check2 me-1"></i><?= t('select') ?></button>
                        </div>
                    </form>

                    <div class="mb-4" style="font-size:.9rem;color:var(--muted)">
                        <strong style="color:var(--text)"><?= t('user') ?>:</strong> <?= h($currentUsername) ?>
                    </div>

                    <!-- Stats row -->
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--accent)"><?= (int)$todayStats['total'] ?></div><div class="stat-label"><?= t('rework_total') ?></div></div>
                        </div>
                        <?php foreach ($todayStats['by_action'] as $ba): ?>
                        <div class="col-6 col-lg-3">
                            <div class="stat-card"><div class="stat-val" style="color:var(--accent3)"><?= (int)$ba['cnt'] ?></div><div class="stat-label"><?= h((string)$ba['label']) ?></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$canEdit): ?>
                    <div style="background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.3);color:var(--warn);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem">
                        <?= t('superadmin_only') ?>
                    </div>
                    <?php endif; ?>

                    <!-- Entry form -->
                    <form method="post" class="no-print">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="save">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_project') ?> <span class="req">*</span></label>
                                <select name="project_id" class="form-select" required <?= $canEdit ? '' : 'disabled' ?>>
                                    <?php foreach ($projects as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $selectedProjectId ? 'selected' : '' ?>>
                                        <?= h((string)$p['code']) ?><?= !empty($p['label']) ? ' – ' . h((string)$p['label']) : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_dmc') ?> <span class="req">*</span></label>
                                <input type="text" name="dmc" id="dmcInput" class="form-control" required autocomplete="off"
                                    placeholder="naskenujte DMC" autofocus <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_reason') ?> <span class="req">*</span></label>
                                <select name="reason_id" class="form-select" required <?= $canEdit ? '' : 'disabled' ?>>
                                    <option value=""><?= t('select') ?>...</option>
                                    <?php foreach ($reasons as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>"><?= h((string)$r['code'] . ' – ' . (string)$r['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_action') ?> <span class="req">*</span></label>
                                <select name="action_id" class="form-select" required <?= $canEdit ? '' : 'disabled' ?>>
                                    <option value=""><?= t('select') ?>...</option>
                                    <?php foreach ($actions as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>"><?= h((string)$a['code'] . ' – ' . (string)$a['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_new_qr') ?></label>
                                <input type="text" name="replacement_dmc" class="form-control" autocomplete="off"
                                    placeholder="nové DMC (volitelné)" <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label-dvd"><?= t('rework_note') ?></label>
                                <input type="text" name="note" class="form-control" autocomplete="off"
                                    placeholder="poznámka (volitelná)" <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn-dvd" <?= $canEdit ? '' : 'disabled' ?>>
                                    <i class="bi bi-check2-circle me-1"></i><?= t('save') ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Right: Hourly stats ── -->
        <div class="col-12 col-xl-5">
            <div class="card-dvd h-100">
                <div class="card-header-dvd">
                    <span class="s-num"><i class="bi bi-clock-history"></i></span>
                    <span class="s-title"><?= t('rework_hourly_title') ?></span>
                </div>
                <div class="card-body-dvd" style="padding:0">
                    <div style="overflow-x:auto">
                        <table class="table-dvd" style="width:100%;font-size:.78rem">
                            <thead><tr><th><?= t('hour') ?></th><th style="text-align:right"><?= t('pocet') ?></th></tr></thead>
                            <tbody>
                            <?php if (!$hourlyStats): ?>
                                <tr><td colspan="2" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                            <?php else: foreach ($hourlyStats as $row): ?>
                                <tr>
                                    <td><?= h((string)$row['label']) ?></td>
                                    <td style="text-align:right"><?= (int)$row['count'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Recent entries table ── -->
    <div class="card-dvd">
        <div class="card-header-dvd">
            <span class="s-num"><i class="bi bi-list-ul"></i></span>
            <span class="s-title"><?= t('rework_recent') ?></span>
        </div>
        <div class="card-body-dvd" style="padding:0">
            <div style="overflow-x:auto">
                <table class="table-dvd" style="width:100%;font-size:.78rem">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= t('rework_project') ?></th>
                            <th><?= t('rework_dmc') ?></th>
                            <th><?= t('rework_reason') ?></th>
                            <th><?= t('rework_action') ?></th>
                            <th><?= t('rework_new_qr') ?></th>
                            <th><?= t('rework_recorded_at') ?></th>
                            <th><?= t('rework_recorded_by') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentRows): ?>
                        <tr><td colspan="8" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                    <?php else: foreach ($recentRows as $row): ?>
                        <tr>
                            <td class="mono"><?= (int)$row['id'] ?></td>
                            <td><?= h((string)$row['project_code']) ?></td>
                            <td class="mono"><?= h((string)$row['dmc']) ?></td>
                            <td><?= h((string)$row['reason_code'] . ' – ' . (string)$row['reason_label']) ?></td>
                            <td><?= h((string)$row['action_code'] . ' – ' . (string)$row['action_label']) ?></td>
                            <td class="mono"><?= h((string)$row['replacement_dmc']) ?></td>
                            <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$row['recorded_at']))) ?></td>
                            <td><?= h((string)$row['recorded_by']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Auto-focus DMC input after page load / form render
document.addEventListener('DOMContentLoaded', () => {
    const dmc = document.getElementById('dmcInput');
    if (dmc) {
        dmc.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const form = dmc.closest('form');
                const reasonSel = form ? form.querySelector('select[name="reason_id"]') : null;
                if (reasonSel && reasonSel.value === '') {
                    reasonSel.focus();
                } else {
                    const btn = form ? form.querySelector('button[type="submit"]') : null;
                    if (btn) btn.click();
                }
            }
        });
    }
});
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
