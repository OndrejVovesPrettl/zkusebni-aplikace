<?php
// admin/import.php – Import z Excel DVD-2025-odemčená.xlsx
require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/import_runtime.php';
requireRole('superadmin');
$pageTitle = t('import_title'); $activePage = 'import';
$msg = ''; $preview = []; $importedCount = 0; $skippedCount = 0;
$pythonCommand = getPythonImportCommand();

function decodePythonJsonOutput(string $output): array {
    $decoded = json_decode($output, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $normalized = @iconv('Windows-1250', 'UTF-8//IGNORE', $output);
    if (is_string($normalized) && $normalized !== '') {
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [
        'error' => 'Neplatný JSON z Pythonu: ' . json_last_error_msg(),
        'raw_output' => $output,
    ];
}

function getLastAutoImportResult(): ?array {
    $pattern = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'auto_import_dvd_*.lock.json';
    $files = glob($pattern) ?: [];
    if (empty($files)) {
        return null;
    }

    usort(
        $files,
        static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a)
    );

    $content = @file_get_contents($files[0]);
    if (!is_string($content) || trim($content) === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : null;
}

function getAutoImportSourceInfo(): array {
    $sourceFile = getenv('DVD_AUTO_IMPORT_SOURCE') ?: '\\\\lbfile01.czlb-pms.prettl.pgn\\pacz\\Departments\\DVD\\DVD-2026.xlsx';
    $mtime = @filemtime($sourceFile);
    $mtime = ($mtime !== false) ? (int)$mtime : null;

    return [
        'path' => $sourceFile,
        'exists' => is_file($sourceFile),
        'readable' => is_readable($sourceFile),
        'mtime' => $mtime,
        'mtime_iso' => $mtime ? date('c', $mtime) : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $action = post('action');

    if ($pythonCommand === null) {
        $msg = t('import_error').': Python interpreter nebyl nalezen.';
    }

    if ($pythonCommand !== null && $action === 'upload' && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        $tmpPath = UPLOAD_DIR . 'import_' . bin2hex(random_bytes(8)) . '_' . basename($_FILES['excel_file']['name']);
        move_uploaded_file($_FILES['excel_file']['tmp_name'], $tmpPath);
        $_SESSION['import_file'] = $tmpPath;

        // Python preview – prvních 20 řádků
        $script = APP_ROOT . '/includes/import_excel.py';
        $jsonOut = shell_exec($pythonCommand.' '.escapeshellarg($script).' preview '.escapeshellarg($tmpPath).' 2>&1');
        $data = decodePythonJsonOutput($jsonOut ?: '{}');
        if (!empty($data['rows'])) {
            $preview = $data['rows'];
            $_SESSION['import_preview'] = $preview;
        } else {
            $msg = t('file_load_error').': '.($data['error'] ?? t('unknown_error')).'<br><small>'.htmlspecialchars($data['raw_output'] ?? ($jsonOut ?? '')).'</small>';
        }
    }

    if ($pythonCommand !== null && $action === 'run_import' && !empty($_SESSION['import_file']) && file_exists($_SESSION['import_file'])) {
        $script = APP_ROOT . '/includes/import_excel.py';
        $jsonOut = shell_exec($pythonCommand.' '.escapeshellarg($script).' import '.escapeshellarg($_SESSION['import_file']).' 2>&1');
        $data = decodePythonJsonOutput($jsonOut ?: '{}');
        if (!empty($data['imported'])) {
            $importedCount = 0;
            $skippedCount  = (int)($data['skipped']??0);
            // Vložit záznamy z JSON do DB
            if (!empty($data['rows'])) {
                $stmt = db()->prepare("INSERT IGNORE INTO zapisy
                    (typ,pracovisite_id,datum,cw,rok,mesic,smena,zakaznik,projekt,verze,
                     kod_pracovisite,nazev_pracovisite,nazev_dilu,sap_cislo,
                     nakladove_stredisko,jmeno,pracovni_pozice,
                     vyrobeno_ks,nok_ks,k_posouzeni,vyrobeno_dobre_ks,
                     cisty_cas_min,pracovni_doba_min,prestas_min,
                     oee_dostupnost,oee_vykon,oee_kvalita,oee_procent,
                     norma_ks,cycle_time_min,final_typ,zapsal,imported,import_hash)
                    VALUES
                    (:typ,:prac_id,:datum,:cw,:rok,:mesic,:smena,:zakaznik,:projekt,:verze,
                     :kp,:np,:nd,:sap,:ns,:jmeno,:pozice,
                     :vyr,:nok,:pos,:dobre,
                     :cisty,:prac_doba,:prestas,
                     :oee_a,:oee_p,:oee_q,:oee,:norma,:cycle,:final,:zapsal,1,:import_hash)");
                foreach ($data['rows'] as $r) {
                    try {
                        $prepared = importPrepareZapisyRow($r);
                        $stmt->execute(importSqlBindParamsForZapisyInsert($prepared));
                        $affected = (int)$stmt->rowCount();
                        $importedCount += $affected;
                        if ($affected > 0) {
                            $zapisId = (int)db()->lastInsertId();
                            syncZapisyPracovnici(db(), $zapisId, buildZapisyWorkerRowsFromSummary(
                                (string)($prepared[':jmeno'] ?? ''),
                                (string)($prepared[':pozice'] ?? '')
                            ));
                            saveZapisyProstoje(db(), $zapisId, [
                                'p1' => (int)($prepared[':p1'] ?? 0), 'p2' => (int)($prepared[':p2'] ?? 0),
                                'p3' => (int)($prepared[':p3'] ?? 0), 'p4' => (int)($prepared[':p4'] ?? 0),
                                'p5' => (int)($prepared[':p5'] ?? 0), 'p6' => (int)($prepared[':p6'] ?? 0),
                                'p7' => (int)($prepared[':p7'] ?? 0), 'p8' => (int)($prepared[':p8'] ?? 0),
                                'p9' => (int)($prepared[':p9'] ?? 0), 'p10' => (int)($prepared[':p10'] ?? 0),
                                'o1' => (int)($prepared[':o1'] ?? 0),
                                'l1' => (int)($prepared[':l1'] ?? 0), 'l2' => (int)($prepared[':l2'] ?? 0),
                                'v1' => (int)($prepared[':v1'] ?? 0), 'v2' => (int)($prepared[':v2'] ?? 0), 'v3' => (int)($prepared[':v3'] ?? 0),
                                'q1' => (int)($prepared[':q1'] ?? 0), 'q2' => (int)($prepared[':q2'] ?? 0),
                                'q3' => (int)($prepared[':q3'] ?? 0), 'q4' => (int)($prepared[':q4'] ?? 0),
                                'prostoj_technicky' => (int)($prepared[':pt'] ?? 0),
                                'prostoj_vyrobni' => (int)($prepared[':pv'] ?? 0),
                                'prostoj_kvalitativni' => (int)($prepared[':pk'] ?? 0),
                                'prostoj_logisticky' => (int)($prepared[':pl'] ?? 0),
                                'prostoj_ostatni' => (int)($prepared[':po'] ?? 0),
                                'prostoj_planovany' => (int)($prepared[':pp'] ?? 0),
                            ]);
                        }
                    } catch (Exception) {
                        $skippedCount++;
                    }
                }
            }
            @unlink($_SESSION['import_file']);
            unset($_SESSION['import_file'], $_SESSION['import_preview']);
            $msg = '<strong>'.t('import_ok').'</strong>: '.$importedCount.' '.t('import_rows').', '.$skippedCount.' '.t('import_skip');
        } else {
            $msg = t('import_error').': '.($data['error'] ?? '').' '.htmlspecialchars($data['raw_output'] ?? ($jsonOut ?? ''));
        }
    }
}

$preview = $preview ?: ($_SESSION['import_preview'] ?? []);
$lastAutoImport = getLastAutoImportResult();
$autoImportSource = getAutoImportSourceInfo();
$lastSourceMtime = isset($lastAutoImport['source_file_mtime']) ? (int)$lastAutoImport['source_file_mtime'] : null;
$currentSourceMtime = $autoImportSource['mtime'];
$sourceChangedSinceLast = null;
if ($lastSourceMtime !== null && $currentSourceMtime !== null) {
    $sourceChangedSinceLast = $currentSourceMtime > $lastSourceMtime;
}
require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('import_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">

<?php if ($msg): ?>
<div class="alert mb-4" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.75rem 1rem;font-size:.84rem"><?= $msg ?></div>
<?php endif; ?>

<div class="row g-4">
<div class="col-12 col-xl-5">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">1</span><span class="s-title"><?= t('import_select') ?></span></div>
    <div class="card-body-dvd">
        <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <div class="mb-3">
            <label class="form-label-dvd"><?= t('import_file_format') ?> <span class="req">*</span></label>
            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
            <div class="mt-2" style="font-size:.75rem;color:var(--muted)">
                <a href="<?= APP_URL ?>/assets/templates/import_template.csv" class="btn-outline" style="padding:.2rem .55rem;font-size:.72rem">
                    <i class="bi bi-download me-1"></i><?= t('import_csv_template_download') ?>
                </a>
                <span class="ms-2"><?= t('import_csv_template_hint') ?></span>
            </div>
        </div>
        <div class="mb-3" style="font-size:.78rem;color:var(--muted)">
            <strong style="color:var(--text)"><?= t('supported_format') ?>:</strong><br>
            <?= t('import_sheet_info') ?><br>
            <?= t('import_auto_columns') ?>
        </div>
        <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-eye me-1"></i><?= t('import_preview') ?></button>
        </form>
    </div>
</div>
</div>

<div class="col-12 col-xl-7">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">A</span><span class="s-title"><?= t('auto_import_title') ?></span></div>
    <div class="card-body-dvd">
        <div style="font-size:.8rem;color:var(--muted);margin-bottom:.7rem">
            <?= t('auto_import_description') ?><br>
            <code style="font-size:.72rem;color:var(--muted)"><?= h($autoImportSource['path']) ?></code>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= APP_URL ?>/scripts/auto_import_dvd.php" target="_blank" class="btn-dvd btn-dvd-sm">
                <i class="bi bi-play-circle me-1"></i><?= t('auto_import_run') ?>
            </a>
            <a href="<?= APP_URL ?>/scripts/auto_import_dvd.php?force=1" target="_blank" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">
                <i class="bi bi-arrow-repeat me-1"></i><?= t('auto_import_force') ?>
            </a>
        </div>
        <div style="font-size:.72rem;color:var(--muted);margin-top:.65rem">
            <?= t('auto_import_lock_hint') ?>
        </div>
        <div style="margin-top:.75rem;padding-top:.65rem;border-top:1px solid var(--border);font-size:.75rem;color:var(--muted)">
            <strong style="color:var(--text)"><?= t('auto_import_last_run') ?>:</strong>
            <?php if ($lastAutoImport): ?>
                <div class="mt-1">
                    <span class="mono"><?= h((string)($lastAutoImport['ran_at'] ?? '—')) ?></span>
                    | <?= t('auto_import_stats') ?>:
                    <?= t('import_rows') ?> <strong><?= (int)($lastAutoImport['inserted'] ?? 0) ?></strong>,
                    <?= t('import_skip') ?> <strong><?= (int)(($lastAutoImport['duplicates'] ?? 0) + ($lastAutoImport['parser_skipped'] ?? 0) + ($lastAutoImport['db_errors'] ?? 0)) ?></strong>
                </div>
                <div class="mt-2" style="display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;">
                    <strong style="color:var(--text)">Kontrola změny souboru:</strong>
                    <?php if (!$autoImportSource['exists']): ?>
                        <span class="badge-nok">Zdrojový soubor nenalezen</span>
                    <?php elseif (!$autoImportSource['readable']): ?>
                        <span class="badge-nok">Zdrojový soubor není čitelný</span>
                    <?php elseif ($sourceChangedSinceLast === true): ?>
                        <span class="badge-ok">Soubor byl od posledního importu upraven</span>
                    <?php elseif ($sourceChangedSinceLast === false): ?>
                        <span class="badge-filtry">Soubor nebyl od posledního importu upraven</span>
                    <?php else: ?>
                        <span class="badge-serie">Nelze porovnat (chybí metadata staršího běhu)</span>
                    <?php endif; ?>
                </div>
                <div class="mt-1" style="font-size:.72rem;color:var(--muted)">
                    Poslední změna zdroje: <span class="mono"><?= h((string)($autoImportSource['mtime_iso'] ?? '—')) ?></span>
                    <?php if (!empty($lastAutoImport['source_file_mtime_iso'])): ?>
                        | Stav při posledním importu: <span class="mono"><?= h((string)$lastAutoImport['source_file_mtime_iso']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <span><?= t('auto_import_no_run') ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php if (!empty($preview)): ?>
<div class="col-12 col-xl-7">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">2</span><span class="s-title"><?= t('import_preview') ?> – <?= t('first_rows') ?> <?= count($preview) ?> <?= t('rows_abbreviation') ?></span></div>
    <div class="card-body-dvd p-0">
        <div style="overflow:auto;max-height:350px">
        <table class="ptbl">
            <thead><tr><th><?= t('row_datum') ?></th><th><?= t('row_customer') ?></th><th><?= t('row_part') ?></th><th><?= t('row_shift') ?></th><th><?= t('row_produced') ?></th><th><?= t('row_nok') ?></th><th>OEE%</th></tr></thead>
            <tbody>
            <?php foreach($preview as $row): ?>
            <tr>
                <td class="mono" style="font-size:.72rem"><?= h($row['datum']??'') ?></td>
                <td><?= h($row['zakaznik']??'') ?></td>
                <td><?= h($row['nazev_dilu']??'') ?></td>
                <td class="mono"><?= h($row['smena']??'') ?></td>
                <td class="mono"><?= number_format((int)($row['vyr']??0)) ?></td>
                <td class="mono"><?= (int)($row['nok']??0) ?></td>
                <td class="mono"><?= isset($row['oee'])&&$row['oee']!==null?round($row['oee']*100,1).'%':'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="padding:.75rem 1rem;border-top:1px solid var(--border);display:flex;gap:.75rem;align-items:center">
            <form method="post">
            <input type="hidden" name="action" value="run_import">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <button type="submit" class="btn-dvd" style="background:linear-gradient(135deg,var(--accent3),#3b6de8)">
                <i class="bi bi-cloud-arrow-up me-2"></i><?= t('import_run') ?>
            </button>
            </form>
            <span style="font-size:.75rem;color:var(--muted)"><?= t('import_description') ?></span>
        </div>
    </div>
</div>
</div>
<?php endif; ?>
</div>

<!-- Info -->
<div class="card-dvd mt-4">
    <div class="card-header-dvd"><span class="s-num">ℹ</span><span class="s-title"><?= t('import_requirements') ?></span></div>
    <div class="card-body-dvd" style="font-size:.82rem">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <strong style="color:var(--accent)"><?= t('python_version') ?></strong><br>
                <code style="font-size:.72rem;color:var(--muted)">pip install openpyxl</code>
            </div>
            <div class="col-12 col-md-4">
                <strong style="color:var(--accent)"><?= t('python_script') ?></strong><br>
                <code style="font-size:.72rem;color:var(--muted)"><?= h($pythonCommand ?? 'Python nenalezen') ?></code>
            </div>
            <div class="col-12 col-md-4">
                <strong style="color:var(--accent)"><?= t('upload_folder') ?></strong><br>
                <code style="font-size:.72rem;color:var(--muted)"><?= UPLOAD_DIR ?></code>
                <?= is_writable(UPLOAD_DIR) ? '<span class="badge-ok ms-1">'.t('writable').'</span>' : '<span class="badge-nok ms-1">'.t('not_writable').'</span>' ?>
            </div>
        </div>
    </div>
</div>

</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
