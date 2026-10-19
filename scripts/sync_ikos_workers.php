#!/usr/bin/env php
<?php
/**
 * scripts/sync_ikos_workers.php
 *
 * Synchronizuje pracovniky ze souboru uploads/export_ikos.txt
 * do tabulky pracovnici.
 *
 * Pravidla:
 * - synchronizace probiha podle interni_id (1. sloupec CSV)
 * - nejdrive se vsichni pracovnici deaktivuji (aktivni=0)
 * - existujici interni_id se aktualizuje podle aktivniho DB zaznamu (vcetne zachovani jeho role)
 * - role existujicich pracovniku zustava beze zmeny; nove zaznamy pouziji DB default D
 * - chybejici interni_id se vlozi jako novy zaznam (aktivni=1)
 * - bez --force se pri nezmenenem mtime souboru synchronizace preskoci
 *
 * Web:
 *   /scripts/sync_ikos_workers.php
 *   /scripts/sync_ikos_workers.php?force=1
 *
 * CLI:
 *   php scripts/sync_ikos_workers.php
 *   php scripts/sync_ikos_workers.php --force
 */

require_once dirname(__DIR__) . '/includes/config.php';
ensurePracovniciClassColumn();

define('IKOS_WORKERS_SOURCE_FILE', APP_ROOT . '/uploads/export_ikos.txt');
define('IKOS_WORKERS_LOCK_FILE', UPLOAD_DIR . 'sync_ikos_workers.lock');
define('IKOS_WORKERS_STATE_FILE', UPLOAD_DIR . 'sync_ikos_workers_state.json');
define('IKOS_WORKERS_SYNC_VERSION', 3);

function respondIkosWorkers(array $payload, int $status = 200): never
{
    http_response_code($status);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function normalizeIkosValue(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    if ($value === null || $value === '') {
        return $value;
    }

    $isUtf8 = function_exists('mb_check_encoding')
        ? mb_check_encoding($value, 'UTF-8')
        : (preg_match('//u', $value) === 1);

    if (!$isUtf8) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
        if ($converted === false || $converted === '') {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $value);
        }
        if ($converted !== false && $converted !== '') {
            $value = $converted;
        }
    }

    return trim($value);
}

function parseIkosWorkerLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $cols = str_getcsv($line, ';');
    $interniId = normalizeIkosValue($cols[0] ?? null);
    $jmeno = normalizeIkosValue($cols[1] ?? null);
    $thirdValue = normalizeIkosValue($cols[2] ?? null);
    $fourthValue = normalizeIkosValue($cols[3] ?? null);
    $thirdDate = parseWorkerTerminationDate($thirdValue);
    $fourthDate = parseWorkerTerminationDate($fourthValue);
    $newColumnOrder = $thirdDate !== false && ($fourthDate === false || $fourthDate === null);
    $smennost = $newColumnOrder ? $fourthValue : $thirdValue;
    $datumUkonceni = $newColumnOrder ? $thirdDate : $fourthDate;

    if ($smennost === '0' || $smennost === '') {
        $smennost = null;
    }

    if ($interniId === null || $interniId === '' || $jmeno === null || $jmeno === '' || $datumUkonceni === false) {
        return null;
    }

    return [
        'interni_id' => $interniId,
        'jmeno' => $jmeno,
        'smennost' => $smennost,
        'datum_ukonceni' => $datumUkonceni,
    ];
}

function selectIkosExistingWorkersByInternalId(array $workers, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $selected = [];
    foreach ($workers as $worker) {
        $interniId = trim((string)($worker['interni_id'] ?? ''));
        if ($interniId === '') {
            continue;
        }

        $current = $selected[$interniId] ?? null;
        $workerActive = (string)($worker['datum_ukonceni'] ?? '') > $today;
        $currentActive = $current !== null && (string)($current['datum_ukonceni'] ?? '') > $today;
        if ($current === null || ($workerActive && !$currentActive)) {
            $selected[$interniId] = $worker;
        }
    }
    return $selected;
}

