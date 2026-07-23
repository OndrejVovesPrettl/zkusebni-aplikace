<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'traceability', 'writer']);

$pageTitle = t('traceability_records_title');
$activePage = 'traceability_records';
$allTables = ['gen3', 'gen3manual', '48w', '48w200', '48w200_2', 'gen3cina'];
$canEdit = canTraceabilityEdit();

$fOd = get('od', date('Y-m-01'));
$fDo = get('do', date('Y-m-d'));
$fTable = (string)get('src_table', '');
$fSerial = trim((string)get('serial', ''));
$fUserId = max(0, (int)get('user_id', 0));
$fLimit = min(1000, max(50, (int)get('limit', 300)));

if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
if (!in_array($fTable, $allTables, true)) $fTable = '';

$flashOk = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $action = (string)post('action');

    if ($action === 'edit_row') {
        if (!$canEdit) {
            http_response_code(403);
            die('403');
        }

        $srcTable = (string)post('src_table');
        $id = max(0, (int)post('id'));
        $snDil = trim((string)post('snDil'));
        $userId = max(0, (int)post('user_id'));
        $tsRaw = trim((string)post('timestamp'));
        $tsRaw = str_replace('T', ' ', $tsRaw);

        $tsOk = DateTime::createFromFormat('Y-m-d H:i:s', $tsRaw) !== false;
        if (!in_array($srcTable, $allTables, true) || $id <= 0 || $snDil === '' || !$tsOk || $userId <= 0) {
            $flashErr = t('traceability_edit_failed');
        } else {
            try {
                $sql = 'UPDATE `' . $srcTable . '` SET snDil=?, `timestamp`=?, userId=? WHERE id=?';
                $stmt = trace_db()->prepare($sql);
                $stmt->execute([$snDil, $tsRaw, $userId, $id]);

                logAction('TRACEABILITY_ROW_EDIT', $srcTable, $id, [], ['snDil' => $snDil, 'timestamp' => $tsRaw, 'userId' => $userId], t('traceability_row_updated'));
                $flashOk = t('traceability_row_updated');
            } catch (Throwable $e) {
                $flashErr = t('traceability_edit_failed') . ': ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_row') {
        if (!$canEdit) {
            http_response_code(403);
            die('403');
        }

        $srcTable = (string)post('src_table');
        $id = max(0, (int)post('id'));
        if (!in_array($srcTable, $allTables, true) || $id <= 0) {
            $flashErr = t('traceability_delete_failed');
        } else {
            try {
                deleteTraceabilityRow($srcTable, $id);
                logAction('TRACEABILITY_ROW_DELETE', $srcTable, $id, [], ['id' => $id], t('traceability_row_deleted'));
                $flashOk = t('traceability_row_deleted');
            } catch (Throwable $e) {
                $flashErr = t('traceability_delete_failed') . ': ' . $e->getMessage();
            }
        }
    }
}

$selectedTables = $fTable !== '' ? [$fTable] : $allTables;
$paramsOne = [$fOd . ' 00:00:00', $fDo . ' 23:59:59'];
$where = ['`timestamp` BETWEEN ? AND ?'];
if ($fSerial !== '') {
    $where[] = 'snDil LIKE ?';
    $paramsOne[] = '%' . $fSerial . '%';
}
if ($fUserId > 0) {
    $where[] = 'userId = ?';
    $paramsOne[] = $fUserId;
}

$unionParts = [];
$unionParams = [];
foreach ($selectedTables as $table) {
    $unionParts[] = "SELECT '" . $table . "' AS src_table, id, snDil, `timestamp`, userId FROM `" . $table . "` WHERE " . implode(' AND ', $where);
    $unionParams = array_merge($unionParams, $paramsOne);
}
$unionSql = implode(' UNION ALL ', $unionParts);

$rows = trace_db_all(
    "SELECT u.src_table, u.id, u.snDil, u.`timestamp`, u.userId
     FROM ($unionSql) u
     ORDER BY u.`timestamp` DESC, u.id DESC
     LIMIT " . $fLimit,
    $unionParams
);
$rows = applyTraceabilityUsernames($rows);

$userOptions = getUnifiedTraceabilityUserOptions();

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-table me-2"></i><?= t('traceability_records_title') ?></span>
        <span class="topbar-meta"><i class="bi bi-shield-check me-1"></i><?= $canEdit ? t('traceability_mode_edit') : t('traceability_mode_readonly') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if (hasRole('traceability')): ?>
        <a href="users.php?scope=traceability" class="btn-dvd btn-dvd-sm"><i class="bi bi-people me-1"></i><?= t('nav_writer_users') ?></a>
        <?php endif; ?>
        <a href="traceability.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-graph-up me-1"></i><?= t('traceability_title') ?></a>
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

    <form method="get" class="filter-bar no-print">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('traceability_table') ?></label>
                <select name="src_table" class="form-select">
                    <option value=""><?= t('all') ?></option>
                    <?php foreach ($allTables as $table): ?>
                    <option value="<?= h($table) ?>" <?= $fTable === $table ? 'selected' : '' ?>><?= h(traceabilityProjectLabel($table)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:2 1 160px;min-width:130px"><label class="form-label-dvd"><?= t('traceability_serial') ?></label><input type="text" name="serial" class="form-control" value="<?= h($fSerial) ?>" placeholder="SN..."></div>
            <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('user') ?></label>
                <select name="user_id" class="form-select">
                    <option value="0"><?= t('all') ?></option>
                    <?php foreach ($userOptions as $user): ?>
                    <option value="<?= (int)$user['id'] ?>" <?= $fUserId === (int)$user['id'] ? 'selected' : '' ?>><?= h($user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:0 1 100px;min-width:90px"><label class="form-label-dvd"><?= t('rows') ?></label><input type="number" name="limit" min="50" max="1000" step="50" class="form-control" value="<?= (int)$fLimit ?>"></div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
                <a href="traceability_records.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
            </div>
        </div>
    </form>

    <div class="tbl-wrap">
        <div class="overflow-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= t('traceability_table') ?></th>
                        <th>ID</th>
                        <th><?= t('traceability_serial') ?></th>
                        <th><?= t('user') ?></th>
                        <th><?= t('datetime') ?></th>
                        <?php if ($canEdit): ?><th style="width:110px"><?= t('action') ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr><td colspan="<?= $canEdit ? '6' : '5' ?>" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                    <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><span class="badge" style="background:rgba(255,255,255,.06);color:var(--text)"><?= h((string)$row['src_table']) ?></span></td>
                        <td class="mono"><?= (int)$row['id'] ?></td>
                        <td class="mono"><?= h((string)$row['snDil']) ?></td>
                        <td><?= h((string)$row['username']) ?></td>
                        <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$row['timestamp']))) ?></td>
                        <?php if ($canEdit): ?>
                        <td>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--accent);padding:.2rem .45rem;border-radius:4px" onclick='openTraceEdit(<?= h(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" onsubmit="return confirm('<?= h(t('confirm_delete')) ?>');">
                                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_row">
                                    <input type="hidden" name="src_table" value="<?= h((string)$row['src_table']) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--danger);padding:.2rem .45rem;border-radius:4px">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="tbl-footer"><span><?= count($rows) ?> <?= t('rows') ?></span></div>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="traceEditModal" tabindex="-1" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="edit_row">
                <input type="hidden" name="src_table" id="edit_src_table">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.05);">
                    <h5 class="modal-title"><?= t('traceability_edit_row') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('traceability_serial') ?></label>
                        <input type="text" name="snDil" id="edit_snDil" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('user') ?></label>
                        <select name="user_id" id="edit_user_id" class="form-select" required>
                            <?php foreach ($userOptions as $user): ?>
                            <option value="<?= (int)$user['id'] ?>"><?= h($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('datetime') ?></label>
                        <input type="datetime-local" name="timestamp" id="edit_timestamp" class="form-control" step="1" required>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.05);">
                    <button type="button" class="btn" style="background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text);padding:.4rem .8rem;border-radius:6px" data-bs-dismiss="modal"><?= t('cancel') ?></button>
                    <button type="submit" class="btn-dvd"><?= t('save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function toDateTimeLocal(v) {
    const d = new Date(v.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const h = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    const s = String(d.getSeconds()).padStart(2, '0');
    return `${y}-${m}-${day}T${h}:${min}:${s}`;
}

function openTraceEdit(row) {
    document.getElementById('edit_src_table').value = row.src_table;
    document.getElementById('edit_id').value = row.id;
    document.getElementById('edit_snDil').value = row.snDil;
    document.getElementById('edit_user_id').value = row.userId;
    document.getElementById('edit_timestamp').value = toDateTimeLocal(row.timestamp);

    const modal = new bootstrap.Modal(document.getElementById('traceEditModal'));
    modal.show();
}
</script>
<?php endif; ?>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
