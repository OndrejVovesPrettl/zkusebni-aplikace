<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read', 'writer']);

$workerKey = trim((string)get('worker_key', ''));
if ($workerKey === '') {
    redirect(APP_URL . '/admin/zaznamy_pracovnici.php');
}

$fOd = get('od', date('Y-m-01'));
$fDo = get('do', date('Y-m-d'));
if (!validateDate($fOd)) $fOd = date('Y-m-01');
if (!validateDate($fDo)) $fDo = date('Y-m-d');
$fPrac = get('pracovisite_id', '');
$fSmena = get('smena', '');
$fSearch = trim((string)get('search', ''));

$params = [':od' => $fOd, ':do' => $fDo];
$where = ['z.datum BETWEEN :od AND :do'];
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

if (str_starts_with($workerKey, 'manual:')) {
    $manualName = substr($workerKey, 7);
    $where[] = 'LOWER(TRIM(zp.jmeno)) = :worker_name';
    $params[':worker_name'] = $manualName;
} else {
    $where[] = 'COALESCE(p_id.id, p_interni.id, pn.id) = :worker_id';
    $params[':worker_id'] = (int)$workerKey;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$downtimeJoin = "LEFT JOIN (
    SELECT zapis_id, SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) dt ON dt.zapis_id = z.id";

$worker = db_row("SELECT
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
        ROUND(
            COALESCE(
                (SUM(z.cisty_cas_min) / NULLIF(SUM(z.pracovni_doba_min), 0)) * (SUM(z.vyrobeno_ks) / NULLIF(SUM(z.norma_ks), 0)) * ((SUM(z.vyrobeno_ks) - SUM(z.nok_ks)) / NULLIF(SUM(z.vyrobeno_ks), 0))
            , 0) * 100
        , 2) AS avg_oee,
        CASE WHEN SUM(z.vyrobeno_ks) > 0 THEN (SUM(z.nok_ks) / SUM(z.vyrobeno_ks)) * 100 ELSE NULL END AS nok_rate,
        CASE WHEN SUM(z.vyrobeno_ks) > 0 THEN (SUM(z.vyrobeno_dobre_ks) / SUM(z.vyrobeno_ks)) * 100 ELSE NULL END AS good_rate
    FROM zapis_pracovnici zp
    JOIN zapisy z ON z.id = zp.zapis_id
    $workerIdJoin
    $workerNameJoin
    $downtimeJoin
    $whereSql
    GROUP BY worker_key, COALESCE(p_interni.id, p_id.id, pn.id), COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno)
    LIMIT 1", $params);

if (!$worker) {
    http_response_code(404);
    die(t('no_data'));
}

if (trim((string)($worker['interni_id'] ?? '')) === '') {
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

    $candidate = null;
    $exact = normalizeZapisyWorkerLookupKey((string)$worker['jmeno']);
    if ($exact !== null && isset($byExact[$exact])) {
        $candidate = $byExact[$exact];
    } else {
        $token = normalizeZapisyWorkerTokenKey((string)$worker['jmeno']);
        if ($token !== null && isset($byToken[$token])) {
            $candidate = $byToken[$token];
        }
    }

    if ($candidate !== null) {
        $worker['interni_id'] = (string)($candidate['interni_id'] ?? '');
    }
}

