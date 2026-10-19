<?php
// admin/vysledovka.php - Měsíční výsledovka operátorů
require_once dirname(__DIR__) . '/includes/config.php';
requireSuperAdmin();

$pageTitle = 'Výsledovka';
$activePage = 'vysledovka';

$fMonth = (string)get('mesic', date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fMonth)) {
    $fMonth = date('Y-m');
}
$fOd = $fMonth . '-01';
$fDo = date('Y-m-t', strtotime($fOd));
$fOperator = trim((string)get('operator_id', ''));
$fSkupina = strtoupper(trim((string)get('skupina', '')));
if (!in_array($fSkupina, ['A', 'B', 'C', 'D'], true)) $fSkupina = '';
$fOeeLimit = (string)get('oee_limit', 'all');
if (!in_array($fOeeLimit, ['all', '75', '85', '95'], true)) {
    $fOeeLimit = 'all';
}
$fPerformanceLimit = (string)get('performance_limit', 'all');
if (!in_array($fPerformanceLimit, ['all', '75', '85', '95'], true)) {
    $fPerformanceLimit = 'all';
}
$sortColumns = [
    'jmeno' => 'jmeno',
    'zaznamu' => 'zaznamu',
    'oee' => 'oee',
    'vykon' => 'vykon',
    'vyrobeno_ks' => 'vyrobeno_ks',
    'nok_ks' => 'nok_ks',
    'scrap' => 'scrap',
    'prostoj_celkem_min' => 'prostoj_celkem_min',
];
$fSort = (string)get('sort', 'oee');
if (!isset($sortColumns[$fSort])) {
    $fSort = 'oee';
}
$fDir = strtolower((string)get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
$export = get('export') === 'csv';

$workerIdJoin = "LEFT JOIN pracovnici p_interni ON CAST(p_interni.interni_id AS UNSIGNED) = zp.pracovnik_id
LEFT JOIN pracovnici p_id ON p_id.id = CAST(zp.jmeno AS UNSIGNED)";
$workerNameJoin = "LEFT JOIN (
    SELECT MIN(id) AS id, LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci AS jmeno_key, MIN(interni_id) AS interni_id, MIN(jmeno) AS jmeno
    FROM pracovnici
    WHERE COALESCE(TRIM(jmeno), '') <> ''
    GROUP BY LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci
) pn ON pn.jmeno_key = LOWER(TRIM(zp.jmeno)) COLLATE utf8mb4_unicode_ci";
$downtimeJoin = "LEFT JOIN (
    SELECT zapis_id,
        SUM(CASE WHEN kategorie = 'plan' THEN minuty ELSE 0 END) AS dt_plan,
        SUM(CASE WHEN kategorie IN ('tech', 'vyr', 'kval', 'log', 'ost') THEN minuty ELSE 0 END) AS dt_neplan,
        SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) dt ON dt.zapis_id = z.id";

// --- BEZPEČNÝ VÝPOČET OEE S PŮVODNÍ LOGIKOU A STROPY ---

$sqlNetTime = "GREATEST(0, COALESCE(z.pracovni_doba_min, 0) - COALESCE(z.pauza_min, 0))";
$sqlPlanTime = "GREATEST(0, $sqlNetTime - COALESCE(dt.dt_plan, 0))";
$sqlOpTime = "GREATEST(0, $sqlNetTime - COALESCE(dt.dt_plan, 0) - COALESCE(dt.dt_neplan, 0))";

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
$sqlQ = "LEAST(1.0, GREATEST(0.0, SUM(GREATEST(0, z.vyrobeno_ks - COALESCE(z.nok_operator_ks, z.nok_ks))) / NULLIF(SUM(z.vyrobeno_ks), 0)))";

// Celkové OEE s maximem 100%
$oeeSqlExpr = "LEAST(1.0, ($sqlA * $sqlP * $sqlQ)) * 100";
$performanceSqlExpr = "$sqlP";

