<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'writer']);

$pageTitle = t('kt_report_all_title');
$activePage = 'kt_report_all';

$today = new DateTimeImmutable('today');
$defaultCw = (int)$today->format('W');
$defaultIsoYear = (int)$today->format('o');

$lang = $_SESSION['lang'] ?? 'cs';
$dayNames = localizedWeekdayNames();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$fCw   = (int)($isPost ? ($_POST['cw'] ?? $defaultCw) : get('cw', (string)$defaultCw));
$fYear = (int)($isPost ? ($_POST['year'] ?? $defaultIsoYear) : get('year', (string)$defaultIsoYear));
$export = get('export') === 'csv';

if ($fCw < 1 || $fCw > 53)       $fCw   = $defaultCw;
if ($fYear < 2020 || $fYear > 2100) $fYear = $defaultIsoYear;

$weekStart = $today->setISODate($fYear, $fCw, 1);
$weekEnd   = $today->setISODate($fYear, $fCw, 7);
$dateFrom  = $weekStart->format('Y-m-d');
$dateTo    = $weekEnd->format('Y-m-d');

$days    = [];
$dayKeys = [];
for ($i = 0; $i < 7; $i++) {
    $d       = $weekStart->modify('+' . $i . ' day');
    $isoDate = $d->format('Y-m-d');
    $dayNum  = (int)$d->format('N');
    $days[]    = ['iso' => $isoDate, 'label' => $d->format('d.m.Y'), 'name' => $dayNames[$dayNum] ?? $isoDate];
    $dayKeys[] = $isoDate;
}

// All workplaces from DB
$allWpRows = db_all(
    "SELECT p.cislo_id,
            COALESCE(MAX(NULLIF(p.nazev_pracovisite, '')), MAX(NULLIF(p.nazev_dilu, '')), CONCAT('ID ', p.cislo_id)) AS nazev
     FROM pracovisite p
     GROUP BY p.cislo_id
     ORDER BY p.cislo_id",
    []
);

// Multi-select filter: cids[]
$rawCids = $isPost
    ? ((isset($_POST['cids']) && is_array($_POST['cids'])) ? $_POST['cids'] : [])
    : ((isset($_GET['cids']) && is_array($_GET['cids'])) ? $_GET['cids'] : []);
$selectedCids = [];
foreach ($rawCids as $v) {
    $ci = (int)$v;
    if ($ci > 0) $selectedCids[] = $ci;
}
$selectedCids = array_unique($selectedCids);

$allCisloIds   = array_map(fn($r) => (int)$r['cislo_id'], $allWpRows);
$nameByCislo   = [];
foreach ($allWpRows as $n) {
    $nameByCislo[(int)$n['cislo_id']] = (string)$n['nazev'];
}

$preset = (string)get('preset', '');
$fixedPresetSets = [
    'kt1' => ['name' => 'KT report', 'cids' => [39, 50, 162, 175, 33, 119, 183, 148, 184], 'locked' => true],
    'kt2' => ['name' => 'KT report 2', 'cids' => [128, 129, 131, 135, 81, 83, 145, 146, 119, 183, 180, 153, 148, 184], 'locked' => true],
];

$userPresetRows = getCurrentUserKtPresets();
$userPresetSets = [];
foreach ($userPresetRows as $row) {
    $k = (string)($row['key'] ?? '');
    if ($k === '' || isset($fixedPresetSets[$k])) {
        continue;
    }
    $userPresetSets[$k] = [
        'name' => (string)($row['name'] ?? $k),
        'cids' => normalizeKtPresetCids($row['cids'] ?? []),
        'locked' => false,
    ];
}

// Which cislo_ids had production in the selected week
$activeRows = db_all(
    "SELECT DISTINCT p.cislo_id
     FROM zapisy z
     INNER JOIN pracovisite p ON p.id = z.pracovisite_id
     WHERE z.datum BETWEEN :od AND :do
       AND COALESCE(z.vyrobeno_ks, 0) > 0",
    [':od' => $dateFrom, ':do' => $dateTo]
);
$activeInWeek = array_values(array_filter(
    array_map(fn($r) => (int)$r['cislo_id'], $activeRows),
    fn($cid) => in_array($cid, $allCisloIds, true)
));
// Keep original DB order
usort($activeInWeek, fn($a, $b) => array_search($a, $allCisloIds) - array_search($b, $allCisloIds));

