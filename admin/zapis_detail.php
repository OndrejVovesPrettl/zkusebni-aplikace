<?php
// admin/zapis_detail.php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'read']);
$id = (int)get('id');
if (!$id) redirect(APP_URL . '/admin/zaznamy.php');

$z = db_row('SELECT * FROM zapisy WHERE id=?', [$id]);
if (!$z) { http_response_code(404); die(t('record_not_found')); }

$workerIdJoin = "LEFT JOIN pracovnici p_interni ON CAST(p_interni.interni_id AS UNSIGNED) = zp.pracovnik_id
LEFT JOIN pracovnici p_id ON p_id.id = CAST(zp.jmeno AS UNSIGNED)";
$workerNameJoin = "LEFT JOIN (
    SELECT MIN(id) AS id, LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci AS jmeno_key, MIN(interni_id) AS interni_id, MIN(jmeno) AS jmeno
    FROM pracovnici
    WHERE COALESCE(TRIM(jmeno), '') <> ''
    GROUP BY LOWER(TRIM(jmeno)) COLLATE utf8mb4_unicode_ci
) pn ON pn.jmeno_key = LOWER(TRIM(zp.jmeno)) COLLATE utf8mb4_unicode_ci";

$workers  = db_all("SELECT
    zp.id,
    zp.zapis_id,
    zp.poradi,
    zp.pracovni_pozice,
    zp.jmeno AS worker_value,
    zp.pracovnik_id AS worker_interni_id,
    COALESCE(NULLIF(p_id.jmeno, ''), NULLIF(p_interni.jmeno, ''), NULLIF(pn.jmeno, ''), zp.jmeno) AS display_jmeno,
    COALESCE(p_id.interni_id, p_interni.interni_id, pn.interni_id) AS resolved_interni_id
    FROM zapis_pracovnici zp
    $workerIdJoin
    $workerNameJoin
    WHERE zp.zapis_id=?
    ORDER BY zp.poradi, zp.id", [$id]);
$prostoje = db_all('SELECT zp.id, zp.typ_kod, zp.cas_od, zp.cas_do, zp.minuty, zp.pocet, zp.poznamka, pt.popis AS typ_popis
    FROM zapisy_prostoje zp
    LEFT JOIN prostoj_typy pt ON pt.kod=zp.typ_kod
    WHERE zp.zapis_id=? AND zp.kategorie=\'tech\' AND zp.typ_kod<>\'TECH_SUM\'
    ORDER BY zp.id', [$id]);
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

$nok      = db_all('SELECT zn.*, nk.kod, nk.popis FROM zapis_nok zn JOIN nok_kody nk ON nk.id=zn.nok_kod_id WHERE zn.zapis_id=? AND zn.pocet>0 ORDER BY nk.kod', [$id]);
$hodiny   = db_all('SELECT * FROM hodinove_sledovani WHERE zapis_id=? ORDER BY hodina_od', [$id]);

$pageTitle  = t('record_detail').' #'.$id;
$activePage = 'zaznamy';
require APP_ROOT . '/includes/layout.php';

$oee = $z['oee_procent']!==null ? round($z['oee_procent']*100,1) : null;
$workerSummary = $workers ? implode(', ', array_map(static fn(array $worker): string => (string)$worker['display_jmeno'], $workers)) : ($z['jmeno'] ?? '');
$positionSummary = $workers
    ? implode(', ', array_values(array_unique(array_filter(array_map(static fn(array $worker): ?string => $worker['pracovni_pozice'], $workers)))))
    : ($z['pracovni_pozice'] ?? '');
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <a href="zaznamy.php" class="btn-icon" title="<?= t('back') ?>"><i class="bi bi-arrow-left"></i></a>
        <span class="topbar-title"><?= t('record_detail') ?> <span class="mono text-accent">#<?= $id ?></span></span>
        <span class="badge-<?= $z['typ'] ?>"><?= strtoupper($z['typ']) ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem"><i class="bi bi-printer me-1"></i><?= t('print') ?></button>
        <?php if (isLoggedIn()): ?>
        <?php if (!hasOnlyRole('read')): ?>
        <a href="zapis_edit.php?id=<?= $id ?>" class="btn-dvd btn-dvd-sm"><i class="bi bi-pencil me-1"></i><?= t('edit') ?></a>
        <?php endif; ?>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="content">

<!-- Print header -->
<div class="d-none d-print-block mb-3" style="border-bottom:2px solid #000;padding-bottom:.5rem">
    <h5 style="margin:0;font-size:13pt">Záznam směny #<?= $id ?> – <?= h($z['nazev_dilu']??'') ?></h5>
    <div style="font-size:9pt;color:#444"><?= date('d.m.Y',strtotime($z['datum'])) ?> | <?= h($z['smena']) ?> | Skupina <?= h($z['skupina'] ?? '—') ?> | <?= h($workerSummary ?: '—') ?></div>
</div>

<div class="row g-3">
<!-- Identifikace -->
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">01</span><span class="s-title"><?= t('identification') ?></span></div>
    <div class="card-body-dvd">
    <div class="row g-2" style="font-size:.82rem">
        <?php $fields = [
            [t('label_customer'),'zakaznik'],[t('label_project'),'projekt'],[t('label_version'),'verze'],
            [t('label_workplace'),'kod_pracovisite'],[t('label_workplace_name'),'nazev_pracovisite'],
            [t('label_part_name'),'nazev_dilu'],[t('label_sap_number'),'sap_cislo'],
            [t('label_cost_center'),'nakladove_stredisko'],[t('label_final_type'),'final_typ'],
        ]; foreach ($fields as [$lbl,$key]): ?>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="form-label-dvd"><?= $lbl ?></div>
            <div class="calc-display" style="min-height:30px;font-size:.78rem"><?= h((string)($z[$key]??'—')) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($z['poznamka_zapisujiciho']): ?>
        <div class="col-12"><div class="form-label-dvd"><?= t('note_operator_detail') ?></div>
            <div style="background:rgba(255,255,255,.04);border-radius:6px;padding:.4rem .7rem;font-size:.8rem"><?= h($z['poznamka_zapisujiciho']) ?></div></div>
        <?php endif; ?>
    </div>
    </div>
</div>
</div>

<!-- Směna + Výroba -->
<div class="col-12 col-lg-6">
<div class="card-dvd h-100">
    <div class="card-header-dvd"><span class="s-num">02</span><span class="s-title"><?= t('shift_times') ?></span></div>
    <div class="card-body-dvd">
    <div class="row g-2" style="font-size:.82rem">
        <?php $sf = [
            [t('label_date'), date('d.m.Y',strtotime($z['datum'])).' (KT '.$z['cw'].')'],
            [t('label_shift'), $z['smena']], ['Skupina', $z['skupina'] ?? '—'], [t('label_arrival'), $z['cas_prichodu'] ? date('H:i',strtotime($z['cas_prichodu'])) : '—'],
            [t('label_departure'), $z['cas_odchodu'] ? date('H:i',strtotime($z['cas_odchodu'])) : '—'],
            [t('label_work_time'), ($z['pracovni_doba_min']??'—').' min'],
            [t('label_break'), ($z['pauza_min']??0).' min'],
            [t('label_overtime'), ($z['prestas_min']??0).' min'],
            [t('label_net_time'), ($z['cisty_cas_min']??'—').' min'],
            [t('label_worker'), $workerSummary ?: '—'],
            [t('label_position'), $positionSummary ?: '—'],
            [t('label_recorded_by'), $z['zapsal']??'—'],
        ]; foreach ($sf as [$l,$v]): ?>
        <div class="col-6"><div class="form-label-dvd"><?= $l ?></div><div class="calc-display" style="min-height:30px;font-size:.78rem"><?= h($v) ?></div></div>
        <?php endforeach; ?>
        <?php if ($workers): ?>
        <div class="col-12">
            <div class="form-label-dvd"><?= t('label_worker') ?> / <?= t('label_position') ?></div>
            <div style="background:rgba(255,255,255,.04);border-radius:6px;padding:.55rem .7rem;font-size:.8rem">
                <?php foreach ($workers as $worker): ?>
                <div style="display:flex;justify-content:space-between;gap:1rem;padding:.15rem 0;border-bottom:1px solid rgba(255,255,255,.06)">
                    <span><?= h($worker['display_jmeno']) ?></span>
                    <span style="color:var(--muted)"><?= h($worker['pracovni_pozice'] ?: '—') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    </div>
</div>
</div>

<!-- Výroba + OEE -->
<div class="col-12 col-lg-6">
<div class="card-dvd h-100">
    <div class="card-header-dvd"><span class="s-num">03</span><span class="s-title"><?= t('title_production_oee') ?></span></div>
    <div class="card-body-dvd">
    <div class="row g-2 mb-3" style="font-size:.82rem">
        <?php 
        $vf = [
            [t('label_produced_total'),number_format((int)$z['vyrobeno_ks']).' ks'],
            [t('label_nok_pieces'), number_format((int)$z['nok_ks']).' ks'],
        ];
        if ((int)$z['k_posouzeni'] > 0) {
            $vf[] = [t('label_to_assess'), number_format((int)$z['k_posouzeni']).' ks'];
        }
        $vf[] = [t('label_produced_ok'), number_format((int)$z['vyrobeno_dobre_ks']).' ks'];
        $vf[] = [t('tech_short') ?? 'Tech', number_format((int)$z['tech']).' ks'];
        $vf[] = [t('blok_short') ?? 'Blok', number_format((int)$z['blok']).' ks'];
        $vf[] = [t('tnz_short') ?? 'TNZ', number_format((int)$z['tnz']).' ks'];
        $vf[] = [t('label_standard_pieces'), $z['norma_ks'] ? number_format($z['norma_ks'],4) : '—'];
        $vf[] = [t('label_cycle_time'), $z['cycle_time_min'] ? number_format($z['cycle_time_min'],4).' min' : '—'];

        foreach ($vf as [$l,$v]): ?>
        <div class="col-6"><div class="form-label-dvd"><?= $l ?></div>
            <div class="calc-display" style="min-height:30px;font-size:.78rem"><?= $v ?></div></div>
        <?php endforeach; ?>
    </div>
    <!-- OEE bars -->
    <?php foreach ([
        [t('label_availability'),$z['oee_dostupnost'],'var(--accent)'],
        [t('label_performance'),     $z['oee_vykon'],     'var(--accent3)'],
        [t('label_quality'),   $z['oee_kvalita'],   'var(--warn)'],
    ] as [$l,$v,$c]): $pct = $v!==null?round($v*100,1):null; ?>
    <div class="d-flex align-items-center gap-2 mb-2">
        <span style="width:120px;font-size:.68rem;color:var(--muted);text-transform:uppercase"><?= $l ?></span>
        <div class="oee-bar-wrap flex-grow-1"><div class="oee-bar-fill" style="width:<?= $pct??0 ?>%;background:<?= $c ?>"></div></div>
        <span class="mono" style="font-size:.8rem;color:<?= $c ?>;min-width:45px;text-align:right"><?= $pct!==null?$pct.'%':'—' ?></span>
    </div>
    <?php endforeach; ?>
    <div style="background:rgba(0,229,176,.08);border:1px solid rgba(0,229,176,.2);border-radius:6px;padding:.65rem 1rem;display:flex;align-items:center;justify-content:space-between;margin-top:.75rem">
        <div><div style="font-size:.65rem;color:var(--muted);text-transform:uppercase"><?= t('label_oee_total') ?></div>
            <div class="mono" style="font-size:1.5rem;font-weight:600;color:var(--accent)"><?= $oee!==null?$oee.'%':'—' ?></div></div>
        <div style="text-align:right;font-size:.7rem;color:<?= $oee!==null?($oee>=85?'var(--accent)':($oee>=70?'var(--warn)':'var(--accent2)')):'var(--muted)' ?>">
            <?= $oee!==null?($oee>=85?'✓ '.t('status_above_target'):($oee>=70?'⚠ '.t('status_below_target'):'✗ '.t('status_far_below_target'))):'—' ?>
        </div>
    </div>
    </div>
</div>
</div>

<!-- Prostoje souhrn -->
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">04</span><span class="s-title"><?= t('title_downtime_summary') ?></span></div>
    <div class="card-body-dvd">
    <div class="row g-2 text-center">
        <?php $ps = [
            ['P1',$prostCodeMap['P1']],['P2',$prostCodeMap['P2']],['P3',$prostCodeMap['P3']],
            ['P4',$prostCodeMap['P4']],['P5',$prostCodeMap['P5']],['P6',$prostCodeMap['P6']],
            ['P7',$prostCodeMap['P7']],['P8',$prostCodeMap['P8']],['P9',$prostCodeMap['P9']],
            ['P10',$prostCodeMap['P10']],['O1',$prostCodeMap['O1']],
            ['L1',$prostCodeMap['L1']],['L2',$prostCodeMap['L2']],
            ['V1',$prostCodeMap['V1']],['V2',$prostCodeMap['V2']],['V3',$prostCodeMap['V3']],
            ['Q1',$prostCodeMap['Q1']],['Q2',$prostCodeMap['Q2']],['Q3',$prostCodeMap['Q3']],['Q4',$prostCodeMap['Q4']],
        ]; foreach ($ps as [$lbl,$val]): if (!$val) continue; ?>
        <div class="col-4 col-md-2 col-xl-1">
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.4rem">
                <div class="mono" style="font-size:.62rem;color:var(--muted)"><?= $lbl ?></div>
                <div class="mono" style="font-size:1rem;font-weight:600;color:var(--accent3)"><?= $val ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="row g-2 text-center mt-2">
        <?php foreach ([
            [t('label_downtime_technical'),(int)($prostSummary['tech'] ?? 0),'accent'],
            [t('label_downtime_production'),(int)($prostSummary['vyr'] ?? 0),'accent3'],
            [t('label_downtime_quality'),(int)($prostSummary['kval'] ?? 0),'warn'],
            [t('label_downtime_logistics'),(int)($prostSummary['log'] ?? 0),'accent2'],
            [t('label_downtime_other'),(int)($prostSummary['ost'] ?? 0),'muted'],
            [t('label_downtime_planned'),(int)($prostSummary['plan'] ?? 0),'accent3'],
            [t('label_downtime_unplanned'),(int)($prostSummary['nepl'] ?? 0),'accent2'],
            [t('label_downtime_total'),(int)($prostSummary['celkem'] ?? 0),'accent'],
        ] as [$l,$v,$c]): ?>
        <div class="col-6 col-md-3 col-xl-1">
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.4rem">
                <div style="font-size:.6rem;color:var(--muted)"><?= $l ?></div>
                <div class="mono" style="font-size:1.05rem;font-weight:600;color:var(--<?= $c ?>)"><?= (int)$v ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
</div>

<!-- Technické prostoje -->
<?php if (!empty($prostoje)): ?>
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">05</span><span class="s-title"><?= t('title_technical_downtime') ?></span></div>
    <div class="card-body-dvd p-0">
    <div class="overflow-x">
    <table class="ptbl">
        <thead><tr><th><?= t('code') ?></th><th><?= t('description') ?></th><th><?= t('from') ?></th><th><?= t('to') ?></th><th><?= t('minutes') ?></th><th><?= t('count') ?></th><th><?= t('note_cz') ?></th></tr></thead>
        <tbody>
        <?php foreach ($prostoje as $p): ?>
        <tr>
            <td class="mono text-accent"><?= h($p['typ_kod']) ?></td>
            <td><?= h($p['typ_popis']??'') ?></td>
            <td class="mono"><?= $p['cas_od']??'—' ?></td>
            <td class="mono"><?= $p['cas_do']??'—' ?></td>
            <td class="mono"><?= $p['minuty'] ?></td>
            <td class="mono"><?= $p['pocet'] ?></td>
            <td><?= h($p['poznamka']??'') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
</div>
<?php endif; ?>

<!-- NOK záznamy -->
<?php if (!empty($nok)): ?>
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">06</span><span class="s-title"><?= t('title_nok_records') ?></span></div>
    <div class="card-body-dvd p-0">
    <div class="overflow-x">
    <table class="ptbl">
        <thead><tr><th><?= t('code') ?></th><th><?= t('description') ?></th><th><?= t('count') ?></th></tr></thead>
        <tbody>
        <?php foreach ($nok as $n): ?>
        <tr>
            <td class="mono text-accent2"><?= $n['kod'] ?></td>
            <td><?= h($n['popis']) ?></td>
            <td class="mono" style="font-weight:600"><?= number_format($n['pocet']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
</div>
<?php endif; ?>

<!-- Hodinové sledování -->
<?php if (!empty($hodiny)): ?>
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">07</span><span class="s-title"><?= t('title_hourly_monitoring') ?></span></div>
    <div class="card-body-dvd">
    <div class="hourly-grid">
        <?php foreach ($hodiny as $h): ?>
        <div class="hour-card">
            <div class="hour-label"><?= h($h['hodina_od']) ?></div>
            <div class="row g-1" style="font-size:.75rem">
                <div class="col-4"><div style="color:var(--muted);font-size:.6rem"><?= t('hourly_produced') ?></div><div class="mono"><?= $h['vyrobeno'] ?></div></div>
                <div class="col-4"><div style="color:var(--muted);font-size:.6rem">P</div><div class="mono"><?= $h['p_minuty'] ?></div></div>
                <div class="col-4"><div style="color:var(--muted);font-size:.6rem">T</div><div class="mono"><?= $h['t_minuty'] ?></div></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
</div>
<?php endif; ?>

<!-- Poznámky -->
<?php if ($z['poznamky']): ?>
<div class="col-12">
<div class="card-dvd">
    <div class="card-header-dvd"><span class="s-num">08</span><span class="s-title"><?= t('notes') ?></span></div>
    <div class="card-body-dvd" style="font-size:.85rem"><?= nl2br(h($z['poznamky'])) ?></div>
</div>
</div>
<?php endif; ?>

</div><!-- row -->

<div class="mt-3 d-flex gap-2 no-print">
    <a href="zaznamy.php" class="btn-outline"><i class="bi bi-arrow-left me-1"></i><?= t('back') ?></a>
    <button onclick="window.print()" class="btn-dvd btn-dvd-sm"><i class="bi bi-printer me-1"></i><?= t('print') ?> – <?= t('print_shift') ?></button>
</div>

</div>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>