$params = [':od' => $fOd, ':do' => $fDo];
$where = ['z.datum BETWEEN :od AND :do'];
if ($fOperator !== '') {
    $where[] = '(p_interni.interni_id = :operator_interni_id OR p_id.interni_id = :operator_primary_id OR pn.interni_id = :operator_name_id)';
    $params[':operator_interni_id'] = $fOperator;
    $params[':operator_primary_id'] = $fOperator;
    $params[':operator_name_id'] = $fOperator;
}
if ($fSkupina !== '') {
    $where[] = 'z.skupina = :skupina';
    $params[':skupina'] = $fSkupina;
}
$having = '';
if ($fOeeLimit !== 'all' || $fPerformanceLimit !== 'all') {
    $havingConditions = [];
    if ($fOeeLimit !== 'all') {
        $havingConditions[] = "COALESCE($oeeSqlExpr, 0) < :oee_limit";
        $params[':oee_limit'] = (int)$fOeeLimit;
    }
    if ($fPerformanceLimit !== 'all') {
        $havingConditions[] = "COALESCE($performanceSqlExpr, 0) * 100 < :performance_limit";
        $params[':performance_limit'] = (int)$fPerformanceLimit;
    }
    $having = 'HAVING ' . implode(' AND ', $havingConditions);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$orderSql = $sortColumns[$fSort] . ' ' . strtoupper($fDir) . ', jmeno ASC';

$rows = db_all("SELECT
    COALESCE(CAST(p_id.id AS CHAR), CAST(p_interni.id AS CHAR), CAST(pn.id AS CHAR), CONCAT('manual:', LOWER(TRIM(zp.jmeno)))) AS worker_key,
    COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno) AS jmeno,
    MAX(COALESCE(p_id.interni_id, p_interni.interni_id, pn.interni_id, '')) AS interni_id,
    GROUP_CONCAT(DISTINCT NULLIF(z.skupina, '') ORDER BY z.skupina SEPARATOR ', ') AS skupiny,
    COUNT(DISTINCT z.id) AS zaznamu,
    GROUP_CONCAT(DISTINCT z.id) AS z_ids,
    SUM(z.vyrobeno_ks) AS vyrobeno_ks,
    SUM(z.nok_ks) AS nok_ks,
    SUM(COALESCE(z.nok_operator_ks, z.nok_ks)) AS nok_operator_ks,
    SUM(COALESCE(dt.dt_total, 0)) AS prostoj_celkem_min,
    ROUND(COALESCE($oeeSqlExpr, 0), 2) AS oee,
    ROUND(COALESCE($performanceSqlExpr, 0) * 100, 2) AS vykon,
    ROUND(COALESCE(SUM(COALESCE(z.nok_operator_ks, z.nok_ks)) / NULLIF(SUM(z.vyrobeno_ks), 0) * 100, 0), 2) AS scrap
    FROM zapis_pracovnici zp
    JOIN zapisy z ON z.id = zp.zapis_id
    $workerIdJoin
    $workerNameJoin
    $downtimeJoin
    $whereSql
    GROUP BY worker_key, COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno)
    $having
    ORDER BY $orderSql", $params);

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dvd_vysledovka_' . $fMonth . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Operátor', 'Interní ID', 'Záznamy', 'OEE (%)', 'Celkový výkon (%)', 'Vyrobeno (ks)', 'NOK celkem (ks)', 'NOK operátora (ks)', 'Scrap operátora (%)', 'Celkové prostoje (min)'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [$row['jmeno'], $row['interni_id'], $row['zaznamu'], $row['oee'], $row['vykon'], $row['vyrobeno_ks'], $row['nok_ks'], $row['nok_operator_ks'], $row['scrap'], $row['prostoj_celkem_min']], ';');
    }
    fclose($out);
    exit;
}

// --- BEZPEČNÝ SOUHRN HORNÍCH KARTIČEK (filtruje sdílené směny) ---
$validZapisIds = [];
foreach ($rows as $row) {
    if (!empty($row['z_ids'])) {
        foreach (explode(',', $row['z_ids']) as $id) {
            $validZapisIds[$id] = true;
        }
    }
}
$validZapisIds = array_keys($validZapisIds);

