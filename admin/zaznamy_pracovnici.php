<?php
// admin/zaznamy_pracovnici.php – Přehled a statistiky dle pracovníků
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read', 'writer']);

$pageTitle = t('nav_workers') . ' - ' . t('nav_records');
$activePage = 'zaznamy_pracovnici';

$fOd = get('od', date('Y-m-01'));
$fDo = get('do', date('Y-m-d'));
if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
$fPrac = get('pracovisite_id', '');
$fSmena = get('smena', '');
$fSearch = trim((string)get('search', ''));
$export = get('export') === 'csv';

$where = ['z.datum BETWEEN :od AND :do'];
$params = [':od' => $fOd, ':do' => $fDo];
$workerIdJoin = "LEFT JOIN pracovnici p_interni ON CAST(p_interni.interni_id AS UNSIGNED) = zp.pracovnik_id
LEFT JOIN pracovnici p_id ON p_id.id = CAST(zp.jmeno AS UNSIGNED)";
$workerNameJoin = "LEFT JOIN (
    SELECT MIN(id) AS id, LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci AS jmeno_key, MIN(interni_id) AS interni_id, MIN(jmeno) AS jmeno
    FROM pracovnici
    WHERE COALESCE(TRIM(jmeno), '') <> ''
    GROUP BY LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci
) pn ON pn.jmeno_key = LOWER(TRIM(zp.jmeno)) COLLATE utf8mb4_unicode_ci";
if ($fPrac !== '') {
    $where[] = 'z.pracovisite_id = :prac';
    $params[':prac'] = $fPrac;
}
if ($fSmena !== '') {
    $where[] = 'z.smena = :smena';
    $params[':smena'] = $fSmena;
}
if ($fSearch !== '') {
    $terms = preg_split('/\s+/u', $fSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($terms as $index => $term) {
        $pNameZp = ':search_name_zp_' . $index;
        $pNamePInterni = ':search_name_p_interni_' . $index;
        $pNamePId = ':search_name_p_id_' . $index;
        $pNamePn = ':search_name_pn_' . $index;
        $pPos = ':search_pos_' . $index;
        $pInternPInterni = ':search_intern_p_interni_' . $index;
        $pInternPId = ':search_intern_p_id_' . $index;
        $pInternPn = ':search_intern_pn_' . $index;
        $where[] = "((zp.jmeno COLLATE utf8mb4_unicode_ci LIKE $pNameZp) OR (p_interni.jmeno COLLATE utf8mb4_unicode_ci LIKE $pNamePInterni) OR (p_id.jmeno COLLATE utf8mb4_unicode_ci LIKE $pNamePId) OR (pn.jmeno COLLATE utf8mb4_unicode_ci LIKE $pNamePn) OR (COALESCE(zp.pracovni_pozice, '') COLLATE utf8mb4_unicode_ci LIKE $pPos) OR (p_interni.interni_id COLLATE utf8mb4_unicode_ci LIKE $pInternPInterni) OR (p_id.interni_id COLLATE utf8mb4_unicode_ci LIKE $pInternPId) OR (pn.interni_id COLLATE utf8mb4_unicode_ci LIKE $pInternPn))";
        $params[$pNameZp] = '%' . $term . '%';
        $params[$pNamePInterni] = '%' . $term . '%';
        $params[$pNamePId] = '%' . $term . '%';
        $params[$pNamePn] = '%' . $term . '%';
        $params[$pPos] = '%' . $term . '%';
        $params[$pInternPInterni] = '%' . $term . '%';
        $params[$pInternPId] = '%' . $term . '%';
        $params[$pInternPn] = '%' . $term . '%';
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Opravený JOIN prostojů s rozpadem na plánované a neplánované prostoje
$downtimeJoin = "LEFT JOIN (
    SELECT zapis_id,
        SUM(CASE WHEN kategorie='plan' THEN minuty ELSE 0 END) AS dt_plan,
        SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost') THEN minuty ELSE 0 END) AS dt_neplan,
        SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) dt ON dt.zapis_id = z.id";

// Pomocný SQL výraz pro vážené OEE u jednotlivých pracovníků
$oeeSqlExpr = "LEAST(1.0, GREATEST(0.0,
    -- Dostupnost (A)
    (
        SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(dt.dt_plan, 0) - COALESCE(dt.dt_neplan, 0)))
        / NULLIF(SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(dt.dt_plan, 0))), 0)
    )
    *
    -- Výkonnost (P)
    (
        SUM(z.vyrobeno_ks)
        / NULLIF(SUM(
            CASE 
                WHEN z.cycle_time_min > 0 THEN GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(dt.dt_plan, 0) - COALESCE(dt.dt_neplan, 0)) / z.cycle_time_min
                WHEN z.norma_ks > 0 THEN GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(dt.dt_plan, 0) - COALESCE(dt.dt_neplan, 0)) * z.norma_ks
                ELSE 0
            END
        ), 0)
    )
    *
    -- Kvalita (Q)
    (
        SUM(GREATEST(0, z.vyrobeno_ks - z.nok_ks))
        / NULLIF(SUM(z.vyrobeno_ks), 0)
    )
)) * 100";

