<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// admin/zaznamy.php – Záznamový list se všemi filtry
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read', 'writer']);
$pageTitle  = t('records_title');
$activePage = 'zaznamy';

if (isset($_GET['clear_filters'])) {
    unset($_SESSION['zaznamy_saved_filters']);
    redirect(APP_URL . '/admin/zaznamy.php');
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$isReturningFromAction = preg_match('/(zapis_edit\.php|zapis_delete\.php|zapis_detail\.php|zaznamy\.php)/i', $referer);
if (isset($_GET['od'])) {
    if (empty($_GET['export']) && empty($_GET['print'])) {
        $filtersToSave = $_GET;
        unset($filtersToSave['msg'], $filtersToSave['status'], $filtersToSave['success'], $filtersToSave['error']);
        $_SESSION['zaznamy_saved_filters'] = $filtersToSave;
    }
} elseif (!$isReturningFromAction) {
    unset($_SESSION['zaznamy_saved_filters']);
} elseif (!empty($_SESSION['zaznamy_saved_filters'])) {
    $restore = $_SESSION['zaznamy_saved_filters'];
    foreach ($_GET as $key => $value) {
        if ($key !== 'clear_filters') {
            $restore[$key] = $value;
        }
    }
    redirect(APP_URL . '/admin/zaznamy.php?' . http_build_query($restore));
}

// ── Filtry ───────────────────────────────────────────────────
$fOd      = get('od',     date('Y-m-01'));
$fDo      = get('do',     date('Y-m-d'));
if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
$fTyp     = get('typ',    '');
$fSmena   = get('smena',  '');
$fPrac    = get('pracovisite_id', '');
$fJmeno   = trim((string)get('jmeno', ''));
$fRok     = get('rok',    '');
$fCw      = get('cw',     '');
$fSkupina = strtoupper(trim((string)get('skupina', '')));
if (!in_array($fSkupina, ['A', 'B', 'C', 'D'], true)) $fSkupina = '';
$fOeeMin  = get('oee_min','');
$export   = get('export') === 'csv';
$print    = get('print')  === '1';

$where  = ['z.datum BETWEEN :od AND :do'];
$params = [':od' => $fOd, ':do' => $fDo];
if ($fTyp)   { $where[] = 'z.typ=:typ';       $params[':typ']    = $fTyp; }
if ($fSmena) { $where[] = 'z.smena=:smena';   $params[':smena']  = $fSmena; }
if ($fPrac)  { $where[] = 'z.pracovisite_id=:prac'; $params[':prac'] = $fPrac; }
if ($fJmeno !== '') {
    $jmenoTerms = preg_split('/\s+/u', $fJmeno, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($jmenoTerms) {
        $jmenoWhere = [];
        foreach ($jmenoTerms as $i => $term) {
            $pJmeno = ':jmeno'.$i.'_jmeno';
            $pPozice = ':jmeno'.$i.'_pozice';
            $pZapsal = ':jmeno'.$i.'_zapsal';
            $jmenoWhere[] = '(z.jmeno LIKE '.$pJmeno.' OR z.pracovni_pozice LIKE '.$pPozice.' OR z.zapsal LIKE '.$pZapsal.')';
            $likeTerm = '%'.$term.'%';
            $params[$pJmeno] = $likeTerm;
            $params[$pPozice] = $likeTerm;
            $params[$pZapsal] = $likeTerm;
        }
        $where[] = '('.implode(' AND ', $jmenoWhere).')';
    }
}
if ($fRok)   { $where[] = 'z.rok=:rok';        $params[':rok']   = $fRok; }
if ($fCw)    { $where[] = 'z.cw=:cw';          $params[':cw']    = $fCw; }
if ($fSkupina !== '') { $where[] = 'z.skupina=:skupina'; $params[':skupina'] = $fSkupina; }
if ($fOeeMin!=='') { $where[] = 'z.oee_procent >= :oeemin'; $params[':oeemin'] = (float)$fOeeMin/100; }
$wSQL = 'WHERE '.implode(' AND ', $where);

$downtimeJoin = "LEFT JOIN (
    SELECT zapis_id,
        SUM(CASE WHEN kategorie='plan' THEN minuty ELSE 0 END) AS dt_plan,
        SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost') THEN minuty ELSE 0 END) AS dt_neplan,
        SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) zp ON zp.zapis_id=z.id";

$sql = "SELECT z.id,z.typ,z.datum,z.cw,z.rok,z.smena,z.skupina,z.jmeno,z.pracovni_pozice,
               z.vyrobeno_ks,z.nok_ks,COALESCE(z.nok_operator_ks,z.nok_ks) AS nok_operator_ks,z.vyrobeno_dobre_ks,z.k_posouzeni,
               z.cisty_cas_min,z.oee_procent,COALESCE(zp.dt_total,0) AS prostoj_celkem_min,
               z.kod_pracovisite,z.nazev_pracovisite,z.nazev_dilu,
               z.zakaznik,z.projekt,z.zapsal
        FROM zapisy z
        $downtimeJoin
        $wSQL
        ORDER BY z.datum DESC, z.id DESC LIMIT 2000";

$rows = db_all($sql, $params);

// Pomocný SQL výraz pro vážené OEE přes vyfiltrované záznamy
$oeeSqlExpr = "LEAST(1.0, GREATEST(0.0,
    -- Dostupnost (A)
    (
        SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0) - COALESCE(zp.dt_neplan, 0)))
        / NULLIF(SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0))), 0)
    )
    *
    -- Výkonnost (P)
    (
        SUM(z.vyrobeno_ks)
        / NULLIF(SUM(
            CASE 
                WHEN z.cycle_time_min > 0 THEN GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0) - COALESCE(zp.dt_neplan, 0)) / z.cycle_time_min
                WHEN z.norma_ks > 0 THEN GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0) - COALESCE(zp.dt_neplan, 0)) * z.norma_ks
                ELSE 0
            END
        ), 0)
    )
    *
    -- Kvalita (Q)
    (
        SUM(GREATEST(0, z.vyrobeno_ks - COALESCE(z.nok_operator_ks, z.nok_ks)))
        / NULLIF(SUM(z.vyrobeno_ks), 0)
    )
)) * 100";