if (empty($validZapisIds)) {
    $summary = ['operators' => 0, 'produced' => 0, 'nok' => 0, 'nok_operator' => 0, 'downtime' => 0];
} else {
    $inClause = implode(',', array_map('intval', $validZapisIds));
    $summaryQuery = db_row("SELECT 
        SUM(z.vyrobeno_ks) AS produced, 
        SUM(z.nok_ks) AS nok, 
        SUM(COALESCE(z.nok_operator_ks, z.nok_ks)) AS nok_operator,
        SUM(COALESCE(dt.dt_total, 0)) AS downtime
        FROM zapisy z
        LEFT JOIN (
            SELECT zapis_id, SUM(minuty) AS dt_total
            FROM zapisy_prostoje
            GROUP BY zapis_id
        ) dt ON dt.zapis_id = z.id
        WHERE z.id IN ($inClause)");
        
    $summary = [
        'operators' => count($rows),
        'produced' => (int)($summaryQuery['produced'] ?? 0),
        'nok' => (int)($summaryQuery['nok'] ?? 0),
        'nok_operator' => (int)($summaryQuery['nok_operator'] ?? 0),
        'downtime' => (int)($summaryQuery['downtime'] ?? 0),
    ];
}

$pracovniciList = getPracovniciList();
$operatorSuggestions = array_values(array_filter(array_map(static function (array $pracovnik): ?array {
    $interniId = trim((string)($pracovnik['interni_id'] ?? ''));
    $jmeno = trim((string)($pracovnik['jmeno'] ?? ''));
    if ($interniId === '' ||$jmeno === '') {
        return null;
    }
    return [
        'id' => $interniId,
        'label' => $jmeno . ' (' .$interniId . ')',
        'search' => mb_strtolower($jmeno . ' ' .$interniId, 'UTF-8'),
    ];
}, $pracovniciList)));$selectedOperator = null;
foreach ($operatorSuggestions as$operatorSuggestion) {
    if ($operatorSuggestion['id'] ===$fOperator) {
        $selectedOperator =$operatorSuggestion;
        break;
    }
}

require APP_ROOT . '/includes/layout.php';
?>

<style>
.print-only {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 8mm 10mm;
    }

    /* Reset všech nadřazených kontejnerů z layout.php (zruší flexbox/grid a zákaz zalamování, který odsouval tabulku na 2. stranu) */
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
        font-size: 9pt !important;
        line-height: 1.25 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Skrytí navigace, filtrů, horních kartiček a ovládacích prvků */
    html body .no-print,
    html body .topbar,
    html body .sidebar,
    html body #sidebar,
    html body nav,
    html body aside,
    html body .filter-bar,
    html body .oee-bar-wrap,
    html body .tbl thead th i.bi {
        display: none !important;
    }

    /* Tisková hlavička bez flexboxu, aby neodstrkovala tabulku */
    html body .print-only.print-header {
        display: block !important;
        border-bottom: 2px solid #000 !important;
        padding-bottom: 5px !important;
        margin-bottom: 8px !important;
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
        font-size: 13pt !important;
        font-weight: 700 !important;
        margin: 0 !important;
        color: #000 !important;
    }

    .print-header-meta {
        font-size: 8.5pt !important;
        color: #333 !important;
        text-align: right !important;
    }

    /* Kompaktní tabulka přes celou šířku papíru */
    html body table.tbl {
        display: table !important;
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 8.5pt !important;
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
        padding: 4px 5px !important;
        color: #000 !important;
        background: transparent !important;
        vertical-align: middle !important;
    }

    html body .tbl thead th {
        background-color: #eaeaea !important;
        font-weight: 700 !important;
        text-align: center !important;
        white-space: normal !important;
        line-height: 1.15 !important;
        font-size: 8pt !important;
    }

    html body .tbl thead th:first-child,
    html body .tbl tbody td:first-child {
        text-align: left !important;
        font-weight: 600 !important;
    }

    html body .tbl thead th a {
        color: #000 !important;
        text-decoration: none !important;
    }

    html body .tbl td.num-col,
    html body .tbl th.num-col {
        text-align: right !important;
        white-space: nowrap !important;
    }

    html body .tbl td.center-col,
    html body .tbl th.center-col {
        text-align: center !important;
    }

    html body .tbl tbody tr:nth-child(even) td {
        background-color: #f7f7f7 !important;
    }
}
</style>

