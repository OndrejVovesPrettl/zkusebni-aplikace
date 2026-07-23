<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/serizovac_checklist.php';
requireSerizovacEdit();

$pageTitle = t('serizovac_catalog_title');
$activePage = 'serizovac_checklist_catalog';
$flashOk = '';
$flashErr = '';
$openAddModal = false;

if (!serizovacEnsureChecklistTables()) {
    $flashErr = t('serizovac_tables_error');
}

$seedPath = APP_ROOT . '/uploads/checklist.xlsx';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $action = (string)post('action');
    if ($action === 'sync_xlsx') {
        $res = serizovacSyncChecklistCatalogFromXlsx($seedPath);
        if ($res['ok']) {
            $flashOk = t('serizovac_sync_done') . ' +' . (int)$res['inserted'] . ' / ~' . (int)$res['updated'];
        } else {
            $flashErr = t('serizovac_sync_error');
        }
    }

    if ($action === 'save_item') {
        $id = (int)post('id');
        if ($id > 0) {
            db()->prepare(
                'UPDATE serizovac_checklist_catalog
                 SET afo_code = ?, task_text = ?, value_hint = ?, requires_value = ?, sort_order = ?, active = ?
                 WHERE id = ?'
            )->execute([
                trim((string)post('afo_code')),
                trim((string)post('task_text')),
                trim((string)post('value_hint')),
                (int)post('requires_value') === 1 ? 1 : 0,
                (int)post('sort_order'),
                (int)post('active') === 1 ? 1 : 0,
                $id,
            ]);
            $flashOk = t('save') . ' OK';
        }
    }

    if ($action === 'add_item') {
        $openAddModal = true;
        $projectKey = trim((string)post('project_key'));
        $projectLabel = trim((string)db_val(
            'SELECT project_label FROM serizovac_checklist_projects WHERE project_key = ? LIMIT 1',
            [$projectKey]
        ));
        if ($projectLabel === '') {
            $projectLabel = trim((string)db_val(
                'SELECT MAX(project_label) FROM serizovac_checklist_catalog WHERE project_key = ?',
                [$projectKey]
            ));
        }

        $taskText = trim((string)post('task_text'));
        if ($projectKey === '' || $projectLabel === '' || $taskText === '') {
            $flashErr = t('error_required_fields');
        } else {
            $afoCode = trim((string)post('afo_code'));
            $valueHint = trim((string)post('value_hint'));
            $sortOrder = (int)post('sort_order');
            $requiresValue = (int)post('requires_value') === 1 ? 1 : 0;
            if (serizovacValueHintMeansNoInput($valueHint)) {
                $requiresValue = 0;
            }

            db()->prepare(
                'INSERT INTO serizovac_checklist_catalog
                 (item_key, sheet_name, project_key, project_label, afo_code, task_text, value_hint, requires_value, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                sha1($projectKey . '|' . $afoCode . '|' . $taskText . '|' . uniqid('', true)),
                $projectLabel,
                $projectKey,
                $projectLabel,
                $afoCode,
                $taskText,
                $valueHint,
                $requiresValue,
                $sortOrder,
            ]);
            $flashOk = t('add') . ' OK';
            $openAddModal = false;
        }
    }
}

$fProject = trim((string)get('project', ''));
$params = [];
$where = [];
if ($fProject !== '') {
    $where[] = 'project_key = ?';
    $params[] = $fProject;
}

$sql = 'SELECT id, project_key, project_label, sheet_name, afo_code, task_text, value_hint, requires_value, sort_order, active
        FROM serizovac_checklist_catalog';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY project_label, sort_order, id';
