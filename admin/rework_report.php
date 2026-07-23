<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireReworkAccess();

$pageTitle = t('rework_report_title');
$activePage = 'rework_report';

$storageReady = ensureReworkTables();
$projects = getReworkProjects();
$reasons = getReworkReasons();
$actions = getReworkActions();

$fOd = get('od', date('Y-m-01'));
$fDo = get('do', date('Y-m-d'));
$fProjectId = max(0, (int)get('project_id', 0));
$fReasonId = max(0, (int)get('reason_id', 0));
$fActionId = max(0, (int)get('action_id', 0));
$fDmc = trim((string)get('dmc', ''));

if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');

$params = [$fOd . ' 00:00:00', $fDo . ' 23:59:59'];
$where = ['re.recorded_at BETWEEN ? AND ?'];
if ($fProjectId > 0) {
    $where[] = 're.project_id = ?';
    $params[] = $fProjectId;
}
if ($fReasonId > 0) {
    $where[] = 're.reason_id = ?';
    $params[] = $fReasonId;
}
if ($fActionId > 0) {
    $where[] = 're.action_id = ?';
    $params[] = $fActionId;
}
if ($fDmc !== '') {
    $where[] = '(re.dmc LIKE ? OR re.replacement_dmc LIKE ?)';
    $params[] = '%' . $fDmc . '%';
    $params[] = '%' . $fDmc . '%';
}

$whereSql = implode(' AND ', $where);

$summary = [
    'total' => 0,
    'repair_count' => 0,
    'scrap_count' => 0,
    'ok_count' => 0,
];
$byProject = [];
$byReason = [];
$byAction = [];
$rows = [];

if ($storageReady) {
    $summary = db_row(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN ra.code = "1" THEN 1 ELSE 0 END) AS repair_count,
                SUM(CASE WHEN ra.code = "2" THEN 1 ELSE 0 END) AS scrap_count,
                SUM(CASE WHEN ra.code = "3" THEN 1 ELSE 0 END) AS ok_count
         FROM rework_entries re
         INNER JOIN rework_actions ra ON ra.id = re.action_id
         WHERE ' . $whereSql,
        $params
    ) ?: $summary;

    $byProject = db_all(
        'SELECT rp.code, COUNT(*) AS cnt
         FROM rework_entries re
         INNER JOIN rework_projects rp ON rp.id = re.project_id
         WHERE ' . $whereSql . '
         GROUP BY rp.id, rp.code
         ORDER BY cnt DESC, rp.code',
        $params
    );

    $byReason = db_all(
        'SELECT rr.code, rr.label, COUNT(re.id) AS cnt
         FROM rework_reasons rr
         LEFT JOIN rework_entries re ON re.reason_id = rr.id AND ' . $whereSql . '
         GROUP BY rr.id, rr.code, rr.label
         ORDER BY CAST(rr.code AS UNSIGNED), rr.code',
        $params
    );

    $byAction = db_all(
        'SELECT ra.code, ra.label, COUNT(*) AS cnt
         FROM rework_entries re
         INNER JOIN rework_actions ra ON ra.id = re.action_id
         WHERE ' . $whereSql . '
         GROUP BY ra.id, ra.code, ra.label
         ORDER BY cnt DESC, ra.sort_order, ra.code',
        $params
    );

    $rows = db_all(
        'SELECT re.id,
                rp.code AS project_code,
                rr.code AS reason_code,
                rr.label AS reason_label,
                ra.code AS action_code,
                ra.label AS action_label,
                re.dmc,
                re.replacement_dmc,
                re.note,
                re.recorded_at,
                COALESCE(NULLIF(re.recorded_by_name, ""), NULLIF(u.skutecneJmeno, ""), u.username, "-") AS recorded_by
         FROM rework_entries re
         INNER JOIN rework_projects rp ON rp.id = re.project_id
         INNER JOIN rework_reasons rr ON rr.id = re.reason_id
         INNER JOIN rework_actions ra ON ra.id = re.action_id
         LEFT JOIN users u ON u.id = re.recorded_by_user_id
         WHERE ' . $whereSql . '
         ORDER BY re.recorded_at DESC, re.id DESC
         LIMIT 300',
        $params
    );
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-clipboard-data me-2"></i><?= t('rework_report_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="rework_kiosk.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-grid-3x3-gap me-1"></i><?= t('nav_rework_kiosk') ?></a>
    </div>
</div>

