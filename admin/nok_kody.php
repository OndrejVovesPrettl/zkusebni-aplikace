<?php
// admin/nok_kody.php - NOK matrix editor (STABILNÍ VERZE)
@ini_set('max_input_vars', '10000');

require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_nok');
$activePage = 'nok';
ensureNokKodyMatrixSchema();

$msg = null;

$isSaveMatrixPost = $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_matrix';
$filterActive = (string)($isSaveMatrixPost ? post('filter_active', 'active') : get('filter_active', 'active'));
$filterActive = in_array($filterActive, ['active', 'all', 'inactive'], true) ? $filterActive : 'active';
$requestedAfoValues = $isSaveMatrixPost ? post('afo', []) : get('afo', []);
$requestedAfoValues = is_array($requestedAfoValues) ? $requestedAfoValues : [$requestedAfoValues];

$afoRows = db_all('SELECT CAST(cislo_id AS CHAR) AS afo, kod_pracovisite, nazev_pracovisite, nazev_dilu, aktivni
                   FROM pracovisite
                   WHERE aktivni = 1 AND cislo_id IS NOT NULL AND cislo_id > 0
                   ORDER BY CAST(cislo_id AS UNSIGNED), cislo_id');
$allAfoList = [];
$afoWorkplaceMeta = [];
foreach ($afoRows as $r) {
    $afoStr = (string)$r['afo'];
    $allAfoList[] = $afoStr;
    $afoWorkplaceMeta[$afoStr] = [
        'kod' => (string)($r['kod_pracovisite'] ?? ''),
        'nazev' => (string)($r['nazev_pracovisite'] ?? ''),
        'dil' => (string)($r['nazev_dilu'] ?? ''),
        'aktivni' => (int)($r['aktivni'] ?? 0) === 1,
    ];
}

$requestedAfoList = [];
foreach ($requestedAfoValues as $requestedAfoValue) {
    if (!is_scalar($requestedAfoValue)) {
        continue;
    }
    $requestedAfoValue = trim((string)$requestedAfoValue);
    if ($requestedAfoValue !== '') {
        $requestedAfoList[] = $requestedAfoValue;
    }
}
$requestedAfoList = array_values(array_unique($requestedAfoList));
$invalidAfoSelection = array_diff($requestedAfoList, $allAfoList) !== [];
$selectedAfoList = array_values(array_intersect($allAfoList, array_slice($requestedAfoList, 0, 5)));
$selectedAfoLabels = array_map(static function (string $afo) use ($afoWorkplaceMeta): string {
    $meta = $afoWorkplaceMeta[$afo] ?? [];
    return '#' . $afo . ' · ' . (string)($meta['kod'] ?? '') . ' · ' . (string)($meta['nazev'] ?? '');
}, $selectedAfoList);
$afoList = $selectedAfoList;
$afoKeys = $afoList;

if ($isSaveMatrixPost && ($selectedAfoList === [] || count($requestedAfoList) > 5 || $invalidAfoSelection)) {
    http_response_code(400);
    die('Vyberte jedno až pět platných pracovišť.');
}

// 1. ULOŽENÍ MATICE A TEXTŮ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_matrix') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $matrixJson = post('matrix_json');
    $textsJson = post('texts_json');
    $operatorDefaultsJson = post('operator_defaults_json');

    $posted = is_string($matrixJson) ? (json_decode($matrixJson, true) ?: []) : [];
    $postedTexts = is_string($textsJson) ? (json_decode($textsJson, true) ?: []) : [];
    $postedOperatorDefaults = is_string($operatorDefaultsJson) ? (json_decode($operatorDefaultsJson, true) ?: []) : [];

    $whereSaveActive = match ($filterActive) {
        'all' => '',
        'inactive' => 'WHERE aktivni = 0',
        default => 'WHERE aktivni = 1',
    };
    $nokRows = db_all("SELECT id, kod, popis, aktivni FROM nok_kody $whereSaveActive ORDER BY id");

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO nok_matrix_afo (afo, nok_kod_id, kod, popis, zobrazit, operator_excluded_default) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE zobrazit=VALUES(zobrazit), operator_excluded_default=VALUES(operator_excluded_default), kod=VALUES(kod), popis=VALUES(popis), updated_at=NOW()'
        );
        $updText = $pdo->prepare('UPDATE nok_kody SET popis=? WHERE id=?');
        $updOperatorDefault = $pdo->prepare('UPDATE nok_matrix_afo SET operator_excluded_default=? WHERE nok_kod_id=? AND afo=?');
        $updTr = $pdo->prepare(
            'INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()'
        );

        foreach ($postedOperatorDefaults as $nokId => $afoDefaults) {
            if (!ctype_digit((string)$nokId) || (int)$nokId <= 0 || !is_array($afoDefaults)) {
                continue;
            }
            foreach ($afoDefaults as $afo => $excluded) {
                if (!in_array(trim((string)$afo), $afoKeys, true)) {
                    continue;
                }
                $updOperatorDefault->execute([(int)$excluded === 1 ? 1 : 0, (int)$nokId, (string)$afo]);
            }
        }

        foreach ($nokRows as $nok) {
            $nokId = (int)$nok['id'];
            $nokKod = (int)($nok['kod'] ?? $nokId);
            
            $newText = trim((string)($postedTexts[(string)$nokId] ?? $nok['popis'] ?? ''));
            if ($newText === '') {
                $newText = (string)($nok['popis'] ?? '');
            }
            
            $updText->execute([$newText, $nokId]);
            $key1 = 'nok_' . str_pad((string)$nokKod, 3, '0', STR_PAD_LEFT) . '_popis';
            $key2 = 'nok_' . (string)$nokKod . '_popis';
            $key3 = 'nok_' . str_pad((string)$nokId, 3, '0', STR_PAD_LEFT) . '_popis';
            
            foreach (['cs', 'en', 'uk'] as $langCode) {
                $updTr->execute([$langCode, $key1, $newText]);
                $updTr->execute([$langCode, $key2, $newText]);
                $updTr->execute([$langCode, $key3, $newText]);
            }

            foreach ($afoKeys as $afo) {
                $val = 0;
                if (isset($posted[(string)$nokId]) && is_array($posted[(string)$nokId]) && !empty($posted[(string)$nokId][$afo])) {
                    $val = 1;
                }
                $operatorExcludedDefault = (int)($postedOperatorDefaults[(string)$nokId][$afo] ?? 0) === 1 ? 1 : 0;
                $ins->execute([$afo, $nokId, $nokKod, $newText, $val, $operatorExcludedDefault]);
            }
        }

        $pdo->commit();
        redirect(APP_URL . '/admin/nok_kody.php?' . http_build_query([
            'ok' => 1,
            'filter_active' => $filterActive,
            'afo' => $selectedAfoList,
        ]));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = 'Uložení matice selhalo: ' . $e->getMessage();
    }
}

