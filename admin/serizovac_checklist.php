<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/serizovac_checklist.php';
requireSerizovacAccess();

$pageTitle = t('serizovac_checklist_title');
$activePage = 'serizovac_checklist';

$flashOk = '';
$flashErr = '';

$tablesReady = serizovacEnsureChecklistTables();
if (!$tablesReady) {
    $flashErr = t('serizovac_tables_error');
}

$seedPath = APP_ROOT . '/uploads/checklist.xlsx';
$catalogCount = $tablesReady ? (int)db_val('SELECT COUNT(*) FROM serizovac_checklist_catalog') : 0;
$projectCount = $tablesReady ? (int)db_val('SELECT COUNT(*) FROM serizovac_checklist_projects WHERE active = 1') : 0;
if ($tablesReady && ($catalogCount === 0 || $projectCount === 0) && is_file($seedPath)) {
    $syncResult = serizovacSyncChecklistCatalogFromXlsx($seedPath);
    if (!$syncResult['ok']) {
        $flashErr = t('serizovac_sync_error');
    }
}

$projects = $tablesReady ? db_all(
    'SELECT project_key, project_label
     FROM serizovac_checklist_projects
     WHERE active = 1
     ORDER BY project_label'
) : [];
if ($projects === [] && $tablesReady) {
    $projects = db_all(
        'SELECT project_key, MAX(project_label) AS project_label
         FROM serizovac_checklist_catalog
         WHERE active = 1
         GROUP BY project_key
         ORDER BY project_label'
    );
}
$projectKeys = array_column($projects, 'project_key');
$currentProject = (string)get('project', $projectKeys[0] ?? '');
if ($currentProject !== '' && !in_array($currentProject, $projectKeys, true)) {
    $currentProject = $projectKeys[0] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)post('action') === 'save_checklist') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $postedProject = trim((string)post('project_key'));
    if ($postedProject === '' || !in_array($postedProject, $projectKeys, true)) {
        $flashErr = t('serizovac_project_invalid');
    } else {
        $currentProject = $postedProject;
        $items = db_all(
            'SELECT id, requires_value
             FROM serizovac_checklist_catalog
             WHERE active = 1 AND project_key = ?
             ORDER BY sort_order, id',
            [$currentProject]
        );

        $errors = [];
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $resultRaw = trim((string)post('result_' . $itemId));
            $value = trim((string)post('value_' . $itemId));
            $result = $resultRaw === '1' ? 'OK' : 'NOK';

            if (!in_array($resultRaw, ['0', '1'], true)) {
                $errors[] = t('serizovac_result_required') . ' #' . $itemId;
                continue;
            }

            $valueOptionalByHint = serizovacValueHintMeansNoInput((string)($item['value_hint'] ?? ''));
            if ((int)$item['requires_value'] === 1 && !$valueOptionalByHint && $value === '') {
                $errors[] = t('serizovac_value_required') . ' #' . $itemId;
            }
        }

        if ($errors !== []) {
            $flashErr = implode('<br>', array_slice($errors, 0, 8));
        } else {
            $projectLabel = (string)db_val(
                'SELECT MAX(project_label) FROM serizovac_checklist_catalog WHERE project_key = ?',
                [$currentProject]
            );
            $displayName = (string)db_val(
                'SELECT COALESCE(NULLIF(skutecneJmeno, ""), username) FROM users WHERE id = ? LIMIT 1',
                [(int)($_SESSION['admin_user_id'] ?? 0)]
            );
            if ($displayName === '') {
                $displayName = (string)($_SESSION['admin_user'] ?? '');
            }

            db()->beginTransaction();
            try {
                db()->prepare(
                    'INSERT INTO serizovac_checklists (project_key, project_label, check_date, created_by, created_by_name)
                     VALUES (?, ?, CURDATE(), ?, ?)'
                )->execute([
                    $currentProject,
                    $projectLabel,
                    (int)($_SESSION['admin_user_id'] ?? 0),
                    $displayName,
                ]);
                $checklistId = (int)db()->lastInsertId();

                $rowStmt = db()->prepare(
                    'INSERT INTO serizovac_checklist_rows (checklist_id, catalog_id, result_status, value_text, note_text)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($items as $item) {
                    $itemId = (int)$item['id'];
                    $result = trim((string)post('result_' . $itemId)) === '1' ? 'OK' : 'NOK';
                    $value = trim((string)post('value_' . $itemId));
                    $note = trim((string)post('note_' . $itemId));
                    $rowStmt->execute([
                        $checklistId,
                        $itemId,
                        $result,
                        $value !== '' ? $value : null,
                        $note !== '' ? $note : null,
                    ]);
                }

                db()->commit();
                $flashOk = t('serizovac_checklist_saved');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $flashErr = t('serizovac_save_error');
            }
        }
    }
}

$items = (!$tablesReady || $currentProject === '') ? [] : db_all(
    'SELECT id, afo_code, task_text, value_hint, requires_value
     FROM serizovac_checklist_catalog
     WHERE active = 1 AND project_key = ?
     ORDER BY sort_order, id',
    [$currentProject]
);

