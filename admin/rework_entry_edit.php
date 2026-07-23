<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireReworkAccess();

if (hasOnlyRole('read')) {
    http_response_code(403);
    die('<pre style="color:red">403 Forbidden – ' . t('access_denied') . '</pre>');
}

$id = (int)get('id');
if (!$id) redirect(APP_URL . '/admin/rework_report.php');

$row = db_row(
    'SELECT * FROM rework_entries WHERE id = ?',
    [$id]
);

if (!$row) { http_response_code(404); die(t('record_not_found')); }

$storageReady = ensureReworkTables();
$projects = getReworkProjects();
$reasons = getReworkReasons();
$actions = getReworkActions();

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $projectId = max(0, (int)post('project_id'));
    $reasonId = max(0, (int)post('reason_id'));
    $actionId = max(0, (int)post('action_id'));
    $dmc = trim((string)post('dmc'));
    $replacementDmc = trim((string)post('replacement_dmc'));
    $note = trim((string)post('note'));

    if ($projectId <= 0 || $reasonId <= 0 || $actionId <= 0 || $dmc === '') {
        $flashErr = t('required');
    } else {
        try {
            db_exec(
                'UPDATE rework_entries SET project_id=?, reason_id=?, action_id=?, dmc=?, replacement_dmc=?, note=? WHERE id=?',
                [$projectId, $reasonId, $actionId, $dmc, $replacementDmc, $note, $id]
            );

            logAction('REWORK_ENTRY_EDIT', 'rework_entries', $id, [], [
                'project_id' => $projectId,
                'reason_id' => $reasonId,
                'action_id' => $actionId,
                'dmc' => $dmc,
            ], t('saved'));

            redirect(APP_URL . '/admin/rework_entry_detail.php?id=' . $id . '&saved=1');
        } catch (Throwable $e) {
            $flashErr = t('rework_save_failed') . ': ' . $e->getMessage();
        }
    }

    // Refresh data from DB
    $row = db_row('SELECT * FROM rework_entries WHERE id = ?', [$id]);
}

if ((string)get('saved') === '1') {
    $flashOk = t('saved');
}

$pageTitle = t('edit') . ' – ' . t('rework_record_detail') . ' #' . $id;
$activePage = 'rework_report';
require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="rework_entry_detail.php?id=<?= $id ?>" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><i class="bi bi-pencil me-2"></i><?= t('edit') ?> – <?= t('rework_record_detail') ?> <span class="mono text-accent">#<?= $id ?></span></span>
    </div>
</div>

<div class="content">
    <?php if ($flashOk): ?>
    <div style="background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem"><?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <form method="post" class="form-vertical" style="max-width:600px">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_project') ?> <span class="text-danger">*</span></label>
            <select name="project_id" class="form-select" required>
                <option value="">— <?= t('select') ?> —</option>
                <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$row['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h((string)$p['code']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_dmc') ?> <span class="text-danger">*</span></label>
            <input type="text" name="dmc" class="form-control" value="<?= h((string)$row['dmc']) ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_reason') ?> <span class="text-danger">*</span></label>
            <select name="reason_id" class="form-select" required>
                <option value="">— <?= t('select') ?> —</option>
                <?php foreach ($reasons as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (int)$row['reason_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                    <?= h((string)$r['code'] . ' - ' . (string)$r['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_action') ?> <span class="text-danger">*</span></label>
            <select name="action_id" class="form-select" required>
                <option value="">— <?= t('select') ?> —</option>
                <?php foreach ($actions as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)$row['action_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                    <?= h((string)$a['code'] . ' - ' . (string)$a['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_new_qr') ?></label>
            <input type="text" name="replacement_dmc" class="form-control" value="<?= h((string)($row['replacement_dmc'] ?? '')) ?>">
        </div>

        <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label-dvd"><?= t('rework_note') ?></label>
            <textarea name="note" class="form-control" rows="3" style="resize:vertical"><?= h((string)($row['note'] ?? '')) ?></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn-dvd"><i class="bi bi-check me-1"></i><?= t('save') ?></button>
            <a href="rework_entry_detail.php?id=<?= $id ?>" class="btn-outline"><?= t('cancel') ?></a>
        </div>
    </form>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