// 2. PŘIDÁNÍ NOVÉ CHYBY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add_nok_kod') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $popis = trim((string)post('new_nok_popis'));

    if ($popis !== '') {
        try {
            $nextKod = (int)db_val('SELECT COALESCE(MAX(kod), 0) + 1 FROM nok_kody');
            
            $stmt = db()->prepare('INSERT INTO nok_kody (kod, popis, skupina, aktivni, zobrazit_default) VALUES (?, ?, "ostatni", 1, 1)');
            $stmt->execute([$nextKod, $popis]);
            $newId = (int)db()->lastInsertId();

            $activeAfoRows = db_all('SELECT CAST(cislo_id AS CHAR) AS afo FROM pracovisite WHERE aktivni=1 AND cislo_id IS NOT NULL AND cislo_id>0');
            $matrixInsert = db()->prepare('INSERT INTO nok_matrix_afo (afo,nok_kod_id,kod,popis,zobrazit,operator_excluded_default) VALUES (?,?,?,?,1,0)');
            foreach ($activeAfoRows as $afoRow) {
                $matrixInsert->execute([(string)$afoRow['afo'], $newId, $nextKod, $popis]);
            }
            
            $key1 = 'nok_' . str_pad((string)$nextKod, 3, '0', STR_PAD_LEFT) . '_popis';
            $key2 = 'nok_' . (string)$nextKod . '_popis';
            $key3 = 'nok_' . str_pad((string)$newId, 3, '0', STR_PAD_LEFT) . '_popis';
            
            $updTr = db()->prepare('INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()');
            foreach (['cs', 'en', 'uk'] as $langCode) {
                $updTr->execute([$langCode, $key1, $popis]);
                $updTr->execute([$langCode, $key2, $popis]);
                $updTr->execute([$langCode, $key3, $popis]);
            }

            redirect(APP_URL . '/admin/nok_kody.php?' . http_build_query(['ok' => 1, 'afo' => $selectedAfoList]));
        } catch (Throwable $e) {
            $msg = 'Chyba při přidávání chyby: ' . $e->getMessage();
        }
    } else {
        $msg = 'Vyplňte prosím název chyby.';
    }
}