$rows = db_all($sql, $params);
$projects = db_all('SELECT project_key, project_label FROM serizovac_checklist_projects WHERE active = 1 ORDER BY project_label');
if ($projects === []) {
    $projects = db_all('SELECT DISTINCT project_key, project_label FROM serizovac_checklist_catalog ORDER BY project_label');
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-list-check me-2"></i><?= h(t('serizovac_catalog_title')) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <?php if ($flashOk !== ''): ?><div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✓ <?= h($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✗ <?= h($flashErr) ?></div><?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-body-dvd">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <form method="get" class="d-flex gap-2 align-items-end">
                    <div style="min-width:200px">
                        <label class="form-label-dvd"><?= h(t('projekt')) ?></label>
                        <select name="project" class="form-select" style="min-width:200px" onchange="this.form.submit()">
                            <option value="">--</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= h((string)$p['project_key']) ?>" <?= $fProject === (string)$p['project_key'] ? 'selected' : '' ?>><?= h((string)$p['project_label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                <button type="button" class="btn-dvd btn-dvd-sm" data-bs-toggle="modal" data-bs-target="#addCatalogItemModal">
                    <i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?>
                </button>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="sync_xlsx">
                    <button type="submit" class="btn-outline" style="padding:.52rem .95rem;font-size:.82rem;white-space:nowrap"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('serizovac_sync_catalog')) ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="card-dvd">
        <div class="card-body-dvd" style="padding:0">
            <div class="overflow-x">
                <table class="tbl">
                    <thead><tr><th>ID</th><th><?= h(t('projekt')) ?></th><th>AFO</th><th><?= h(t('serizovac_task')) ?></th><th><?= h(t('serizovac_value')) ?></th><th><?= h(t('required')) ?></th><th>Sort</th><th><?= h(t('active')) ?></th><th><?= h(t('save')) ?></th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="9" style="padding:.8rem 1rem;color:var(--muted)"><?= h(t('no_data')) ?></td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= h((string)$r['project_label']) ?></td>
                                <td><input type="text" class="form-control" name="afo_code" value="<?= h((string)$r['afo_code']) ?>" style="min-width:90px"></td>
                                <td><input type="text" class="form-control" name="task_text" value="<?= h((string)$r['task_text']) ?>" style="min-width:380px"></td>
                                <td><input type="text" class="form-control" name="value_hint" value="<?= h((string)$r['value_hint']) ?>" style="min-width:120px"></td>
                                <td>
                                    <select class="form-select" name="requires_value">
                                        <option value="0" <?= (int)$r['requires_value'] === 0 ? 'selected' : '' ?>>No</option>
                                        <option value="1" <?= (int)$r['requires_value'] === 1 ? 'selected' : '' ?>>Yes</option>
                                    </select>
                                </td>
                                <td><input type="number" class="form-control" name="sort_order" value="<?= (int)$r['sort_order'] ?>" style="width:90px"></td>
                                <td>
                                    <select class="form-select" name="active">
                                        <option value="1" <?= (int)$r['active'] === 1 ? 'selected' : '' ?>>1</option>
                                        <option value="0" <?= (int)$r['active'] === 0 ? 'selected' : '' ?>>0</option>
                                    </select>
                                </td>
                                <td><button class="btn-dvd btn-dvd-sm" type="submit"><?= h(t('save')) ?></button></td>
                            </form>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addCatalogItemModal" tabindex="-1" aria-hidden="true" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background:var(--surface);color:var(--text);border:1px solid var(--border)">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_item">
                <div class="modal-header" style="border-bottom:1px solid var(--border)">
                    <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i><?= h(t('add')) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label-dvd"><?= h(t('projekt')) ?></label>
                            <select name="project_key" class="form-select" required>
                                <option value="">--</option>
                                <?php foreach ($projects as $p): ?>
                                    <?php $postedProjectKey = (string)post('project_key', $fProject); ?>
                                    <option value="<?= h((string)$p['project_key']) ?>" <?= $postedProjectKey === (string)$p['project_key'] ? 'selected' : '' ?>><?= h((string)$p['project_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-dvd">AFO</label>
                            <input type="text" name="afo_code" class="form-control" value="<?= h((string)post('afo_code')) ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label-dvd"><?= h(t('serizovac_task')) ?></label>
                            <input type="text" name="task_text" class="form-control" value="<?= h((string)post('task_text')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dvd"><?= h(t('serizovac_value')) ?></label>
                            <input type="text" name="value_hint" class="form-control" value="<?= h((string)post('value_hint')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dvd"><?= h(t('required')) ?></label>
                            <select class="form-select" name="requires_value">
                                <option value="0" <?= (string)post('requires_value', '0') === '0' ? 'selected' : '' ?>>No</option>
                                <option value="1" <?= (string)post('requires_value') === '1' ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dvd">Sort</label>
                            <input type="number" name="sort_order" class="form-control" value="<?= h((string)post('sort_order', '0')) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border)">
                    <button type="button" class="btn-outline" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
                    <button type="submit" class="btn-dvd"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($openAddModal): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('addCatalogItemModal');
    if (modalElement) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    }
});
</script>
<?php endif; ?>

<?php require APP_ROOT . '/includes/layout_end.php';