if (PHP_SAPI !== 'cli' && !canAdmin()) {
    respondIkosWorkers([
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

if (!$forceRun && is_file(IKOS_WORKERS_LOCK_FILE)) {
    $lock = json_decode((string)file_get_contents(IKOS_WORKERS_LOCK_FILE), true);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Sync IKOS pracovniku jiz bezi nebo nespatne skoncil. Pouzij force=1 nebo --force.',
        'lock' => is_array($lock) ? $lock : null,
    ], 409);
}

@file_put_contents(IKOS_WORKERS_LOCK_FILE, json_encode([
    'started_at' => date('c'),
    'pid' => getmypid(),
    'forced' => $forceRun,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$state = [];
if (is_file(IKOS_WORKERS_STATE_FILE)) {
    $state = (array)json_decode((string)file_get_contents(IKOS_WORKERS_STATE_FILE), true);
}

$sourceFile = IKOS_WORKERS_SOURCE_FILE;
$sourceFileMtime = @filemtime($sourceFile);
$sourceFileMtime = ($sourceFileMtime !== false) ? (int)$sourceFileMtime : null;

if (!is_file($sourceFile)) {
    @unlink(IKOS_WORKERS_LOCK_FILE);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Zdrojovy soubor neexistuje.',
        'source_file' => $sourceFile,
    ], 500);
}

if (!is_readable($sourceFile)) {
    @unlink(IKOS_WORKERS_LOCK_FILE);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Zdrojovy soubor neni citelny pro uzivatele web serveru.',
        'source_file' => $sourceFile,
    ], 500);
}

if (
    !$forceRun
    && $sourceFileMtime !== null
    && (int)($state['source_file_mtime'] ?? 0) === $sourceFileMtime
    && (int)($state['sync_version'] ?? 0) === IKOS_WORKERS_SYNC_VERSION
) {
    $state['last_checked_at'] = date('c');
    $state['last_skip_reason'] = 'source_unchanged';
    @file_put_contents(IKOS_WORKERS_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @unlink(IKOS_WORKERS_LOCK_FILE);

    respondIkosWorkers([
        'ok' => true,
        'skipped' => true,
        'reason' => 'source_unchanged',
        'source_file' => $sourceFile,
        'source_file_mtime' => $sourceFileMtime,
        'source_file_mtime_iso' => date('c', $sourceFileMtime),
        'state_file' => IKOS_WORKERS_STATE_FILE,
        'ran_at' => date('c'),
    ]);
}

$inserted = 0;
$updated = 0;
$skippedInvalid = 0;
$skippedDuplicateInFile = 0;
$skippedInactive = 0;
$errors = 0;
$processedLines = 0;

$handle = fopen($sourceFile, 'rb');
if ($handle === false) {
    @unlink(IKOS_WORKERS_LOCK_FILE);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Nepodarilo se otevrit zdrojovy soubor.',
        'source_file' => $sourceFile,
    ], 500);
}

// Parse all rows before selecting current employees from duplicate ID groups.
$rows = [];
while (($line = fgets($handle)) !== false) {
    $processedLines++;
    $parsed = parseIkosWorkerLine($line);

    if ($parsed === null) {
        $skippedInvalid++;
        continue;
    }

    $rows[] = $parsed;
}

fclose($handle);

$selection = selectActiveWorkerRowsByInternalId($rows);
$rows = $selection['rows'];
$skippedInactive = $selection['skipped_inactive'];
$skippedDuplicateInFile = $selection['skipped_duplicate_in_file'];
if ($rows === []) {
    @unlink(IKOS_WORKERS_LOCK_FILE);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Zdrojovy soubor neobsahuje zadneho aktualne zamestnaneho pracovnika; databaze nebyla zmenena.',
        'source_file' => $sourceFile,
        'source_file_mtime' => $sourceFileMtime,
        'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
        'processed_lines' => $processedLines,
        'skipped_duplicate_in_file' => $skippedDuplicateInFile,
        'skipped_inactive' => $skippedInactive,
        'skipped_invalid' => $skippedInvalid,
    ], 422);
}