// ── Statistiky ───────────────────────────────────────────────
$stat = db_row("SELECT COUNT(*) cnt, SUM(z.vyrobeno_ks) vyr, SUM(z.nok_ks) nok,
    SUM(COALESCE(z.nok_operator_ks, z.nok_ks)) nok_operator,
    ROUND(COALESCE($oeeSqlExpr, 0), 2) AS oee_avg,
    SUM(COALESCE(zp.dt_total,0)) prostoj
    FROM zapisy z
    $downtimeJoin
    $wSQL", $params);

// ── CSV export ───────────────────────────────────────────────
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dvd_zaznamy_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r) fputcsv($out, $r, ';');
    }
    fclose($out); exit;
}

$pracovisteList = getPracovisteList();
$selectedPracovisteName = '';
if ($fPrac !== '') {
    foreach ($pracovisteList as $p) {
        if ((string)$p['id'] === (string)$fPrac) {
            $selectedPracovisteName = '[' . $p['cislo_id'] . '] ' . $p['nazev_dilu'];
            break;
        }
    }
}

$typLabels = [
    'serie' => t('type_serie'),
    'filtry' => t('type_filtry'),
    'vstrikovna' => t('type_vstrikovna'),
];
$selectedTypLabel = $fTyp !== '' ? ($typLabels[$fTyp] ?? strtoupper($fTyp)) : t('all');

