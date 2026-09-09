<?php
// admin/pracovisite.php – Správa pracovišť + CSV import
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle  = t('nav_pracovisite');
$activePage = 'pracovisite';
$msg = '';
$showAll = get('show', '') === 'all';
$fSearch = trim((string)get('search', ''));
$fTyp = get('typ', '');
$fActive = (string)get('active', '');
if ($fActive === '') {
    $fActive = 'all';
}

// ── Smazání ──────────────────────────────────────────────────
if (get('action') === 'delete' && get('id')) {
    $id = (int)get('id');
    $row = db_row('SELECT nazev_dilu FROM pracovisite WHERE id=?', [$id]);
    db()->prepare('UPDATE pracovisite SET aktivni=0 WHERE id=?')->execute([$id]);
    logAction(
        'PRACOVISITE_DELETE',
        'pracovisite',
        $id,
        ['aktivni' => 1],
        ['aktivni' => 0],
        "Smazáno: " . ($row['nazev_dilu'] ?? '—')
    );
    redirect(APP_URL . '/admin/pracovisite.php?ok=1');
}

// ── Přepnutí aktivního stavu ─────────────────────────────────
if (get('action') === 'toggle_active' && get('id')) {
    $id = (int)get('id');
    $row = db_row('SELECT aktivni, nazev_dilu FROM pracovisite WHERE id=?', [$id]);
    if ($row) {
        $currentActive = (int)($row['aktivni'] ?? 0);
        $newActive = $currentActive ? 0 : 1;
        db()->prepare('UPDATE pracovisite SET aktivni=? WHERE id=?')->execute([$newActive, $id]);
        logAction(
            'PRACOVISITE_TOGGLE_ACTIVE',
            'pracovisite',
            $id,
            ['aktivni' => $currentActive],
            ['aktivni' => $newActive],
            'Aktivní stav změněn na ' . ($newActive ? 'ano' : 'ne') . ': ' . ($row['nazev_dilu'] ?? '—')
        );
    }
    redirect(APP_URL . '/admin/pracovisite.php?ok=1');
}

// ── Uložení formuláře ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $data = [
        post('cislo_id'), post('zakaznik'), post('projekt'), post('verze'),
        post('kod_pracovisite'), post('nazev_pracovisite'), post('nazev_dilu'),
        post('sap_cislo'), post('nakladove_stredisko') ?: null, post('oznaceni'),
        post('produktivita') ? 1 : 0,
        post('norma_ks_min') !== '' ? (float)post('norma_ks_min') : null,
        post('cyklus_min')   !== '' ? (float)post('cyklus_min')   : null,
        post('typ'), post('poznamka') ?: null,
    ];
    $id = (int)post('id');
    if ($id) {
        $oldRow = db_row('SELECT * FROM pracovisite WHERE id=?', [$id]);
        db()->prepare('UPDATE pracovisite SET cislo_id=?,zakaznik=?,projekt=?,verze=?,kod_pracovisite=?,nazev_pracovisite=?,nazev_dilu=?,sap_cislo=?,nakladove_stredisko=?,oznaceni=?,produktivita=?,norma_ks_min=?,cyklus_min=?,typ=?,poznamka=? WHERE id=?')
            ->execute([...$data, $id]);
        
        $newData = [
            'cislo_id' => $data[0], 'zakaznik' => $data[1], 'projekt' => $data[2],
            'verze' => $data[3], 'kod_pracovisite' => $data[4], 'nazev_pracovisite' => $data[5],
            'nazev_dilu' => $data[6], 'sap_cislo' => $data[7], 'nakladove_stredisko' => $data[8],
            'oznaceni' => $data[9], 'produktivita' => $data[10], 'norma_ks_min' => $data[11],
            'cyklus_min' => $data[12], 'typ' => $data[13], 'poznamka' => $data[14]
        ];
        $changes = diffData($oldRow, $newData);
        
        logAction(
            'PRACOVISITE_UPDATE',
            'pracovisite',
            $id,
            $oldRow,
            $changes,
            "Aktualizováno: {$newData['nazev_dilu']}"
        );
    } else {
        db()->prepare('INSERT INTO pracovisite (cislo_id,zakaznik,projekt,verze,kod_pracovisite,nazev_pracovisite,nazev_dilu,sap_cislo,nakladove_stredisko,oznaceni,produktivita,norma_ks_min,cyklus_min,typ,poznamka) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute($data);
        
        logAction(
            'PRACOVISITE_CREATE',
            'pracovisite',
            db()->lastInsertId(),
            null,
            ['cislo_id' => $data[0], 'nazev_dilu' => $data[6]],
            "Nové pracoviště: {$data[6]}"
        );
    }
    redirect(APP_URL . '/admin/pracovisite.php?ok=1');
}

