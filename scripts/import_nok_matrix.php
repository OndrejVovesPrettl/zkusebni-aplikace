<?php
// scripts/import_nok_matrix.php
// Reimport NOK AFO matrix from uploads/NOKy.xlsx.
// Default behavior: purge existing matrix rows before import.

require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

ensureNokKodyMatrixSchema();

$argv = $_SERVER['argv'] ?? [];
$keepExisting = in_array('--keep-existing', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
$xlsxPath = APP_ROOT . '/uploads/NOKy.xlsx';
$xlsxExtractDirFresh = APP_ROOT . '/uploads/NOKy_from_xlsx';
$xlsxExtractDir = APP_ROOT . '/uploads/NOKy_xml';

$sharedStrings = [];
$sheetXml = false;
$sharedStringsXml = false;

if (class_exists('ZipArchive')) {
    if (!is_file($xlsxPath)) {
        fwrite(STDERR, "Source file not found: {$xlsxPath}\n");
        exit(2);
    }

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        fwrite(STDERR, "Cannot open xlsx: {$xlsxPath}\n");
        exit(3);
    }

    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
} else {
    $chosenDir = is_file($xlsxExtractDirFresh . '/xl/worksheets/sheet1.xml') ? $xlsxExtractDirFresh : $xlsxExtractDir;
    $sharedPath = $chosenDir . '/xl/sharedStrings.xml';
    $sheetPath = $chosenDir . '/xl/worksheets/sheet1.xml';
    if (!is_file($sheetPath)) {
        fwrite(STDERR, "ZipArchive extension missing and fallback XML not found: {$sheetPath}\n");
        exit(3);
    }
    $sheetXml = file_get_contents($sheetPath);
    if (is_file($sharedPath)) {
        $sharedStringsXml = file_get_contents($sharedPath);
    }
}

if ($sharedStringsXml !== false && $sharedStringsXml !== null) {
    $sx = @simplexml_load_string($sharedStringsXml);
    if ($sx !== false) {
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
                continue;
            }
            $parts = [];
            if (isset($si->r)) {
                foreach ($si->r as $r) {
                    $parts[] = (string)$r->t;
                }
            }
            $sharedStrings[] = implode('', $parts);
        }
    }
}

if ($sheetXml === false || $sheetXml === null) {
    fwrite(STDERR, "Worksheet sheet1.xml not found in xlsx source\n");
    exit(4);
}

$sheet = @simplexml_load_string($sheetXml);
if ($sheet === false) {
    fwrite(STDERR, "Cannot parse worksheet XML\n");
    exit(5);
}

$ns = $sheet->getNamespaces(true);
if (isset($ns[''])) {
    $sheet->registerXPathNamespace('x', $ns['']);
    $rowNodes = $sheet->xpath('//x:sheetData/x:row');
} else {
    $rowNodes = $sheet->sheetData->row ?? [];
}

if (!$rowNodes || count($rowNodes) < 2) {
    fwrite(STDERR, "No data rows in worksheet\n");
    exit(6);
}

$columnLetters = ['A', 'B', 'C', 'D']; // AFO, Hodnota, Chyba, ID chyby

$getCellValue = static function ($cell) use (&$sharedStrings): string {
    $type = (string)($cell['t'] ?? '');
    $raw = isset($cell->v) ? (string)$cell->v : '';
    if ($type === 's') {
        $idx = (int)$raw;
        return (string)($sharedStrings[$idx] ?? '');
    }
    return $raw;
};

$header = [];
$first = true;
$dataRows = [];
foreach ($rowNodes as $row) {
    $cellsByCol = [];
    foreach ($row->c as $c) {
        $ref = (string)($c['r'] ?? '');
        if ($ref === '') {
            continue;
        }
        if (preg_match('/^([A-Z]+)/', $ref, $m)) {
            $cellsByCol[$m[1]] = $getCellValue($c);
        }
    }

    $values = [];
    foreach ($columnLetters as $col) {
        $values[] = trim((string)($cellsByCol[$col] ?? ''));
    }

    if ($first) {
        $header = $values;
        $first = false;
        continue;
    }

    if ($values[0] === '' && $values[1] === '' && $values[2] === '' && $values[3] === '') {
        continue;
    }

    $dataRows[] = [
        'afo' => $values[0],
        'hodnota' => $values[1],
        'chyba' => $values[2],
        'id_chyby' => $values[3],
    ];
}

$headerNorm = array_map(static fn($x) => mb_strtolower(trim((string)$x), 'UTF-8'), $header);
$expected = ['afo', 'hodnota', 'chyba', 'id chyby'];
if ($headerNorm !== $expected) {
    fwrite(STDERR, "Unexpected header. Got: [" . implode(', ', $header) . "]\n");
    exit(7);
}

$nokIds = db_all('SELECT id FROM nok_kody');
$nokSet = [];
foreach ($nokIds as $r) {
    $nokSet[(int)$r['id']] = true;
}

$preparedRows = [];
$skippedInvalid = 0;
$missingNokId = 0;
$duplicatesCollapsed = 0;
$seen = [];

foreach ($dataRows as $row) {
    $afo = trim((string)$row['afo']);
    $id = (int)$row['id_chyby'];
    $valRaw = trim((string)$row['hodnota']);
    $zobrazit = (int)$valRaw === 1 ? 1 : 0;

    if ($afo === '' || $id <= 0) {
        $skippedInvalid++;
        continue;
    }

    if (!isset($nokSet[$id])) {
        $missingNokId++;
        continue;
    }

    $key = $afo . '|' . $id;
    if (isset($seen[$key])) {
        $duplicatesCollapsed++;
    }
    $seen[$key] = [
        'afo' => $afo,
        'nok_kod_id' => $id,
        'zobrazit' => $zobrazit,
    ];
}

$preparedRows = array_values($seen);

$result = [
    'source' => class_exists('ZipArchive') ? $xlsxPath : ($xlsxExtractDir . '/xl/worksheets/sheet1.xml'),
    'header' => $header,
    'rows_in_sheet' => count($dataRows),
    'rows_prepared' => count($preparedRows),
    'skipped_invalid' => $skippedInvalid,
    'missing_nok_id' => $missingNokId,
    'duplicates_collapsed' => $duplicatesCollapsed,
    'purged' => 0,
    'inserted' => 0,
    'mode' => $dryRun ? 'dry-run' : ($keepExisting ? 'append/upsert' : 'purge+replace'),
];

if ($dryRun) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$pdo = db();
$pdo->beginTransaction();
try {
    if (!$keepExisting) {
        $result['purged'] = (int)$pdo->exec('DELETE FROM nok_matrix_afo');
    }

    $ins = $pdo->prepare(
        'INSERT INTO nok_matrix_afo (afo, nok_kod_id, zobrazit) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE zobrazit=VALUES(zobrazit), updated_at=NOW()'
    );

    foreach ($preparedRows as $r) {
        $ins->execute([$r['afo'], $r['nok_kod_id'], $r['zobrazit']]);
        $result['inserted']++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(8);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
