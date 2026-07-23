<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'traceability', 'writer', 'read']);

$pageTitle = t('traceability_title');
$activePage = 'traceability';

$allTables = ['gen3', 'gen3manual', '48w', '48w200', '48w200_2', 'gen3cina'];
$fOd = get('od', date('Y-m-01'));
$fDo = get('do', date('Y-m-d'));
$fTable = (string)get('src_table', '');
$fSerial = trim((string)get('serial', ''));
$fUserId = max(0, (int)get('user_id', 0));
$fSerialT = get('serial_t', '') === '1';
$export = get('export') === 'csv';

if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
if (!in_array($fTable, $allTables, true)) $fTable = '';

$selectedTables = $fTable !== '' ? [$fTable] : $allTables;
$paramsOne = [$fOd . ' 00:00:00', $fDo . ' 23:59:59'];
$where = ['`timestamp` BETWEEN ? AND ?'];
if ($fSerial !== '') {
    $where[] = 'snDil LIKE ?';
    $paramsOne[] = '%' . $fSerial . '%';
}
if ($fSerialT) {
    $where[] = 'snDil LIKE ?';
    $paramsOne[] = 'T%';
}
if ($fUserId > 0) {
    $where[] = 'userId = ?';
    $paramsOne[] = $fUserId;
}

$unionParts = [];
$unionParams = [];
foreach ($selectedTables as $table) {
    $unionParts[] = "SELECT '" . $table . "' AS src_table, id, snDil, `timestamp`, userId FROM `" . $table . "` WHERE " . implode(' AND ', $where);
    $unionParams = array_merge($unionParams, $paramsOne);
}
$unionSql = implode(' UNION ALL ', $unionParts);

if ($export) {
    $exportRows = trace_db_all(
        "SELECT u.src_table, u.id, u.snDil, u.`timestamp`, u.userId
         FROM ($unionSql) u
         ORDER BY u.`timestamp` DESC, u.id DESC",
        $unionParams
    );
    $exportRows = applyTraceabilityUsernames($exportRows);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="traceability2_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if (!empty($exportRows)) {
        fputcsv($out, array_keys($exportRows[0]), ';');
        foreach ($exportRows as $row) {
            fputcsv($out, $row, ';');
        }
    }
    fclose($out);
    exit;
}

$summary = trace_db_row(
    "SELECT COUNT(*) AS scans_total, COUNT(DISTINCT snDil) AS serials_total, COUNT(DISTINCT userId) AS users_total,
            MIN(`timestamp`) AS first_scan, MAX(`timestamp`) AS last_scan
     FROM ($unionSql) u",
    $unionParams
);

$daily = trace_db_all(
    "SELECT DATE(`timestamp`) AS den, COUNT(*) AS scans_total, COUNT(DISTINCT snDil) AS serials_total
     FROM ($unionSql) u
     GROUP BY DATE(`timestamp`)
     ORDER BY DATE(`timestamp`)",
    $unionParams
);

$byTable = trace_db_all(
    "SELECT src_table, COUNT(*) AS scans_total, COUNT(DISTINCT snDil) AS serials_total,
            COUNT(DISTINCT userId) AS users_total, MIN(`timestamp`) AS first_scan, MAX(`timestamp`) AS last_scan
     FROM ($unionSql) u
     GROUP BY src_table
     ORDER BY scans_total DESC",
    $unionParams
);

$topUsers = trace_db_all(
    "SELECT u.userId,
            COUNT(*) AS scans_total, COUNT(DISTINCT u.snDil) AS serials_total,
            MIN(u.`timestamp`) AS first_scan, MAX(u.`timestamp`) AS last_scan
     FROM ($unionSql) u
     GROUP BY u.userId
     ORDER BY scans_total DESC
     LIMIT 20",
    $unionParams
);
$topUsers = applyTraceabilityUsernames($topUsers);

$recent = trace_db_all(
    "SELECT u.src_table, u.id, u.snDil, u.`timestamp`, u.userId
     FROM ($unionSql) u
     ORDER BY u.`timestamp` DESC, u.id DESC
     LIMIT 100",
    $unionParams
);
$recent = applyTraceabilityUsernames($recent);

$userOptions = getUnifiedTraceabilityUserOptions();