if ($isPost) {
    $token = (string)($_POST['csrf'] ?? '');
    if (!verifyCsrfToken($token)) {
        http_response_code(400);
        die('<pre style="color:red">Neplatný CSRF token.</pre>');
    }

    $action = (string)($_POST['preset_action'] ?? 'filter');
    $postedPreset = trim((string)($_POST['preset'] ?? ''));
    $actionPresetKey = trim((string)($_POST['preset_key'] ?? ''));
    $availablePresetKeys = array_fill_keys(array_merge(array_keys($fixedPresetSets), array_keys($userPresetSets)), true);
    $redirectParams = ['cw' => $fCw, 'year' => $fYear];

    if ($action === 'create') {
        $name = trim((string)($_POST['preset_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name ?? '');
        $chosen = array_values(array_intersect($allCisloIds, $selectedCids));

        if ($name !== '' && !empty($chosen)) {
            $key = 'u' . bin2hex(random_bytes(4));
            while (isset($userPresetSets[$key]) || isset($fixedPresetSets[$key])) {
                $key = 'u' . bin2hex(random_bytes(4));
            }

            $userPresetRows[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 60),
                'cids' => $chosen,
            ];
            saveCurrentUserKtPresets($userPresetRows);
            $redirectParams['preset'] = $key;
        } elseif ($postedPreset !== '') {
            $redirectParams['preset'] = $postedPreset;
        }
    } elseif ($action === 'delete') {
        $deleteKey = trim((string)($_POST['preset_key'] ?? ''));
        if ($deleteKey !== '' && !isset($fixedPresetSets[$deleteKey])) {
            $filtered = [];
            foreach ($userPresetRows as $row) {
                if ((string)($row['key'] ?? '') !== $deleteKey) {
                    $filtered[] = $row;
                }
            }
            saveCurrentUserKtPresets($filtered);
        }
        if ($postedPreset !== '' && $postedPreset !== $deleteKey) {
            $redirectParams['preset'] = $postedPreset;
        }
    } elseif ($action === 'apply') {
        if ($actionPresetKey !== '' && isset($availablePresetKeys[$actionPresetKey])) {
            $redirectParams['preset'] = $actionPresetKey;
        } elseif ($postedPreset !== '') {
            $redirectParams['preset'] = $postedPreset;
        }
    } else {
        if ($postedPreset !== '') {
            $redirectParams['preset'] = $postedPreset;
        }
    }

    if ($action === 'filter') {
        foreach ($selectedCids as $cid) {
            $redirectParams['cids'][] = (int)$cid;
        }
    }

    header('Location: ?' . http_build_query($redirectParams));
    exit;
}

$presetCisloSets = array_merge($fixedPresetSets, $userPresetSets);

if (empty($selectedCids) && isset($presetCisloSets[$preset])) {
    $selectedCids = array_values(array_intersect($activeInWeek, $presetCisloSets[$preset]['cids']));
}

// Active set
$targetCisloIds = empty($selectedCids)
    ? $activeInWeek
    : array_values(array_intersect($allCisloIds, $selectedCids));

// Build SQL params
$idPlaceholders = [];
$params         = [':od' => $dateFrom, ':do' => $dateTo];
$idParams       = [];
foreach ($targetCisloIds as $idx => $cid) {
    $ph               = ':cid' . $idx;
    $idPlaceholders[] = $ph;
    $params[$ph]      = $cid;
    $idParams[$ph]    = $cid;
}

$workplaces  = [];
$matrix      = [];
$totalsByDay = [];
foreach ($dayKeys as $dk) {
    $totalsByDay[$dk] = ['vyrobeno_ks' => 0, 'nok_ks' => 0];
}
foreach ($targetCisloIds as $cid) {
    $workplaces[] = ['cislo_id' => $cid, 'nazev' => $nameByCislo[$cid] ?? ('ID ' . $cid)];
    foreach ($dayKeys as $dk) {
        $matrix[$cid][$dk] = ['vyrobeno_ks' => 0, 'nok_ks' => 0];
    }
}

if (!empty($idPlaceholders)) {
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
        $cid     = (int)$row['cislo_id'];
        $dateKey = (string)$row['datum'];
        if (!isset($matrix[$cid][$dateKey])) continue;
        $prod = (int)$row['vyrobeno_ks'];
        $nok  = (int)$row['nok_ks'];
        $matrix[$cid][$dateKey] = ['vyrobeno_ks' => $prod, 'nok_ks' => $nok];
        $totalsByDay[$dateKey]['vyrobeno_ks'] += $prod;
        $totalsByDay[$dateKey]['nok_ks']      += $nok;
    }
}

$totalProduced = 0;
$totalNok      = 0;
foreach ($totalsByDay as $sum) {
    $totalProduced += (int)$sum['vyrobeno_ks'];
    $totalNok      += (int)$sum['nok_ks'];
}
$totalNokRate = $totalProduced > 0 ? ($totalNok / $totalProduced) * 100 : 0.0;

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kt_report_all_' . $fYear . '_cw' . str_pad((string)$fCw, 2, '0', STR_PAD_LEFT) . '.csv"');
    echo "\xEF\xBB\xBF";
    $out    = fopen('php://output', 'w');
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
        <span class="topbar-title"><?= t('kt_report_all_title') ?> – CW <?= h((string)$fCw) ?> / <?= h((string)$fYear) ?></span>
        <?php if (!empty($selectedCids)): ?>
        <span class="topbar-meta"><?= count($selectedCids) ?> <?= t('kt_filter_workplaces') ?></span>
        <?php else: ?>
        <span class="topbar-meta"><?= t('kt_all_workplaces') ?> (<?= count($allCisloIds) ?>)</span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <a href="<?= APP_URL ?>/admin/kt_report_all_tv.php" class="btn-outline" target="_blank" rel="noopener noreferrer" style="padding:.38rem .9rem;font-size:.78rem">
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
    <form method="post" class="filter-bar no-print" id="kt-all-filter-form">
        <input type="hidden" name="csrf" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="preset" value="<?= h($preset) ?>">
        <input type="hidden" name="preset_action" id="preset-action" value="filter">
        <input type="hidden" name="preset_name" id="preset-name" value="">
        <input type="hidden" name="preset_key" id="preset-key" value="">
        <div class="row g-2 align-items-end mb-2">
            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label-dvd"><?= t('cw_label') ?></label>
                <input type="number" name="cw" id="cw-input" class="form-control" min="1" max="53" value="<?= h((string)$fCw) ?>">
            </div>
            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label-dvd"><?= t('year') ?></label>
                <input type="number" name="year" class="form-control" min="2020" max="2100" value="<?= h((string)$fYear) ?>">
            </div>
            <div class="col-auto d-flex gap-2 align-self-end">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
                <button type="button" id="preset-create-btn" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">Vytvořit trvalý výběr</button>
                <a href="<?= APP_URL ?>/admin/kt_report_all.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
            </div>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label-dvd mb-1"><?= t('kt_filter_workplaces') ?></label>
                <select name="cids[]" id="cids-select" class="form-control" multiple size="5" style="height:auto;">
                    <?php foreach ($activeInWeek as $activeCid): ?>
                    <option value="<?= $activeCid ?>"
                        <?= in_array($activeCid, $selectedCids, true) ? 'selected' : '' ?>>
                        [<?= $activeCid ?>] <?= h($nameByCislo[$activeCid] ?? ('ID ' . $activeCid)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);font-size:.68rem"><?= t('kt_multiselect_hint') ?></small>
                <div class="mt-2">
                    <label class="form-label-dvd mb-1">Uložené filtry</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn-outline js-apply-preset" data-preset-key="kt1" style="padding:.2rem .55rem;font-size:.68rem;line-height:1.2;">KT report</button>
                        <button type="button" class="btn-outline js-apply-preset" data-preset-key="kt2" style="padding:.2rem .55rem;font-size:.68rem;line-height:1.2;">KT report 2</button>
                        <?php foreach ($userPresetSets as $presetKey => $presetDef): ?>
                            <div class="btn-outline d-inline-flex align-items-center" style="padding:.2rem .5rem;font-size:.68rem;line-height:1.2;gap:.4rem;">
                                <button type="button" class="js-apply-preset" data-preset-key="<?= h($presetKey) ?>" style="border:none;background:transparent;padding:0;margin:0;color:inherit;line-height:1;cursor:pointer;"><?= h($presetDef['name']) ?></button>
                                <button type="button" class="js-delete-preset" data-preset-key="<?= h($presetKey) ?>" data-preset-name="<?= h($presetDef['name']) ?>" style="border:none;background:transparent;padding:0;margin:0;color:inherit;line-height:1;" title="Smazat filtr">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono"><?= h($dateFrom) ?></div><div class="stat-label"><?= t('od') ?></div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-val mono"><?= h($dateTo) ?></div><div class="stat-label"><?= t('do') ?></div></div>
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
            ?>
                <tr>
                    <td>
                        <div class="mono">[<?= (int)$wp['cislo_id'] ?>]</div>
                        <div><?= h($wp['nazev']) ?></div>
                    </td>
                    <?php foreach ($dayKeys as $dk):
                        $prod = (int)$matrix[$wp['cislo_id']][$dk]['vyrobeno_ks'];
                        $nok  = (int)$matrix[$wp['cislo_id']][$dk]['nok_ks'];
                        $nokRate = $prod > 0 ? ($nok / $prod) * 100 : 0.0;
                        $isTodayOrFuture = $dk >= $today->format('Y-m-d');
                    ?>
                        <td class="mono">
                            <?php if ($prod === 0): ?>
                            <div style="color:var(--muted);font-style:italic;font-size:.82rem"><?= t($isTodayOrFuture ? 'kt_waiting_for_data' : 'kt_no_production') ?></div>
                            <?php else: ?>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                            <div style="color:var(--accent);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format($prod) ?></div>
                            <?php if ($nok >= 1): ?>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                            <div style="color:var(--accent2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format($nok) ?> <small class="mono" style="font-size:.75rem;opacity:.75;">(<?= number_format($nokRate, 2) ?>%)</small></div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr style="font-weight:700;">
                    <td><?= t('kt_total') ?></td>
                    <?php foreach ($dayKeys as $dk):
                        $dayProd = (int)$totalsByDay[$dk]['vyrobeno_ks'];
                        $dayNok = (int)$totalsByDay[$dk]['nok_ks'];
                        $dayNokRate = $dayProd > 0 ? ($dayNok / $dayProd) * 100 : 0.0;
                    ?>
                        <td class="mono">
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= t('kt_produced_short') ?></div>
                            <div style="color:var(--accent);font-weight:700;font-size:1.15rem;line-height:1.2"><?= number_format($dayProd) ?></div>
                            <?php if ($dayNok >= 1): ?>
                            <div style="font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem"><?= t('kt_nok_short') ?></div>
                            <div style="color:var(--accent2);font-weight:700;font-size:1.05rem;line-height:1.2"><?= number_format($dayNok) ?> <small class="mono" style="font-size:.75rem;opacity:.75;">(<?= number_format($dayNokRate, 2) ?>%)</small></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<script>
(() => {
    const form = document.getElementById('kt-all-filter-form');
    if (!form) return;

    const presetAction = document.getElementById('preset-action');
    const presetName = document.getElementById('preset-name');
    const presetKey = document.getElementById('preset-key');
    const createBtn = document.getElementById('preset-create-btn');
    const cidsSelect = document.getElementById('cids-select');
    const cwInput = document.getElementById('cw-input');

    const selectedCount = () => {
        if (!cidsSelect) return 0;
        return Array.from(cidsSelect.options).filter((opt) => opt.selected).length;
    };

    if (createBtn) {
        createBtn.addEventListener('click', () => {
            if (selectedCount() === 0) {
                window.alert('Nejprve vyberte alespoň jedno pracoviště.');
                return;
            }

            const name = window.prompt('Zadejte název filtru:');
            if (name === null) return;

            const trimmed = name.trim();
            if (!trimmed) {
                window.alert('Název filtru nemůže být prázdný.');
                return;
            }

            presetAction.value = 'create';
            presetName.value = trimmed;
            presetKey.value = '';
            form.submit();
        });
    }

    document.querySelectorAll('.js-delete-preset').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-preset-key') || '';
            const name = btn.getAttribute('data-preset-name') || '';
            if (!key) return;

            const ok = window.confirm('Opravdu smazat filtr "' + name + '"?');
            if (!ok) return;

            presetAction.value = 'delete';
            presetKey.value = key;
            presetName.value = '';
            form.submit();
        });
    });

    document.querySelectorAll('.js-apply-preset').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-preset-key') || '';
            if (!key) return;

            presetAction.value = 'apply';
            presetKey.value = key;
            presetName.value = '';
            form.submit();
        });
    });

    if (cwInput) {
        cwInput.addEventListener('wheel', (ev) => {
            ev.preventDefault();
            const min = parseInt(cwInput.min || '1', 10);
            const max = parseInt(cwInput.max || '53', 10);
            let val = parseInt(cwInput.value || '1', 10);
            if (Number.isNaN(val)) val = min;

            val += ev.deltaY < 0 ? 1 : -1;
            if (val < min) val = min;
            if (val > max) val = max;

            cwInput.value = String(val);
        }, { passive: false });
    }
})();
</script>
<?php require APP_ROOT . '/includes/layout_end.php'; ?>
