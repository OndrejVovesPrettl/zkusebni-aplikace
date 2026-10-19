<?php
// admin/oee.php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read', 'writer']);
$pageTitle  = t('nav_oee');
$activePage = 'oee';

$fOd  = get('od', date('Y-m-01'));
$fDo  = get('do', date('Y-m-d'));
if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
$fTyp = get('typ','');
$fPrac= get('pracovisite_id','');

$where = ['z.datum BETWEEN :od AND :do'];
$params= [':od'=>$fOd,':do'=>$fDo];
if($fTyp)  { $where[]='z.typ=:typ';  $params[':typ']=$fTyp; }
if($fPrac) { $where[]='z.pracovisite_id=:prac'; $params[':prac']=$fPrac; }
$w='WHERE '.implode(' AND ',$where);
$downtimeJoin = "LEFT JOIN (
    SELECT
        zapis_id,
        SUM(CASE WHEN kategorie='tech' THEN minuty ELSE 0 END) AS dt_tech,
        SUM(CASE WHEN kategorie='vyr' THEN minuty ELSE 0 END) AS dt_vyr,
        SUM(CASE WHEN kategorie='kval' THEN minuty ELSE 0 END) AS dt_kval,
        SUM(CASE WHEN kategorie='log' THEN minuty ELSE 0 END) AS dt_log,
        SUM(CASE WHEN kategorie='ost' THEN minuty ELSE 0 END) AS dt_ost,
        SUM(CASE WHEN kategorie='plan' THEN minuty ELSE 0 END) AS dt_plan,
        SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost') THEN minuty ELSE 0 END) AS dt_neplan,
        SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) zp ON zp.zapis_id=z.id";

// --- BEZPEČNÝ VÝPOČET OEE S PŮVODNÍ LOGIKOU A STROPY ---
$sqlNetTime = "GREATEST(0, COALESCE(z.pracovni_doba_min, 0) - COALESCE(z.pauza_min, 0))";
$sqlPlanTime = "GREATEST(0, $sqlNetTime - COALESCE(zp.dt_plan, 0))";
$sqlOpTime = "GREATEST(0, $sqlNetTime - COALESCE(zp.dt_plan, 0) - COALESCE(zp.dt_neplan, 0))";

// Očekávané kusy přesně podle staré logiky - zamezuje střelbě na 150 % u nenormovaných dílů
$sqlExpected = "SUM(CASE 
    WHEN z.cycle_time_min > 0 THEN $sqlOpTime / z.cycle_time_min
    WHEN z.norma_ks > 0 THEN ($sqlOpTime / 60.0) * z.norma_ks
    ELSE z.vyrobeno_ks 
END)";

// Dostupnost (A) - max 100%
$sqlA = "LEAST(1.0, GREATEST(0.0, SUM($sqlOpTime) / NULLIF(SUM($sqlPlanTime), 0)))";

// Výkon (P) - max 150%
$sqlP = "LEAST(1.5, GREATEST(0.0, SUM(z.vyrobeno_ks) / NULLIF($sqlExpected, 0)))";

// Kvalita (Q) - max 100%
$sqlQ = "LEAST(1.0, GREATEST(0.0, SUM(GREATEST(0, z.vyrobeno_ks - z.nok_ks)) / NULLIF(SUM(z.vyrobeno_ks), 0)))";

// Celkové OEE s maximem 100%
$oeeSqlExpr = "LEAST(1.0, ($sqlA * $sqlP * $sqlQ)) * 100";

