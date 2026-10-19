<?php
// admin/zapis_edit.php – Editace existujícího záznamu
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

// Načtení stejných číselníků jako v index.php
$pracovisteList = getPracovisteList();
$pracovniciList = getPracovniciList();
$nokKodyFlat    = getNokKodyGrouped();
$nokAfoMap      = getNokAfoVisibilityMap();
$nokOperatorAfoMap = getNokAfoOperatorExclusionMap();
$prostojTypy    = getProstojTypyList();
$smenyList      = getSmenyList();
$prestavkyList  = getPrestavkyList();

$workers      = db_all("SELECT
    zp.*,
    p.interni_id,
    COALESCE(
        NULLIF(p_id.jmeno, ''),
        NULLIF(p_interni.jmeno, ''),
        NULLIF(pn.jmeno, ''),
        zp.jmeno
    ) AS display_jmeno
    FROM zapis_pracovnici zp
    LEFT JOIN pracovnici p ON p.id = zp.pracovnik_id
    LEFT JOIN pracovnici p_interni ON CAST(p_interni.interni_id AS UNSIGNED) = zp.pracovnik_id
    LEFT JOIN pracovnici p_id ON p_id.id = CAST(zp.jmeno AS UNSIGNED)
    LEFT JOIN (
        SELECT MIN(id) AS id, LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci AS jmeno_key, MIN(interni_id) AS interni_id, MIN(jmeno) AS jmeno
        FROM pracovnici
        WHERE COALESCE(TRIM(jmeno), '') <> ''
        GROUP BY LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci
    ) pn ON pn.jmeno_key = LOWER(TRIM(zp.jmeno)) COLLATE utf8mb4_unicode_ci
    WHERE zp.zapis_id=?
    ORDER BY zp.poradi, zp.id", [$id]);
    
$prostoje     = db_all("SELECT id, typ_kod, cas_od, cas_do, minuty, pocet, poznamka
    FROM zapisy_prostoje
    WHERE zapis_id=? AND UPPER(typ_kod) NOT IN ('TECH_SUM','PLAN_SUM','VYR_SUM','KVAL_SUM','LOG_SUM','OST_SUM')
    ORDER BY id", [$id]);
    
$nokZaznamy   = db_all('SELECT nok_kod_id, pocet, operator_excluded FROM zapis_nok WHERE zapis_id=? AND pocet>0', [$id]);
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

$prostCodeMap = array_fill_keys([
    'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10',
    'O1','L1','L2','V1','V2','V3','Q1','Q2','Q3','Q4'
], 0);
foreach ($prostRows as $row) {
    $code = strtoupper((string)($row['typ_kod'] ?? ''));
    if (!array_key_exists($code, $prostCodeMap)) continue;
    $prostCodeMap[$code] = (int)($row['minuty'] ?? 0);
}

$formDowntimeSummary = [
    'tech' => (int)($prostSummary['tech'] ?? 0),
    'vyr' => (int)($prostSummary['vyr'] ?? 0),
    'kval' => (int)($prostSummary['kval'] ?? 0),
    'log' => (int)($prostSummary['log'] ?? 0),
    'ost' => (int)($prostSummary['ost'] ?? 0),
    'plan' => (int)($prostSummary['plan'] ?? 0),
    'nepl' => (int)($prostSummary['nepl'] ?? 0),
    'celkem' => (int)($prostSummary['celkem'] ?? 0),
];

$nokMap = [];
$nokOperatorExcludedMap = [];
foreach ($nokZaznamy as $n) {
    $nokId = (int)$n['nok_kod_id'];
    $nokMap[$nokId] = (int)$n['pocet'];
    $nokOperatorExcludedMap[$nokId] = $n['operator_excluded'] === null ? null : (int)$n['operator_excluded'];
}
$hodinyMap = [];
foreach ($hodiny as $h) $hodinyMap[$h['hodina_od']] = $h;

// Prepare NOK groups for template (stejné jako v index.php)
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

// Fallback logic pro starší prostoje
$prostojTypyMap = array_column($prostojTypy, 'popis', 'kod');
if (empty($prostoje)) {
    $fallbackTechCode = '';
    foreach (array_keys($prostojTypyMap) as $prostojKod) {
        $prostojKod = strtoupper((string)$prostojKod);
        if ($prostojKod === '' || isZapisyDowntimeSummaryCode($prostojKod) || preg_match('/^[PVQLO]/', $prostojKod)) continue;
        $fallbackTechCode = $prostojKod;
        break;
    }
    $legacyFallbackRows = [
        ['typ_kod' => 'P1', 'minuty' => (int)($formDowntimeSummary['plan'] ?? 0)],
        ['typ_kod' => $fallbackTechCode, 'minuty' => (int)($formDowntimeSummary['tech'] ?? 0)],
        ['typ_kod' => 'V1', 'minuty' => (int)($formDowntimeSummary['vyr'] ?? 0)],
        ['typ_kod' => 'Q1', 'minuty' => (int)($formDowntimeSummary['kval'] ?? 0)],
        ['typ_kod' => 'L1', 'minuty' => (int)($formDowntimeSummary['log'] ?? 0)],
        ['typ_kod' => 'O1', 'minuty' => (int)($formDowntimeSummary['ost'] ?? 0)],
    ];
    $prostoje = [];
    foreach ($legacyFallbackRows as $fallbackRow) {
        $typKod = strtoupper(trim((string)($fallbackRow['typ_kod'] ?? '')));
        $minuty = (int)($fallbackRow['minuty'] ?? 0);
        if ($typKod === '' || $minuty <= 0 || !isset($prostojTypyMap[$typKod])) continue;
        $prostoje[] = [
            'id' => null, 'typ_kod' => $typKod, 'cas_od' => null, 'cas_do' => null,
            'minuty' => $minuty, 'pocet' => 1, 'poznamka' => null,
        ];
    }
}

// Data pro našeptávače pracovníků
$workerPositionLabels = [t('pos_operator'), t('pos_serizovac'), t('pos_mistr'), t('pos_jina')];
$workerPositionOptions = '';
foreach ($workerPositionLabels as $positionLabel) {
    $workerPositionOptions .= '<option value="' . h($positionLabel) . '">' . h($positionLabel) . '</option>';
}

$pracovniciSuggest = array_map(static function (array $pw): array {
    $label = (string)$pw['jmeno'] . ($pw['smennost'] ? ' (' . (string)$pw['smennost'] . ')' : '');
    return [
        'id' => (int)$pw['id'], 'label' => $label, 'jmeno' => (string)$pw['jmeno'],
        'smennost' => (string)($pw['smennost'] ?? ''),
        'search' => mb_strtolower(implode(' ', [(string)$pw['jmeno'], (string)($pw['smennost'] ?? ''), $label]), 'UTF-8'),
    ];
}, $pracovniciList);

// Data pro našeptávače pracoviště
$pracovisteSuggest = array_map(static function (array $pw): array {
    $label = '[' . $pw['cislo_id'] . '] ' . $pw['zakaznik'] . ' – ' . $pw['nazev_dilu'] . ' (' . $pw['kod_pracovisite'] . ')';
    return [
        'id' => (int)$pw['id'], 'cisloId' => (int)($pw['cislo_id'] ?? 0), 'label' => $label,
        'zakaznik' => (string)($pw['zakaznik'] ?? ''), 'projekt' => (string)($pw['projekt'] ?? ''),
        'verze' => (string)($pw['verze'] ?? ''), 'afo' => (string)($pw['kod_pracovisite'] ?? ''),
        'oznaceni' => (string)($pw['oznaceni'] ?? ''),
        'nazev' => (string)($pw['nazev_pracovisite'] ?? ''), 'dil' => (string)($pw['nazev_dilu'] ?? ''),
        'sap' => (string)($pw['sap_cislo'] ?? ''), 'ns' => (string)($pw['nakladove_stredisko'] ?? ''),
        'norma' => $pw['norma_ks_min'] ?? '', 'cycle' => $pw['cyklus_min'] ?? '',
        'typ' => trim((string)($pw['typ'] ?? '')),
        'search' => mb_strtolower(implode(' ', [$label, (string)($pw['zakaznik'] ?? ''), (string)($pw['projekt'] ?? ''), (string)($pw['verze'] ?? ''), (string)($pw['kod_pracovisite'] ?? ''), (string)($pw['nazev_pracovisite'] ?? ''), (string)($pw['nazev_dilu'] ?? ''), (string)($pw['sap_cislo'] ?? '')]), 'UTF-8'),
    ];
}, $pracovisteList);

$jsLang = [
    'saved' => t('saved'),
    'save_confirmation' => t('save_entry') . ' ' . t('save_confirmation_msg'),
    'error_save' => t('error_save'),
    'confirm_delete' => t('confirm_delete'),
    'saving' => t('saving'),
    'summary_net_time' => t('summary_net_time'),
    'summary_produced' => t('summary_produced'),
    'summary_nok' => t('summary_nok'),
    'summary_operator_nok' => t('summary_operator_nok'),
    'summary_ok' => t('summary_ok'),
    'summary_oee' => t('summary_oee'),
    'oee_status_above' => t('oee_status_above'),
    'oee_status_below' => t('oee_status_below'),
    'oee_status_far_below' => t('oee_status_far_below'),
    'error_required_fields' => t('error_required_fields'),
    'nok_mismatch' => t('nok_mismatch'),
    'nok_mismatch_auto_fill' => t('nok_mismatch_auto_fill'),
    'nok_mismatch_manual' => t('nok_mismatch_manual'),
    'nok_mismatch_exceeded' => t('nok_mismatch_exceeded'),
    'nok_mismatch_missing_ostatni' => t('nok_mismatch_missing_ostatni'),
    'sec_nok_empty_for_afo' => t('sec_nok_empty_for_afo'),
];

$pageTitle  = t('edit') . ' #' . $id;
$activePage = 'zaznamy';
?>
<script>
window.LANG_TEXT = <?= json_encode($jsLang) ?>;
window.SHIFT_REF_DATE = '<?= defined('SHIFT_REF_DATE_FILTRY') ? SHIFT_REF_DATE_FILTRY : '2026-08-03' ?>';
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
    <div class="d-flex align-items-center gap-2 no-print" style="justify-content:flex-end;flex-wrap:wrap">
        
        <!-- PŘIDANÉ TLAČÍTKO PRO TISK -->
        <button type="button" onclick="pripravTiskA4()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap">
            <i class="bi bi-printer me-1"></i><?= t('print') ?>
        </button>
        
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap">
            <i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?>
        </a>
    </div>
</div>

<div class="content">

<style>
/* Oprava prohlížečového autocomplete (zbělení pole) */
.form-control:-webkit-autofill,
.form-control:-webkit-autofill:hover, 
.form-control:-webkit-autofill:focus, 
.form-control:-webkit-autofill:active {
    transition: background-color 5000s ease-in-out 0s !important;
    -webkit-text-fill-color: var(--text) !important;
}
</style>

<form id="edit-form" novalidate>
<input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="typ" id="input-typ" value="<?= h($z['typ']) ?>">

<!-- Přepínač typu výroby -->
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
    <div class="type-btn <?= $z['typ']==='vstrikovna'?'active':'' ?>" id="btn-vstrikovna" onclick="setTyp('vstrikovna')">
        <span class="tb-icon"><i class="bi bi-droplet"></i></span>
        <div class="tb-label"><?= t('type_vstrikovna') ?></div>
        <div class="tb-sub"><?= t('injection_production') ?></div>
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
            <input type="hidden" id="pracovisite_id" name="pracovisite_id" value="<?= h($z['pracovisite_id'] ?? '') ?>" required>
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
            <div class="calc-display" id="disp-cw">KT <?= h($z['cw'] ?? '') ?></div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('smena') ?> <span class="req">*</span></label>
            <select name="smena" id="input-smena" class="form-select" required onchange="applySmenaDefaults()">
                <?php foreach ($smenyList as $sm): ?>
                <option value="<?= h($sm['kod']) ?>"<?= ($z['smena'] === $sm['kod']) ? ' selected' : '' ?>><?= h($sm['nazev']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd">Skupina <span class="req">*</span></label>
            <select name="skupina" id="input-skupina" class="form-select" required>
                <option value="A" <?= ($z['skupina'] ?? '') === 'A' ? 'selected' : '' ?>>Směna A</option>
                <option value="B" <?= ($z['skupina'] ?? '') === 'B' ? 'selected' : '' ?>>Směna B</option>
                <option value="C" <?= ($z['skupina'] ?? '') === 'C' ? 'selected' : '' ?>>Směna C</option>
                <option value="D" <?= ($z['skupina'] ?? '') === 'D' ? 'selected' : '' ?>>Směna D</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prichod') ?></label>
            <input type="time" name="cas_prichodu" id="cas-od" class="form-control" 
                   value="<?= $z['cas_prichodu'] ? date('H:i', strtotime($z['cas_prichodu'])) : '' ?>" onchange="calcCisty()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('odchod') ?></label>
            <input type="time" name="cas_odchodu" id="cas-do" class="form-control" 
                   value="<?= $z['cas_odchodu'] ? date('H:i', strtotime($z['cas_odchodu'])) : '' ?>" onchange="calcCisty()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prac_doba') ?></label>
            <input type="number" name="pracovni_doba_min" id="prac-doba" class="form-control" min="0" max="720" 
                   value="<?= (int)($z['pracovni_doba_min'] ?? 480) ?>" onchange="calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('pauza') ?></label>
            <input type="number" name="pauza_min" id="pauza" class="form-control" min="0" max="120" 
                   value="<?= (int)($z['pauza_min'] ?? 30) ?>" onchange="calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('prestas') ?></label>
            <input type="number" name="prestas_min" class="form-control" min="0" max="240" 
                   value="<?= (int)($z['prestas_min'] ?? 0) ?>" placeholder="0">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('cisty_cas') ?></label>
            <div class="calc-display" id="disp-cisty"><?= (int)($z['cisty_cas_min'] ?? 0) ?> min</div>
            <input type="hidden" name="cisty_cas_min" id="cisty-hidden" value="<?= (int)($z['cisty_cas_min'] ?? 0) ?>">
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
            <input type="text" name="zapsal" class="form-control" value="<?= h($z['zapsal'] ?? '') ?>" readonly style="background:rgba(255,255,255,.04);cursor:not-allowed;color:var(--muted)">
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
        
        <?php 
        $kolonky = ['tech' => t('tech_short') ?? 'Tech', 'blok' => t('blok_short') ?? 'Blok'];
        // Zpětná kompatibilita: u starých záznamů zobrazíme i TNZ, abychom je při uložení nesmazali
        if ((int)($z['tnz'] ?? 0) > 0) {
            $kolonky['tnz'] = t('tnz_short') ?? 'TNZ';
        }
        foreach($kolonky as $k => $l): ?>
        <div class="col-4 col-md-2">
            <label class="form-label-dvd"><?= $l ?></label>
            <input type="number" name="<?= $k ?>" id="<?= $k ?>" class="form-control" min="0" placeholder="0" 
                   value="<?= (int)($z[$k] ?? 0) ?: '' ?>" onchange="calcKs();calcOEE()">
        </div>
        <?php endforeach; ?>

        <div class="col-12 col-md-2">
            <label class="form-label-dvd"><?= t('vyrobeno_dobre') ?></label>
            <div class="calc-display text-accent" id="disp-dobre"><?= number_format((int)$z['vyrobeno_dobre_ks'], 0, '', ' ') ?></div>
        </div>
        <div class="col-12">
            <label class="form-label-dvd"><?= t('poznamky') ?></label>
            <textarea name="poznamky" class="form-control" rows="2" placeholder="<?= t('notes_placeholder') ?>"><?= h($z['poznamky']??'') ?></textarea>
        </div>
    </div>
    </div>
</div>

<!-- ── 05 NOK záznamy ── -->
<div class="card-dvd mb-3" id="sec-nok">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">05</span>
        <span class="s-title"><i class="bi bi-x-octagon me-2" style="color:var(--accent2)"></i><?= t('sec_nok') ?>
            <span id="nok-badge" style="display:none;background:rgba(255,107,53,.18);color:var(--accent2);font-family:var(--mono);font-size:.62rem;border-radius:3px;padding:.1rem .4rem;margin-left:.5rem"></span>
        </span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div id="nok-empty-hint" style="display:none;font-size:.78rem;color:var(--muted);margin-bottom:.6rem;">
        <?= h(t('sec_nok_empty_for_afo')) ?>
    </div>
    <input type="hidden" name="nok[134]" id="nok_134" value="0">
    <div class="row g-3" id="nok-groups-container"></div>
    </div>
</div>

<!-- ── 06 Záznam prostojů ── -->
<div class="card-dvd mb-3" id="sec-tech">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">06</span>
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

<!-- ── 07 Hodinové sledování ──
<div class="card-dvd mb-3" id="sec-hodiny">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">07</span>
        <span class="s-title"><i class="bi bi-bar-chart-steps me-2" style="color:var(--accent3)"></i><?= t('sec_hourly') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
        <div class="hourly-grid" id="hourly-container"></div>
    </div>
</div>
-->

<!-- ── 07 OEE Preview ── -->
<div class="card-dvd mb-3" id="sec-oee">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">07</span>
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
            <button type="button" onclick="closeConfirmModal()" style="background:transparent;border:none;color:var(--muted);font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.5rem;color:var(--text);font-size:.9rem;line-height:1.6;">
            <p style="margin:0 0 .75rem 0;"><?= t('confirm_save_msg') ?></p>
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.75rem 1rem;font-size:.8rem;color:var(--muted);">
                <i class="bi bi-info-circle me-2 text-accent"></i>Upravujete již existující záznam.
            </div>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" onclick="closeConfirmModal()" style="background:transparent;border:1px solid var(--border2);color:var(--text);padding:.5rem 1.25rem;border-radius:6px;font-size:.85rem;cursor:pointer;transition:all .18s;" onmouseover="this.style.borderColor='var(--text)'" onmouseout="this.style.borderColor='var(--border2)'">
                <i class="bi bi-x-lg me-1"></i><?= t('cancel_continue') ?>
            </button>
            <button type="button" onclick="executeSave()" style="background:linear-gradient(135deg,var(--accent),#00c496);border:none;color:#000;padding:.5rem 1.25rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(0,229,176,.22);" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 22px rgba(0,229,176,.38)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(0,229,176,.22)'">
                <i class="bi bi-check2 me-1"></i>Uložit změny
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

<!-- ====== TISKOVÁ ŠABLONA START ====== -->
<style>
@media print {
    body > *:not(#tisk-sekce) { display: none !important; }
    html, body {
        height: auto !important;
        min-height: 100% !important;
        overflow: visible !important;
        display: block !important;
        position: static !important;
        background: white !important;
        margin: 0 !important; 
        padding: 0 !important;
    }
    #tisk-sekce { display: block !important; width: 100%; color: black; font-family: Arial, sans-serif; }
    @page { size: A4 portrait; margin: 6mm; }
    .tisk-strana { page-break-after: always; break-after: page; width: 100%; display: block; position: relative; }
    .tisk-strana:last-child { page-break-after: auto; break-after: auto; }
    .tisk-tabulka { width: 100%; border-collapse: collapse; margin-bottom: 5px; font-size: 9pt; box-sizing: border-box; }
    .tisk-tabulka th, .tisk-tabulka td { border: 2px solid black; padding: 2px; text-align: center; vertical-align: middle; box-sizing: border-box; }
    .tisk-text-left { text-align: left !important; }
    .tisk-bold { font-weight: bold; }
    .tisk-seda { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
@media screen { #tisk-sekce { display: none !important; } }
</style>

<div id="tisk-sekce">
    <!-- STRANA 1 -->
    <div class="tisk-strana" id="tisk-strana-1">
        
        <!-- HLAVIČKA -->
        <table class="tisk-tabulka" style="table-layout: fixed;">
            <colgroup>
                <col style="width:13%">
                <col style="width:12%">
                <col style="width:5%">
                <col style="width:15%">
                <col style="width:12.5%">
                <col style="width:12.5%">
                <col style="width:12%">
                <col style="width:18%">
            </colgroup>
            
            <tr>
                <td colspan="2" rowspan="2" style="color:#d32f2f; font-size:24pt; font-weight:900; letter-spacing:1px; text-align:center;">PRETTL<br><span style="font-size:9pt; color:#555; letter-spacing:0px;">automotive</span></td>
                <td colspan="4" class="tisk-seda tisk-bold" style="font-size:14pt;">Proces</td>
                <td colspan="2" class="tisk-bold" style="font-size:11pt;">Formulář</td>
            </tr>
            
            <tr>
                <td colspan="4" rowspan="5" class="tisk-bold" style="font-size:26pt; text-transform:uppercase;">Sledování výroby</td>
                <td colspan="2" class="tisk-bold" style="font-size:10pt;">Sledování výroby</td>
            </tr>
            
            <tr>
                <td rowspan="2" class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Kód související<br>směrnice</td>
                <td rowspan="2" style="font-size:9pt;">IS-H-01</td>
                <td class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Zpracoval:</td>
                <td class="tisk-text-left" style="font-size:9pt; padding-left:4px;">O. Voves</td>
            </tr>
            
            <tr>
                <td class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Přezkoumal:</td>
                <td class="tisk-text-left" style="font-size:9pt; padding-left:4px;">F. Římek</td>
            </tr>
            
            <tr>
                <td rowspan="2" class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Kód souvisejícího<br>MP</td>
                <td rowspan="2" style="font-size:9pt;">N/A</td>
                <td class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Schválil:</td>
                <td class="tisk-text-left" style="font-size:9pt; padding-left:4px;">F. Sokol</td>
            </tr>
            
            <tr>
                <td colspan="2" id="tisk-revize-varovani" class="tisk-bold" style="background-color:#ff6666 !important; color:black; font-size:10pt;">!!! Proveď revizi !!!</td>
            </tr>
            
            <tr>
                <td rowspan="2" class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Číslo Přílohy</td>
                <td rowspan="2" style="font-size:9pt;">P-002</td>
                
                <td rowspan="2" class="tisk-bold" style="font-size:16pt; border-right:none; text-align:right;">ID</td>
                <td rowspan="2" class="tisk-bold" style="font-size:24pt; border-left:none; text-align:left; padding-left:10px;" id="tisk-id-pracoviste"></td>
                
                <td rowspan="2" style="font-size:7.5pt;">Aktualizace DVD<br><br><span id="tisk-akt-dvd">01.02.2026</span></td>
                <td rowspan="2" style="font-size:7.5pt;">Označení pracoviště<br><br><span id="tisk-ozn-prac">Pol</span></td>
                
                <td class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Verze :</td>
                <td class="tisk-text-left" style="font-size:9pt; padding-left:4px;" id="tisk-verze">06</td>
            </tr>
            
            <tr>
                <td class="tisk-seda tisk-bold tisk-text-left" style="font-size:8pt; padding-left:4px;">Datum :</td>
                <td class="tisk-text-left" style="font-size:9pt; padding-left:4px;" id="tisk-datum-revize">01.09.2026</td>
            </tr>
        </table>

        <!-- PROJEKT -->
        <table class="tisk-tabulka">
            <tr class="tisk-seda tisk-bold" style="height:22px;">
                <td style="width:20%">Zákazník</td><td style="width:20%">Název projektu</td><td style="width:20%">AFO, Pracoviště</td><td style="width:20%">Název dílu</td><td style="width:20%">SAP číslo dílu</td>
            </tr>
            <tr class="tisk-bold" style="font-size:12pt; height:30px;">
                <td id="tisk-zakaznik"></td><td id="tisk-projekt"></td><td id="tisk-afo"></td><td id="tisk-dil"></td><td id="tisk-sap"></td>
            </tr>
        </table>

        <!-- PRACOVNÍK A SMĚNA -->
        <table class="tisk-tabulka">
            <tr>
                <td class="tisk-seda" style="width:22%; height:22px;">příjmení:</td>
                <td class="tisk-seda" style="width:24%;">datum příchodu na směnu</td>
                <td class="tisk-seda" style="width:10%;">směna:</td>
                <td class="tisk-bold" style="width:28%; padding:0;">
                    <div style="display:flex; justify-content:space-around; align-items:center; font-size:14pt;"><span>R 8</span><span>O 8</span><span>N 8</span></div>
                </td>
                <td class="tisk-bold" style="width:16%; padding:0;">
                    <div style="display:flex; justify-content:space-around; align-items:center; font-size:14pt;"><span>R 12</span><span>N 12</span></div>
                </td>
            </tr>
            <tr>
                <td rowspan="3"></td>
                <td style="height:22px;"></td>
                <td class="tisk-seda" rowspan="3">norma ks</td>
                <td rowspan="3" id="tisk-normy-8h" style="padding: 2px 0;"></td>
                <td rowspan="3" id="tisk-normy-12h" style="padding: 2px 0;"></td>
            </tr>
            <tr>
                <td class="tisk-seda" style="height:22px;">
                    <div style="display:flex; justify-content:space-around; align-items:center;"><span>čas OD</span><span>čas DO</span></div>
                </td>
            </tr>
            <tr><td style="height:22px;"></td></tr>
        </table>

        <!-- TABULKY VAD A VÝSLEDKŮ -->
        <div style="display: flex; gap: 8px; align-items: stretch; margin-top: 5px;">
            <div style="width: 50%; display: flex; flex-direction: column;">
                <table class="tisk-tabulka" style="margin-bottom:5px;">
                    <thead>
                        <tr class="tisk-seda">
                            <th style="width:12%; height:20px;">kód<br>vady</th><th style="width:56%">POPIS VADY</th><th style="width:12%">Q<br>stop</th><th style="width:20%">CELKEM<br>NOK</th>
                        </tr>
                    </thead>
                    <tbody id="tisk-nok-levy"></tbody>
                </table>
                <table class="tisk-tabulka" style="margin-top: auto; margin-bottom:0;">
                     <tr><td class="tisk-bold tisk-text-left" style="width:68%; height:25px; font-size:11pt; padding-left:10px;">OK ks:</td><td style="width:32%"></td></tr>
                     <tr><td class="tisk-bold tisk-text-left" style="height:25px; font-size:11pt; padding-left:10px;">NOK ks:</td><td></td></tr>
                     <tr><td class="tisk-bold tisk-text-left" style="height:25px; font-size:11pt; padding-left:10px;">Celkem vyrobeno:</td><td></td></tr>
                </table>
            </div>
            
            <div style="width: 50%; display: flex; flex-direction: column;">
                <table class="tisk-tabulka" style="margin-bottom:5px;">
                    <thead>
                        <tr class="tisk-seda">
                            <th style="width:12%; height:20px;">kód<br>vady</th><th style="width:56%">POPIS VADY</th><th style="width:12%">Q<br>stop</th><th style="width:20%">CELKEM<br>NOK</th>
                        </tr>
                    </thead>
                    <tbody id="tisk-nok-pravy"></tbody>
                </table>
                <table class="tisk-tabulka" style="margin-top: auto; margin-bottom:0;">
                    <tr><td class="tisk-bold" style="width:12%; height:25px; font-size:10pt;">Tech</td><td class="tisk-text-left" style="width:56%; font-size:10pt; padding-left:10px;">Technologie</td><td style="width:12%"></td><td style="width:20%"></td></tr>
                    <tr><td class="tisk-bold" style="height:25px; font-size:10pt;">Blok</td><td class="tisk-text-left" style="font-size:10pt; padding-left:10px;">Blokace</td><td></td><td></td></tr>
                    <tr><td class="tisk-bold" style="height:25px; font-size:10pt;">TNZ</td><td class="tisk-text-left" style="font-size:10pt; padding-left:10px;">TNZ</td><td></td><td></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- STRANA 2 (Hodinové sledování a Slovníček) -->
    <div class="tisk-strana" id="tisk-strana-2">
        <div class="tisk-bold" style="font-size:16pt; text-align:center; margin: 15px 0 10px 0;">Hodinové sledování výroby</div>
        
        <table class="tisk-tabulka">
            <tr class="tisk-seda tisk-bold" style="font-size:10pt; height:25px;">
                <td style="width:20%">Zákazník</td><td style="width:20%">Název projektu</td><td style="width:20%">AFO, Pracoviště</td><td style="width:20%">Název dílu</td><td style="width:20%">SAP číslo dílu</td>
            </tr>
            <tr class="tisk-bold" style="font-size:12pt; height:35px;">
                <td id="tisk-zakaznik2"></td><td id="tisk-projekt2"></td><td id="tisk-afo2"></td><td id="tisk-dil2"></td><td id="tisk-sap2"></td>
            </tr>
        </table>

        <!-- HODINY -->
        <div style="display:flex; gap:10px; margin-top:10px;">
            <table class="tisk-tabulka" style="width:50%">
                <tr class="tisk-seda"><th style="height:25px;">hodina</th><th>KÓD PROSTOJE, popis, počet min</th></tr>
                <tr><td class="tisk-bold" style="height:50px;">6 - 7</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">7 - 8</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">8 - 9</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">9 - 10</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">10 - 11</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">11 - 12</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">12 - 13</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">13 - 14</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">14 - 15</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">15 - 16</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">16 - 17</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">17 - 18</td><td></td></tr>
            </table>
            <table class="tisk-tabulka" style="width:50%">
                <tr class="tisk-seda"><th style="height:25px;">hodina</th><th>KÓD PROSTOJE, popis, počet min</th></tr>
                <tr><td class="tisk-bold" style="height:50px;">18 - 19</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">19 - 20</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">20 - 21</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">21 - 22</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">22 - 23</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">23 - 24</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">24 - 1</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">1 - 2</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">2 - 3</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">3 - 4</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">4 - 5</td><td></td></tr><tr><td class="tisk-bold" style="height:50px;">5 - 6</td><td></td></tr>
            </table>
        </div>

        <!-- SLOVNÍČEK PROSTOJŮ -->
        <div style="display: flex; gap: 8px; margin-top: 10px;">
            <table class="tisk-tabulka" style="width: 50%; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
                <tr><td colspan="9" class="tisk-seda tisk-bold" style="font-size:10pt; height:20px;">Neplánované prostoje</td></tr>
                <tr class="tisk-bold tisk-seda" style="font-size: 8pt;">
                    <td style="width:11.11%;">Q1</td><td style="width:11.11%;">Q2</td><td style="width:11.11%;">Q4</td><td style="width:11.11%;">V1</td><td style="width:11.11%;">V2</td><td style="width:11.11%;">V3</td><td style="width:11.11%;">L1</td><td style="width:11.11%;">L2</td><td style="width:11.11%;">O1</td>
                </tr>
                <tr style="vertical-align: top; text-align: center; font-size: 6.5pt;">
                    <td style="padding: 1px;">nekvalitní<br>vstupní<br>materiál</td>
                    <td style="padding: 1px;">změna<br>šarže<br>(požadavek<br>Q)</td>
                    <td style="padding: 1px;">uvolnění<br>výroby</td>
                    <td style="padding: 1px;">chybí<br>pracovník</td>
                    <td style="padding: 1px;">chybějící<br>materiál<br>- výroba</td>
                    <td style="padding: 1px;">seřízení<br>linky</td>
                    <td style="padding: 1px;">chybějící<br>materiál<br>- sklad</td>
                    <td style="padding: 1px;">čekání na<br>materiál<br>(manipula-<br>nt)</td>
                    <td style="padding: 1px;">ostatní</td>
                </tr>
            </table>
            
            <table class="tisk-tabulka" style="width: 50%; table-layout: fixed; word-wrap: break-word; margin-bottom: 0;">
                <tr><td colspan="9" class="tisk-seda tisk-bold" style="font-size:10pt; height:20px;">Plánované prostoje</td></tr>
                <tr class="tisk-bold tisk-seda" style="font-size: 8pt;">
                    <td style="width:11.11%;">P1</td><td style="width:11.11%;">P2</td><td style="width:11.11%;">P3</td><td style="width:11.11%;">P4</td><td style="width:11.11%;">P5</td><td style="width:11.11%;">P6</td><td style="width:11.11%;">P7</td><td style="width:11.11%;">P8</td><td style="width:11.11%;">P9</td>
                </tr>
                <tr style="vertical-align: top; text-align: center; font-size: 6.5pt;">
                    <td style="padding: 1px;">změna<br>verze,<br>linky,<br>formy</td>
                    <td style="padding: 1px;">rozjezd<br>linky,<br>natápění</td>
                    <td style="padding: 1px;">vzorkování,<br>testování,<br>DUMMY</td>
                    <td style="padding: 1px;">čištění,<br>údržba,<br>výměna<br>el.</td>
                    <td style="padding: 1px;">schůzky,<br>školení</td>
                    <td style="padding: 1px;">školitel<br>(učitel)</td>
                    <td style="padding: 1px;">zaško-<br>lovaný<br>(žák)</td>
                    <td style="padding: 1px;">změna<br>praco-<br>viště</td>
                    <td style="padding: 1px;">inventura</td>
                </tr>
            </table>
        </div>
        
        <!-- POZNÁMKY (Bezpečně zvětšeno na 160px bez rizika 3. strany) -->
        <div style="border: 2px solid black; padding: 10px; height: 160px; margin-top: 10px;">
            <div class="tisk-bold" style="font-size: 11pt; color: #444;">Poznámky:</div>
        </div>
    </div>
</div>
<!-- ====== TISKOVÁ ŠABLONA END ====== -->
</div><!-- content -->
<div id="toast-area"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
const RECORD_ID = <?= $id ?>;

let pwData = {};
let techIdx = 0;
let workerIdx = 0;
let currentTyp = document.getElementById('input-typ').value || 'serie';

const pracovisteSuggest = <?= json_encode($pracovisteSuggest, JSON_UNESCAPED_UNICODE) ?>;
const pracovisteById = Object.fromEntries(pracovisteSuggest.map(item => [String(item.id), item]));
const nokTemplateGroups = <?= json_encode($nokTemplateGroups, JSON_UNESCAPED_UNICODE) ?>;
const nokAfoVisibilityMap = <?= json_encode($nokAfoMap, JSON_UNESCAPED_UNICODE) ?>;
const nokOperatorAfoMap = <?= json_encode($nokOperatorAfoMap, JSON_UNESCAPED_UNICODE) ?>;
const nokOperatorExcludedMap = <?= json_encode($nokOperatorExcludedMap, JSON_UNESCAPED_UNICODE) ?>;
let activePracovisteSuggest = [...pracovisteSuggest];

const prostojTypy = <?= json_encode(array_column($prostojTypy,'popis','kod'), JSON_UNESCAPED_UNICODE) ?>;
const pracovniciSuggest = <?= json_encode($pracovniciSuggest, JSON_UNESCAPED_UNICODE) ?>;
const pracovniciById = Object.fromEntries(pracovniciSuggest.map(item => [String(item.id), item]));
const workerPositionOptions = <?= json_encode($workerPositionOptions) ?>;
const prestavkyDef = <?= json_encode($prestavkyList, JSON_UNESCAPED_UNICODE) ?>;

const sDef = <?= json_encode(array_combine(
    array_column($smenyList, 'kod'),
    array_map(static fn($s) => [
        'od'    => substr((string)$s['cas_od'], 0, 5),
        'do'    => substr((string)$s['cas_do'], 0, 5),
        'min'   => (int)$s['pracovni_doba_min'],
        'pauza' => (int)$s['pauza_min'],
    ], $smenyList)
), JSON_UNESCAPED_UNICODE) ?>;

// Uložená data pro inicializaci
const initialWorkers = <?= json_encode(array_values($workers)) ?>;
const initialProstoje = <?= json_encode(array_values($prostoje)) ?>;
const nokMap = <?= json_encode($nokMap) ?>;

/* --- HODINOVÉ SLEDOVÁNÍ ZAKOMENTOVÁNO ---
const initHodiny = <?= json_encode($hodinyMap) ?>;

let savedHourlyValues = {};
for (const slot in initHodiny) {
    savedHourlyValues[slot] = {
        vyr: initHodiny[slot].vyrobeno > 0 ? initHodiny[slot].vyrobeno : '',
        p: initHodiny[slot].p_minuty > 0 ? initHodiny[slot].p_minuty : '',
        t: initHodiny[slot].t_minuty > 0 ? initHodiny[slot].t_minuty : ''
    };
}
*/

// ==========================================
// FUNKCE HODINOVÉHO SLEDOVÁNÍ
// ==========================================
function renderHourlyGrid() {
    // --- OBSAH FUNKCE ZAKOMENTOVÁN ---
    /*
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

        html += \`
        <div class="hour-card" data-slot="\${slot}">
            <div class="hour-label">\${slot}</div>
            <input type="hidden" name="hodiny[\${slot}][hodina_od]" value="\${slot}">
            <div class="row g-1">
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">\${window.LANG_TEXT.hour_from || 'Vyrobeno'}</div>
                    <input type="number" name="hodiny[\${slot}][vyrobeno]" class="form-control" min="0" placeholder="ks" 
                           value="\${escapeHtml(existing.vyr)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">P (min)</div>
                    <input type="number" name="hodiny[\${slot}][p_minuty]" class="form-control" min="0" max="60" placeholder="0" 
                           value="\${escapeHtml(existing.p)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
                <div class="col-4">
                    <div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem">T (min)</div>
                    <input type="number" name="hodiny[\${slot}][t_minuty]" class="form-control" min="0" max="60" placeholder="0" 
                           value="\${escapeHtml(existing.t)}" style="padding:.28rem .4rem;font-size:.75rem">
                </div>
            </div>
        </div>\`;
    }
    container.innerHTML = html;
    */
}

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
    autoCalcSkupina();
}

function setTyp(typ) {
    currentTyp = typ;
    document.getElementById('input-typ').value = typ;
    document.getElementById('btn-serie').classList.toggle('active', typ==='serie');
    document.getElementById('btn-filtry').classList.toggle('active', typ==='filtry');
    document.getElementById('btn-vstrikovna').classList.toggle('active', typ==='vstrikovna');
    const b = document.getElementById('typ-badge');
    if (b) {
        b.textContent = typ==='serie' ? 'SÉRIE' : (typ==='filtry' ? 'FILTRY' : 'VSTŘIKOVNA');
        b.className = 'badge-'+typ;
    }
    refreshPracovisteSuggestByTyp();
    calcCisty();
    autoCalcSkupina();
}

// ==========================================
// PRACOVIŠTĚ NAŠEPTÁVAČ
// ==========================================
let pracovisteDropdownHighlight = -1;

function getPracovisteSuggestForTyp(typ) {
    return pracovisteSuggest.filter(item => item.typ === typ || item.typ === 'obe');
}

function onPracovisteInput(val) {
    const q = String(val || '').trim();
    const hiddenId = document.getElementById('pracovisite_id');
    if (hiddenId && event && event.type === 'input') hiddenId.value = '';

    if (!q) {
        renderPracovisteDropdown(activePracovisteSuggest.slice(0, 200));
        return;
    }
    
    const qNorm = q.toLocaleLowerCase('cs-CZ');
    let results = activePracovisteSuggest.filter(item => item.search.includes(qNorm));

    results.sort((a, b) => {
        const idA = String(a.cisloId);
        const idB = String(b.cisloId);
        const cleanQ = q.replace(/[\[\]]/g, '').trim();
        const exactIdA = (idA === cleanQ);
        const exactIdB = (idB === cleanQ);
        if (exactIdA && !exactIdB) return -1;
        if (!exactIdA && exactIdB) return 1;
        
        const startsIdA = idA.startsWith(cleanQ);
        const startsIdB = idB.startsWith(cleanQ);
        if (startsIdA && !startsIdB) return -1;
        if (!startsIdA && startsIdB) return 1;
        
        const afoA = String(a.afo || '').toLocaleLowerCase('cs-CZ');
        const afoB = String(b.afo || '').toLocaleLowerCase('cs-CZ');
        const startsAfoA = afoA.startsWith(qNorm);
        const startsAfoB = afoB.startsWith(qNorm);
        if (startsAfoA && !startsAfoB) return -1;
        if (!startsAfoA && startsAfoB) return 1;
        
        return 0;
    });

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

// ==========================================
// NOK LOGIKA
// ==========================================
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
            const val = nokMap[id] || '';
            const hasSavedCount = Object.prototype.hasOwnProperty.call(nokMap, id) && Number(nokMap[id]) > 0;
            const savedExcluded = Object.prototype.hasOwnProperty.call(nokOperatorExcludedMap, id) ? nokOperatorExcludedMap[id] : null;
            const workplaceDefaults = nokOperatorAfoMap[String(document.getElementById('pracovisite_id')?.value || '')] || {};
            const workplaceExcluded = workplaceDefaults[id] === true || workplaceDefaults[id] === 1 || workplaceDefaults[id] === '1';
            const excludedByDefault = savedExcluded !== null
                ? Number(savedExcluded) === 1
                : (hasSavedCount ? false : workplaceExcluded);
            const excludedValue = excludedByDefault ? '1' : '0';
            
            items.push(`<div class="nok-item col-12 col-md-6 col-xl-4" data-nok-id="${id}" style="padding-right: 3rem; padding-bottom: 0.5rem;">
                <span class="nok-code">${kod}</span>
                <label for="nok_${id}">${popis}</label>
                <input type="number" name="nok[${id}]" id="nok_${id}" class="form-control nok-qty" min="0" max="9999" placeholder="0" value="${val}" onchange="updateNokBadge()">
                <input type="hidden" name="nok_operator_excluded[${id}]" id="nok_operator_excluded_${id}" value="${excludedValue}">
                <label class="d-flex align-items-center gap-2 mt-1" for="nok_operator_excluded_check_${id}" style="font-size:.72rem;color:var(--muted);cursor:pointer">
                    <input type="checkbox" id="nok_operator_excluded_check_${id}" data-nok-id="${id}" ${excludedByDefault ? 'checked' : ''} onchange="syncNokOperatorExcluded(this)">
                    <?= h(t('nok_operator_excluded')) ?>
                </label>
            </div>`);
        });
    });

    container.innerHTML = items.join('');
    container.dataset.rendered = '1';
}

function syncNokOperatorExcluded(checkbox) {
    const id = String(checkbox?.dataset.nokId || '');
    const hidden = document.getElementById('nok_operator_excluded_' + id);
    if (hidden) hidden.value = checkbox.checked ? '1' : '0';
    calcOEE();
}

function applyNokOperatorDefaults(workplaceId) {
    const workplaceMap = nokOperatorAfoMap[String(workplaceId || '')] || {};
    document.querySelectorAll('#sec-nok .nok-item[data-nok-id]').forEach(row => {
        const id = String(row.getAttribute('data-nok-id') || '');
        const savedExcluded = Object.prototype.hasOwnProperty.call(nokOperatorExcludedMap, id) ? nokOperatorExcludedMap[id] : null;
        const hasSavedCount = Object.prototype.hasOwnProperty.call(nokMap, id) && Number(nokMap[id]) > 0;
        const defaultExcluded = workplaceMap[id] === true || workplaceMap[id] === 1 || workplaceMap[id] === '1';
        const excluded = savedExcluded !== null ? Number(savedExcluded) === 1 : (hasSavedCount ? false : defaultExcluded);
        const checkbox = document.getElementById('nok_operator_excluded_check_' + id);
        const hidden = document.getElementById('nok_operator_excluded_' + id);
        if (checkbox) checkbox.checked = excluded;
        if (hidden) hidden.value = excluded ? '1' : '0';
    });
    calcOEE();
}

function getNokOperatorTotal() {
    return [...document.querySelectorAll('.nok-qty')].reduce((total, input) => {
        const id = input.id.replace('nok_', '');
        const excluded = document.getElementById('nok_operator_excluded_check_' + id)?.checked === true;
        return total + (excluded ? 0 : (parseInt(input.value, 10) || 0));
    }, 0);
}

function loadPracovisteData(id) {
    const selected = pracovisteById[String(id)];
    if (!id || !selected) {
        ['zakaznik','projekt','verze','afo','ns','nazev','dil','sap'].forEach(k=>document.getElementById('disp-'+k).textContent='—');
        pwData={};
        const search = document.getElementById('pracovisite_search');
        if (search) search.value = '';
        const hidden = document.getElementById('pracovisite_id');
        if (hidden) hidden.value = '';
        applyNokVisibilityByAfo('', '');
        applyNokOperatorDefaults('');
        return;
    }
    pwData = { zakaznik:selected.zakaznik, projekt:selected.projekt, verze:selected.verze,
        afo:selected.afo, nazev:selected.nazev, dil:selected.dil,
        sap:selected.sap, ns:selected.ns, oznaceni:selected.oznaceni,
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
    applyNokOperatorDefaults(String(selected.cisloId || ''));
    
    // Nastavení pauzy pro VPD, ale pole zůstává odemčené pro ruční úpravu
    const pauzaInput = document.getElementById('pauza');
    if (pauzaInput) {
        if (pwData && pwData.oznaceni && pwData.oznaceni.toUpperCase() === 'VPD') {
            pauzaInput.value = '0';
            calcCisty(); 
        } else {
            calcOEE();
        }
    } else {
        calcOEE();
    }
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
    if (idKey !== '' || String(afoText || '').trim() !== '') renderNokGroups();
    
    const mapByWorkplaceId = idKey && nokAfoVisibilityMap[idKey] ? nokAfoVisibilityMap[idKey] : null;
    const afoTokens = extractAfoTokens(afoText);
    const mapByTextTokens = buildVisibilityFromAfoTokens(afoTokens);
    const mapForAfo = mapByWorkplaceId || mapByTextTokens;
    const nokRows = Array.from(document.querySelectorAll('#sec-nok .nok-item[data-nok-id]'));
    const strictMode = idKey !== '' || String(afoText || '').trim() !== '';

    nokRows.forEach(row => {
        const nokId = String(row.getAttribute('data-nok-id') || '');
        const input = row.querySelector('input.nok-qty');
        
        // Zjistíme, zda už je v poli nějaká zadaná hodnota
        let hasValue = false;
        if (input && input.value) {
            const val = parseInt(input.value, 10);
            if (!isNaN(val) && val > 0) {
                hasValue = true;
            }
        }

        let visible = true;
        if (strictMode) {
            // Kontrola, zda je vada přiřazená k novému AFO
            const inMap = !!(mapForAfo && (mapForAfo[nokId] === true || mapForAfo[nokId] === 1 || mapForAfo[nokId] === '1'));
            
            // Vada bude viditelná POKUD patří k novému AFO --- NEBO --- pokud už má zadanou hodnotu
            visible = inMap || hasValue;
        }
        
        if (!visible && input && !input.value) {
            input.value = ''; // Pouze pojistka pro vyčištění neviditelných prázdných polí
        }
        
        // OPRAVA: Tady chybělo odstranit .parentElement! Teď se schová jen konkrétní řádek vady.
        row.style.display = visible ? '' : 'none';
    });

    // OPRAVA: Zde to také musíme kontrolovat přímo na 'row' a ne 'row.parentElement'
    const anyVisible = nokRows.some(row => row.style.display !== 'none');
    const hint = document.getElementById('nok-empty-hint');
    if (hint) hint.style.display = (mapForAfo && !anyVisible) ? '' : 'none';
    
    updateNokBadge();
}

// ==========================================
// VÝPOČTY (Směna, pauzy, OEE)
// ==========================================
function normZoneKey(val) { return String(val || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
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
function getCurrentZonaLabel() { return currentTyp === 'filtry' ? 'Filtry' : 'Série'; }
function getCurrentRezimFromSmena(smenaKod) { return String(smenaKod || '').toUpperCase().includes('12') ? 12 : 8; }

function getMergedBreaksTimeline() {
    const zonaWanted = normZoneKey(getCurrentZonaLabel());
    const smenaKod = String(document.getElementById('input-smena')?.value || '').toUpperCase();
    const rezimWanted = smenaKod.includes('12') ? 12 : 8;
    let breakIntervals = [];
    
    prestavkyDef.forEach((br) => {
        if ((parseInt(br.aktivni, 10) || 0) !== 1) return;
        if (normZoneKey(br.zona) !== zonaWanted) return;
        if ((parseInt(br.rezim_hod, 10) || 8) !== rezimWanted) return;

        const bStart = toMinutes(br.cas_od);
        let bEnd = toMinutes(br.cas_do);
        if (bStart === null || bEnd === null) return;
        
        if (bEnd <= bStart) bEnd += 1440;
        
        breakIntervals.push({ start: bStart, end: bEnd });
        breakIntervals.push({ start: bStart + 1440, end: bEnd + 1440 });
        breakIntervals.push({ start: bStart - 1440, end: bEnd - 1440 });
    });

    breakIntervals.sort((a, b) => a.start - b.start);
    let mergedBreaks = [];
    if (breakIntervals.length > 0) {
        let current = { ...breakIntervals[0] };
        for (let i = 1; i < breakIntervals.length; i++) {
            if (breakIntervals[i].start <= current.end) {
                current.end = Math.max(current.end, breakIntervals[i].end);
            } else {
                mergedBreaks.push(current);
                current = { ...breakIntervals[i] };
            }
        }
        mergedBreaks.push(current);
    }
    return mergedBreaks;
}

function calculateOverlapWithBreaks(startMin, endMin) {
    const breaks = getMergedBreaksTimeline();
    let totalOverlap = 0;
    breaks.forEach(br => {
        const overlap = Math.max(0, Math.min(endMin, br.end) - Math.max(startMin, br.start));
        totalOverlap += overlap;
    });
    return totalOverlap;
}

function computeDynamicPauzaMinutes() {
    // Pro pracoviště VPD vracíme automaticky 0 minut, ale pole necháme odemčené
    if (pwData && pwData.oznaceni && pwData.oznaceni.toUpperCase() === 'VPD') {
        return 0;
    }

    const casOd = document.getElementById('cas-od')?.value || '';
    const casDo = document.getElementById('cas-do')?.value || '';
    const odMin = toMinutes(casOd);
    const doMinRaw = toMinutes(casDo);
    if (odMin === null || doMinRaw === null) return null;

    let doMin = doMinRaw;
    if (doMin <= odMin) doMin += 1440;

    return calculateOverlapWithBreaks(odMin, doMin);
}

function applyDynamicPauza() {
    const pauzaInput = document.getElementById('pauza');
    if (!pauzaInput) return;
    const minutes = computeDynamicPauzaMinutes();
    if (minutes === null) return;
    
    pauzaInput.value = String(minutes);
    
    // Pojistka - pro jistotu zajistíme, že pole bude vždy odemčené
    pauzaInput.readOnly = false;
    pauzaInput.style.backgroundColor = '';
}


function applySmenaDefaults() {
    const s = document.getElementById('input-smena').value;
    const d = sDef[s]; if(!d) return;
    document.getElementById('cas-od').value    = d.od;
    document.getElementById('cas-do').value    = d.do;
    document.getElementById('prac-doba').value = d.min;
    applyDynamicPauza();
    calcCisty();
    autoCalcSkupina();
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
    const blok = parseInt(document.getElementById('blok')?.value) || 0;
    
    // Dobré = Vyrobeno - NOK - Blok
    const dobre = Math.max(0, v - n - blok);
    
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

    ['plan','tech','vyr','kval','log','ost','celk'].forEach(k => {
        const el = document.getElementById('ps-'+k);
        if(el) el.textContent = String(summary[k]);
    });
    calcOEE();
}

function calcOEE() {
    const vyr = parseInt(document.getElementById('vyrobeno').value) || 0;
    const nok = parseInt(document.getElementById('nokks').value) || 0;
    const nokOperator = getNokOperatorTotal();
    const tech = parseInt(document.getElementById('tech')?.value) || 0;
    const blok = parseInt(document.getElementById('blok')?.value) || 0;
    
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
        
        if (planovanyCasVyroby > 0) {
            A = Math.min(1.0, Math.max(0, operacniCas / planovanyCasVyroby));
        }
        if (pwData.cycle > 0 && operacniCas > 0) {
            P = Math.min(1.5, Math.max(0, vyr / (operacniCas / pwData.cycle)));
        } else if (operacniCas === 0) {
            P = 0;
        }
        // 3. KVALITA: srážíme NOK operátora a Tech
        if (vyr > 0) {
            Q = Math.min(1.0, Math.max(0, (vyr - (nokOperator + tech)) / vyr));
        } else {
            Q = 0;
        }
        if (A !== null && P !== null && Q !== null) {
            OEE = Math.min(1.0, Math.max(0, A * P * Q));
        }
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
        st.textContent = p2 >= 85 ? '✓ ' + (window.LANG_TEXT?.oee_status_above || 'Výborné') : (p2 >= 70 ? '⚠ ' + (window.LANG_TEXT?.oee_status_below || 'Slabé') : '✗ ' + (window.LANG_TEXT?.oee_status_far_below || 'Kritické'));
        st.style.color = p2 >= 85 ? 'var(--accent)' : (p2 >= 70 ? 'var(--warn)' : 'var(--accent2)');
    }
    
    const sl = document.getElementById('summary-lines');
    const row = (l, v, c) => `<div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding:.12rem 0"><span style="color:var(--muted)">${l}</span><span class="mono" style="font-weight:600;color:${c||'var(--text)'}">${v}</span></div>`;
    const dobre = Math.max(0, vyr - nok - blok);

    sl.innerHTML = row(window.LANG_TEXT?.summary_net_time || 'Čistý čas', cisty + ' min') + 
                   row(window.LANG_TEXT?.summary_produced || 'Vyrobeno', vyr.toLocaleString()) + 
                   row(window.LANG_TEXT?.summary_nok || 'Z toho NOK', nok + ' ks', nok > 0 ? 'var(--accent2)' : 'var(--text)') + 
                   row(window.LANG_TEXT?.summary_operator_nok || 'Z toho Operátor NOK', nokOperator + ' ks', nokOperator > 0 ? 'var(--accent2)' : 'var(--text)') +
                   row(window.LANG_TEXT?.summary_ok || 'Vyrobeno Dobře', dobre.toLocaleString(), 'var(--accent)') + 
                   row(window.LANG_TEXT?.summary_oee || 'OEE Celkem', pct(OEE), OEE >= .85 ? 'var(--accent)' : (OEE >= .7 ? 'var(--warn)' : 'var(--accent2)'));
}

// ==========================================
// PRACOVNÍCI NAŠEPTÁVAČ
// ==========================================
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function addWorkerRow(data = {}) {
    workerIdx++;
    const index = workerIdx;
    const wrapper = document.createElement('div');
    wrapper.className = 'worker-entry';
    wrapper.id = 'worker-row-' + index;
    wrapper.innerHTML = `<div style="border:1px solid var(--border);border-radius:10px;padding:.9rem;background:var(--surface2)">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label class="form-label-dvd">Pracovník</label>
                <div style="position:relative">
                    <input type="text" name="workers[${index}][pracovnik_search]" class="form-control" autocomplete="off" placeholder="Vyberte pracovníka..."
                        oninput="onWorkerInput(this)" onfocus="onWorkerInput(this)" onkeydown="onWorkerKeydown(event, this)" onblur="setTimeout(() => closeWorkerDropdown(this), 150)">
                    <div class="worker-dropdown" style="display:none;position:absolute;z-index:1000;width:100%;max-height:220px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 4px 4px;box-shadow:0 4px 8px rgba(0,0,0,.3)"></div>
                </div>
                <input type="hidden" name="workers[${index}][pracovnik_id]" value="">
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label-dvd">Nebo ručně <span class="req">*</span></label>
                <input type="text" name="workers[${index}][jmeno_rucne]" class="form-control" placeholder="Jméno a příjmení" required>
            </div>
            <div class="col-8 col-lg-3">
                <label class="form-label-dvd">Pracovní pozice</label>
                <select name="workers[${index}][pracovni_pozice]" class="form-select">${workerPositionOptions}</select>
            </div>
            <div class="col-4 col-lg-1 d-flex justify-content-end">
                <button type="button" class="btn-rm" title="Odstranit" aria-label="Odstranit" onclick="removeWorkerRow(${index})"><i class="bi bi-trash3"></i></button>
            </div>
        </div>
    </div>`;
    document.getElementById('workers-list').appendChild(wrapper);

    const hiddenWorkerId = wrapper.querySelector(`input[name="workers[${index}][pracovnik_id]"]`);
    const searchInput = wrapper.querySelector(`input[name="workers[${index}][pracovnik_search]"]`);
    const manualInput = wrapper.querySelector(`input[name="workers[${index}][jmeno_rucne]"]`);
    const position = wrapper.querySelector(`select[name="workers[${index}][pracovni_pozice]"]`);
    
    let initialName = escapeHtml(data.jmeno_rucne || data.display_jmeno || data.jmeno || '');
    if (data.pracovnik_id) {
        const selected = pracovniciById[String(data.pracovnik_id)] || null;
        if (hiddenWorkerId) hiddenWorkerId.value = String(data.pracovnik_id);
        if (searchInput && selected) searchInput.value = selected.label;
        if (manualInput) manualInput.value = initialName || selected.jmeno;
    } else if (initialName) {
        if (manualInput) manualInput.value = initialName;
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

    let results = !q ? pracovniciSuggest.slice(0, 60) : pracovniciSuggest.filter(item => item.search.includes(q.toLocaleLowerCase('cs-CZ'))).slice(0, 50);
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
    else if (e.key === 'Enter') {
        e.preventDefault();
        const target = hi >= 0 ? items[hi] : items[0];
        if (target) pickWorkerItem(target, target.dataset.id);
        return;
    } else if (e.key === 'Escape') { dd.style.display = 'none'; return; } 
    else { return; }

    dd.dataset.hi = hi;
    items.forEach((el, i) => {
        el.style.background = i === hi ? 'var(--accent)' : '';
        el.style.color = i === hi ? '#fff' : 'var(--text)';
    });
}

// ==========================================
// PROSTOJE
// ==========================================
function computeDowntimeMinutesFromRange(od, dc) {
    if (!od || !dc) return null;
    const [oh, om] = String(od).split(':').map(Number);
    const [dh, dm] = String(dc).split(':').map(Number);
    if ([oh, om, dh, dm].some(Number.isNaN)) return null;

    // Musíme vědět, kdy začala směna, kvůli nočním směnám a přesahům po půlnoci
    const shiftOd = document.getElementById('cas-od')?.value || '06:00';
    const shiftOdMin = toMinutes(shiftOd) || 0;
    
    let pStart = oh * 60 + om;
    let pEnd = dh * 60 + dm;
    
    // Pokud čas prostoje vypadá, že je "menší" než začátek směny, znamená to, že se stal až po půlnoci
    if (pStart < shiftOdMin) pStart += 1440;
    
    // Pokud konec prostoje "předchází" začátku, prostoj sám jde přes půlnoc
    if (pEnd <= pStart) pEnd += 1440;
    
    const totalRaw = pEnd - pStart; // Hrubá doba prostoje
    
    // OPRAVA: Pokud se jedná o VPD (kde nejsou pevné pauzy), 
    // překryv s pauzou ignorujeme (nastavíme na 0).
    let overlap = 0;
    if (!(pwData && pwData.oznaceni && pwData.oznaceni.toUpperCase() === 'VPD')) {
        overlap = calculateOverlapWithBreaks(pStart, pEnd);
    }
    
    // Započítáme pouze "čistý" prostoj očištěný o oficiální pauzu (kromě VPD)
    return Math.max(0, totalRaw - overlap);
}

function recalcDowntimeRowMinutes(row) {
    if (!row) return;
    const odInput = row.querySelector('input[name$="[cas_od]"]');
    const doInput = row.querySelector('input[name$="[cas_do]"]');
    const minInput = row.querySelector('input[name$="[minuty]"]');
    if (!odInput || !doInput || !minInput) return;

    odInput.style.borderColor = '';
    doInput.style.borderColor = '';

    const minutes = computeDowntimeMinutesFromRange(odInput.value, doInput.value);
    if (minutes === null) return;
    minInput.value = String(minutes);
    updateTechSum();
}

function addTechRow(d={}) {
    techIdx++;
    const i=techIdx;
    let casOd = d.cas_od ? String(d.cas_od).substring(0,5) : '';
    let casDo = d.cas_do ? String(d.cas_do).substring(0,5) : '';
    const typKod = d.typ_kod || d.typ || '';
    const opts=Object.entries(prostojTypy).map(([k,v])=>`<option value="${k}"${typKod===k?' selected':''}>${k} – ${v.substring(0,30)}</option>`).join('');
    const tr=document.createElement('tr');
    tr.id='tr-'+i;
    tr.innerHTML=`<td><select name="prostoje[${i}][typ_kod]" class="form-select" style="min-width:130px;font-size:.75rem;padding:.28rem .5rem" onchange="calcProstoje()">${opts}</select></td>
        <td><input type="time" name="prostoje[${i}][cas_od]" class="form-control" style="width:95px;font-size:.75rem" value="${casOd}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
        <td><input type="time" name="prostoje[${i}][cas_do]" class="form-control" style="width:95px;font-size:.75rem" value="${casDo}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
        <td><input type="number" name="prostoje[${i}][minuty]" class="form-control" style="width:65px;text-align:center;font-size:.75rem" min="0" value="${d.min||d.minuty||''}" onchange="updateTechSum()"></td>
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
    const sOd = toMin(shiftOd);
    let sDo = toMin(shiftDo);
    let dOd = toMin(dtOd);
    let dDo = toMin(dtDo);

    if (sOd === null || sDo === null || dOd === null || dDo === null) return true;
    if (sDo <= sOd) sDo += 1440;
    if (dOd < sOd && (dOd + 1440) <= sDo) { dOd += 1440; }
    if (dDo <= (dOd % 1440)) { dDo += 1440; } else if (dOd >= 1440 && dDo < 1440) { dDo += 1440; }

    return dOd >= sOd && dDo <= sDo;
}

function validateDowntimeRow(row) {
    const odInput = row.querySelector('input[name$="[cas_od]"]');
    const doInput = row.querySelector('input[name$="[cas_do]"]');
    if (!odInput || !doInput) return true;
    const od = odInput.value;
    const doVal = doInput.value;
    if (!od || !doVal) { odInput.style.borderColor = ''; doInput.style.borderColor = ''; return true; }

    const { shiftOd, shiftDo } = getCurrentShiftBounds();
    const valid = isTimeRangeWithinShift(od, doVal, shiftOd, shiftDo);

    if (!valid) {
        odInput.style.borderColor = 'var(--accent2)';
        doInput.style.borderColor = 'var(--accent2)';
        odInput.title = `Prostoj (${od}-${doVal}) je mimo čas směny (${shiftOd}-${shiftDo})`;
        doInput.title = `Prostoj (${od}-${doVal}) je mimo čas směny (${shiftOd}-${shiftDo})`;
    } else {
        odInput.style.borderColor = '';
        doInput.style.borderColor = '';
        odInput.title = '';
        doInput.title = '';
    }
    return valid;
}

function validateAllDowntimes() {
    let allValid = true;
    let invalidCount = 0;
    document.querySelectorAll('#tech-tbody tr').forEach(row => {
        const od = row.querySelector('input[name$="[cas_od]"]')?.value;
        const doVal = row.querySelector('input[name$="[cas_do]"]')?.value;
        if (od && doVal) {
            if (!validateDowntimeRow(row)) { allValid = false; invalidCount++; }
        }
    });
    return { allValid, invalidCount };
}

// ==========================================
// NOK KONTROLA A MODALY
// ==========================================
function getNokSectionTotals() {
    const section04 = parseInt(document.getElementById('nokks')?.value, 10) || 0;
    const tech = parseInt(document.getElementById('tech')?.value, 10) || 0;
    const section08 = [...document.querySelectorAll('.nok-qty')].reduce((a,e)=>a+(parseInt(e.value, 10)||0),0);
    return {
        section04,
        section08,
        tech,
        diff: section04 - (section08 + tech),
    };
}

function updateNokBadge() {
    const { section04, section08, tech, diff } = getNokSectionTotals();
    const b = document.getElementById('nok-badge');
    if (!b) return;

    const vysvetleno = section08 + tech;
    b.style.display = (section04 > 0 || vysvetleno > 0) ? '' : 'none';
    b.textContent = `${vysvetleno} / ${section04} ks`;
    b.style.color = diff === 0 ? 'var(--accent)' : 'var(--accent2)';
    calcOEE();
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

async function validateNokConsistencyBeforeSubmit() {
    const totals = getNokSectionTotals();
    if (totals.diff === 0) return true;
    showToast('error', '✗ Špatně zapsané NOK kusy! Celkový počet zapsaných NOK nesouhlasí s rozpisem jednotlivých vad.');
    document.getElementById('sec-nok')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
}

// ==========================================
// SUBMIT A KLÁVESOVÉ ZKRATKY
// ==========================================
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
    if (currentIndex > -1 && currentIndex < formElements.length - 1) {
        formElements[currentIndex + 1].focus();
    }
    return false;
});

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

    let workersValid = true;
    document.querySelectorAll('input[name$="[jmeno_rucne]"]').forEach(input => {
        if (!input.value.trim()) { workersValid = false; input.style.borderColor = 'var(--accent2)'; } else { input.style.borderColor = ''; }
    });
    if (!workersValid) {
        showToast('error', 'Vyplňte prosím jméno pracovníka (nebo jej vyberte ze seznamu).');
        document.getElementById('workers-list').scrollIntoView({behavior:'smooth', block:'center'});
        return;
    }

    const vyrobenoInput = document.getElementById('vyrobeno');
    const vyrobenoVal = parseInt(vyrobenoInput.value, 10) || 0;
    if (vyrobenoVal <= 0) {
        showToast('error', 'Zadejte prosím vyrobené kusy (hodnota musí být větší než 0).');
        vyrobenoInput.style.borderColor = 'var(--accent2)';
        document.getElementById('sec-vyroba').scrollIntoView({behavior:'smooth', block:'center'});
        return;
    } else {
        vyrobenoInput.style.borderColor = '';
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
if (modal) { modal.addEventListener('click', function(e) { if (e.target === modal) closeConfirmModal(); }); }

async function executeSave() {
    closeConfirmModal();
    const btn = document.getElementById('btn-submit');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>' + window.LANG_TEXT.saving;
    
    try {
        const formData = new FormData(document.getElementById('edit-form'));
        const r = await fetch(APP_URL + '/admin/save_zapis_edit.php', {method: 'POST', body: formData});
        const raw = await r.text();
        let d = null;
        try { d = JSON.parse(raw); } catch (e) {}
        if (!d || typeof d !== 'object') {
            throw new Error(`${window.LANG_TEXT.error_save} (HTTP ${r.status}): ${raw ? String(raw).slice(0, 180) : 'Empty'}`);
        }
        if (d.ok) {
            showToast('success', '✓ ' + d.message);
            setTimeout(() => { window.location.href = APP_URL + '/admin/zapis_detail.php?id=' + RECORD_ID; }, 1200);
        } else {
            showToast('error', '✗ ' + (d.error || window.LANG_TEXT.error_save));
        }
    } catch (err) {
        showToast('error', '✗ ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
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

// ===== TISK A4 FUNKCE START =====
function pripravTiskA4() {
    try {
        const getText = (id) => {
            const el = document.getElementById(id);
            if (!el) return '';
            const txt = el.textContent.trim();
            return txt === '—' ? '' : txt;
        };

        const zakaznik = getText('disp-zakaznik');
    const projekt = getText('disp-projekt');
    const afo = getText('disp-afo');
    let dil = getText('disp-dil'); // Změněno z const na let, abychom to mohli upravit
    const sap = getText('disp-sap');
    
    // --- PŘIDÁNA LOGIKA POUZE PRO TISK ---
    const verze = getText('disp-verze'); // Načteme si verzi z formuláře
    if (projekt === "Dnox 2.2 M") {
        let pripona = verze.slice(-3); // Získáme poslední 3 znaky z verze
        dil = dil + " " + pripona;     // Slepíme dohromady
    }
    // --- KONEC ÚPRAVY ---
        
        let pracId = '';
        const searchInput = document.getElementById('pracovisite_search');
        if (searchInput && searchInput.value) {
            const match = searchInput.value.match(/^\[(\d+)\]/);
            if (match) pracId = match[1];
        }
        
        document.getElementById('tisk-id-pracoviste').textContent = pracId;
        document.getElementById('tisk-zakaznik').textContent = zakaznik;
        document.getElementById('tisk-projekt').textContent = projekt;
        document.getElementById('tisk-afo').textContent = afo;
        document.getElementById('tisk-dil').textContent = dil;
        document.getElementById('tisk-sap').textContent = sap;

        document.getElementById('tisk-zakaznik2').textContent = zakaznik;
        document.getElementById('tisk-projekt2').textContent = projekt;
        document.getElementById('tisk-afo2').textContent = afo;
        document.getElementById('tisk-dil2').textContent = dil;
        document.getElementById('tisk-sap2').textContent = sap;

        const normaText = getText('d-norma');
        const normaCislo = parseFloat(normaText.replace(',', '.'));
        
        const calcNorm = (minuty) => {
            if (!isNaN(normaCislo) && normaCislo > 0) {
                const vypocet = Math.round(normaCislo * minuty);
                return `<div style="text-align:center;"><span style="font-size:11pt; font-weight:bold;">${vypocet}</span><br><span style="font-size:7pt; font-weight:normal; color:#444;">${minuty} min</span></div>`;
            } else {
                return `<div style="text-align:center;"><span style="font-size:11pt; font-weight:bold;">&nbsp;</span><br><span style="font-size:7pt; font-weight:normal; color:#444;">${minuty} min</span></div>`;
            }
        };

        const n8h = document.getElementById('tisk-normy-8h');
        if (n8h) n8h.innerHTML = `<div style="display:flex; justify-content:space-around; align-items:center;">${calcNorm(60)}${calcNorm(435)}${calcNorm(480)}</div>`;

        const n12h = document.getElementById('tisk-normy-12h');
        if (n12h) n12h.innerHTML = `<div style="display:flex; justify-content:space-around; align-items:center;">${calcNorm(660)}${calcNorm(720)}</div>`;

        const nokItems = Array.from(document.querySelectorAll('#sec-nok .nok-item')).filter(el => el.style.display !== 'none');
        const vady = [];
        
        nokItems.forEach(el => {
            const kodEl = el.querySelector('.nok-code');
            const labelEl = el.querySelector('label');
            if (kodEl && labelEl) {
                vady.push({
                    kod: kodEl.textContent.trim(),
                    popis: labelEl.textContent.trim()
                });
            }
        });

        const half = Math.ceil(vady.length / 2);
        const vadyLeft = vady.slice(0, half);
        const vadyRight = vady.slice(half);

        const getQStop = (kod) => {
            if (!kod) return '';
            const projNormalized = projekt.trim().toLowerCase();
            if (projNormalized === 'emv filter') return 'x';
            if (String(kod).trim() === '23') return 'x';
            return '3 ks';
        };

        const renderRows = (arr, minRows) => {
            let html = '';
            for (let i = 0; i < Math.max(arr.length, minRows); i++) {
                if (i < arr.length) {
                    const qStopText = getQStop(arr[i].kod);
                    html += `<tr>
                        <td class="tisk-bold" style="height:18px; font-size:9pt; padding:1px;">${arr[i].kod}</td>
                        <td class="tisk-text-left" style="font-size:8.5pt; padding:1px 4px;">${arr[i].popis}</td>
                        <td style="font-size:9pt; padding:1px;">${qStopText}</td>
                        <td style="padding:1px;"></td>
                    </tr>`;
                } else {
                    html += `<tr><td style="height:18px; padding:1px;"></td><td style="padding:1px;"></td><td style="padding:1px;"></td><td style="padding:1px;"></td></tr>`; 
                }
            }
            return html;
        };

        document.getElementById('tisk-nok-levy').innerHTML = renderRows(vadyLeft, 22);
        document.getElementById('tisk-nok-pravy').innerHTML = renderRows(vadyRight, 22);

        // --- VARIANTA B: TISK PŘES IZOLOVANÝ NEVIDITELNÝ IFRAME ---
        const printSekce = document.getElementById('tisk-sekce');
        
        // Vytvoříme neviditelné okno
        const iframe = document.createElement('iframe');
        iframe.style.position = 'absolute';
        iframe.style.width = '0px';
        iframe.style.height = '0px';
        iframe.style.border = 'none';
        document.body.appendChild(iframe);
        
        // Zapíšeme do něj HTML tabulek a čisté CSS pravidla jen a pouze pro tisk
        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write(`
            <html>
            <head>
                <style>
                    @page { size: A4 portrait; margin: 6mm; }
                    body { margin: 0; padding: 0; font-family: Arial, sans-serif; color: black; background: white; }
                    
                    /* Tímto příkazem zakážeme tiskárně dělat stranu za druhým listem */
                    .tisk-strana { page-break-after: always; width: 100%; display: block; position: relative; }
                    .tisk-strana:last-child { page-break-after: avoid !important; break-after: avoid !important; }
                    
                    .tisk-tabulka { width: 100%; border-collapse: collapse; margin-bottom: 5px; font-size: 9pt; box-sizing: border-box; }
                    .tisk-tabulka th, .tisk-tabulka td { border: 2px solid black; padding: 2px; text-align: center; vertical-align: middle; box-sizing: border-box; }
                    .tisk-text-left { text-align: left !important; }
                    .tisk-bold { font-weight: bold; }
                    .tisk-seda { background-color: #d9d9d9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                </style>
            </head>
            <body>
                ${printSekce.innerHTML}
            </body>
            </html>
        `);
        doc.close();
        
        // Z tohoto okna vyvoláme tisk. Nikdy nebude mít 3 strany.
        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            
            // Okno po sobě za chvíli uklidíme
            setTimeout(() => {
                document.body.removeChild(iframe);
            }, 1000);
        }, 250);

    } catch (err) {
        alert("Chyba při přípravě tisku: " + err.message);
        console.error(err);
    }
}
// ===== TISK A4 FUNKCE END =====


// Collapsible UI
document.querySelectorAll('.card-header-dvd[data-collapsible]').forEach(h=>{
    h.addEventListener('click',()=>{
        const body=h.nextElementSibling;
        const hidden=body.style.display==='none';
        body.style.display=hidden?'':'none';
        h.classList.toggle('collapsed',!hidden);
    });
});

// ==========================================
// STARTUP INICIALIZACE (Speciálně pro Editaci)
// ==========================================
// UNIVERZÁLNÍ A BEZPEČNÁ FUNKCE PRO VÝPOČET SKUPINY
let isEditPageLoaded = (typeof RECORD_ID !== 'undefined') ? false : true;

function autoCalcSkupina() {
    // Pojistka pro editaci
    if (typeof RECORD_ID !== 'undefined' && window.isEditPageLoaded !== true) {
        return; 
    }

    const typEl = document.getElementById('input-typ');
    const datumEl = document.getElementById('input-datum');
    const smenaEl = document.getElementById('input-smena');
    const skupinaSelect = document.getElementById('input-skupina');
    
    if (!datumEl || !smenaEl || !skupinaSelect || !typEl) return;

    const typ = typEl.value;
    const datumStr = datumEl.value;
    const smenaKod = smenaEl.value;
    
    if (!datumStr || !smenaKod) return;
    
    let newSkupina = null;

    if (typ === 'serie') {
        let targetDate = new Date(datumStr + 'T00:00:00');
        let calcDateStr = datumStr;
        
        // FIX PRO NEDĚLNÍ NOČNÍ (POUZE PRO SÉRII): Neděle = 0, posouváme na pondělí
        if (targetDate.getDay() === 0 && smenaKod.startsWith('N')) {
            let nextDay = new Date(targetDate);
            nextDay.setDate(nextDay.getDate() + 1);
            let y = nextDay.getFullYear();
            let m = String(nextDay.getMonth() + 1).padStart(2, '0');
            let d = String(nextDay.getDate()).padStart(2, '0');
            calcDateStr = `${y}-${m}-${d}`;
        }

        let cw = null;
        if (typeof getISOWeekNumber === 'function') {
            cw = getISOWeekNumber(calcDateStr);
        } else {
            let dObj = new Date(calcDateStr);
            dObj.setHours(0, 0, 0, 0);
            dObj.setDate(dObj.getDate() + 3 - (dObj.getDay() + 6) % 7);
            let week1 = new Date(dObj.getFullYear(), 0, 4);
            cw = 1 + Math.round(((dObj.getTime() - week1.getTime()) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
        }
        
        if (cw) {
            const mod = cw % 3;
            if (smenaKod.startsWith('R')) {
                newSkupina = (mod === 1) ? 'B' : (mod === 2 ? 'C' : 'A');
            } else if (smenaKod.startsWith('O')) {
                newSkupina = (mod === 1) ? 'C' : (mod === 2 ? 'A' : 'B');
            } else if (smenaKod.startsWith('N')) {
                newSkupina = (mod === 1) ? 'A' : (mod === 2 ? 'B' : 'C');
            }
        }
    } else if (typ === 'filtry') {
        
        // VÝJIMKA: Pokud se na Filtrech dělá 8h směna, automatika nesmí nic dělat
        // a nechá políčko tak, jak si ho mistr nastaví manuálně.
        if (smenaKod === 'R8' || smenaKod === 'O8' || smenaKod === 'N8') {
            return; 
        }

        // Pro Filtry je kotevní datum natvrdo 3.8.2026 (Pondělí), jinak se rozbije krátký/dlouhý týden
        const refDate = new Date('2026-08-03T00:00:00');
        const targetDate = new Date(datumStr + 'T00:00:00');
        
        if (!isNaN(targetDate.getTime()) && !isNaN(refDate.getTime())) {
            const diffTime = targetDate.getTime() - refDate.getTime();
            const diffDays = Math.round(diffTime / (1000 * 3600 * 24));
            
            const weeks = Math.floor(diffDays / 7);
            const d = diffDays - (weeks * 7); 
            
            let blockInWeek = 0;
            if (d === 2 || d === 3) blockInWeek = 1; 
            else if (d >= 4) blockInWeek = 2;        
            
            const globalBlock = (weeks * 3) + blockInWeek;
            const pairIndex = ((globalBlock % 4) + 4) % 4; 
            
            const isDay = smenaKod.startsWith('R') || smenaKod.startsWith('O');
            
            if (pairIndex === 0) newSkupina = isDay ? 'D' : 'C';
            else if (pairIndex === 1) newSkupina = isDay ? 'A' : 'B';
            else if (pairIndex === 2) newSkupina = isDay ? 'C' : 'D';
            else if (pairIndex === 3) newSkupina = isDay ? 'B' : 'A';
        }
    }
    
    if (newSkupina) {
        skupinaSelect.value = newSkupina;
        skupinaSelect.style.transition = 'background-color 0.4s';
        skupinaSelect.style.backgroundColor = 'var(--accent)';
        setTimeout(() => {
            skupinaSelect.style.backgroundColor = '';
        }, 500);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderNokGroups(); 
    
    // Načtení zobrazení Pracoviště z existujícího ID
    const savedPwId = document.getElementById('pracovisite_id')?.value;
    if (savedPwId) {
        const pwItem = pracovisteById[savedPwId];
        if (pwItem) {
            document.getElementById('pracovisite_search').value = pwItem.label;
            loadPracovisteData(savedPwId);
        }
    }
    
    // Načtení pracovníků
    if (initialWorkers && initialWorkers.length > 0) {
        initialWorkers.forEach(w => addWorkerRow(w));
    } else { addWorkerRow(); }

    // Načtení prostojů
    if (initialProstoje && initialProstoje.length > 0) {
        initialProstoje.forEach(p => addTechRow(p));
    } else { addTechRow(); }

    // Inicializace zbytku
    refreshPracovisteSuggestByTyp();
    renderHourlyGrid();
    calcKs(); 
    calcProstoje();
    updateNokBadge();
});
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
