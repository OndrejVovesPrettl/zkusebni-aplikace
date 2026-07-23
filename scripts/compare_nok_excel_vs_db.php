<?php
require_once dirname(__DIR__) . '/includes/config.php';

$sheetPath = APP_ROOT . '/uploads/NOKy_from_xlsx/xl/worksheets/sheet1.xml';
$sharedPath = APP_ROOT . '/uploads/NOKy_from_xlsx/xl/sharedStrings.xml';
if (!is_file($sheetPath)) {
    $sheetPath = APP_ROOT . '/uploads/NOKy_xml/xl/worksheets/sheet1.xml';
    $sharedPath = APP_ROOT . '/uploads/NOKy_xml/xl/sharedStrings.xml';
}
if (!is_file($sheetPath)) {
    fwrite(STDERR, "Missing worksheet XML source.\n");
    exit(1);
}

$shared = [];
if (is_file($sharedPath)) {
    $sx = @simplexml_load_file($sharedPath);
    if ($sx) {
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $parts = [];
                foreach ($si->r as $r) {
                    $parts[] = (string)$r->t;
                }
                $shared[] = implode('', $parts);
            }
        }
    }
}

$sheet = @simplexml_load_file($sheetPath);
if (!$sheet) {
    fwrite(STDERR, "Cannot parse worksheet XML.\n");
    exit(2);
}

$ns = $sheet->getNamespaces(true);
if (isset($ns[''])) {
    $sheet->registerXPathNamespace('x', $ns['']);
    $rows = $sheet->xpath('//x:sheetData/x:row');
} else {
    $rows = $sheet->sheetData->row ?? [];
}

$getCell = static function($cell) use ($shared): string {
    $t = (string)($cell['t'] ?? '');
    $v = isset($cell->v) ? (string)$cell->v : '';
    if ($t === 's') {
        return (string)($shared[(int)$v] ?? '');
    }
    return $v;
};

$first = true;
$excel = []; // [afo][nokId] => 0/1
foreach ($rows as $row) {
    $vals = ['A'=>'','B'=>'','C'=>'','D'=>''];
    foreach ($row->c as $c) {
        $ref = (string)($c['r'] ?? '');
        if (preg_match('/^([A-D]+)/', $ref, $m)) {
            $col = $m[1][0];
            if (isset($vals[$col])) {
                $vals[$col] = trim($getCell($c));
            }
        }
    }

    if ($first) { $first = false; continue; }

    if ($vals['A'] === '' && $vals['B'] === '' && $vals['C'] === '' && $vals['D'] === '') {
        continue;
    }

    $afoDigits = preg_replace('/\D+/', '', (string)$vals['A']);
    $afo = $afoDigits === '' ? '' : (string)((int)$afoDigits);
    $nokId = (int)$vals['D'];
    $z = ((int)$vals['B'] === 1) ? 1 : 0;

    if ($afo === '' || $nokId <= 0) {
        continue;
    }

    $excel[$afo][$nokId] = $z;
}

$dbRows = db_all('SELECT afo, nok_kod_id, zobrazit FROM nok_matrix_afo');
$db = [];
foreach ($dbRows as $r) {
    $afo = trim((string)$r['afo']);
    $id = (int)$r['nok_kod_id'];
    $db[$afo][$id] = (int)$r['zobrazit'] === 1 ? 1 : 0;
}

$allAfo = array_values(array_unique(array_merge(array_keys($excel), array_keys($db))));
sort($allAfo, SORT_NATURAL);

$summary = [];
foreach ($allAfo as $afo) {
    $e = $excel[$afo] ?? [];
    $d = $db[$afo] ?? [];
    $ids = array_values(array_unique(array_merge(array_keys($e), array_keys($d))));
    sort($ids, SORT_NUMERIC);

    $diff = [];
    $eOn = 0;
    $dOn = 0;
    foreach ($ids as $id) {
        $ev = (int)($e[$id] ?? 0);
        $dv = (int)($d[$id] ?? 0);
        if ($ev === 1) $eOn++;
        if ($dv === 1) $dOn++;
        if ($ev !== $dv) {
            $diff[] = $id;
        }
    }

    if ($diff !== []) {
        $summary[] = [
            'afo' => $afo,
            'excel_ones' => $eOn,
            'db_ones' => $dOn,
            'diff_count' => count($diff),
            'diff_ids' => array_slice($diff, 0, 50),
        ];
    }
}

$focus = '4';
$focusExcel = $excel[$focus] ?? [];
$focusDb = $db[$focus] ?? [];
$focusIds = array_values(array_unique(array_merge(array_keys($focusExcel), array_keys($focusDb))));
sort($focusIds, SORT_NUMERIC);
$focusDiff = [];
$focusExcelOn = 0;
$focusDbOn = 0;
foreach ($focusIds as $id) {
    $ev = (int)($focusExcel[$id] ?? 0);
    $dv = (int)($focusDb[$id] ?? 0);
    if ($ev === 1) $focusExcelOn++;
    if ($dv === 1) $focusDbOn++;
    if ($ev !== $dv) {
        $focusDiff[] = ['id' => $id, 'excel' => $ev, 'db' => $dv];
    }
}

$out = [
    'source_sheet' => $sheetPath,
    'afo_count_excel' => count($excel),
    'afo_count_db' => count($db),
    'afo_with_any_diff' => count($summary),
    'top_diffs' => array_slice($summary, 0, 20),
    'focus_afo' => '4',
    'focus_excel_ones' => $focusExcelOn,
    'focus_db_ones' => $focusDbOn,
    'focus_diff_count' => count($focusDiff),
    'focus_diff_rows' => array_slice($focusDiff, 0, 200),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
