<?php
// admin/prostoj_typy.php – Správa typů technických prostojů
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_prostoje'); $activePage = 'prostoj_typy';

function normalizeCsvDowntimeTypeValue(?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    if ($value === null || $value === '') {
        return $value;
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
        if ($converted === false || $converted === '') {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $value);
        }
        if ($converted !== false && $converted !== '') {
            $value = $converted;
        }
    }

    return trim($value);
}

if (get('action')==='delete'&&get('id')) { db()->prepare('DELETE FROM prostoj_typy WHERE id=?')->execute([(int)get('id')]); redirect(APP_URL.'/admin/prostoj_typy.php?ok=1'); }
if ($_SERVER['REQUEST_METHOD']==='POST'&&post('action')==='save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $id=(int)post('id');
    $data=[post('kod'),post('popis'),post('detail')?:null];
    if($id) db()->prepare('UPDATE prostoj_typy SET kod=?,popis=?,detail=? WHERE id=?')->execute([...$data,$id]);
    else    db()->prepare('INSERT IGNORE INTO prostoj_typy (kod,popis,detail) VALUES (?,?,?)')->execute($data);
    redirect(APP_URL.'/admin/prostoj_typy.php?ok=1');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='csv_import') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $imp = 0;
    $skip = 0;
    $csvTmp = isset($_FILES['csv_file']) ? validateUploadedCsv($_FILES['csv_file']) : null;
    if ($csvTmp !== null) {
        $h = fopen($csvTmp, 'r');
        if ($h !== false) {
            fgetcsv($h, 0, ';');
            $stmt = db()->prepare('INSERT IGNORE INTO prostoj_typy (kod,popis,detail) VALUES (?,?,?)');
            while (($r = fgetcsv($h, 0, ';')) !== false) {
                $kod = normalizeCsvDowntimeTypeValue($r[0] ?? null);
                $popis = normalizeCsvDowntimeTypeValue($r[1] ?? null);
                $detail = normalizeCsvDowntimeTypeValue($r[2] ?? null) ?: null;
                if ($kod === null || $kod === '' || $popis === null || $popis === '') {
                    $skip++;
                    continue;
                }

                $kod = mb_substr($kod, 0, 5);
                if ($kod === '') {
                    $skip++;
                    continue;
                }

                try {
                    $stmt->execute([$kod, $popis, $detail]);
                    $imp += $stmt->rowCount();
                } catch (Exception) {
                    $skip++;
                }
            }
            fclose($h);
        }
    } else {
        $skip++;
    }
    $msg = t('import_ok').': '.$imp.' '.t('import_rows').', '.$skip.' '.t('import_skip');
}
$edit=null; if(get('edit')) $edit=get('edit')==='new'?[]:db_row('SELECT * FROM prostoj_typy WHERE id=?',[(int)get('edit')]);
$fSearch = trim((string)get('search', ''));
$where = [];
$params = [];
if ($fSearch !== '') {
    $where[] = '(kod LIKE ? OR popis LIKE ? OR detail LIKE ?)';
    $params[] = '%'.$fSearch.'%';
    $params[] = '%'.$fSearch.'%';
    $params[] = '%'.$fSearch.'%';
}
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
$list=db_all('SELECT * FROM prostoj_typy WHERE ' . $whereSql . "\n"
    . "ORDER BY\n"
    . "    LEFT(UPPER(kod), 1),\n"
    . "    CASE WHEN SUBSTRING(UPPER(kod), 2) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,\n"
    . "    CAST(SUBSTRING(UPPER(kod), 2) AS UNSIGNED),\n"
    . "    UPPER(kod)", $params);
require APP_ROOT.'/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_prostoje') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">
<?php if(get('ok')):?><div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.6rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div><?php endif;?>
<?php if(isset($msg)):?><div class="alert mb-3" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.6rem 1rem;font-size:.82rem"><?= h($msg) ?></div><?php endif;?>

<div class="row mb-3 no-print">
    <div class="col-12">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <input type="text" name="search" class="form-control" placeholder="Hledat kód, popis nebo detail" value="<?= h($fSearch) ?>">
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-search me-1"></i>Filtrovat</button>
                <?php if ($fSearch !== ''): ?><a href="prostoj_typy.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible><span class="s-num">⬆</span><span class="s-title"><?= t('import_csv') ?> – <?= t('import_csv_downtime_types') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
    <div class="card-body-dvd" style="display:none">
        <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="csv_import">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-6"><label class="form-label-dvd"><?= t('import_select') ?></label><input type="file" name="csv_file" class="form-control" accept=".csv" required></div>
            <div class="col-auto d-flex gap-2">
                <a href="<?= APP_URL ?>/assets/templates/import_prostoj_typy_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download>
                    <i class="bi bi-download me-1"></i>Vzor CSV
                </a>
                <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
            </div>
        </div>
        <div class="mt-2" style="font-size:.7rem;color:var(--muted)"><?= t('format_downtime_types') ?></div>
        </form>
    </div>
</div>

<?php if($edit!==null): $e=$edit;?>
<div class="card-dvd mb-4">
    <div class="card-header-dvd"><span class="s-num"><?= empty($e)?t('novy_item'):t('edit') ?></span><span class="s-title"><?= empty($e)?t('add'):t('edit') ?></span></div>
    <div class="card-body-dvd">
    <form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="id" value="<?=(int)($e['id']??0)?>">
    <div class="row g-3">
        <div class="col-4 col-md-2"><label class="form-label-dvd"><?= t('kod') ?> <span class="req">*</span></label><input type="text" name="kod" class="form-control" value="<?= h($e['kod'] ?? '') ?>" placeholder="<?= t('example_code') ?>" maxlength="5" required></div>
        <div class="col-8 col-md-5"><label class="form-label-dvd"><?= t('popis') ?> <span class="req">*</span></label><input type="text" name="popis" class="form-control" value="<?= h($e['popis'] ?? '') ?>" required></div>
        <div class="col-12 col-md-5"><label class="form-label-dvd"><?= t('max_5_chars_hint') ?></label><input type="text" name="detail" class="form-control" value="<?= h($e['detail'] ?? '') ?>"></div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('save') ?></button>
            <a href="prostoj_typy.php" class="btn-outline" style="padding:.35rem .9rem;font-size:.78rem"><?= t('cancel') ?></a>
        </div>
    </div>
    </form></div>
</div>
<?php endif;?>
<div class="tbl-wrap"><div class="overflow-x">
<table class="tbl">
    <thead><tr><th><?= t('kod') ?></th><th><?= t('popis') ?></th><th><?= t('max_5_chars_hint') ?></th><th><?= t('actions') ?></th></tr></thead>
    <tbody>
    <?php foreach($list as $p):?>
    <tr><td class="mono text-accent"><?= h($p['kod']) ?></td><td><?= h($p['popis']) ?></td>
        <td style="font-size:.75rem;color:var(--muted)"><?= h($p['detail']??'—') ?></td>
        <td><a href="?edit=<?= $p['id'] ?>" class="btn-icon"><i class="bi bi-pencil"></i></a>
            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>"><i class="bi bi-trash3"></i></a></td>
    </tr>
    <?php endforeach;?>
    </tbody>
</table>
</div><div class="tbl-footer"><span><?= count($list) ?> <?= count($list)==1?t('plural_type'):t('plural_types') ?></span></div></div>
</div>
<?php require APP_ROOT.'/includes/layout_end.php';?>