$afoRowspans = [];
$itemsCount = count($items);
for ($i = 0; $i < $itemsCount; $i++) {
    $currentAfo = trim((string)$items[$i]['afo_code']);
    if ($currentAfo === '') {
        $afoRowspans[(int)$items[$i]['id']] = 1;
        continue;
    }

    $isGroupStart = ($i === 0) || (trim((string)$items[$i - 1]['afo_code']) !== $currentAfo);
    if (!$isGroupStart) {
        $afoRowspans[(int)$items[$i]['id']] = 0;
        continue;
    }

    $span = 1;
    for ($j = $i + 1; $j < $itemsCount; $j++) {
        if (trim((string)$items[$j]['afo_code']) !== $currentAfo) {
            break;
        }
        $span++;
    }
    $afoRowspans[(int)$items[$i]['id']] = $span;
}

$recent = $tablesReady ? db_all(
    'SELECT id, project_label, check_date, created_by_name, created_at
     FROM serizovac_checklists
     ORDER BY id DESC
     LIMIT 20'
) : [];

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-clipboard-check me-2"></i><?= h(t('serizovac_checklist_title')) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <?php if ($flashOk !== ''): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        ✓ <?= h($flashOk) ?>
    </div>
    <?php endif; ?>

    <?php if ($flashErr !== ''): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        ✗ <?= $flashErr ?>
    </div>
    <?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-body-dvd">
            <?php if ($projects === []): ?>
                <div style="color:var(--muted)"><?= h(t('serizovac_no_items')) ?></div>
            <?php else: ?>
                <form method="get" class="d-flex gap-2 align-items-end mb-3">
                    <div style="min-width:200px">
                        <label class="form-label-dvd"><?= h(t('projekt')) ?></label>
                        <select name="project" class="form-select" style="min-width:200px" onchange="this.form.submit()">
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= h((string)$p['project_key']) ?>" <?= $currentProject === (string)$p['project_key'] ? 'selected' : '' ?>><?= h((string)$p['project_label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_checklist">
                    <input type="hidden" name="project_key" value="<?= h($currentProject) ?>">

                    <div class="tbl-wrap">
                        <div class="overflow-x">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th style="width:90px">AFO</th>
                                        <th><?= h(t('serizovac_task')) ?></th>
                                        <th style="width:210px"><?= h(t('serizovac_result')) ?></th>
                                        <th style="width:220px"><?= h(t('serizovac_value')) ?></th>
                                        <th style="width:260px"><?= h(t('note')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php $itemId = (int)$item['id']; ?>
                                    <?php $valueHint = (string)$item['value_hint']; ?>
                                    <?php $hideValueInput = serizovacValueHintMeansNoInput($valueHint); ?>
                                    <tr>
                                        <?php if (($afoRowspans[$itemId] ?? 0) > 0): ?>
                                            <td rowspan="<?= (int)$afoRowspans[$itemId] ?>" style="vertical-align:top;padding-top:.7rem">
                                                <span style="display:inline-block;font-weight:700;letter-spacing:.02em">
                                                    <?= h((string)$item['afo_code']) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?= h((string)$item['task_text']) ?>
                                            <?php if ((int)$item['requires_value'] === 1): ?>
                                                <span class="req">*</span>
                                            <?php elseif (trim($valueHint) !== '' && !$hideValueInput): ?>
                                                <span style="color:var(--muted)">[<?= h($valueHint) ?>]</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:.6rem;min-height:40px">
                                                <input type="hidden" name="result_<?= $itemId ?>" value="0">
                                                <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;margin:0;font-weight:600">
                                                    <input type="checkbox" name="result_<?= $itemId ?>" value="1" style="width:18px;height:18px">
                                                    <span>OK</span>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($hideValueInput): ?>
                                                
                                            <?php else: ?>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    name="value_<?= $itemId ?>"
                                                    <?= (int)$item['requires_value'] === 1 ? 'required' : '' ?>
                                                    placeholder="<?= h($valueHint) ?>"
                                                >
                                            <?php endif; ?>
                                        </td>
                                        <td><input type="text" class="form-control" name="note_<?= $itemId ?>"></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn-dvd"><i class="bi bi-save me-1"></i><?= h(t('save')) ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-dvd">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-clock-history"></i></span><span class="s-title"><?= h(t('serizovac_recent')) ?></span></div>
        <div class="card-body-dvd" style="padding:0">
            <div class="overflow-x">
                <table class="tbl">
                    <thead><tr><th>ID</th><th><?= h(t('projekt')) ?></th><th><?= h(t('date')) ?></th><th><?= h(t('user')) ?></th><th><?= h(t('datetime')) ?></th></tr></thead>
                    <tbody>
                    <?php if ($recent === []): ?>
                        <tr><td colspan="5" style="padding:.8rem 1rem;color:var(--muted)"><?= h(t('no_data')) ?></td></tr>
                    <?php else: foreach ($recent as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= h((string)$r['project_label']) ?></td>
                            <td><?= h((string)$r['check_date']) ?></td>
                            <td><?= h((string)$r['created_by_name']) ?></td>
                            <td><?= h((string)$r['created_at']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php';
