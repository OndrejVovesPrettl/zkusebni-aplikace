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

$workers      = db_all('SELECT zp.*, p.interni_id FROM zapis_pracovnici zp LEFT JOIN pracovnici p ON p.id=zp.pracovnik_id WHERE zp.zapis_id=? ORDER BY zp.poradi, zp.id', [$id]);
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

$prostCodeMap = array_fill_keys([
    'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10',
    'O1','L1','L2','V1','V2','V3','Q1','Q2','Q3','Q4'
], 0);
foreach ($prostRows as $row) {
    $code = strtoupper((string)($row['typ_kod'] ?? ''));
    if (!array_key_exists($code, $prostCodeMap)) {
        continue;
    }
    $prostCodeMap[$code] = (int)($row['minuty'] ?? 0);
}

$formDowntimeByCode = [
    'p1' => $prostCodeMap['P1'] ?? 0,
    'p2' => $prostCodeMap['P2'] ?? 0,
    'p3' => $prostCodeMap['P3'] ?? 0,
    'p4' => $prostCodeMap['P4'] ?? 0,
    'p5' => $prostCodeMap['P5'] ?? 0,
    'p6' => $prostCodeMap['P6'] ?? 0,
    'p7' => $prostCodeMap['P7'] ?? 0,
    'p8' => $prostCodeMap['P8'] ?? 0,
    'p9' => $prostCodeMap['P9'] ?? 0,
    'p10' => $prostCodeMap['P10'] ?? 0,
    'o1' => $prostCodeMap['O1'] ?? 0,
    'l1' => $prostCodeMap['L1'] ?? 0,
    'l2' => $prostCodeMap['L2'] ?? 0,
    'v1' => $prostCodeMap['V1'] ?? 0,
    'v2' => $prostCodeMap['V2'] ?? 0,
    'v3' => $prostCodeMap['V3'] ?? 0,
    'q1' => $prostCodeMap['Q1'] ?? 0,
    'q2' => $prostCodeMap['Q2'] ?? 0,
    'q3' => $prostCodeMap['Q3'] ?? 0,
    'q4' => $prostCodeMap['Q4'] ?? 0,
];

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
foreach ($nokZaznamy as $n) $nokMap[(int)$n['nok_kod_id']] = (int)$n['pocet'];
$hodinyMap = [];
foreach ($hodiny as $h) $hodinyMap[$h['hodina_od']] = $h;

$pracovisteList = getPracovisteList();
$pracovniciList = getPracovniciList();
$nokKody        = getNokKodyGrouped();
$prostojTypy    = getProstojTypyList();

// Filter out NOK code 134 (same as index.php)
$nokKody = array_filter($nokKody, static function(array $nok): bool {
    return (int)($nok['id'] ?? 0) !== 134;
});
$nokKody = array_values($nokKody);

// Get AFO visibility map for JavaScript filtering
$nokAfoVisibilityMap = getNokAfoVisibilityMap();
$prostojTypyMap = array_column($prostojTypy, 'popis', 'kod');

if (empty($prostoje)) {
    $fallbackTechCode = '';
    foreach (array_keys($prostojTypyMap) as $prostojKod) {
        $prostojKod = strtoupper((string)$prostojKod);
        if ($prostojKod === '' || isZapisyDowntimeSummaryCode($prostojKod)) {
            continue;
        }
        if (preg_match('/^[PVQLO]/', $prostojKod)) {
            continue;
        }
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
        if ($typKod === '' || $minuty <= 0) {
            continue;
        }
        if (!isset($prostojTypyMap[$typKod])) {
            continue;
        }
        $prostoje[] = [
            'id' => null,
            'typ_kod' => $typKod,
            'cas_od' => null,
            'cas_do' => null,
            'minuty' => $minuty,
            'pocet' => 1,
            'poznamka' => null,
        ];
    }
}