$rows = db_all("SELECT
    COALESCE(CAST(p_id.id AS CHAR), CAST(p_interni.id AS CHAR), CAST(pn.id AS CHAR), CONCAT('manual:', LOWER(TRIM(zp.jmeno)))) AS worker_key,
    COALESCE(p_interni.id, p_id.id, pn.id) AS pracovnik_id,
    COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno) AS jmeno,
    MAX(COALESCE(p_id.interni_id, p_interni.interni_id, pn.interni_id, '')) AS interni_id,
        GROUP_CONCAT(DISTINCT NULLIF(z.smena, '') ORDER BY z.smena SEPARATOR ', ') AS smeny,
        COUNT(DISTINCT z.id) AS zaznamu,
        MAX(z.datum) AS posledni_datum,
        SUM(z.vyrobeno_ks) AS vyrobeno_ks,
        SUM(z.nok_ks) AS nok_ks,
        SUM(z.vyrobeno_dobre_ks) AS dobre_ks,
        SUM(z.cisty_cas_min) AS cisty_cas_min,
        SUM(COALESCE(dt.dt_total,0)) AS prostoj_celkem_min,
        ROUND(COALESCE($oeeSqlExpr, 0), 2) AS avg_oee,
        CASE WHEN SUM(z.vyrobeno_ks) > 0 THEN (SUM(z.nok_ks) / SUM(z.vyrobeno_ks)) * 100 ELSE NULL END AS nok_rate,
        CASE WHEN SUM(z.vyrobeno_ks) > 0 THEN (SUM(z.vyrobeno_dobre_ks) / SUM(z.vyrobeno_ks)) * 100 ELSE NULL END AS good_rate
    FROM zapis_pracovnici zp
    JOIN zapisy z ON z.id = zp.zapis_id
    $workerIdJoin
    $workerNameJoin
    $downtimeJoin
    $whereSql
    GROUP BY worker_key, COALESCE(p_interni.id, p_id.id, pn.id), COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno)
    ORDER BY jmeno ASC, posledni_datum DESC, vyrobeno_ks DESC", $params);