$workerSuggest = db_all("SELECT DISTINCT z.jmeno
                         FROM zapisy z
                         WHERE z.jmeno IS NOT NULL AND z.jmeno <> ''
                         ORDER BY z.jmeno ASC
                         LIMIT 400");

$dvdSyncState = [];
$dvdSyncAt = null;
$dvdSyncStateFile = APP_ROOT . '/uploads/tmp/sync_dvd_state.json';
if (is_file($dvdSyncStateFile)) {
    $dvdSyncState = (array)json_decode((string)file_get_contents($dvdSyncStateFile), true);
    $dvdSyncAt = (string)($dvdSyncState['last_success_at'] ?? $dvdSyncState['last_run_at'] ?? '');
}
$dvdSyncLabel = $dvdSyncAt ? h(date('d.m.Y H:i', strtotime($dvdSyncAt))) : '—';

require APP_ROOT . '/includes/layout.php';
?>

<style>
.print-only {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 7mm 8mm;
    }

    /* Reset všech nadřazených obalů z layout.php (zruší flexbox/grid a zákaz zalamování, který odsouval tabulku na 2. stranu) */
    html,
    body,
    html body *:has(.tbl),
    html body .content,
    html body .tbl-wrap,
    html body .overflow-x {
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
        page-break-before: auto !important;
        page-break-after: auto !important;
        page-break-inside: auto !important;
        break-before: auto !important;
        break-after: auto !important;
        break-inside: auto !important;
    }

    html, body {
        font-size: 8pt !important;
        line-height: 1.2 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Skrytí navigace, filtrů, horních kartiček, patičky a grafických proužků OEE */
    html body .no-print,
    html body .topbar,
    html body .sidebar,
    html body #sidebar,
    html body nav,
    html body aside,
    html body .filter-bar,
    html body .tbl-footer,
    html body .oee-bar-wrap {
        display: none !important;
    }

    /* Tisková hlavička přímo nad tabulkou na 1. straně */
    html body .print-only.print-header {
        display: block !important;
        border-bottom: 2px solid #000 !important;
        padding-bottom: 4px !important;
        margin-bottom: 6px !important;
        page-break-after: avoid !important;
        break-after: avoid !important;
    }

    .print-header-table {
        width: 100% !important;
        border-collapse: collapse !important;
        border: none !important;
    }

    .print-header-table td {
        border: none !important;
        padding: 0 !important;
        vertical-align: bottom !important;
        color: #000 !important;
        background: transparent !important;
    }

    .print-header h1 {
        font-size: 12.5pt !important;
        font-weight: 700 !important;
        margin: 0 !important;
        color: #000 !important;
    }

    .print-header-meta {
        font-size: 8pt !important;
        color: #333 !important;
        text-align: right !important;
    }

    /* Kompaktní tabulka přes celou šířku papíru (14 tištěných sloupců) */
    html body table.tbl {
        display: table !important;
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 7.5pt !important;
        color: #000 !important;
        page-break-before: auto !important;
        page-break-inside: auto !important;
        break-before: auto !important;
        break-inside: auto !important;
    }

    html body .tbl thead {
        display: table-header-group !important;
    }

    html body .tbl tbody {
        display: table-row-group !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
    }

    html body .tbl tr {
        display: table-row !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    html body .tbl th,
    html body .tbl td {
        display: table-cell !important;
        border: 1px solid #bbb !important;
        padding: 2.5px 3.5px !important;
        color: #000 !important;
        background: transparent !important;
        vertical-align: middle !important;
    }

    /* Sloupec s akcemi zůstane skrytý i po table-cell resetu */
    html body .tbl th.no-print,
    html body .tbl td.no-print {
        display: none !important;
    }

    html body .tbl thead th {
        background-color: #eaeaea !important;
        font-weight: 700 !important;
        text-align: center !important;
        white-space: normal !important;
        line-height: 1.1 !important;
        font-size: 7pt !important;
    }

    /* Podřádky (název pracoviště, zákazník/projekt) v buňkách */
    html body .tbl td small.text-muted-dvd {
        color: #444 !important;
        font-size: 6.5pt !important;
        line-height: 1.05 !important;
        display: inline-block !important;
    }

    /* Zjednodušení štítků typu (SERIE/FILTRY/VSTRIKOVNA) pro tisk */
    html body .tbl td [class^="badge-"],
    html body .tbl td [class*=" badge-"] {
        background: transparent !important;
        color: #000 !important;
        border: 1px solid #666 !important;
        padding: 1px 3px !important;
        font-size: 6.5pt !important;
        font-weight: 700 !important;
        border-radius: 2px !important;
    }

    html body .tbl td.num-col,
    html body .tbl th.num-col {
        text-align: right !important;
        white-space: nowrap !important;
    }

    html body .tbl td.center-col,
    html body .tbl th.center-col {
        text-align: center !important;
        white-space: nowrap !important;
    }

    html body .tbl tbody tr:nth-child(even) td {
        background-color: #f7f7f7 !important;
    }
}
</style>

<div class="topbar no-print">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('records_title') ?></span>
        <?php if (isSuperAdmin()): ?>
        <a href="<?= APP_URL ?>/scripts/sync_dvd_import.php?force=1" title="<?= t('sync_run_now') ?>" class="topbar-meta no-print" style="text-decoration:none"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $dvdSyncLabel ?></strong></a>
        <?php else: ?>
        <span class="topbar-meta"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $dvdSyncLabel ?></strong></span>
        <?php endif; ?>
        <span class="badge-<?= $fTyp ?: 'serie' ?>" style="display:<?= $fTyp?'':'none' ?>"><?= strtoupper(h($fTyp)) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-download me-1"></i><?= t('export_csv') ?>
        </a>
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-printer me-1"></i><?= t('print') ?>
        </button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

<!-- Tisková hlavička (zobrazí se pouze při tisku přímo nad tabulkou na 1. straně) -->
<div class="print-only print-header">
    <table class="print-header-table">
        <tr>
            <td>
                <h1><?= h(t('records_title')) ?> (<?= date('d.m.Y', strtotime($fOd)) ?> – <?= date('d.m.Y', strtotime($fDo)) ?>)</h1>
                <div style="font-size:7.5pt;color:#444;margin-top:2px">
                    <?= h(t('typ')) ?>: <strong><?= h($selectedTypLabel) ?></strong>
                    | <?= h(t('smena')) ?>: <strong><?= h($fSmena !== '' ? $fSmena : t('all')) ?></strong>
                    | <?= h(t('group')) ?>: <strong><?= h($fSkupina !== '' ? $fSkupina : t('all')) ?></strong>
                    <?php if ($selectedPracovisteName !== ''): ?> | <?= h(t('pracovisite')) ?>: <strong><?= h($selectedPracovisteName) ?></strong><?php endif; ?>
                    <?php if ($fJmeno !== ''): ?> | <?= h(t('pracovnik')) ?>: <strong><?= h($fJmeno) ?></strong><?php endif; ?>
                    <?php if ($fOeeMin !== ''): ?> | OEE &ge; <strong><?= h($fOeeMin) ?>%</strong><?php endif; ?>
                </div>
            </td>
            <td class="print-header-meta">
                Počet záznamů: <strong><?= number_format(count($rows)) ?></strong><br>
                Vytištěno: <?= date('d.m.Y H:i') ?>
            </td>
        </tr>
    </table>
</div>

<!-- Statistiky -->
<div class="row g-3 mb-4 no-print">
    <?php $stats = [
        [$stat['cnt']??0, t('record_id').' / '.t('records_btn'), 'bi-file-earmark-text'],
        [number_format((int)($stat['vyr']??0)), t('produced_ks'), 'bi-boxes'],
        [number_format((int)($stat['nok']??0)), t('nok_total'), 'bi-x-octagon'],
        [number_format((int)($stat['nok_operator']??0)), t('summary_operator_nok'), 'bi-person-x'],
        [$stat['oee_avg'] ? round($stat['oee_avg'],1).'%' : '—', t('avg_oee'), 'bi-speedometer2'],
        [number_format((int)($stat['prostoj']??0)).' min', t('downtime_min'), 'bi-pause-circle'],
    ]; foreach ($stats as [$val,$lbl,$ico]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><div class="stat-val"><?= $val ?></div><div class="stat-label"><i class="bi <?= $ico ?> me-1"></i><?= $lbl ?></div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filtry -->
<form method="get" class="filter-bar no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
        <div class="col-6 col-md-1"><label class="form-label-dvd"><?= t('typ') ?></label>
            <select name="typ" class="form-select">
                <option value=""><?= t('all') ?></option>
                <option value="serie" <?=$fTyp==='serie'?'selected':''?>><?= t('type_serie') ?></option>
                <option value="filtry" <?=$fTyp==='filtry'?'selected':''?>><?= t('type_filtry') ?></option>
                <option value="vstrikovna" <?=$fTyp==='vstrikovna'?'selected':''?>><?= t('type_vstrikovna') ?></option>
            </select></div>
        <div class="col-6 col-md-1"><label class="form-label-dvd"><?= t('smena') ?></label>
            <select name="smena" class="form-select">
                <option value=""><?= t('all') ?></option>
                <?php foreach(['R8','O8','N8','R12','N12'] as $s): ?><option <?=$fSmena===$s?'selected':''?>><?= $s ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-4 col-md-1"><label class="form-label-dvd"><?= t('group') ?></label><select name="skupina" class="form-select">
            <option value=""><?= t('all') ?></option>
            <?php foreach (['A', 'B', 'C', 'D'] as $skupina): ?><option value="<?= $skupina ?>" <?= $fSkupina === $skupina ? 'selected' : '' ?>><?= $skupina ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-12 col-md-3"><label class="form-label-dvd"><?= t('pracovisite') ?></label>
            <select name="pracovisite_id" class="form-select">
                <option value=""><?= t('all') ?></option>
                <?php foreach ($pracovisteList as $p): ?><option value="<?= $p['id'] ?>" <?=$fPrac==$p['id']?'selected':''?>>[<?= $p['cislo_id'] ?>] <?= h($p['nazev_dilu']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('pracovnik') ?></label><input type="search" name="jmeno" class="form-control" value="<?= h($fJmeno) ?>" placeholder="<?= t('worker_namep') ?>" list="worker-list" autocomplete="off"></div>
        <!-- <div class="col-4 col-md-1"><label class="form-label-dvd"><?= t('cw') ?></label><input type="number" name="cw" class="form-control" min="1" max="53" value="<?= h($fCw) ?>" placeholder="—"></div> -->
        <!--<div class="col-4 col-md-1"><label class="form-label-dvd"><?= t('year') ?></label><input type="number" name="rok" class="form-control" min="2020" value="<?= h($fRok) ?>" placeholder="—"></div> -->
        <div class="col-4 col-md-1"><label class="form-label-dvd"><?= t('oee_min_filter') ?></label><input type="number" name="oee_min" class="form-control" min="0" max="100" value="<?= h($fOeeMin) ?>" placeholder="—"></div>
        <div class="col-auto d-flex gap-2 mt-1">
            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
            <a href="zaznamy.php?clear_filters=1" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
        </div>
    </div>
</form>

<datalist id="worker-list">
    <?php foreach ($workerSuggest as $ws): if (!empty($ws['jmeno'])): ?>
    <option value="<?= h($ws['jmeno']) ?>"></option>
    <?php endif; endforeach; ?>
</datalist>

<!-- Tabulka -->
<div class="tbl-wrap">
    <div class="overflow-x">
    <table class="tbl">
        <thead><tr>
            <th class="center-col">ID</th>
            <th class="center-col"><?= t('typ') ?></th>
            <th class="center-col"><?= t('datum') ?></th>
            <th><?= t('pracovisite') ?></th>
            <th><?= t('nazev_dilu') ?></th>
            <th class="center-col"><?= t('smena') ?></th>
            <th class="center-col"><?= t('group') ?></th>
            <th><?= t('pracovnik') ?></th>
            <th class="num-col"><?= t('vyrobeno') ?></th>
            <th class="num-col"><?= t('nok_total') ?></th>
            <th class="num-col"><?= t('summary_operator_nok') ?></th>
            <th class="num-col"><?= t('cisty_cas') ?></th>
            <th class="num-col"><?= t('column_oee') ?></th>
            <th class="num-col"><?= t('prostoje') ?></th>
            <th class="no-print"><?= t('actions') ?></th>
        </tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="15" class="text-center py-5 text-muted-dvd"><?= t('no_data') ?></td></tr>
        <?php else: foreach ($rows as $r):
            $oee = $r['oee_procent'] !== null ? round($r['oee_procent']*100,1) : null;
            $oc  = $oee!==null ? ($oee>=85?'good':($oee>=70?'warn':'bad')) : '';
        ?>
        <tr data-href="<?= APP_URL ?>/admin/zapis_detail.php?id=<?= $r['id'] ?>">
            <td class="mono text-accent center-col">#<?= $r['id'] ?></td>
            <td class="center-col"><span class="badge-<?= $r['typ'] ?>"><?= strtoupper($r['typ']) ?></span></td>
            <td class="mono center-col"><?= date('d.m.Y',strtotime($r['datum'])) ?></td>
            
            <td><?= h($r['kod_pracovisite']??'') ?><br><small class="text-muted-dvd"><?= h($r['nazev_pracovisite']??'') ?></small></td>
            <td><?= h($r['nazev_dilu']??'') ?><br><small class="text-muted-dvd"><?= h(($r['zakaznik']??'').' / '.($r['projekt']??'')) ?></small></td>
            <td class="mono center-col"><?= h($r['smena']) ?></td>
            <td class="mono text-center center-col"><?= h($r['skupina'] ?: '—') ?></td>
            <td><?= h($r['jmeno']??'') ?></td>
            <td class="mono text-accent num-col"><?= number_format((int)$r['vyrobeno_ks']) ?></td>
            <td class="mono num-col <?= $r['nok_ks']>0?'text-accent2':'' ?>"><?= number_format((int)$r['nok_ks']) ?></td>
            <td class="mono num-col <?= $r['nok_operator_ks']>0?'text-accent2':'' ?>"><?= number_format((int)$r['nok_operator_ks']) ?></td>
            <td class="mono num-col"><?= $r['cisty_cas_min']??'—' ?></td>
            <td class="num-col">
                <?php if ($oee!==null): ?>
                <span class="mono" style="font-size:.78rem"><?= $oee ?>%</span>
                <div class="oee-bar-wrap"><div class="oee-bar-fill oee-<?= $oc ?>" style="width:<?= min(100,$oee) ?>%"></div></div>
                <?php else: ?><span class="text-muted-dvd">—</span><?php endif; ?>
            </td>
            <td class="mono num-col"><?= (int)$r['prostoj_celkem_min'] ?> <?= t('unit_minutes') ?></td>
            <td class="no-print">
                <a href="<?= APP_URL ?>/admin/zapis_detail.php?id=<?= $r['id'] ?>" class="btn-icon" title="<?= t('detail') ?>"><i class="bi bi-eye"></i></a>
                <?php if (!hasOnlyRole('read')): ?>
                <a href="<?= APP_URL ?>/admin/zapis_edit.php?id=<?= $r['id'] ?>" class="btn-icon" title="<?= t('edit') ?>"><i class="bi bi-pencil"></i></a>
                <a href="<?= APP_URL ?>/admin/zapis_delete.php?id=<?= $r['id'] ?>"
                   class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>" title="<?= t('delete') ?>"><i class="bi bi-trash3"></i></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <div class="tbl-footer no-print">
        <span><?= t('records_title') ?>: <?= count($rows) ?></span>
        <span class="text-muted-dvd"><?= t('max_rows_hint') ?></span>
    </div>
</div>

</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>