$pCodes = [
    'p1'=>t('p1_desc'),'p2'=>t('p2_desc'),
    'p3'=>t('p3_desc'),'p4'=>t('p4_desc'),
    'p5'=>t('p5_desc'),'p6'=>t('p6_desc'),'p7'=>t('p7_desc'),
    'p8'=>t('p8_desc'),'p9'=>t('p9_desc'),'p10'=>t('p10_desc'),
];
$lCodes = ['l1'=>t('l1_desc'),'l2'=>t('l2_desc')];
$vCodes = ['v1'=>t('v1_desc'),'v2'=>t('v2_desc'),'v3'=>t('v3_desc')];
$qCodes = ['q1'=>t('q1_desc'),'q2'=>t('q2_desc'),'q3'=>t('q3_desc'),'q4'=>t('q4_desc')];

$workerPositionLabels = [t('pos_operator'), t('pos_serizovac'), t('pos_mistr'), t('pos_jina')];
$workerSelectOptions = '<option value="">' . h(t('select_worker')) . '</option>';
foreach ($pracovniciList as $pw) {
    $workerLabel = h($pw['jmeno']) . ($pw['smennost'] ? ' (' . h($pw['smennost']) . ')' : '');
    $workerSelectOptions .= '<option value="' . (int)$pw['id'] . '" data-jmeno="' . h($pw['jmeno']) . '">' . $workerLabel . '</option>';
}
$workerPositionOptions = '';
foreach ($workerPositionLabels as $positionLabel) {
    $workerPositionOptions .= '<option value="' . h($positionLabel) . '">' . h($positionLabel) . '</option>';
}

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
];

$pageTitle  = t('edit') . ' #' . $id;
$activePage = 'zaznamy';
?>
<script>
window.LANG_TEXT = <?= json_encode($jsLang) ?>;
</script>
<?php require APP_ROOT . '/includes/layout.php'; ?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="zapis_detail.php?id=<?= $id ?>" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><?= t('edit') ?> <span class="mono text-accent">#<?= $id ?></span></span>
        <span class="badge-<?= $z['typ'] ?>"><?= strtoupper($z['typ']) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

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
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">01</span>
        <span class="s-title"><i class="bi bi-card-text me-2" style="color:var(--accent)"></i><?= t('sec_ident') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label-dvd"><?= t('pracovisite') ?> <span class="req">*</span></label>
            <select name="pracovisite_id" id="pracovisite_id" class="form-select" required onchange="loadPracovisteData(this.value)">
                <option value="">— <?= t('pracovisite') ?> —</option>
                <?php foreach($pracovisteList as $pw): ?>
                <option value="<?= $pw['id'] ?>"
                    <?= $z['pracovisite_id']==$pw['id']?'selected':'' ?>
                    data-zakaznik="<?= h($pw['zakaznik']) ?>"
                    data-projekt="<?= h($pw['projekt']) ?>"
                    data-verze="<?= h($pw['verze']) ?>"
                    data-afo="<?= h($pw['kod_pracovisite']) ?>"
                    data-nazev="<?= h($pw['nazev_pracovisite']) ?>"
                    data-dil="<?= h($pw['nazev_dilu']) ?>"
                    data-sap="<?= h($pw['sap_cislo']) ?>"
                    data-ns="<?= h($pw['nakladove_stredisko']??'') ?>"
                    data-norma="<?= $pw['norma_ks_min']??'' ?>"
                    data-cycle="<?= $pw['cyklus_min']??'' ?>">
                    [<?= $pw['cislo_id'] ?>] <?= h($pw['zakaznik'].' – '.$pw['nazev_dilu'].' ('.$pw['kod_pracovisite'].')') ?>
                </option>
                <?php endforeach; ?>
            </select>
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
<div class="card-dvd mb-3">
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
            <select name="smena" id="input-smena" class="form-select" required>
                <?php foreach(['R8','O8','N8','R12','N12'] as $s): ?>
                <option value="<?= $s ?>" <?= $z['smena']===$s?'selected':'' ?>><?= $s ?></option>
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
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">04</span>
        <span class="s-title"><i class="bi bi-boxes me-2" style="color:var(--warn)"></i><?= t('sec_vyroba') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <label class="form-label-dvd"><?= t('vyrobeno') ?> <span class="req">*</span></label>
            <input type="number" name="vyrobeno_ks" id="vyrobeno" class="form-control" min="0"
                value="<?= (int)$z['vyrobeno_ks'] ?>" required onchange="calcKs();calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('nok_ks') ?></label>
            <input type="number" name="nok_ks" id="nokks" class="form-control" min="0"
                value="<?= (int)$z['nok_ks'] ?>" onchange="calcKs();calcOEE()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('k_posouzeni') ?></label>
            <input type="number" name="k_posouzeni" class="form-control" min="0"
                value="<?= (int)$z['k_posouzeni'] ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label-dvd"><?= t('vyrobeno_dobre') ?></label>
            <div class="calc-display text-accent" id="disp-dobre"><?= number_format((int)$z['vyrobeno_dobre_ks']) ?></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="d-flex gap-3 pt-4">
                <?php foreach(['tech'=>t('tech_short'),'blok'=>t('blok_short'),'tnz'=>t('tnz_short')] as $k=>$l): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="<?= $k ?>" id="chk-<?= $k ?>" <?= $z[$k]?'checked':'' ?>>
                    <label class="form-check-label" for="chk-<?= $k ?>" style="font-size:.8rem"><?= $l ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label-dvd"><?= t('poznamky') ?></label>
            <textarea name="poznamky" class="form-control" rows="2" placeholder="<?= t('notes_placeholder') ?>"><?= h($z['poznamky']??'') ?></textarea>
        </div>
    </div>
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

