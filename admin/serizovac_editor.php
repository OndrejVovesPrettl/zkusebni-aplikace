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

/**
 * Pomocné funkce pro tisk (přenesené z formuláře, aby fungoval i tisk v editoru)
 */
if (!function_exists('serizovacIsIclTask')) {
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
}

if (!function_exists('serizovacIsValueCellActive')) {
    function serizovacIsValueCellActive(string $taskText, string $afoCode, bool $isGen3): bool {
        $t = mb_strtolower(trim($taskText));
        $afo = trim($afoCode);

        if ($isGen3) {
            if (str_contains($t, '9,45-11,55') || str_contains($t, '9,45 - 11,55') || (str_contains($t, '9,45') && str_contains($t, '11,55'))) return true;
            if (str_contains($t, '0,27-0,33') || str_contains($t, '0,27 - 0,33') || str_contains($t, '0,27 -0,33')) return true;
            if ((str_contains($t, 'správného lepidla') || str_contains($t, 'spravneho lepidla')) && str_contains($t, 'expirace')) return true;
            return false;
        } else {
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
}

if (!function_exists('serizovacCalculateRowspans')) {
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
            
            // Získání informací z katalogu
            $catData = db_row('SELECT requires_value, value_hint FROM serizovac_checklist_catalog WHERE id = ?', [$catalogId]);
            $requiresValue = (int)($catData['requires_value'] ?? 0);
            $valueHint = (string)($catData['value_hint'] ?? '');
            $valueOptionalByHint = serizovacValueHintMeansNoInput($valueHint);
            
            $result = strtoupper(trim((string)post('result_' . $rowId)));
            $value = trim((string)post('value_' . $rowId));
            
            if (!in_array($result, ['OK', 'NOK', 'NA'], true)) {
                $errors[] = 'Row #' . $rowId;
                continue;
            }
            // Pokud je vyžadována hodnota, ale pole nesmí být skryto podle value_hint
            if ($requiresValue === 1 && !$valueOptionalByHint && $value === '') {
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
            // --- NOVÉ: Aktualizace NOK akcí ---
            db()->prepare('DELETE FROM serizovac_checklist_nok_actions WHERE checklist_id = ?')->execute([$entryId]);
            $nokStmt = db()->prepare('INSERT INTO serizovac_checklist_nok_actions (checklist_id, action_text, resolve_date, person_signature) VALUES (?, ?, ?, ?)');
            for ($i = 1; $i <= 3; $i++) {
                $a = trim((string)post('nok_action_' . $i));
                $d = trim((string)post('nok_date_' . $i));
                $s = trim((string)post('nok_sign_' . $i));
                if ($a !== '' || $d !== '' || $s !== '') {
                    $nokStmt->execute([$entryId, $a, $d, $s]);
                }
            }

            // --- NOVÉ: Aktualizace vah lepidla ---
            db()->prepare('DELETE FROM serizovac_checklist_glue_weights WHERE checklist_id = ?')->execute([$entryId]);
            $glueStmt = db()->prepare('INSERT INTO serizovac_checklist_glue_weights (checklist_id, workplace, value_text) VALUES (?, ?, ?)');
            for ($i = 1; $i <= 11; $i++) {
                $w = trim((string)post('glue_workplace_' . $i));
                $v = trim((string)post('glue_value_' . $i));
                if ($w !== '' || $v !== '') {
                    $glueStmt->execute([$entryId, $w, $v]);
                }
            }
            $flashOk = t('save') . ' OK';
        } else {
            $flashErr = implode('<br>', array_slice($errors, 0, 8));
        }
    }
}

$fProject = trim((string)get('project', ''));
$fDate = trim((string)get('date', ''));

// --- NOVÉ: Získání unikátního seznamu projektů, které mají nějaký záznam ---
$filterProjects = db_all(
    'SELECT project_key, MAX(project_label) as project_label 
     FROM serizovac_checklists 
     GROUP BY project_key 
     ORDER BY project_label'
);

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

// PŘIDÁNO: Přidáno c.value_hint do SQL dotazu pro správné zobrazení u tisku
$entryRows = $entryId > 0 ? db_all(
    'SELECT r.id, r.result_status, r.value_text, r.note_text, c.afo_code, c.task_text, c.requires_value, c.value_hint
     FROM serizovac_checklist_rows r
     INNER JOIN serizovac_checklist_catalog c ON c.id = r.catalog_id
     WHERE r.checklist_id = ?
     ORDER BY c.sort_order, c.id',
    [$entryId]
) : [];
$nokActions = $entryId > 0 ? db_all('SELECT * FROM serizovac_checklist_nok_actions WHERE checklist_id = ? ORDER BY id', [$entryId]) : [];
$glueWeights = $entryId > 0 ? db_all('SELECT * FROM serizovac_checklist_glue_weights WHERE checklist_id = ? ORDER BY id', [$entryId]) : [];

// Zpracování logiky pro tisk vyplněného checklistu (pouze pokud je načten checklist)
$isGen3 = false;
$printPage1Items = [];
$printPage2Items = [];
$printPage1Rowspans = [];
$printPage2Rowspans = [];
$notesList = [];

if ($entry) {
    $isGen3 = stripos($entry['project_key'], 'gen3') !== false || stripos($entry['project_label'], 'gen3') !== false;
    
    $inPart2 = false;
    $totalCount = count($entryRows);
    
    for ($i = 0; $i < $totalCount; $i++) {
        $row = $entryRows[$i];
        
        // Sesbírání poznámek pro poslední tabulku
        if (trim((string)$row['note_text']) !== '') {
            $notesList[] = [
                'afo' => trim((string)$row['afo_code']),
                'note' => trim((string)$row['note_text'])
            ];
        }
        
        // Zalamování 48W za AFO 250
        if ($isGen3) {
            $printPage1Items[] = $row;
        } else {
            $afo = trim((string)$row['afo_code']);
            if (!$inPart2) {
                $printPage1Items[] = $row;
                if (str_contains($afo, '250')) {
                    $nextAfo = isset($entryRows[$i + 1]) ? trim((string)$entryRows[$i + 1]['afo_code']) : '';
                    if (!str_contains($nextAfo, '250')) {
                        $inPart2 = true;
                    }
                }
            } else {
                $printPage2Items[] = $row;
            }
        }
    }
    // Výpočet sloučení (rowspans) pro tisk. ID záznamu (r.id) zaručuje unikátnost položky.
    $printPage1Rowspans = serizovacCalculateRowspans($printPage1Items);
    $printPage2Rowspans = serizovacCalculateRowspans($printPage2Items);
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-table me-2"></i><?= h(t('serizovac_editor_title')) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if ($entry): ?>
            <!-- Tlačítko k tisku se zobrazí jen když je zobrazen editor -->
            <button type="button" onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap;cursor:pointer">
                <i class="bi bi-printer me-1"></i><?= t('Tisk') ?>
            </button>
        <?php endif; ?>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content no-print">
    <?php if ($flashOk !== ''): ?><div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✓ <?= h($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">✗ <?= $flashErr ?></div><?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-body-dvd">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label-dvd">Projekt</label>
                    <select name="project" class="form-select">
                        <option value="">-- Všechny projekty --</option>
                        <?php foreach ($filterProjects as $fp): ?>
                            <option value="<?= h((string)$fp['project_key']) ?>" <?= $fProject === (string)$fp['project_key'] ? 'selected' : '' ?>>
                                <?= h((string)$fp['project_label'] !== '' ? (string)$fp['project_label'] : (string)$fp['project_key']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label-dvd"><?= h(t('date')) ?></label>
                    <input type="date" class="form-control" name="date" value="<?= h($fDate) ?>">
                </div>
                <div class="col-auto">
                    <button class="btn-dvd btn-dvd-sm" type="submit"><?= h(t('search')) ?></button>
                </div>
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
                            <?php 
                                $rowId = (int)$row['id']; 
                                $valueHint = (string)$row['value_hint'];
                                $hideValueInput = serizovacValueHintMeansNoInput($valueHint);
                            ?>
                            <tr>
                                <td><?= h((string)$row['afo_code']) ?></td>
                                <td>
                                    <?= h((string)$row['task_text']) ?>
                                    <?php if ((int)$row['requires_value'] === 1): ?>
                                        <span class="req">*</span>
                                    <?php elseif (trim($valueHint) !== '' && !$hideValueInput): ?>
                                        <span style="color:var(--muted)">[<?= h($valueHint) ?>]</span>
                                    <?php endif; ?>
                                </td>
                                <?php 
                                    $res = strtoupper(trim((string)$row['result_status']));
                                    // Svěží zelená a jasná červená (jemně průhledné, aby nebily do očí)
                                    $bgOk = 'rgba(74, 222, 128, 0.35)'; 
                                    $bgNok = 'rgba(248, 113, 113, 0.4)';
                                    
                                    // Nastavení výchozí barvy podle načtené hodnoty
                                    $tdBg = $res === 'OK' ? $bgOk : ($res === 'NOK' ? $bgNok : 'transparent');
                                ?>
                                <td id="td_result_<?= $rowId ?>" style="background-color: <?= $tdBg ?>; transition: background-color 0.3s; vertical-align: middle;">
                                    <select name="result_<?= $rowId ?>" class="form-select fw-bold" required style="background: #fff; border: 1px solid #ccc; width: 100%;" onchange="
                                        let td = document.getElementById('td_result_<?= $rowId ?>');
                                        if (this.value === 'OK') td.style.backgroundColor = '<?= $bgOk ?>';
                                        else if (this.value === 'NOK') td.style.backgroundColor = '<?= $bgNok ?>';
                                        else td.style.backgroundColor = 'transparent';
                                    ">
                                        <?php foreach (['OK', 'NOK', 'NA'] as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $res === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($hideValueInput): ?>
                                        <!-- Skryto: Pro tento úkon se hodnota nezadává -->
                                    <?php else: ?>
                                        <input type="text" class="form-control" name="value_<?= $rowId ?>" value="<?= h((string)$row['value_text']) ?>" <?= (int)$row['requires_value'] === 1 ? 'required' : '' ?> placeholder="<?= h($valueHint) ?>">
                                    <?php endif; ?>
                                </td>
                                <td><input type="text" class="form-control" name="note_<?= $rowId ?>" value="<?= h((string)$row['note_text']) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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
                            <?php for ($i = 1; $i <= 3; $i++): 
                                $nok = $nokActions[$i-1] ?? null;
                            ?>
                                <tr>
                                    <td><input type="text" class="form-control" name="nok_action_<?= $i ?>" value="<?= h($nok['action_text'] ?? '') ?>" placeholder="Krok <?= $i ?>"></td>
                                    <td><input type="text" class="form-control" name="nok_date_<?= $i ?>" value="<?= h($nok['resolve_date'] ?? '') ?>"></td>
                                    <td><input type="text" class="form-control" name="nok_sign_<?= $i ?>" value="<?= h($nok['person_signature'] ?? '') ?>"></td>
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
                            <?php for ($i = 1; $i <= 11; $i++): 
                                $glue = $glueWeights[$i-1] ?? null;
                            ?>
                                <tr>
                                    <td><input type="text" class="form-control" name="glue_workplace_<?= $i ?>" value="<?= h($glue['workplace'] ?? '') ?>"></td>
                                    <td><input type="text" class="form-control" name="glue_value_<?= $i ?>" value="<?= h($glue['value_text'] ?? '') ?>"></td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- --- KONEC NOVÉHO KÓDU --- -->
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

<?php if ($entry): ?>
<!-- ================================================================= -->
<!-- TISKOVÁ ŠABLONA VYPLNĚNÉHO FORMULÁŘE (Zobrazí se výhradně při tisku) -->
<!-- ================================================================= -->
<style>
@media print {
    @page { size: A4 portrait; margin: 6mm 7mm 6mm 7mm; }
    *, *:before, *:after { box-sizing: border-box !important; }
    body {
        background: #fff !important; color: #000 !important;
        font-family: Arial, "Helvetica Neue", Helvetica, sans-serif !important;
        font-size: 7.2pt !important; line-height: 1.12 !important;
        margin: 0 !important; padding: 0 !important;
    }
    .topbar, .sidebar, #sidebar, .no-print, .alert, .card-dvd, .btn, .btn-dvd, .btn-outline { display: none !important; }
    .content { margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
    #serizovac-print-template { display: block !important; width: 100% !important; }
    .p-page-break { page-break-before: always !important; break-before: page !important; height: 0 !important; margin: 0 !important; padding: 0 !important; border: none !important; }
    .p-main-table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 4px !important; border: 2px solid #000 !important; }
    .p-main-table td, .p-main-table th { border-left: 1px solid #000 !important; border-right: 1px solid #000 !important; padding: 2px 3.5px !important; vertical-align: middle !important; font-size: 7.2pt !important; }
    .p-head-cell { border: 1px solid #000 !important; font-size: 7.6pt !important; }
    .p-head-title { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold !important; font-size: 9pt !important; text-align: center !important; padding: 4px !important; border-bottom: 1.5px solid #000 !important; }
    .p-logo-box { text-align: center !important; vertical-align: middle !important; padding: 3px 6px !important; border: 1.5px solid #000 !important; background-color: #fff !important; }
    .p-logo-img { max-height: 38px !important; max-width: 100% !important; display: inline-block !important; }
    .p-head-instruction { font-size: 7.2pt !important; font-style: italic !important; text-align: center !important; font-weight: bold !important; border-top: 1.5px solid #000 !important; border-bottom: 1.5px solid #000 !important; padding: 2.5px !important; background-color: #fff !important; }
    .p-col-head th { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold !important; text-align: center !important; border: 1.5px solid #000 !important; padding: 2.5px !important; }
    .p-task-row td { border-top: 1px dotted #555 !important; border-bottom: 1px dotted #555 !important; }
    .p-afo-cell { border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; border-left: 2px solid #000 !important; border-right: 2px solid #000 !important; text-align: center !important; font-weight: bold !important; font-size: 8.2pt !important; vertical-align: middle !important; }
    .p-afo-start td { border-top: 2px solid #000 !important; }
    .p-afo-end td { border-bottom: 2px solid #000 !important; }
    .p-cell-gray { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .p-cell-struck { background: linear-gradient(to top right, transparent calc(50% - 0.75px), #000000 calc(50% - 0.75px), #000000 calc(50% + 0.75px), transparent calc(50% + 0.75px)) !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .p-sign-table { width: 100% !important; border-collapse: collapse !important; margin-top: 4px !important; margin-bottom: 6px !important; border-top: 1.5px solid #000 !important; border-left: 1.5px solid #000 !important; border-right: 1.5px solid #000 !important; border-bottom: 1.5px dashed #000 !important; }
    .p-sign-table td { border: none !important; height: 25px !important; vertical-align: bottom !important; padding-bottom: 3px !important; padding-left: 4px !important; padding-right: 4px !important; font-size: 7.4pt !important; }
    .p-nok-table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 6px !important; }
    .p-nok-table th { border: none !important; background: transparent !important; font-weight: bold !important; font-size: 7.2pt !important; padding: 2px 4px !important; color: #000 !important; }
    .p-nok-table td { border: 1px solid #000 !important; padding: 2px 4px !important; font-size: 7.2pt !important; }
    .p-glue-table { width: 100% !important; table-layout: fixed !important; border-collapse: collapse !important; border: 1.5px solid #000 !important; margin-top: 6px !important; }
    .p-glue-table td, .p-glue-table th { border-left: 1px solid #000 !important; border-right: 1px solid #000 !important; border-top: 1px dotted #555 !important; border-bottom: 1px dotted #555 !important; padding: 3px 5px !important; font-size: 8pt !important; vertical-align: middle !important; }
    .p-glue-table tr:first-child td { border-top: 1.5px solid #000 !important; border-bottom: 1.5px solid #000 !important; }
    .p-glue-table tr:last-child td { border-bottom: 1.5px solid #000 !important; }
    .p-glue-vert-cell { width: 46px !important; text-align: center !important; vertical-align: middle !important; background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; padding: 4px 2px !important; border: 1.5px solid #000 !important; }
    .p-vert-text-multiline { writing-mode: vertical-rl !important; transform: rotate(180deg) !important; text-align: center !important; white-space: normal !important; line-height: 1.25 !important; font-size: 8.2pt !important; font-weight: bold !important; letter-spacing: 0.2px !important; display: inline-block !important; }
    
    .p-notes-table { width: 100% !important; border-collapse: collapse !important; margin-top: 4px !important; border: 1.5px solid #000 !important; }
    .p-notes-table td, .p-notes-table th { border: 1px solid #000 !important; padding: 3px 5px !important; font-size: 8pt !important; vertical-align: middle !important; }
    .p-notes-table th { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; font-weight: bold !important; text-align: center !important; border-bottom: 1.5px solid #000 !important; }
    
    .p-avoid-break { page-break-inside: avoid !important; }
}
#serizovac-print-template { display: none; }
</style>

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
            <td class="p-head-cell" style="font-weight:bold;font-size:8pt;text-align:center"><?= h((string)$entry['project_label'] !== '' ? (string)$entry['project_label'] : (string)$entry['project_key']) ?></td>
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

                <td style="text-align:center;font-weight:bold;font-size:8pt;">
                    <?php 
                        $resText = strtoupper(trim((string)$item['result_status']));
                        echo ($resText === 'OK' || $resText === 'NOK') ? $resText : (($resText === 'NA') ? 'N/A' : '');
                    ?>
                </td>
                <td class="<?= $isValueActive ? '' : 'p-cell-gray' ?>" style="text-align:center;font-weight:bold;font-size:8pt;">
                    <?= h((string)$item['value_text']) ?>
                </td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <?php if ($isGen3): ?>
        <div class="p-avoid-break">
            <!-- 1. TABULKA UVOLNĚNÍ PROVEDL (PŘEDVYPLNĚNÁ) -->
            <table class="p-sign-table">
                <tr>
                    <td style="width:40%"><strong>Uvolnění provedl:</strong> <?= h((string)$entry['created_by_name']) ?></td>
                    <td style="width:25%"><strong>Datum:</strong> <?= h(date('d.m.Y', strtotime((string)$entry['check_date']))) ?></td>
                    <td style="width:35%"><strong>Podpis:</strong> ______________________</td>
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
                    <?php for ($i = 0; $i < 3; $i++): 
                        $n = $nokActions[$i] ?? ['action_text' => '', 'resolve_date' => '', 'person_signature' => ''];
                    ?>
                    <tr>
                        <td style="height:20px; border-left: 1px solid #000; border-right: 1px solid #000;"><strong><?= $i+1 ?>.</strong> <?= h($n['action_text']) ?></td>
                        <td style="text-align:center; border-right: 1px solid #000;"><?= h($n['resolve_date']) ?></td>
                        <td style="text-align:center; border-right: 1px solid #000;"><?= h($n['person_signature']) ?></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>


    <!-- ==================== ZLOM NA 2. STRÁNKU ==================== -->
    <div class="p-page-break"></div>


    <!-- ==================== STRÁNKA 2 ==================== -->
    <?php if (!$isGen3 && $printPage2Items !== []): ?>
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

                    <td style="text-align:center;font-weight:bold;font-size:8pt;">
                        <?php 
                            $resText = strtoupper(trim((string)$item['result_status']));
                            echo ($resText === 'OK' || $resText === 'NOK') ? $resText : (($resText === 'NA') ? 'N/A' : '');
                        ?>
                    </td>
                    <td class="<?= $isValueActive ? '' : 'p-cell-gray' ?>" style="text-align:center;font-weight:bold;font-size:8pt;">
                        <?= h((string)$item['value_text']) ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <div class="p-avoid-break">
            <!-- 1. TABULKA UVOLNĚNÍ PROVEDL (PŘEDVYPLNĚNÁ) -->
            <table class="p-sign-table">
                <tr>
                    <td style="width:40%"><strong>Uvolnění provedl:</strong> <?= h((string)$entry['created_by_name']) ?></td>
                    <td style="width:25%"><strong>Datum:</strong> <?= h(date('d.m.Y', strtotime((string)$entry['check_date']))) ?></td>
                    <td style="width:35%"><strong>Podpis:</strong> ______________________</td>
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
                    <?php for ($i = 0; $i < 3; $i++): 
                        $n = $nokActions[$i] ?? ['action_text' => '', 'resolve_date' => '', 'person_signature' => ''];
                    ?>
                    <tr>
                        <td style="height:20px; border-left: 1px solid #000; border-right: 1px solid #000;"><strong><?= $i+1 ?>.</strong> <?= h($n['action_text']) ?></td>
                        <td style="text-align:center; border-right: 1px solid #000;"><?= h($n['resolve_date']) ?></td>
                        <td style="text-align:center; border-right: 1px solid #000;"><?= h($n['person_signature']) ?></td>
                    </tr>
                    <?php endfor; ?>
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
                <?php for ($r = 0; $r < 11; $r++): 
                    $g = $glueWeights[$r] ?? ['workplace' => '', 'value_text' => ''];
                ?>
                <tr>
                    <td style="height:24px; padding-left: 5px;"><?= h($g['workplace']) ?></td>
                    <td style="height:24px; text-align:center;"><?= h($g['value_text']) ?></td>
                </tr>
                <?php endfor; ?>
            </table>
        </div>

    <?php else: ?>
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
                <?php for ($r = 0; $r < 11; $r++): 
                    $g = $glueWeights[$r] ?? ['workplace' => '', 'value_text' => ''];
                ?>
                <tr>
                    <td style="height:24px; padding-left: 5px;"><?= h($g['workplace']) ?></td>
                    <td style="height:24px; text-align:center;"><?= h($g['value_text']) ?></td>
                </tr>
                <?php endfor; ?>
            </table>
        </div>
    <?php endif; ?>

    <!-- 5. TABULKA POZNÁMEK (S dynamickým vkládáním řádků na základě uložených dat) -->
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
                <?php 
                    $minNoteRows = 10;
                    $notesCount = count($notesList);
                    $rowsToPrint = max($minNoteRows, $notesCount);
                    for ($n = 0; $n < $rowsToPrint; $n++): 
                ?>
                <tr>
                    <td style="height:24px;text-align:center;font-weight:bold;font-size:8pt">
                        <?= isset($notesList[$n]) ? h($notesList[$n]['afo']) : '&nbsp;' ?>
                    </td>
                    <td style="font-size:8pt">
                        <?= isset($notesList[$n]) ? h($notesList[$n]['note']) : '&nbsp;' ?>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

</div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>