// Průchod dílů 48w → 48w200
// Dvě nezávislé agregace spojené JOINem – žádný korelovaný poddotaz.
$flowWhere  = ['`timestamp` BETWEEN ? AND ?'];
$flowParams = [$fOd . ' 00:00:00', $fDo . ' 23:59:59'];
if ($fSerial !== '') {
    $flowWhere[]  = 'snDil LIKE ?';
    $flowParams[] = '%' . $fSerial . '%';
}
if ($fSerialT) {
    $flowWhere[]  = 'snDil LIKE ?';
    $flowParams[] = 'T%';
}
$flowWhereSql = implode(' AND ', $flowWhere);
$flowRows = trace_db_all(
    "SELECT w.snDil, w.ts_48w, w200.ts_48w200,
            w.userId_48w,
            w200.userId_48w200
     FROM (
         SELECT w1.snDil, w1.`timestamp` AS ts_48w, w1.userId AS userId_48w
         FROM `48w` w1
         JOIN (
             SELECT snDil, MIN(`timestamp`) AS ts_48w
             FROM `48w`
             WHERE {$flowWhereSql}
             GROUP BY snDil
         ) tmin ON tmin.snDil = w1.snDil AND tmin.ts_48w = w1.`timestamp`
         ORDER BY w1.`timestamp` DESC
         LIMIT 500
     ) w
     LEFT JOIN (
         SELECT w2.snDil, w2.`timestamp` AS ts_48w200, w2.userId AS userId_48w200
         FROM `48w200` w2
         JOIN (
             SELECT snDil, MIN(`timestamp`) AS ts_48w200
             FROM `48w200`
             GROUP BY snDil
         ) t2min ON t2min.snDil = w2.snDil AND t2min.ts_48w200 = w2.`timestamp`
     ) w200 ON w200.snDil = w.snDil
     ORDER BY w.ts_48w DESC",
    $flowParams
);
$flowUserMap = getTraceabilityUsernamesByIds(array_merge(
    array_map(static fn(array $row): int => (int)($row['userId_48w'] ?? 0), $flowRows),
    array_map(static fn(array $row): int => (int)($row['userId_48w200'] ?? 0), $flowRows)
));
foreach ($flowRows as &$flowRow) {
    $id48w = (int)($flowRow['userId_48w'] ?? 0);
    $id48w200 = (int)($flowRow['userId_48w200'] ?? 0);
    $flowRow['user_48w'] = $flowUserMap[$id48w] ?? ('#' . $id48w);
    $flowRow['user_48w200'] = $flowUserMap[$id48w200] ?? ('#' . $id48w200);
}
unset($flowRow);

// Last sync time from state file
$syncStateFile = UPLOAD_DIR . 'sync_traceability_state.json';
$lastSyncAt = null;
if (is_file($syncStateFile)) {
    $stateData = json_decode(file_get_contents($syncStateFile), true);
    if (is_array($stateData)) {
        $times = array_filter(array_column($stateData, 'synced_at'));
        if ($times) {
            $lastSyncAt = max($times);
        }
    }
}

$jsDaily = [
    'labels' => array_map(static fn(array $row): string => (string)$row['den'], $daily),
    'scans' => array_map(static fn(array $row): int => (int)$row['scans_total'], $daily),
    'serials' => array_map(static fn(array $row): int => (int)$row['serials_total'], $daily),
];

