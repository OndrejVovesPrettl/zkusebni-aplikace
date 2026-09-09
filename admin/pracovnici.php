<?php
// admin/pracovnici.php
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_workers'); $activePage = 'pracovnici';

function normalizeCsvValue(?string $value): ?string {
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

function findPracovnikByName(string $jmeno, ?int $excludeId = null): array|false {
    $sql = 'SELECT * FROM pracovnici WHERE jmeno = ?';
    $params = [$jmeno];

    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $sql .= ' ORDER BY aktivni DESC, id ASC LIMIT 1';
    return db_row($sql, $params);
}

function parseIkosWorkerLineForReplace(string $line): ?array {
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $cols = str_getcsv($line, ';');
    $interniId = normalizeCsvValue($cols[0] ?? null);
    $jmeno = normalizeCsvValue($cols[1] ?? null);
    $smennost = normalizeCsvValue($cols[2] ?? null);

    if ($smennost === '0' || $smennost === '') {
        $smennost = null;
    }

    if ($interniId === null || $interniId === '' || $jmeno === null || $jmeno === '') {
        return null;
    }

    return [
        'interni_id' => $interniId,
        'jmeno' => $jmeno,
        'smennost' => $smennost,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'sync_replace_workers') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }

    $sourceFile = APP_ROOT . '/uploads/export_ikos.txt';
    if (!is_file($sourceFile) || !is_readable($sourceFile)) {
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }

    $rows = [];
    $seenInterniIds = [];
    $skip = 0;
    $imp = 0;

    $handle = fopen($sourceFile, 'rb');
    if ($handle === false) {
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }

    while (($line = fgets($handle)) !== false) {
        $parsed = parseIkosWorkerLineForReplace($line);
        if ($parsed === null) {
            $skip++;
            continue;
        }

        if (isset($seenInterniIds[$parsed['interni_id']])) {
            $skip++;
            continue;
        }
        $seenInterniIds[$parsed['interni_id']] = true;
        $rows[] = $parsed;
    }
    fclose($handle);

    try {
        db()->beginTransaction();

        // Full replace mode: remove all current rows and import the file content.
        db()->exec('DELETE FROM pracovnici');

        $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id, jmeno, smennost, aktivni) VALUES (?, ?, ?, 1)');

        foreach ($rows as $row) {
            $interniId = $row['interni_id'];
            $jmeno = $row['jmeno'];
            $smennost = $row['smennost'];

            $insertStmt->execute([$interniId, $jmeno, $smennost]);
            $imp += 1;
        }

        db()->commit();

        $stateFile = APP_ROOT . '/uploads/tmp/sync_ikos_workers_state.json';
        @file_put_contents($stateFile, json_encode([
            'source_file' => $sourceFile,
            'source_file_mtime' => @filemtime($sourceFile) ?: null,
            'source_file_mtime_iso' => @filemtime($sourceFile) ? date('c', (int)@filemtime($sourceFile)) : null,
            'last_run_at' => date('c'),
            'last_success_at' => date('c'),
            'last_skip_reason' => null,
            'last_result' => [
                'ok' => true,
                'inserted' => $imp,
                'skipped_invalid' => $skip,
                'mode' => 'full_replace',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        logAction(
            'SYNC_IKOS_WORKERS',
            'pracovnici',
            null,
            null,
            ['inserted' => $imp, 'skipped' => $skip, 'mode' => 'full_replace'],
            'Rucni sync pracovniku (full replace ze souboru)'
        );

        redirect(APP_URL . '/admin/pracovnici.php?sync=1&imp=' . $imp . '&skip=' . $skip);
    } catch (Throwable) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }
}

if (get('action') === 'delete' && get('id')) {
    db()->prepare('UPDATE pracovnici SET aktivni=0 WHERE id=?')->execute([(int)get('id')]);
    redirect(APP_URL.'/admin/pracovnici.php?ok=1');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $id = (int)post('id');
    $interniId = trim((string)post('interni_id'));
    $jmeno = trim((string)post('jmeno'));
    $smennost = post('smennost')?: null;
    $aktivni = post('aktivni')?1:0;
    $data = [$interniId, $jmeno, $smennost, $aktivni];

    if ($id) {
        db()->prepare('UPDATE pracovnici SET interni_id=?,jmeno=?,smennost=?,aktivni=? WHERE id=?')->execute([...$data,$id]);
    } else {
        $existing = $jmeno !== '' ? findPracovnikByName($jmeno) : false;
        if ($existing) {
            db()->prepare('UPDATE pracovnici SET interni_id=?, smennost=?, aktivni=? WHERE id=?')->execute([$interniId, $smennost, $aktivni, (int)$existing['id']]);
        } else {
            db()->prepare('INSERT IGNORE INTO pracovnici (interni_id,jmeno,smennost,aktivni) VALUES (?,?,?,?)')->execute($data);
        }
    }
    redirect(APP_URL.'/admin/pracovnici.php?ok=1');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='csv_import') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $imp=0; $skip=0;
    $csvTmp = isset($_FILES['csv_file']) ? validateUploadedCsv($_FILES['csv_file']) : null;
    if ($csvTmp !== null) {
        $rowsToImport = [];
        $h=fopen($csvTmp,'r');
        if ($h !== false) {
            fgetcsv($h,0,';');
        }
        while($h !== false && ($r=fgetcsv($h,0,';'))!==false) {
            $interniId = normalizeCsvValue($r[0] ?? null);
            $jmeno = normalizeCsvValue($r[1] ?? null);
            $smennost = normalizeCsvValue($r[2] ?? null) ?: null;

            if ($interniId === null || $interniId === '' || $jmeno === null || $jmeno === '') { $skip++; continue; }
            $rowsToImport[] = [$interniId, $jmeno, $smennost];
        }

        if ($h !== false) {
            fclose($h);
        }

        // Keep only the first occurrence of each interni_id to satisfy unique index.
        $seenInterniIds = [];
        $dedupedRows = [];
        foreach ($rowsToImport as $rowData) {
            $interniId = (string)$rowData[0];
            if (isset($seenInterniIds[$interniId])) {
                $skip++;
                continue;
            }
            $seenInterniIds[$interniId] = true;
            $dedupedRows[] = $rowData;
        }

        try {
            db()->beginTransaction();
            db()->exec('DELETE FROM pracovnici');
            $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id,jmeno,smennost,aktivni) VALUES (?,?,?,1)');
            foreach ($dedupedRows as $rowData) {
                $insertStmt->execute($rowData);
                $imp += 1;
            }
            db()->commit();
        } catch (Throwable) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $skip += count($dedupedRows);
            $imp = 0;
        }
    } else {
        $skip++;
    }
    $msg = t('import_ok').': '.$imp.' '.t('import_rows').', '.$skip.' '.t('import_skip');
}
$edit = null;
if (get('edit')) $edit = get('edit')==='new' ? [] : db_row('SELECT * FROM pracovnici WHERE id=?',[(int)get('edit')]);
$fSearch = trim((string)get('search', ''));
$fActive = (string)get('active', 'all');
$where = [];
$params = [];
if ($fSearch !== '') {
    $where[] = '(interni_id LIKE ? OR jmeno LIKE ? OR smennost LIKE ?)';
    $params[] = '%'.$fSearch.'%';
    $params[] = '%'.$fSearch.'%';
    $params[] = '%'.$fSearch.'%';
}
if ($fActive === '1' || $fActive === '0') {
    $where[] = 'aktivni = ?';
    $params[] = (int)$fActive;
}
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
$list = db_all('SELECT * FROM pracovnici WHERE ' . $whereSql . ' ORDER BY jmeno', $params);