// ── CSV import ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $imported = 0; $skipped = 0;
    $csvTmp = isset($_FILES['csv_file']) ? validateUploadedCsv($_FILES['csv_file']) : null;
    if ($csvTmp !== null) {
        $handle = fopen($csvTmp, 'r');
        $header = fgetcsv($handle, 0, ';');
        $stmt = db()->prepare('INSERT IGNORE INTO pracovisite (cislo_id,zakaznik,projekt,verze,kod_pracovisite,nazev_pracovisite,nazev_dilu,sap_cislo,nakladove_stredisko,oznaceni,norma_ks_min,cyklus_min,typ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (empty($row[0])) { $skipped++; continue; }
            try {
                $stmt->execute([
                    (int)$row[0], $row[1]??'', $row[2]??'', $row[3]??'',
                    $row[4]??'', $row[5]??'', $row[6]??'', $row[7]??'',
                    $row[8]??null, $row[9]??t('marking_default'),
                    $row[10]!==''?(float)$row[10]:null,
                    $row[11]!==''?(float)$row[11]:null,
                    in_array($row[12]??'serie',['serie','filtry','vstrikovna','obe'], true) ? $row[12] : 'serie',
                ]);
                $imported++;
            } catch (Exception $e) { $skipped++; }
        }
        fclose($handle);
        logAction(
            'CSV_IMPORT',
            'pracovisite',
            null,
            null,
            ['imported' => $imported, 'skipped' => $skipped, 'file' => $_FILES['csv_file']['name'] ?? ''],
            t('import_csv_result') . " $imported " . t('import_rows_imported') . ", $skipped " . t('import_rows_skipped')
        );
        $msg = t('import_ok').': '.$imported.' '.t('import_rows').', '.$skipped.' '.t('import_skip');
    } else {
        $skipped++;
    }
}

// ── Editace ───────────────────────────────────────────────────
$edit = null;
if (get('edit')) $edit = db_row('SELECT * FROM pracovisite WHERE id=?', [(int)get('edit')]);

