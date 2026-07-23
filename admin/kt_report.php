<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'writer']);

$pageTitle = t('kt_report_title');
$activePage = 'kt_report';

$targetCisloIds = [39, 50, 162, 175, 33, 119, 183, 148, 184];
$today = new DateTimeImmutable('today');
$defaultCw = (int)$today->format('W');
$defaultIsoYear = (int)$today->format('o');

$dayNamesByLang = [
    'cs' => [1 => 'Pondeli', 2 => 'Utery', 3 => 'Streda', 4 => 'Ctvrtek', 5 => 'Patek', 6 => 'Sobota', 7 => 'Nedele'],
    'en' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
    'uk' => [1 => 'Ponedilok', 2 => 'Vivtorok', 3 => 'Sereda', 4 => 'Chetver', 5 => 'Piatnytsia', 6 => 'Subota', 7 => 'Nedilia'],
];
$lang = $_SESSION['lang'] ?? 'cs';
$dayNames = $dayNamesByLang[$lang] ?? $dayNamesByLang['cs'];

$fCw = (int)get('cw', (string)$defaultCw);
$fYear = (int)get('year', (string)$defaultIsoYear);
$export = get('export') === 'csv';

if ($fCw < 1 || $fCw > 53) {
    $fCw = $defaultCw;
}
if ($fYear < 2020 || $fYear > 2100) {
    $fYear = $defaultIsoYear;
}

$weekStart = $today->setISODate($fYear, $fCw, 1);
$weekEnd = $today->setISODate($fYear, $fCw, 7);
$dateFrom = $weekStart->format('Y-m-d');
$dateTo = $weekEnd->format('Y-m-d');

$days = [];
$dayKeys = [];
for ($i = 0; $i < 7; $i++) {
    $d = $weekStart->modify('+' . $i . ' day');
    $isoDate = $d->format('Y-m-d');
    $dayNum = (int)$d->format('N');
    $days[] = [
        'iso' => $isoDate,
        'label' => $d->format('d.m.Y'),
        'name' => $dayNames[$dayNum] ?? $isoDate,
    ];
    $dayKeys[] = $isoDate;
}

$idPlaceholders = [];
$params = [':od' => $dateFrom, ':do' => $dateTo];
$idParams = [];
foreach ($targetCisloIds as $idx => $cid) {
    $ph = ':cid' . $idx;
    $idPlaceholders[] = $ph;
    $params[$ph] = $cid;
    $idParams[$ph] = $cid;
}

$workplaces = [];
$nameRows = db_all(
    "SELECT p.cislo_id,
            COALESCE(MAX(NULLIF(p.nazev_pracovisite, '')), MAX(NULLIF(p.nazev_dilu, '')), CONCAT('ID ', p.cislo_id)) AS nazev
     FROM pracovisite p
     WHERE p.cislo_id IN (" . implode(',', $idPlaceholders) . ")
     GROUP BY p.cislo_id",
    $idParams
);
$nameByCislo = [];
foreach ($nameRows as $n) {
    $nameByCislo[(int)$n['cislo_id']] = (string)$n['nazev'];
}

$matrix = [];
$totalsByDay = [];
foreach ($dayKeys as $dk) {
    $totalsByDay[$dk] = ['vyrobeno_ks' => 0, 'nok_ks' => 0];
}

foreach ($targetCisloIds as $cid) {
    $workplaces[] = [
        'cislo_id' => $cid,
        'nazev' => $nameByCislo[$cid] ?? ('ID ' . $cid),
    ];
    foreach ($dayKeys as $dk) {
        $matrix[$cid][$dk] = ['vyrobeno_ks' => 0, 'nok_ks' => 0];
    }
}

$sql = "SELECT p.cislo_id, z.datum,
               SUM(COALESCE(z.vyrobeno_ks, 0)) AS vyrobeno_ks,
               SUM(COALESCE(z.nok_ks, 0)) AS nok_ks
        FROM zapisy z
        INNER JOIN pracovisite p ON p.id = z.pracovisite_id
        WHERE z.datum BETWEEN :od AND :do
          AND p.cislo_id IN (" . implode(',', $idPlaceholders) . ")
        GROUP BY p.cislo_id, z.datum
        ORDER BY p.cislo_id, z.datum";

$dbRows = db_all($sql, $params);
foreach ($dbRows as $row) {
    $cid = (int)$row['cislo_id'];
    $dateKey = (string)$row['datum'];
    if (!isset($matrix[$cid][$dateKey])) {
        continue;
    }
    $prod = (int)$row['vyrobeno_ks'];
    $nok = (int)$row['nok_ks'];
    $matrix[$cid][$dateKey] = ['vyrobeno_ks' => $prod, 'nok_ks' => $nok];
    $totalsByDay[$dateKey]['vyrobeno_ks'] += $prod;
    $totalsByDay[$dateKey]['nok_ks'] += $nok;
}

