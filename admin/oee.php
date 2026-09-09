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

// Pomocný SQL výraz pro vážené výpočty OEE přes více záznamů současně
$oeeSqlExpr = "LEAST(1.0, GREATEST(0.0,
    -- Dostupnost (A) = Skutečný čas běhu / Plánovaný čas provozu
    (
        SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0) - COALESCE(zp.dt_neplan, 0)))
        / NULLIF(SUM(GREATEST(0, z.pracovni_doba_min - z.pauza_min - COALESCE(zp.dt_plan, 0))), 0)
    )
    *
    -- Výkonnost (P) = Vyrobeno / Cílová výroba za skutečný čas běhu
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
    -- Kvalita (Q) = Dobré ks / Vyrobené ks
    (
        SUM(GREATEST(0, z.vyrobeno_ks - z.nok_ks))
        / NULLIF(SUM(z.vyrobeno_ks), 0)
    )
)) * 100";

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
$topNok = db_all("SELECT nk.kod, nk.popis, SUM(zn.pocet) total
    FROM zapis_nok zn JOIN nok_kody nk ON nk.id=zn.nok_kod_id
    JOIN zapisy z ON z.id=zn.zapis_id $w GROUP BY nk.id ORDER BY total DESC LIMIT 10", $params);
if (empty($topNok)) {
    $nokTotal = (int)db_val("SELECT COALESCE(SUM(z.nok_ks),0) FROM zapisy z $w", $params);
    if ($nokTotal > 0) {
        $topNok = [[
            'kod' => 'SUM',
            'popis' => t('nok_ks').' (bez rozpisu kodu)',
            'total' => $nokTotal,
        ]];
    }
}

// Celkové stats
$stat = db_row("SELECT COUNT(*) cnt, SUM(z.vyrobeno_ks) vyr, SUM(z.nok_ks) nok,
    ROUND(COALESCE($oeeSqlExpr, 0), 2) AS oee_avg,
    SUM(COALESCE(zp.dt_total,0)) prostoj
    FROM zapisy z $downtimeJoin $w", $params);

$pracovisteList = getPracovisteList();

// Sync state
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<div class="topbar">
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

<div class="content">

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
        <span class="mono" style="font-size:.8rem;font-weight:600;color:var(--accent2);min-width:40px;text-align:right"><?= number_format($nk['total']) ?></span>
    </div>
    <?php endforeach; endif;?>
    </div>
</div>
</div>

</div><!-- row pracoviste+nok -->

<?php
$pCelkem = (float)($prostoje['celkem'] ?? 0);
$pRows = [
    ['key'=>'tech',  'label'=>t('downtime_tech'),     'color'=>'#ff6b35'],
    ['key'=>'vyr',   'label'=>t('downtime_prod'),     'color'=>'#ffc107'],
    ['key'=>'kval',  'label'=>t('downtime_qual'),     'color'=>'#5b8dee'],
    ['key'=>'log',   'label'=>t('downtime_logistic'), 'color'=>'#00e5b0'],
    ['key'=>'ost',   'label'=>t('downtime_other'),    'color'=>'#a78bfa'],
    ['key'=>'plan',  'label'=>t('downtime_planned'),  'color'=>'#4ade80'],
];
?>

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
</div><!-- content -->

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