<div class="content">
    <form method="get" class="filter-bar no-print">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('rework_project') ?></label>
                <select name="project_id" class="form-select">
                    <option value="0"><?= t('all') ?></option>
                    <?php foreach ($projects as $project): ?>
                    <option value="<?= (int)$project['id'] ?>" <?= $fProjectId === (int)$project['id'] ? 'selected' : '' ?>><?= h((string)$project['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:2 1 180px;min-width:160px"><label class="form-label-dvd"><?= t('rework_dmc') ?></label><input type="text" name="dmc" class="form-control" value="<?= h($fDmc) ?>" placeholder="DMC / nové QR"></div>
            <div style="flex:1 1 180px;min-width:160px"><label class="form-label-dvd"><?= t('rework_reason') ?></label>
                <select name="reason_id" class="form-select">
                    <option value="0"><?= t('all') ?></option>
                    <?php foreach ($reasons as $reason): ?>
                    <option value="<?= (int)$reason['id'] ?>" <?= $fReasonId === (int)$reason['id'] ? 'selected' : '' ?>><?= h((string)$reason['code'] . ' - ' . (string)$reason['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1 1 150px;min-width:130px"><label class="form-label-dvd"><?= t('rework_action') ?></label>
                <select name="action_id" class="form-select">
                    <option value="0"><?= t('all') ?></option>
                    <?php foreach ($actions as $action): ?>
                    <option value="<?= (int)$action['id'] ?>" <?= $fActionId === (int)$action['id'] ? 'selected' : '' ?>><?= h((string)$action['code'] . ' - ' . (string)$action['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
                <a href="rework_report.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
            </div>
        </div>
    </form>

    <?php if (!$storageReady): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;">Rework tabulky se nepodařilo inicializovat.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
        <div class="card-dvd" style="padding:1rem;"><div style="font-size:1.8rem;font-weight:700"><?= (int)($summary['total'] ?? 0) ?></div><div style="color:var(--muted);font-size:.85rem"><?= t('rework_total') ?></div></div>
        <div class="card-dvd" style="padding:1rem;"><div style="font-size:1.8rem;font-weight:700;color:var(--accent)"><?= (int)($summary['repair_count'] ?? 0) ?></div><div style="color:var(--muted);font-size:.85rem"><?= t('rework_repair_count') ?></div></div>
        <div class="card-dvd" style="padding:1rem;"><div style="font-size:1.8rem;font-weight:700;color:var(--danger)"><?= (int)($summary['scrap_count'] ?? 0) ?></div><div style="color:var(--muted);font-size:.85rem"><?= t('rework_scrap_count') ?></div></div>
        <div class="card-dvd" style="padding:1rem;"><div style="font-size:1.8rem;font-weight:700;color:var(--success)"><?= (int)($summary['ok_count'] ?? 0) ?></div><div style="color:var(--muted);font-size:.85rem"><?= t('rework_ok_count') ?></div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;grid-template-rows:auto auto;gap:1rem;margin-bottom:1rem;align-items:start;">
        <div class="card-dvd" style="padding:1rem;grid-column:1;grid-row:1/3;">
            <div style="font-size:1rem;font-weight:600;margin-bottom:.75rem;"><?= t('rework_reason_breakdown') ?></div>
            <table class="tbl"><tbody><?php foreach ($byReason as $row): ?><tr><td><?= h((string)$row['code'] . ' - ' . (string)$row['label']) ?></td><td style="text-align:right" class="mono"><?= (int)$row['cnt'] ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
        <div class="card-dvd" style="padding:1rem;grid-column:2;grid-row:1;">
            <div style="font-size:1rem;font-weight:600;margin-bottom:.75rem;"><?= t('rework_project_breakdown') ?></div>
            <table class="tbl"><tbody><?php foreach ($byProject as $row): ?><tr><td><?= h((string)$row['code']) ?></td><td style="text-align:right" class="mono"><?= (int)$row['cnt'] ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
        <div class="card-dvd" style="padding:1rem;grid-column:2;grid-row:2;">
            <div style="font-size:1rem;font-weight:600;margin-bottom:.75rem;"><?= t('rework_action_breakdown') ?></div>
            <table class="tbl"><tbody><?php foreach ($byAction as $row): ?><tr><td><?= h((string)$row['code'] . ' - ' . (string)$row['label']) ?></td><td style="text-align:right" class="mono"><?= (int)$row['cnt'] ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
    </div>

    <div class="tbl-wrap">
        <div class="overflow-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?= t('rework_recorded_at') ?></th>
                        <th><?= t('rework_project') ?></th>
                        <th><?= t('rework_dmc') ?></th>
                        <th><?= t('rework_reason') ?></th>
                        <th><?= t('rework_action') ?></th>
                        <th><?= t('rework_new_qr') ?></th>
                        <th><?= t('rework_note') ?></th>
                        <th><?= t('rework_recorded_by') ?></th>
                        <th class="no-print"><?= t('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="10" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono"><?= (int)$row['id'] ?></td>
                        <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$row['recorded_at']))) ?></td>
                        <td><?= h((string)$row['project_code']) ?></td>
                        <td class="mono"><?= h((string)$row['dmc']) ?></td>
                        <td><?= h((string)$row['reason_code'] . ' - ' . (string)$row['reason_label']) ?></td>
                        <td><?= h((string)$row['action_code'] . ' - ' . (string)$row['action_label']) ?></td>
                        <td class="mono"><?= h((string)($row['replacement_dmc'] ?? '')) ?></td>
                        <td><?= h((string)($row['note'] ?? '')) ?></td>
                        <td><?= h((string)$row['recorded_by']) ?></td>
                        <td class="no-print">
                            <a href="<?= APP_URL ?>/admin/rework_entry_detail.php?id=<?= $row['id'] ?>" class="btn-icon" title="<?= t('detail') ?>"><i class="bi bi-eye"></i></a>
                            <?php if (!hasOnlyRole('read')): ?>
                            <a href="<?= APP_URL ?>/admin/rework_entry_edit.php?id=<?= $row['id'] ?>" class="btn-icon" title="<?= t('edit') ?>"><i class="bi bi-pencil"></i></a>
                            <a href="<?= APP_URL ?>/admin/rework_entry_delete.php?id=<?= $row['id'] ?>" class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>" title="<?= t('delete') ?>"><i class="bi bi-trash3"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="tbl-footer"><span><?= count($rows) ?> <?= t('rows') ?></span></div>
    </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
