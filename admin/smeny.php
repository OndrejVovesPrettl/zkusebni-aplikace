<?php
// admin/smeny.php – Správa číselníku směn
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_smeny'); $activePage = 'smeny';

ensureSmenyTable();

// DELETE
if (get('action') === 'delete' && get('id')) {
    if (!verifyCsrfToken(get('_csrf'))) { http_response_code(403); die('CSRF'); }
    db()->prepare('DELETE FROM smeny WHERE id=?')->execute([(int)get('id')]);
    redirect(APP_URL . '/admin/smeny.php?ok=1');
}

// TOGGLE aktivni
if (get('action') === 'toggle' && get('id')) {
    if (!verifyCsrfToken(get('_csrf'))) { http_response_code(403); die('CSRF'); }
    db()->prepare('UPDATE smeny SET aktivni = 1 - aktivni WHERE id=?')->execute([(int)get('id')]);
    redirect(APP_URL . '/admin/smeny.php?ok=1');
}

// SAVE (INSERT / UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $id       = (int)post('id');
    $kod      = strtoupper(trim((string)post('kod')));
    $nazev    = trim((string)post('nazev'));
    $cas_od   = trim((string)post('cas_od'));
    $cas_do   = trim((string)post('cas_do'));
    $prac     = max(0, min(1440, (int)post('pracovni_doba_min')));
    $pauza    = max(0, min(240, (int)post('pauza_min')));
    $poradi   = max(0, min(255, (int)post('poradi')));
    $aktivni  = (int)(bool)post('aktivni');
    if ($kod !== '' && $nazev !== '') {
        if ($id) {
            db()->prepare('UPDATE smeny SET kod=?,nazev=?,cas_od=?,cas_do=?,pracovni_doba_min=?,pauza_min=?,poradi=?,aktivni=? WHERE id=?')
                ->execute([$kod, $nazev, $cas_od, $cas_do, $prac, $pauza, $poradi, $aktivni, $id]);
        } else {
            db()->prepare('INSERT INTO smeny (kod,nazev,cas_od,cas_do,pracovni_doba_min,pauza_min,poradi,aktivni) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$kod, $nazev, $cas_od, $cas_do, $prac, $pauza, $poradi, $aktivni]);
        }
    }
    redirect(APP_URL . '/admin/smeny.php?ok=1');
}

$edit = null;
if (get('edit')) {
    $edit = get('edit') === 'new'
        ? ['id'=>0,'kod'=>'','nazev'=>'','cas_od'=>'06:00','cas_do'=>'14:00','pracovni_doba_min'=>480,'pauza_min'=>30,'poradi'=>99,'aktivni'=>1]
        : db_row('SELECT * FROM smeny WHERE id=?', [(int)get('edit')]);
}

$list = db_all('SELECT * FROM smeny ORDER BY poradi, kod');
require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_smeny') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">

<?php if (get('ok')): ?>
<div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.6rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div>
<?php endif; ?>