require APP_ROOT . '/includes/layout.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-upc-scan me-2"></i><?= t('traceability_title') ?></span>
        <?php if ($lastSyncAt): ?>
        <?php if (isSuperAdmin()): ?>
        <a href="<?= $baseUrl ?>/scripts/sync_traceability.php" title="<?= t('sync_run_now') ?>" style="font-size:.75rem;color:var(--muted);white-space:nowrap;text-decoration:none" class="no-print"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= h(date('d.m.Y H:i', strtotime($lastSyncAt))) ?></strong></a>
        <?php else: ?>
        <span style="font-size:.75rem;color:var(--muted);white-space:nowrap"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= h(date('d.m.Y H:i', strtotime($lastSyncAt))) ?></strong></span>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-download me-1"></i><?= t('export_csv') ?></a>
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
<form method="get" class="filter-bar no-print">
    <div class="d-flex flex-wrap align-items-end gap-2">
        <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
        <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
        <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('traceability_table') ?></label>
            <select name="src_table" class="form-select">
                <option value=""><?= t('all') ?></option>
                <?php foreach ($allTables as $table): ?>
                <option value="<?= h($table) ?>" <?= $fTable === $table ? 'selected' : '' ?>><?= h($table) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:2 1 160px;min-width:130px"><label class="form-label-dvd"><?= t('traceability_serial') ?></label><input type="text" name="serial" class="form-control" value="<?= h($fSerial) ?>" placeholder="SN..."></div>
        <div style="flex:1 1 130px;min-width:110px"><label class="form-label-dvd"><?= t('user') ?></label>
            <select name="user_id" class="form-select">
                <option value="0"><?= t('all') ?></option>
                <?php foreach ($userOptions as $user): ?>
                <option value="<?= (int)$user['id'] ?>" <?= $fUserId === (int)$user['id'] ? 'selected' : '' ?>><?= h($user['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1 1 170px;min-width:160px" class="pt-1">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" value="1" name="serial_t" id="serial_t" <?= $fSerialT ? 'checked' : '' ?>>
                <label class="form-check-label" for="serial_t" style="font-size:.85rem"><?= t('traceability_serial_t_only') ?></label>
            </div>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
            <a href="traceability.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
        </div>
    </div>
</form>

<?php
$cards = [
    [(int)($summary['scans_total'] ?? 0), t('traceability_scans_total'), 'bi-upc-scan', 'accent'],
    [(int)($summary['serials_total'] ?? 0), t('traceability_serials_total'), 'bi-123', 'accent3'],
    [(int)($summary['users_total'] ?? 0), t('traceability_users_total'), 'bi-people', 'warn'],
    [$summary['first_scan'] ? date('d.m.Y H:i', strtotime((string)$summary['first_scan'])) : '—', t('traceability_first_scan'), 'bi-calendar-event', 'muted'],
    [$lastSyncAt ? date('d.m.Y H:i', strtotime((string)$lastSyncAt)) : ($summary['last_scan'] ? date('d.m.Y H:i', strtotime((string)$summary['last_scan'])) : '—'), t('last_sync'), 'bi-arrow-repeat', 'accent2'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as [$value, $label, $icon, $color]): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card">
            <div class="stat-val" style="color:var(--<?= $color ?>);font-size:<?= is_numeric(str_replace(['.', ','], '', (string)$value)) ? '2rem' : '1.05rem' ?>"><?= is_int($value) ? number_format($value) : $value ?></div>
            <div class="stat-label"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card-dvd">
            <div class="card-header-dvd"><span class="s-num"><i class="bi bi-graph-up"></i></span><span class="s-title"><?= t('traceability_daily_scans') ?></span></div>
            <div class="card-body-dvd"><canvas id="traceability-chart" height="65"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card-dvd h-100">
            <div class="card-header-dvd"><span class="s-num"><i class="bi bi-table"></i></span><span class="s-title"><?= t('traceability_by_table') ?></span></div>
            <div class="card-body-dvd" style="padding:0">
                <div style="overflow-x:auto">
                    <table class="table-dvd" style="width:100%;font-size:.74rem">
                        <thead><tr>
                            <th><?= t('traceability_source') ?></th>
                            <th style="text-align:right"><?= t('traceability_scans_total') ?></th>
                            <th style="text-align:right"><?= t('traceability_serials_total') ?></th>
                            <th style="text-align:right"><?= t('traceability_users_total') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$byTable): ?>
                            <tr><td colspan="4" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                        <?php else: foreach ($byTable as $row): ?>
                            <tr>
                                <td class="mono"><?= h($row['src_table']) ?></td>
                                <td style="text-align:right"><?= number_format((int)$row['scans_total']) ?></td>
                                <td style="text-align:right"><?= number_format((int)$row['serials_total']) ?></td>
                                <td style="text-align:right"><?= number_format((int)$row['users_total']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card-dvd h-100">
            <div class="card-header-dvd"><span class="s-num"><i class="bi bi-people"></i></span><span class="s-title"><?= t('traceability_top_users') ?></span></div>
            <div class="card-body-dvd" style="padding:0">
                <div style="overflow-x:auto">
                    <table class="table-dvd" style="width:100%;font-size:.74rem">
                        <thead><tr>
                            <th><?= t('user') ?></th>
                            <th style="text-align:right"><?= t('traceability_scans_total') ?></th>
                            <th style="text-align:right"><?= t('traceability_serials_total') ?></th>
                            <th><?= t('traceability_first_scan') ?></th>
                            <th><?= t('traceability_last_scan') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$topUsers): ?>
                            <tr><td colspan="5" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                        <?php else: foreach ($topUsers as $row): ?>
                            <tr>
                                <td><?= h($row['username']) ?></td>
                                <td style="text-align:right"><?= number_format((int)$row['scans_total']) ?></td>
                                <td style="text-align:right"><?= number_format((int)$row['serials_total']) ?></td>
                                <td class="mono"><?= $row['first_scan'] ? h(date('d.m.Y H:i', strtotime((string)$row['first_scan']))) : '—' ?></td>
                                <td class="mono"><?= $row['last_scan'] ? h(date('d.m.Y H:i', strtotime((string)$row['last_scan']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card-dvd">
            <div class="card-header-dvd"><span class="s-num"><i class="bi bi-clock-history"></i></span><span class="s-title"><?= t('traceability_recent_scans') ?></span></div>
            <div class="card-body-dvd" style="padding:0">
                <div style="overflow-x:auto;max-height:520px">
                    <table class="table-dvd" style="width:100%;font-size:.74rem">
                        <thead><tr>
                            <th><?= t('traceability_source') ?></th>
                            <th>ID</th>
                            <th><?= t('traceability_serial') ?></th>
                            <th><?= t('user') ?></th>
                            <th><?= t('cas') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$recent): ?>
                            <tr><td colspan="5" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                        <?php else: foreach ($recent as $row): ?>
                            <tr>
                                <td class="mono"><?= h($row['src_table']) ?></td>
                                <td class="mono"><?= (int)$row['id'] ?></td>
                                <td class="mono" style="font-size:.7rem"><?= h($row['snDil']) ?></td>
                                <td><?= h($row['username']) ?></td>
                                <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$row['timestamp']))) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card-dvd">
            <div class="card-header-dvd">
                <span class="s-num"><i class="bi bi-diagram-3"></i></span>
                <span class="s-title">Průchod dílů 48w &rarr; CSL</span>
                <span style="margin-left:auto;font-size:.72rem;color:var(--muted)">max 500 SN, datum filtruje 48w</span>
            </div>
            <div class="card-body-dvd" style="padding:0">
                <div style="overflow-x:auto;max-height:520px">
                    <table class="table-dvd" style="width:100%;font-size:.74rem">
                        <thead><tr>
                            <th>Sériové číslo</th>
                            <th>48w – čas průchodu</th>
                            <th>48w – uživatel</th>
                            <th>CSL – čas průchodu</th>
                            <th>CSL – uživatel</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$flowRows): ?>
                            <tr><td colspan="5" style="padding:.9rem 1rem;color:var(--muted)"><?= t('no_data') ?></td></tr>
                        <?php else: foreach ($flowRows as $fRow):
                            $hasBoth = $fRow['ts_48w200'] !== null;
                            $rowColor = $hasBoth ? 'var(--success)' : 'var(--warn)';
                        ?>
                            <tr style="color:<?= $rowColor ?>">
                                <td class="mono" style="font-size:.7rem;font-weight:600"><?= h($fRow['snDil']) ?></td>
                                <td class="mono"><?= h(date('d.m.Y H:i:s', strtotime((string)$fRow['ts_48w']))) ?></td>
                                <td><?= h((string)$fRow['user_48w']) ?></td>
                                <td class="mono"><?= $fRow['ts_48w200'] ? h(date('d.m.Y H:i:s', strtotime((string)$fRow['ts_48w200']))) : '<span style="color:var(--warn)">— ' . h(t('traceability_not_found')) . '</span>' ?></td>
                                <td><?= $fRow['ts_48w200'] ? h((string)$fRow['user_48w200']) : '<span style="color:var(--warn)">— ' . h(t('traceability_not_found')) . '</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const traceDaily = <?= json_encode($jsDaily, JSON_UNESCAPED_UNICODE) ?>;
if (traceDaily.labels.length) {
    new Chart(document.getElementById('traceability-chart'), {
        type: 'bar',
        data: {
            labels: traceDaily.labels,
            datasets: [
                {
                    type: 'bar',
                    label: '<?= h(t('traceability_scans_total')) ?>',
                    data: traceDaily.scans,
                    backgroundColor: 'rgba(0,229,176,.45)',
                    borderColor: 'rgba(0,229,176,.95)',
                    borderWidth: 1,
                },
                {
                    type: 'line',
                    label: '<?= h(t('traceability_serials_total')) ?>',
                    data: traceDaily.serials,
                    borderColor: 'rgba(91,141,238,.95)',
                    backgroundColor: 'rgba(91,141,238,.15)',
                    yAxisID: 'y',
                    tension: 0.25,
                    pointRadius: 2,
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: '#c8d0e0' } } },
            scales: {
                x: { ticks: { color: '#8190ad' }, grid: { color: 'rgba(255,255,255,.05)' } },
                y: { ticks: { color: '#8190ad' }, grid: { color: 'rgba(255,255,255,.05)' }, beginAtZero: true }
            }
        }
    });
}
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>