<!-- ── 06 NOK záznamy ── -->
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">06</span>
        <span class="s-title"><i class="bi bi-x-octagon me-2" style="color:var(--accent2)"></i><?= t('sec_nok') ?>
            <span id="nok-badge" style="display:none;background:rgba(255,107,53,.18);color:var(--accent2);font-family:var(--mono);font-size:.62rem;border-radius:3px;padding:.1rem .4rem;margin-left:.5rem"></span>
        </span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="row g-0">
    <?php foreach($nokKody as $nok): ?>
    <div class="col-12 col-md-6 col-xl-4 pe-md-3 pb-3">
        <div class="nok-item">
            <span class="nok-code"><?= $nok['kod'] ?></span>
            <label for="nok_<?= $nok['id'] ?>"><?= h($nok['popis']) ?></label>
            <input type="number" name="nok[<?= $nok['id'] ?>]" id="nok_<?= $nok['id'] ?>"
                   class="form-control nok-qty" min="0" max="9999" placeholder="0"
                   value="<?= $nokMap[(int)$nok['id']] ?? '' ?>"
                   onchange="updateNokBadge()">
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
</div>

<!-- ── 07 Hodinové sledování ── -->
<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible>
        <span class="s-num">07</span>
        <span class="s-title"><i class="bi bi-bar-chart-steps me-2" style="color:var(--accent3)"></i><?= t('sec_hourly') ?></span>
        <i class="bi bi-chevron-down s-toggle"></i>
    </div>
    <div class="card-body-dvd">
    <div class="hourly-grid">
    <?php foreach(['06-07','07-08','08-09','09-10','10-11','11-12','12-13','13-14','14-15','15-16','16-17','17-18','18-19','19-20','20-21','21-22','22-23','23-00','00-01','01-02','02-03','03-04','04-05','05-06'] as $slot):
        $hd = $hodinyMap[$slot] ?? []; ?>
    <div class="hour-card">
        <div class="hour-label"><?= $slot ?></div>
        <input type="hidden" name="hodiny[<?= $slot ?>][hodina_od]" value="<?= $slot ?>">
        <div class="row g-1">
            <div class="col-4"><div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem"><?= t('hour_from') ?></div>
                <input type="number" name="hodiny[<?= $slot ?>][vyrobeno]" class="form-control" min="0" placeholder="ks"
                    value="<?= isset($hd['vyrobeno'])&&$hd['vyrobeno']>0?(int)$hd['vyrobeno']:'' ?>"
                    style="padding:.28rem .4rem;font-size:.75rem"></div>
            <div class="col-4"><div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem"><?= t('hour_p') ?></div>
                <input type="number" name="hodiny[<?= $slot ?>][p_minuty]" class="form-control" min="0" max="60" placeholder="0"
                    value="<?= isset($hd['p_minuty'])&&$hd['p_minuty']>0?(int)$hd['p_minuty']:'' ?>"
                    style="padding:.28rem .4rem;font-size:.75rem"></div>
            <div class="col-4"><div style="font-size:.57rem;color:var(--muted);margin-bottom:.18rem"><?= t('hour_t') ?></div>
                <input type="number" name="hodiny[<?= $slot ?>][t_minuty]" class="form-control" min="0" max="60" placeholder="0"
                    value="<?= isset($hd['t_minuty'])&&$hd['t_minuty']>0?(int)$hd['t_minuty']:'' ?>"
                    style="padding:.28rem .4rem;font-size:.75rem"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
