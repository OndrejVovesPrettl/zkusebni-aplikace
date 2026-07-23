<?php
// admin/nok_kody.php - NOK matrix editor (X = AFO, Y = NOK ID)
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('nav_nok');
$activePage = 'nok';
ensureNokKodyMatrixSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_matrix') {
    if (!verifyCsrfToken(post('_csrf'))) {
        http_response_code(403);
        die('CSRF');
    }

    $posted = post('matrix');
    if (!is_array($posted)) {
        $posted = [];
    }
    $postedTexts = post('texts');
    if (!is_array($postedTexts)) {
        $postedTexts = [];
    }

    $afoList = db_all('SELECT DISTINCT afo FROM nok_matrix_afo ORDER BY CAST(afo AS UNSIGNED), afo');
    $afoKeys = array_map(static fn(array $r): string => trim((string)$r['afo']), $afoList);

    $nokRows = db_all('SELECT id, popis, aktivni FROM nok_kody WHERE aktivni=1 ORDER BY id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM nok_matrix_afo');

        $ins = $pdo->prepare(
            'INSERT INTO nok_matrix_afo (afo, nok_kod_id, zobrazit) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE zobrazit=VALUES(zobrazit), updated_at=NOW()'
        );
        $updText = $pdo->prepare('UPDATE nok_kody SET popis=? WHERE id=?');
        $updTr = $pdo->prepare(
            'INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()'
        );

        foreach ($nokRows as $nok) {
            $nokId = (int)$nok['id'];
            $newText = trim((string)($postedTexts[(string)$nokId] ?? $nok['popis'] ?? ''));
            if ($newText === '') {
                $newText = (string)($nok['popis'] ?? '');
            }
            $updText->execute([$newText, $nokId]);
            $keyBase = 'nok_' . str_pad((string)$nokId, 3, '0', STR_PAD_LEFT) . '_popis';
            $updTr->execute(['cs', $keyBase, $newText]);

            foreach ($afoKeys as $afo) {
                $val = 0;
                if (isset($posted[(string)$nokId]) && is_array($posted[(string)$nokId]) && isset($posted[(string)$nokId][$afo])) {
                    $val = ((int)$posted[(string)$nokId][$afo] === 1) ? 1 : 0;
                }
                $ins->execute([$afo, $nokId, $val]);
            }
        }

        $pdo->commit();
        redirect(APP_URL . '/admin/nok_kody.php?ok=1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = 'Ulozeni matice selhalo: ' . $e->getMessage();
    }
}

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

$nokRows = db_all(
    "SELECT n.id,
            n.kod,
            n.aktivni,
            COALESCE(
                (SELECT `value` FROM translations WHERE lang_code=? AND `key`=CONCAT('nok_',LPAD(n.kod,3,'0'),'_popis') LIMIT 1),
                n.popis
            ) AS popis
     FROM nok_kody n
     WHERE n.aktivni=1
     ORDER BY n.id",
    [$lang]
);

$afoRows = db_all('SELECT DISTINCT afo FROM nok_matrix_afo ORDER BY CAST(afo AS UNSIGNED), afo');
$afoList = array_map(static fn(array $r): string => (string)$r['afo'], $afoRows);

$afoWorkplaceMeta = [];
if ($afoList !== []) {
    $ids = [];
    foreach ($afoList as $afo) {
        if (preg_match('/^\d+$/', $afo)) {
            $ids[] = (int)$afo;
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn(int $v): bool => $v > 0)));
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            'SELECT cislo_id, kod_pracovisite, nazev_pracovisite, nazev_dilu, aktivni
             FROM pracovisite
             WHERE cislo_id IN (' . $ph . ')',
            $ids
        );
        foreach ($rows as $r) {
            $afoWorkplaceMeta[(string)$r['cislo_id']] = [
                'kod' => (string)($r['kod_pracovisite'] ?? ''),
                'nazev' => (string)($r['nazev_pracovisite'] ?? ''),
                'dil' => (string)($r['nazev_dilu'] ?? ''),
                'aktivni' => (int)($r['aktivni'] ?? 0) === 1,
            ];
        }
    }
}

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
<?php if(get('ok')):?><div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.6rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div><?php endif;?>
<?php if(get('rebuilt')):?><div class="alert mb-3" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.6rem 1rem;font-size:.82rem">Excel import prestavel ciselnik NOK i matici AFO.</div><?php endif;?>
<?php if(isset($msg)):?><div class="alert mb-3" style="background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.25);color:var(--accent2);border-radius:8px;padding:.6rem 1rem;font-size:.82rem;white-space:pre-wrap"><?= h($msg) ?></div><?php endif;?>

