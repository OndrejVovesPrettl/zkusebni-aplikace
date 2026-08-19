<?php
// admin/prestavky.php – Správa číselníku přestávek (ČISTÁ VERZE BEZ AUTOMATICKÝCH RE-SEEDŮ)
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_prestavky');
$activePage = 'prestavky';

// POZOR: Odstraněno volání ensurePrestavkyTable(), které způsobovalo neustálé znovu-vkládání a reaktivaci smazaných přestávek!

$msg = null;
$msgType = 'success';

// 1. JEDNORÁZOVÉ VYČIŠTĚNÍ DUPLICIT (Spustí se POUZE po kliknutí na tlačítko uživatelem)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'clean_duplicates') {
    if (!verifyCsrfToken(post('_csrf'))) {
        $msg = 'CSRF ověření selhalo.';
        $msgType = 'danger';
    } else {
        try {
            db()->exec("DELETE p1 FROM prestavky p1
                        INNER JOIN prestavky p2 
                        WHERE p1.id > p2.id 
                          AND p1.zona = p2.zona 
                          AND p1.smena_kod = p2.smena_kod 
                          AND p1.nazev = p2.nazev");
            
            redirect(APP_URL . '/admin/prestavky.php?msg=cleaned');
        } catch (Throwable $e) {
            $msg = 'Chyba při čištění: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// 2. DELETE (Trvalé smazání z databáze)
if (get('action') === 'delete' && get('id')) {
    if (!verifyCsrfToken(get('_csrf'))) { 
        $msg = 'CSRF ověření selhalo.'; 
        $msgType = 'danger';
    } else {
        try {
            db()->prepare('DELETE FROM prestavky WHERE id=?')->execute([(int)get('id')]);
            redirect(APP_URL . '/admin/prestavky.php?msg=deleted');
        } catch (Throwable $e) {
            $msg = 'Chyba při mazání: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// 3. TOGGLE (Změna stavu aktivní / neaktivní)
if (get('action') === 'toggle' && get('id')) {
    if (!verifyCsrfToken(get('_csrf'))) { 
        $msg = 'CSRF ověření selhalo.'; 
        $msgType = 'danger';
    } else {
        try {
            db()->prepare('UPDATE prestavky SET aktivni = 1 - aktivni WHERE id=?')->execute([(int)get('id')]);
            redirect(APP_URL . '/admin/prestavky.php?msg=toggled');
        } catch (Throwable $e) {
            $msg = 'Chyba při změně stavu: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// 4. SAVE (Uložení nové / Úprava stávající přestávky)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken(post('_csrf'))) { 
        $msg = 'CSRF ověření selhalo.'; 
        $msgType = 'danger';
    } else {
        $id       = (int)post('id');
        $zona     = trim((string)post('zona'));
        $rezim    = (int)post('rezim_hod');
        $smena    = strtoupper(trim((string)post('smena_kod')));
        $nazev    = trim((string)post('nazev'));
        $casOd    = trim((string)post('cas_od'));
        $casDo    = trim((string)post('cas_do'));
        $trvani   = max(0, min(240, (int)post('trvani_min')));
        $poradi   = max(1, min(99, (int)post('poradi')));
        $aktivni  = (int)(bool)post('aktivni');

        $rezim = in_array($rezim, [8, 12], true) ? $rezim : 8;
        $casOdSql = $casOd !== '' ? $casOd : null;
        $casDoSql = $casDo !== '' ? $casDo : null;

        if ($zona !== '' && $smena !== '' && $nazev !== '') {
            try {
                if ($id > 0) {
                    db()->prepare('UPDATE prestavky SET zona=?,rezim_hod=?,smena_kod=?,nazev=?,cas_od=?,cas_do=?,trvani_min=?,poradi=?,aktivni=? WHERE id=?')
                        ->execute([$zona, $rezim, $smena, $nazev, $casOdSql, $casDoSql, $trvani, $poradi, $aktivni, $id]);
                    redirect(APP_URL . '/admin/prestavky.php?msg=updated');
                } else {
                    db()->prepare('INSERT INTO prestavky (zona,rezim_hod,smena_kod,nazev,cas_od,cas_do,trvani_min,poradi,aktivni) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$zona, $rezim, $smena, $nazev, $casOdSql, $casDoSql, $trvani, $poradi, $aktivni]);
                    redirect(APP_URL . '/admin/prestavky.php?msg=inserted');
                }
            } catch (Throwable $e) {
                $msg = 'Chyba při ukládání: ' . $e->getMessage();
                $msgType = 'danger';
            }
        } else {
            $msg = 'Vyplňte prosím všechna povinná pole (Zóna, Směna, Název).';
            $msgType = 'danger';
        }
    }
}

// Zprávy po přesměrování
if (get('msg') === 'inserted') { $msg = '✓ Přestávka byla úspěšně vytvořena.'; $msgType = 'success'; }
elseif (get('msg') === 'updated') { $msg = '✓ Přestávka byla úspěšně upravena.'; $msgType = 'success'; }
elseif (get('msg') === 'deleted') { $msg = '✓ Přestávka byla úspěšně smazána z databáze.'; $msgType = 'success'; }
elseif (get('msg') === 'toggled') { $msg = '✓ Stav přestávky byl změněn.'; $msgType = 'success'; }
elseif (get('msg') === 'cleaned') { $msg = '✓ Duplicity byly úspěšně vyčištěny.'; $msgType = 'success'; }

$edit = null;
if (get('edit')) {
    if (get('edit') === 'new') {
        $maxPoradi = (int)db_val("SELECT MAX(poradi) FROM prestavky WHERE zona='Série' AND smena_kod='R8'");
        $edit = [
            'id' => 0,
            'zona' => 'Série',
            'rezim_hod' => 8,
            'smena_kod' => 'R8',
            'nazev' => 'Nová přestávka',
            'cas_od' => '08:30',
            'cas_do' => '08:40',
            'trvani_min' => 10,
            'poradi' => $maxPoradi > 0 ? $maxPoradi + 1 : 1,
            'aktivni' => 1,
        ];
    } else {
        $edit = db_row('SELECT * FROM prestavky WHERE id=?', [(int)get('edit')]);
    }
}

$list = db_all('SELECT * FROM prestavky ORDER BY zona, rezim_hod, smena_kod, poradi, id');

require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_prestavky') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

<?php if ($msg !== null): ?>
<div class="alert mb-3" style="background:<?= $msgType === 'success' ? 'rgba(74,222,128,.12)' : 'rgba(255,107,53,.12)' ?>;border:1px solid <?= $msgType === 'success' ? 'rgba(74,222,128,.3)' : 'rgba(255,107,53,.3)' ?>;color:<?= $msgType === 'success' ? 'var(--success)' : 'var(--accent2)' ?>;border-radius:8px;padding:.6rem 1rem;font-size:.82rem">
    <?= h($msg) ?>
</div>
<?php endif; ?>

<!-- Tlačítko pro jednorázovou očistu databáze -->
<div class="card-dvd mb-3 no-print">
    <div class="card-body-dvd d-flex align-items-center justify-content-between gap-3">
        <span style="font-size:.78rem;color:var(--muted)">Správa číselníku přestávek. Načtení stránky neprovádí žádné automatické změny v DB.</span>
        <form method="post" style="margin:0">
            <input type="hidden" name="action" value="clean_duplicates">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <button type="submit" class="btn-outline danger" style="padding:.28rem .7rem;font-size:.78rem" onclick="return confirm('Opravdu vyčistit duplicitní záznamy ze stejným názvem a směnou?')">
                <i class="bi bi-trash3 me-1"></i>Vyčistit duplicity z DB
            </button>
        </form>
    </div>
</div>

<?php if ($edit !== null): $e = $edit; ?>
<div class="card-dvd mb-4">
    <div class="card-header-dvd">
        <span class="s-num"><?= empty($e['id']) ? t('novy_item') : t('edit') ?></span>
        <span class="s-title"><?= empty($e['id']) ? t('add') : h($e['zona'] ?? '') . ' / ' . h($e['smena_kod'] ?? '') ?></span>
    </div>
    <div class="card-body-dvd">
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">

        <div class="row g-3">
            <div class="col-12 col-md-5">
                <label class="form-label-dvd"><?= t('prestavky_zona') ?> <span class="req">*</span></label>
                <input type="text" name="zona" class="form-control" maxlength="160" required value="<?= h($e['zona'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('prestavky_rezim') ?></label>
                <select name="rezim_hod" class="form-select">
                    <option value="8"<?= ((int)($e['rezim_hod'] ?? 8) === 8) ? ' selected' : '' ?>>8h</option>
                    <option value="12"<?= ((int)($e['rezim_hod'] ?? 8) === 12) ? ' selected' : '' ?>>12h</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('prestavky_smena') ?> <span class="req">*</span></label>
                <input type="text" name="smena_kod" class="form-control" maxlength="10" required value="<?= h($e['smena_kod'] ?? '') ?>" placeholder="R8/O8/N8/R12/N12">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label-dvd"><?= t('prestavky_nazev') ?> <span class="req">*</span></label>
                <input type="text" name="nazev" class="form-control" maxlength="80" required value="<?= h($e['nazev'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_cas_od') ?></label>
                <input type="time" name="cas_od" class="form-control" value="<?= h(substr((string)($e['cas_od'] ?? '08:30'), 0, 5)) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_cas_do') ?></label>
                <input type="time" name="cas_do" class="form-control" value="<?= h(substr((string)($e['cas_do'] ?? '08:40'), 0, 5)) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('prestavky_trvani') ?></label>
                <input type="number" name="trvani_min" class="form-control" min="0" max="240" value="<?= (int)($e['trvani_min'] ?? 10) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('smeny_poradi') ?></label>
                <input type="number" name="poradi" class="form-control" min="1" max="99" value="<?= (int)($e['poradi'] ?? 1) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="aktivni" id="cb-prestavka-aktivni" value="1" <?= ($e['aktivni'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cb-prestavka-aktivni"><?= t('aktivni') ?></label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-check-lg me-1"></i><?= t('save') ?></button>
            <a href="prestavky.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('cancel') ?></a>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="card-dvd">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">⬡</span>
        <span class="s-title"><?= t('nav_prestavky') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd" style="overflow-x:auto">
    <table class="table-dvd w-100">
        <thead>
            <tr>
                <th><?= t('prestavky_zona') ?></th>
                <th><?= t('prestavky_rezim') ?></th>
                <th><?= t('prestavky_smena') ?></th>
                <th><?= t('prestavky_nazev') ?></th>
                <th><?= t('smeny_cas_od') ?></th>
                <th><?= t('smeny_cas_do') ?></th>
                <th><?= t('prestavky_trvani') ?></th>
                <th><?= t('smeny_poradi') ?></th>
                <th><?= t('stav') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $row): ?>
        <tr class="<?= $row['aktivni'] ? '' : 'opacity-50' ?>">
            <td><strong><?= h($row['zona']) ?></strong></td>
            <td><?= (int)$row['rezim_hod'] ?>h</td>
            <td><strong><?= h($row['smena_kod']) ?></strong></td>
            <td><?= h($row['nazev']) ?></td>
            <td><?= h(substr((string)($row['cas_od'] ?? ''), 0, 5)) ?></td>
            <td><?= h(substr((string)($row['cas_do'] ?? ''), 0, 5)) ?></td>
            <td><strong><?= (int)$row['trvani_min'] ?> min</strong></td>
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
        <tr><td colspan="10" class="text-center" style="color:var(--muted);padding:1rem"><?= t('no_results') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php require APP_ROOT . '/includes/layout.php'; ?>