if ($rows) {
    $lookupRows = db_all("SELECT id, interni_id, jmeno, aktivni
        FROM pracovnici
        WHERE COALESCE(TRIM(jmeno), '') <> ''
        ORDER BY aktivni DESC, id ASC");
    $byExact = [];
    $byToken = [];
    foreach ($lookupRows as $lw) {
        $exact = normalizeZapisyWorkerLookupKey((string)$lw['jmeno']);
        if ($exact !== null && !isset($byExact[$exact])) {
            $byExact[$exact] = $lw;
        }
        $token = normalizeZapisyWorkerTokenKey((string)$lw['jmeno']);
        if ($token !== null && !isset($byToken[$token])) {
            $byToken[$token] = $lw;
        }
    }

    foreach ($rows as &$row) {
        if (trim((string)($row['interni_id'] ?? '')) !== '') {
            continue;
        }
        $candidate = null;
        $name = (string)($row['jmeno'] ?? '');
        $exact = normalizeZapisyWorkerLookupKey($name);
        if ($exact !== null && isset($byExact[$exact])) {
            $candidate = $byExact[$exact];
        } else {
            $token = normalizeZapisyWorkerTokenKey($name);
            if ($token !== null && isset($byToken[$token])) {
                $candidate = $byToken[$token];
            }
        }

        if ($candidate !== null) {
            $row['interni_id'] = (string)($candidate['interni_id'] ?? '');
        }
    }
    unset($row);
}

// Celkové souhrnné statistiky pro horní karty
$stat = db_row("SELECT
        COUNT(*) AS workers_count,
        SUM(x.zaznamu) AS zaznamu,
        SUM(x.vyrobeno_ks) AS vyrobeno_ks,
        SUM(x.nok_ks) AS nok_ks,
        AVG(x.avg_oee) AS avg_oee,
        SUM(x.prostoj_celkem_min) AS prostoj_celkem_min
    FROM (
        SELECT
            COALESCE(CAST(p_id.id AS CHAR), CAST(p_interni.id AS CHAR), CAST(pn.id AS CHAR), CONCAT('manual:', LOWER(TRIM(zp.jmeno)))) AS worker_key,
            COUNT(DISTINCT z.id) AS zaznamu,
            SUM(z.vyrobeno_ks) AS vyrobeno_ks,
            SUM(z.nok_ks) AS nok_ks,
            ROUND(COALESCE($oeeSqlExpr, 0), 2) AS avg_oee,
            SUM(COALESCE(dt.dt_total,0)) AS prostoj_celkem_min
        FROM zapis_pracovnici zp
        JOIN zapisy z ON z.id = zp.zapis_id
        $workerIdJoin
        $workerNameJoin
        $downtimeJoin
        $whereSql
        GROUP BY worker_key
    ) x", $params) ?: [];

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dvd_pracovnici_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
    }
    fclose($out);
    exit;
}