$records = db_all("SELECT
        z.id,
        z.datum,
        z.smena,
        z.kod_pracovisite,
        z.nazev_pracovisite,
        z.nazev_dilu,
        z.vyrobeno_ks,
        z.nok_ks,
        z.cisty_cas_min,
        z.oee_procent,
        COALESCE(dt.dt_total,0) AS prostoj_celkem_min
    FROM zapis_pracovnici zp
    JOIN zapisy z ON z.id = zp.zapis_id
    $workerIdJoin
    $workerNameJoin
    $downtimeJoin
    $whereSql
    GROUP BY z.id, z.datum, z.smena, z.kod_pracovisite, z.nazev_pracovisite, z.nazev_dilu, z.vyrobeno_ks, z.nok_ks, z.cisty_cas_min, z.oee_procent, dt.dt_total
    ORDER BY z.datum DESC, z.id DESC
    LIMIT 500", $params);

$backLink = APP_URL . '/admin/zaznamy_pracovnici.php?' . http_build_query([
    'od' => $fOd,
    'do' => $fDo,
    'search' => $fSearch,
    'pracovisite_id' => $fPrac,
    'smena' => $fSmena,
]);

$pageTitle = t('nav_workers') . ' - ' . h($worker['jmeno']);
$activePage = 'zaznamy_pracovnici';
require APP_ROOT . '/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="<?= $backLink ?>" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><?= h($worker['jmeno']) ?></span>
        <span class="topbar-meta mono"><?= h($worker['interni_id'] !== '' ? $worker['interni_id'] : '—') ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
<div class="row g-3 mb-4">
    <?php $stats = [
        [number_format((int)($worker['zaznamu'] ?? 0)), t('records_btn'), 'bi-file-earmark-text'],
        [number_format((int)($worker['vyrobeno_ks'] ?? 0)), t('produced_ks'), 'bi-boxes'],
        [number_format((int)($worker['nok_ks'] ?? 0)), t('nok_ks'), 'bi-x-octagon'],
        [$worker['nok_rate'] !== null ? number_format((float)$worker['nok_rate'], 2) . '%' : '—', t('kt_nok_rate'), 'bi-percent'],
        [$worker['avg_oee'] !== null ? round((float)$worker['avg_oee'], 1) . '%' : '—', t('avg_oee'), 'bi-speedometer2'],
        [number_format((int)($worker['prostoj_celkem_min'] ?? 0)) . ' min', t('downtime_min'), 'bi-pause-circle'],
    ]; foreach ($stats as [$val, $lbl, $ico]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card"><div class="stat-val"><?= $val ?></div><div class="stat-label"><i class="bi <?= $ico ?> me-1"></i><?= $lbl ?></div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card-dvd h-100">
            <div class="card-header-dvd"><span class="s-num">01</span><span class="s-title"><?= t('nav_workers') ?></span></div>
            <div class="card-body-dvd">
                <div class="row g-2" style="font-size:.82rem">
                    <div class="col-12"><div class="form-label-dvd"><?= t('pracovnik') ?></div><div class="calc-display" style="min-height:30px;font-size:.78rem"><?= h($worker['jmeno']) ?></div></div>
                    <div class="col-6"><div class="form-label-dvd">ID</div><div class="calc-display" style="min-height:30px;font-size:.78rem"><?= h($worker['interni_id'] !== '' ? $worker['interni_id'] : '—') ?></div></div>
                    <div class="col-6"><div class="form-label-dvd"><?= t('smena') ?></div><div class="calc-display mono" style="min-height:30px;font-size:.78rem"><?= h($worker['smeny'] ?: '—') ?></div></div>
                    <div class="col-6"><div class="form-label-dvd"><?= t('datum') ?></div><div class="calc-display mono" style="min-height:30px;font-size:.78rem"><?= $worker['posledni_datum'] ? date('d.m.Y', strtotime((string)$worker['posledni_datum'])) : '—' ?></div></div>
                    <div class="col-6"><div class="form-label-dvd"><?= t('cisty_cas') ?></div><div class="calc-display mono" style="min-height:30px;font-size:.78rem"><?= number_format((int)($worker['cisty_cas_min'] ?? 0)) ?> <?= t('unit_minutes') ?></div></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card-dvd h-100">
            <div class="card-header-dvd"><span class="s-num">02</span><span class="s-title"><?= t('records_title') ?></span></div>
            <div class="card-body-dvd">
                <div class="overflow-x">
                    <table class="tbl">
                        <thead><tr>
                            <th>ID</th><th><?= t('datum') ?></th><th><?= t('smena') ?></th><th><?= t('pracovisite') ?></th><th><?= t('nazev_dilu') ?></th><th><?= t('produced_ks') ?></th><th><?= t('nok_ks') ?></th><th><?= t('avg_oee') ?></th><th><?= t('downtime_min') ?></th><th class="no-print"><?= t('actions') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$records): ?>
                            <tr><td colspan="10" class="text-center py-5 text-muted-dvd"><?= t('no_data') ?></td></tr>
                        <?php else: foreach ($records as $record):
                            $oee = $record['oee_procent'] !== null ? round((float)$record['oee_procent'] * 100, 1) : null;
                        ?>
                            <tr data-href="<?= APP_URL ?>/admin/zapis_detail.php?id=<?= (int)$record['id'] ?>">
                                <td class="mono text-accent">#<?= (int)$record['id'] ?></td>
                                <td class="mono"><?= date('d.m.Y', strtotime((string)$record['datum'])) ?></td>
                                <td class="mono"><?= h($record['smena']) ?></td>
                                <td><?= h($record['kod_pracovisite'] ?? '') ?><br><small class="text-muted-dvd"><?= h($record['nazev_pracovisite'] ?? '') ?></small></td>
                                <td><?= h($record['nazev_dilu'] ?? '') ?></td>
                                <td class="mono text-accent"><?= number_format((int)$record['vyrobeno_ks']) ?></td>
                                <td class="mono <?= (int)$record['nok_ks'] > 0 ? 'text-accent2' : '' ?>"><?= number_format((int)$record['nok_ks']) ?></td>
                                <td class="mono"><?= $oee !== null ? $oee . '%' : '—' ?></td>
                                <td class="mono"><?= number_format((int)$record['prostoj_celkem_min']) ?> <?= t('unit_minutes') ?></td>
                                <td class="no-print"><a href="<?= APP_URL ?>/admin/zapis_detail.php?id=<?= (int)$record['id'] ?>" class="btn-icon" title="<?= t('detail') ?>"><i class="bi bi-eye"></i></a></td>
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
<?php require APP_ROOT . '/includes/layout_end.php'; ?>