// 3. SKRYTÍ / ODKRYTÍ CHYBY
if (get('action') === 'toggle_nok_aktivni' && get('id')) {
    if (!verifyCsrfToken(get('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }
    try {
        db()->prepare('UPDATE nok_kody SET aktivni = 1 - aktivni WHERE id=?')->execute([(int)get('id')]);
        redirect(APP_URL . '/admin/nok_kody.php?' . http_build_query([
            'ok' => 1,
            'filter_active' => $filterActive,
            'afo' => $selectedAfoList,
        ]));
    } catch (Throwable $e) {
        $msg = 'Chyba při změně stavu: ' . $e->getMessage();
    }
}

// 4. PŘESTAVĚNÍ Z EXCELU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'rebuild_from_excel') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $cmd = 'php ' . escapeshellarg(APP_ROOT . '/scripts/import_nok_rebuild_from_excel.php');
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode === 0) {
        redirect(APP_URL . '/admin/nok_kody.php?' . http_build_query(['ok' => 1, 'rebuilt' => 1, 'afo' => $selectedAfoList]));
    }

    $msg = "Rebuild import selhal (exit {$exitCode}):\n" . implode("\n", $output);
}

$lang = $_SESSION['lang'] ?? 'cs';

// Zpracování filtru aktivních / skrytých
$whereActive = match ($filterActive) {
    'all' => '',
    'inactive' => 'WHERE n.aktivni = 0',
    default => 'WHERE n.aktivni = 1',
};

// Načtení dat chyb
$nokRows = $selectedAfoList !== [] ? db_all(
        "SELECT n.id,
            n.kod,
            n.aktivni,
            COALESCE(
                (SELECT `value` FROM translations WHERE lang_code=? AND `key`=CONCAT('nok_',LPAD(n.kod,3,'0'),'_popis') LIMIT 1),
                n.popis
            ) AS popis
     FROM nok_kody n
     $whereActive
     ORDER BY n.id",
    [$lang]
) : [];

// Načtení stávajících vazeb z matice
$matrixMap = [];
if ($afoList !== [] && $nokRows !== []) {
    $placeholders = implode(',', array_fill(0, count($afoList), '?'));
    $vals = db_all("SELECT afo, nok_kod_id, zobrazit, operator_excluded_default FROM nok_matrix_afo WHERE afo IN ($placeholders)", $afoList);
    foreach ($vals as $v) {
        $afo = (string)$v['afo'];
        $id = (int)$v['nok_kod_id'];
        $matrixMap[$id][$afo] = [
            'visible' => (int)$v['zobrazit'],
            'operatorExcludedDefault' => (int)$v['operator_excluded_default'],
        ];
    }
}

require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_nok') ?> - Matice AFO</span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
<?php if(get('ok')):?><div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.6rem 1rem;font-size:.82rem">✓ Uloženo</div><?php endif;?>
<?php if(isset($msg)):?><div class="alert mb-3" style="background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.25);color:var(--accent2);border-radius:8px;padding:.6rem 1rem;font-size:.82rem;white-space:pre-wrap"><?= h($msg) ?></div><?php endif;?>

<div class="card-dvd mb-3 no-print">
    <div class="card-body-dvd d-flex flex-wrap align-items-center justify-content-between gap-3">
        
        <!-- Akční tlačítka vlevo (Rebuild a Add form jsou striktně odděleny) -->
        <div class="d-flex gap-2 align-items-center">
            <form method="post" style="margin:0;display:none;">
                <input type="hidden" name="action" value="rebuild_from_excel">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <?php foreach ($selectedAfoList as $afo): ?><input type="hidden" name="afo[]" value="<?= h($afo) ?>"><?php endforeach; ?>
                <button type="submit" class="btn-dvd btn-dvd-sm" onclick="return confirm('Prestavet NOK ciselnik i matici podle uploads/NOKy.xlsx?')">
                    <i class="bi bi-arrow-repeat me-1"></i>Přestavět z Excelu
                </button>
            </form>
            <button type="button" class="btn-dvd btn-dvd-sm" onclick="openAddNokModal()">
                <i class="bi bi-plus-lg me-1"></i>Přidat novou chybu
            </button>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($selectedAfoList !== []): ?>
                <span style="font-size:.82rem;color:var(--muted)">Pracoviště <strong style="color:var(--text)"><?= h(implode(' | ', $selectedAfoLabels)) ?></strong></span>
            <?php else: ?>
                <span style="font-size:.82rem;color:var(--muted)">Pro zobrazení matice vyberte pracoviště.</span>
            <?php endif; ?>
            <button type="button" class="btn-outline" onclick="openWorkplaceModal()" style="padding:.35rem .7rem;font-size:.78rem">
                <i class="bi bi-building me-1"></i><?= $selectedAfoList !== [] ? 'Změnit pracoviště' : 'Vybrat pracoviště' ?>
            </button>
        </div>
        <div class="d-flex gap-2">
            <form method="get" style="margin:0">
                    <?php foreach ($selectedAfoList as $afo): ?><input type="hidden" name="afo[]" value="<?= h($afo) ?>"><?php endforeach; ?>
                <select name="filter_active" class="form-select form-select-sm" onchange="this.form.submit()" style="width:170px;font-size:.78rem">
                    <option value="active" <?= $filterActive === 'active' ? 'selected' : '' ?>>Stav: Pouze aktivní</option>
                    <option value="all" <?= $filterActive === 'all' ? 'selected' : '' ?>>Stav: Všechny chyby</option>
                    <option value="inactive" <?= $filterActive === 'inactive' ? 'selected' : '' ?>>Stav: Pouze skryté</option>
                </select>
            </form>
            <input type="text" id="search-nok" class="form-control form-control-sm" placeholder="Hledat chybu..." oninput="filterMatrix()" style="width:180px;font-size:.78rem">
        </div>

    </div>
</div>

<div id="afo-select-modal" data-required="<?= $selectedAfoList === [] ? '1' : '0' ?>" style="display:<?= $selectedAfoList === [] ? 'flex' : 'none' ?>;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem">
    <div role="dialog" aria-modal="true" aria-labelledby="afo-select-title" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;max-width:560px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,.5)">
        <div style="padding:1rem 1.2rem;border-bottom:1px solid var(--border)">
            <h2 id="afo-select-title" style="margin:0;font-size:1.05rem;color:var(--text)">Vyberte pracoviště</h2>
            <p style="margin:.35rem 0 0;font-size:.82rem;color:var(--muted)">Vyberte až pět pracovišť. Načtou se pouze jejich matice.</p>
        </div>
        <form method="get" style="padding:1rem 1.2rem">
            <input type="hidden" name="filter_active" value="<?= h($filterActive) ?>">
            <label for="afo-select-search" class="form-label-dvd">Hledat pracoviště</label>
            <input type="search" id="afo-select-search" class="form-control mb-2" placeholder="Číslo AFO, kód nebo název" oninput="filterAfoOptions()" autocomplete="off">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="form-label-dvd mb-0">Pracoviště</span>
                <span id="afo-selection-count" style="font-size:.78rem;color:var(--muted)"><?= count($selectedAfoList) ?> / 5 vybráno</span>
            </div>
            <div id="afo-select-list" style="height:48vh;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:.35rem">
                <?php foreach ($allAfoList as $afo): $meta = $afoWorkplaceMeta[$afo]; ?>
                    <?php $optionLabel = trim('#' . $afo . ' · ' . $meta['kod'] . ' · ' . $meta['nazev'] . ($meta['dil'] !== '' ? ' · ' . $meta['dil'] : '')); ?>
                    <label class="afo-option d-flex align-items-center gap-2 p-2" data-search="<?= h(mb_strtolower($optionLabel, 'UTF-8')) ?>" style="border-radius:5px;cursor:pointer;font-size:.85rem">
                        <input type="checkbox" name="afo[]" value="<?= h($afo) ?>" onchange="updateAfoSelectionLimit(this)" <?= in_array($afo, $selectedAfoList, true) ? 'checked' : '' ?>>
                        <span><?= h($optionLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <?php if ($selectedAfoList !== []): ?><button type="button" class="btn-outline" onclick="closeWorkplaceModal()">Zrušit</button><?php endif; ?>
                <button id="afo-load-button" type="submit" class="btn-dvd btn-dvd-sm" <?= $selectedAfoList === [] ? 'disabled' : '' ?>><i class="bi bi-check2 me-1"></i>Načíst matici</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- HLAVNÍ FORMULÁŘ MATICE -->
<!-- ========================================================================= -->
<?php if ($selectedAfoList !== []): ?>
<form method="post" id="matrix-form" onsubmit="return serializeMatrixBeforeSubmit()">
<input type="hidden" name="action" value="save_matrix">
<input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
<input type="hidden" name="matrix_json" id="matrix_json" value="">
<input type="hidden" name="texts_json" id="texts_json" value="">
<input type="hidden" name="operator_defaults_json" id="operator_defaults_json" value="">
<?php foreach ($selectedAfoList as $afo): ?><input type="hidden" name="afo[]" value="<?= h($afo) ?>"><?php endforeach; ?>
<input type="hidden" name="filter_active" value="<?= h($filterActive) ?>">

<div class="tbl-wrap">
<div class="overflow-x" style="max-height:70vh;overflow:auto;border:1px solid var(--border);border-radius:10px;">
<table class="tbl" id="matrix-table" style="width:100%;min-width:<?= 276 + count($afoList) * 170 ?>px;table-layout:auto;border-collapse:separate;border-spacing:0;">
    <thead>
        <tr>
            <th style="position:sticky;left:0;z-index:5;background:var(--surface2);color:var(--text);width:56px;min-width:56px;border-bottom:1px solid var(--border)">ID</th>
            <th style="position:sticky;left:56px;z-index:5;background:var(--surface2);color:var(--text);width:max-content;min-width:220px;max-width:360px;border-bottom:1px solid var(--border)">Chyba</th>
            <?php foreach($afoList as $afo):
                $meta = $afoWorkplaceMeta[$afo] ?? null;
                $headerMain = '#' . $afo;
                $headerSub = '';
                if ($meta) {
                    $headerSub = trim(($meta['kod'] !== '' ? $meta['kod'] : '') . ($meta['dil'] !== '' ? ' - ' . $meta['dil'] : ''));
                }
                $title = $headerMain;
                if ($meta) {
                    $parts = [$headerMain];
                    if ($meta['kod'] !== '') $parts[] = $meta['kod'];
                    if ($meta['nazev'] !== '') $parts[] = $meta['nazev'];
                    if ($meta['dil'] !== '') $parts[] = $meta['dil'];
                    if (!$meta['aktivni']) $parts[] = 'neaktivni';
                    $title = implode(' | ', $parts);
                }
            ?>
            <th class="afo-col" data-afo-search="<?= mb_strtolower($title, 'UTF-8') ?>" style="min-width:150px;text-align:center;line-height:1.2;background:var(--surface2);color:var(--text);border-bottom:1px solid var(--border)" title="<?= h($title) ?>">
                <div style="font-family:var(--mono);font-size:.72rem;"> <?= h($headerMain) ?> </div>
                <?php if ($headerSub !== ''): ?>
                <div style="font-size:.62rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:84px;margin:0 auto;"> <?= h($headerSub) ?> </div>
                <?php endif; ?>
            </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach($nokRows as $row):
            $id = (int)$row['id'];
            $isInactive = (int)$row['aktivni'] === 0;
        ?>
        <tr class="nok-row <?= $isInactive ? 'opacity-50' : '' ?>" data-nok-search="<?= mb_strtolower($id . ' ' . $row['popis'], 'UTF-8') ?>">
            <td style="position:sticky;left:0;z-index:4;background:var(--surface2);color:var(--text);font-family:var(--mono);border-bottom:1px solid var(--border)"><?= $id ?></td>
            <td style="position:sticky;left:56px;z-index:4;background:var(--surface2);color:var(--text);white-space:normal;width:max-content;min-width:220px;max-width:360px;border-bottom:1px solid var(--border)">
                <input type="hidden" class="nok-text-hidden" data-id="<?= $id ?>" id="nok_text_<?= $id ?>" value="<?= h($row['popis']) ?>">
                <div style="display:flex;align-items:center;gap:.5rem;min-width:0;">
                    <div id="nok_text_preview_<?= $id ?>" style="font-size:.82rem;line-height:1.35;white-space:normal;overflow-wrap:anywhere;flex:1 1 auto;min-width:0;color:var(--text)"><?= h($row['popis']) ?></div>
                    <div style="display:flex;align-items:center;gap:.3rem;flex:0 0 auto;">
                        <button type="button" class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;" onclick="openNokTextModal(<?= $id ?>)" title="Upravit text chyby">
                            <i class="bi bi-pencil"></i>
                        </button>
                        
                        <?php if (!$isInactive): ?>
                            <a href="?action=toggle_nok_aktivni&id=<?= $id ?>&filter_active=<?= urlencode($filterActive) ?><?= h(implode('', array_map(static fn(string $afo): string => '&afo%5B%5D=' . rawurlencode($afo), $selectedAfoList))) ?>&_csrf=<?= h(generateCsrfToken()) ?>"
                               class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;color:var(--warn);" 
                               onclick="return confirm('Opravdu chcete tuto chybu deaktivovat a skrýt?')" 
                               title="Skrýt / Deaktivovat chybu">
                                <i class="bi bi-eye-slash"></i>
                            </a>
                        <?php else: ?>
                            <a href="?action=toggle_nok_aktivni&id=<?= $id ?>&filter_active=<?= urlencode($filterActive) ?><?= h(implode('', array_map(static fn(string $afo): string => '&afo%5B%5D=' . rawurlencode($afo), $selectedAfoList))) ?>&_csrf=<?= h(generateCsrfToken()) ?>"
                               class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;color:var(--accent);" 
                               onclick="return confirm('Opravdu chcete tuto chybu znovu aktivovat?')" 
                               title="Znovu aktivovat / Odkrýt chybu">
                                <i class="bi bi-eye"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <?php foreach($afoList as $afo):
                $matrixState = $matrixMap[$id][$afo] ?? [];
                $visible = (int)($matrixState['visible'] ?? 0) === 1;
                $operatorExcludedDefault = (int)($matrixState['operatorExcludedDefault'] ?? 0) === 1;
            ?>
            <td class="afo-col-cell" style="text-align:left;border-bottom:1px solid var(--border);white-space:nowrap">
                <label class="d-flex align-items-center justify-content-between gap-1" style="font-size:.65rem;color:var(--muted);cursor:pointer" title="Zobrazit NOK na tomto pracovišti">
                    <?= h(t('nok_show_on_workplace')) ?> <input type="checkbox" value="1" class="matrix-cb" data-nok="<?= $id ?>" data-afo="<?= h($afo) ?>" <?= $visible ? 'checked' : '' ?>>
                </label>
                <label class="d-flex align-items-center justify-content-between gap-1 mt-1" style="font-size:.65rem;color:var(--muted);cursor:pointer" title="Vyloučit NOK z operátorského scrapu na tomto pracovišti">
                    <?= h(t('nok_operator_excluded_default')) ?> <input type="checkbox" class="operator-default-cb" data-nok="<?= $id ?>" data-afo="<?= h($afo) ?>" <?= $operatorExcludedDefault ? 'checked' : '' ?>>
                </label>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer d-flex justify-content-between align-items-center">
    <span><?= count($nokRows) ?> chyb · <?= count($selectedAfoList) ?> pracovišť</span>
    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-save me-1"></i>Uložit změny v matici</button>
</div>
</div>
</form>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL PRO ÚPRAVU TEXTU CHYBY (Není obalen formem) -->
<!-- ========================================================================= -->
<div id="nok-text-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:720px;width:92%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);">
        <div style="padding:1.1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
            <h5 style="margin:0;font-size:1rem;font-weight:600;color:var(--text);"><i class="bi bi-pencil-square me-2" style="color:var(--accent)"></i>Upravit text chyby</h5>
            <button type="button" onclick="closeNokTextModal()" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:1.1rem 1.3rem;display:grid;gap:.8rem;">
            <div style="font-size:.8rem;color:var(--muted);">ID chyby: <span id="nok-text-modal-id" class="mono"></span></div>
            <textarea id="nok-text-modal-input" class="form-control" rows="5" style="font-size:.9rem;line-height:1.45;"></textarea>
        </div>
        <div style="padding:1rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" onclick="closeNokTextModal()" class="btn-outline" style="padding:.42rem .95rem;font-size:.82rem;">Zrušit</button>
            <button type="button" onclick="applyNokTextModal()" class="btn-dvd btn-dvd-sm">Potvrdit text</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL PRO PŘIDÁNÍ NOVÉ CHYBY (Samostatný form) -->
<!-- ========================================================================= -->
<div id="add-nok-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:500px;width:92%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);">
        <form method="post">
            <input type="hidden" name="action" value="add_nok_kod">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <?php foreach ($selectedAfoList as $afo): ?><input type="hidden" name="afo[]" value="<?= h($afo) ?>"><?php endforeach; ?>
            
            <div style="padding:1.1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                <h5 style="margin:0;font-size:1rem;font-weight:600;color:var(--text);"><i class="bi bi-plus-circle me-2" style="color:var(--accent)"></i>Přidat novou chybu</h5>
                <button type="button" onclick="closeAddNokModal()" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;">&times;</button>
            </div>
            <div style="padding:1.1rem 1.3rem;">
                <label class="form-label-dvd" for="new_nok_popis">Název chyby <span class="req">*</span></label>
                <input type="text" name="new_nok_popis" id="new_nok_popis" class="form-control" placeholder="např. Poškozená izolace" required autocomplete="off">
            </div>
            <div style="padding:1rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
                <button type="button" onclick="closeAddNokModal()" class="btn-outline" style="padding:.42rem .95rem;font-size:.82rem;">Zrušit</button>
                <button type="submit" class="btn-dvd btn-dvd-sm">Vytvořit chybu</button>
            </div>
        </form>
    </div>
</div>

</div> <!-- /.content -->

<script>
let activeNokTextId = null;

function filterAfoOptions() {
    const query = (document.getElementById('afo-select-search')?.value || '').toLocaleLowerCase('cs-CZ').trim();
    document.querySelectorAll('.afo-option').forEach(option => {
        const visible = query === '' || (option.dataset.search || '').includes(query);
        option.style.setProperty('display', visible ? 'flex' : 'none', 'important');
    });
}

function updateAfoSelectionLimit(changedCheckbox) {
    const checkboxes = [...document.querySelectorAll('#afo-select-list input[type="checkbox"]')];
    let selectedCount = checkboxes.filter(checkbox => checkbox.checked).length;
    if (changedCheckbox.checked && selectedCount > 5) {
        changedCheckbox.checked = false;
        selectedCount--;
    }
    const countLabel = document.getElementById('afo-selection-count');
    const submitButton = document.getElementById('afo-load-button');
    if (countLabel) countLabel.textContent = selectedCount + ' / 5 vybráno';
    if (submitButton) submitButton.disabled = selectedCount === 0;
    checkboxes.forEach(checkbox => {
        checkbox.disabled = !checkbox.checked && selectedCount >= 5;
    });
}

function openWorkplaceModal() {
    const modal = document.getElementById('afo-select-modal');
    if (!modal) return;
    modal.style.display = 'flex';
    const search = document.getElementById('afo-select-search');
    if (search) {
        search.value = '';
        filterAfoOptions();
        updateAfoSelectionLimit({ checked: false });
        search.focus();
    }
}

function closeWorkplaceModal() {
    const modal = document.getElementById('afo-select-modal');
    if (modal && modal.dataset.required !== '1') modal.style.display = 'none';
}

if (document.getElementById('afo-select-modal')?.dataset.required === '1') {
    document.getElementById('afo-select-search')?.focus();
}

// Modal pro novou chybu
function openAddNokModal() {
    document.getElementById('add-nok-modal').style.display = 'flex';
    document.getElementById('new_nok_popis').focus();
}
function closeAddNokModal() {
    document.getElementById('add-nok-modal').style.display = 'none';
}

// Modal pro úpravu existující chyby
function openNokTextModal(id) {
    const hidden = document.getElementById('nok_text_' + id);
    activeNokTextId = String(id);
    document.getElementById('nok-text-modal-id').textContent = String(id);
    document.getElementById('nok-text-modal-input').value = hidden.value || '';
    document.getElementById('nok-text-modal').style.display = 'flex';
    document.getElementById('nok-text-modal-input').focus();
}
function closeNokTextModal() {
    document.getElementById('nok-text-modal').style.display = 'none';
    activeNokTextId = null;
}
function applyNokTextModal() {
    if (!activeNokTextId) return;
    const inputVal = document.getElementById('nok-text-modal-input').value;
    document.getElementById('nok_text_' + activeNokTextId).value = inputVal;
    document.getElementById('nok_text_preview_' + activeNokTextId).textContent = inputVal;
    closeNokTextModal();
}

// Odeslání JSON matice
function serializeMatrixBeforeSubmit() {
    const matrix = {};
    const texts = {};
    const operatorDefaults = {};

    document.querySelectorAll('.nok-text-hidden').forEach(el => {
        const id = el.getAttribute('data-id');
        if (id) texts[id] = el.value || '';
    });

    document.querySelectorAll('.operator-default-cb').forEach(el => {
        const id = el.getAttribute('data-nok');
        const afo = el.getAttribute('data-afo');
        if (id && afo) {
            if (!operatorDefaults[id]) operatorDefaults[id] = {};
            operatorDefaults[id][afo] = el.checked ? 1 : 0;
        }
    });

    document.querySelectorAll('.matrix-cb:checked').forEach(cb => {
        const nokId = cb.getAttribute('data-nok');
        const afo = cb.getAttribute('data-afo');
        if (nokId && afo) {
            if (!matrix[nokId]) matrix[nokId] = {};
            matrix[nokId][afo] = 1;
        }
    });

    document.getElementById('matrix_json').value = JSON.stringify(matrix);
    document.getElementById('texts_json').value = JSON.stringify(texts);
    document.getElementById('operator_defaults_json').value = JSON.stringify(operatorDefaults);
    return true;
}

// Hledání v matici
function filterMatrix() {
    const qNok = (document.getElementById('search-nok')?.value || '').toLowerCase().trim();

    document.querySelectorAll('.nok-row').forEach(tr => {
        const searchStr = tr.getAttribute('data-nok-search') || '';
        tr.style.display = (!qNok || searchStr.includes(qNok)) ? '' : 'none';
    });

}
</script>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>