<div class="topbar no-print">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-bar-chart-line me-2"></i>Výsledovka</span>
        <span class="topbar-meta">Měsíční výkon operátorů</span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?<?= http_build_query(['mesic' => $fMonth, 'operator_id' => $fOperator, 'skupina' =>$fSkupina, 'oee_limit' => $fOeeLimit, 'performance_limit' =>$fPerformanceLimit, 'sort' => $fSort, 'dir' =>$fDir, 'export' => 'csv']) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-download me-1"></i><?= t('export_csv') ?></a>
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
    </div>
</div>

<div class="content">
<!-- Tisková hlavička (zobrazí se pouze při tisku přímo nad tabulkou na 1. straně) -->
<div class="print-only print-header">
    <table class="print-header-table">
        <tr>
            <td>
                <h1>Měsíční výsledovka operátorů – <?= h($fMonth) ?></h1>
                <div style="font-size:8.5pt;color:#444;margin-top:2px">
                    Skupina: <strong><?= h($fSkupina !== '' ?$fSkupina : 'Vše') ?></strong>
                    <?php if ($selectedOperator): ?> | Operátor: <strong><?= h($selectedOperator['label']) ?></strong><?php endif; ?>
                    <?php if ($fOeeLimit !== 'all'): ?> | OEE: <strong>&lt; <?= h($fOeeLimit) ?>%</strong><?php endif; ?>
                    <?php if ($fPerformanceLimit !== 'all'): ?> | Výkon: <strong>&lt; <?= h($fPerformanceLimit) ?>%</strong><?php endif; ?>
                </div>
            </td>
            <td class="print-header-meta">
                Počet operátorů: <strong><?= number_format($summary['operators']) ?></strong><br>
                Vytištěno: <?= date('d.m.Y H:i') ?>
            </td>
        </tr>
    </table>
</div>

<form method="get" class="filter-bar no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label-dvd">Měsíc</label><input type="month" name="mesic" class="form-control" value="<?= h($fMonth) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label-dvd">Operátor</label><div style="position:relative">
            <input id="operator-search" type="search" class="form-control" value="<?= h($selectedOperator['label'] ?? '') ?>" placeholder="Hledat jméno nebo ID" autocomplete="off">
            <input id="operator-id" type="hidden" name="operator_id" value="<?= h($fOperator) ?>">
            <div id="operator-dropdown" style="display:none;position:absolute;z-index:50;top:calc(100% + 2px);left:0;right:0;max-height:240px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 10px 25px rgba(0,0,0,.2)"></div>
        </div></div>
        <div class="col-6 col-md-1"><label class="form-label-dvd"><?= t('group') ?></label><select name="skupina" class="form-select">
            <option value=""><?= t('all') ?></option>
            <?php foreach (['A', 'B', 'C', 'D'] as $skupina): ?><option value="<?= $skupina ?>" <?= $fSkupina === $skupina ? 'selected' : '' ?>><?= $skupina ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd">OEE</label><select name="oee_limit" class="form-select">
            <option value="all" <?= $fOeeLimit === 'all' ? 'selected' : '' ?>>Vše</option>
            <option value="75" <?= $fOeeLimit === '75' ? 'selected' : '' ?>>&lt; 75%</option>
            <option value="85" <?= $fOeeLimit === '85' ? 'selected' : '' ?>>&lt; 85%</option>
            <option value="95" <?= $fOeeLimit === '95' ? 'selected' : '' ?>>&lt; 95%</option>
        </select></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd">Výkon</label><select name="performance_limit" class="form-select">
            <option value="all" <?= $fPerformanceLimit === 'all' ? 'selected' : '' ?>>Vše</option>
            <option value="75" <?= $fPerformanceLimit === '75' ? 'selected' : '' ?>>&lt; 75%</option>
            <option value="85" <?= $fPerformanceLimit === '85' ? 'selected' : '' ?>>&lt; 85%</option>
            <option value="95" <?= $fPerformanceLimit === '95' ? 'selected' : '' ?>>&lt; 95%</option>
        </select></div>
        <div class="col-auto"><button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button></div>
        <div class="col-auto"><a href="vysledovka.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a></div>
    </div>
