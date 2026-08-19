<?php
// admin/nok_kody.php - NOK matrix editor (STABILNÍ VERZE)
@ini_set('max_input_vars', '10000');

require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_nok');
$activePage = 'nok';
ensureNokKodyMatrixSchema();

$msg = null;

// 1. ULOŽENÍ MATICE A TEXTŮ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_matrix') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $matrixJson = post('matrix_json');
    $textsJson = post('texts_json');

    $posted = is_string($matrixJson) ? (json_decode($matrixJson, true) ?: []) : [];
    $postedTexts = is_string($textsJson) ? (json_decode($textsJson, true) ?: []) : [];

    $afoList = db_all('SELECT CAST(cislo_id AS CHAR) AS afo FROM pracovisite WHERE aktivni = 1 AND cislo_id IS NOT NULL AND cislo_id > 0 ORDER BY CAST(cislo_id AS UNSIGNED), cislo_id');
    $afoKeys = array_map(static fn(array $r): string => trim((string)$r['afo']), $afoList);

    $filterActive = get('filter_active', 'active');
    $nokRows = db_all('SELECT id, kod, popis, aktivni FROM nok_kody WHERE aktivni=1 ORDER BY id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO nok_matrix_afo (afo, nok_kod_id, kod, popis, zobrazit) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE zobrazit=VALUES(zobrazit), kod=VALUES(kod), popis=VALUES(popis), updated_at=NOW()'
        );
        $updText = $pdo->prepare('UPDATE nok_kody SET popis=? WHERE id=?');
        $updTr = $pdo->prepare(
            'INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()'
        );

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
                $ins->execute([$afo, $nokId, $nokKod, $newText, $val]);
            }
        }

        $pdo->commit();
        redirect(APP_URL . '/admin/nok_kody.php?ok=1&filter_active=' . urlencode($filterActive));
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
            
            $key1 = 'nok_' . str_pad((string)$nextKod, 3, '0', STR_PAD_LEFT) . '_popis';
            $key2 = 'nok_' . (string)$nextKod . '_popis';
            $key3 = 'nok_' . str_pad((string)$newId, 3, '0', STR_PAD_LEFT) . '_popis';
            
            $updTr = db()->prepare('INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()');
            foreach (['cs', 'en', 'uk'] as $langCode) {
                $updTr->execute([$langCode, $key1, $popis]);
                $updTr->execute([$langCode, $key2, $popis]);
                $updTr->execute([$langCode, $key3, $popis]);
            }

            redirect(APP_URL . '/admin/nok_kody.php?ok=1');
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
        redirect(APP_URL . '/admin/nok_kody.php?ok=1&filter_active=' . urlencode((string)get('filter_active', 'active')));
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
        redirect(APP_URL . '/admin/nok_kody.php?ok=1&rebuilt=1');
    }

    $msg = "Rebuild import selhal (exit {$exitCode}):\n" . implode("\n", $output);
}

$lang = $_SESSION['lang'] ?? 'cs';

// Zpracování filtru aktivních / skrytých
$filterActive = get('filter_active', 'active');
$whereActive = match ($filterActive) {
    'all' => '',
    'inactive' => 'WHERE n.aktivni = 0',
    default => 'WHERE n.aktivni = 1',
};

// Načtení dat chyb
$nokRows = db_all(
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
);