$where = [];
$params = [];
if ($fSearch !== '') {
    $where[] = '(cislo_id LIKE ? OR zakaznik LIKE ? OR projekt LIKE ? OR verze LIKE ? OR kod_pracovisite LIKE ? OR nazev_pracovisite LIKE ? OR nazev_dilu LIKE ? OR sap_cislo LIKE ?)';
    for ($i = 0; $i < 8; $i++) {
        $params[] = '%'.$fSearch.'%';
    }
}
if (in_array($fTyp, ['serie', 'filtry', 'vstrikovna', 'obe'], true)) {
    $where[] = 'typ = ?';
    $params[] = $fTyp;
}
if ($fActive === '1' || $fActive === '0') {
    $where[] = 'aktivni = ?';
    $params[] = (int)$fActive;
} elseif ($fActive !== 'all') {
    $fActive = 'all';
}
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
$list = db_all('SELECT * FROM pracovisite WHERE ' . $whereSql . ' ORDER BY id', $params);
require APP_ROOT . '/includes/layout.php';
?>
<!-- CSS určené výhradně pro tisk (jednoduchá excelová tabulka) -->
<style>
    .print-only { display: none; }
    @media print {
        /* Skrytí běžného webu */
        .no-print, .topbar, .card-dvd, .alert, .tbl-wrap, form { display: none !important; }
        
        /* Zobrazení tiskového bloku */
        .print-only { display: block !important; }
        
        /* Čištění pozadí prohlížeče */
        body { background: #fff !important; color: #000 !important; }
        .content { padding: 0 !important; margin: 0 !important; border: none !important; }
        
        /* Styly pro čistou jednoduchou tabulku */
        table.print-table { width: 100%; border-collapse: collapse; font-size: 11pt; margin-top: 15px; }
        table.print-table th, table.print-table td { border: 1px solid #000; padding: 6px; text-align: left; }
        table.print-table th { background-color: #f0f0f0 !important; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_pracovisite') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <!-- Tlačítko pro tisk -->
        <button type="button" onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap">
            <i class="bi bi-printer me-1"></i>Tisk
        </button>
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">
<?php if (get('ok')): ?><div class="alert mb-3 no-print" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert mb-3 no-print" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.65rem 1rem;font-size:.82rem"><?= h($msg) ?></div><?php endif; ?>

<div class="row mb-3 no-print">
    <div class="col-12">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Hledat ID, zákazníka, projekt, AFO, název dílu, SAP..." value="<?= h($fSearch) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="typ" class="form-select">
                    <option value="">Typ: vše</option>
                    <option value="serie" <?= $fTyp==='serie'?'selected':'' ?>><?= t('type_serie') ?></option>
                    <option value="filtry" <?= $fTyp==='filtry'?'selected':'' ?>><?= t('type_filtry') ?></option>
                    <option value="vstrikovna" <?= $fTyp==='vstrikovna'?'selected':'' ?>><?= t('type_vstrikovna') ?></option>
                    <option value="obe" <?= $fTyp==='obe'?'selected':'' ?>><?= t('type_both') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="active" class="form-select">
                    <option value="all" <?= $fActive==='all'?'selected':'' ?>>Stav: vše</option>
                    <option value="1" <?= $fActive==='1'?'selected':'' ?>><?= t('active') ?></option>
                    <option value="0" <?= $fActive==='0'?'selected':'' ?>><?= t('inactive') ?></option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-search me-1"></i>Filtrovat</button>
                <?php if ($fSearch !== '' || $fTyp !== '' || $fActive !== '1'): ?>
                    <a href="pracovisite.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- CSV Import -->
<div class="card-dvd mb-4 no-print">
    <div class="card-header-dvd" data-collapsible><span class="s-num">⬆</span><span class="s-title"><?= t('import_csv') ?> – <?= t('import_csv_workcenter') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
    <div class="card-body-dvd" style="display:none">
        <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="csv_import">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label-dvd"><?= t('import_select') ?> <small class="text-muted-dvd">(sep. ;)</small></label>
                <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
            </div>
            <div class="col-auto d-flex gap-2">
                <a href="<?= APP_URL ?>/assets/templates/import_pracovisite_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download>
                    <i class="bi bi-download me-1"></i>Vzor CSV
                </a>
                <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
            </div>
        </div>
        <div class="mt-2" style="font-size:.72rem;color:var(--muted)">
            <?= t('import_format_workplace') ?>
        </div>
        </form>
    </div>
</div>

<!-- Formulář přidat/editovat -->
<?php if ($edit !== null): 
    $isNew = $edit === false || get('edit')==='new'; 
    $e = $edit ?: [];
?>
<div class="card-dvd mb-4 no-print">
    <div class="card-header-dvd"><span class="s-num"><?= $isNew ? t('novy_item') : t('edit') ?></span><span class="s-title"><?= $isNew ? t('add').' '.t('pracovisite') : t('edit').' #'.h((string)($e['id']??'')) ?></span></div>
    <div class="card-body-dvd">
    <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int)($e['id']??0) ?>">
    <div class="row g-3">
        <?php
        $ff = [
            ['cislo_id',t('id_number'),'number',true],['zakaznik',t('customer'),'text',true],
            ['projekt',t('projekt'),'text',false],['verze',t('verze'),'text',false],
            ['kod_pracovisite',t('code_workplace'),'text',false],
            ['nazev_pracovisite',t('workplace_name'),'text',false],
            ['nazev_dilu',t('nazev_dilu'),'text',true],['sap_cislo',t('sap_number'),'text',false],
            ['nakladove_stredisko',t('cost_center'),'number',false],
            ['oznaceni',t('marking'),'text',false],
            ['norma_ks_min',t('norm_ks_min'),'number',false],
            ['cyklus_min',t('cycle_time'),'number',false],
        ];
        foreach ($ff as [$k,$l,$t2,$req]): ?>
        <div class="col-6 col-md-3">
            <label class="form-label-dvd"><?= $l ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
            <input type="<?= $t2 ?>" name="<?= $k ?>" class="form-control" value="<?= h((string)($e[$k]??'')) ?>" <?= $req ? 'required' : '' ?> step="any">
        </div>
        <?php endforeach; ?>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('typ') ?></label>
            <select name="typ" class="form-select">
                <option value="serie" <?= ($e['typ']??'serie')==='serie' ? 'selected' : '' ?>><?= t('type_serie') ?></option>
                <option value="filtry" <?= ($e['typ']??'serie')==='filtry' ? 'selected' : '' ?>><?= t('type_filtry') ?></option>
                <option value="vstrikovna" <?= ($e['typ']??'serie')==='vstrikovna' ? 'selected' : '' ?>><?= t('type_vstrikovna') ?></option>
                <option value="obe" <?= ($e['typ']??'serie')==='obe' ? 'selected' : '' ?>><?= t('type_both') ?></option>
            </select>
        </div>
        <div class="col-6 col-md-2 d-flex align-items-end pb-1">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="produktivita" id="chk-prod" <?= ($e['produktivita']??1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="chk-prod" style="font-size:.8rem"><?= t('productive') ?></label></div>
        </div>
        <div class="col-12">
            <label class="form-label-dvd"><?= t('note') ?></label>
            <input type="text" name="poznamka" class="form-control" value="<?= h($e['poznamka']??'') ?>">
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('save') ?></button>
            <a href="pracovisite.php" class="btn-outline" style="padding:.35rem .9rem;font-size:.78rem"><?= t('cancel') ?></a>
        </div>
    </div>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- Speciální formát tabulky určený VÝHRADNĚ PRO TISK -->
<div class="print-only">
    <h2 style="margin:0 0 5px 0; font-size:16pt;">Seznam pracovišť</h2>
    <?php if ($fSearch || $fTyp || $fActive !== 'all'): ?>
        <p style="margin: 0 0 10px 0; font-size:10pt; color:#444;">
            Aktivní filtry: <?= $fSearch ? 'Hledání: "'.h($fSearch).'" ' : '' ?>
            <?= $fTyp ? 'Typ: '.h($fTyp).' ' : '' ?>
            <?= $fActive !== 'all' ? 'Stav: '.($fActive==='1'?'Aktivní':'Neaktivní') : '' ?>
        </p>
    <?php endif; ?>
    
    <table class="print-table">
        <thead>
            <tr>
                <th>ID pracoviště</th>
                <th>Zákazník</th>
                <th>Projekt</th>
                <th>AFO pracoviště</th>
                <th>Název dílu</th>
                <th>SAP číslo dílu</th>
                <th>Verze</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($list as $p): ?>
            <tr>
                <td><?= h($p['cislo_id']) ?></td>
                <td><?= h($p['zakaznik']) ?></td>
                <td><?= h($p['projekt']) ?></td>
                <td><?= h($p['kod_pracovisite']) ?></td>
                <td><?= h($p['nazev_dilu']) ?></td>
                <td><?= h($p['sap_cislo']) ?></td>
                <td><?= h($p['verze']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Standardní Seznam tabulka pro webové rozhraní -->
<div class="tbl-wrap">
<div class="overflow-x">
<table class="tbl">
    <thead><tr><th>ID</th><th><?= t('customer') ?></th><th><?= t('projekt') ?>/<?= t('verze') ?></th><th>AFO</th><th><?= t('nazev_dilu') ?></th><th>SAP</th><th>NS</th><th><?= t('typ') ?></th><th><?= t('norm_ks_min') ?></th><th><?= t('active') ?></th><th><?= t('actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
    <tr>
        <td class="mono"><?= $p['cislo_id'] ?></td>
        <td><?= h($p['zakaznik']) ?></td>
        <td><?= h($p['projekt']) ?><br><small class="text-muted-dvd"><?= h($p['verze']) ?></small></td>
        <td class="mono"><?= h($p['kod_pracovisite']) ?><br><small class="text-muted-dvd"><?= h($p['nazev_pracovisite']) ?></small></td>
        <td><?= h($p['nazev_dilu']) ?><?php if (!$p['aktivni'] && str_contains((string)($p['poznamka'] ?? ''), 'Sloučeno do pracoviště ID')): ?><br><small class="text-muted-dvd"><?= h($p['poznamka']) ?></small><?php endif; ?></td>
        <td class="mono" style="font-size:.72rem"><?= h($p['sap_cislo']) ?></td>
        <td class="mono" style="font-size:.72rem"><?= $p['nakladove_stredisko'] ?></td>
        <td><span class="badge-<?= $p['typ'] ?>"><?= $p['typ'] ?></span></td>
        <td class="mono" style="font-size:.72rem"><?= $p['norma_ks_min'] ? number_format($p['norma_ks_min'],4) : '—' ?></td>
        <td>
            <?php $nextActive = $p['aktivni'] ? 'ne' : 'ano'; ?>
            <a
                href="?action=toggle_active&id=<?= $p['id'] ?>"
                class="<?= $p['aktivni'] ? 'badge-ok' : 'badge-nok' ?>"
                data-confirm="<?= h('Opravdu změnit aktivní stav na ' . $nextActive . '?') ?>"
                style="text-decoration:none;display:inline-block"
                title="<?= h('Kliknutím přepnete stav na ' . $nextActive) ?>"
            >
                <?= $p['aktivni'] ? t('ano') : t('inactive') ?>
            </a>
        </td>
        <td>
            <a href="?edit=<?= $p['id'] ?>" class="btn-icon" title="<?= t('edit') ?>"><i class="bi bi-pencil"></i></a>
            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>" title="<?= t('delete') ?>"><i class="bi bi-trash3"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer"><span><?= count($list) ?> <?= count($list)==1?t('plural_record'):t('plural_records') ?></span></div>
</div>
</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>