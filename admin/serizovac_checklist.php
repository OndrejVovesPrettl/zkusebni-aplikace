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

// Zjištění názvu aktuálního projektu
$currentProjectLabel = '';
foreach ($projects as $p) {
    if ((string)$p['project_key'] === $currentProject) {
        $currentProjectLabel = (string)$p['project_label'];
        break;
    }
}
if ($currentProjectLabel === '' && $currentProject !== '') {
    $currentProjectLabel = (string)db_val(
        'SELECT MAX(project_label) FROM serizovac_checklist_catalog WHERE project_key = ?',
        [$currentProject]
    );
}

// Rozpoznání zda jde o Gen3 nebo 48W
$isGen3 = stripos($currentProject, 'gen3') !== false || stripos($currentProjectLabel, 'gen3') !== false;

/**
 * Zjištění zda daný úkon patří pod ICL dle předlohy Excelu
 */
function serizovacIsIclTask(string $taskText, string $afoCode): bool {
    $t = mb_strtolower(trim($taskText));
    $afo = trim($afoCode);

    if (str_contains($t, 'tkemc_00003')) return true;
    if (str_contains($t, 'vizuální kontrola zakladaček') || str_contains($t, 'vizualni kontrola zakladacek')) return true;
    if (str_contains($t, 'tkemc_00004')) return true;
    if (str_contains($t, 'tkemc_00005')) return true;
    if (str_contains($t, 'tkemc_00001')) return true;
    if (str_contains($t, 'tkemc_00002')) return true;
    if (str_contains($t, 'tkemc_00007')) return true;
    if (str_contains($t, 'tkemc_00010')) return true;
    if (str_contains($t, 'tkemc_00012')) return true;
    if (str_contains($t, 'tkemc_00013')) return true;
    if (str_contains($t, 'tkemc_00011') && str_contains($t, 'spojů')) return true;
    if (str_contains($t, 'velikosti sváru posuvným měřítkem') || str_contains($t, 'provedení sváru')) return true;
    if (str_contains($t, 'peel test') || str_contains($t, 'únosnosti sváru')) return true;
    if (str_contains($t, 'termálních podložek') || str_contains($t, 'termalnich podlozek')) return true;
    if (str_contains($t, '0,27-0,33') || str_contains($t, '0,15-0,19')) return true;
    if (str_contains($t, 'dummy')) return true;
    if (str_contains($t, 'kn000252') || str_contains($t, 'kn_000303') || str_contains($t, 'kn000303')) return true;
    if (str_contains($t, 'ink test')) return true;
    if (str_contains($t, 'izolačních dílů') || str_contains($t, 'klipovacího a kontrolního')) return true;
    if (str_contains($t, 'trhací zkoušku')) return true;
    if (str_contains($t, 'pozice lepidla') && ($afo === '170 + 180' || $afo === '290')) return true;
    if (str_contains($t, 'závitových vložek') && $afo === '295') return true;

    return false;
}

/**
 * Zjištění zda má mít sloupec Hodnota bílou barvu (aktivní pro zápis) nebo šedou (neaktivní)
 */