// Denní OEE
$denni = db_all("SELECT z.datum,
    ROUND(COALESCE($oeeSqlExpr, 0), 2) AS oee_avg,
    COUNT(*) cnt, SUM(z.vyrobeno_ks) vyrobeno, SUM(z.nok_ks) nok
    FROM zapisy z $downtimeJoin $w
    GROUP BY z.datum ORDER BY z.datum", $params);

// OEE dle pracoviště
$pracData = db_all("SELECT z.nazev_dilu, z.kod_pracovisite,
    ROUND(COALESCE($oeeSqlExpr, 0), 1) AS oee_avg,
    COUNT(*) cnt, SUM(z.vyrobeno_ks) vyrobeno
    FROM zapisy z $downtimeJoin $w AND z.kod_pracovisite IS NOT NULL
    GROUP BY z.kod_pracovisite, z.nazev_dilu ORDER BY oee_avg DESC LIMIT 20", $params);

// Prostoje souhrn
$prostoje = db_row("SELECT
    SUM(COALESCE(zp.dt_tech,0)) tech,
    SUM(COALESCE(zp.dt_vyr,0)) vyr,
    SUM(COALESCE(zp.dt_kval,0)) kval,
    SUM(COALESCE(zp.dt_log,0)) log,
    SUM(COALESCE(zp.dt_ost,0)) ost,
    SUM(COALESCE(zp.dt_plan,0)) plan,
    SUM(COALESCE(zp.dt_total,0)) celkem
    FROM zapisy z $downtimeJoin $w", $params);

// Prostoje dle pracoviště
$prostojePrac = db_all("SELECT z.kod_pracovisite, z.nazev_dilu,
    SUM(COALESCE(zp.dt_tech,0)) tech, SUM(COALESCE(zp.dt_vyr,0)) vyr,
    SUM(COALESCE(zp.dt_kval,0)) kval, SUM(COALESCE(zp.dt_log,0)) log,
    SUM(COALESCE(zp.dt_ost,0)) ost, SUM(COALESCE(zp.dt_plan,0)) plan,
    SUM(COALESCE(zp.dt_total,0)) celkem
    FROM zapisy z $downtimeJoin $w AND COALESCE(zp.dt_total,0) > 0
    GROUP BY z.kod_pracovisite, z.nazev_dilu
    ORDER BY celkem DESC LIMIT 20", $params);

// Top NOK kódy
$topNok = db_all("SELECT nk.kod, nk.popis, SUM(zn.pocet) total,
        SUM(CASE WHEN COALESCE(zn.operator_excluded, nm.operator_excluded_default, 0) = 0 THEN zn.pocet ELSE 0 END) AS operator_total
    FROM zapis_nok zn JOIN nok_kody nk ON nk.id=zn.nok_kod_id
    JOIN zapisy z ON z.id=zn.zapis_id
    LEFT JOIN pracovisite p ON p.id=z.pracovisite_id
    LEFT JOIN nok_matrix_afo nm ON nm.afo COLLATE utf8mb4_unicode_ci = CAST(p.cislo_id AS CHAR) COLLATE utf8mb4_unicode_ci AND nm.nok_kod_id=zn.nok_kod_id
    $w GROUP BY nk.id ORDER BY total DESC LIMIT 10", $params);
if (empty($topNok)) {
    $nokTotal = (int)db_val("SELECT COALESCE(SUM(z.nok_ks),0) FROM zapisy z $w", $params);
    if ($nokTotal > 0) {
        $topNok = [[
            'kod' => 'SUM',
            'popis' => t('nok_ks').' (bez rozpisu kodu)',
            'total' => $nokTotal,
            'operator_total' => $nokTotal,
        ]];
    }
}

// Celkové stats
$stat = db_row("SELECT COUNT(*) cnt, SUM(z.vyrobeno_ks) vyr, SUM(z.nok_ks) nok,
    ROUND(COALESCE($oeeSqlExpr, 0), 2) AS oee_avg,
    SUM(COALESCE(zp.dt_total,0)) prostoj
    FROM zapisy z $downtimeJoin $w", $params);

$pracovisteList = getPracovisteList();
$selectedPracovisteName = '';
if ($fPrac !== '') {
    foreach ($pracovisteList as $pw) {
        if ((string)$pw['id'] === (string)$fPrac) {
            $selectedPracovisteName = $pw['cislo_id'] . ' - ' . $pw['nazev_dilu'];
            break;
        }
    }
}
$typLabels = [
    'serie' => t('type_serie'),
    'filtry' => t('type_filtry'),
    'vstrikovna' => t('type_vstrikovna'),
];
$selectedTypLabel = $fTyp !== '' ? ($typLabels[$fTyp] ?? $fTyp) : t('all');

// Sync state
$dvdSyncState = [];
$dvdSyncAt = null;
$dvdSyncStateFile = APP_ROOT . '/uploads/tmp/sync_dvd_state.json';
if (is_file($dvdSyncStateFile)) {
    $dvdSyncState = (array)json_decode((string)file_get_contents($dvdSyncStateFile), true);
    $dvdSyncAt = (string)($dvdSyncState['last_success_at'] ?? $dvdSyncState['last_run_at'] ?? '');
}
$dvdSyncLabel = $dvdSyncAt ? h(date('d.m.Y H:i', strtotime($dvdSyncAt))) : '—';

$pCelkem = (float)($prostoje['celkem'] ?? 0);
$pRows = [
    ['key'=>'tech',  'label'=>t('downtime_tech'),     'color'=>'#ff6b35'],
    ['key'=>'vyr',   'label'=>t('downtime_prod'),     'color'=>'#ffc107'],
    ['key'=>'kval',  'label'=>t('downtime_qual'),     'color'=>'#5b8dee'],
    ['key'=>'log',   'label'=>t('downtime_logistic'), 'color'=>'#00e5b0'],
    ['key'=>'ost',   'label'=>t('downtime_other'),    'color'=>'#a78bfa'],
    ['key'=>'plan',  'label'=>t('downtime_planned'),  'color'=>'#4ade80'],
];

require APP_ROOT . '/includes/layout.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
/* Tisková sestava je na obrazovce zcela skrytá */
.print-report {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 10mm 12mm;
    }

    /* Reset všech obalových prvků z layout.php */
    html,
    body,
    html body *:has(.print-report) {
        display: block !important;
        position: static !important;
        float: none !important;
        width: 100% !important;
        max-width: none !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        overflow: visible !important;
        background: #fff !important;
        color: #000 !important;
    }

    html, body {
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 9pt !important;
        line-height: 1.3 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Skrytí celého webového rozhraní aplikace při tisku */
    html body .topbar,
    html body .sidebar,
    html body #sidebar,
    html body nav,
    html body aside,
    html body .no-print,
    html body .screen-only {
        display: none !important;
    }

    /* Zobrazení čisté tiskové sestavy */
    html body .print-report {
        display: block !important;
        width: 100% !important;
        background: #fff !important;
        color: #000 !important;
    }

    .print-header {
        border-bottom: 2px solid #000;
        padding-bottom: 6px;
        margin-bottom: 12px;
    }

    .print-header-tbl {
        width: 100%;
        border-collapse: collapse;
    }

    .print-header-tbl td {
        padding: 0;
        border: none;
        vertical-align: bottom;
    }

    .print-title {
        font-size: 15pt;
        font-weight: 700;
        margin: 0 0 3px 0;
        color: #000;
    }

    .print-subtitle {
        font-size: 8.5pt;
        color: #333;
    }

    .print-section-title {
        font-size: 11pt;
        font-weight: 700;
        margin: 0 0 6px 0;
        padding-bottom: 3px;
        border-bottom: 1px solid #333;
        color: #000;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .print-chart-box {
        width: 100%;
        border: 1px solid #bbb;
        padding: 10px 12px;
        margin-bottom: 10px;
        background: #fff;
        box-sizing: border-box;
    }

    .print-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
        margin-bottom: 10px;
    }

    .print-table th,
    .print-table td {
        border: 1px solid #999;
        padding: 4px 6px;
        color: #000;
        vertical-align: middle;
    }

    .print-table thead th {
        background-color: #e8e8e8 !important;
        font-weight: 700;
        text-align: left;
    }

    .print-table tbody tr:nth-child(even) td {
        background-color: #f7f7f7 !important;
    }

    .print-table tfoot td {
        background-color: #e8e8e8 !important;
        font-weight: 700;
        border-top: 2px solid #000;
    }

    .print-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    /* Mírně kompaktnější řádky na 3. straně, aby se TOP 10 NOK a koláčový graf vždy vešly společně */
    .print-table-compact th,
    .print-table-compact td {
        padding: 3px 6px !important;
        font-size: 8pt !important;
    }

    .text-right {
        text-align: right !important;
        white-space: nowrap;
    }

    .text-center {
        text-align: center !important;
    }

    /* Explicitní vymezení stránek a jejich odskoků při tisku */
    .print-page {
        width: 100%;
        box-sizing: border-box;
        page-break-after: always;
        break-after: page;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .print-page:last-child {
        page-break-after: auto;
        break-after: auto;
    }

    .print-two-cols {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
    }

    .print-two-cols > tbody > tr > td {
        vertical-align: top;
        padding: 0;
        border: none;
    }
}
</style>

<!-- ===================================================================== -->
<!-- 1. WEBOVÁ VERZE PRO OBRAZOVKU (100% PŮVODNÍ VZHLED A GRAFY)           -->
<!-- ===================================================================== -->
<div class="topbar screen-only">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_oee') ?></span>
        <?php if (isSuperAdmin()): ?>
        <a href="<?= APP_URL ?>/scripts/sync_dvd_import.php?force=1" title="<?= t('sync_run_now') ?>" class="topbar-meta no-print" style="text-decoration:none"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $dvdSyncLabel ?></strong></a>
        <?php else: ?>
        <span class="topbar-meta"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $dvdSyncLabel ?></strong></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content screen-only">

<!-- Filtry -->
<form method="get" class="filter-bar no-print">
<div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('typ') ?></label>
        <select name="typ" class="form-select"><option value=""><?= t('all') ?></option>
        <option value="serie" <?=$fTyp==='serie'?'selected':''?>><?= t('type_serie') ?></option>
        <option value="filtry" <?=$fTyp==='filtry'?'selected':''?>><?= t('type_filtry') ?></option>
        <option value="vstrikovna" <?=$fTyp==='vstrikovna'?'selected':''?>><?= t('type_vstrikovna') ?></option></select></div>
    <div class="col-12 col-md-4"><label class="form-label-dvd"><?= t('pracovisite') ?></label>
        <select name="pracovisite_id" class="form-select"><option value=""><?= t('all') ?></option>
        <?php foreach($pracovisteList as $pw): ?><option value="<?= $pw['id'] ?>" <?=$fPrac==$pw['id']?'selected':''?>><?= h($pw['cislo_id'].' - '.$pw['nazev_dilu']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-auto"><button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="oee.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a></div>
</div>
</form>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php $oeeVal=$stat['oee_avg']??0;
    $cards=[
        [$stat['cnt']??0,t('records_total'),'bi-file-earmark-text','accent'],
        [number_format((int)($stat['vyr']??0)),t('produced_ks'),'bi-boxes','accent'],
        [number_format((int)($stat['nok']??0)),t('nok_ks'),'bi-x-octagon','accent2'],
        [$oeeVal?round($oeeVal,1).'%':'&mdash;',t('avg_oee'),'bi-speedometer2',$oeeVal>=85?'accent':($oeeVal>=70?'warn':'accent2')],
        [number_format((int)($stat['prostoj']??0)).' '.t('unit_minutes'),t('downtime_min'),'bi-pause-circle','accent3'],
        [count($denni),t('days_with_data'),'bi-calendar3','muted'],
    ];
    foreach($cards as [$val,$lbl,$ico,$col]):?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><div class="stat-val" style="color:var(--<?= $col ?>)"><?= $val ?></div>
        <div class="stat-label"><i class="bi <?= $ico ?> me-1"></i><?= $lbl ?></div></div>
    </div>
    <?php endforeach;?>
</div>

<!-- Denni OEE chart (full width) -->
<div class="row g-3 mb-3">
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-graph-up"></i></span><span class="s-title"><?= t('daily_oee') ?></span></div>
    <div class="card-body-dvd"><canvas id="chart-oee" height="55"></canvas></div>
</div>
</div>
</div>

<div class="row g-3">

<!-- Pracoviste OEE -->
<div class="col-12 col-xl-6">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-building"></i></span><span class="s-title"><?= t('oee_by_workplace') ?></span></div>
    <div class="card-body-dvd" style="padding:.75rem 1rem">
    <?php if(empty($pracData)): ?><p class="text-muted-dvd" style="font-size:.8rem"><?= t('no_data') ?></p>
    <?php else: foreach($pracData as $pr):
        $oee=round((float)$pr['oee_avg'],1);
        $c=$oee>=85?'var(--accent)':($oee>=70?'var(--warn)':'var(--accent2)');?>
    <div style="display:flex;align-items:center;gap:.6rem;padding:.42rem 0;border-bottom:1px solid var(--border)">
        <div style="width:190px;font-size:.76rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h(($pr['kod_pracovisite']??'').' - '.($pr['nazev_dilu']??'')) ?></div>
        <div class="oee-bar-wrap flex-grow-1"><div class="oee-bar-fill" style="width:<?= min(100,$oee) ?>%;background:<?= $c ?>"></div></div>
        <span class="mono" style="font-size:.8rem;font-weight:600;color:<?= $c ?>;min-width:45px;text-align:right"><?= $oee ?>%</span>
        <span style="font-size:.68rem;color:var(--muted);min-width:50px;text-align:right"><?= $pr['cnt'] ?> <?= t('records_short') ?></span>
    </div>
    <?php endforeach; endif;?>
    </div>
</div>
</div>

<!-- Top NOK -->
<div class="col-12 col-xl-6">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-x-octagon"></i></span><span class="s-title"><?= t('top_nok_codes') ?></span></div>
    <div class="card-body-dvd" style="padding:.75rem 1rem">
    <?php if(empty($topNok)): ?><p class="text-muted-dvd" style="font-size:.8rem"><?= t('no_data') ?></p>
    <?php else:
        $maxNok = max(array_column($topNok,'total'));
        foreach($topNok as $nk):
            $pct = $maxNok>0 ? round($nk['total']/$maxNok*100) : 0;?>
    <div style="display:flex;align-items:center;gap:.6rem;padding:.38rem 0;border-bottom:1px solid var(--border)">
        <span class="mono text-accent2" style="font-size:.68rem;min-width:2.5rem"><?= $nk['kod'] ?></span>
        <div style="flex:1;font-size:.74rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($nk['popis']) ?></div>
        <div class="oee-bar-wrap" style="width:80px"><div class="oee-bar-fill oee-bad" style="width:<?= $pct ?>%"></div></div>
        <span class="mono" style="font-size:.8rem;font-weight:600;color:var(--accent2);min-width:90px;text-align:right" title="NOK celkem / NOK operátora"><?= number_format($nk['total']) ?> / <?= number_format((int)($nk['operator_total'] ?? $nk['total'])) ?></span>
    </div>
    <?php endforeach; endif;?>
    </div>
</div>
</div>

</div><!-- row pracoviste+nok -->

<!-- Struktura prostoju - detailni report -->
<div class="row g-3 mt-1">

<!-- Kolacovy graf prostoju -->
<div class="col-12 col-xl-3">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-pie-chart"></i></span><span class="s-title"><?= t('downtime_structure') ?></span></div>
    <div class="card-body-dvd"><canvas id="chart-prostoje" height="220"></canvas></div>
</div>
</div>

<!-- Souhrn kategorii -->
<div class="col-12 col-xl-3">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-bar-chart"></i></span><span class="s-title"><?= t('downtime_structure') ?></span></div>
    <div class="card-body-dvd" style="padding:.6rem 1rem">
    <?php if($pCelkem <= 0): ?>
        <p class="text-muted-dvd" style="font-size:.8rem"><?= t('no_data') ?></p>
    <?php else: foreach($pRows as $pr):
        $val = (float)($prostoje[$pr['key']] ?? 0);
        if($val <= 0) continue;
        $pct = round($val / $pCelkem * 100, 1);
        $barW = min(100, $pct);
        ?>
        <div style="display:flex;align-items:center;gap:.5rem;padding:.38rem 0;border-bottom:1px solid var(--border)">
            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $pr['color'] ?>;flex-shrink:0"></span>
            <div style="flex:1;font-size:.76rem"><?= $pr['label'] ?></div>
            <div class="oee-bar-wrap" style="width:70px"><div class="oee-bar-fill" style="width:<?= $barW ?>%;background:<?= $pr['color'] ?>"></div></div>
            <span class="mono" style="font-size:.72rem;min-width:48px;text-align:right;color:<?= $pr['color'] ?>"><?= number_format($val) ?></span>
            <span style="font-size:.68rem;color:var(--muted);min-width:34px;text-align:right"><?= $pct ?>%</span>
        </div>
    <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding:.45rem 0;font-size:.76rem;font-weight:600;color:var(--text);margin-top:.2rem">
            <span><?= t('prostoj_celk') ?></span>
            <span class="mono"><?= number_format($pCelkem) ?> min</span>
        </div>
    <?php endif; ?>
    </div>
</div>
</div>

<!-- Prostoje dle pracoviste -->
<div class="col-12 col-xl-6">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num"><i class="bi bi-building"></i></span><span class="s-title"><?= t('downtime_structure') ?> &ndash; <?= t('pracovisite') ?></span></div>
    <div class="card-body-dvd" style="padding:0">
    <?php if(empty($prostojePrac)): ?>
        <p class="text-muted-dvd" style="font-size:.8rem;padding:.75rem 1rem"><?= t('no_data') ?></p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table-dvd" style="width:100%;font-size:.74rem">
        <thead><tr>
            <th style="min-width:140px"><?= t('pracovisite') ?></th>
            <?php foreach($pRows as $pr): ?>
            <th style="text-align:right;white-space:nowrap"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $pr['color'] ?>;margin-right:3px"></span><?= $pr['label'] ?></th>
            <?php endforeach; ?>
            <th style="text-align:right;font-weight:700"><?= t('prostoj_celk') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach($prostojePrac as $row):
            $rowTotal = (float)($row['celkem'] ?? 0);
            ?>
            <tr>
                <td style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px"><?= h(($row['kod_pracovisite'] ?? '') . ($row['nazev_dilu'] ? ' - '.$row['nazev_dilu'] : '')) ?></td>
                <?php foreach($pRows as $pr):
                    $v = (float)($row[$pr['key']] ?? 0);
                    ?>
                <td style="text-align:right;color:<?= $v > 0 ? $pr['color'] : 'var(--muted)' ?>"><?= $v > 0 ? number_format($v) : '&ndash;' ?></td>
                <?php endforeach; ?>
                <td style="text-align:right;font-weight:600;color:var(--text)"><?= number_format($rowTotal) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:700;border-top:2px solid var(--border)">
            <td><?= t('prostoj_celk') ?></td>
            <?php foreach($pRows as $pr): ?>
            <td style="text-align:right;color:<?= $pr['color'] ?>"><?= number_format((float)($prostoje[$pr['key']]??0)) ?></td>
            <?php endforeach; ?>
            <td style="text-align:right;color:var(--text)"><?= number_format($pCelkem) ?></td>
        </tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
    </div>
</div>
</div>

</div><!-- row prostoje detail -->
</div><!-- content screen-only -->


<!-- ===================================================================== -->
<!-- 2. ČISTÁ TISKOVÁ SESTAVA (ZOBRAZÍ SE POUZE PŘI TISKU)                 -->
<!-- ===================================================================== -->
<div class="print-report">

    <!-- ================================================================= -->
    <!-- STRANA 1: HLAVIČKA REPORTU + VELKÝ GRAF DENNÍ OEE                 -->
    <!-- ================================================================= -->
    <div class="print-header">
        <table class="print-header-tbl">
            <tr>
                <td>
                    <div class="print-title"><?= h(t('nav_oee')) ?> – Report výroby (<?= date('d.m.Y', strtotime($fOd)) ?> – <?= date('d.m.Y', strtotime($fDo)) ?>)</div>
                    <div class="print-subtitle">
                        <?= h(t('typ')) ?>: <strong><?= h($selectedTypLabel) ?></strong>
                        &nbsp;|&nbsp;
                        <?= h(t('pracovisite')) ?>: <strong><?= h($selectedPracovisteName !== '' ? $selectedPracovisteName : t('all')) ?></strong>
                    </div>
                </td>
                <td style="text-align:right;font-size:8.5pt;color:#222">
                    <?= h(t('avg_oee')) ?>: <strong><?= !empty($stat['oee_avg']) ? round((float)$stat['oee_avg'], 1) . '%' : '—' ?></strong>
                    &nbsp;|&nbsp;
                    <?= h(t('produced_ks')) ?>: <strong><?= number_format((int)($stat['vyr'] ?? 0)) ?></strong>
                    &nbsp;|&nbsp;
                    <?= h(t('nok_ks')) ?>: <strong><?= number_format((int)($stat['nok'] ?? 0)) ?></strong>
                    &nbsp;|&nbsp;
                    <?= h(t('downtime_min')) ?>: <strong><?= number_format((int)($stat['prostoj'] ?? 0)) ?> min</strong><br>
                    Vytištěno: <?= date('d.m.Y H:i') ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="print-section-title"><?= h(t('daily_oee')) ?></div>
    <div class="print-chart-box">
        <?php if (empty($denni)): ?>
            <p style="margin:25px 0;text-align:center;color:#555"><?= h(t('no_data')) ?></p>
        <?php else:
            $svgW = 1000;
            $svgH = 440;
            $padL = 52;
            $padR = 28;
            $padT = 32;
            $padB = 64;
            $plotW = $svgW - $padL - $padR;
            $plotH = $svgH - $padT - $padB;
            $maxY = 110;
            $nDays = count($denni);

            $pts = [];
            foreach ($denni as $i => $d) {
                $val = min($maxY, max(0.0, (float)$d['oee_avg']));
                $x = $nDays > 1 ? $padL + ($i / ($nDays - 1)) * $plotW : $padL + $plotW / 2;
                $y = $padT + $plotH - ($val / $maxY) * $plotH;
                $pts[] = [
                    'x' => round($x, 1),
                    'y' => round($y, 1),
                    'val' => round((float)$d['oee_avg'], 1),
                    'date' => date('d.m.', strtotime((string)$d['datum'])),
                ];
            }
            $polyPoints = implode(' ', array_map(static fn($p) => $p['x'] . ',' . $p['y'], $pts));
            $areaPoints = $pts[0]['x'] . ',' . ($padT + $plotH) . ' ' . $polyPoints . ' ' . $pts[count($pts) - 1]['x'] . ',' . ($padT + $plotH);
            $target85Y = round($padT + $plotH - (85 / $maxY) * $plotH, 1);
        ?>
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" style="width:100%;height:140mm;display:block;font-family:Arial,sans-serif">
            <!-- Vodorovné mřížky a popisky osy Y -->
            <?php foreach ([0, 20, 40, 60, 80, 100] as $yTick):
                $gy = round($padT + $plotH - ($yTick / $maxY) * $plotH, 1);
            ?>
                <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>" stroke="#dcdcdc" stroke-width="1" />
                <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end" font-size="11.5" fill="#222" font-weight="600"><?= $yTick ?>%</text>
            <?php endforeach; ?>

            <!-- Cílová hranice 85 % -->
            <line x1="<?= $padL ?>" y1="<?= $target85Y ?>" x2="<?= $svgW - $padR ?>" y2="<?= $target85Y ?>" stroke="#d97706" stroke-width="2" stroke-dasharray="6,4" />
            <rect x="<?= $svgW - $padR - 76 ?>" y="<?= $target85Y - 18 ?>" width="74" height="16" rx="3" fill="#fff8eb" stroke="#d97706" stroke-width="1" />
            <text x="<?= $svgW - $padR - 39 ?>" y="<?= $target85Y - 7 ?>" text-anchor="middle" font-size="10.5" font-weight="700" fill="#b45309">Cíl: 85%</text>

            <!-- Výplň a křivka OEE -->
            <polygon points="<?= $areaPoints ?>" fill="rgba(13, 148, 136, 0.12)" />
            <polyline points="<?= $polyPoints ?>" fill="none" stroke="#0f766e" stroke-width="3" />

            <!-- Body, hodnoty OEE a data na ose X -->
            <?php
            $stepLabel = $nDays > 24 ? 2 : 1;
            foreach ($pts as $i => $p):
                $showVal = ($i % $stepLabel === 0) || $i === $nDays - 1;
            ?>
                <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="4.5" fill="#0f766e" stroke="#ffffff" stroke-width="1.5" />
                <?php if ($showVal): ?>
                    <rect x="<?= $p['x'] - 20 ?>" y="<?= $p['y'] - 23 ?>" width="40" height="15" rx="3" fill="rgba(255,255,255,0.92)" stroke="#999" stroke-width="0.6" />
                    <text x="<?= $p['x'] ?>" y="<?= $p['y'] - 12 ?>" text-anchor="middle" font-size="10" font-weight="700" fill="#000"><?= $p['val'] ?>%</text>
                <?php endif; ?>
                <text x="<?= $p['x'] ?>" y="<?= $padT + $plotH + 20 ?>" text-anchor="end" transform="rotate(-35 <?= $p['x'] ?>,<?= $padT + $plotH + 20 ?>)" font-size="10.5" font-weight="600" fill="#111"><?= $p['date'] ?></text>
            <?php endforeach; ?>

            <!-- Osy -->
            <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT + $plotH ?>" stroke="#333" stroke-width="1.5" />
            <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $svgW - $padR ?>" y2="<?= $padT + $plotH ?>" stroke="#333" stroke-width="1.5" />
        </svg>
        <?php endif; ?>
    </div>


    <!-- ================================================================= -->
    <!-- STRANA 2: POUZE OEE DLE PRACOVIŠTĚ                                -->
    <!-- ================================================================= -->
    <div class="print-page-break"></div>

    <div class="print-section-title"><?= h(t('oee_by_workplace')) ?></div>
    <?php if (empty($pracData)): ?>
        <p><?= h(t('no_data')) ?></p>
    <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:50%"><?= h(t('pracovisite')) ?></th>
                    <th class="text-right" style="width:16%"><?= h(t('records_total')) ?></th>
                    <th class="text-right" style="width:17%"><?= h(t('produced_ks')) ?></th>
                    <th class="text-right" style="width:17%"><?= h(t('avg_oee')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pracData as $pr):
                    $oee = round((float)$pr['oee_avg'], 1);
                ?>
                <tr>
                    <td><strong><?= h($pr['kod_pracovisite'] ?? '') ?></strong><?= !empty($pr['nazev_dilu']) ? ' – ' . h($pr['nazev_dilu']) : '' ?></td>
                    <td class="text-right"><?= number_format((int)$pr['cnt']) ?></td>
                    <td class="text-right"><?= number_format((int)($pr['vyrobeno'] ?? 0)) ?></td>
                    <td class="text-right"><strong><?= number_format($oee, 1) ?> %</strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>


    <!-- ================================================================= -->
    <!-- STRANA 3: TOP 10 NOK KÓDŮ + STRUKTURA PROSTOJŮ                    -->
    <!-- ================================================================= -->
    <div class="print-page-break"></div>

    <div class="print-section-title"><?= h(t('top_nok_codes')) ?></div>
    <?php if (empty($topNok)): ?>
        <p><?= h(t('no_data')) ?></p>
    <?php else:
        $sumTopNok = array_sum(array_map(static fn($r) => (int)$r['total'], $topNok));
    ?>
        <table class="print-table print-table-compact" style="margin-bottom:14px">
            <thead>
                <tr>
                    <th style="width:12%">Kód</th>
                    <th style="width:48%">Popis vady</th>
                    <th class="text-right" style="width:14%">NOK celkem (ks)</th>
                    <th class="text-right" style="width:14%">NOK operátora (ks)</th>
                    <th class="text-right" style="width:12%">Podíl z TOP NOK</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topNok as $nk):
                    $totalNokRow = (int)$nk['total'];
                    $opNokRow = (int)($nk['operator_total'] ?? $totalNokRow);
                    $sharePct = $sumTopNok > 0 ? round(($totalNokRow / $sumTopNok) * 100, 1) : 0;
                ?>
                <tr>
                    <td><strong><?= h($nk['kod']) ?></strong></td>
                    <td><?= h($nk['popis']) ?></td>
                    <td class="text-right"><strong><?= number_format($totalNokRow) ?></strong></td>
                    <td class="text-right"><?= number_format($opNokRow) ?></td>
                    <td class="text-right"><?= number_format($sharePct, 1) ?> %</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="print-section-title"><?= h(t('downtime_structure')) ?></div>
    <?php
    $activeSlices = [];
    foreach ($pRows as $pr) {
        $val = (float)($prostoje[$pr['key']] ?? 0);
        if ($val > 0) {
            $activeSlices[] = [
                'label' => $pr['label'],
                'color' => $pr['color'],
                'val'   => $val,
            ];
        }
    }
    if (empty($activeSlices) && $pCelkem > 0) {
        $activeSlices[] = [
            'label' => t('downtime_other'),
            'color' => '#a78bfa',
            'val'   => $pCelkem,
        ];
    }
    $sliceSum = array_sum(array_column($activeSlices, 'val'));
    ?>

    <?php if ($sliceSum <= 0): ?>
        <p><?= h(t('no_data')) ?></p>
    <?php else: ?>
        <table class="print-two-cols" style="page-break-inside:avoid;break-inside:avoid">
            <tr>
                <!-- Velký koláčový graf s vypsanými hodnotami u výsečí -->
                <td style="width:58%;padding-right:12px">
                    <div class="print-chart-box" style="margin-bottom:0;padding:6px">
                        <?php
                        $pieW = 620;
                        $pieH = 270;
                        $cx = 310;
                        $cy = 135;
                        $rOut = 88;
                        $rIn = 42;

                        $curAngle = -M_PI / 2;
                        $slicesMeta = [];
                        $rightCallouts = [];
                        $leftCallouts = [];

                        foreach ($activeSlices as $idx => $s) {
                            $pct = ($s['val'] / $sliceSum) * 100;
                            $sweep = ($s['val'] / $sliceSum) * 2 * M_PI;
                            $drawSweep = min($sweep, 2 * M_PI - 0.0005);
                            $endAngle = $curAngle + $drawSweep;
                            $midAngle = $curAngle + $sweep / 2;

                            $x1Out = $cx + cos($curAngle) * $rOut;
                            $y1Out = $cy + sin($curAngle) * $rOut;
                            $x2Out = $cx + cos($endAngle) * $rOut;
                            $y2Out = $cy + sin($endAngle) * $rOut;

                            $x1In = $cx + cos($endAngle) * $rIn;
                            $y1In = $cy + sin($endAngle) * $rIn;
                            $x2In = $cx + cos($curAngle) * $rIn;
                            $y2In = $cy + sin($curAngle) * $rIn;

                            $largeArc = $drawSweep > M_PI ? 1 : 0;

                            $pathData = sprintf(
                                'M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z',
                                $x1Out, $y1Out, $rOut, $rOut, $largeArc, $x2Out, $y2Out,
                                $x1In, $y1In, $rIn, $rIn, $largeArc, $x2In, $y2In
                            );

                            $cosM = cos($midAngle);
                            $sinM = sin($midAngle);

                            $callout = [
                                'idx' => $idx,
                                'label' => $s['label'],
                                'val' => $s['val'],
                                'pct' => round($pct, 1),
                                'color' => $s['color'],
                                'sx' => $cx + $cosM * ($rOut - 6),
                                'sy' => $cy + $sinM * ($rOut - 6),
                                'kx' => $cx + $cosM * ($rOut + 15),
                                'ky' => $cy + $sinM * ($rOut + 15),
                                'ly' => $cy + $sinM * ($rOut + 15),
                                'isRight' => ($cosM >= 0),
                            ];

                            if ($callout['isRight']) {
                                $rightCallouts[] = $callout;
                            } else {
                                $leftCallouts[] = $callout;
                            }

                            $slicesMeta[] = [
                                'path' => $pathData,
                                'color' => $s['color'],
                                'pct' => round($pct, 1),
                                'mx' => $cx + $cosM * (($rOut + $rIn) / 2),
                                'my' => $cy + $sinM * (($rOut + $rIn) / 2),
                            ];

                            $curAngle += $sweep;
                        }

                        // Rozmístění popisků bez překrývání
                        $resolveCollisions = static function (array &$list, float $minGap = 26.0, float $minY = 20.0, float $maxY = 250.0): void {
                            usort($list, static fn($a, $b) => $a['ly'] <=> $b['ly']);
                            $n = count($list);
                            for ($i = 1; $i < $n; $i++) {
                                if ($list[$i]['ly'] - $list[$i - 1]['ly'] < $minGap) {
                                    $list[$i]['ly'] = $list[$i - 1]['ly'] + $minGap;
                                }
                            }
                            if ($n > 0 && $list[$n - 1]['ly'] > $maxY) {
                                $list[$n - 1]['ly'] = $maxY;
                                for ($i = $n - 2; $i >= 0; $i--) {
                                    if ($list[$i + 1]['ly'] - $list[$i]['ly'] < $minGap) {
                                        $list[$i]['ly'] = max($minY, $list[$i + 1]['ly'] - $minGap);
                                    }
                                }
                            }
                        };

                        $resolveCollisions($rightCallouts);
                        $resolveCollisions($leftCallouts);
                        $allCallouts = array_merge($rightCallouts, $leftCallouts);
                        ?>
                        <svg viewBox="0 0 <?= $pieW ?> <?= $pieH ?>" style="width:100%;height:68mm;display:block;font-family:Arial,sans-serif">
                            <!-- Výseče koláčového grafu -->
                            <?php foreach ($slicesMeta as $sm): ?>
                                <path d="<?= $sm['path'] ?>" fill="<?= $sm['color'] ?>" stroke="#ffffff" stroke-width="2" />
                                <?php if ($sm['pct'] >= 9): ?>
                                    <text x="<?= round($sm['mx'], 1) ?>" y="<?= round($sm['my'] + 4, 1) ?>" text-anchor="middle" font-size="10.5" font-weight="700" fill="#000" stroke="#fff" stroke-width="2.5" paint-order="stroke"><?= $sm['pct'] ?>%</text>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <!-- Střed prstence s celkovým součtem -->
                            <text x="<?= $cx ?>" y="<?= $cy - 6 ?>" text-anchor="middle" font-size="10" font-weight="700" fill="#444">CELKEM</text>
                            <text x="<?= $cx ?>" y="<?= $cy + 11 ?>" text-anchor="middle" font-size="13" font-weight="700" fill="#000"><?= number_format($sliceSum) ?> min</text>

                            <!-- Vodící čáry a vypsané hodnoty (minuty + %) u každé výseče -->
                            <?php foreach ($allCallouts as $c):
                                $endX = $c['isRight'] ? ($cx + $rOut + 30) : ($cx - $rOut - 30);
                                $textX = $c['isRight'] ? ($endX + 5) : ($endX - 5);
                                $anchor = $c['isRight'] ? 'start' : 'end';
                            ?>
                                <polyline points="<?= round($c['sx'], 1) ?>,<?= round($c['sy'], 1) ?> <?= round($c['kx'], 1) ?>,<?= round($c['ly'], 1) ?> <?= round($endX, 1) ?>,<?= round($c['ly'], 1) ?>" fill="none" stroke="#333" stroke-width="1.2" />
                                <circle cx="<?= round($c['sx'], 1) ?>" cy="<?= round($c['sy'], 1) ?>" r="2.5" fill="#000" />
                                <text x="<?= round($textX, 1) ?>" y="<?= round($c['ly'] - 2, 1) ?>" text-anchor="<?= $anchor ?>" font-size="11" font-weight="700" fill="#000">
                                    <?= h($c['label']) ?>: <?= number_format($c['val']) ?> min (<?= $c['pct'] ?>%)
                                </text>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                </td>

                <!-- Přehledná tabulka prostojů dle kategorií vedle grafu -->
                <td style="width:42%">
                    <table class="print-table print-table-compact" style="margin-bottom:0">
                        <thead>
                            <tr>
                                <th>Kategorie prostoje</th>
                                <th class="text-right">Prostoj (min)</th>
                                <th class="text-right">Podíl (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pRows as $pr):
                                $val = (float)($prostoje[$pr['key']] ?? 0);
                                if ($val <= 0) continue;
                                $pct = round(($val / $sliceSum) * 100, 1);
                            ?>
                            <tr>
                                <td>
                                    <span style="display:inline-block;width:10px;height:10px;background:<?= $pr['color'] ?>;border:1px solid #333;margin-right:5px;vertical-align:middle"></span>
                                    <strong><?= h($pr['label']) ?></strong>
                                </td>
                                <td class="text-right"><strong><?= number_format($val) ?> min</strong></td>
                                <td class="text-right"><?= number_format($pct, 1) ?> %</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><?= h(t('prostoj_celk')) ?></td>
                                <td class="text-right"><?= number_format($sliceSum) ?> min</td>
                                <td class="text-right">100.0 %</td>
                            </tr>
                        </tfoot>
                    </table>
                </td>
            </tr>
        </table>
    <?php endif; ?>


    <!-- ================================================================= -->
    <!-- STRANA 4: STRUKTURA PROSTOJŮ (MIN) – PRACOVIŠTĚ                   -->
    <!-- ================================================================= -->
    <div class="print-page-break"></div>

    <div class="print-section-title"><?= h(t('downtime_structure')) ?> – <?= h(t('pracovisite')) ?></div>
    <?php if (empty($prostojePrac)): ?>
        <p><?= h(t('no_data')) ?></p>
    <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th><?= h(t('pracovisite')) ?></th>
                    <?php foreach ($pRows as $pr): ?>
                        <th class="text-right"><?= h($pr['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-right"><?= h(t('prostoj_celk')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prostojePrac as $row):
                    $rowTotal = (float)($row['celkem'] ?? 0);
                ?>
                <tr>
                    <td><strong><?= h($row['kod_pracovisite'] ?? '') ?></strong><?= !empty($row['nazev_dilu']) ? ' – ' . h($row['nazev_dilu']) : '' ?></td>
                    <?php foreach ($pRows as $pr):
                        $v = (float)($row[$pr['key']] ?? 0);
                    ?>
                        <td class="text-right"><?= $v > 0 ? number_format($v) : '–' ?></td>
                    <?php endforeach; ?>
                    <td class="text-right"><strong><?= number_format($rowTotal) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><?= h(t('prostoj_celk')) ?></td>
                    <?php foreach ($pRows as $pr): ?>
                        <td class="text-right"><?= number_format((float)($prostoje[$pr['key']] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td class="text-right"><?= number_format($pCelkem) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

</div><!-- print-report -->

<!-- PŮVODNÍ JAVASCRIPT PRO OBRAZOVKOVÉ GRAFY V APLIKACI -->
<script>
window.LANG_TERMS = {
    'chart_oee_pct': '<?= t('chart_oee_pct') ?>',
    'oee_target': '<?= t('oee_target') ?>',
    'downtime_tech': '<?= t('downtime_tech') ?>',
    'downtime_prod': '<?= t('downtime_prod') ?>',
    'downtime_qual': '<?= t('downtime_qual') ?>',
    'downtime_logistic': '<?= t('downtime_logistic') ?>',
    'downtime_other': '<?= t('downtime_other') ?>',
    'downtime_planned': '<?= t('downtime_planned') ?>',
};
function t_val(key) { return window.LANG_TERMS[key] || key; }

Chart.defaults.color = '#5e6a88';
Chart.defaults.borderColor = '#1e2438';

const denni = <?= json_encode($denni) ?>;
new Chart(document.getElementById('chart-oee'), {
    type: 'line',
    data: {
        labels: denni.map(d=>d.datum),
        datasets: [
            { label:t_val('chart_oee_pct'), data: denni.map(d=>parseFloat(d.oee_avg)||0),
              borderColor:'#00e5b0', backgroundColor:'rgba(0,229,176,.08)',
              fill:true, tension:.35, pointRadius:4, pointBackgroundColor:'#00e5b0' },
            { label:t_val('oee_target'), data: denni.map(()=>85),
              borderColor:'rgba(255,193,7,.5)', borderDash:[6,3], pointRadius:0 }
        ]
    },
    options: { responsive:true,
        plugins:{ legend:{ labels:{color:'#5e6a88',font:{size:11}} } },
        scales: {
            x:{ grid:{color:'#1e2438'}, ticks:{color:'#5e6a88',font:{size:10},maxTicksLimit:15} },
            y:{ grid:{color:'#1e2438'}, ticks:{color:'#5e6a88',font:{size:10}}, min:0, max:110 }
        }
    }
});

const pr = <?= json_encode($prostoje) ?>;
const pVals = [pr.tech||0,pr.vyr||0,pr.kval||0,pr.log||0,pr.ost||0,pr.plan||0];
const pBreakdownTotal = pVals.reduce((acc, val) => acc + (parseFloat(val) || 0), 0);
if (pBreakdownTotal === 0 && (parseFloat(pr.celkem) || 0) > 0) {
    pVals[4] = parseFloat(pr.celkem) || 0;
}
if(pVals.some(v=>v>0)) {
new Chart(document.getElementById('chart-prostoje'), {
    type:'doughnut',
    data: {
        labels:[t_val('downtime_tech'),t_val('downtime_prod'),t_val('downtime_qual'),t_val('downtime_logistic'),t_val('downtime_other'),t_val('downtime_planned')],
        datasets:[{ data:pVals, backgroundColor:['#ff6b35','#ffc107','#5b8dee','#00e5b0','#a78bfa','#4ade80'], borderWidth:0 }]
    },
    options:{ responsive:true, cutout:'65%',
        plugins:{ legend:{ display:false } }
    }
});
}
</script>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>