$ikosSyncState = [];
$ikosSyncAt = null;
$ikosSyncStateFile = APP_ROOT . '/uploads/tmp/sync_ikos_workers_state.json';
if (is_file($ikosSyncStateFile)) {
    $ikosSyncState = (array)json_decode((string)file_get_contents($ikosSyncStateFile), true);
    $ikosSyncAt = (string)($ikosSyncState['last_success_at'] ?? $ikosSyncState['last_run_at'] ?? '');
}
$ikosSyncLabel = $ikosSyncAt ? h(date('d.m.Y H:i', strtotime($ikosSyncAt))) : '—';

if (get('sync') === '1') {
    $msg = 'Synchronizace dokončena: ' . (int)get('imp', 0) . ' záznamů vloženo, ' . (int)get('skip', 0) . ' přeskočeno.';
} elseif (get('sync') === '0') {
    $msg = 'Synchronizace se nepodařila.';
}

require APP_ROOT.'/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_workers') ?></span>
        <?php if (isSuperAdmin()): ?>
        <form method="post" class="d-inline no-print" style="margin:0">
            <input type="hidden" name="action" value="sync_replace_workers">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <button
                type="submit"
                class="topbar-meta"
                style="text-decoration:none;background:none;border:none;padding:0;cursor:pointer"
                title="<?= t('sync_run_now') ?>"
                data-confirm="Opravdu refreshnout operátory podle interního ID (1. sloupec CSV)?"
            ><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $ikosSyncLabel ?></strong></button>
        </form>
        <?php else: ?>
        <span class="topbar-meta"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $ikosSyncLabel ?></strong></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?edit=new" class="btn-dvd btn-dvd-sm"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></a>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">