function serizovacIsValueCellActive(string $taskText, string $afoCode, bool $isGen3): bool {
    $t = mb_strtolower(trim($taskText));
    $afo = trim($afoCode);

    if ($isGen3) {
        // Gen3 EVO nešedá pole
        if (str_contains($t, '9,45-11,55') || str_contains($t, '9,45 - 11,55') || (str_contains($t, '9,45') && str_contains($t, '11,55'))) return true;
        if (str_contains($t, '0,27-0,33') || str_contains($t, '0,27 - 0,33') || str_contains($t, '0,27 -0,33')) return true;
        if ((str_contains($t, 'správného lepidla') || str_contains($t, 'spravneho lepidla')) && str_contains($t, 'expirace')) return true;
        return false;
    } else {
        // 48W nešedá pole
        if (str_contains($t, 'gel30') || str_contains($t, '2,88g-3,52g') || str_contains($t, '2,88g - 3,52g') || (str_contains($t, '2,88') && str_contains($t, '3,52'))) return true;
        if (str_contains($t, 'lisovaných matic') || str_contains($t, 'lisovanych matic') || str_contains($t, '1838/1839')) return true;
        if (str_contains($t, 'správného materiálu') || str_contains($t, 'spravneho materialu')) return true;
        if (str_contains($t, '0,9-1,1g') || str_contains($t, '0,9 - 1,1g') || str_contains($t, '0,9 -1,1g') || (str_contains($t, 'toroid') && str_contains($t, '1,1'))) return true;
        if (str_contains($t, '3,32-4,06g') || str_contains($t, '3,32 - 4,06g') || (str_contains($t, 'housing') && str_contains($t, '4,06'))) return true;
        if (str_contains($t, 'závitových vložek') || str_contains($t, 'zavitovych vlozek') || str_contains($t, '1836/1837')) return true;
        if (str_contains($t, '0,15-0,19') || str_contains($t, '0,15 - 0,19') || str_contains($t, '0,15 -0,19')) return true;
        if ((str_contains($t, 'správného lepidla') || str_contains($t, 'spravneho lepidla')) && str_contains($t, 'expirace')) return true;
        return false;
    }
}

/**
 * Pomocná funkce pro výpočet rowspanů pro seznam položek
 */
