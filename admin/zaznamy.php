<?php
// admin/zaznamy.php – Záznamový list se všemi filtry
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read', 'writer']);
$pageTitle  = t('records_title');
$activePage = 'zaznamy';

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
               z.vyrobeno_ks,z.nok_ks,z.vyrobeno_dobre_ks,z.k_posouzeni,
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
        SUM(GREATEST(0, z.vyrobeno_ks - z.nok_ks))
        / NULLIF(SUM(z.vyrobeno_ks), 0)
    )
)) * 100";

// ── Statistiky ───────────────────────────────────────────────
$stat = db_row("SELECT COUNT(*) cnt, SUM(z.vyrobeno_ks) vyr, SUM(z.nok_ks) nok,
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
<div class="topbar">
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

<!-- Statistiky -->
<div class="row g-3 mb-4 no-print">
    <?php $stats = [
        [$stat['cnt']??0, t('record_id').' / '.t('records_btn'), 'bi-file-earmark-text'],
        [number_format((int)($stat['vyr']??0)), t('produced_ks'), 'bi-boxes'],
        [number_format((int)($stat['nok']??0)), t('nok_ks'), 'bi-x-octagon'],
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
            <a href="zaznamy.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
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
            <th>ID</th><th><?= t('typ') ?></th><th><?= t('datum') ?></th><th><?= t('column_cw') ?></th>
            <th><?= t('pracovisite') ?></th><th><?= t('nazev_dilu') ?></th>
            <th><?= t('smena') ?></th><th><?= t('group') ?></th><th><?= t('pracovnik') ?></th>
            <th><?= t('vyrobeno') ?></th><th><?= t('nok_ks') ?></th><th><?= t('cisty_cas') ?></th>
            <th><?= t('column_oee') ?></th><th><?= t('prostoje') ?></th><th class="no-print"><?= t('actions') ?></th>
        </tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="15" class="text-center py-5 text-muted-dvd"><?= t('no_data') ?></td></tr>
        <?php else: foreach ($rows as $r):
            $oee = $r['oee_procent'] !== null ? round($r['oee_procent']*100,1) : null;
            $oc  = $oee!==null ? ($oee>=85?'good':($oee>=70?'warn':'bad')) : '';
        ?>
        <tr data-href="<?= APP_URL ?>/admin/zapis_detail.php?id=<?= $r['id'] ?>">
            <td class="mono text-accent">#<?= $r['id'] ?></td>
            <td><span class="badge-<?= $r['typ'] ?>"><?= strtoupper($r['typ']) ?></span></td>
            <td class="mono"><?= date('d.m.Y',strtotime($r['datum'])) ?></td>
            <td class="mono text-muted-dvd"><?= $r['cw'] ?></td>
            <td><?= h($r['kod_pracovisite']??'') ?><br><small class="text-muted-dvd"><?= h($r['nazev_pracovisite']??'') ?></small></td>
            <td><?= h($r['nazev_dilu']??'') ?><br><small class="text-muted-dvd"><?= h(($r['zakaznik']??'').' / '.($r['projekt']??'')) ?></small></td>
            <td class="mono"><?= h($r['smena']) ?></td>
            <td class="mono text-center"><?= h($r['skupina'] ?: '—') ?></td>
            <td><?= h($r['jmeno']??'') ?></td>
            <td class="mono text-accent"><?= number_format((int)$r['vyrobeno_ks']) ?></td>
            <td class="mono <?= $r['nok_ks']>0?'text-accent2':'' ?>"><?= number_format((int)$r['nok_ks']) ?></td>
            <td class="mono"><?= $r['cisty_cas_min']??'—' ?></td>
            <td>
                <?php if ($oee!==null): ?>
                <span class="mono" style="font-size:.78rem"><?= $oee ?>%</span>
                <div class="oee-bar-wrap"><div class="oee-bar-fill oee-<?= $oc ?>" style="width:<?= min(100,$oee) ?>%"></div></div>
                <?php else: ?><span class="text-muted-dvd">—</span><?php endif; ?>
            </td>
            <td class="mono"><?= (int)$r['prostoj_celkem_min'] ?> <?= t('unit_minutes') ?></td>
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