<div class="card-dvd mb-3 no-print">
    <div class="card-body-dvd">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="action" value="rebuild_from_excel">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <button type="submit" class="btn-dvd btn-dvd-sm" onclick="return confirm('Prestavet NOK ciselnik i matici podle uploads/NOKy.xlsx?')">
                <i class="bi bi-arrow-repeat me-1"></i>Prestavet z Excelu
            </button>
            <span style="font-size:.78rem;color:var(--muted)">Smaze puvodni NOK logiku, nastavi ID chyby jako klic a naplni AFO matici.</span>
        </form>
    </div>
</div>

<form method="post">
<input type="hidden" name="action" value="save_matrix">
<input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">

<div id="nok-text-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:720px;width:92%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);">
        <div style="padding:1.1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.7rem;">
            <h5 style="margin:0;font-size:1rem;font-weight:600;color:var(--text);"><i class="bi bi-pencil-square me-2" style="color:var(--accent)"></i>Upravit text chyby</h5>
            <button type="button" onclick="closeNokTextModal()" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.1rem 1.3rem;display:grid;gap:.8rem;">
            <div style="font-size:.8rem;color:var(--muted);">ID chyby: <span id="nok-text-modal-id" class="mono"></span></div>
            <textarea id="nok-text-modal-input" class="form-control" rows="5" style="font-size:.9rem;line-height:1.45;"></textarea>
        </div>
        <div style="padding:1rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" onclick="closeNokTextModal()" class="btn-outline" style="padding:.42rem .95rem;font-size:.82rem;">Zrušit</button>
            <button type="button" onclick="applyNokTextModal()" class="btn-dvd btn-dvd-sm">Použít text</button>
        </div>
    </div>
</div>

<div class="tbl-wrap">
<div class="overflow-x" style="max-height:70vh;overflow:auto;border:1px solid rgba(255,255,255,.08);border-radius:10px;">
<table class="tbl" style="min-width:1300px;border-collapse:separate;border-spacing:0;">
    <thead>
        <tr>
            <th style="position:sticky;left:0;z-index:5;background:#0f141b;min-width:90px;">ID chyby</th>
            <th style="position:sticky;left:90px;z-index:5;background:#0f141b;min-width:320px;">Chyba</th>
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
            <th style="min-width:94px;text-align:center;line-height:1.2" title="<?= h($title) ?>">
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
        ?>
        <tr>
            <td style="position:sticky;left:0;z-index:4;background:#121a23;font-family:var(--mono);"><?= $id ?></td>
            <td style="position:sticky;left:90px;z-index:4;background:#121a23;white-space:normal;">
                <input type="hidden" name="texts[<?= $id ?>]" id="nok_text_<?= $id ?>" value="<?= h($row['popis']) ?>">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.6rem;min-width:280px;">
                    <div id="nok_text_preview_<?= $id ?>" style="font-size:.82rem;line-height:1.35;white-space:normal;flex:1 1 auto;"><?= h($row['popis']) ?></div>
                    <button type="button" class="btn-outline" style="padding:.28rem .5rem;font-size:.72rem;white-space:nowrap;flex:0 0 auto;" onclick="openNokTextModal(<?= $id ?>)" title="Upravit text chyby" aria-label="Upravit text chyby">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
            </td>
            <?php foreach($afoList as $afo):
                $v = (int)($matrixMap[$id][$afo] ?? 0);
            ?>
            <td style="text-align:center;">
                <input type="hidden" name="matrix[<?= $id ?>][<?= h($afo) ?>]" value="0">
                <input type="checkbox" value="1" name="matrix[<?= $id ?>][<?= h($afo) ?>]" <?= $v===1?'checked':'' ?>>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer d-flex justify-content-between align-items-center">
    <span><?= count($nokRows) ?> NOK ID, <?= count($afoList) ?> AFO sloupcu</span>
    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-save me-1"></i><?= t('save') ?></button>
</div>
</div>
</form>
</div>
<script>
let activeNokTextId = null;

function openNokTextModal(id) {
    const hidden = document.getElementById('nok_text_' + id);
    const modal = document.getElementById('nok-text-modal');
    const input = document.getElementById('nok-text-modal-input');
    const idLabel = document.getElementById('nok-text-modal-id');
    if (!hidden || !modal || !input || !idLabel) return;
    activeNokTextId = String(id);
    idLabel.textContent = String(id);
    input.value = hidden.value || '';
    modal.style.display = 'flex';
    input.focus();
    input.select();
}

function closeNokTextModal() {
    const modal = document.getElementById('nok-text-modal');
    if (modal) modal.style.display = 'none';
    activeNokTextId = null;
}

function applyNokTextModal() {
    if (!activeNokTextId) return;
    const hidden = document.getElementById('nok_text_' + activeNokTextId);
    const preview = document.getElementById('nok_text_preview_' + activeNokTextId);
    const input = document.getElementById('nok-text-modal-input');
    if (!hidden || !preview || !input) return;
    hidden.value = input.value;
    preview.textContent = input.value;
    closeNokTextModal();
}

document.getElementById('nok-text-modal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeNokTextModal();
    }
});
</script>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