$pracovisteList = getPracovisteList();
$workerSuggest = db_all("SELECT DISTINCT zp.jmeno
    FROM zapis_pracovnici zp
    WHERE zp.jmeno IS NOT NULL AND zp.jmeno <> ''
    ORDER BY zp.jmeno ASC
    LIMIT 400");

require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_workers') ?></span>
        <a href="<?= APP_URL ?>/admin/zaznamy.php" class="topbar-meta no-print" style="text-decoration:none"><i class="bi bi-table me-1"></i><?= t('nav_records') ?></a>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-download me-1"></i><?= t('export_csv') ?></a>
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">

<div class="row g-3 mb-4 no-print">
    <?php $stats = [
        [$stat['workers_count'] ?? 0, t('nav_workers'), 'bi-people'],
        [number_format((int)($stat['zaznamu'] ?? 0)), t('records_btn'), 'bi-file-earmark-text'],
        [number_format((int)($stat['vyrobeno_ks'] ?? 0)), t('produced_ks'), 'bi-boxes'],
        [number_format((int)($stat['nok_ks'] ?? 0)), t('nok_ks'), 'bi-x-octagon'],
        [isset($stat['avg_oee']) && $stat['avg_oee'] !== null ? round((float)$stat['avg_oee'], 1) . '%' : '—', t('avg_oee'), 'bi-speedometer2'],
        [number_format((int)($stat['prostoj_celkem_min'] ?? 0)) . ' min', t('downtime_min'), 'bi-pause-circle'],
    ]; foreach ($stats as [$val, $lbl, $ico]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><div class="stat-val"><?= $val ?></div><div class="stat-label"><i class="bi <?= $ico ?> me-1"></i><?= $lbl ?></div></div>
    </div>
    <?php endforeach; ?>
</div>

<form method="get" class="filter-bar no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('od') ?></label><input type="date" name="od" class="form-control" value="<?= h($fOd) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('do') ?></label><input type="date" name="do" class="form-control" value="<?= h($fDo) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label-dvd"><?= t('pracovisite') ?></label>
            <select name="pracovisite_id" class="form-select">
                <option value=""><?= t('all') ?></option>
                <?php foreach ($pracovisteList as $p): ?><option value="<?= $p['id'] ?>" <?= $fPrac == $p['id'] ? 'selected' : '' ?>>[<?= $p['cislo_id'] ?>] <?= h($p['nazev_dilu']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('smena') ?></label>
            <select name="smena" class="form-select">
                <option value=""><?= t('all') ?></option>
                <?php foreach(['R8','O8','N8','R12','N12'] as $s): ?><option <?= $fSmena === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2"><label class="form-label-dvd"><?= t('nav_workers') ?></label><input type="search" name="search" class="form-control" value="<?= h($fSearch) ?>" placeholder="<?= h(t('worker_namep')) ?>" list="worker-list" autocomplete="off"></div>
        <div class="col-auto d-flex gap-2 mt-1">
            <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
            <a href="zaznamy_pracovnici.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
        </div>
    </div>
</form>

<datalist id="worker-list">
    <?php foreach ($workerSuggest as $ws): if (!empty($ws['jmeno'])): ?>
    <option value="<?= h($ws['jmeno']) ?>"></option>
    <?php endif; endforeach; ?>
</datalist>

<div class="tbl-wrap">
    <div class="overflow-x">
        <table class="tbl">
            <thead>
                <tr>
                    <th><?= t('nav_workers') ?></th>
                    <th>ID</th>
                    <th><?= t('smena') ?></th>
                    <th><?= t('records_btn') ?></th>
                    <th><?= t('datum') ?></th>
                    <th><?= t('produced_ks') ?></th>
                    <th><?= t('nok_ks') ?></th>
                    <th><?= t('kt_nok_rate') ?></th>
                    <th><?= t('avg_oee') ?></th>
                    <th><?= t('downtime_min') ?></th>
                    <th><?= t('cisty_cas') ?></th>
                    <th class="no-print"><?= t('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="12" class="text-center py-5 text-muted-dvd"><?= t('no_data') ?></td></tr>
            <?php else: foreach ($rows as $row):
                $oee = $row['avg_oee'] !== null ? round((float)$row['avg_oee'], 1) : null;
                $oeeClass = $oee !== null ? ($oee >= 85 ? 'good' : ($oee >= 70 ? 'warn' : 'bad')) : '';
                $nokRate = $row['nok_rate'] !== null ? round((float)$row['nok_rate'], 2) : null;
                $detailLink = APP_URL . '/admin/zaznamy_pracovnici_detail.php?' . http_build_query([
                    'worker_key' => $row['worker_key'],
                    'od' => $fOd,
                    'do' => $fDo,
                    'search' => $fSearch,
                    'pracovisite_id' => $fPrac,
                    'smena' => $fSmena,
                ]);
            ?>
                <tr data-href="<?= h($detailLink) ?>">
                    <td><?= h($row['jmeno']) ?></td>
                    <td class="mono"><?= h($row['interni_id'] !== '' ? $row['interni_id'] : '—') ?></td>
                    <td class="mono"><?= h($row['smeny'] ?: '—') ?></td>
                    <td class="mono text-accent"><?= number_format((int)$row['zaznamu']) ?></td>
                    <td class="mono"><?= $row['posledni_datum'] ? date('d.m.Y', strtotime((string)$row['posledni_datum'])) : '—' ?></td>
                    <td class="mono text-accent"><?= number_format((int)$row['vyrobeno_ks']) ?></td>
                    <td class="mono <?= (int)$row['nok_ks'] > 0 ? 'text-accent2' : '' ?>"><?= number_format((int)$row['nok_ks']) ?></td>
                    <td class="mono"><?= $nokRate !== null ? number_format($nokRate, 2) . '%' : '—' ?></td>
                    <td>
                        <?php if ($oee !== null): ?>
                        <span class="mono" style="font-size:.78rem"><?= $oee ?>%</span>
                        <div class="oee-bar-wrap"><div class="oee-bar-fill oee-<?= $oeeClass ?>" style="width:<?= min(100, $oee) ?>%"></div></div>
                        <?php else: ?><span class="text-muted-dvd">—</span><?php endif; ?>
                    </td>
                    <td class="mono"><?= number_format((int)$row['prostoj_celkem_min']) ?> <?= t('unit_minutes') ?></td>
                    <td class="mono"><?= number_format((int)$row['cisty_cas_min']) ?> <?= t('unit_minutes') ?></td>
                    <td class="no-print"><a href="<?= $detailLink ?>" class="btn-icon" title="<?= t('detail') ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="tbl-footer no-print">
        <span><?= t('nav_workers') ?>: <?= count($rows) ?></span>
        <span class="text-muted-dvd"><?= t('max_rows_hint') ?></span>
    </div>
</div>

</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>