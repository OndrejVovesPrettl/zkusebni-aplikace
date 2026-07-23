<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireReworkAccess();

$id = (int)get('id');
if (!$id) redirect(APP_URL . '/admin/rework_report.php');

$row = db_row(
    'SELECT re.id, re.project_id, re.reason_id, re.action_id, re.dmc, re.replacement_dmc, re.note, re.recorded_at, re.recorded_by_user_id, re.recorded_by_name,
            rp.code AS project_code, rr.code AS reason_code, rr.label AS reason_label, ra.code AS action_code, ra.label AS action_label,
            COALESCE(NULLIF(re.recorded_by_name, ""), NULLIF(u.skutecneJmeno, ""), u.username, "-") AS recorded_by
     FROM rework_entries re
     INNER JOIN rework_projects rp ON rp.id = re.project_id
     INNER JOIN rework_reasons rr ON rr.id = re.reason_id
     INNER JOIN rework_actions ra ON ra.id = re.action_id
     LEFT JOIN users u ON u.id = re.recorded_by_user_id
     WHERE re.id = ?',
    [$id]
);

if (!$row) { http_response_code(404); die(t('record_not_found')); }

$pageTitle = t('rework_record_detail') . ' #' . $id;
$activePage = 'rework_report';
require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="rework_report.php" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><i class="bi bi-file-earmark me-2"></i><?= t('rework_record_detail') ?> <span class="mono text-accent">#<?= $id ?></span></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <?php if (!hasOnlyRole('read')): ?>
        <a href="rework_entry_edit.php?id=<?= $id ?>" class="btn-dvd btn-dvd-sm"><i class="bi bi-pencil me-1"></i><?= t('edit') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="content">
    <div class="tbl-wrap" style="margin-bottom:1rem">
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
                    <td><?= h((string)$row['reason_code'] . ' - ' . (string)$row['reason_label']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_action') ?></td>
                    <td><?= h((string)$row['action_code'] . ' - ' . (string)$row['action_label']) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_new_qr') ?></td>
                    <td class="mono"><?= h((string)($row['replacement_dmc'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_note') ?></td>
                    <td><?= h((string)($row['note'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600"><?= t('rework_recorded_by') ?></td>
                    <td><?= h((string)$row['recorded_by']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