<?php if ($edit !== null): $e = $edit; ?>
<div class="card-dvd mb-4">
    <div class="card-header-dvd">
        <span class="s-num"><?= empty($e['id']) ? t('novy_item') : t('edit') ?></span>
        <span class="s-title"><?= empty($e['id']) ? t('add') : h($e['kod']) . ' – ' . h($e['nazev']) ?></span>
    </div>
    <div class="card-body-dvd">
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id"   value="<?= (int)($e['id'] ?? 0) ?>">
        <div class="row g-3">
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_kod') ?> <span class="req">*</span></label>
                <input type="text" name="kod" class="form-control" maxlength="10" required
                       value="<?= h($e['kod'] ?? '') ?>" placeholder="napr. R8">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label-dvd"><?= t('smeny_nazev') ?> <span class="req">*</span></label>
                <input type="text" name="nazev" class="form-control" maxlength="60" required
                       value="<?= h($e['nazev'] ?? '') ?>" placeholder="napr. Ranní 8h">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_cas_od') ?></label>
                <input type="time" name="cas_od" class="form-control" value="<?= h(substr((string)($e['cas_od'] ?? '06:00'), 0, 5)) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_cas_do') ?></label>
                <input type="time" name="cas_do" class="form-control" value="<?= h(substr((string)($e['cas_do'] ?? '14:00'), 0, 5)) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_prac_doba') ?></label>
                <input type="number" name="pracovni_doba_min" class="form-control" min="0" max="1440"
                       value="<?= (int)($e['pracovni_doba_min'] ?? 480) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_pauza') ?></label>
                <input type="number" name="pauza_min" class="form-control" min="0" max="240"
                       value="<?= (int)($e['pauza_min'] ?? 30) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_poradi') ?></label>
                <input type="number" name="poradi" class="form-control" min="0" max="255"
                       value="<?= (int)($e['poradi'] ?? 99) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="aktivni" id="cb-aktivni" value="1"
                           <?= ($e['aktivni'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cb-aktivni"><?= t('aktivni') ?></label>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-check-lg me-1"></i><?= t('save') ?></button>
            <a href="smeny.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('cancel') ?></a>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="card-dvd">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">⬡</span>
        <span class="s-title"><?= t('nav_smeny') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd" style="overflow-x:auto">
    <table class="table-dvd w-100">
        <thead>
            <tr>
                <th><?= t('smeny_kod') ?></th>
                <th><?= t('smeny_nazev') ?></th>
                <th><?= t('smeny_cas_od') ?></th>
                <th><?= t('smeny_cas_do') ?></th>
                <th><?= t('smeny_prac_doba') ?></th>
                <th><?= t('smeny_pauza') ?></th>
                <th><?= t('smeny_poradi') ?></th>
                <th><?= t('stav') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $row): ?>
        <tr class="<?= $row['aktivni'] ? '' : 'opacity-50' ?>">
            <td><strong><?= h($row['kod']) ?></strong></td>
            <td><?= h($row['nazev']) ?></td>
            <td><?= h(substr((string)$row['cas_od'], 0, 5)) ?></td>
            <td><?= h(substr((string)$row['cas_do'], 0, 5)) ?></td>
            <td><?= (int)$row['pracovni_doba_min'] ?> min</td>
            <td><?= (int)$row['pauza_min'] ?> min</td>
            <td><?= (int)$row['poradi'] ?></td>
            <td>
                <span style="font-size:.75rem;padding:.2rem .5rem;border-radius:4px;
                    background:<?= $row['aktivni'] ? 'rgba(74,222,128,.15)' : 'rgba(248,113,113,.15)' ?>;
                    color:<?= $row['aktivni'] ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= $row['aktivni'] ? t('aktivni') : t('neaktivni') ?>
                </span>
            </td>
            <td class="text-end" style="white-space:nowrap">
                <a href="?edit=<?= (int)$row['id'] ?>" class="btn-outline" style="padding:.2rem .6rem;font-size:.72rem"><i class="bi bi-pencil"></i></a>
                <a href="?action=toggle&id=<?= (int)$row['id'] ?>&_csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn-outline" style="padding:.2rem .6rem;font-size:.72rem"
                   title="<?= $row['aktivni'] ? t('deaktivovat') : t('aktivovat') ?>">
                    <i class="bi bi-<?= $row['aktivni'] ? 'eye-slash' : 'eye' ?>"></i>
                </a>
                <a href="?action=delete&id=<?= (int)$row['id'] ?>&_csrf=<?= h(generateCsrfToken()) ?>"
                   class="btn-outline" style="padding:.2rem .6rem;font-size:.72rem;color:var(--danger)"
                   onclick="return confirm('<?= t('confirm_delete') ?>')"
                   title="<?= t('delete') ?>">
                    <i class="bi bi-trash"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($list)): ?>
        <tr><td colspan="9" class="text-center" style="color:var(--muted);padding:1rem"><?= t('no_results') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