try {
    db()->beginTransaction();

    $existingWorkers = db_all('SELECT id, interni_id, datum_ukonceni, `role` FROM pracovnici ORDER BY id');
    $existingByInterniId = selectIkosExistingWorkersByInternalId($existingWorkers);
    db()->exec('UPDATE pracovnici SET aktivni = 0');

    $updateStmt = db()->prepare('UPDATE pracovnici SET jmeno = ?, smennost = ?, datum_ukonceni = ?, `role` = ?, aktivni = 1 WHERE id = ?');
    $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id, jmeno, smennost, datum_ukonceni, aktivni) VALUES (?, ?, ?, ?, 1)');

    foreach ($rows as $row) {
        $interniId = $row['interni_id'];
        $jmeno = $row['jmeno'];
        $smennost = $row['smennost'];
        $datumUkonceni = $row['datum_ukonceni'];

        $existingWorker = $existingByInterniId[$interniId] ?? null;
        if ($existingWorker !== null) {
            $updateStmt->execute([$jmeno, $smennost, $datumUkonceni, $existingWorker['role'], $existingWorker['id']]);
            $updated++;
        } else {
            $insertStmt->execute([$interniId, $jmeno, $smennost, $datumUkonceni]);
            $inserted++;
        }
    }

    db()->commit();
} catch (Throwable) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    $errors++;
    @unlink(IKOS_WORKERS_LOCK_FILE);
    respondIkosWorkers([
        'ok' => false,
        'error' => 'Synchronizace pracovniku selhala pri zapisovani do databaze.',
        'source_file' => $sourceFile,
        'source_file_mtime' => $sourceFileMtime,
        'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
        'processed_lines' => $processedLines,
        'skipped_duplicate_in_file' => $skippedDuplicateInFile,
        'skipped_inactive' => $skippedInactive,
        'skipped_invalid' => $skippedInvalid,
    ], 500);
}

$durationMs = (int)round((microtime(true) - $start) * 1000);
$result = [
    'ok' => true,
    'forced' => $forceRun,
    'skipped' => false,
    'source_file' => $sourceFile,
    'source_file_mtime' => $sourceFileMtime,
    'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
    'processed_lines' => $processedLines,
    'inserted' => $inserted,
    'updated' => $updated,
    'inserted_or_updated' => $inserted + $updated,
    'skipped_duplicate_in_file' => $skippedDuplicateInFile,
    'skipped_inactive' => $skippedInactive,
    'skipped_invalid' => $skippedInvalid,
    'errors' => $errors,
    'duration_ms' => $durationMs,
    'ran_at' => date('c'),
    'state_file' => IKOS_WORKERS_STATE_FILE,
];

$state = [
    'sync_version' => IKOS_WORKERS_SYNC_VERSION,
    'source_file' => $sourceFile,
    'source_file_mtime' => $sourceFileMtime,
    'source_file_mtime_iso' => $sourceFileMtime ? date('c', $sourceFileMtime) : null,
    'last_run_at' => date('c'),
    'last_success_at' => date('c'),
    'last_skip_reason' => null,
    'last_result' => [
        'ok' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'inserted_or_updated' => $inserted + $updated,
        'skipped_duplicate_in_file' => $skippedDuplicateInFile,
        'skipped_inactive' => $skippedInactive,
        'skipped_invalid' => $skippedInvalid,
        'errors' => $errors,
        'duration_ms' => $durationMs,
        'mode' => 'refresh_by_interni_id',
    ],
];

@file_put_contents(IKOS_WORKERS_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@unlink(IKOS_WORKERS_LOCK_FILE);

try {
    logAction(
        'SYNC_IKOS_WORKERS',
        'pracovnici',
        null,
        null,
        $result,
        'Sync pracovniku ze souboru export_ikos.txt'
    );
} catch (Throwable) {
    // Audit log failure must not break sync response
}

respondIkosWorkers($result, 200);
