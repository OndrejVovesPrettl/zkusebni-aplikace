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
    'SELECT re.id, re.dmc, re.recorded_at, rp.code AS project_code, rr.code AS reason_code, ra.code AS action_code
     FROM rework_entries re
     INNER JOIN rework_projects rp ON rp.id = re.project_id
     INNER JOIN rework_reasons rr ON rr.id = re.reason_id
     INNER JOIN rework_actions ra ON ra.id = re.action_id
     WHERE re.id = ?',
    [$id]
);

if (!$row) { http_response_code(404); die(t('record_not_found')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    try {
        logAction('REWORK_ENTRY_DELETE', 'rework_entries', $id, [
            'project_code' => $row['project_code'],
            'reason_code' => $row['reason_code'],
            'action_code' => $row['action_code'],
            'dmc' => $row['dmc'],
        ], [], t('deleted'));

        db_exec('DELETE FROM rework_entries WHERE id = ?', [$id]);
        redirect(APP_URL . '/admin/rework_report.php?deleted=1');
    } catch (Throwable $e) {
        http_response_code(500);
        die('<pre style="color:red">' . h($e->getMessage()) . '</pre>');
    }
}

$pageTitle = t('delete') . ' – ' . t('rework_record_detail') . ' #' . $id;
$activePage = 'rework_report';
require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="rework_entry_detail.php?id=<?= $id ?>" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><i class="bi bi-trash3 me-2"></i><?= t('delete') ?> – <?= t('rework_record_detail') ?> <span class="mono text-accent">#<?= $id ?></span></span>
    </div>
</div>

<div class="content">
    <div style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:1rem;margin-bottom:1.5rem">
        <strong><?= t('are_you_sure') ?></strong>
        <p style="margin:0.5rem 0 0 0;font-size:.95rem"><?= t('this_action_cannot_be_undone') ?></p>
    </div>

    <div class="tbl-wrap" style="margin-bottom:1.5rem">
        <table class="tbl" style="width:100%">
            <tbody>
                <tr>
                    <td style="width:20%;font-weight:600"><?= t('id') ?></td>
                    <td class="mono"><?= (int)$row['id'] ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_recorded_at') ?></td>
                    <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$row['recorded_at']))) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_project') ?></td>
                    <td><?= h((string)$row['project_code']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_dmc') ?></td>
                    <td class="mono"><?= h((string)$row['dmc']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_reason') ?></td>
                    <td><?= h((string)$row['reason_code']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_action') ?></td>
                    <td><?= h((string)$row['action_code']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <form method="post" class="d-flex gap-2">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <button type="submit" class="btn-danger-dvd"><i class="bi bi-trash3 me-1"></i><?= t('delete') ?></button>
        <a href="rework_entry_detail.php?id=<?= $id ?>" class="btn-outline"><?= t('cancel') ?></a>
    </form>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