</div>

<!-- ── 08 OEE Preview ── -->
<div class="card-dvd mb-3">
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
</div><!-- content -->
<div id="toast-area"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
const RECORD_ID = <?= $id ?>;
const nokAfoVisibilityMap = <?= json_encode($nokAfoVisibilityMap) ?>;
let pwData = {};
let techIdx = 0;
let workerIdx = 0;
const prostojTypy = <?= json_encode(array_column($prostojTypy,'popis','kod')) ?>;
const workerSelectOptions = <?= json_encode($workerSelectOptions) ?>;
const workerPositionOptions = <?= json_encode($workerPositionOptions) ?>;
const initialWorkers = <?= json_encode(array_values($workers)) ?>;
const initialProstoje = <?= json_encode(array_values($prostoje)) ?>;
const pracovisteId = <?= (int)($z['pracovisite_id']??0) ?>;
const pracovisteNorma = <?= (float)($pracovisteList[array_search($z['pracovisite_id']??0,array_column($pracovisteList,'id'))]??[])['norma_ks_min'] ?? 0 ?>;
const pracovisteCycle = <?= (float)($pracovisteList[array_search($z['pracovisite_id']??0,array_column($pracovisteList,'id'))]??[])['cyklus_min'] ?? 0 ?>;

// Init pwData from selected pracovisite
(function() {
    const sel = document.getElementById('pracovisite_id');
    if (sel && sel.value) {
        const opt = sel.options[sel.selectedIndex];
        if (opt) {
            pwData = {
                norma: parseFloat(opt.dataset.norma)||0,
                cycle: parseFloat(opt.dataset.cycle)||0,
                afo: opt.dataset.afo || ''
            };
        }
    }
    document.getElementById('d-norma').textContent = pwData.norma ? pwData.norma.toFixed(4) : '—';
    document.getElementById('d-cycle').textContent = pwData.cycle ? pwData.cycle.toFixed(4) : '—';
    applyNokVisibilityByAfo(pwData.afo);
})();

function updateMeta() {
    const d = document.getElementById('input-datum').value;
    if (!d) return;
    const dt = new Date(d);
    const jan4 = new Date(dt.getFullYear(),0,4);
    const dy = (dt - new Date(dt.getFullYear(),0,0))/86400000;
    const cw = Math.ceil((dy - (jan4.getDay()||7) + 4) / 7);
    document.getElementById('disp-cw').textContent = 'KT '+cw;
}

function setTyp(typ) {
    document.getElementById('input-typ').value = typ;
    document.getElementById('btn-serie').classList.toggle('active', typ==='serie');
    document.getElementById('btn-filtry').classList.toggle('active', typ==='filtry');
}

