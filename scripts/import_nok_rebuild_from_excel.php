<?php
// scripts/import_nok_rebuild_from_excel.php
// Rebuild NOK master and AFO matrix from uploads/NOKy.xlsx (or extracted XML fallback).

require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

ensureNokKodyMatrixSchema();

$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);

$xlsxPath = APP_ROOT . '/uploads/NOKy.xlsx';
$xlsxExtractDirFresh = APP_ROOT . '/uploads/NOKy_from_xlsx';
$xlsxExtractDir = APP_ROOT . '/uploads/NOKy_xml';

$sheetXml = false;
$sharedStringsXml = false;
$sharedStrings = [];

if (class_exists('ZipArchive') && is_file($xlsxPath)) {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) === true) {
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
    }
}

if ($sheetXml === false || $sheetXml === null) {
    $chosenDir = is_file($xlsxExtractDirFresh . '/xl/worksheets/sheet1.xml') ? $xlsxExtractDirFresh : $xlsxExtractDir;
    $sharedPath = $chosenDir . '/xl/sharedStrings.xml';
    $sheetPath = $chosenDir . '/xl/worksheets/sheet1.xml';
    if (!is_file($sheetPath)) {
        fwrite(STDERR, "XLSX source not available (ZipArchive missing and fallback XML not found).\n");
        exit(2);
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

$sheet = @simplexml_load_string((string)$sheetXml);
if ($sheet === false) {
    fwrite(STDERR, "Cannot parse worksheet XML\n");
    exit(3);
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
    exit(4);
}

$getCellValue = static function ($cell) use (&$sharedStrings): string {
    $type = (string)($cell['t'] ?? '');
    $raw = isset($cell->v) ? (string)$cell->v : '';
    if ($type === 's') {
        return (string)($sharedStrings[(int)$raw] ?? '');
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
        if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
            $cellsByCol[$m[1]] = $getCellValue($c);
        }
    }

    $vals = [
        trim((string)($cellsByCol['A'] ?? '')),
        trim((string)($cellsByCol['B'] ?? '')),
        trim((string)($cellsByCol['C'] ?? '')),
        trim((string)($cellsByCol['D'] ?? '')),
    ];

    if ($first) {
        $header = $vals;
        $first = false;
        continue;
    }

    if ($vals[0] === '' && $vals[1] === '' && $vals[2] === '' && $vals[3] === '') {
        continue;
    }

    $id = (int)$vals[3];
    $afoNum = preg_replace('/\D+/', '', (string)$vals[0]);
    $afo = $afoNum === '' ? '' : (string)((int)$afoNum);
    $hodnota = ((int)$vals[1] === 1) ? 1 : 0;
    $chyba = trim((string)$vals[2]);

    if ($id <= 0 || $afo === '' || $chyba === '') {
        continue;
    }

    $dataRows[] = [
        'id' => $id,
        'afo' => $afo,
        'zobrazit' => $hodnota,
        'popis' => $chyba,
    ];
}

$headerNorm = array_map(static fn($x) => mb_strtolower(trim((string)$x), 'UTF-8'), $header);
if ($headerNorm !== ['afo', 'hodnota', 'chyba', 'id chyby']) {
    fwrite(STDERR, 'Unexpected header: [' . implode(', ', $header) . "]\n");
    exit(5);
}

$nokById = [];
$matrixSeen = [];
foreach ($dataRows as $r) {
    $id = (int)$r['id'];
    if (!isset($nokById[$id])) {
        $nokById[$id] = [
            'id' => $id,
            'popis' => (string)$r['popis'],
            'kod' => $id,
            'skupina' => 'ostatni',
            'aktivni' => 1,
            'zobrazit_default' => 0,
        ];
    }
    $mk = $r['afo'] . '|' . $id;
    $matrixSeen[$mk] = [
        'afo' => (string)$r['afo'],
        'nok_kod_id' => $id,
        'zobrazit' => (int)$r['zobrazit'],
    ];
}

$matrixRows = array_values($matrixSeen);

$result = [
    'rows_in_sheet' => count($dataRows),
    'nok_unique_ids' => count($nokById),
    'matrix_rows' => count($matrixRows),
    'dry_run' => $dryRun,
    'deactivated_old_nok' => 0,
    'upserted_nok' => 0,
    'purged_matrix' => 0,
    'upserted_matrix' => 0,
    'updated_translations_cs' => 0,
];

if ($dryRun) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$pdo = db();
$pdo->beginTransaction();
try {
    // 1) deactivate all existing NOK (old codebook) but keep IDs for history compatibility
    $result['deactivated_old_nok'] = (int)$pdo->exec('UPDATE nok_kody SET aktivni=0');

    // 2) upsert NOK by Excel ID chyby as primary key
    $upsertNok = $pdo->prepare(
        'INSERT INTO nok_kody (id, kod, popis, skupina, aktivni, zobrazit_default) VALUES (?, ?, ?, ?, 1, 0) '
        . 'ON DUPLICATE KEY UPDATE kod=VALUES(kod), popis=VALUES(popis), skupina=VALUES(skupina), aktivni=1, zobrazit_default=0'
    );

    $upsertTr = $pdo->prepare(
        'INSERT INTO translations (lang_code, `key`, `value`) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()'
    );

    foreach ($nokById as $id => $n) {
        $upsertNok->execute([(int)$id, (int)$n['kod'], (string)$n['popis'], (string)$n['skupina']]);
        $result['upserted_nok']++;

        $keyBase = 'nok_' . str_pad((string)((int)$n['kod']), 3, '0', STR_PAD_LEFT) . '_popis';
        $upsertTr->execute(['cs', $keyBase, (string)$n['popis']]);
        $result['updated_translations_cs']++;
    }

    // 3) rebuild matrix table
    $result['purged_matrix'] = (int)$pdo->exec('DELETE FROM nok_matrix_afo');

    $insMatrix = $pdo->prepare(
        'INSERT INTO nok_matrix_afo (afo, nok_kod_id, zobrazit) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE zobrazit=VALUES(zobrazit), updated_at=NOW()'
    );

    foreach ($matrixRows as $m) {
        $insMatrix->execute([(string)$m['afo'], (int)$m['nok_kod_id'], (int)$m['zobrazit']]);
        $result['upserted_matrix']++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Rebuild import failed: ' . $e->getMessage() . PHP_EOL);
    exit(6);
}

LanguageLoader::reload('cs');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