</form>

<div class="row g-3 mb-4 no-print">
    <?php foreach ([
        [number_format($summary['operators']), 'Operátorů', 'bi-people'],
        [number_format($summary['produced']), t('produced_ks'), 'bi-boxes'],
        [number_format($summary['nok']), t('nok_total'), 'bi-x-octagon'],
        [number_format($summary['nok_operator']), t('summary_operator_nok'), 'bi-person-x'],
        [number_format($summary['downtime']) . ' ' . t('unit_minutes'), 'Celkové prostoje', 'bi-pause-circle'],
    ] as [$value, $label,$icon]): ?>
    <div class="col-6 col-md"><div class="stat-card"><div class="stat-val"><?= $value ?></div><div class="stat-label"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="tbl-wrap">
    <div class="overflow-x">
        <table class="tbl">
            <?php
            $sortLink = static function (string $column) use ($fMonth, $fOperator,$fSkupina, $fOeeLimit,$fPerformanceLimit, $fSort,$fDir): string {
                $nextDir =$fSort === $column &&$fDir === 'asc' ? 'desc' : 'asc';
                return '?' . http_build_query(['mesic' => $fMonth, 'operator_id' => $fOperator, 'skupina' =>$fSkupina, 'oee_limit' => $fOeeLimit, 'performance_limit' =>$fPerformanceLimit, 'sort' => $column, 'dir' =>$nextDir]);
            };
            $sortIcon = static function (string$column) use ($fSort,$fDir): string {
                return $fSort !== $column ? 'bi-arrow-down-up' : ($fDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
            };
            ?>
            <thead><tr>
                <th><a href="<?= $sortLink('jmeno') ?>" class="text-reset text-decoration-none">Operátor <i class="bi <?= $sortIcon('jmeno') ?>"></i></a></th>
                <th class="center-col">ID</th>
                <th class="center-col"><?= t('group') ?></th>
                <th class="num-col"><a href="<?= $sortLink('zaznamu') ?>" class="text-reset text-decoration-none"><?= t('records_btn') ?> <i class="bi <?= $sortIcon('zaznamu') ?>"></i></a></th>
                <th class="num-col"><a href="<?= $sortLink('oee') ?>" class="text-reset text-decoration-none">OEE <i class="bi <?= $sortIcon('oee') ?>"></i></a></th>
                <th class="num-col"><a href="<?= $sortLink('vykon') ?>" class="text-reset text-decoration-none">Celkový výkon <i class="bi <?= $sortIcon('vykon') ?>"></i></a></th>
                <th class="num-col"><a href="<?= $sortLink('vyrobeno_ks') ?>" class="text-reset text-decoration-none"><?= t('produced_ks') ?> <i class="bi <?= $sortIcon('vyrobeno_ks') ?>"></i></a></th>
                <th class="num-col"><?= t('nok_total') ?></th>
                <th class="num-col"><?= t('summary_operator_nok') ?></th>
                <th class="num-col"><a href="<?= $sortLink('scrap') ?>" class="text-reset text-decoration-none"><?= t('scrap_operator') ?> <i class="bi <?= $sortIcon('scrap') ?>"></i></a></th>
                <th class="num-col"><a href="<?= $sortLink('prostoj_celkem_min') ?>" class="text-reset text-decoration-none">Celkové prostoje <i class="bi <?= $sortIcon('prostoj_celkem_min') ?>"></i></a></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="11" class="text-center py-5 text-muted-dvd"><?= t('no_data') ?></td></tr>
            <?php else: foreach ($rows as$row):
                $oee = (float)$row['oee'];
                $oeeClass =$oee >= 85 ? 'good' : ($oee >= 70 ? 'warn' : 'bad');$detailLink = APP_URL . '/admin/zaznamy_pracovnici_detail.php?' . http_build_query(['worker_key' => $row['worker_key'], 'od' =>$fOd, 'do' => $fDo, 'skupina' =>$fSkupina]);
            ?>
                <tr data-href="<?= h($detailLink) ?>" style="cursor:pointer" title="Otevřít detail zaměstnance">
                    <td><?= h($row['jmeno']) ?></td>
                    <td class="mono center-col"><?= h($row['interni_id'] !== '' ?$row['interni_id'] : '—') ?></td>
                    <td class="mono center-col"><?= h($row['skupiny'] ?: '—') ?></td>
                    <td class="mono text-accent num-col"><?= number_format((int)$row['zaznamu']) ?></td>
                    <td class="num-col"><span class="mono"><?= number_format($oee, 1) ?>%</span><div class="oee-bar-wrap"><div class="oee-bar-fill oee-<?= $oeeClass ?>" style="width:<?= min(100, $oee) ?>%"></div></div></td>
                    <td class="mono num-col"><?= number_format((float)$row['vykon'], 1) ?>%</td>
                    <td class="mono text-accent num-col"><?= number_format((int)$row['vyrobeno_ks']) ?></td>
                    <td class="mono num-col <?= (int)$row['nok_ks'] > 0 ? 'text-accent2' : '' ?>"><?= number_format((int)$row['nok_ks']) ?></td>
                    <td class="mono num-col <?= (int)$row['nok_operator_ks'] > 0 ? 'text-accent2' : '' ?>"><?= number_format((int)$row['nok_operator_ks']) ?></td>
                    <td class="mono num-col <?= (float)$row['scrap'] > 0 ? 'text-accent2' : '' ?>"><?= number_format((float)$row['scrap'], 2) ?>%</td>
                    <td class="mono num-col"><?= number_format((int)$row['prostoj_celkem_min']) ?> <?= t('unit_minutes') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
<script>
document.querySelectorAll('tr[data-href]').forEach(row => {
    row.addEventListener('click', () => window.location.href = row.dataset.href);
});

const operatorSuggestions = <?= json_encode($operatorSuggestions, JSON_UNESCAPED_UNICODE) ?>;
const operatorSearch = document.getElementById('operator-search');
const operatorId = document.getElementById('operator-id');
const operatorDropdown = document.getElementById('operator-dropdown');

function renderOperatorSuggestions() {
    const query = operatorSearch.value.trim().toLocaleLowerCase('cs-CZ');
    const matches = query === '' ? operatorSuggestions.slice(0, 60) : operatorSuggestions.filter(item => item.search.includes(query)).slice(0, 60);
    if (!matches.length) {
        operatorDropdown.style.display = 'none';
        return;
    }
    operatorDropdown.innerHTML = matches.map(item =>
        `<button type="button" data-id="${item.id}" style="display:block;width:100%;padding:7px 10px;background:transparent;border:0;border-bottom:1px solid var(--border);text-align:left;color:var(--text);font-size:.85rem;cursor:pointer">${item.label}</button>`
    ).join('');
    operatorDropdown.style.display = 'block';
}

operatorSearch.addEventListener('focus', renderOperatorSuggestions);
operatorSearch.addEventListener('input', () => {
    operatorId.value = '';
    renderOperatorSuggestions();
});
operatorDropdown.addEventListener('mousedown', event => {
    const option = event.target.closest('[data-id]');
    if (!option) return;
    const selected = operatorSuggestions.find(item => item.id === option.dataset.id);
    if (!selected) return;
    operatorSearch.value = selected.label;
    operatorId.value = selected.id;
    operatorDropdown.style.display = 'none';
});
document.addEventListener('click', event => {
    if (!event.target.closest('#operator-search') && !event.target.closest('#operator-dropdown')) {
        operatorDropdown.style.display = 'none';
    }
});
</script>