function loadPracovisteData(id) {
    const sel = document.getElementById('pracovisite_id');
    const opt = sel.options[sel.selectedIndex];
    if (!id || !opt) { pwData={}; return; }
    pwData = {
        zakaznik:opt.dataset.zakaznik, projekt:opt.dataset.projekt, verze:opt.dataset.verze,
        afo:opt.dataset.afo, nazev:opt.dataset.nazev, dil:opt.dataset.dil,
        sap:opt.dataset.sap, ns:opt.dataset.ns,
        norma:parseFloat(opt.dataset.norma)||0, cycle:parseFloat(opt.dataset.cycle)||0
    };
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
    applyNokVisibilityByAfo(pwData.afo);
    calcOEE();
}

function applyNokVisibilityByAfo(afoText) {
    const nokInputs = Array.from(document.querySelectorAll('input[id^="nok_"]'));
    if (nokInputs.length === 0) return;
    
    const afoTokens = [];
    if (afoText) {
        const matches = String(afoText || '').match(/\d+/g) || [];
        afoTokens.push(...matches);
    }
    
    let mapForAfo = null;
    if (afoTokens.length > 0) {
        const tempMap = {};
        for (const token of afoTokens) {
            const tokenMap = nokAfoVisibilityMap[token];
            if (!tokenMap) continue;
            for (const nokId in tokenMap) {
                if (tokenMap[nokId] === true || tokenMap[nokId] === 1 || tokenMap[nokId] === '1') {
                    tempMap[nokId] = true;
                }
            }
        }
        // Only set mapForAfo if we found something, otherwise show all
        if (Object.keys(tempMap).length > 0) {
            mapForAfo = tempMap;
        }
    }
    
    nokInputs.forEach(input => {
        const nokId = input.id.replace('nok_', '');
        const nokItem = input.closest('.nok-item');
        if (!nokItem) return;
        
        let visible = true;
        if (mapForAfo) {
            visible = !!mapForAfo[nokId];
        }
        nokItem.parentElement.style.display = visible ? '' : 'none';
    });
}

function calcCisty() {
    const od=document.getElementById('cas-od').value, dc=document.getElementById('cas-do').value;
    const pauza=parseInt(document.getElementById('pauza').value)||0;
    if(od&&dc){
        let [oh,om]=od.split(':').map(Number), [dh,dm]=dc.split(':').map(Number);
        let total=(dh*60+dm)-(oh*60+om); if(total<0) total+=1440;
        total=Math.max(0,total-pauza);
        document.getElementById('prac-doba').value=total;
        document.getElementById('disp-cisty').textContent=total+' min';
        document.getElementById('cisty-hidden').value=total;
    }
    calcOEE();
}

function calcKs() {
    const v=parseInt(document.getElementById('vyrobeno').value)||0;
    const n=parseInt(document.getElementById('nokks').value)||0;
    document.getElementById('disp-dobre').textContent=(Math.max(0,v-n)).toLocaleString('cs-CZ');
}