<?php if (get('ok')): ?><div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div><?php endif; ?>
<?php if (isset($msg)): ?><div class="alert mb-3" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.65rem 1rem;font-size:.82rem"><?= h($msg) ?></div><?php endif; ?>

<div class="row mb-3 no-print">
    <div class="col-12">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <input type="text" name="search" class="form-control" placeholder="Hledat interní ID, jméno nebo směnnost" value="<?= h($fSearch) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="active" class="form-select">
                    <option value="all" <?= $fActive==='all'?'selected':'' ?>>Stav: vše</option>
                    <option value="1" <?= $fActive==='1'?'selected':'' ?>><?= t('active') ?></option>
                    <option value="0" <?= $fActive==='0'?'selected':'' ?>><?= t('inactive') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-search me-1"></i>Filtrovat</button>
                <?php if ($fSearch !== '' || $fActive !== 'all'): ?><a href="pracovnici.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible><span class="s-num">⬆</span><span class="s-title"><?= t('import_csv') ?> – <?= t('import_csv_workers') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
    <div class="card-body-dvd" style="display:none">
        <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="csv_import">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-6"><label class="form-label-dvd"><?= t('import_select') ?></label><input type="file" name="csv_file" class="form-control" accept=".csv" required></div>
            <div class="col-auto d-flex gap-2">
                <a href="<?= APP_URL ?>/assets/templates/import_pracovnici_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download>
                    <i class="bi bi-download me-1"></i>Vzor CSV
                </a>
                <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
            </div>
        </div>
        <div class="mt-2" style="font-size:.7rem;color:var(--muted)"><?= t('format_workers') ?></div>
        </form>
    </div>
</div>

<?php if ($edit!==null): $e=$edit; ?>
<div class="card-dvd mb-4">
    <div class="card-header-dvd"><span class="s-num"><?= empty($e)?t('novy_item'):t('edit') ?></span><span class="s-title"><?= empty($e)?t('add'):t('edit') ?></span></div>
    <div class="card-body-dvd">
    <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int)($e['id']??0) ?>">
    <div class="row g-3">
        <div class="col-6 col-md-3"><label class="form-label-dvd"><?= t('internal_id') ?> <span class="req">*</span></label><input type="text" name="interni_id" class="form-control" value="<?= h($e['interni_id']??'') ?>" required></div>
        <div class="col-6 col-md-4"><label class="form-label-dvd"><?= t('name') ?> <span class="req">*</span></label><input type="text" name="jmeno" class="form-control" value="<?= h($e['jmeno']??'') ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label-dvd"><?= t('shift_assignment') ?></label>
            <select name="smennost" class="form-select">
                <option value="">—</option>
                <?php foreach([t('shift_a'),t('shift_b'),t('shift_c'),t('shift_d')] as $s): ?><option <?=($e['smennost']??'')===$s?'selected':''?>><?= $s ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2 d-flex align-items-end pb-1">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="aktivni" <?=($e['aktivni']??1)?'checked':''?>>
            <label class="form-check-label" style="font-size:.8rem"><?= t('active') ?></label></div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('save') ?></button>
            <a href="pracovnici.php" class="btn-outline" style="padding:.35rem .9rem;font-size:.78rem"><?= t('cancel') ?></a>
        </div>
    </div>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="tbl-wrap">
<div class="overflow-x">
<table class="tbl">
    <thead><tr><th><?= t('short_id') ?></th><th><?= t('name') ?></th><th><?= t('shift_assignment') ?></th><th><?= t('active') ?></th><th><?= t('actions') ?></th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
    <tr>
        <td class="mono"><?= h($p['interni_id']) ?></td>
        <td><?= h($p['jmeno']) ?></td>
        <td><?= h($p['smennost']??'—') ?></td>
        <td><?= $p['aktivni'] ? '<span class="badge-ok">'.t('ano').'</span>' : '<span class="badge-nok">'.t('ne').'</span>' ?></td>
        <td>
            <a href="?edit=<?= $p['id'] ?>" class="btn-icon"><i class="bi bi-pencil"></i></a>
            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>"><i class="bi bi-trash3"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer"><span><?= count($list) ?> <?= count($list)==1?t('plural_worker'):t('plural_workers') ?></span></div>
</div>
</div>
<?php require APP_ROOT.'/includes/layout_end.php'; ?>
