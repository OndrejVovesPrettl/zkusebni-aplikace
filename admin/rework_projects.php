<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['admin', 'superadmin', 'rework_admin']);

$pageTitle  = t('rework_projects_title');
$activePage = 'rework_projects';
ensureReworkTables();

// ── DELETE (deactivate) ───────────────────────────────────────────────────────
if (get('action') === 'delete' && get('id')) {
    db()->prepare('UPDATE rework_projects SET active = 0 WHERE id = ?')->execute([(int)get('id')]);
    redirect(APP_URL . '/admin/rework_projects.php?ok=delete');
}

// ── SAVE ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }
    $id         = max(0, (int)post('id'));
    $code       = mb_substr(trim((string)post('code')), 0, 20);
    $label      = mb_substr(trim((string)post('label')), 0, 100);
    $sortOrder  = max(0, (int)post('sort_order'));
    $active     = post('active') ? 1 : 0;

    if ($code !== '') {
        if ($id > 0) {
            db()->prepare('UPDATE rework_projects SET code = ?, label = ?, sort_order = ?, active = ? WHERE id = ?')
               ->execute([$code, $label, $sortOrder, $active, $id]);
        } else {
            db()->prepare('INSERT INTO rework_projects (code, label, sort_order, active) VALUES (?, ?, ?, ?)')
               ->execute([$code, $label, $sortOrder, $active]);
        }
    }
    redirect(APP_URL . '/admin/rework_projects.php?ok=1');
}

// ── LOAD ──────────────────────────────────────────────────────────────────────
$edit = null;
if (get('edit') !== null) {
    $edit = get('edit') === 'new'
        ? ['id' => 0, 'code' => '', 'label' => '', 'sort_order' => 0, 'active' => 1]
        : db_row('SELECT * FROM rework_projects WHERE id = ?', [(int)get('edit')]);
}

$flashOk  = get('ok') === '1' ? t('rework_item_saved') : (get('ok') === 'delete' ? t('rework_item_deleted') : null);
$list     = getReworkProjects(false);

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-diagram-3 me-2"></i><?= t('rework_projects_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('rework_new_item') ?></a>
    </div>
</div>

<div class="content">
    <?php if ($flashOk): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✓ <?= h($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($edit !== null): ?>
    <!-- ── Edit form ── -->
    <div class="card-dvd mb-3" style="max-width:560px">
        <div class="card-header-dvd">
            <span class="s-num"><i class="bi bi-pencil"></i></span>
            <span class="s-title"><?= (int)($edit['id'] ?? 0) > 0 ? t('edit') : t('rework_new_item') ?></span>
        </div>
        <div class="card-body-dvd">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label-dvd"><?= t('rework_code') ?> <span class="req">*</span></label>
                        <input type="text" name="code" class="form-control" required maxlength="20"
                            value="<?= h((string)($edit['code'] ?? '')) ?>" autofocus>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label-dvd"><?= t('rework_label_col') ?></label>
                        <input type="text" name="label" class="form-control" maxlength="100"
                            value="<?= h((string)($edit['label'] ?? '')) ?>">
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label-dvd"><?= t('rework_sort_order') ?></label>
                        <input type="number" name="sort_order" class="form-control" min="0" max="9999"
                            value="<?= (int)($edit['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="col-6 col-sm-4 d-flex align-items-end pb-1">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem">
                            <input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>
                            <?= t('rework_active') ?>
                        </label>
                    </div>
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <a href="rework_projects.php" class="btn-outline" style="padding:.45rem .9rem;font-size:.82rem"><?= t('cancel') ?></a>
                        <button type="submit" class="btn-dvd"><i class="bi bi-check2-circle me-1"></i><?= t('save') ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── List ── -->
    <div class="card-dvd">
        <div class="card-header-dvd">
            <span class="s-num"><i class="bi bi-list-ul"></i></span>
            <span class="s-title"><?= t('rework_projects_title') ?></span>
            <span class="ms-auto" style="font-size:.78rem;color:var(--muted)"><?= count($list) ?> <?= t('import_rows') ?></span>
        </div>
        <div class="card-body-dvd" style="padding:0">
            <div style="overflow-x:auto">
                <table class="table-dvd" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= t('rework_code') ?></th>
                            <th><?= t('rework_label_col') ?></th>
                            <th><?= t('rework_sort_order') ?></th>
                            <th><?= t('rework_active') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$list): ?>
                        <tr><td colspan="6" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                    <?php else: foreach ($list as $row): ?>
                        <tr <?= !(int)$row['active'] ? 'style="opacity:.5"' : '' ?>>
                            <td class="mono"><?= (int)$row['id'] ?></td>
                            <td><strong><?= h((string)$row['code']) ?></strong></td>
                            <td><?= h((string)$row['label']) ?></td>
                            <td class="mono"><?= (int)$row['sort_order'] ?></td>
                            <td>
                                <?php if ((int)$row['active']): ?>
                                <span class="badge-ok"><?= t('active') ?></span>
                                <?php else: ?>
                                <span class="badge-nok"><?= t('inactive') ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a href="?edit=<?= (int)$row['id'] ?>" class="btn-outline" style="padding:.3rem .65rem;font-size:.75rem"><i class="bi bi-pencil"></i></a>
                                <?php if ((int)$row['active']): ?>
                                <a href="?action=delete&id=<?= (int)$row['id'] ?>"
                                    onclick="return confirm('<?= h(t('confirm_delete')) ?>')"
                                    class="btn-outline" style="padding:.3rem .65rem;font-size:.75rem;color:var(--danger)">
                                    <i class="bi bi-eye-slash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
