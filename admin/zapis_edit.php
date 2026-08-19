--- START OF FILE Paste August 18, 2026 - 4:53PM ---

<?php
// admin/zapis_edit.php – Editace existujícího záznamu (Sjednoceno s index.php)
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'write']);

if (!canWrite()) {
    http_response_code(403);
    die('<pre style="color:red">403 Forbidden – Nedostatečná oprávnění.</pre>');
}

$id = (int)get('id');
if (!$id) redirect(APP_URL . '/admin/zaznamy.php');

$z = db_row('SELECT * FROM zapisy WHERE id=?', [$id]);
if (!$z) { http_response_code(404); die(t('record_not_found')); }

$workers      = db_all('SELECT zp.*, p.interni_id, p.jmeno, p.smennost FROM zapis_pracovnici zp LEFT JOIN pracovnici p ON p.id=zp.pracovnik_id WHERE zp.zapis_id=? ORDER BY zp.poradi, zp.id', [$id]);
$prostoje     = db_all("SELECT id, typ_kod, cas_od, cas_do, minuty, pocet, poznamka
    FROM zapisy_prostoje
    WHERE zapis_id=? AND UPPER(typ_kod) NOT IN ('TECH_SUM','PLAN_SUM','VYR_SUM','KVAL_SUM','LOG_SUM','OST_SUM')
    ORDER BY id", [$id]);
$nokZaznamy   = db_all('SELECT nok_kod_id, pocet FROM zapis_nok WHERE zapis_id=? AND pocet>0', [$id]);
$hodiny       = db_all('SELECT * FROM hodinove_sledovani WHERE zapis_id=? ORDER BY hodina_od', [$id]);

$prostRows = db_all('SELECT UPPER(typ_kod) AS typ_kod, SUM(minuty) AS minuty
    FROM zapisy_prostoje
    WHERE zapis_id=?
    GROUP BY UPPER(typ_kod)', [$id]);
$prostSummary = db_row("SELECT
    SUM(CASE WHEN kategorie='tech' THEN minuty ELSE 0 END) AS tech,
    SUM(CASE WHEN kategorie='vyr' THEN minuty ELSE 0 END) AS vyr,
    SUM(CASE WHEN kategorie='kval' THEN minuty ELSE 0 END) AS kval,
    SUM(CASE WHEN kategorie='log' THEN minuty ELSE 0 END) AS log,
    SUM(CASE WHEN kategorie='ost' THEN minuty ELSE 0 END) AS ost,
    SUM(CASE WHEN kategorie='plan' THEN minuty ELSE 0 END) AS plan,
    SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost') THEN minuty ELSE 0 END) AS nepl,
    SUM(minuty) AS celkem
    FROM zapisy_prostoje
    WHERE zapis_id=?", [$id]) ?: [];

$nokMap = [];
foreach ($nokZaznamy as $n) $nokMap[(int)$n['nok_kod_id']] = (int)$n['pocet'];

$hodinyMap = [];
foreach ($hodiny as $h) $hodinyMap[$h['hodina_od']] = $h;

// Získání sdílených dat (stejné jako v index.php)
$pracovisteList = getPracovisteList();
$pracovniciList = getPracovniciList();
$nokKodyFlat    = getNokKodyGrouped();
$nokAfoMap      = getNokAfoVisibilityMap();
$prostojTypy    = getProstojTypyList();
$smenyList      = getSmenyList();
$prestavkyList  = getPrestavkyList();
$prostojTypyMap = array_column($prostojTypy, 'popis', 'kod');

// Prepare NOK groups for template (converted to simple list without grouping)
$nokTemplateGroups = [
    [
        'key' => 'all',
        'label' => '',
        'items' => array_map(static function (array $nok): array {
            return [
                'id' => (int)$nok['id'],
                'kod' => (string)$nok['kod'],
                'popis' => (string)$nok['popis'],
            ];
        }, array_values(array_filter($nokKodyFlat, static function (array $nok): bool {
            return (int)($nok['id'] ?? 0) !== 134;
        })))
    ]
];

$workerPositionLabels = [t('pos_operator'), t('pos_serizovac'), t('pos_mistr'), t('pos_jina')];
$workerPositionOptions = '';
foreach ($workerPositionLabels as $positionLabel) {
    $workerPositionOptions .= '<option value="' . h($positionLabel) . '">' . h($positionLabel) . '</option>';
}

$pracovniciSuggest = array_map(static function (array $pw): array {
    $label = (string)$pw['jmeno'] . ($pw['smennost'] ? ' (' . (string)$pw['smennost'] . ')' : '');
    return [
        'id' => (int)$pw['id'],
        'label' => $label,
        'jmeno' => (string)$pw['jmeno'],
        'smennost' => (string)($pw['smennost'] ?? ''),
        'search' => mb_strtolower(implode(' ', [(string)$pw['jmeno'], (string)($pw['smennost'] ?? ''), $label]), 'UTF-8'),
    ];
}, $pracovniciList);

$pracovisteSuggest = array_map(static function (array $pw): array {
    $label = '[' . $pw['cislo_id'] . '] ' . $pw['zakaznik'] . ' – ' . $pw['nazev_dilu'] . ' (' . $pw['kod_pracovisite'] . ')';
    return [
        'id' => (int)$pw['id'],
        'cisloId' => (int)($pw['cislo_id'] ?? 0),
        'label' => $label,
        'zakaznik' => (string)($pw['zakaznik'] ?? ''),
        'projekt' => (string)($pw['projekt'] ?? ''),
        'verze' => (string)($pw['verze'] ?? ''),
        'afo' => (string)($pw['kod_pracovisite'] ?? ''),
        'nazev' => (string)($pw['nazev_pracovisite'] ?? ''),
        'dil' => (string)($pw['nazev_dilu'] ?? ''),
        'sap' => (string)($pw['sap_cislo'] ?? ''),
        'ns' => (string)($pw['nakladove_stredisko'] ?? ''),
        'norma' => $pw['norma_ks_min'] ?? '',
        'cycle' => $pw['cyklus_min'] ?? '',
        'typ' => trim((string)($pw['typ'] ?? '')),
        'search' => mb_strtolower(implode(' ', [$label, (string)($pw['zakaznik'] ?? ''), (string)($pw['projekt'] ?? ''), (string)($pw['verze'] ?? ''), (string)($pw['kod_pracovisite'] ?? ''), (string)($pw['nazev_pracovisite'] ?? ''), (string)($pw['nazev_dilu'] ?? ''), (string)($pw['sap_cislo'] ?? '')]), 'UTF-8'),
    ];
}, $pracovisteList);

$jsLang = [
    'saved'                => t('saved'),
    'save_confirmation'    => t('save_entry') . ' ' . t('save_confirmation_msg'),
    'error_save'           => t('error_save'),
    'confirm_delete'       => t('confirm_delete'),
    'saving'               => t('saving'),
    'summary_net_time'     => t('summary_net_time'),
    'summary_produced'     => t('summary_produced'),
    'summary_nok'          => t('summary_nok'),
    'summary_ok'           => t('summary_ok'),
    'summary_oee'          => t('summary_oee'),
    'oee_status_above'     => t('oee_status_above'),
    'oee_status_below'     => t('oee_status_below'),
    'oee_status_far_below' => t('oee_status_far_below'),
    'error_required_fields'=> t('error_required_fields'),
    'nok_mismatch'         => t('nok_mismatch'),
    'nok_mismatch_auto_fill' => t('nok_mismatch_auto_fill'),
    'nok_mismatch_manual'  => t('nok_mismatch_manual'),
    'nok_mismatch_exceeded'=> t('nok_mismatch_exceeded'),
    'nok_mismatch_missing_ostatni' => t('nok_mismatch_missing_ostatni'),
    'sec_nok_empty_for_afo'=> t('sec_nok_empty_for_afo'),
];

$pageTitle  = t('edit') . ' #' . $id;
$activePage = 'zaznamy';
?>
<script>
window.LANG_TEXT = <?= json_encode($jsLang) ?>;
</script>
<?php require APP_ROOT . '/includes/layout.php'; ?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3 topbar-mainline" style="flex:1">
        <button class="btn-icon d-md-none" id="sidebar-toggle" style="margin-right:auto"><i class="bi bi-list fs-5"></i></button>
        <a href="zapis_detail.php?id=<?= $id ?>" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><?= t('edit') ?> <span class="mono text-accent">#<?= $id ?></span></span>
        <span id="typ-badge" class="badge-<?= $z['typ'] ?>"><?= strtoupper($z['typ']) ?></span>
        <span class="topbar-meta" id="header-meta"></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print" style="justify-content:flex-end;flex-wrap:wrap">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

<?php if (isSuperAdmin()): ?>
<div id="nok-afo-debug" class="card-dvd mb-3 no-print" style="display:none;">
    <div class="card-header-dvd">
        <span class="s-num">DBG</span>
        <span class="s-title"><i class="bi bi-bug me-2" style="color:var(--warn)"></i>NOK/AFO debug</span>
    </div>
    <div class="card-body-dvd" style="font-size:.78rem;color:var(--muted);display:grid;gap:.35rem;">
        <div><strong>AFO text:</strong> <span id="dbg-afo-text">—</span></div>
        <div><strong>AFO tokeny:</strong> <span id="dbg-afo-tokens">—</span></div>
        <div><strong>Mapa nalezena:</strong> <span id="dbg-afo-map">—</span></div>
        <div><strong>Viditelné NOK:</strong> <span id="dbg-afo-visible">—</span></div>
    </div>
</div>
<?php endif; ?>

<form id="edit-form" novalidate>
<input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="typ" id="input-typ" value="<?= h($z['typ']) ?>">

<!-- Přepínač Série / Filtry -->
<div class="type-switch mb-3">
    <div class="type-btn <?= $z['typ']==='serie'?'active':'' ?>" id="btn-serie" onclick="setTyp('serie')">
        <span class="tb-icon">⚙</span>
        <div class="tb-label"><?= t('type_serie') ?></div>
        <div class="tb-sub"><?= t('series_production') ?></div>
    </div>
    <div class="type-btn <?= $z['typ']==='filtry'?'active':'' ?>" id="btn-filtry" onclick="setTyp('filtry')">
        <span class="tb-icon">⚡</span>
        <div class="tb-label"><?= t('type_filtry') ?></div>
        <div class="tb-sub"><?= t('filters_production') ?></div>
    </div>
</div>

<!-- ── 01 Identifikace ── -->
<div class="card-dvd mb-3" id="sec-ident">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">01</span>
        <span class="s-title"><i class="bi bi-card-text me-2" style="color:var(--accent)"></i><?= t('sec_ident') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label-dvd"><?= t('pracovisite') ?> <span class="req">*</span></label>
            <div style="position:relative">
                <input type="text" id="pracovisite_search" class="form-control" autocomplete="off"
                    placeholder="<?= t('pracovisite') ?>"
                    oninput="onPracovisteInput(this.value)"
                    onfocus="onPracovisteInput(this.value)"
                    onkeydown="onPracovisteKeydown(event)"
                    onblur="setTimeout(closePracovisteDropdown, 150)" required>
                <div id="pracovisite_dropdown" style="display:none;position:absolute;z-index:1000;width:100%;max-height:260px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 4px 4px;box-shadow:0 4px 8px rgba(0,0,0,.3)"></div>
            </div>
            <input type="hidden" id="pracovisite_id" name="pracovisite_id" value="<?= $z['pracovisite_id'] ?>" required>
        </div>
        <?php foreach([
            ['zakaznik',t('zakaznik'),$z['zakaznik']??''],
            ['projekt',t('projekt'),$z['projekt']??''],
            ['verze',t('verze'),$z['verze']??''],
            ['afo',t('afo'),$z['kod_pracovisite']??''],
            ['ns',t('ns'),$z['nakladove_stredisko']??''],
        ] as [$k,$l,$v]): ?>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= $l ?></label>
            <div class="calc-display" id="disp-<?= $k ?>"><?= h($v)?:$v ?></div>
        </div>
        <?php endforeach; ?>
        <div class="col-12 col-md-5">
            <label class="form-label-dvd"><?= t('pracovisite') ?> – název</label>
            <div class="calc-display" id="disp-nazev"><?= h($z['nazev_pracovisite']??'—') ?></div>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label-dvd"><?= t('nazev_dilu') ?></label>
            <div class="calc-display" id="disp-dil"><?= h($z['nazev_dilu']??'—') ?></div>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label-dvd"><?= t('sap_cislo') ?></label>
            <div class="calc-display" id="disp-sap" style="font-size:.75rem"><?= h($z['sap_cislo']??'—') ?></div>
        </div>
        <div class="col-12">
            <label class="form-label-dvd"><?= t('note_operator') ?></label>
            <input type="text" name="poznamka_zapisujiciho" class="form-control" value="<?= h($z['poznamka_zapisujiciho']??'') ?>" placeholder="<?= t('note_placeholder') ?>">
        </div>
    </div>
    </div>
</div>

<!-- ── 02 Směna ── -->
<div class="card-dvd mb-3" id="sec-smena">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">02</span>
        <span class="s-title"><i class="bi bi-clock me-2" style="color:var(--accent3)"></i><?= t('sec_smena') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('datum') ?> <span class="req">*</span></label>
            <input type="date" name="datum" id="input-datum" class="form-control" value="<?= h($z['datum']) ?>" required onchange="updateMeta()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('cw') ?></label>
            <div class="calc-display" id="disp-cw">KT <?= $z['cw'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('smena') ?> <span class="req">*</span></label>
            <select name="smena" id="input-smena" class="form-select" required onchange="applySmenaDefaults()">
                <?php foreach ($smenyList as $sm): ?>
                <option value="<?= h($sm['kod']) ?>" <?= $z['smena']===$sm['kod']?'selected':'' ?>><?= h($sm['nazev']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prichod') ?></label>
            <input type="time" name="cas_prichodu" id="cas-od" class="form-control"
                value="<?= $z['cas_prichodu'] ? date('H:i',strtotime($z['cas_prichodu'])) : '' ?>"
                onchange="calcCisty()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('odchod') ?></label>
            <input type="time" name="cas_odchodu" id="cas-do" class="form-control"
                value="<?= $z['cas_odchodu'] ? date('H:i',strtotime($z['cas_odchodu'])) : '' ?>"
                onchange="calcCisty()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prac_doba') ?></label>
            <input type="number" name="pracovni_doba_min" id="prac-doba" class="form-control" min="0" max="720"
                value="<?= (int)($z['pracovni_doba_min']??480) ?>" onchange="calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('pauza') ?></label>
            <input type="number" name="pauza_min" id="pauza" class="form-control" min="0" max="120"
                value="<?= (int)($z['pauza_min']??30) ?>" onchange="calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prestas') ?></label>
            <input type="number" name="prestas_min" class="form-control" min="0" max="240"
                value="<?= (int)($z['prestas_min']??0) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('cisty_cas') ?></label>
            <div class="calc-display" id="disp-cisty"><?= (int)($z['cisty_cas_min']??0) ?> min</div>
            <input type="hidden" name="cisty_cas_min" id="cisty-hidden" value="<?= (int)($z['cisty_cas_min']??0) ?>">
        </div>
    </div>
    </div>
</div>

<!-- ── 03 Pracovník ── -->
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">03</span>
        <span class="s-title"><i class="bi bi-person me-2" style="color:var(--accent3)"></i><?= t('sec_worker') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div style="font-size:.8rem;color:var(--muted)"><?= t('pracovnik') ?> / <?= t('pracovni_pozice') ?></div>
        <button type="button" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem;white-space:nowrap" onclick="addWorkerRow()">
            <i class="bi bi-plus-lg me-1"></i><?= t('add') . ' ' . t('pracovnik') ?>
        </button>
    </div>
    <div id="workers-list" class="d-flex flex-column gap-3"></div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-md-3">
            <label class="form-label-dvd"><?= t('zapsal') ?></label>
            <input type="text" name="zapsal" class="form-control"
                value="<?= h($z['zapsal']??'') ?>"
                readonly style="background:rgba(255,255,255,.04);cursor:not-allowed;color:var(--muted)">
        </div>
    </div>
    </div>
</div>

<!-- ── 04 Výroba ── -->
<div class="card-dvd mb-3" id="sec-vyroba">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">04</span>
        <span class="s-title"><i class="bi bi-boxes me-2" style="color:var(--warn)"></i><?= t('sec_vyroba') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('vyrobeno') ?> <span class="req">*</span></label>
            <input type="number" name="vyrobeno_ks" id="vyrobeno" class="form-control" min="0" placeholder="0" 
                value="<?= (int)$z['vyrobeno_ks'] ?>" required onchange="calcKs();calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('nok_ks') ?></label>
            <input type="number" name="nok_ks" id="nokks" class="form-control" min="0" placeholder="0" 
                value="<?= (int)$z['nok_ks'] ?>" onchange="calcKs();calcOEE()">
        </div>
        
        <?php foreach(['tech'=>t('tech_short'),'blok'=>t('blok_short'),'tnz'=>t('tnz_short')] as $k=>$l): ?>
        <div class="col-4 col-md-2">
            <label class="form-label-dvd"><?= $l ?></label>
            <input type="number" name="<?= $k ?>" id="<?= $k ?>" class="form-control" min="0" placeholder="0" 
                value="<?= (int)$z[$k] ?>" onchange="calcKs();calcOEE()">
        </div>
        <?php endforeach; ?>

        <div class="col-12 col-md-2">
            <label class="form-label-dvd"><?= t('vyrobeno_dobre') ?></label>
            <div class="calc-display text-accent" id="disp-dobre"><?= number_format((int)$z['vyrobeno_dobre_ks']) ?></div>
        </div>
        <input type="hidden" name="k_posouzeni" value="<?= (int)$z['k_posouzeni'] ?>">
        <div class="col-12">
            <label class="form-label-dvd"><?= t('poznamky') ?></label>
            <textarea name="poznamky" class="form-control" rows="2" placeholder="<?= t('notes_placeholder') ?>"><?= h($z['poznamky']??'') ?></textarea>
        </div>
    </div>
    </div>
</div>

<!-- ── 06 NOK záznamy ── -->
<div class="card-dvd mb-3" id="sec-nok">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">06</span>
        <span class="s-title"><i class="bi bi-x-octagon me-2" style="color:var(--accent2)"></i><?= t('sec_nok') ?>
            <span id="nok-badge" style="display:none;background:rgba(255,107,53,.18);color:var(--accent2);font-family:var(--mono);font-size:.62rem;border-radius:3px;padding:.1rem .4rem;margin-left:.5rem"></span>
        </span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div id="nok-empty-hint" style="display:none;font-size:.78rem;color:var(--muted);margin-bottom:.6rem;">
        <?= h(t('sec_nok_empty_for_afo')) ?>
    </div>
    <input type="hidden" name="nok[134]" id="nok_134" value="<?= $nokMap[134] ?? 0 ?>">
    <div class="row g-3" id="nok-groups-container"></div>
    </div>
</div>

<!-- ── 05 Záznam prostojů ── -->
<div class="card-dvd mb-3" id="sec-tech">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">05</span>
        <span class="s-title"><i class="bi bi-tools me-2" style="color:var(--accent2)"></i><?= t('sec_tech_prostoj') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem">
        <div class="d-flex flex-wrap justify-content-between gap-2 text-center">
            <?php foreach(['plan'=>[t('sum_planned'),'accent'],'tech'=>[t('sum_technical'),'accent2'],'vyr'=>[t('sum_production'),'warn'],'kval'=>[t('sum_quality'),'accent2'],'log'=>[t('sum_logistic'),'accent3'],'ost'=>[t('label_downtime_other'),'muted'],'celk'=>[t('sum_total'),'accent2']] as $k=>[$l,$c]): ?>
            <div style="flex:1 1 80px;min-width:70px">
                <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= $l ?></div>
                <div id="ps-<?= $k ?>" class="mono" style="font-size:1.1rem;font-weight:600;color:var(--<?= $c ?>)">0</div>
                <div style="font-size:.6rem;color:var(--muted)"><?= t('sum_minutes') ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="mt-3" style="overflow-x:auto">
    <table class="ptbl" id="tech-table">
        <thead><tr><th><?= t('typ') ?></th><th><?= t('cas_od') ?></th><th><?= t('cas_do') ?></th><th><?= t('minuty') ?></th><th><?= t('poznamky') ?></th><th></th></tr></thead>
        <tbody id="tech-tbody"></tbody>
    </table>
    </div>
    <button type="button" class="btn-add-row" onclick="addTechRow()">
        <i class="bi bi-plus-circle me-2"></i><?= t('add_prostoj') ?>
    </button>
    </div>
</div>

<?php /*
<!-- ── 07 Hodinové sledování ── -->
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">07</span>
        <span class="s-title"><i class="bi bi-bar-chart-steps me-2" style="color:var(--accent3)"></i><?= t('sec_hourly') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
        <div id="hourly-container" class="hourly-grid"></div>
    </div>
</div>
*/ ?>

<!-- ── 08 OEE Preview ── -->
<div class="card-dvd mb-3" id="sec-oee">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">08</span>
        <span class="s-title"><i class="bi bi-speedometer2 me-2" style="color:var(--accent)"></i><?= t('sec_oee') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-12 col-md-7">
        <div class="oee-preview">
            <?php foreach([['A',t('oee_label_availability'),'accent'],['P',t('oee_label_performance'),'accent3'],['Q',t('oee_label_quality'),'warn']] as [$k,$l,$c]): ?>
            <div class="oee-row">
                <span class="oee-label"><?= $l ?> (<?= $k ?>)</span>
                <div class="oee-bar-wrap flex-grow-1"><div id="oee-bar-<?= strtolower($k) ?>" class="oee-bar-fill" style="width:0%;background:var(--<?= $c ?>)"></div></div>
                <span id="oee-val-<?= strtolower($k) ?>" class="oee-val" style="color:var(--<?= $c ?>)">—</span>
            </div>
            <?php endforeach; ?>
            <div class="oee-total-box">
                <div><div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em"><?= t('oee_celkem') ?></div>
                    <div class="oee-total-val" id="oee-total">—</div></div>
                <div style="text-align:right"><div style="font-size:.7rem;color:var(--muted)"><?= t('oee_cil') ?></div>
                    <div id="oee-status" style="font-size:.72rem;margin-top:.15rem"></div></div>
            </div>
            <div style="border-top:1px dashed rgba(255,193,7,.3);margin-top:.6rem;padding-top:.35rem;font-size:.68rem;color:var(--warn);font-family:var(--mono)">
                ▲ Norma: <span id="d-norma">—</span> ks/min &nbsp;|&nbsp; Cycle: <span id="d-cycle">—</span> min
            </div>
        </div>
        </div>
        <div class="col-12 col-md-5">
        <div class="oee-preview">
            <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.7rem"><?= t('summary') ?></div>
            <div id="summary-lines" style="font-size:.78rem;line-height:2.1"></div>
        </div>
        </div>
    </div>
    </div>
</div>

<!-- Submit -->
<div class="d-flex gap-3 justify-content-end pb-5 pt-2">
    <a href="zapis_detail.php?id=<?= $id ?>" class="btn-outline"><i class="bi bi-x-lg me-2"></i><?= t('cancel_continue') ?></a>
    <button type="submit" class="btn-dvd" id="btn-submit"><i class="bi bi-cloud-check me-2"></i><?= t('save_entry') ?></button>
</div>

</form>

<!-- Potvrzovací Modal -->
<div id="confirm-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:420px;width:90%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);transform:scale(1);transition:transform .2s;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
            <h5 style="margin:0;font-size:1rem;font-weight:600;color:var(--text);"><i class="bi bi-question-circle me-2 text-accent"></i><?= t('confirm_save_title') ?></h5>
            <button onclick="closeConfirmModal()" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.5rem;color:var(--text);font-size:.9rem;line-height:1.6;">
            <p style="margin:0 0 .75rem 0;"><?= t('confirm_save_msg') ?></p>
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.75rem 1rem;font-size:.8rem;color:var(--muted);">
                <i class="bi bi-info-circle me-2 text-accent"></i><?= t('confirm_save_note') ?>
            </div>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
            <button onclick="closeConfirmModal()" style="background:transparent;border:1px solid var(--border2);color:var(--text);padding:.5rem 1.25rem;border-radius:6px;font-size:.85rem;cursor:pointer;transition:all .18s;" onmouseover="this.style.borderColor='var(--text)'" onmouseout="this.style.borderColor='var(--border2)'">
                <i class="bi bi-x-lg me-1"></i><?= t('cancel_continue') ?>
            </button>
            <button onclick="executeSave()" style="background:linear-gradient(135deg,var(--accent),#00c496);border:none;color:#000;padding:.5rem 1.25rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(0,229,176,.22);" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 22px rgba(0,229,176,.38)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(0,229,176,.22)'">
                <i class="bi bi-check2 me-1"></i><?= t('save_entry') ?>
            </button>
        </div>
    </div>
</div>

<!-- NOK nesoulad Modal -->
<div id="nok-mismatch-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;max-width:520px;width:92%;box-shadow:0 25px 50px -12px rgba(0,0,0,.5);">
        <div style="padding:1.1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.7rem;">
            <h5 style="margin:0;font-size:1rem;font-weight:600;color:var(--text);"><i class="bi bi-exclamation-triangle me-2" style="color:var(--accent2)"></i><span id="nok-mismatch-title"></span></h5>
            <button type="button" onclick="closeNokMismatchModal(false)" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.25rem 1.3rem;color:var(--text);font-size:.9rem;line-height:1.55;">
            <p id="nok-mismatch-body" style="margin:0 0 .65rem 0;"></p>
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.65rem .85rem;font-size:.82rem;color:var(--muted);">
                <i class="bi bi-arrow-right-circle me-2" style="color:var(--accent)"></i>
                <span id="nok-mismatch-fill-text"></span>
            </div>
        </div>
        <div style="padding:1rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" onclick="closeNokMismatchModal(false)" style="background:transparent;border:1px solid var(--border2);color:var(--text);padding:.5rem 1.1rem;border-radius:6px;font-size:.85rem;cursor:pointer;transition:all .18s;">
                <i class="bi bi-pencil-square me-1"></i><span id="nok-mismatch-no"></span>
            </button>
            <button type="button" onclick="closeNokMismatchModal(true)" style="background:linear-gradient(135deg,var(--accent),#00c496);border:none;color:#000;padding:.5rem 1.1rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(0,229,176,.22);">
                <i class="bi bi-check2-circle me-1"></i><span id="nok-mismatch-yes"></span>
            </button>
        </div>
    </div>
</div>

</div><!-- content -->
<div id="toast-area"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
const RECORD_ID = <?= $id ?>;
let pwData = {};
let techIdx = 0;
let workerIdx = 0;
let currentTyp = '<?= $z['typ'] ?>';

const pracovisteSuggest = <?= json_encode($pracovisteSuggest, JSON_UNESCAPED_UNICODE) ?>;
const pracovisteById = Object.fromEntries(pracovisteSuggest.map(item => [String(item.id), item]));
const nokTemplateGroups = <?= json_encode($nokTemplateGroups, JSON_UNESCAPED_UNICODE) ?>;
const nokAfoVisibilityMap = <?= json_encode($nokAfoMap, JSON_UNESCAPED_UNICODE) ?>;
const nokDebugEnabled = <?= isSuperAdmin() ? 'true' : 'false' ?>;
let activePracovisteSuggest = [...pracovisteSuggest];
const prostojTypy = <?= json_encode(array_column($prostojTypy,'popis','kod')) ?>;
const pracovniciSuggest = <?= json_encode($pracovniciSuggest, JSON_UNESCAPED_UNICODE) ?>;
const pracovniciById = Object.fromEntries(pracovniciSuggest.map(item => [String(item.id), item]));
const workerPositionOptions = <?= json_encode($workerPositionOptions) ?>;
const prestavkyDef = <?= json_encode($prestavkyList, JSON_UNESCAPED_UNICODE) ?>;
const initialWorkers = <?= json_encode(array_values($workers)) ?>;
const initialProstoje = <?= json_encode(array_values($prostoje)) ?>;
const initialNok = <?= json_encode($nokMap) ?>;
const initialHourly = <?= json_encode($hodinyMap) ?>;

// Uchování již zadaných hodnot při změně časů
let savedHourlyValues = {};
Object.keys(initialHourly).forEach(k => {
    savedHourlyValues[k] = {
        vyr: initialHourly[k].vyrobeno > 0 ? initialHourly[k].vyrobeno : '',
        p: initialHourly[k].p_minuty > 0 ? initialHourly[k].p_minuty : '',
        t: initialHourly[k].t_minuty > 0 ? initialHourly[k].t_minuty : ''
    };
});

function renderHourlyGrid() {
    const container = document.getElementById('hourly-container');
    if (!container) return;

    container.querySelectorAll('.hour-card').forEach(card => {
        const slot = card.dataset.slot;
        if (slot) {
            savedHourlyValues[slot] = {
                vyr: card.querySelector('input[name$="[vyrobeno]"]')?.value || '',
                p: card.querySelector('input[name$="[p_minuty]"]')?.value || '',
                t: card.querySelector('input[name$="[t_minuty]"]')?.value || ''
            };
        }
    });

    const casOdVal = document.getElementById('cas-od')?.value || '06:00';
    const casDoVal = document.getElementById('cas-do')?.value;
    const pracDobaVal = parseInt(document.getElementById('prac-doba')?.value, 10) || 480;

    let pocetHodin = 8;
    if (casOdVal && casDoVal) {
        let [oh, om] = casOdVal.split(':').map(Number);
        let [dh, dm] = casDoVal.split(':').map(Number);
        if (!isNaN(oh) && !isNaN(dh)) {
            let totalMin = (dh * 60 + dm) - (oh * 60 + om);
            if (totalMin <= 0) totalMin += 1440;
            pocetHodin = Math.max(1, Math.round(totalMin / 60));
        }
    } else {
        pocetHodin = Math.max(1, Math.round(pracDobaVal / 60));
    }

    let startHour = parseInt(casOdVal.split(':')[0], 10);
    if (isNaN(startHour)) startHour = 6;

    let html = '';
    for (let i = 0; i < pocetHodin; i++) {
        const hFrom = (startHour + i) % 24;
        const hTo = (startHour + i + 1) % 24;
        const pad = n => String(n).padStart(2, '0');
        const slot = `${pad(hFrom)}-${pad(hTo)}`;
        const existing = savedHourlyValues[slot] || { vyr: '', p: '', t: '' };

        html += `
        <div class="hour-card" data-slot="${slot}">
            <div class="hour-label">${slot}</div>
            <input type="hidden" name="hodiny[${slot}][hodina_od]" value="${slot}">
            <div class="row g-1">
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">${window.LANG_TEXT.hour_from || 'Vyrobeno'}</div>
                    <input type="number" name="hodiny[${slot}][vyrobeno]" class="form-control" min="0" placeholder="ks" 
                           value="${escapeHtml(existing.vyr)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">P (min)</div>
                    <input type="number" name="hodiny[${slot}][p_minuty]" class="form-control" min="0" max="60" placeholder="0" 
                           value="${escapeHtml(existing.p)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">T (min)</div>
                    <input type="number" name="hodiny[${slot}][t_minuty]" class="form-control" min="0" max="60" placeholder="0" 
                           value="${escapeHtml(existing.t)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
            </div>
        </div>`;
    }
    container.innerHTML = html;
}

// Meta + Header
function getISOWeekNumber(dStr) {
    if (!dStr) return '';
    const parts = dStr.split('-').map(Number);
    if (parts.length !== 3) return '';
    const date = new Date(parts[0], parts[1] - 1, parts[2]);
    if (isNaN(date.getTime())) return '';
    date.setHours(0, 0, 0, 0);
    date.setDate(date.getDate() + 3 - (date.getDay() + 6) % 7);
    const week1 = new Date(date.getFullYear(), 0, 4);
    return 1 + Math.round(((date.getTime() - week1.getTime()) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
}

function updateMeta() {
    const d = document.getElementById('input-datum').value;
    if (!d) return;
    const cw = getISOWeekNumber(d);
    document.getElementById('disp-cw').textContent = 'KT ' + cw;
    const parts = d.split('-').map(Number);
    if (parts.length === 3) {
        const dt = new Date(parts[0], parts[1] - 1, parts[2]);
        document.getElementById('header-meta').textContent = new Intl.DateTimeFormat('cs-CZ', {
            weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric'
        }).format(dt);
    }
}

// Typ
function setTyp(typ) {
    currentTyp = typ;
    document.getElementById('input-typ').value = typ;
    document.getElementById('btn-serie').classList.toggle('active', typ==='serie');
    document.getElementById('btn-filtry').classList.toggle('active', typ==='filtry');
    const b = document.getElementById('typ-badge');
    b.textContent = typ==='serie'?'SÉRIE':'FILTRY';
    b.className = 'badge-'+typ;
    refreshPracovisteSuggestByTyp();
    calcCisty();
}

function getPracovisteSuggestForTyp(typ) {
    return pracovisteSuggest.filter(item => item.typ === typ || item.typ === 'obe');
}

let pracovisteDropdownHighlight = -1;

function onPracovisteInput(val) {
    const q = String(val || '').trim();
    const hiddenId = document.getElementById('pracovisite_id');
    if (hiddenId && event && event.type === 'input') hiddenId.value = '';

    if (!q) {
        renderPracovisteDropdown(activePracovisteSuggest.slice(0, 200));
        return;
    }
    const qNorm = q.toLocaleLowerCase('cs-CZ');
    const results = activePracovisteSuggest.filter(item => item.search.includes(qNorm));
    renderPracovisteDropdown(results.slice(0, 100));
}

function renderPracovisteDropdown(list) {
    const dd = document.getElementById('pracovisite_dropdown');
    if (!list.length) { dd.style.display = 'none'; return; }
    pracovisteDropdownHighlight = -1;
    dd.innerHTML = list.map((item, i) =>
        `<div class="pw-dd-item" data-id="${item.id}" style="padding:6px 10px;cursor:pointer;font-size:.85rem;border-bottom:1px solid var(--border);color:var(--text)"
            onmousedown="pickPracovisteItem('${item.id}')"
            onmouseover="highlightPracovisteItem(${i})"
            onmouseout="if(pracovisteDropdownHighlight!==${i}){this.style.background='';this.style.color='var(--text)'}">${escapeHtml(item.label)}</div>`
    ).join('');
    dd.style.display = 'block';
}

function highlightPracovisteItem(idx) {
    pracovisteDropdownHighlight = idx;
    document.querySelectorAll('#pracovisite_dropdown .pw-dd-item').forEach((el, i) => {
        el.style.background = i === idx ? 'var(--accent)' : '';
        el.style.color = i === idx ? '#fff' : 'var(--text)';
    });
}

function pickPracovisteItem(id) {
    const item = activePracovisteSuggest.find(p => String(p.id) === String(id));
    if (!item) return;
    document.getElementById('pracovisite_search').value = item.label;
    document.getElementById('pracovisite_id').value = String(item.id);
    closePracovisteDropdown();
    loadPracovisteData(String(item.id));
}

function closePracovisteDropdown() {
    const dd = document.getElementById('pracovisite_dropdown');
    if (dd) dd.style.display = 'none';
    pracovisteDropdownHighlight = -1;
}

function onPracovisteKeydown(e) {
    const dd = document.getElementById('pracovisite_dropdown');
    const items = dd ? dd.querySelectorAll('.pw-dd-item') : [];
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        pracovisteDropdownHighlight = Math.min(pracovisteDropdownHighlight + 1, items.length - 1);
        highlightPracovisteItem(pracovisteDropdownHighlight);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        pracovisteDropdownHighlight = Math.max(pracovisteDropdownHighlight - 1, 0);
        highlightPracovisteItem(pracovisteDropdownHighlight);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (pracovisteDropdownHighlight >= 0 && items[pracovisteDropdownHighlight]) {
            pickPracovisteItem(items[pracovisteDropdownHighlight].dataset.id);
        } else if (items.length > 0) {
            pickPracovisteItem(items[0].dataset.id);
        }
    } else if (e.key === 'Escape') {
        closePracovisteDropdown();
    }
}

function refreshPracovisteSuggestByTyp() {
    activePracovisteSuggest = getPracovisteSuggestForTyp(currentTyp);
    const hidden = document.getElementById('pracovisite_id');
    const selectedId = hidden ? String(hidden.value || '') : '';
    if (selectedId && !activePracovisteSuggest.some(item => String(item.id) === selectedId)) {
        document.getElementById('pracovisite_search').value = '';
        loadPracovisteData('');
    }
}

function renderNokGroups() {
    const container = document.getElementById('nok-groups-container');
    if (!container || container.dataset.rendered === '1') return;

    const items = [];
    nokTemplateGroups.forEach(group => {
        if (!group.items || !group.items.length) return;
        group.items.forEach(item => {
            const id = Number(item.id || 0);
            const kod = escapeHtml(item.kod || '');
            const popis = escapeHtml(item.popis || '');
            const val = initialNok[id] || '';
            
            items.push(`<div class="nok-item col-12 col-md-6 col-xl-4" data-nok-id="${id}" style="padding-right: 3rem; padding-bottom: 0.5rem;">
                <span class="nok-code">${kod}</span>
                <label for="nok_${id}">${popis}</label>
                <input type="number" name="nok[${id}]" id="nok_${id}" class="form-control nok-qty" min="0" max="9999" placeholder="0" value="${val}" onchange="updateNokBadge()">
            </div>`);
        });
    });

    container.innerHTML = items.join('');
    container.dataset.rendered = '1';
}

function loadPracovisteData(id) {
    const selected = pracovisteById[String(id)];
    if (!id||!selected) {
        ['zakaznik','projekt','verze','afo','ns','nazev','dil','sap'].forEach(k=>document.getElementById('disp-'+k).textContent='—');
        pwData={};
        const search = document.getElementById('pracovisite_search');
        if (search) search.value = '';
        const hidden = document.getElementById('pracovisite_id');
        if (hidden) hidden.value = '';
        applyNokVisibilityByAfo('', '');
        return;
    }
    pwData = { zakaznik:selected.zakaznik, projekt:selected.projekt, verze:selected.verze,
        afo:selected.afo, nazev:selected.nazev, dil:selected.dil,
        sap:selected.sap, ns:selected.ns,
        norma:parseFloat(selected.norma)||0, cycle:parseFloat(selected.cycle)||0 };
    const search = document.getElementById('pracovisite_search');
    if (search) search.value = selected.label;
    const hidden = document.getElementById('pracovisite_id');
    if (hidden) hidden.value = String(selected.id);
    
    document.getElementById('disp-zakaznik').textContent = pwData.zakaznik;
    document.getElementById('disp-projekt').textContent  = pwData.projekt;
    document.getElementById('disp-verze').textContent    = pwData.verze;
    document.getElementById('disp-afo').textContent      = pwData.afo;
    document.getElementById('disp-ns').textContent       = pwData.ns;
    document.getElementById('disp-nazev').textContent    = pwData.nazev;
    document.getElementById('disp-dil').textContent      = pwData.dil;
    document.getElementById('disp-sap').textContent      = pwData.sap;
    document.getElementById('d-norma').textContent       = pwData.norma ? pwData.norma.toFixed(4) : '—';
    document.getElementById('d-cycle').textContent       = pwData.cycle ? pwData.cycle.toFixed(4) : '—';
    
    applyNokVisibilityByAfo(String(selected.cisloId || ''), String(pwData.afo || ''));
    calcOEE();
}

function extractAfoTokens(afoText) {
    const src = String(afoText || '').trim();
    if (!src) return [];
    const matches = src.match(/\d+/g) || [];
    return Array.from(new Set(matches.map(v => String(parseInt(v, 10)).trim()).filter(v => v !== '' && v !== 'NaN')));
}

function buildVisibilityFromAfoTokens(afoTokens) {
    if (!Array.isArray(afoTokens) || afoTokens.length === 0) return null;
    const hasAny = afoTokens.some(token => Object.prototype.hasOwnProperty.call(nokAfoVisibilityMap, token));
    if (!hasAny) return null;

    const merged = {};
    afoTokens.forEach(token => {
        const tokenMap = nokAfoVisibilityMap[token];
        if (!tokenMap) return;
        Object.keys(tokenMap).forEach(nokId => {
            merged[nokId] = (merged[nokId] === true) || tokenMap[nokId] === true || tokenMap[nokId] === 1 || tokenMap[nokId] === '1';
        });
    });
    return merged;
}

function applyNokVisibilityByAfo(workplaceId, afoText) {
    const idKey = String(workplaceId || '').trim();
    if (idKey !== '' || String(afoText || '').trim() !== '') {
        renderNokGroups();
    }
    const mapByWorkplaceId = idKey && nokAfoVisibilityMap[idKey] ? nokAfoVisibilityMap[idKey] : null;
    const afoTokens = extractAfoTokens(afoText);
    const mapByTextTokens = buildVisibilityFromAfoTokens(afoTokens);
    const mapForAfo = mapByWorkplaceId || mapByTextTokens;
    const nokRows = Array.from(document.querySelectorAll('#sec-nok .nok-item[data-nok-id]'));
    const strictMode = idKey !== '' || String(afoText || '').trim() !== '';

    nokRows.forEach(row => {
        const nokId = String(row.getAttribute('data-nok-id') || '');
        let visible = true;
        if (strictMode) {
            visible = !!(mapForAfo && (mapForAfo[nokId] === true || mapForAfo[nokId] === 1 || mapForAfo[nokId] === '1'));
        }
        
        // Záchrana pro editaci - pokud už to má vyplněnou hodnotu z DB, UDRŽ to zobrazené navzdory AFO (změna normy apod.)
        const input = row.querySelector('input.nok-qty');
        if (input && parseInt(input.value, 10) > 0) {
            visible = true;
        }

        if (!visible && input) input.value = '';
        row.style.display = visible ? '' : 'none';
    });

    const anyVisible = nokRows.some(row => row.style.display !== 'none');
    const hint = document.getElementById('nok-empty-hint');
    if (hint) hint.style.display = (mapForAfo && !anyVisible) ? '' : 'none';

    const dbgCard = nokDebugEnabled ? document.getElementById('nok-afo-debug') : null;
    if (dbgCard) {
        dbgCard.style.display = '';
        document.getElementById('dbg-afo-text').textContent = String(afoText || '');
        document.getElementById('dbg-afo-tokens').textContent = afoTokens.length ? afoTokens.join(', ') : '—';
        document.getElementById('dbg-afo-map').textContent = mapForAfo ? 'ano' : 'ne';
        document.getElementById('dbg-afo-visible').textContent = String(nokRows.filter(r => r.style.display !== 'none').length);
    }
    updateNokBadge();
}

// Směny
const sDef = <?= json_encode(array_combine(
    array_column($smenyList, 'kod'),
    array_map(static fn($s) => [
        'od'    => substr((string)$s['cas_od'], 0, 5),
        'do'    => substr((string)$s['cas_do'], 0, 5),
        'min'   => (int)$s['pracovni_doba_min'],
        'pauza' => (int)$s['pauza_min'],
    ], $smenyList)
), JSON_UNESCAPED_UNICODE) ?>;

function normZoneKey(val) {
    return String(val || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

function toMinutes(value) {
    const src = String(value || '').trim();
    if (!src || !src.includes(':')) return null;
    const [h, m] = src.split(':').map(Number);
    if (Number.isNaN(h) || Number.isNaN(m)) return null;
    return h * 60 + m;
}

function overlapMinutes(aStart, aEnd, bStart, bEnd) {
    const from = Math.max(aStart, bStart);
    const to = Math.min(aEnd, bEnd);
    return Math.max(0, to - from);
}

function computeDynamicPauzaMinutes() {
    const smenaKod = String(document.getElementById('input-smena')?.value || '').toUpperCase();
    const casOd = document.getElementById('cas-od')?.value || '';
    const casDo = document.getElementById('cas-do')?.value || '';
    const odMin = toMinutes(casOd);
    const doMin = toMinutes(casDo);
    if (odMin === null || doMin === null || !smenaKod) return null;

    const zonaWanted = normZoneKey(currentTyp === 'filtry' ? 'Filtry' : 'Série');
    const rezimWanted = smenaKod.includes('12') ? 12 : 8;

    let workEnd = doMin;
    if (workEnd <= odMin) workEnd += 1440;

    let total = 0;
    prestavkyDef.forEach((br) => {
        if ((parseInt(br.aktivni, 10) || 0) !== 1) return;
        if (normZoneKey(br.zona) !== zonaWanted) return;
        if ((parseInt(br.rezim_hod, 10) || 0) !== rezimWanted) return;
        if (String(br.smena_kod || '').toUpperCase() !== smenaKod) return;

        const bStartBase = toMinutes(br.cas_od);
        const bEndBaseRaw = toMinutes(br.cas_do);
        if (bStartBase === null || bEndBaseRaw === null) return;

        let bEndBase = bEndBaseRaw;
        if (bEndBase <= bStartBase) bEndBase += 1440;

        const overlapA = overlapMinutes(odMin, workEnd, bStartBase, bEndBase);
        const overlapB = overlapMinutes(odMin, workEnd, bStartBase + 1440, bEndBase + 1440);
        total += Math.max(overlapA, overlapB);
    });
    return Math.max(0, total);
}

function applyDynamicPauza() {
    const pauzaInput = document.getElementById('pauza');
    if (!pauzaInput) return;
    const minutes = computeDynamicPauzaMinutes();
    if (minutes === null) return;
    pauzaInput.value = String(minutes);
}

function applySmenaDefaults() {
    const s = document.getElementById('input-smena').value;
    const d = sDef[s]; if(!d) return;
    document.getElementById('cas-od').value    = d.od;
    document.getElementById('cas-do').value    = d.do;
    document.getElementById('prac-doba').value = d.min;
    applyDynamicPauza();
    calcCisty();
}

function calcCisty() {
    applyDynamicPauza();
    const od=document.getElementById('cas-od').value, dc=document.getElementById('cas-do').value;
    const pauza=parseInt(document.getElementById('pauza').value)||0;
    if(od&&dc){
        let [oh,om]=od.split(':').map(Number), [dh,dm]=dc.split(':').map(Number);
        let total=(dh*60+dm)-(oh*60+om); if(total<0) total+=1440;
        const grossTotal=Math.max(0,total);
        const cisty=Math.max(0,total-pauza);
        document.getElementById('prac-doba').value=grossTotal;
        document.getElementById('disp-cisty').textContent=cisty+' min';
        document.getElementById('cisty-hidden').value=cisty;
    }
    calcOEE();
    renderHourlyGrid();
}

function calcKs() {
    const v = parseInt(document.getElementById('vyrobeno').value) || 0;
    const n = parseInt(document.getElementById('nokks').value) || 0;
    const tech = parseInt(document.getElementById('tech').value) || 0;
    const blok = parseInt(document.getElementById('blok').value) || 0;
    const tnz = parseInt(document.getElementById('tnz').value) || 0;
    const dobre = Math.max(0, v - n - tech - blok - tnz);
    document.getElementById('disp-dobre').textContent = dobre.toLocaleString('cs-CZ');
    updateNokBadge();
}

function calcProstoje() {
    const summary = { plan: 0, tech: 0, vyr: 0, kval: 0, log: 0, ost: 0, celk: 0 };
    document.querySelectorAll('#tech-tbody tr').forEach((row) => {
        const code = String(row.querySelector('select[name$="[typ_kod]"]')?.value || '').trim().toUpperCase();
        const minutes = parseInt(row.querySelector('input[name$="[minuty]"]')?.value || '0', 10) || 0;
        if (!code || minutes <= 0) return;

        let bucket = 'tech';
        if (code.startsWith('P')) bucket = 'plan';
        else if (code.startsWith('V')) bucket = 'vyr';
        else if (code.startsWith('Q')) bucket = 'kval';
        else if (code.startsWith('L')) bucket = 'log';
        else if (code.startsWith('O')) bucket = 'ost';
        summary[bucket] += minutes;
        summary.celk += minutes;
    });

    document.getElementById('ps-plan').textContent = String(summary.plan);
    document.getElementById('ps-tech').textContent = String(summary.tech);
    document.getElementById('ps-tech').dataset.v = String(summary.tech);
    document.getElementById('ps-vyr').textContent = String(summary.vyr);
    document.getElementById('ps-kval').textContent = String(summary.kval);
    document.getElementById('ps-log').textContent = String(summary.log);
    document.getElementById('ps-ost').textContent = String(summary.ost);
    document.getElementById('ps-celk').textContent = String(summary.celk);
    calcOEE();
}

function calcOEE() {
    const vyr = parseInt(document.getElementById('vyrobeno').value) || 0;
    const nok = parseInt(document.getElementById('nokks').value) || 0;
    const tech = parseInt(document.getElementById('tech').value) || 0;
    const blok = parseInt(document.getElementById('blok').value) || 0;
    const tnz = parseInt(document.getElementById('tnz').value) || 0;
    const prac = parseInt(document.getElementById('prac-doba').value) || 0;
    const pauza2 = parseInt(document.getElementById('pauza').value) || 0;
    
    const plan = parseInt(document.getElementById('ps-plan')?.textContent || '0', 10) || 0;
    const celkProstoje = parseInt(document.getElementById('ps-celk')?.textContent || '0', 10) || 0;
    const neplanProstoje = Math.max(0, celkProstoje - plan);
    
    const cisty = Math.max(0, prac - pauza2);
    document.getElementById('cisty-hidden').value = cisty;
    document.getElementById('disp-cisty').textContent = cisty + ' min';
    
    let A = null, P = null, Q = null, OEE = null;
    
    if (cisty > 0 && prac > 0) {
        const planovanyCasVyroby = Math.max(0, cisty - plan);
        const operacniCas = Math.max(0, planovanyCasVyroby - neplanProstoje);
        
        if (planovanyCasVyroby > 0) A = Math.min(1.0, Math.max(0, operacniCas / planovanyCasVyroby));
        if (pwData.cycle > 0 && operacniCas > 0) {
            P = Math.min(1.5, Math.max(0, vyr / (operacniCas / pwData.cycle)));
        } else if (operacniCas === 0) {
            P = 0;
        }
        if (vyr > 0) Q = Math.min(1.0, Math.max(0, (vyr - nok) / vyr)); else Q = 0;
        if (A !== null && P !== null && Q !== null) OEE = Math.min(1.0, Math.max(0, A * P * Q));
    }
    
    const pct = v => v !== null ? (v * 100).toFixed(1) + '%' : '—';
    ['a','p','q'].forEach((k,i) => {
        const v = [A,P,Q][i];
        document.getElementById('oee-val-'+k).textContent = pct(v);
        if(v !== null) document.getElementById('oee-bar-'+k).style.width = Math.min(100, v * 100).toFixed(1) + '%';
    });
    
    document.getElementById('oee-total').textContent = OEE !== null ? (OEE * 100).toFixed(1) + '%' : '—';
    const st = document.getElementById('oee-status');
    if (OEE !== null) {
        const p2 = OEE * 100;
        st.textContent = p2 >= 85 ? '✓ ' + window.LANG_TEXT.oee_status_above : (p2 >= 70 ? '⚠ ' + window.LANG_TEXT.oee_status_below : '✗ ' + window.LANG_TEXT.oee_status_far_below);
        st.style.color = p2 >= 85 ? 'var(--accent)' : (p2 >= 70 ? 'var(--warn)' : 'var(--accent2)');
    }
    
    const sl = document.getElementById('summary-lines');
    const row = (l, v, c) => `<div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding:.12rem 0"><span style="color:var(--muted)">${l}</span><span class="mono" style="font-weight:600;color:${c||'var(--text)'}">${v}</span></div>`;
    const dobre = Math.max(0, vyr - nok - tech - blok - tnz);

    sl.innerHTML = row(window.LANG_TEXT.summary_net_time, cisty + ' min') + 
                   row(window.LANG_TEXT.summary_produced, vyr.toLocaleString()) + 
                   row(window.LANG_TEXT.summary_nok, nok + ' ks', nok > 0 ? 'var(--accent2)' : 'var(--text)') + 
                   row(window.LANG_TEXT.summary_ok, dobre.toLocaleString(), 'var(--accent)') + 
                   row(window.LANG_TEXT.summary_oee, pct(OEE), OEE >= .85 ? 'var(--accent)' : (OEE >= .7 ? 'var(--warn)' : 'var(--accent2)'));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

// Pracovníci search
function addWorkerRow(data = {}) {
    workerIdx++;
    const index = workerIdx;
    const wrapper = document.createElement('div');
    wrapper.className = 'worker-entry';
    wrapper.id = 'worker-row-' + index;
    wrapper.innerHTML = `<div style="border:1px solid var(--border);border-radius:10px;padding:.9rem;background:var(--surface2)">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label class="form-label-dvd"><?= t('pracovnik') ?></label>
                <div style="position:relative">
                    <input type="text" name="workers[${index}][pracovnik_search]" class="form-control" autocomplete="off" placeholder="<?= h(t('select_worker')) ?>"
                        oninput="onWorkerInput(this)"
                        onfocus="onWorkerInput(this)"
                        onkeydown="onWorkerKeydown(event, this)"
                        onblur="setTimeout(() => closeWorkerDropdown(this), 150)">
                    <div class="worker-dropdown" style="display:none;position:absolute;z-index:1000;width:100%;max-height:220px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 4px 4px;box-shadow:0 4px 8px rgba(0,0,0,.3)"></div>
                </div>
                <input type="hidden" name="workers[${index}][pracovnik_id]" value="">
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label-dvd"><?= t('or_manually') ?></label>
                <input type="text" name="workers[${index}][jmeno_rucne]" class="form-control" placeholder="<?= h(t('placeholder_name')) ?>" value="${escapeHtml(data.jmeno_rucne || data.jmeno || '')}">
            </div>
            <div class="col-8 col-lg-3">
                <label class="form-label-dvd"><?= t('pracovni_pozice') ?></label>
                <select name="workers[${index}][pracovni_pozice]" class="form-select">${workerPositionOptions}</select>
            </div>
            <div class="col-4 col-lg-1 d-flex justify-content-end">
                <button type="button" class="btn-rm" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>" onclick="removeWorkerRow(${index})"><i class="bi bi-trash3"></i></button>
            </div>
        </div>
    </div>`;
    document.getElementById('workers-list').appendChild(wrapper);

    const hiddenWorkerId = wrapper.querySelector(`input[name="workers[${index}][pracovnik_id]"]`);
    const searchInput = wrapper.querySelector(`input[name="workers[${index}][pracovnik_search]"]`);
    const manualInput = wrapper.querySelector(`input[name="workers[${index}][jmeno_rucne]"]`);
    const position = wrapper.querySelector(`select[name="workers[${index}][pracovni_pozice]"]`);
    if (data.pracovnik_id) {
        const selected = pracovniciById[String(data.pracovnik_id)] || null;
        if (hiddenWorkerId) hiddenWorkerId.value = String(data.pracovnik_id);
        if (searchInput && selected) searchInput.value = selected.label;
    }
    if (data.pracovni_pozice) position.value = data.pracovni_pozice;
}

function removeWorkerRow(index) {
    const rows = document.querySelectorAll('#workers-list .worker-entry');
    const row = document.getElementById('worker-row-' + index);
    if (!row) return;
    if (rows.length === 1) {
        row.querySelectorAll('input, select').forEach(el => {
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
        });
        return;
    }
    row.remove();
}

function onWorkerInput(inputEl) {
    const q = String(inputEl.value || '').trim();
    const row = inputEl.closest('.worker-entry');
    if (!row) return;
    if (event && event.type === 'input') {
        const hiddenId = row.querySelector('input[name$="[pracovnik_id]"]');
        if (hiddenId) hiddenId.value = '';
    }
    const dd = inputEl.nextElementSibling;
    if (!dd || !dd.classList.contains('worker-dropdown')) return;

    let results;
    if (!q) { results = pracovniciSuggest.slice(0, 60); } 
    else {
        const qNorm = q.toLocaleLowerCase('cs-CZ');
        results = pracovniciSuggest.filter(item => item.search.includes(qNorm)).slice(0, 50);
    }
    if (!results.length) { dd.style.display = 'none'; return; }
    dd.innerHTML = results.map((item, i) =>
        `<div class="pw-dd-item" data-id="${item.id}" style="padding:6px 10px;cursor:pointer;font-size:.85rem;border-bottom:1px solid var(--border);color:var(--text)"
            onmousedown="pickWorkerItem(this, '${item.id}')"
            onmouseover="this.style.background='var(--accent)';this.style.color='#fff'"
            onmouseout="this.style.background='';this.style.color='var(--text)'">${escapeHtml(item.label)}</div>`
    ).join('');
    dd.style.display = 'block';
}

function closeWorkerDropdown(inputEl) {
    const dd = inputEl.nextElementSibling;
    if (dd && dd.classList.contains('worker-dropdown')) dd.style.display = 'none';
}

function pickWorkerItem(ddItem, id) {
    const match = pracovniciById[String(id)];
    if (!match) return;
    const dd = ddItem.closest('.worker-dropdown');
    const row = ddItem.closest('.worker-entry');
    if (!row) return;
    const searchInput = row.querySelector('input[name$="[pracovnik_search]"]');
    const hiddenId = row.querySelector('input[name$="[pracovnik_id]"]');
    const manualInput = row.querySelector('input[name$="[jmeno_rucne]"]');
    if (searchInput) searchInput.value = match.label;
    if (hiddenId) hiddenId.value = String(match.id);
    if (manualInput && !manualInput.value) manualInput.value = match.jmeno || '';
    if (dd) dd.style.display = 'none';
}

function onWorkerKeydown(e, inputEl) {
    const dd = inputEl.nextElementSibling;
    if (!dd || !dd.classList.contains('worker-dropdown')) return;
    const items = dd.querySelectorAll('.pw-dd-item');
    let hi = parseInt(dd.dataset.hi ?? '-1', 10);
    if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); } 
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); } 
    else if (e.key === 'Enter') { e.preventDefault(); const t = hi >= 0 ? items[hi] : items[0]; if (t) pickWorkerItem(t, t.dataset.id); return; } 
    else if (e.key === 'Escape') { dd.style.display = 'none'; return; } 
    else { return; }
    dd.dataset.hi = hi;
    items.forEach((el, i) => { el.style.background = i === hi ? 'var(--accent)' : ''; el.style.color = i === hi ? '#fff' : 'var(--text)'; });
}

// Prostoje
function computeDowntimeMinutesFromRange(od, dc) {
    if (!od || !dc) return null;
    const [oh, om] = String(od).split(':').map(Number);
    const [dh, dm] = String(dc).split(':').map(Number);
    if ([oh, om, dh, dm].some(Number.isNaN)) return null;
    let total = (dh * 60 + dm) - (oh * 60 + om);
    if (total < 0) total += 1440;
    return Math.max(0, total);
}

function recalcDowntimeRowMinutes(row) {
    if (!row) return;
    const odInput = row.querySelector('input[name$="[cas_od]"]');
    const doInput = row.querySelector('input[name$="[cas_do]"]');
    const minInput = row.querySelector('input[name$="[minuty]"]');
    if (!odInput || !doInput || !minInput) return;

    const minutes = computeDowntimeMinutesFromRange(odInput.value, doInput.value);
    if (minutes === null) return;
    minInput.value = String(minutes);

    const isValid = validateDowntimeRow(row);
    if (!isValid && odInput.value && doInput.value) {
        const { shiftOd, shiftDo } = getCurrentShiftBounds();
        showToast('warn', `⚠ Prostoj (${odInput.value} - ${doInput.value}) je mimo čas směny (${shiftOd} - ${shiftDo})!`);
    }
    updateTechSum();
}

function addTechRow(d={}) {
    techIdx++;
    const i=techIdx;
    const opts=Object.entries(prostojTypy).map(([k,v])=>`<option value="${k}"${d.typ_kod===k?' selected':''}>${k} – ${v.substring(0,30)}</option>`).join('');
    const tr=document.createElement('tr');
    tr.id='tr-'+i;
    tr.innerHTML=`<td><select name="prostoje[${i}][typ_kod]" class="form-select" style="min-width:130px;font-size:.75rem;padding:.28rem .5rem" onchange="calcProstoje()">${opts}</select></td>
        <td><input type="time" name="prostoje[${i}][cas_od]" class="form-control" style="width:95px;font-size:.75rem" value="${d.cas_od?d.cas_od.substring(0,5):''}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
        <td><input type="time" name="prostoje[${i}][cas_do]" class="form-control" style="width:95px;font-size:.75rem" value="${d.cas_do?d.cas_do.substring(0,5):''}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
        <td><input type="number" name="prostoje[${i}][minuty]" class="form-control" style="width:65px;text-align:center;font-size:.75rem" min="0" value="${d.minuty||''}" onchange="updateTechSum()"></td>
        <td><input type="text" name="prostoje[${i}][poznamka]" class="form-control" style="font-size:.75rem" placeholder="…" value="${escapeHtml(d.poznamka||'')}"></td>
        <td><button type="button" class="btn-rm" onclick="document.getElementById('tr-${i}').remove();updateTechSum()"><i class="bi bi-trash3"></i></button></td>`;
    document.getElementById('tech-tbody').appendChild(tr);
    calcProstoje();
}
function updateTechSum() {
    const t=[...document.querySelectorAll('[name^="prostoje["][name$="][minuty]"]')].reduce((a,e)=>a+(parseInt(e.value)||0),0);
    const el=document.getElementById('ps-tech'); if(el){el.textContent=t;el.dataset.v=t;} calcProstoje();
}

function getCurrentShiftBounds() {
    const shiftOd = document.getElementById('cas-od')?.value || (sDef[document.getElementById('input-smena')?.value]?.od || '06:00');
    const shiftDo = document.getElementById('cas-do')?.value || (sDef[document.getElementById('input-smena')?.value]?.do || '14:00');
    return { shiftOd, shiftDo };
}

function isTimeRangeWithinShift(dtOd, dtDo, shiftOd, shiftDo) {
    if (!dtOd || !dtDo || !shiftOd || !shiftDo) return true;
    const toMin = t => {
        const parts = String(t || '').split(':').map(Number);
        if (parts.length < 2 || isNaN(parts[0]) || isNaN(parts[1])) return null;
        return parts[0] * 60 + parts[1];
    };
    const sOd = toMin(shiftOd); let sDo = toMin(shiftDo);
    let dOd = toMin(dtOd); let dDo = toMin(dtDo);
    if (sOd === null || sDo === null || dOd === null || dDo === null) return true;
    if (sDo <= sOd) sDo += 1440;
    if (dOd < sOd && (dOd + 1440) <= sDo) dOd += 1440;
    if (dDo <= (dOd % 1440)) { dDo += 1440; } else if (dOd >= 1440 && dDo < 1440) { dDo += 1440; }
    return dOd >= sOd && dDo <= sDo;
}

function validateDowntimeRow(row) {
    const odInput = row.querySelector('input[name$="[cas_od]"]');
    const doInput = row.querySelector('input[name$="[cas_do]"]');
    if (!odInput || !doInput) return true;
    const od = odInput.value; const doVal = doInput.value;
    if (!od || !doVal) { odInput.style.borderColor = ''; doInput.style.borderColor = ''; return true; }
    const { shiftOd, shiftDo } = getCurrentShiftBounds();
    const valid = isTimeRangeWithinShift(od, doVal, shiftOd, shiftDo);
    if (!valid) {
        odInput.style.borderColor = 'var(--accent2)'; doInput.style.borderColor = 'var(--accent2)';
        odInput.title = `Prostoj (${od}-${doVal}) je mimo čas směny (${shiftOd}-${shiftDo})`;
        doInput.title = `Prostoj (${od}-${doVal}) je mimo čas směny (${shiftOd}-${shiftDo})`;
    } else {
        odInput.style.borderColor = ''; doInput.style.borderColor = '';
        odInput.title = ''; doInput.title = '';
    }
    return valid;
}

function validateAllDowntimes() {
    let allValid = true; let invalidCount = 0;
    document.querySelectorAll('#tech-tbody tr').forEach(row => {
        const od = row.querySelector('input[name$="[cas_od]"]')?.value;
        const doVal = row.querySelector('input[name$="[cas_do]"]')?.value;
        if (od && doVal) { if (!validateDowntimeRow(row)) { allValid = false; invalidCount++; } }
    });
    return { allValid, invalidCount };
}

// NOK Badge a Modaly
function getNokSectionTotals() {
    const section04 = parseInt(document.getElementById('nokks')?.value, 10) || 0;
    const section08 = [...document.querySelectorAll('.nok-qty')].reduce((a,e)=>a+(parseInt(e.value, 10)||0),0);
    return { section04, section08, diff: section04 - section08 };
}

function updateNokBadge() {
    const { section04, section08, diff } = getNokSectionTotals();
    const b = document.getElementById('nok-badge');
    if (!b) return;
    b.style.display = (section04 > 0 || section08 > 0) ? '' : 'none';
    b.textContent = `${section08} / ${section04} ks`;
    b.style.color = diff === 0 ? 'var(--accent)' : 'var(--accent2)';
}

const nokMismatchModal = document.getElementById('nok-mismatch-modal');
let resolveNokMismatchModal = null;

function closeNokMismatchModal(decision) {
    if (!nokMismatchModal) return;
    nokMismatchModal.style.display = 'none';
    document.body.style.overflow = '';
    if (resolveNokMismatchModal) {
        const resolver = resolveNokMismatchModal;
        resolveNokMismatchModal = null;
        resolver(decision === true);
    }
}

function openNokMismatchModal(totals) {
    if (!nokMismatchModal) return Promise.resolve(false);
    document.getElementById('nok-mismatch-title').textContent = window.LANG_TEXT.nok_mismatch;
    document.getElementById('nok-mismatch-body').textContent = `${window.LANG_TEXT.nok_mismatch} (${totals.section08}/${totals.section04} ks).`;
    document.getElementById('nok-mismatch-fill-text').textContent = window.LANG_TEXT.nok_mismatch_auto_fill;
    document.getElementById('nok-mismatch-yes').textContent = 'Ano doplnit';
    document.getElementById('nok-mismatch-no').textContent = 'Ne doplním sám';
    nokMismatchModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    return new Promise((resolve) => { resolveNokMismatchModal = resolve; });
}

async function validateNokConsistencyBeforeSubmit() {
    const totals = getNokSectionTotals();
    if (totals.diff === 0) return true;
    if (totals.diff < 0) {
        showToast('error', `${window.LANG_TEXT.nok_mismatch_exceeded} (${totals.section08}/${totals.section04})`);
        document.getElementById('sec-nok')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    const shouldAutoFill = await openNokMismatchModal(totals);
    if (!shouldAutoFill) {
        showToast('warn', window.LANG_TEXT.nok_mismatch_manual);
        document.getElementById('sec-nok')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    const ostatniInput = document.getElementById('nok_134');
    if (!ostatniInput) {
        showToast('error', window.LANG_TEXT.nok_mismatch_missing_ostatni);
        document.getElementById('sec-nok')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    const current = parseInt(ostatniInput.value, 10) || 0;
    ostatniInput.value = String(current + totals.diff);
    updateNokBadge();
    calcKs();
    calcOEE();
    return true;
}

// Collapsible
document.querySelectorAll('.card-header-dvd[data-collapsible]').forEach(h=>{
    h.addEventListener('click',()=>{
        const body=h.nextElementSibling;
        const hidden=body.style.display==='none';
        body.style.display=hidden?'':'none';
        h.classList.toggle('collapsed',!hidden);
    });
});

// Záchyt Enter klávesy v inpuetch (stejně jako index.php)
document.getElementById('edit-form').addEventListener('keydown', function(e) {
    const target = e.target;
    if (e.key !== 'Enter' || target.tagName === 'TEXTAREA') return;
    const isSubmitShortcut = e.ctrlKey && (e.shiftKey || e.altKey);
    if (isSubmitShortcut) return;
    if (!(target.matches('input:not([type="submit"]):not([type="button"]):not([type="hidden"])') || target.matches('select'))) return;
    e.preventDefault();
    e.stopPropagation();
    const formElements = Array.from(document.querySelectorAll('#edit-form input:not([type="hidden"]), #edit-form select, #edit-form textarea'));
    const currentIndex = formElements.indexOf(target);
    if (currentIndex > -1 && currentIndex < formElements.length - 1) { formElements[currentIndex + 1].focus(); }
    return false;
});

// Potvrzovací Modal Ukládání
const modal = document.getElementById('confirm-modal');
async function openConfirmModal(e) {
    e.preventDefault();
    const pracoviste = document.getElementById('pracovisite_id').value;
    const datum = document.getElementById('input-datum').value;
    if (!pracoviste || !datum) {
        showToast('error', window.LANG_TEXT.error_required_fields);
        document.getElementById('sec-ident').scrollIntoView({behavior:'smooth'});
        return;
    }
    const dtCheck = validateAllDowntimes();
    if (!dtCheck.allValid) {
        const { shiftOd, shiftDo } = getCurrentShiftBounds();
        showToast('error', `✗ Vložené prostoje (${dtCheck.invalidCount}) jsou mimo čas směny (${shiftOd} - ${shiftDo})!`);
        document.getElementById('sec-tech')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    if (!await validateNokConsistencyBeforeSubmit()) return;
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() { modal.style.display = 'none'; document.body.style.overflow = ''; }
modal.addEventListener('click', function(e) { if (e.target === modal) closeConfirmModal(); });

async function executeSave() {
    closeConfirmModal();
    const btn = document.getElementById('btn-submit');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>' + window.LANG_TEXT.saving;
    try {
        const formData = new FormData(document.getElementById('edit-form'));
        const r = await fetch(APP_URL + '/admin/save_zapis_edit.php', {method:'POST', body:formData});
        const raw = await r.text();
        let d = null;
        try { d = raw ? JSON.parse(raw) : null; } catch(err) { d = null; }
        
        if (!d || typeof d !== 'object') {
            throw new Error(`${window.LANG_TEXT.error_save} (HTTP ${r.status})`);
        }
        
        if (d.ok) {
            showToast('success', '✓ ' + d.message);
            setTimeout(() => { window.location.href = APP_URL + '/admin/zapis_detail.php?id=' + RECORD_ID; }, 1200);
        } else {
            showToast('error', '✗ ' + (d.error || window.LANG_TEXT.error_save));
        }
    } catch(err) {
        showToast('error', '✗ ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

document.getElementById('edit-form').addEventListener('submit', openConfirmModal);
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && nokMismatchModal && nokMismatchModal.style.display === 'flex') {
        e.preventDefault(); closeNokMismatchModal(false); return;
    }
    if (e.ctrlKey && (e.shiftKey || e.altKey) && e.key === 'Enter') {
        e.preventDefault();
        if (modal.style.display === 'flex') { executeSave(); } else { openConfirmModal({preventDefault: ()=>{}}); }
    }
});

// Initialization
(function initEdit() {
    // 1. Nastavení typu filtry/série + natáhnutí pracoviště
    setTyp(currentTyp);
    const savedPracovisteId = document.getElementById('pracovisite_id').value;
    if (savedPracovisteId) {
        loadPracovisteData(savedPracovisteId);
    }

    // 2. Naplnění tabulek
    if (initialWorkers.length > 0) initialWorkers.forEach(w => addWorkerRow(w)); else addWorkerRow();
    if (initialProstoje.length > 0) initialProstoje.forEach(p => addTechRow(p)); else addTechRow();

    // 3. Render NOK (Už zachová existující hodnoty díky inicializačním datům)
    renderNokGroups();

    // 4. Kalkulace a render
    calcKs(); 
    calcProstoje(); 
    updateNokBadge(); 
    updateMeta();
    //renderHourlyGrid();
})();

</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>