function serizovacCalculateRowspans(array $itemsList): array {
    $spans = [];
    $count = count($itemsList);
    for ($i = 0; $i < $count; $i++) {
        $currentAfo = trim((string)$itemsList[$i]['afo_code']);
        if ($currentAfo === '') {
            $spans[(int)$itemsList[$i]['id']] = 1;
            continue;
        }
        $isStart = ($i === 0) || (trim((string)$itemsList[$i - 1]['afo_code']) !== $currentAfo);
        if (!$isStart) {
            $spans[(int)$itemsList[$i]['id']] = 0;
            continue;
        }
        $s = 1;
        for ($j = $i + 1; $j < $count; $j++) {
            if (trim((string)$itemsList[$j]['afo_code']) !== $currentAfo) {
                break;
            }
            $s++;
        }
        $spans[(int)$itemsList[$i]['id']] = $s;
    }
    return $spans;
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
                    $currentProjectLabel,
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

                // --- NOVÉ: Uložení NOK akcí ---
                $nokStmt = db()->prepare('INSERT INTO serizovac_checklist_nok_actions (checklist_id, action_text, resolve_date, person_signature) VALUES (?, ?, ?, ?)');
                for ($i = 1; $i <= 3; $i++) {
                    $a = trim((string)post('nok_action_' . $i));
                    $d = trim((string)post('nok_date_' . $i));
                    $s = trim((string)post('nok_sign_' . $i));
                    if ($a !== '' || $d !== '' || $s !== '') {
                        $nokStmt->execute([$checklistId, $a, $d, $s]);
                    }
                }

                // --- NOVÉ: Uložení vah lepidla ---
                $glueStmt = db()->prepare('INSERT INTO serizovac_checklist_glue_weights (checklist_id, workplace, value_text) VALUES (?, ?, ?)');
                for ($i = 1; $i <= 11; $i++) {
                    $w = trim((string)post('glue_workplace_' . $i));
                    $v = trim((string)post('glue_value_' . $i));
                    if ($w !== '' || $v !== '') {
                        $glueStmt->execute([$checklistId, $w, $v]);
                    }
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

$afoRowspans = serizovacCalculateRowspans($items);

// Rozdělení položek pro tisk (pro 48W rozdělení za AFO 250)
$printPage1Items = [];
$printPage2Items = [];

if ($isGen3) {
    $printPage1Items = $items;
    $printPage2Items = [];
} else {
    $inPart2 = false;
    $totalCount = count($items);
    for ($i = 0; $i < $totalCount; $i++) {
        $it = $items[$i];
        $afo = trim((string)$it['afo_code']);
        if (!$inPart2) {
            $printPage1Items[] = $it;
            if (str_contains($afo, '250')) {
                $nextAfo = isset($items[$i + 1]) ? trim((string)$items[$i + 1]['afo_code']) : '';
                if (!str_contains($nextAfo, '250')) {
                    $inPart2 = true;
                }
            }
        } else {
            $printPage2Items[] = $it;
        }
    }
}

$printPage1Rowspans = serizovacCalculateRowspans($printPage1Items);
$printPage2Rowspans = serizovacCalculateRowspans($printPage2Items);

$recent = $tablesReady ? db_all(
    'SELECT id, project_label, check_date, created_by_name, created_at
     FROM serizovac_checklists
     ORDER BY id DESC
     LIMIT 20'
) : [];

require APP_ROOT . '/includes/layout.php';
?>

<style>
/* =========================================
   TISKOVÉ STYLY DLE NORMY EXCELU
   ========================================= */
@media print {
    @page {
        size: A4 portrait;
        margin: 6mm 7mm 6mm 7mm;
    }

    *, *:before, *:after {
        box-sizing: border-box !important;
    }

    body {
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, "Helvetica Neue", Helvetica, sans-serif !important;
        font-size: 7.2pt !important;
        line-height: 1.12 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .topbar, .sidebar, #sidebar, .no-print, .alert, .card-dvd, .btn, .btn-dvd, .btn-outline {
        display: none !important;
    }

    .content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    #serizovac-print-template {
        display: block !important;
        width: 100% !important;
    }

    /* Zlom stránky */
    .p-page-break {
        page-break-before: always !important;
        break-before: page !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }

    /* -------------------------------------------------------------
       1. HLAVNÍ SPOJENÁ TABULKA VČETNĚ HLAVIČKY (BEZE ZMĚNY)
       ------------------------------------------------------------- */
    .p-main-table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-bottom: 4px !important;
        border: 2px solid #000 !important;
    }

    .p-main-table td, .p-main-table th {
        border-left: 1px solid #000 !important;
        border-right: 1px solid #000 !important;
        padding: 2px 3.5px !important;
        vertical-align: middle !important;
        font-size: 7.2pt !important;
    }

    .p-head-cell {
        border: 1px solid #000 !important;
        font-size: 7.6pt !important;
    }

    .p-head-title {
        background-color: #d9d9d9 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        font-weight: bold !important;
        font-size: 9pt !important;
        text-align: center !important;
        padding: 4px !important;
        border-bottom: 1.5px solid #000 !important;
    }

    .p-logo-box {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 3px 6px !important;
        border: 1.5px solid #000 !important;
        background-color: #fff !important;
    }

    .p-logo-img {
        max-height: 38px !important;
        max-width: 100% !important;
        display: inline-block !important;
    }

    .p-head-instruction {
        font-size: 7.2pt !important;
        font-style: italic !important;
        text-align: center !important;
        font-weight: bold !important;
        border-top: 1.5px solid #000 !important;
        border-bottom: 1.5px solid #000 !important;
        padding: 2.5px !important;
        background-color: #fff !important;
    }

    .p-col-head th {
        background-color: #d9d9d9 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        font-weight: bold !important;
        text-align: center !important;
        border: 1.5px solid #000 !important;
        padding: 2.5px !important;
    }

    .p-task-row td {
        border-top: 1px dotted #555 !important;
        border-bottom: 1px dotted #555 !important;
    }

    .p-afo-cell {
        border-top: 2px solid #000 !important;
        border-bottom: 2px solid #000 !important;
        border-left: 2px solid #000 !important;
        border-right: 2px solid #000 !important;
        text-align: center !important;
        font-weight: bold !important;
        font-size: 8.2pt !important;
        vertical-align: middle !important;
    }

    .p-afo-start td {
        border-top: 2px solid #000 !important;
    }

    .p-afo-end td {
        border-bottom: 2px solid #000 !important;
    }

    .p-cell-gray {
        background-color: #d9d9d9 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .p-cell-struck {
        background: linear-gradient(to top right, transparent calc(50% - 0.75px), #000000 calc(50% - 0.75px), #000000 calc(50% + 0.75px), transparent calc(50% + 0.75px)) !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* -------------------------------------------------------------
       2. TABULKA UVOLNĚNÍ PROVEDL 
       ------------------------------------------------------------- */
    .p-sign-table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-top: 4px !important;
        margin-bottom: 6px !important;
        border-top: 1.5px solid #000 !important;
        border-left: 1.5px solid #000 !important;
        border-right: 1.5px solid #000 !important;
        border-bottom: 1.5px dashed #000 !important;
    }
    .p-sign-table td {
        border: none !important;
        height: 25px !important;
        vertical-align: bottom !important;
        padding-bottom: 3px !important;
        padding-left: 4px !important;
        padding-right: 4px !important;
        font-size: 7.4pt !important;
    }

    /* -------------------------------------------------------------
       3. TABULKA PROVEDENÉ AKCE V PŘÍPADĚ NOK
       ------------------------------------------------------------- */
    .p-nok-table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-bottom: 6px !important;
    }
    .p-nok-table th {
        border: none !important;
        background: transparent !important;
        font-weight: bold !important;
        font-size: 7.2pt !important;
        padding: 2px 4px !important;
        color: #000 !important;
    }
    .p-nok-table td {
        border: 1px solid #000 !important;
        padding: 2px 4px !important;
        font-size: 7.2pt !important;
    }

    /* -------------------------------------------------------------
       4. TABULKA VÁHY LEPIDLA 
       ------------------------------------------------------------- */
    .p-glue-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        border: 1.5px solid #000 !important;
        margin-top: 6px !important;
    }
    .p-glue-table td, .p-glue-table th {
        border-left: 1px solid #000 !important;
        border-right: 1px solid #000 !important;
        border-top: 1px dotted #555 !important;
        border-bottom: 1px dotted #555 !important;
        padding: 3px 5px !important;
        font-size: 8pt !important;
        vertical-align: middle !important;
    }
    .p-glue-table tr:first-child td {
        border-top: 1.5px solid #000 !important;
        border-bottom: 1.5px solid #000 !important;
    }
    .p-glue-table tr:last-child td {
        border-bottom: 1.5px solid #000 !important;
    }
    .p-glue-vert-cell {
        width: 46px !important;
        text-align: center !important;
        vertical-align: middle !important;
        background-color: #f2f2f2 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        padding: 4px 2px !important;
        border: 1.5px solid #000 !important;
    }
    .p-vert-text-multiline {
        writing-mode: vertical-rl !important;
        transform: rotate(180deg) !important;
        text-align: center !important;
        white-space: normal !important;
        line-height: 1.25 !important;
        font-size: 8.2pt !important;
        font-weight: bold !important;
        letter-spacing: 0.2px !important;
        display: inline-block !important;
    }
    
    /* -------------------------------------------------------------
       5. TABULKA POZNÁMEK
       ------------------------------------------------------------- */
    .p-notes-table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-top: 4px !important;
        border: 1.5px solid #000 !important;
    }
    .p-notes-table td, .p-notes-table th {
        border: 1px solid #000 !important;
        padding: 3px 5px !important;
        font-size: 8pt !important;
        vertical-align: middle !important;
    }
    .p-notes-table th {
        background-color: #d9d9d9 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-weight: bold !important;
        text-align: center !important;
        border-bottom: 1.5px solid #000 !important;
    }

    .p-avoid-break {
        page-break-inside: avoid !important;
    }
}