// Načtení aktivních pracovišť (sloupců)
$afoRows = db_all('SELECT CAST(cislo_id AS CHAR) AS afo, kod_pracovisite, nazev_pracovisite, nazev_dilu, aktivni 
                   FROM pracovisite 
                   WHERE aktivni = 1 AND cislo_id IS NOT NULL AND cislo_id > 0 
                   ORDER BY CAST(cislo_id AS UNSIGNED), cislo_id');

$afoList = [];
$afoWorkplaceMeta = [];
foreach ($afoRows as $r) {
    $afoStr = (string)$r['afo'];
    $afoList[] = $afoStr;
    $afoWorkplaceMeta[$afoStr] = [
        'kod' => (string)($r['kod_pracovisite'] ?? ''),
        'nazev' => (string)($r['nazev_pracovisite'] ?? ''),
        'dil' => (string)($r['nazev_dilu'] ?? ''),
        'aktivni' => (int)($r['aktivni'] ?? 0) === 1,
    ];
}

// Načtení stávajících vazeb z matice
$matrixMap = [];
if ($afoList !== [] && $nokRows !== []) {
    $vals = db_all('SELECT afo, nok_kod_id, zobrazit FROM nok_matrix_afo');
    foreach ($vals as $v) {
        $afo = (string)$v['afo'];
        $id = (int)$v['nok_kod_id'];
        $matrixMap[$id][$afo] = (int)$v['zobrazit'];
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
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="rebuild_from_excel">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <button type="submit" class="btn-dvd btn-dvd-sm" onclick="return confirm('Prestavet NOK ciselnik i matici podle uploads/NOKy.xlsx?')">
                    <i class="bi bi-arrow-repeat me-1"></i>Přestavět z Excelu
                </button>
            </form>
            <button type="button" class="btn-dvd btn-dvd-sm" onclick="openAddNokModal()">
                <i class="bi bi-plus-lg me-1"></i>Přidat novou chybu
            </button>
        </div>

        <!-- Filtry a hledání vpravo -->
        <div class="d-flex gap-2">
            <form method="get" style="margin:0">
                <select name="filter_active" class="form-select form-select-sm" onchange="this.form.submit()" style="width:170px;font-size:.78rem">
                    <option value="active" <?= $filterActive === 'active' ? 'selected' : '' ?>>Stav: Pouze aktivní</option>
                    <option value="all" <?= $filterActive === 'all' ? 'selected' : '' ?>>Stav: Všechny chyby</option>
                    <option value="inactive" <?= $filterActive === 'inactive' ? 'selected' : '' ?>>Stav: Pouze skryté</option>
                </select>
            </form>
            <input type="text" id="search-afo" class="form-control form-control-sm" placeholder="Hledat proces (AFO)..." oninput="filterMatrix()" style="width:180px;font-size:.78rem">
            <input type="text" id="search-nok" class="form-control form-control-sm" placeholder="Hledat chybu..." oninput="filterMatrix()" style="width:180px;font-size:.78rem">
        </div>

    </div>
</div>

<!-- ========================================================================= -->
<!-- HLAVNÍ FORMULÁŘ MATICE -->
<!-- ========================================================================= -->
<form method="post" id="matrix-form" onsubmit="return serializeMatrixBeforeSubmit()">
<input type="hidden" name="action" value="save_matrix">
<input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
<input type="hidden" name="matrix_json" id="matrix_json" value="">
<input type="hidden" name="texts_json" id="texts_json" value="">

<div class="tbl-wrap">
<div class="overflow-x" style="max-height:70vh;overflow:auto;border:1px solid var(--border);border-radius:10px;">
<table class="tbl" id="matrix-table" style="min-width:1300px;border-collapse:separate;border-spacing:0;">
    <thead>
        <tr>
            <th style="position:sticky;left:0;z-index:5;background:var(--surface2);color:var(--text);min-width:90px;border-bottom:1px solid var(--border)">ID chyby</th>
            <th style="position:sticky;left:90px;z-index:5;background:var(--surface2);color:var(--text);min-width:320px;border-bottom:1px solid var(--border)">Chyba</th>
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
            <th class="afo-col" data-afo-search="<?= mb_strtolower($title, 'UTF-8') ?>" style="min-width:94px;text-align:center;line-height:1.2;background:var(--surface2);color:var(--text);border-bottom:1px solid var(--border)" title="<?= h($title) ?>">
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
            <td style="position:sticky;left:90px;z-index:4;background:var(--surface2);color:var(--text);white-space:normal;border-bottom:1px solid var(--border)">
                <input type="hidden" class="nok-text-hidden" data-id="<?= $id ?>" id="nok_text_<?= $id ?>" value="<?= h($row['popis']) ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;min-width:280px;">
                    <div id="nok_text_preview_<?= $id ?>" style="font-size:.82rem;line-height:1.35;white-space:normal;flex:1 1 auto;color:var(--text)"><?= h($row['popis']) ?></div>
                    <div style="display:flex;align-items:center;gap:.3rem;flex:0 0 auto;">
                        <button type="button" class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;" onclick="openNokTextModal(<?= $id ?>)" title="Upravit text chyby">
                            <i class="bi bi-pencil"></i>
                        </button>
                        
                        <?php if (!$isInactive): ?>
                            <a href="?action=toggle_nok_aktivni&id=<?= $id ?>&filter_active=<?= urlencode($filterActive) ?>&_csrf=<?= h(generateCsrfToken()) ?>" 
                               class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;color:var(--warn);" 
                               onclick="return confirm('Opravdu chcete tuto chybu deaktivovat a skrýt?')" 
                               title="Skrýt / Deaktivovat chybu">
                                <i class="bi bi-eye-slash"></i>
                            </a>
                        <?php else: ?>
                            <a href="?action=toggle_nok_aktivni&id=<?= $id ?>&filter_active=<?= urlencode($filterActive) ?>&_csrf=<?= h(generateCsrfToken()) ?>" 
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
                $v = (int)($matrixMap[$id][$afo] ?? 0);
            ?>
            <td class="afo-col-cell" style="text-align:center;border-bottom:1px solid var(--border)">
                <input type="checkbox" value="1" class="matrix-cb" data-nok="<?= $id ?>" data-afo="<?= h($afo) ?>" <?= $v===1?'checked':'' ?>>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer d-flex justify-content-between align-items-center">
    <span><?= count($nokRows) ?> zobrazených chyb</span>
    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-save me-1"></i>Uložit změny v matici</button>
</div>
</div>
</form>

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

    document.querySelectorAll('.nok-text-hidden').forEach(el => {
        const id = el.getAttribute('data-id');
        if (id) texts[id] = el.value || '';
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
    return true;
}

// Hledání v matici
function filterMatrix() {
    const qAfo = (document.getElementById('search-afo')?.value || '').toLowerCase().trim();
    const qNok = (document.getElementById('search-nok')?.value || '').toLowerCase().trim();

    document.querySelectorAll('.nok-row').forEach(tr => {
        const searchStr = tr.getAttribute('data-nok-search') || '';
        tr.style.display = (!qNok || searchStr.includes(qNok)) ? '' : 'none';
    });

    document.querySelectorAll('.afo-col').forEach((th, index) => {
        const searchStr = th.getAttribute('data-afo-search') || '';
        const visible = !qAfo || searchStr.includes(qAfo);
        th.style.display = visible ? '' : 'none';

        document.querySelectorAll(`.nok-row td.afo-col-cell:nth-child(${index + 3})`).forEach(td => {
            td.style.display = visible ? '' : 'none';
        });
    });
}
</script>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>