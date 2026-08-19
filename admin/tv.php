<?php
// admin/tv.php – TV mode – live výrobní statistiky
require_once dirname(__DIR__) . '/includes/config.php';

// Daily widgets use selected date (default previous day); weekly overview remains anchored to current day.
$dnes = date('Y-m-d');
$selectedDate = get('datum', '');
$isValidSelectedDate = is_string($selectedDate)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)
    && strtotime($selectedDate) !== false;
$reportDate = $isValidSelectedDate ? $selectedDate : date('Y-m-d', strtotime('-1 day'));
$downtimeJoin = "LEFT JOIN (
    SELECT zapis_id,
        SUM(CASE WHEN kategorie='plan' THEN minuty ELSE 0 END) AS dt_plan,
        SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost') THEN minuty ELSE 0 END) AS dt_neplan,
        SUM(minuty) AS dt_total
    FROM zapisy_prostoje
    GROUP BY zapis_id
) zp ON zp.zapis_id=z.id";

// Pomocný SQL výraz pro vážené OEE přes více směn/záznamů
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

// Dnešní statistiky pro TV
$stat = db_row("SELECT COUNT(*) cnt, SUM(z.vyrobeno_ks) vyr, SUM(z.nok_ks) nok,
    ROUND(COALESCE($oeeSqlExpr, 0), 1) oee_avg, 
    SUM(COALESCE(zp.dt_total,0)) prostoj,
    SUM(z.vyrobeno_dobre_ks) dobre
    FROM zapisy z
    $downtimeJoin
    WHERE z.datum=?", [$reportDate]);

$topNok = db_all("SELECT nk.kod,nk.popis,SUM(zn.pocet) total
    FROM zapis_nok zn JOIN nok_kody nk ON nk.id=zn.nok_kod_id
    JOIN zapisy z ON z.id=zn.zapis_id WHERE z.datum=?
    GROUP BY nk.id ORDER BY total DESC LIMIT 5", [$reportDate]);

// Týdenní trend OEE pro TV graf
$weekOee = db_all("SELECT z.datum,
    ROUND(COALESCE($oeeSqlExpr, 0), 1) oee
    FROM zapisy z
    $downtimeJoin
    WHERE z.datum BETWEEN DATE_SUB(?,INTERVAL 6 DAY) AND ?
    GROUP BY z.datum ORDER BY z.datum", [$dnes,$dnes]);

$lastRecords = db_all("SELECT z.jmeno,z.nazev_dilu,z.smena,z.vyrobeno_ks,z.oee_procent
    FROM zapisy z WHERE z.datum=? ORDER BY z.id DESC LIMIT 8", [$reportDate]);

// JS translations
$jsLang = [
    'tv_goal_85' => t('tv_goal_85'),
];
$lang = $_SESSION['lang'] ?? 'cs';
$tvTitle = (string)t('tv_title');
$tvTitleFirst = function_exists('mb_substr') ? (string)mb_substr($tvTitle, 0, 1, 'UTF-8') : (string)substr($tvTitle, 0, 1);
$tvTitleRest = function_exists('mb_substr') ? (string)mb_substr($tvTitle, 1, null, 'UTF-8') : (string)substr($tvTitle, 1);
$langUrl = static function (string $code): string {
    $params = $_GET;
    $params['lang'] = $code;
    return '?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title><?= h(buildDocumentTitle(t('tv_title'))) ?></title>
<script>
(() => {
    try {
        const stored = localStorage.getItem('dvd_theme');
        const theme = (stored === 'light' || stored === 'dark') ? stored : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= urlencode(APP_VERSION) ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
window.LANG_TEXT = <?= json_encode($jsLang) ?>;
</script>
<style>
:root{--bg:#060810;--s:#0d1018;--s2:#131820;--b:#1a2030;--a:#00e5b0;--a2:#ff6b35;--a3:#5b8dee;--t:#e8eeff;--m:#4a5570;--w:#ffc107;--f:'IBM Plex Sans',sans-serif;--mo:'IBM Plex Mono',monospace}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;background:var(--bg);color:var(--t);font-family:var(--f);overflow:hidden}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:auto 1fr 1fr;gap:12px;padding:12px;height:100vh}
.card{background:var(--s);border:1px solid var(--b);border-radius:10px;padding:1rem 1.25rem;overflow:hidden}
.card-header{font-size:.6rem;text-transform:uppercase;letter-spacing:.12em;color:var(--m);margin-bottom:.6rem;font-weight:600}
.topbar{grid-column:1/-1;background:var(--s);border:1px solid var(--b);border-radius:12px;padding:8px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.tv-title{font-size:1.3rem;font-weight:700}
.tv-title-link,.tv-title-link:visited{color:inherit;text-decoration:none}
.tv-title-link:hover,.tv-title-link:focus{color:inherit;text-decoration:none;outline:none}
.tv-meta{font-size:.82rem;color:var(--muted)}
.tv-header-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
.live-badge{background:rgba(255,107,53,.2);border:1px solid var(--a2);color:var(--a2);font-family:var(--mo);font-size:.65rem;padding:.2rem .6rem;border-radius:4px;animation:pulse 2s infinite;white-space:nowrap}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.clock-tv{font-family:var(--mono);color:var(--accent);font-size:.98rem;white-space:nowrap}
.kiosk-tools{display:flex;align-items:center;gap:8px}
.kiosk-tools .theme-switch-wrap{margin-bottom:0}
.lang-link{color:var(--muted);text-decoration:none;font-size:.88rem;line-height:1}
.lang-link.active{color:var(--accent)}
.filter-inline{display:flex;align-items:center;gap:.4rem}
/* Big stat cards */
.stat-big .val{font-family:var(--mo);font-size:3.5rem;font-weight:700;line-height:1;color:var(--a)}
.stat-big .lbl{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--m);margin-top:.4rem}
.stat-big .sub{font-size:.75rem;color:var(--m);margin-top:.2rem}
/* OEE card */
.oee-big{text-align:center}
.oee-num{font-family:var(--mo);font-size:5rem;font-weight:700;line-height:1}
.oee-cil{font-size:.7rem;color:var(--m);margin-top:.3rem}
/* Table */
.tv-tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tv-tbl th{color:var(--m);font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;padding:.35rem .5rem;border-bottom:1px solid var(--b)}
.tv-tbl td{padding:.35rem .5rem;border-bottom:1px solid rgba(255,255,255,.04)}
/* Bar rows */
.nok-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem}
.nok-bar{flex:1;height:8px;background:var(--b);border-radius:4px;overflow:hidden}
.nok-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--a2),#ff3b00)}
/* Grid layout */
.span2{grid-column:span 2}
html[data-theme="light"]{--bg:#f4f7fb;--s:#ffffff;--s2:#f0f4fa;--b:#d7e0ed;--t:#1a2433;--m:#5d6f88;--a:#007f67;--a2:#d14b1f;--w:#b18400}
</style>
</head>
<body>
<div class="grid">

<!-- Top bar -->
<div class="topbar">
    <div>
        <div class="tv-title"><a class="tv-title-link" href="<?= APP_URL ?>/index.php"><?= h($tvTitleFirst) ?></a><?= h($tvTitleRest) ?></div>
        <div class="tv-meta"><?= date('d.m.Y', strtotime($reportDate)) ?></div>
    </div>
    <div class="tv-header-right">
        <form method="get" class="filter-inline">
            <input type="date" name="datum" value="<?= h($reportDate) ?>" max="<?= h($dnes) ?>" style="background:var(--s2);border:1px solid var(--b);color:var(--t);font-size:.72rem;padding:.18rem .35rem;border-radius:4px;min-width:140px" onchange="this.form.submit()">
            <button type="submit" style="background:transparent;border:1px solid var(--b);color:var(--m);font-size:.72rem;padding:.18rem .45rem;border-radius:4px;cursor:pointer"><?= t('filter') ?></button>
            <a href="tv.php" style="background:transparent;border:1px solid var(--b);color:var(--m);font-size:.72rem;padding:.18rem .45rem;border-radius:4px;text-decoration:none;white-space:nowrap">Dnes</a>
        </form>
        <span class="live-badge">● <?= h(t('tv_live')) ?></span>
        <div class="clock-tv"><span id="clock-time-tv"></span> <span id="clock-date-tv"></span></div>
        <div class="kiosk-tools">
            <div class="theme-switch-wrap" title="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
                <label class="theme-pill-switch" for="theme-toggle-checkbox">
                    <input type="checkbox" id="theme-toggle-checkbox" aria-label="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
                    <span class="theme-pill-track">
                        <span class="theme-pill-icon theme-pill-icon-sun" aria-hidden="true"><i class="bi bi-brightness-high"></i></span>
                        <span class="theme-pill-icon theme-pill-icon-moon" aria-hidden="true"><i class="bi bi-moon"></i></span>
                        <span class="theme-pill-thumb" id="theme-pill-thumb" aria-hidden="true"></span>
                    </span>
                </label>
            </div>
            <a class="lang-link <?= $lang === 'cs' ? 'active' : '' ?>" href="<?= h($langUrl('cs')) ?>">CZ</a>
            <a class="lang-link <?= $lang === 'en' ? 'active' : '' ?>" href="<?= h($langUrl('en')) ?>">EN</a>
            <a class="lang-link <?= $lang === 'uk' ? 'active' : '' ?>" href="<?= h($langUrl('uk')) ?>">UA</a>
            <?php if (isLoggedIn()): ?>
            <a href="logout.php" class="kiosk-logout-btn"><i class="bi bi-box-arrow-right"></i><?= h(t('nav_logout')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Vyrobeno -->
<div class="card stat-big">
    <div class="card-header"><?= t('tv_today') ?> – <?= t('tv_produced') ?></div>
    <div class="val"><?= number_format((int)($stat['vyr']??0)) ?></div>
    <div class="lbl"><?= t('tv_pcs_total') ?></div>
    <div class="sub"><?= t('tv_ok_output') ?> <strong style="color:var(--a)"><?= number_format((int)($stat['dobre']??0)) ?></strong></div>
</div>

<!-- NOK -->
<div class="card stat-big">
    <div class="card-header"><?= t('tv_nok_pcs') ?></div>
    <div class="val" style="color:var(--a2)"><?= number_format((int)($stat['nok']??0)) ?></div>
    <div class="lbl"><?= t('tv_nok_defects') ?></div>
    <?php if(($stat['vyr']??0)>0): ?>
    <div class="sub"><?= t('tv_nok_rate') ?> <strong style="color:var(--a2)"><?= round($stat['nok']/$stat['vyr']*100,2) ?>%</strong></div>
    <?php endif;?>
</div>

<!-- OEE -->
<div class="card oee-big">
    <div class="card-header"><?= t('tv_oee_today') ?></div>
    <?php $o=$stat['oee_avg']??0; $oc=$o>=85?'var(--a)':($o>=70?'var(--w)':'var(--a2)');?>
    <div class="oee-num" style="color:<?= $oc ?>"><?= $o?round($o,1).'%':'—' ?></div>
    <div class="oee-cil"><?= t('tv_goal') ?> &nbsp;|&nbsp;
        <?= $o>=85?'✓ '.t('tv_meet_goal'):($o>=70?'⚠ '.t('tv_below_goal'):'✗ '.t('tv_far_goal')) ?>
    </div>
    <!-- mini gauge bar -->
    <div style="margin-top:.75rem;height:10px;background:var(--b);border-radius:5px;overflow:hidden">
        <div style="height:100%;width:<?= min(100,$o) ?>%;background:<?= $oc ?>;border-radius:5px;transition:width 1s"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:.2rem;font-size:.6rem;color:var(--m)"><span><?= t('tv_legend_0') ?></span><span style="color:var(--w)"><?= t('tv_legend_85') ?></span><span><?= t('tv_legend_100') ?></span></div>
</div>

<!-- Týdenní OEE chart -->
<div class="card span2">
    <div class="card-header"><?= t('tv_week') ?> – <?= t('tv_last_7_days') ?></div>
    <canvas id="chart-week" height="60"></canvas>
</div>

<!-- Top NOK -->
<div class="card">
    <div class="card-header"><?= t('tv_top_errors') ?></div>
    <?php if(empty($topNok)): ?><p style="color:var(--m);font-size:.78rem"><?= t('tv_no_nok_today') ?></p>
    <?php else:
        $maxN=max(array_column($topNok,'total'));
        foreach($topNok as $n): $pct=$maxN>0?round($n['total']/$maxN*100):0;?>
    <div class="nok-row">
        <span style="font-family:var(--mo);font-size:.65rem;color:var(--a2);min-width:2.5rem"><?= $n['kod'] ?></span>
        <div class="nok-bar"><div class="nok-fill" style="width:<?= $pct ?>%"></div></div>
        <span style="font-family:var(--mo);font-size:.8rem;font-weight:600;color:var(--a2);min-width:35px;text-align:right"><?= $n['total'] ?></span>
    </div>
    <?php endforeach; endif;?>
</div>

<!-- Dnešní záznamy -->
<div class="card span2">
    <div class="card-header"><?= t('tv_today_records') ?></div>
    <table class="tv-tbl">
        <thead><tr><th><?= t('pracovnik') ?></th><th><?= t('nazev_dilu') ?></th><th><?= t('smena') ?></th><th><?= t('vyrobeno') ?></th><th>OEE</th></tr></thead>
        <tbody>
        <?php if(empty($lastRecords)): ?>
        <tr><td colspan="5" style="color:var(--m);padding:.75rem .5rem"><?= t('no_data') ?></td></tr>
        <?php else: foreach($lastRecords as $r):
            $ro=$r['oee_procent']!==null?round($r['oee_procent']*100,1):null;
            $rc=$ro!==null?($ro>=85?'var(--a)':($ro>=70?'var(--w)':'var(--a2)')):'var(--m)';?>
        <tr>
            <td><?= h($r['jmeno']??'—') ?></td>
            <td style="font-size:.7rem;color:var(--m)"><?= h($r['nazev_dilu']??'—') ?></td>
            <td style="font-family:var(--mo);font-size:.72rem"><?= h($r['smena']) ?></td>
            <td style="font-family:var(--mo);font-weight:600;color:var(--a)"><?= number_format((int)$r['vyrobeno_ks']) ?></td>
            <td style="font-family:var(--mo);font-weight:700;color:<?= $rc ?>"><?= $ro!==null?$ro.'%':'—' ?></td>
        </tr>
        <?php endforeach; endif;?>
        </tbody>
    </table>
</div>

<!-- Prostoje dnes -->
<div class="card stat-big">
    <div class="card-header"><?= t('tv_downtime_today') ?></div>
    <div class="val" style="color:var(--a3)"><?= number_format((int)($stat['prostoj']??0)) ?></div>
    <div class="lbl"><?= t('tv_min_total') ?></div>
    <div class="sub"><?= $stat['cnt'] ?> <?= t('tv_records_count') ?></div>
</div>

</div>

<script>
(() => {
    const LOCALE = '<?= $lang === 'uk' ? 'uk-UA' : ($lang === 'en' ? 'en-GB' : 'cs-CZ') ?>';
    const timeEl = document.getElementById('clock-time-tv');
    const dateEl = document.getElementById('clock-date-tv');
    const renderClock = () => {
        const n = new Date();
        if (timeEl) timeEl.textContent = n.toLocaleTimeString(LOCALE, {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        if (dateEl) dateEl.textContent = n.toLocaleDateString(LOCALE, {day:'2-digit', month:'2-digit', year:'numeric'});
    };
    renderClock();
    setInterval(renderClock, 1000);

    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
        const toggle = document.getElementById('theme-toggle-checkbox');
        if (toggle) toggle.checked = (theme === 'light');
    };
    applyTheme(document.documentElement.getAttribute('data-theme') || 'dark');
    const toggle = document.getElementById('theme-toggle-checkbox');
    if (toggle) toggle.addEventListener('change', () => applyTheme(toggle.checked ? 'light' : 'dark'));
})();

const wd = <?= json_encode($weekOee) ?>;
if(wd.length) {
new Chart(document.getElementById('chart-week'),{
    type:'bar',
    data:{
        labels:wd.map(d=>d.datum),
        datasets:[
            {type:'bar',label:'OEE %',data:wd.map(d=>parseFloat(d.oee)||0),
             backgroundColor:wd.map(d=>{const v=parseFloat(d.oee)||0;return v>=85?'rgba(0,229,176,.6)':v>=70?'rgba(255,193,7,.6)':'rgba(255,107,53,.6)'})},
            {type:'line',label:window.LANG_TEXT.tv_goal_85,data:wd.map(()=>85),borderColor:'rgba(255,193,7,.5)',borderDash:[5,3],pointRadius:0,borderWidth:1}
        ]
    },
    options:{responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            x:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#4a5570',font:{size:10}}},
            y:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#4a5570',font:{size:10}},min:0,max:110}
        }
    }
});
}
</script>
</body>
</html>