/* Skrytí tiskové šablony na webu */
#serizovac-print-template {
    display: none;
}
</style>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-clipboard-check me-2"></i><?= h(t('serizovac_checklist_title')) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <button type="button" onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap;cursor:pointer">
            <i class="bi bi-printer me-1"></i><?= t('Tisk') ?>
        </button>
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
                    <div style="min-width:220px">
                        <label class="form-label-dvd"><?= h(t('projekt')) ?></label>
                        <select name="project" class="form-select" style="min-width:220px" onchange="this.form.submit()">
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

                    <!-- --- NOVÉ: Tabulky pro NOK akce a váhy lepidla --- -->
                    <h6 class="mt-4 mb-2 fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Provedené akce v případě NOK výsledku</h6>
                    <div class="tbl-wrap mb-4">
                        <div class="overflow-x">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th>Akce</th>
                                        <th style="width:250px">Datum vyřešení / plánovaného vyřešení</th>
                                        <th style="width:250px">Podpis zodpovědné osoby</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <tr>
                                        <td><input type="text" class="form-control" name="nok_action_<?= $i ?>" placeholder="Krok <?= $i ?>"></td>
                                        <td><input type="text" class="form-control" name="nok_date_<?= $i ?>"></td>
                                        <td><input type="text" class="form-control" name="nok_sign_<?= $i ?>"></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <h6 class="mt-4 mb-2 fw-bold"><i class="bi bi-droplet me-2"></i>Záznam o ověření váhy lepidla při výměně sudu/kartuše</h6>
                    <div class="tbl-wrap mb-4">
                        <div class="overflow-x">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th>Pracoviště</th>
                                        <th>Hodnota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php for ($i = 1; $i <= 11; $i++): ?>
                                    <tr>
                                        <td><input type="text" class="form-control" name="glue_workplace_<?= $i ?>"></td>
                                        <td><input type="text" class="form-control" name="glue_value_<?= $i ?>"></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- --- KONEC NOVÉHO KÓDU --- -->

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