function calcProstoje() {
    const summary = { plan: 0, tech: 0, vyr: 0, kval: 0, log: 0, ost: 0, celk: 0 };
    document.querySelectorAll('#tech-tbody tr').forEach((row) => {
        const code = String(row.querySelector('select[name$="[typ_kod]"]')?.value || '').trim().toUpperCase();
        const minutes = parseInt(row.querySelector('input[name$="[minuty]"]')?.value || '0', 10) || 0;
        if (!code || minutes <= 0) {
            return;
        }

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
    const prac = parseInt(document.getElementById('prac-doba').value) || 0;
    const pauza2 = parseInt(document.getElementById('pauza').value) || 0;
    
    // Načtení plánovaných a VŠECH prostojů z DOMu
    const plan = parseInt(document.getElementById('ps-plan')?.textContent || '0', 10) || 0;
    const celkProstoje = parseInt(document.getElementById('ps-celk')?.textContent || '0', 10) || 0;
    
    // Čistý čas = Pracovní doba mínus pauza
    const cisty = Math.max(0, prac - pauza2);
    
    document.getElementById('cisty-hidden').value = cisty;
    document.getElementById('disp-cisty').textContent = cisty + ' min';
    
    let A = null, P = null, Q = null, OEE = null;
    
    if (cisty > 0 && prac > 0) {
        // Správný základ pro Dostupnost (jen čistý čas mínus plánované prostoje)
        const planovanyCasVyroby = Math.max(1, cisty - plan);
        // Správný čas pro Výkon (čistý čas mínus úplně všechny prostoje)
        const operacniCas = Math.max(0, cisty - celkProstoje);
        
        // 1. DOSTUPNOST
        A = operacniCas / planovanyCasVyroby;
        
        // 2. VÝKON (počítáno z operačního času místo čistého)
        if (pwData.cycle > 0 && operacniCas > 0) {
            P = vyr / (operacniCas / pwData.cycle);
        } else if (operacniCas === 0) {
            P = 0;
        }
        
        // 3. KVALITA
        if (vyr > 0) {
            Q = (vyr - nok) / vyr;
        } else {
            Q = 0;
        }
        
        // OEE CELKEM (zastropováno max na 100%)
        if (A !== null && P !== null && Q !== null) {
            OEE = Math.min(1, A * P * Q);
        }
    }
    
    // Vykreslení čísel a progress barů
    const pct = v => v !== null ? (v * 100).toFixed(1) + '%' : '—';
    
    ['a','p','q'].forEach((k,i) => {
        const v = [A,P,Q][i];
        document.getElementById('oee-val-'+k).textContent = pct(v);
        if(v !== null) document.getElementById('oee-bar-'+k).style.width = Math.min(100, Math.max(0, v * 100)).toFixed(1) + '%';
    });
    
    document.getElementById('oee-total').textContent = OEE !== null ? (OEE * 100).toFixed(1) + '%' : '—';
    
    const st = document.getElementById('oee-status');
    if (OEE !== null) {
        const p2 = OEE * 100;
        st.textContent = p2 >= 85 ? '✓ ' + window.LANG_TEXT.oee_status_above : (p2 >= 70 ? '⚠ ' + window.LANG_TEXT.oee_status_below : '✗ ' + window.LANG_TEXT.oee_status_far_below);
        st.style.color = p2 >= 85 ? 'var(--accent)' : (p2 >= 70 ? 'var(--warn)' : 'var(--accent2)');
    }
    
    // Summary boxík
    const sl = document.getElementById('summary-lines');
    const row = (l, v, c) => `<div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding:.12rem 0"><span style="color:var(--muted)">${l}</span><span class="mono" style="font-weight:600;color:${c||'var(--text)'}">${v}</span></div>`;
    sl.innerHTML = row(window.LANG_TEXT.summary_net_time, cisty + ' min') + 
                   row('Operační čas', operacniCas + ' min') +
                   row(window.LANG_TEXT.summary_produced, vyr.toLocaleString()) + 
                   row(window.LANG_TEXT.summary_nok, nok + ' ks', nok > 0 ? 'var(--accent2)' : 'var(--text)') + 
                   row(window.LANG_TEXT.summary_ok, (Math.max(0, vyr - nok)).toLocaleString(), 'var(--accent)') + 
                   row(window.LANG_TEXT.summary_oee, pct(OEE), OEE >= .85 ? 'var(--accent)' : (OEE >= .7 ? 'var(--warn)' : 'var(--accent2)'));
}

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
                <label class="form-label-dvd"><?= t('pracovnik') ?></label>
                <select name="workers[${index}][pracovnik_id]" class="form-select" onchange="fillWorkerRow(this)">${workerSelectOptions}</select>
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
                <button type="button" class="btn-rm" title="<?= h(t('delete')) ?>" onclick="removeWorkerRow(${index})"><i class="bi bi-trash3"></i></button>
            </div>
        </div>
    </div>`;
    document.getElementById('workers-list').appendChild(wrapper);

    const select = wrapper.querySelector(`select[name="workers[${index}][pracovnik_id]"]`);
    const position = wrapper.querySelector(`select[name="workers[${index}][pracovni_pozice]"]`);
    if (data.pracovnik_id) select.value = String(data.pracovnik_id);
    if (data.pracovni_pozice) position.value = data.pracovni_pozice;
}

function removeWorkerRow(index) {
    const rows = document.querySelectorAll('#workers-list .worker-entry');
    const row = document.getElementById('worker-row-' + index);
    if (!row) return;
    if (rows.length === 1) {
        row.querySelectorAll('input, select').forEach(el => {
            el.tagName === 'SELECT' ? el.selectedIndex = 0 : el.value = '';
        });
        return;
    }
    row.remove();
}

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
    updateTechSum();
}

function addTechRow(d={}) {
    techIdx++;
    const i=techIdx;
    const opts=Object.entries(prostojTypy).map(([k,v])=>`<option value="${k}"${d.typ_kod===k?' selected':''}>${k} – ${v.substring(0,30)}</option>`).join('');
    const tr=document.createElement('tr');
    tr.id='tr-'+i;
    tr.innerHTML=`<td><select name="prostoje[${i}][typ_kod]" class="form-select" style="min-width:130px;font-size:.75rem;padding:.28rem .5rem" onchange="calcProstoje()">${opts}</select></td>
        <td><input type="time" name="prostoje[${i}][cas_od]" class="form-control" style="width:95px;font-size:.75rem" value="${d.cas_od||''}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
        <td><input type="time" name="prostoje[${i}][cas_do]" class="form-control" style="width:95px;font-size:.75rem" value="${d.cas_do||''}" onchange="recalcDowntimeRowMinutes(this.closest('tr'))"></td>
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

function updateNokBadge() {
    const t=[...document.querySelectorAll('.nok-qty')].reduce((a,e)=>a+(parseInt(e.value)||0),0);
    const b=document.getElementById('nok-badge'); b.style.display=t>0?'':'none'; b.textContent=t+' ks';
}

function fillWorkerRow(sel) {
    const row = sel.closest('.worker-entry');
    if (!row) return;
    const manualInput = row.querySelector('input[name$="[jmeno_rucne]"]');
    const option = sel.options[sel.selectedIndex];
    if (manualInput && option && option.value) manualInput.value = option.dataset.jmeno || '';
}

// Collapsible sections
document.querySelectorAll('.card-header-dvd[data-collapsible]').forEach(h=>{
    h.addEventListener('click',()=>{
        const body=h.nextElementSibling;
        const hidden=body.style.display==='none';
        body.style.display=hidden?'':'none';
        h.classList.toggle('collapsed',!hidden);
    });
});

// Save handler
document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const pracoviste = document.getElementById('pracovisite_id').value;
    const datum = document.getElementById('input-datum').value;
    if (!pracoviste || !datum) {
        showToast('error', window.LANG_TEXT.error_required_fields);
        return;
    }
    const btn = document.getElementById('btn-submit');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>' + window.LANG_TEXT.saving;
    try {
        const formData = new FormData(document.getElementById('edit-form'));
        const r = await fetch(APP_URL + '/admin/save_zapis_edit.php', {method:'POST', body:formData});
        const d = await r.json();
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
});

// Init existing workers
if (initialWorkers.length > 0) {
    initialWorkers.forEach(w => addWorkerRow(w));
} else {
    addWorkerRow();
}

// Init existing prostoje
if (initialProstoje.length > 0) {
    initialProstoje.forEach(p => addTechRow(p));
} else {
    addTechRow();
}

// Init sums
calcKs(); calcProstoje(); updateNokBadge(); updateMeta();
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
