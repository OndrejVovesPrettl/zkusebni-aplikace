#!/usr/bin/env php
<?php
/**
 * scripts/auto_import_dvd.php
 *
 * Web/CLI spustitelny automaticky import DVD souboru do tabulky zapisy.
 *
 * Web:
 *   /scripts/auto_import_dvd.php
 *   /scripts/auto_import_dvd.php?force=1
 *
 * CLI:
 *   php scripts/auto_import_dvd.php
 *   php scripts/auto_import_dvd.php --force
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/import_runtime.php';

define('AUTO_IMPORT_SOURCE_FILE', getenv('DVD_AUTO_IMPORT_SOURCE') ?: '\\\\lbfile01.czlb-pms.prettl.pgn\\pacz\\Departments\\DVD\\DVD-2026.xlsx');

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function decodePythonJsonOutput(string $output): array {
    $decoded = json_decode($output, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $normalized = @iconv('Windows-1250', 'UTF-8//IGNORE', $output);
    if (is_string($normalized) && $normalized !== '') {
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    error_log('auto_import_dvd invalid python output: ' . $output);

    return [
        'error' => 'Neplatny JSON z Pythonu: ' . json_last_error_msg(),
        'raw_output' => $output,
    ];
}

if (PHP_SAPI !== 'cli' && !canAdmin()) {
    respond([
        'ok' => false,
        'error' => 'Pro spusteni je nutne prihlaseni jako admin nebo superadmin.',
    ], 403);
}

$start = microtime(true);
$forceRun = PHP_SAPI === 'cli'
    ? in_array('--force', $_SERVER['argv'] ?? [], true)
    : (($_GET['force'] ?? '0') === '1');

$dailyLockFile = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'auto_import_dvd_' . date('Ymd') . '.lock.json';
if (!$forceRun && is_file($dailyLockFile)) {
    $last = json_decode((string)file_get_contents($dailyLockFile), true);
    respond([
        'ok' => false,
        'error' => 'Auto import uz dnes probehl. Pro opakovani pouzij force=1 nebo --force.',
        'last_run' => is_array($last) ? $last : null,
    ], 409);
}

$sourceFile = AUTO_IMPORT_SOURCE_FILE;
$tmpFile = UPLOAD_DIR . 'autoimport_' . bin2hex(random_bytes(8)) . '_' . basename($sourceFile);
$workingFile = $sourceFile;
$copiedToTmp = false;
$sourceFileMtime = @filemtime($sourceFile);
$sourceFileMtime = ($sourceFileMtime !== false) ? (int)$sourceFileMtime : null;

if (!is_file($sourceFile)) {
    respond([
        'ok' => false,
        'error' => 'Zdrojovy soubor neexistuje.',
        'source_file' => $sourceFile,
    ], 500);
}

if (!is_readable($sourceFile)) {
    respond([
        'ok' => false,
        'error' => 'Zdrojovy soubor neni citelny pro uzivatele web serveru.',
        'source_file' => $sourceFile,
    ], 500);
}

if (is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR) && @copy($sourceFile, $tmpFile)) {
    $workingFile = $tmpFile;
    $copiedToTmp = true;
}

$pythonCommand = getPythonImportCommand();
if ($pythonCommand === null) {
    if ($copiedToTmp) {
        @unlink($tmpFile);
    }
    respond([
        'ok' => false,
        'error' => 'Python interpreter nebyl nalezen.',
    ], 500);
}

$script = APP_ROOT . '/includes/import_excel.py';
$jsonOut = shell_exec($pythonCommand . ' ' . escapeshellarg($script) . ' import ' . escapeshellarg($workingFile) . ' 2>&1');
$data = decodePythonJsonOutput($jsonOut ?: '{}');

if (empty($data['imported']) && empty($data['rows'])) {
    if ($copiedToTmp) {
        @unlink($tmpFile);
    }
    respond([
        'ok' => false,
        'error' => $data['error'] ?? 'Import parser nevratil data.',
        'raw_output' => $data['raw_output'] ?? $jsonOut,
    ], 500);
}

$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$parserSkipped = (int)($data['skipped'] ?? 0);
$dbErrors = 0;
$inserted = 0;
$duplicates = 0;

$stmt = db()->prepare("INSERT IGNORE INTO zapisy
    (typ,pracovisite_id,datum,cw,rok,mesic,smena,zakaznik,projekt,verze,
     kod_pracovisite,nazev_pracovisite,nazev_dilu,sap_cislo,
     nakladove_stredisko,jmeno,pracovni_pozice,
     vyrobeno_ks,nok_ks,k_posouzeni,vyrobeno_dobre_ks,
     cisty_cas_min,pracovni_doba_min,prestas_min,
     oee_dostupnost,oee_vykon,oee_kvalita,oee_procent,
    norma_ks,cycle_time_min,final_typ,zapsal,imported,import_hash)
    VALUES
    (:typ,:prac_id,:datum,:cw,:rok,:mesic,:smena,:zakaznik,:projekt,:verze,
     :kp,:np,:nd,:sap,:ns,:jmeno,:pozice,
     :vyr,:nok,:pos,:dobre,
     :cisty,:prac_doba,:prestas,
    :oee_a,:oee_p,:oee_q,:oee,:norma,:cycle,:final,:zapsal,1,:import_hash)");

foreach ($rows as $r) {
    try {
        $prepared = importPrepareZapisyRow($r);
        $stmt->execute(importSqlBindParamsForZapisyInsert($prepared));
        $affected = (int)$stmt->rowCount();
        if ($affected > 0) {
            $inserted += $affected;
            $zapisId = (int)db()->lastInsertId();
            syncZapisyPracovnici(db(), $zapisId, buildZapisyWorkerRowsFromSummary(
                (string)($prepared[':jmeno'] ?? ''),
                (string)($prepared[':pozice'] ?? '')
            ));
            saveZapisyProstoje(db(), $zapisId, [
                'p1' => (int)($prepared[':p1'] ?? 0), 'p2' => (int)($prepared[':p2'] ?? 0),
                'p3' => (int)($prepared[':p3'] ?? 0), 'p4' => (int)($prepared[':p4'] ?? 0),
                'p5' => (int)($prepared[':p5'] ?? 0), 'p6' => (int)($prepared[':p6'] ?? 0),
                'p7' => (int)($prepared[':p7'] ?? 0), 'p8' => (int)($prepared[':p8'] ?? 0),
                'p9' => (int)($prepared[':p9'] ?? 0), 'p10' => (int)($prepared[':p10'] ?? 0),
                'o1' => (int)($prepared[':o1'] ?? 0),
                'l1' => (int)($prepared[':l1'] ?? 0), 'l2' => (int)($prepared[':l2'] ?? 0),
                'v1' => (int)($prepared[':v1'] ?? 0), 'v2' => (int)($prepared[':v2'] ?? 0), 'v3' => (int)($prepared[':v3'] ?? 0),
                'q1' => (int)($prepared[':q1'] ?? 0), 'q2' => (int)($prepared[':q2'] ?? 0),
                'q3' => (int)($prepared[':q3'] ?? 0), 'q4' => (int)($prepared[':q4'] ?? 0),
                'prostoj_technicky' => (int)($prepared[':pt'] ?? 0),
                'prostoj_vyrobni' => (int)($prepared[':pv'] ?? 0),
                'prostoj_kvalitativni' => (int)($prepared[':pk'] ?? 0),
                'prostoj_logisticky' => (int)($prepared[':pl'] ?? 0),
                'prostoj_ostatni' => (int)($prepared[':po'] ?? 0),
                'prostoj_planovany' => (int)($prepared[':pp'] ?? 0),
            ]);
        } else {
            $duplicates++;
        }
    } catch (Exception) {
        $dbErrors++;
    }
}

if ($copiedToTmp) {
    @unlink($tmpFile);
}

$durationMs = (int)round((microtime(true) - $start) * 1000);
$result = [
    'ok' => true,
    'forced' => $forceRun,
    'source_file' => $sourceFile,
    'source_file_mtime' => $sourceFileMtime,
    'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
    'working_file' => $workingFile,
    'copied_to_tmp' => $copiedToTmp,
    'total_rows' => count($rows),
    'inserted' => $inserted,
    'duplicates' => $duplicates,
    'parser_skipped' => $parserSkipped,
    'db_errors' => $dbErrors,
    'duration_ms' => $durationMs,
    'ran_at' => date('c'),
];

@file_put_contents($dailyLockFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

try {
    logAction(
        'AUTO_IMPORT',
        'zapisy',
        null,
        null,
        $result,
        'Automaticky import DVD-2026.xlsx'
    );
} catch (Exception) {
    // Ignorovat chyby auditu, import uz probehl.
}

respond($result, 200);
