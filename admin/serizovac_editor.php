<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/serizovac_checklist.php';
requireSerizovacEdit();

$pageTitle = t('serizovac_editor_title');
$activePage = 'serizovac_editor';
$flashOk = '';
$flashErr = '';

if (!serizovacEnsureChecklistTables()) {
    $flashErr = t('serizovac_tables_error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $action = (string)post('action');
    $entryId = (int)post('entry_id');

    if ($action === 'delete_entry' && $entryId > 0) {
        db()->prepare('DELETE FROM serizovac_checklists WHERE id = ?')->execute([$entryId]);
        $flashOk = t('delete') . ' OK';
    }

    if ($action === 'save_entry' && $entryId > 0) {
        $rows = db_all('SELECT id, catalog_id FROM serizovac_checklist_rows WHERE checklist_id = ?', [$entryId]);
        $errors = [];
        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $catalogId = (int)$row['catalog_id'];
            $requiresValue = (int)db_val('SELECT requires_value FROM serizovac_checklist_catalog WHERE id = ?', [$catalogId]);
            $result = strtoupper(trim((string)post('result_' . $rowId)));
            $value = trim((string)post('value_' . $rowId));
            if (!in_array($result, ['OK', 'NOK', 'NA'], true)) {
                $errors[] = 'Row #' . $rowId;
                continue;
            }
            if ($requiresValue === 1 && $value === '') {
                $errors[] = t('serizovac_value_required') . ' #' . $rowId;
            }
        }

        if ($errors === []) {
            $stmt = db()->prepare('UPDATE serizovac_checklist_rows SET result_status = ?, value_text = ?, note_text = ? WHERE id = ? AND checklist_id = ?');
            foreach ($rows as $row) {
                $rowId = (int)$row['id'];
                $result = strtoupper(trim((string)post('result_' . $rowId)));
                $value = trim((string)post('value_' . $rowId));
                $note = trim((string)post('note_' . $rowId));
                $stmt->execute([$result, $value !== '' ? $value : null, $note !== '' ? $note : null, $rowId, $entryId]);
            }
            $flashOk = t('save') . ' OK';
        } else {
            $flashErr = implode('<br>', array_slice($errors, 0, 8));
        }
    }
}

$fProject = trim((string)get('project', ''));
$fDate = trim((string)get('date', ''));
$params = [];
$where = [];
if ($fProject !== '') {
    $where[] = 'project_key = ?';
    $params[] = $fProject;
}
if ($fDate !== '') {
    $where[] = 'check_date = ?';
    $params[] = $fDate;
}

$sql = 'SELECT id, project_key, project_label, check_date, created_by_name, created_at FROM serizovac_checklists';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC LIMIT 150';
$entries = db_all($sql, $params);

$entryId = (int)get('entry_id', 0);
$entry = $entryId > 0 ? db_row('SELECT * FROM serizovac_checklists WHERE id = ?', [$entryId]) : null;
$entryRows = $entryId > 0 ? db_all(
    'SELECT r.id, r.result_status, r.value_text, r.note_text, c.afo_code, c.task_text, c.requires_value
     FROM serizovac_checklist_rows r
     INNER JOIN serizovac_checklist_catalog c ON c.id = r.catalog_id
     WHERE r.checklist_id = ?
     ORDER BY c.sort_order, c.id',
    [$entryId]
) : [];

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-table me-2"></i><?= h(t('serizovac_editor_title')) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <?php if ($flashOk !== ''): ?><div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✓ <?= h($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✗ <?= $flashErr ?></div><?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-body-dvd">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-3"><label class="form-label-dvd">Projekt</label><input type="text" class="form-control" name="project" value="<?= h($fProject) ?>"></div>
                <div class="col-12 col-md-3"><label class="form-label-dvd"><?= h(t('date')) ?></label><input type="date" class="form-control" name="date" value="<?= h($fDate) ?>"></div>
                <div class="col-auto"><button class="btn-dvd btn-dvd-sm" type="submit"><?= h(t('search')) ?></button></div>
            </form>
        </div>
    </div>

    <div class="card-dvd mb-3">
        <div class="card-body-dvd" style="padding:0">
            <div class="overflow-x">
                <table class="tbl">
                    <thead><tr><th>ID</th><th><?= h(t('projekt')) ?></th><th><?= h(t('date')) ?></th><th><?= h(t('user')) ?></th><th><?= h(t('datetime')) ?></th><th><?= h(t('action')) ?></th></tr></thead>
                    <tbody>
                    <?php if ($entries === []): ?>
                        <tr><td colspan="6" style="padding:.8rem 1rem;color:var(--muted)"><?= h(t('no_data')) ?></td></tr>
                    <?php else: foreach ($entries as $e): ?>
                        <tr>
                            <td><?= (int)$e['id'] ?></td>
                            <td><?= h((string)$e['project_label']) ?></td>
                            <td><?= h((string)$e['check_date']) ?></td>
                            <td><?= h((string)$e['created_by_name']) ?></td>
                            <td><?= h((string)$e['created_at']) ?></td>
                            <td>
                                <a class="btn-outline" style="padding:.2rem .5rem;font-size:.78rem" href="serizovac_editor.php?entry_id=<?= (int)$e['id'] ?>">Editor</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($entry): ?>
    <div class="card-dvd">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-pencil"></i></span><span class="s-title">Entry #<?= (int)$entry['id'] ?></span></div>
        <div class="card-body-dvd">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="save_entry">
                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                <div class="overflow-x">
                    <table class="tbl">
                        <thead><tr><th>AFO</th><th><?= h(t('serizovac_task')) ?></th><th><?= h(t('serizovac_result')) ?></th><th><?= h(t('serizovac_value')) ?></th><th><?= h(t('note')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($entryRows as $row): ?>
                            <?php $rowId = (int)$row['id']; ?>
                            <tr>
                                <td><?= h((string)$row['afo_code']) ?></td>
                                <td><?= h((string)$row['task_text']) ?> <?= (int)$row['requires_value'] === 1 ? '<span class="req">*</span>' : '' ?></td>
                                <td>
                                    <select name="result_<?= $rowId ?>" class="form-select" required>
                                        <?php foreach (['OK', 'NOK', 'NA'] as $opt): ?>
                                            <option value="<?= $opt ?>" <?= (string)$row['result_status'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" class="form-control" name="value_<?= $rowId ?>" value="<?= h((string)$row['value_text']) ?>" <?= (int)$row['requires_value'] === 1 ? 'required' : '' ?>></td>
                                <td><input type="text" class="form-control" name="note_<?= $rowId ?>" value="<?= h((string)$row['note_text']) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn-dvd"><i class="bi bi-save me-1"></i><?= h(t('save')) ?></button>
                </div>
            </form>
            <form method="post" class="mt-2" onsubmit="return confirm('<?= h(t('confirm_delete')) ?>');">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete_entry">
                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                <button type="submit" class="btn-outline" style="border-color:var(--danger);color:var(--danger)"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/layout_end.php';
