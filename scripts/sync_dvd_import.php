#!/usr/bin/env php
<?php
/**
 * scripts/sync_dvd_import.php
 *
 * Synchronizuje (importuje) DVD data ze sdileneho souboru na LBFILE01
 * do lokalni tabulky zapisy. Stav posledniho behu uklada do JSON souboru
 * stejne jako sync_traceability.php.
 *
 * Web:
 *   /scripts/sync_dvd_import.php
 *   /scripts/sync_dvd_import.php?force=1
 *
 * CLI:
 *   php scripts/sync_dvd_import.php
 *   php scripts/sync_dvd_import.php --force
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/import_runtime.php';

define('DVD_SYNC_SOURCE_FILE', getenv('DVD_AUTO_IMPORT_SOURCE') ?: '\\\\lbfile01.czlb-pms.prettl.pgn\\pacz\\Departments\\DVD\\DVD-2026.xlsx');
define('DVD_SYNC_LOCK_FILE', UPLOAD_DIR . 'sync_dvd_import.lock');
define('DVD_SYNC_STATE_FILE', UPLOAD_DIR . 'sync_dvd_state.json');

function respondDvdSync(array $payload, int $status = 200): never
{
    http_response_code($status);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function writeDvdSyncAudit(array $payload, string $description): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            null,
            PHP_SAPI === 'cli' ? 'system' : ($_SESSION['admin_user'] ?? 'anonymous'),
            'SYNC_DVD_IMPORT',
            'zapisy',
            null,
            null,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            '',
            $description,
        ]);
    } catch (Throwable $e) {
        error_log('DVD sync audit log error: ' . $e->getMessage());
    }
}

function decodePythonJsonOutputDvd(string $output): array
{
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

    error_log('sync_dvd_import invalid python output: ' . $output);

    return [
        'error' => 'Neplatny JSON z Pythonu: ' . json_last_error_msg(),
        'raw_output' => $output,
    ];
}

if (PHP_SAPI !== 'cli' && !canAdmin()) {
    respondDvdSync([
        'ok' => false,
        'error' => 'Pro spusteni je nutne prihlaseni jako admin nebo superadmin.',
    ], 403);
}

$start = microtime(true);
$isCli = PHP_SAPI === 'cli';
$args = $_SERVER['argv'] ?? [];
$forceRun = $isCli
    ? in_array('--force', $args, true)
    : (($_GET['force'] ?? '0') === '1');

if (!$forceRun && is_file(DVD_SYNC_LOCK_FILE)) {
    $lock = json_decode((string)file_get_contents(DVD_SYNC_LOCK_FILE), true);
    respondDvdSync([
        'ok' => false,
        'error' => 'Sync DVD jiz bezi nebo nespatne skoncil. Pouzij force=1 nebo --force.',
        'lock' => is_array($lock) ? $lock : null,
    ], 409);
}

@file_put_contents(DVD_SYNC_LOCK_FILE, json_encode([
    'started_at' => date('c'),
    'pid' => getmypid(),
    'forced' => $forceRun,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$state = [];
if (is_file(DVD_SYNC_STATE_FILE)) {
    $state = (array)json_decode((string)file_get_contents(DVD_SYNC_STATE_FILE), true);
}

$sourceFile = DVD_SYNC_SOURCE_FILE;
$sourceFileMtime = @filemtime($sourceFile);
$sourceFileMtime = ($sourceFileMtime !== false) ? (int)$sourceFileMtime : null;

if (!is_file($sourceFile)) {
    @unlink(DVD_SYNC_LOCK_FILE);
    respondDvdSync([
        'ok' => false,
        'error' => 'Zdrojovy soubor neexistuje.',
        'source_file' => $sourceFile,
    ], 500);
}

if (!is_readable($sourceFile)) {
    @unlink(DVD_SYNC_LOCK_FILE);
    respondDvdSync([
        'ok' => false,
        'error' => 'Zdrojovy soubor neni citelny pro uzivatele web serveru.',
        'source_file' => $sourceFile,
    ], 500);
}

if (
    !$forceRun
    && $sourceFileMtime !== null
    && (int)($state['source_file_mtime'] ?? 0) === $sourceFileMtime
) {
    $state['last_checked_at'] = date('c');
    $state['last_skip_reason'] = 'source_unchanged';
    @file_put_contents(DVD_SYNC_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @unlink(DVD_SYNC_LOCK_FILE);

    $skipResult = [
        'ok' => true,
        'skipped' => true,
        'reason' => 'source_unchanged',
        'source_file' => $sourceFile,
        'source_file_mtime' => $sourceFileMtime,
        'source_file_mtime_iso' => date('c', $sourceFileMtime),
        'state_file' => DVD_SYNC_STATE_FILE,
        'ran_at' => date('c'),
    ];

    writeDvdSyncAudit($skipResult, 'Sync DVD import from LBFILE01 (skipped - file unchanged)');

    respondDvdSync($skipResult);
}

$tmpFile = UPLOAD_DIR . 'sync_dvd_' . bin2hex(random_bytes(8)) . '_' . basename($sourceFile);
$workingFile = $sourceFile;
$copiedToTmp = false;

if (is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR) && @copy($sourceFile, $tmpFile)) {
    $workingFile = $tmpFile;
    $copiedToTmp = true;
}

$pythonCommand = getPythonImportCommand();
if ($pythonCommand === null) {
    if ($copiedToTmp) {
        @unlink($tmpFile);
    }
    @unlink(DVD_SYNC_LOCK_FILE);
    respondDvdSync([
        'ok' => false,
        'error' => 'Python interpreter nebyl nalezen.',
    ], 500);
}

$script = APP_ROOT . '/includes/import_excel.py';
$jsonOut = shell_exec($pythonCommand . ' ' . escapeshellarg($script) . ' import ' . escapeshellarg($workingFile) . ' 2>&1');
$data = decodePythonJsonOutputDvd($jsonOut ?: '{}');

if (empty($data['imported']) && empty($data['rows'])) {
    if ($copiedToTmp) {
        @unlink($tmpFile);
    }
    @unlink(DVD_SYNC_LOCK_FILE);
    respondDvdSync([
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
    } catch (Throwable) {
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
    'skipped' => false,
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
    'state_file' => DVD_SYNC_STATE_FILE,
];

$state = [
    'source_file' => $sourceFile,
    'source_file_mtime' => $sourceFileMtime,
    'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
    'last_run_at' => date('c'),
    'last_success_at' => date('c'),
    'last_skip_reason' => null,
    'last_result' => [
        'ok' => true,
        'inserted' => $inserted,
        'duplicates' => $duplicates,
        'parser_skipped' => $parserSkipped,
        'db_errors' => $dbErrors,
        'duration_ms' => $durationMs,
    ],
];

@file_put_contents(DVD_SYNC_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@unlink(DVD_SYNC_LOCK_FILE);

writeDvdSyncAudit($result, 'Sync DVD import from LBFILE01');

respondDvdSync($result, 200);