$totalProduced = 0;
$totalNok = 0;
foreach ($totalsByDay as $sum) {
    $totalProduced += (int)$sum['vyrobeno_ks'];
    $totalNok += (int)$sum['nok_ks'];
}
$totalNokRate = $totalProduced > 0 ? ($totalNok / $totalProduced) * 100 : 0.0;

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kt_report_' . $fYear . '_cw' . str_pad((string)$fCw, 2, '0', STR_PAD_LEFT) . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    $header = [t('kt_col_workplace')];
    foreach ($days as $d) {
        $header[] = $d['label'] . ' ' . $d['name'] . ' - ' . t('kt_produced_short');
        $header[] = $d['label'] . ' ' . $d['name'] . ' - ' . t('kt_nok_short');
    }
    fputcsv($out, $header, ';');

    foreach ($workplaces as $wp) {
        $line = ['[' . $wp['cislo_id'] . '] ' . $wp['nazev']];
        foreach ($dayKeys as $dk) {
            $line[] = (int)$matrix[$wp['cislo_id']][$dk]['vyrobeno_ks'];
            $line[] = (int)$matrix[$wp['cislo_id']][$dk]['nok_ks'];
        }
        fputcsv($out, $line, ';');
    }

    $totalLine = [t('kt_total')];
    foreach ($dayKeys as $dk) {
        $totalLine[] = (int)$totalsByDay[$dk]['vyrobeno_ks'];
        $totalLine[] = (int)$totalsByDay[$dk]['nok_ks'];
    }
    fputcsv($out, $totalLine, ';');
    fclose($out);
    exit;
}

require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('kt_report_title') ?> - CW <?= h((string)$fCw) ?> / <?= h((string)$fYear) ?></span>
        <span class="topbar-meta"><?= t('kt_scope_cislo') ?>: <?= h(implode(', ', $targetCisloIds)) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <a href="<?= APP_URL ?>/admin/kt_report_tv.php" class="btn-outline" target="_blank" rel="noopener noreferrer" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-box-arrow-up-right me-1"></i><?= t('nav_tv') ?>
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-download me-1"></i><?= t('export_csv') ?>
        </a>
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-printer me-1"></i><?= t('print') ?>
        </button>
    </div>
</div>

<div class="content">
    <form method="get" class="filter-bar no-print">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label-dvd">CW</label>
                <input type="number" name="cw" class="form-control" min="1" max="53" value="<?= h((string)$fCw) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label-dvd"><?= t('year') ?></label>
                <input type="number" name="year" class="form-control" min="2020" max="2100" value="<?= h((string)$fYear) ?>">
            </div>
            <div class="col-auto d-flex gap-2 mt-1">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
                <a href="<?= APP_URL ?>/admin/kt_report.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono\"><?= h($dateFrom) ?></div><div class="stat-label"><?= t('od') ?></div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono\"><?= h($dateTo) ?></div><div class="stat-label"><?= t('do') ?></div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono" style="color:var(--accent)"><?= number_format($totalProduced) ?></div><div class="stat-label"><?= t('kt_produced_ks') ?></div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono" style="color:var(--accent2)"><?= number_format($totalNok) ?> <small class="mono" style="font-size:.8rem;opacity:.75;">(<?= number_format($totalNokRate, 2) ?>%)</small></div><div class="stat-label"><?= t('kt_nok_ks') ?></div></div>
        </div>
    </div>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th><?= t('kt_col_workplace') ?></th>
                    <?php foreach ($days as $d): ?>
                        <th class="mono" style="min-width:160px;">
                            <?= h($d['label']) ?><br>
                            <small style="color:var(--muted);font-weight:600;"><?= h($d['name']) ?></small>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($workplaces as $wp):
                $wpTotal = 0;
                foreach ($dayKeys as $dk) { $wpTotal += (int)$matrix[$wp['cislo_id']][$dk]['vyrobeno_ks']; }
            ?>
                <tr>
                    <td>
                        <div class="mono">[<?= (int)$wp['cislo_id'] ?>]</div>
                        <div><?= h($wp['nazev']) ?></div>
                    </td>
                    <?php if ($wpTotal === 0): ?>
                        <td colspan="<?= count($dayKeys) ?>" class="mono" style="color:var(--muted);font-style:italic;text-align:center;"><?= t('kt_no_production') ?></td>
                    <?php else: ?>
                    <?php foreach ($dayKeys as $dk):
                        $prod = (int)$matrix[$wp['cislo_id']][$dk]['vyrobeno_ks'];
                        $nok = (int)$matrix[$wp['cislo_id']][$dk]['nok_ks'];
                    ?>
                        <td class="mono">
                            <?php if ($prod === 0): ?>
                            <div style="color:var(--muted);font-style:italic;font-size:.82rem"><?= t('kt_no_production') ?></div>
                            <?php else: ?>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                            <div style="color:var(--accent);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format($prod) ?></div>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                            <div style="color:var(--accent2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format($nok) ?></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
                <tr style="font-weight:700;">
                    <td><?= t('kt_total') ?></td>
                    <?php foreach ($dayKeys as $dk): ?>
                        <td class="mono">
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                            <div style="color:var(--accent);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format((int)$totalsByDay[$dk]['vyrobeno_ks']) ?></div>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                            <div style="color:var(--accent2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format((int)$totalsByDay[$dk]['nok_ks']) ?></div>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
