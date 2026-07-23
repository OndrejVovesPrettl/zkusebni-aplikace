<?php
require_once dirname(__DIR__) . '/includes/config.php';

$sheetPath = APP_ROOT . '/uploads/NOKy_xml/xl/worksheets/sheet1.xml';
$sharedPath = APP_ROOT . '/uploads/NOKy_xml/xl/sharedStrings.xml';

if (!is_file($sheetPath)) {
    fwrite(STDERR, "Missing sheet XML\n");
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
$ns = $sheet->getNamespaces(true);
if (isset($ns[''])) {
    $sheet->registerXPathNamespace('x', $ns['']);
    $rows = $sheet->xpath('//x:sheetData/x:row');
} else {
    $rows = $sheet->sheetData->row ?? [];
}

$first = true;
$rawAfo = [];
$normAfo = [];
foreach ($rows as $row) {
    $colA = null;
    foreach ($row->c as $c) {
        $ref = (string)($c['r'] ?? '');
        if (str_starts_with($ref, 'A')) {
            $type = (string)($c['t'] ?? '');
            $raw = isset($c->v) ? (string)$c->v : '';
            if ($type === 's') {
                $idx = (int)$raw;
                $colA = (string)($shared[$idx] ?? '');
            } else {
                $colA = $raw;
            }
            break;
        }
    }
    if ($first) {
        $first = false;
        continue;
    }
    $a = trim((string)$colA);
    if ($a === '') continue;
    $rawAfo[$a] = true;

    if (preg_match('/\d+/', $a, $m)) {
        $norm = (string)((int)$m[0]);
        $normAfo[$norm] = true;
    }
}

$raw = array_keys($rawAfo);
sort($raw, SORT_NATURAL);
$norm = array_keys($normAfo);
sort($norm, SORT_NATURAL);

echo 'raw_count=' . count($raw) . PHP_EOL;
echo 'norm_count=' . count($norm) . PHP_EOL;
echo 'has_raw_20=' . (in_array('20', $raw, true) ? 'yes' : 'no') . PHP_EOL;
echo 'has_norm_20=' . (in_array('20', $norm, true) ? 'yes' : 'no') . PHP_EOL;
echo 'sample_raw=' . implode(', ', array_slice($raw, 0, 30)) . PHP_EOL;
echo 'sample_norm=' . implode(', ', array_slice($norm, 0, 30)) . PHP_EOL;
