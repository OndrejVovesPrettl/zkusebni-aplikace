<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'writer']);

$targetCisloIds = [128, 129, 131, 135, 81, 83, 145, 146, 119, 183, 180, 153, 148, 184];
$today = new DateTimeImmutable('today');
$start = $today->sub(new DateInterval('P6D'));
$dateFrom = $start->format('Y-m-d');
$dateTo = $today->format('Y-m-d');

$dayNamesByLang = [
    'cs' => [1 => 'Pondeli', 2 => 'Utery', 3 => 'Streda', 4 => 'Ctvrtek', 5 => 'Patek', 6 => 'Sobota', 7 => 'Nedele'],
    'en' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
    'uk' => [1 => 'Ponedilok', 2 => 'Vivtorok', 3 => 'Sereda', 4 => 'Chetver', 5 => 'Piatnytsia', 6 => 'Subota', 7 => 'Nedilia'],
];
$lang = $_SESSION['lang'] ?? 'cs';
$dayNames = $dayNamesByLang[$lang] ?? $dayNamesByLang['cs'];

$days = [];
$dayKeys = [];
for ($i = 0; $i < 7; $i++) {
    $d = $start->modify('+' . $i . ' day');
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
$lang = $_SESSION['lang'] ?? 'cs';
$tvTitle = (string)t('kt_report_2_title');
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
<title><?= h(buildDocumentTitle(t('kt_report_2_title') . ' TV')) ?></title>
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
<style>
:root{--bg:#060810;--s:#0d1018;--s2:#131820;--b:#1a2030;--a:#00e5b0;--a2:#ff6b35;--a3:#5b8dee;--t:#e8eeff;--m:#4a5570;--w:#ffc107;--f:'IBM Plex Sans',sans-serif;--mo:'IBM Plex Mono',monospace}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;background:var(--bg);color:var(--t);font-family:var(--f);overflow:auto}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:auto auto 1fr;gap:12px;padding:12px;min-height:100vh;min-width:1200px}
.card{background:var(--s);border:1px solid var(--b);border-radius:10px;padding:1rem 1.25rem;overflow:hidden}
.card-header{font-size:.6rem;text-transform:uppercase;letter-spacing:.12em;color:var(--m);margin-bottom:.6rem;font-weight:600}
.topbar{grid-column:1/-1;background:var(--s);border:1px solid var(--b);border-radius:12px;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px}
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
.kiosk-logout-btn{display:inline-flex;align-items:center;gap:.3rem;border:1px solid var(--border2);background:rgba(255,255,255,.03);color:var(--text);padding:.24rem .5rem;border-radius:8px;font-size:.72rem;line-height:1;cursor:pointer;text-decoration:none}
.kiosk-logout-btn:hover{border-color:var(--accent);color:var(--accent)}
.stat-big .val{font-family:var(--mo);font-size:3.2rem;font-weight:700;line-height:1;color:var(--a)}
.stat-big .lbl{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--m);margin-top:.4rem}
.stat-big .sub{font-size:.75rem;color:var(--m);margin-top:.2rem}
.tv-tbl{width:100%;border-collapse:collapse;font-size:.9rem}
.tv-tbl th{color:var(--m);font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;padding:.45rem .6rem;border-bottom:1px solid var(--b);text-align:left}
.tv-tbl td{padding:.45rem .55rem;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.tv-tbl .row-total td{font-weight:700;background:rgba(0,229,176,.08)}
.span3{grid-column:1/-1;overflow:auto}
@media (max-width:1100px){.grid{grid-template-columns:1fr 1fr;grid-template-rows:auto auto auto 1fr;min-width:980px}.span3{grid-column:1/-1}}
html[data-theme="light"]{--bg:#f4f7fb;--s:#ffffff;--s2:#f0f4fa;--b:#d7e0ed;--t:#1a2433;--m:#5d6f88;--a:#007f67;--a2:#d14b1f;--w:#b18400}
</style>
</head>
<body>
<div class="grid">
<div class="topbar">
    <div>
        <div class="tv-title"><a class="tv-title-link" href="<?= APP_URL ?>/index.php"><?= h($tvTitleFirst) ?></a><?= h($tvTitleRest) ?> TV</div>
        <div class="tv-meta">T-7 dni | <?= date('d.m.Y', strtotime($dateFrom)) ?> - <?= date('d.m.Y', strtotime($dateTo)) ?></div>
    </div>
    <div class="tv-header-right">
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

<div class="card stat-big">
    <div class="card-header"><?= t('kt_period') ?></div>
    <div class="val" style="font-size:1.6rem"><?= h($dateFrom) ?> - <?= h($dateTo) ?></div>
    <div class="sub"><?= t('kt_scope_cislo') ?>: <?= h(implode(', ', $targetCisloIds)) ?></div>
</div>

<div class="card stat-big">
    <div class="card-header"><?= t('kt_produced_ks') ?></div>
    <div class="val"><?= number_format($totalProduced) ?></div>
    <div class="lbl"><?= t('kt_total') ?> / 7 dni</div>
</div>

<div class="card stat-big">
    <div class="card-header"><?= t('kt_nok_ks') ?></div>
    <div class="val" style="color:var(--a2)"><?= number_format($totalNok) ?></div>
    <div class="sub"><?= t('kt_nok_rate') ?> <strong style="color:var(--a2)"><?= number_format($totalNokRate, 2) ?>%</strong></div>
</div>

<div class="card span3">
    <div class="card-header"><?= t('kt_daily_overview') ?></div>
    <table class="tv-tbl">
        <thead>
            <tr>
                <th><?= t('kt_col_workplace') ?></th>
                <?php foreach ($days as $d): ?>
                    <th class="mono">
                        <?= h($d['label']) ?><br>
                        <small style="color:var(--m)"><?= h($d['name']) ?></small>
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
                    <td colspan="<?= count($dayKeys) ?>" class="mono" style="color:var(--m);font-style:italic;text-align:center;"><?= t('kt_no_production') ?></td>
                <?php else: ?>
                <?php foreach ($dayKeys as $dk):
                    $prod = (int)$matrix[$wp['cislo_id']][$dk]['vyrobeno_ks'];
                    $nok = (int)$matrix[$wp['cislo_id']][$dk]['nok_ks'];
                ?>
                <td class="mono">
                    <?php if ($prod === 0): ?>
                    <div style="color:var(--m);font-style:italic;font-size:.82rem"><?= t('kt_no_production') ?></div>
                    <?php else: ?>
                    <div style="font-size:.6rem;color:var(--m);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                    <div style="color:var(--a);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format($prod) ?></div>
                    <div style="font-size:.6rem;color:var(--m);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                    <div style="color:var(--a2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format($nok) ?></div>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <tr class="row-total">
                <td><?= t('kt_total') ?></td>
                <?php foreach ($dayKeys as $dk): ?>
                <td class="mono">
                    <div style="font-size:.6rem;color:var(--m);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                    <div style="color:var(--a);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format((int)$totalsByDay[$dk]['vyrobeno_ks']) ?></div>
                    <div style="font-size:.6rem;color:var(--m);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                    <div style="color:var(--a2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format((int)$totalsByDay[$dk]['nok_ks']) ?></div>
                </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
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
</script>
</body>
</html>