<!-- ================================================================= -->
<!-- TISKOVÁ ŠABLONA DLE NORMY EXCELU (Zobrazí se výhradně při tisku) -->
<!-- ================================================================= -->
<div id="serizovac-print-template">

    <!-- ==================== STRÁNKA 1 ==================== -->
    <table class="p-main-table">
        <!-- 1. ŘÁDEK HLAVIČKY -->
        <tr>
            <td colspan="2" rowspan="2" class="p-logo-box" style="width:56%;text-align:left;padding:2px 0 2px 6px">
            <?php
                $logoFile = APP_ROOT . '/assets/img/pma.png';
                if (is_file($logoFile)): 
                    $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
            ?>
                <img src="<?= $logoData ?>" style="max-height:60px;max-width:100%;display:block;margin:0" alt="PMA PRETTL">
            <?php else: ?>
                <img src="../assets/img/pma.png" style="max-height:60px;max-width:100%;display:block;margin:0" alt="PMA PRETTL">
            <?php endif; ?>
            </td>
            <td colspan="5" class="p-head-title" style="width:44%">
                Checklist pro seřizovače - uvolnění procesu a produktu
            </td>
        </tr>

        <!-- 2. ŘÁDEK HLAVIČKY -->
        <tr>
            <td colspan="2" class="p-head-cell" style="font-weight:bold">Linka: EMC filter</td>
            <td colspan="2" class="p-head-cell" style="text-align:center;font-weight:bold">Revize:</td>
            <td class="p-head-cell" style="text-align:center;font-weight:bold;font-size:8.5pt">8</td>
        </tr>

        <!-- 3. ŘÁDEK HLAVIČKY -->
        <tr>
            <td class="p-head-cell" style="font-weight:bold;width:8%">Projekt:</td>
            <td class="p-head-cell" style="font-weight:bold;font-size:8pt;text-align:center"><?= h($currentProjectLabel !== '' ? $currentProjectLabel : $currentProject) ?></td>
            <td colspan="2" class="p-head-cell" style="font-weight:bold">Směna č.:</td>
            <td colspan="2" class="p-head-cell" style="text-align:center;font-weight:bold">Datum revize:</td>
            <td class="p-head-cell" style="text-align:center;font-weight:bold">13.05.2026</td>
        </tr>

        <!-- 4. ŘÁDEK HLAVIČKY: INSTRUKCE -->
        <tr>
            <td colspan="5" class="p-head-instruction">
                Vyplnit vždy s 1. ks na začátku nové směny a s 1. ks při změně produkce
            </td>
            <td colspan="2" style="border:1.5px solid #000;background-color:#fff">&nbsp;</td>
        </tr>

        <!-- 5. ŘÁDEK: ZÁHLAVÍ SLOUPCŮ -->
        <tr class="p-col-head">
            <th style="width:8%">AFO</th>
            <th style="width:48%">Úkon</th>
            <th style="width:5%">ICL</th>
            <?php if ($isGen3): ?>
                <th style="width:4%" class="p-cell-gray">&nbsp;</th>
                <th style="width:6%">Gen3</th>
            <?php else: ?>
                <th style="width:4%" class="p-cell-gray">&nbsp;</th>
                <th style="width:6%">48W</th>
            <?php endif; ?>
            <th style="width:13%">OK/NOK</th>
            <th style="width:16%">Hodnota</th>
        </tr>

        <!-- ŘÁDKY ÚKONŮ (ČÁST 1) -->
        <tbody>
        <?php 
            $p1Count = count($printPage1Items);
            for ($idx = 0; $idx < $p1Count; $idx++): 
                $item = $printPage1Items[$idx];
                $itemId = (int)$item['id'];
                $taskText = (string)$item['task_text'];
                $afoCode = (string)$item['afo_code'];
                $valueHint = trim((string)$item['value_hint']);
                $hasIcl = serizovacIsIclTask($taskText, $afoCode);
                $isValueActive = serizovacIsValueCellActive($taskText, $afoCode, $isGen3);

                $isAfoStart = ($idx === 0) || (trim((string)$printPage1Items[$idx - 1]['afo_code']) !== $afoCode);
                $isAfoEnd = ($idx === $p1Count - 1) || (trim((string)$printPage1Items[$idx + 1]['afo_code']) !== $afoCode);

                $rowClasses = ['p-task-row'];
                if ($isAfoStart) $rowClasses[] = 'p-afo-start';
                if ($isAfoEnd) $rowClasses[] = 'p-afo-end';
        ?>
            <tr class="<?= implode(' ', $rowClasses) ?>">
                <?php if (($printPage1Rowspans[$itemId] ?? 0) > 0): ?>
                    <td rowspan="<?= (int)$printPage1Rowspans[$itemId] ?>" class="p-afo-cell">
                        <?= h($afoCode) ?>
                    </td>
                <?php endif; ?>
                <td style="text-align:left">
                    <?= nl2br(h($taskText)) ?>
                    <?php if ($valueHint !== '' && !serizovacValueHintMeansNoInput($valueHint)): ?>
                        <span style="font-size:6.2pt;color:#333"><em>[<?= h($valueHint) ?>]</em></span>
                    <?php endif; ?>
                </td>
                
                <?php if ($hasIcl): ?>
                    <td style="text-align:center;font-weight:bold">x</td>
                <?php else: ?>
                    <td class="p-cell-struck">&nbsp;</td>
                <?php endif; ?>

                <td class="p-cell-gray">&nbsp;</td>
                <td style="text-align:center;font-weight:bold">x</td>

                <td>&nbsp;</td>
                <td class="<?= $isValueActive ? '' : 'p-cell-gray' ?>">&nbsp;</td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <?php if ($isGen3): ?>
        <!-- Pro Gen3: Uvolnění a Akce jsou dole na 1. stránce -->
        <div class="p-avoid-break">
            <!-- 1. TABULKA UVOLNĚNÍ PROVEDL (čárkovaný spodek, bez vnitřních čar) -->
            <table class="p-sign-table">
                <tr>
                    <td style="width:40%"><strong>Uvolnění provedl:</strong></td>
                    <td style="width:25%"><strong>Datum:</strong></td>
                    <td style="width:35%"><strong>Podpis:</strong></td>
                </tr>
            </table>

            <!-- 2. TABULKA PROVEDENÉ AKCE V PŘÍPADĚ NOK (hlavička bez ohraničení a podbarvení) -->
            <div style="font-weight:bold;font-size:7.2pt;margin:4px 0 2px 0">Provedené akce v případě NOK výsledku:</div>
            <table class="p-nok-table">
                <thead>
                    <tr>
                        <th style="width:45%;text-align:left">Akce</th>
                        <th style="width:28%;text-align:center">Datum vyřešení / plánovaného vyřešení</th>
                        <th style="width:27%;text-align:center">Podpis zodpovědné osoby</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style="height:20px"><strong>1.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td style="height:20px"><strong>2.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td style="height:20px"><strong>3.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>


    <!-- ==================== ZLOM NA 2. STRÁNKU ==================== -->
    <div class="p-page-break"></div>


    <!-- ==================== STRÁNKA 2 ==================== -->
    <?php if (!$isGen3 && $printPage2Items !== []): ?>
        <!-- Pro 48W: 2. část hlavní tabulky -->
        <table class="p-main-table">
            <thead>
                <tr class="p-col-head">
                    <th style="width:8%">AFO</th>
                    <th style="width:48%">Úkon</th>
                    <th style="width:5%">ICL</th>
                    <th style="width:4%" class="p-cell-gray">&nbsp;</th>
                    <th style="width:6%">48W</th>
                    <th style="width:13%">OK/NOK</th>
                    <th style="width:16%">Hodnota</th>
                </tr>
            </thead>
            <tbody>
            <?php 
                $p2Count = count($printPage2Items);
                for ($idx = 0; $idx < $p2Count; $idx++): 
                    $item = $printPage2Items[$idx];
                    $itemId = (int)$item['id'];
                    $taskText = (string)$item['task_text'];
                    $afoCode = (string)$item['afo_code'];
                    $valueHint = trim((string)$item['value_hint']);
                    $hasIcl = serizovacIsIclTask($taskText, $afoCode);
                    $isValueActive = serizovacIsValueCellActive($taskText, $afoCode, $isGen3);

                    $isAfoStart = ($idx === 0) || (trim((string)$printPage2Items[$idx - 1]['afo_code']) !== $afoCode);
                    $isAfoEnd = ($idx === $p2Count - 1) || (trim((string)$printPage2Items[$idx + 1]['afo_code']) !== $afoCode);

                    $rowClasses = ['p-task-row'];
                    if ($isAfoStart) $rowClasses[] = 'p-afo-start';
                    if ($isAfoEnd) $rowClasses[] = 'p-afo-end';
            ?>
                <tr class="<?= implode(' ', $rowClasses) ?>">
                    <?php if (($printPage2Rowspans[$itemId] ?? 0) > 0): ?>
                        <td rowspan="<?= (int)$printPage2Rowspans[$itemId] ?>" class="p-afo-cell">
                            <?= h($afoCode) ?>
                        </td>
                    <?php endif; ?>
                    <td style="text-align:left">
                        <?= nl2br(h($taskText)) ?>
                        <?php if ($valueHint !== '' && !serizovacValueHintMeansNoInput($valueHint)): ?>
                            <span style="font-size:6.2pt;color:#333"><em>[<?= h($valueHint) ?>]</em></span>
                        <?php endif; ?>
                    </td>
                    
                    <?php if ($hasIcl): ?>
                        <td style="text-align:center;font-weight:bold">x</td>
                    <?php else: ?>
                        <td class="p-cell-struck">&nbsp;</td>
                    <?php endif; ?>

                    <td class="p-cell-gray">&nbsp;</td>
                    <td style="text-align:center;font-weight:bold">x</td>

                    <td>&nbsp;</td>
                    <td class="<?= $isValueActive ? '' : 'p-cell-gray' ?>">&nbsp;</td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <!-- Pro 48W: Uvolnění a Akce jsou na 2. stránce -->
        <div class="p-avoid-break">
            <!-- 1. TABULKA UVOLNĚNÍ PROVEDL -->
            <table class="p-sign-table">
                <tr>
                    <td style="width:40%"><strong>Uvolnění provedl:</strong></td>
                    <td style="width:25%"><strong>Datum:</strong></td>
                    <td style="width:35%"><strong>Podpis:</strong></td>
                </tr>
            </table>

            <!-- 2. TABULKA PROVEDENÉ AKCE V PŘÍPADĚ NOK -->
            <div style="font-weight:bold;font-size:7.2pt;margin:4px 0 2px 0">Provedené akce v případě NOK výsledku:</div>
            <table class="p-nok-table">
                <thead>
                    <tr>
                        <th style="width:45%;text-align:left">Akce</th>
                        <th style="width:28%;text-align:center">Datum vyřešení / plánovaného vyřešení</th>
                        <th style="width:27%;text-align:center">Podpis zodpovědné osoby</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style="height:20px"><strong>1.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td style="height:20px"><strong>2.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td style="height:20px"><strong>3.</strong></td><td>&nbsp;</td><td>&nbsp;</td></tr>
                </tbody>
            </table>

            <!-- 3. TABULKA VÁHY LEPIDLA -->
            <table class="p-glue-table">
                <tr>
                    <td rowspan="12" class="p-glue-vert-cell">
                        <div class="p-vert-text-multiline">
                            Záznam o ověření váhy lepidla<br>při výměně sudu nebo kartuše
                        </div>
                    </td>
                    <td style="width:48%;font-weight:bold;height:18px;background:#f2f2f2">Pracoviště:</td>
                    <td style="width:48%;font-weight:bold;height:18px;background:#f2f2f2">Hodnota:</td>
                </tr>
                <?php for ($r = 0; $r < 11; $r++): ?>
                <tr>
                    <td style="height:24px">&nbsp;</td>
                    <td style="height:24px">&nbsp;</td>
                </tr>
                <?php endfor; ?>
            </table>
        </div>

    <?php else: ?>

        <!-- Pro Gen3: Tabulka váhy lepidla na 2. stránce (naprosto stejná jako u 48W) -->
        <div class="p-avoid-break">
            <table class="p-glue-table">
                <tr>
                    <td rowspan="12" class="p-glue-vert-cell">
                        <div class="p-vert-text-multiline">
                            Záznam o ověření váhy lepidla<br>při výměně sudu nebo kartuše
                        </div>
                    </td>
                    <td style="width:48%;font-weight:bold;height:18px;background:#f2f2f2">Pracoviště:</td>
                    <td style="width:48%;font-weight:bold;height:18px;background:#f2f2f2">Hodnota:</td>
                </tr>
                <?php for ($r = 0; $r < 11; $r++): ?>
                <tr>
                    <td style="height:24px">&nbsp;</td>
                    <td style="height:24px">&nbsp;</td>
                </tr>
                <?php endfor; ?>
            </table>
        </div>

    <?php endif; ?>

    <!-- 5. TABULKA POZNÁMEK (Společná na konci dokumentu pro oba typy projektů) -->
    <div class="p-avoid-break" style="margin-top:10px;">
        <div style="font-weight:bold;font-size:8.5pt;margin-bottom:2px">Poznámky k jednotlivým AFO:</div>
        <table class="p-notes-table">
            <thead>
                <tr>
                    <th style="width:12%">AFO</th>
                    <th style="width:88%">Poznámka</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($n = 0; $n < 10; $n++): // Prázdný formulář tiskne staticky 10 řádků ?>
                <tr>
                    <td style="height:24px">&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>