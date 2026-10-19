<?php
// admin/pracovnici.php
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
ensurePracovniciClassColumn();
$pageTitle = t('nav_workers'); $activePage = 'pracovnici';

function normalizeCsvValue(?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    if ($value === null || $value === '') {
        return $value;
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
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

function normalizeWorkerClassCsvHeader(?string $value): string {
    $value = normalizeCsvValue($value) ?? '';
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($value));
}

function normalizeWorkerPersonalNumber(?string $value): ?string {
    $value = normalizeCsvValue($value);
    if ($value !== null && preg_match('/^\d+$/', $value)) {
        $value = ltrim($value, '0');
        return $value !== '' ? $value : '0';
    }
    return $value;
}

function workerDuplicateGroupEndedBefore(array $rows, string $today): bool {
    if (count($rows) < 2) {
        return false;
    }
    foreach ($rows as $row) {
        $endDate = (string)($row['datum_ukonceni'] ?? '');
        if ($endDate === '' || $endDate >= $today) {
            return false;
        }
    }
    return true;
}

function workerImportActiveDuplicateIndex(array $rows, string $today): ?int {
    foreach ($rows as $index => $row) {
        $endDate = (string)($row['datum_ukonceni'] ?? '');
        if ($endDate !== '' && $endDate > $today) {
            return (int)$index;
        }
    }
    return null;
}

function detectWorkerClassCsvDelimiter(string $headerLine): string {
    $delimiter = ';';
    $maxColumns = 0;
    foreach ([';', ',', "\t"] as $candidate) {
        $columns = count(str_getcsv($headerLine, $candidate));
        if ($columns > $maxColumns) {
            $delimiter = $candidate;
            $maxColumns = $columns;
        }
    }
    return $delimiter;
}

function workerImportHeaderIndexes(array $headers): array {
    $aliases = [
        'interni_id' => ['interniid', 'internalid'],
        'jmeno' => ['jmeno', 'name'],
        'ukonceni' => ['ukonceni', 'datumukonceni', 'employmentenddate'],
        'smennost' => ['smennost', 'shift', 'shiftassignment'],
        'role' => ['role'],
    ];
    $indexes = [];
    foreach ($headers as $index => $header) {
        $normalized = normalizeWorkerClassCsvHeader((string)$header);
        foreach ($aliases as $field => $fieldAliases) {
            if (in_array($normalized, $fieldAliases, true)) {
                $indexes[$field] = $index;
                break;
            }
        }
    }
    return $indexes;
}

function parseWorkerImportCsvRow(array $columns, array $headerIndexes): ?array {
    $value = static function (string $field, int $fallbackIndex) use ($columns, $headerIndexes): ?string {
        $index = $headerIndexes[$field] ?? (empty($headerIndexes) ? $fallbackIndex : null);
        return normalizeCsvValue($index !== null && isset($columns[$index]) ? (string)$columns[$index] : null);
    };

    $interniId = $value('interni_id', 0);
    $jmeno = $value('jmeno', 1);
    $datumUkonceni = parseWorkerTerminationDate($value('ukonceni', 2));
    $smennost = $value('smennost', 3);
    $role = $value('role', 4);

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
        'role' => $role !== null && $role !== '' ? $role : 'D',
        'datum_ukonceni' => $datumUkonceni,
    ];
}

function findWorkerRoleConflicts(array $rows): array {
    $existingWorkers = db_all('SELECT interni_id, jmeno, `role` FROM pracovnici');
    $existingRoles = array_column($existingWorkers, 'role', 'interni_id');
    $existingNames = array_column($existingWorkers, 'jmeno', 'interni_id');
    $csvRoles = [];
    $csvNames = [];
    foreach ($rows as $row) {
        $interniId = (string)$row['interni_id'];
        $csvRoles[$interniId][(string)($row['role'] ?? 'D')] = true;
        $csvNames[$interniId] ??= (string)($row['jmeno'] ?? '');
    }

    $conflicts = [];
    foreach ($csvRoles as $interniId => $roles) {
        $existing = (string)($existingRoles[$interniId] ?? 'D');
        $differing = array_values(array_filter(array_map('strval', array_keys($roles)), static fn(string $role): bool => $role !== $existing));
        if ($existing !== 'D' && $differing !== []) {
            $conflicts[] = [
                'interni_id' => (string)$interniId,
                'jmeno' => (string)($existingNames[$interniId] ?? $csvNames[$interniId] ?? ''),
                'options' => [$existing, ...$differing],
            ];
        }
    }
    return $conflicts;
}

function applyWorkerRoleChoices(array $rows, array $conflicts, array $choices): array {
    $conflictByInterniId = [];
    foreach ($conflicts as $index => $conflict) {
        $conflictByInterniId[(string)$conflict['interni_id']] = [$index, $conflict['options']];
    }

    foreach ($rows as &$row) {
        [$index, $options] = $conflictByInterniId[(string)$row['interni_id']] ?? [null, null];
        if ($options === null) {
            continue;
        }
        $choice = filter_var($choices[$index] ?? 0, FILTER_VALIDATE_INT);
        $row['role'] = $options[$choice === false ? 0 : $choice] ?? $options[0];
    }
    unset($row);
    return $rows;
}

function openWorkerClassCsv(string $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return false;
    }
    if (str_starts_with($contents, "\xFF\xFE")) {
        $contents = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($contents, 2));
    } elseif (str_starts_with($contents, "\xFE\xFF")) {
        $contents = iconv('UTF-16BE', 'UTF-8//IGNORE', substr($contents, 2));
    } elseif (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    } elseif (!mb_check_encoding($contents, 'UTF-8')) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $contents);
        if ($converted === false || $converted === '') {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $contents);
        }
        $contents = $converted;
    }
    if (!is_string($contents)) {
        return false;
    }
    $handle = fopen('php://temp', 'r+');
    if ($handle === false || fwrite($handle, $contents) === false) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }
    rewind($handle);
    return $handle;
}

function findPracovnikByName(string $jmeno, ?int $excludeId = null): array|false {
    $sql = 'SELECT * FROM pracovnici WHERE jmeno = ?';
    $params = [$jmeno];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY aktivni DESC, id ASC LIMIT 1';
    return db_row($sql, $params);
}

function parseIkosWorkerLineForReplace(string $line): ?array {
    $line = trim($line);
    if ($line === '') {
        return null;
    }
    $cols = str_getcsv($line, ';');
    $interniId = normalizeCsvValue($cols[0] ?? null);
    $jmeno = normalizeCsvValue($cols[1] ?? null);
    $thirdValue = normalizeCsvValue($cols[2] ?? null);
    $fourthValue = normalizeCsvValue($cols[3] ?? null);
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
        'role' => 'D',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'sync_replace_workers') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $sourceFile = APP_ROOT . '/uploads/export_ikos.txt';
    if (!is_file($sourceFile) || !is_readable($sourceFile)) {
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }
    $rows = [];
    $skip = 0;
    $imp = 0;
    $handle = fopen($sourceFile, 'rb');
    if ($handle === false) {
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }
    while (($line = fgets($handle)) !== false) {
        $parsed = parseIkosWorkerLineForReplace($line);
        if ($parsed === null) {
            $skip++;
            continue;
        }

        $rows[] = $parsed;
    }
    fclose($handle);

    $selection = selectActiveWorkerRowsByInternalId($rows);
    $rows = $selection['rows'];
    $skip += $selection['skipped_inactive'] + $selection['skipped_duplicate_in_file'];
    if ($rows === []) {
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }

    try {
        db()->beginTransaction();

        $existingWorkers = db_all('SELECT interni_id, trida, `role` FROM pracovnici');
        $existingClasses = array_column($existingWorkers, 'trida', 'interni_id');
        $existingRoles = array_column($existingWorkers, 'role', 'interni_id');

        // Full replace mode: remove all current rows and import the file content.
        db()->exec('DELETE FROM pracovnici');

        $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id, jmeno, smennost, trida, datum_ukonceni, `role`, aktivni) VALUES (?, ?, ?, ?, ?, ?, 1)');

        foreach ($rows as $row) {
            $interniId = $row['interni_id'];
            $jmeno = $row['jmeno'];
            $smennost = $row['smennost'];
            $trida = $existingClasses[$interniId] ?? null;
            $datumUkonceni = $row['datum_ukonceni'];
            $role = $existingRoles[$interniId] ?? 'D';

            $insertStmt->execute([$interniId, $jmeno, $smennost, $trida, $datumUkonceni, $role]);
            $imp += 1;
        }

        db()->commit();

        $stateFile = APP_ROOT . '/uploads/tmp/sync_ikos_workers_state.json';
        @file_put_contents($stateFile, json_encode([
            'source_file' => $sourceFile,
            'source_file_mtime' => @filemtime($sourceFile) ?: null,
            'source_file_mtime_iso' => @filemtime($sourceFile) ? date('c', (int)@filemtime($sourceFile)) : null,
            'last_run_at' => date('c'),
            'last_success_at' => date('c'),
            'last_skip_reason' => null,
            'last_result' => [
                'ok' => true,
                'inserted' => $imp,
                'skipped_invalid' => $skip,
                'mode' => 'full_replace',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        logAction(
            'SYNC_IKOS_WORKERS',
            'pracovnici',
            null,
            null,
            ['inserted' => $imp, 'skipped' => $skip, 'mode' => 'full_replace'],
            'Rucni sync pracovniku (full replace ze souboru)'
        );

        redirect(APP_URL . '/admin/pracovnici.php?sync=1&imp=' . $imp . '&skip=' . $skip);
    } catch (Throwable) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        redirect(APP_URL . '/admin/pracovnici.php?sync=0');
    }
}

if (get('action') === 'delete' && get('id')) {
    db()->prepare('UPDATE pracovnici SET aktivni=0 WHERE id=?')->execute([(int)get('id')]);
    redirect(APP_URL.'/admin/pracovnici.php?ok=1');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && post('action')==='save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $id = (int)post('id');
    $interniId = trim((string)post('interni_id'));
    $jmeno = trim((string)post('jmeno'));
    $smennost = post('smennost')?: null;
    $role = (string)post('role', 'D');
    if (trim($role) === '') {
        $role = 'D';
    }
    $aktivni = post('aktivni')?1:0;
    $datumUkonceni = parseWorkerTerminationDate((string)post('datum_ukonceni', ''));
    if ($datumUkonceni === false) {
        $msg = t('worker_end_date_invalid');
    } else {
    $data = [$interniId, $jmeno, $smennost, $datumUkonceni, $role, $aktivni];

    if ($id) {
        db()->prepare('UPDATE pracovnici SET interni_id=?,jmeno=?,smennost=?,datum_ukonceni=?,`role`=?,aktivni=? WHERE id=?')->execute([...$data,$id]);
    } else {
        $existing = $jmeno !== '' ? findPracovnikByName($jmeno) : false;
        if ($existing) {
            db()->prepare('UPDATE pracovnici SET interni_id=?, smennost=?, datum_ukonceni=?, `role`=?, aktivni=? WHERE id=?')->execute([$interniId, $smennost, $datumUkonceni, $role, $aktivni, (int)$existing['id']]);
        } else {
            db()->prepare('INSERT IGNORE INTO pracovnici (interni_id,jmeno,smennost,datum_ukonceni,`role`,aktivni) VALUES (?,?,?,?,?,?)')->execute($data);
        }
    }
    redirect(APP_URL.'/admin/pracovnici.php?ok=1');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import_classes') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }

    $imported = 0;
    $skipped = 0;
    $csvTmp = isset($_FILES['class_csv_file']) ? validateUploadedCsv($_FILES['class_csv_file']) : null;
    $message = '';

    if ($csvTmp !== null) {
        $handle = openWorkerClassCsv($csvTmp);
        if ($handle !== false) {
            $headerLine = fgets($handle);
            $delimiter = detectWorkerClassCsvDelimiter((string)$headerLine);
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            $headerIndexes = [];
            foreach (($headers ?: []) as $index => $header) {
                $normalizedHeader = normalizeWorkerClassCsvHeader((string)$header);
                if (in_array($normalizedHeader, ['oscislo', 'osobnicislo'], true)) {
                    $headerIndexes['personal_number'] = $index;
                } elseif ($normalizedHeader === 'trida') {
                    $headerIndexes['class'] = $index;
                }
            }

            if (!isset($headerIndexes['personal_number'], $headerIndexes['class'])) {
                $message = t('workers_class_import_invalid_format');
            } else {
                $rows = [];
                $seenPersonalNumbers = [];
                while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $personalNumber = normalizeWorkerPersonalNumber($csvRow[$headerIndexes['personal_number']] ?? null);
                    $class = normalizeCsvValue($csvRow[$headerIndexes['class']] ?? null);
                    if ($personalNumber === null || $personalNumber === '' || $class === null || $class === '') {
                        $skipped++;
                        continue;
                    }
                    if (isset($seenPersonalNumbers[$personalNumber])) {
                        $skipped++;
                        continue;
                    }
                    $seenPersonalNumbers[$personalNumber] = true;
                    $rows[] = [$personalNumber, $class];
                }

                try {
                    db()->beginTransaction();
                    $findWorker = db()->prepare('SELECT id FROM pracovnici WHERE interni_id = ? LIMIT 1');
                    $updateClass = db()->prepare('UPDATE pracovnici SET trida = ? WHERE interni_id = ?');
                    foreach ($rows as [$personalNumber, $class]) {
                        $findWorker->execute([$personalNumber]);
                        if (!$findWorker->fetchColumn()) {
                            $skipped++;
                            continue;
                        }
                        $updateClass->execute([$class, $personalNumber]);
                        $imported++;
                    }
                    db()->commit();
                    $message = t('workers_class_import_result') . ': ' . $imported . ' ' . t('import_rows') . ', ' . $skipped . ' ' . t('import_skip');
                } catch (Throwable $e) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    error_log('Worker class CSV import failed: ' . $e->getMessage());
                    $message = t('workers_class_import_failed');
                }
            }
            fclose($handle);
        } else {
            $message = t('workers_class_import_failed');
        }
    } else {
        $message = t('workers_class_import_failed');
    }
}
$workerImportReview = null;
$pendingWorkerImports = $_SESSION['pending_worker_imports'] ?? [];
if (!is_array($pendingWorkerImports)) {
    $pendingWorkerImports = [];
}
foreach ($pendingWorkerImports as $pendingToken => $pendingImport) {
    if (!is_array($pendingImport) || (int)($pendingImport['created_at'] ?? 0) <= time() - 1800) {
        unset($pendingWorkerImports[$pendingToken]);
    }
}
$_SESSION['pending_worker_imports'] = $pendingWorkerImports;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import_cancel') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $token = trim((string)post('import_token'));
    unset($pendingWorkerImports[$token]);
    $_SESSION['pending_worker_imports'] = $pendingWorkerImports;
    redirect(APP_URL . '/admin/pracovnici.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import_confirm') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $token = trim((string)post('import_token'));
    $pending = $pendingWorkerImports[$token] ?? null;
    if (!is_array($pending)) {
        $msg = t('worker_import_pending_expired');
    } else {
        $rowsToImport = $pending['unique_rows'] ?? [];
        $skip = (int)($pending['invalid_rows'] ?? 0);
        $choices = post('duplicate_choice', []);
        $choices = is_array($choices) ? $choices : [];
        foreach (($pending['duplicate_groups'] ?? []) as $groupIndex => $group) {
            if (array_key_exists($groupIndex, $choices) && $choices[$groupIndex] === '') {
                $choice = null;
            } else {
                $choiceValue = $choices[$groupIndex] ?? $group['default_choice'] ?? null;
                $choice = filter_var($choiceValue, FILTER_VALIDATE_INT);
            }
            if ($choice !== null && ($choice === false || !isset($group['rows'][$choice]))) {
                $defaultChoice = filter_var($group['default_choice'] ?? null, FILTER_VALIDATE_INT);
                $choice = $defaultChoice !== false && $defaultChoice !== null && isset($group['rows'][$defaultChoice])
                    ? $defaultChoice
                    : null;
            }
            if ($choice !== false && $choice !== null && isset($group['rows'][$choice])) {
                $rowsToImport[] = $group['rows'][$choice];
                $skip += count($group['rows']) - 1;
            } else {
                $skip += count($group['rows']);
            }
        }

        $roleChoices = post('role_choice', []);
        $rowsToImport = applyWorkerRoleChoices($rowsToImport, $pending['role_conflicts'] ?? [], is_array($roleChoices) ? $roleChoices : []);

        if ($rowsToImport === []) {
            $msg = t('worker_import_no_rows_selected');
        } else try {
            db()->beginTransaction();
            $existingClasses = array_column(db_all('SELECT interni_id, trida FROM pracovnici'), 'trida', 'interni_id');
            db()->exec('DELETE FROM pracovnici');
            $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id,jmeno,smennost,trida,datum_ukonceni,`role`,aktivni) VALUES (?,?,?,?,?,?,1)');
            foreach ($rowsToImport as $row) {
                $insertStmt->execute([
                    $row['interni_id'],
                    $row['jmeno'],
                    $row['smennost'],
                    $existingClasses[$row['interni_id']] ?? null,
                    $row['datum_ukonceni'],
                    $row['role'] ?? 'D',
                ]);
            }
            db()->commit();
            unset($pendingWorkerImports[$token]);
            $_SESSION['pending_worker_imports'] = $pendingWorkerImports;
            redirect(APP_URL . '/admin/pracovnici.php?imported=' . count($rowsToImport) . '&skip=' . $skip);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Worker CSV import failed: ' . $e->getMessage());
            $msg = t('import_error') . ' ' . t('worker_import_failed');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $skip = 0;
    $csvTmp = isset($_FILES['csv_file']) ? validateUploadedCsv($_FILES['csv_file']) : null;
    $handle = $csvTmp !== null ? openWorkerClassCsv($csvTmp) : false;
    if ($handle === false) {
        $msg = t('worker_import_failed');
    } else {
        $headerLine = fgets($handle);
        $delimiter = detectWorkerClassCsvDelimiter((string)$headerLine);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter);
        $headerIndexes = workerImportHeaderIndexes($headers ?: []);
        $rowsById = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $parsed = parseWorkerImportCsvRow($row, $headerIndexes);
            if ($parsed === null) {
                $skip++;
                continue;
            }
            $rowsById['id:' . $parsed['interni_id']][] = $parsed;
        }
        fclose($handle);

        $uniqueRows = [];
        $duplicateGroups = [];
        foreach ($rowsById as $rows) {
            if (count($rows) === 1) {
                $uniqueRows[] = $rows[0];
            } elseif (workerDuplicateGroupEndedBefore($rows, date('Y-m-d'))) {
                $skip += count($rows);
            } else {
                $duplicateGroups[] = [
                    'interni_id' => $rows[0]['interni_id'],
                    'rows' => $rows,
                    'default_choice' => workerImportActiveDuplicateIndex($rows, date('Y-m-d')),
                ];
            }
        }

        $roleConflicts = findWorkerRoleConflicts(array_merge($uniqueRows, ...array_column($duplicateGroups, 'rows')));

        if ($duplicateGroups !== [] || $roleConflicts !== []) {
            $token = bin2hex(random_bytes(16));
            $pendingWorkerImports[$token] = [
                'unique_rows' => $uniqueRows,
                'duplicate_groups' => $duplicateGroups,
                'role_conflicts' => $roleConflicts,
                'invalid_rows' => $skip,
                'created_at' => time(),
            ];
            $_SESSION['pending_worker_imports'] = $pendingWorkerImports;
            redirect(APP_URL . '/admin/pracovnici.php?worker_import_review=' . $token);
        }

        if ($uniqueRows === []) {
            $msg = t('worker_import_no_valid_rows');
        } else try {
            db()->beginTransaction();
            $existingClasses = array_column(db_all('SELECT interni_id, trida FROM pracovnici'), 'trida', 'interni_id');
            db()->exec('DELETE FROM pracovnici');
            $insertStmt = db()->prepare('INSERT INTO pracovnici (interni_id,jmeno,smennost,trida,datum_ukonceni,`role`,aktivni) VALUES (?,?,?,?,?,?,1)');
            foreach ($uniqueRows as $row) {
                $insertStmt->execute([$row['interni_id'], $row['jmeno'], $row['smennost'], $existingClasses[$row['interni_id']] ?? null, $row['datum_ukonceni'], $row['role'] ?? 'D']);
            }
            db()->commit();
            redirect(APP_URL . '/admin/pracovnici.php?imported=' . count($uniqueRows) . '&skip=' . $skip);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Worker CSV import failed: ' . $e->getMessage());
            $msg = t('import_error') . ' ' . t('worker_import_failed');
        }
    }
}

if (isset($_GET['worker_import_review'])) {
    $reviewToken = trim((string)get('worker_import_review'));
    $candidateReview = $pendingWorkerImports[$reviewToken] ?? null;
    if (is_array($candidateReview) && (int)($candidateReview['created_at'] ?? 0) > time() - 1800) {
        $workerImportReview = ['token' => $reviewToken] + $candidateReview;
    } elseif ($candidateReview !== null) {
        unset($pendingWorkerImports[$reviewToken]);
        $_SESSION['pending_worker_imports'] = $pendingWorkerImports;
        $msg = t('worker_import_pending_expired');
    }
}
$edit = null;
if (get('edit')) $edit = get('edit')==='new' ? [] : db_row('SELECT * FROM pracovnici WHERE id=?',[(int)get('edit')]);
$fInterniId = trim((string)get('filter_id', ''));
$fName = trim((string)get('filter_name', ''));
$fShift = trim((string)get('filter_shift', ''));
$fRole = trim((string)get('filter_role', ''));
$fClass = trim((string)get('filter_class', ''));
$shiftOptions = array_column(db_all("SELECT DISTINCT smennost FROM pracovnici WHERE smennost IS NOT NULL AND TRIM(smennost) <> '' ORDER BY smennost"), 'smennost');
$roleOptions = array_column(db_all("SELECT DISTINCT `role` FROM pracovnici WHERE `role` IS NOT NULL AND TRIM(`role`) <> '' ORDER BY `role`"), 'role');
$classOptions = array_column(db_all("SELECT DISTINCT trida FROM pracovnici WHERE trida IS NOT NULL AND TRIM(trida) <> '' ORDER BY trida"), 'trida');
$fEmployment = (string)get('employment', 'active');
$sortColumn = (string)get('sort', '');
$sortDirection = strtolower((string)get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
$sortColumns = [
    'interni_id' => 'interni_id',
    'jmeno' => 'jmeno',
    'smennost' => 'smennost',
    'role' => 'role',
    'trida' => 'trida',
    'status' => '(CASE WHEN aktivni = 1 AND datum_ukonceni > CURDATE() THEN 1 ELSE 0 END)',
];
if ($sortColumn !== '' && !isset($sortColumns[$sortColumn])) {
    $sortColumn = '';
}
$sortUrl = static function (string $column) use ($sortColumn, $sortDirection): string {
    $query = $_GET;
    if ($sortColumn !== $column) {
        $query['sort'] = $column;
        $query['dir'] = 'asc';
    } elseif ($sortDirection === 'ASC') {
        $query['sort'] = $column;
        $query['dir'] = 'desc';
    } else {
        unset($query['sort'], $query['dir']);
    }
    return '?' . http_build_query($query);
};
$sortIcon = static function (string $column) use ($sortColumn, $sortDirection): string {
    return $sortColumn !== $column ? 'bi-arrow-down-up' : ($sortDirection === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down');
};
$where = [];
$params = [];
foreach ([
    ['interni_id', $fInterniId],
    ['jmeno', $fName],
] as [$column, $value]) {
    if ($value !== '') {
        $where[] = $column . ' LIKE ?';
        $params[] = '%' . $value . '%';
    }
}
if ($fRole !== '') {
    $where[] = '`role` = ?';
    $params[] = $fRole;
}
if ($fShift !== '') {
    $where[] = 'smennost = ?';
    $params[] = $fShift;
}
if ($fClass !== '') {
    $where[] = 'trida = ?';
    $params[] = $fClass;
}
if ($fEmployment === 'active') {
    $where[] = 'aktivni = 1 AND datum_ukonceni > CURDATE()';
} elseif ($fEmployment === 'ended') {
    $where[] = '(aktivni = 0 OR datum_ukonceni IS NULL OR datum_ukonceni <= CURDATE())';
}
$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
$orderSql = $sortColumn === '' ? 'id ASC' : $sortColumns[$sortColumn] . ' ' . $sortDirection . ', jmeno ASC';
$list = db_all('SELECT * FROM pracovnici WHERE ' . $whereSql . ' ORDER BY ' . $orderSql, $params);

$ikosSyncState = [];
$ikosSyncAt = null;
$ikosSyncStateFile = APP_ROOT . '/uploads/tmp/sync_ikos_workers_state.json';
if (is_file($ikosSyncStateFile)) {
    $ikosSyncState = (array)json_decode((string)file_get_contents($ikosSyncStateFile), true);
    $ikosSyncAt = (string)($ikosSyncState['last_success_at'] ?? $ikosSyncState['last_run_at'] ?? '');
}
$ikosSyncLabel = $ikosSyncAt ? h(date('d.m.Y H:i', strtotime($ikosSyncAt))) : '—';

if (get('sync') === '1') {
    $msg = 'Synchronizace dokončena: ' . (int)get('imp', 0) . ' záznamů vloženo, ' . (int)get('skip', 0) . ' přeskočeno.';
} elseif (get('sync') === '0') {
    $msg = 'Synchronizace se nepodařila.';
}
if (isset($_GET['imported'])) {
    $msg = t('worker_import_result') . ': ' . (int)get('imported', 0) . ' ' . t('import_rows') . ', ' . (int)get('skip', 0) . ' ' . t('import_skip');
}

require APP_ROOT.'/includes/layout.php';
?>
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= t('nav_workers') ?></span>
        <?php if (isSuperAdmin()): ?>
        <form method="post" class="d-inline no-print" style="margin:0">
            <input type="hidden" name="action" value="sync_replace_workers">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <button
                type="submit"
                class="topbar-meta"
                style="text-decoration:none;background:none;border:none;padding:0;cursor:pointer"
                title="<?= t('sync_run_now') ?>"
                data-confirm="Opravdu refreshnout operátory podle interního ID (1. sloupec CSV)?"
            ><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $ikosSyncLabel ?></strong></button>
        </form>
        <?php else: ?>
        <span class="topbar-meta"><i class="bi bi-arrow-repeat me-1"></i><?= t('last_sync') ?>: <strong><?= $ikosSyncLabel ?></strong></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <button type="button" class="btn-dvd btn-dvd-sm" data-bs-toggle="modal" data-bs-target="#worker-modal"><i class="bi bi-plus-lg me-1"></i><?= t('add') ?></button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>
<div class="content">
<?php if (get('ok')): ?><div class="alert mb-3" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;font-size:.82rem">✓ <?= t('saved') ?></div><?php endif; ?>
<?php if (isset($msg)): ?><div class="alert mb-3" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.65rem 1rem;font-size:.82rem"><?= h($msg) ?></div><?php endif; ?>
<?php if (isset($classImportMsg)): ?><div class="alert mb-3" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.65rem 1rem;font-size:.82rem"><?= h($classImportMsg) ?></div><?php endif; ?>

<?php if ($workerImportReview !== null): ?>
<div class="modal fade" id="worker-duplicates-modal" tabindex="-1" aria-labelledby="worker-duplicates-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background:var(--surface);color:var(--text);border:1px solid var(--border);max-height:calc(100vh - 2rem);overflow:hidden">
            <div class="modal-header" style="border-color:var(--border)">
                <h2 class="modal-title fs-5" id="worker-duplicates-title"><?= t('worker_import_review_title') ?></h2>
            </div>
            <form method="post" style="display:flex;flex:1 1 auto;flex-direction:column;min-height:0;overflow:hidden">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="import_token" value="<?= h($workerImportReview['token']) ?>">
                <div class="modal-body" style="flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain">
                    <?php if (!empty($workerImportReview['duplicate_groups'])): ?><p><?= h(t('worker_import_duplicates_intro')) ?></p><?php endif; ?>
                    <p class="text-muted-dvd" style="font-size:.82rem"><?= h(t('worker_import_unique_rows')) ?>: <?= count($workerImportReview['unique_rows'] ?? []) ?></p>
                    <?php foreach (($workerImportReview['duplicate_groups'] ?? []) as $groupIndex => $group): ?>
                    <fieldset class="mb-3 p-3" style="border:1px solid var(--border);border-radius:6px">
                        <legend class="float-none w-auto px-2" style="font-size:.9rem;font-weight:600"><?= h(t('internal_id')) ?>: <?= h((string)$group['interni_id']) ?></legend>
                        <?php foreach ($group['rows'] as $candidateIndex => $candidate): ?>
                        <label class="d-flex align-items-start gap-2 py-2" style="border-bottom:1px solid var(--border);cursor:pointer">
                            <input class="form-check-input mt-1" type="radio" name="duplicate_choice[<?= (int)$groupIndex ?>]" value="<?= (int)$candidateIndex ?>" <?= $candidateIndex === ($group['default_choice'] ?? null) ? 'checked' : '' ?>>
                            <span><strong><?= h($candidate['jmeno']) ?></strong><br><small><?= h(t('worker_end_date')) ?>: <?= h($candidate['datum_ukonceni'] ?? '—') ?> · <?= h(t('worker_role')) ?>: <?= h($candidate['role'] ?? 'D') ?></small></span>
                        </label>
                        <?php endforeach; ?>
                        <label class="d-flex align-items-center gap-2 pt-2" style="cursor:pointer">
                            <input class="form-check-input" type="radio" name="duplicate_choice[<?= (int)$groupIndex ?>]" value="" <?= ($group['default_choice'] ?? null) === null ? 'checked' : '' ?>>
                            <span><?= h(t('worker_import_choice_none')) ?></span>
                        </label>
                    </fieldset>
                    <?php endforeach; ?>
                    <?php if (!empty($workerImportReview['role_conflicts'])): ?>
                    <h3 class="fs-6 mt-3"><?= h(t('worker_import_role_conflicts_title')) ?></h3>
                    <p class="text-muted-dvd" style="font-size:.82rem"><?= h(t('worker_import_role_conflicts_intro')) ?></p>
                    <?php foreach ($workerImportReview['role_conflicts'] as $conflictIndex => $conflict): ?>
                    <fieldset class="mb-3 p-3" style="border:1px solid var(--border);border-radius:6px">
                        <legend class="float-none w-auto px-2" style="font-size:.9rem;font-weight:600"><?= h((string)$conflict['jmeno']) ?> · <?= h(t('internal_id')) ?>: <?= h((string)$conflict['interni_id']) ?></legend>
                        <?php foreach ($conflict['options'] as $optionIndex => $roleOption): ?>
                        <label class="d-flex align-items-center gap-2 py-1" style="cursor:pointer">
                            <input class="form-check-input" type="radio" name="role_choice[<?= (int)$conflictIndex ?>]" value="<?= (int)$optionIndex ?>" <?= $optionIndex === 0 ? 'checked' : '' ?>>
                            <span><?= h(t($optionIndex === 0 ? 'worker_import_role_keep_existing' : 'worker_import_role_use_csv')) ?>: <strong><?= h((string)$roleOption) ?></strong></span>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="modal-footer" style="border-color:var(--border)">
                    <button type="submit" name="action" value="csv_import_confirm" class="btn-dvd btn-dvd-sm"><?= t('worker_import_confirm') ?></button>
                    <button type="submit" class="btn-outline" name="action" value="csv_import_cancel" formnovalidate><?= t('worker_import_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const el=document.getElementById('worker-duplicates-modal');if(el&&window.bootstrap)new bootstrap.Modal(el,{backdrop:'static',keyboard:false}).show();});</script>
<?php endif; ?>

<div class="row mb-3 no-print">
    <div class="col-12">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-lg-2"><label class="form-label-dvd" for="filter-worker-id"><?= t('short_id') ?></label><input id="filter-worker-id" type="text" name="filter_id" class="form-control" value="<?= h($fInterniId) ?>"></div>
            <div class="col-6 col-lg-2"><label class="form-label-dvd" for="filter-worker-name"><?= t('name') ?></label><input id="filter-worker-name" type="text" name="filter_name" class="form-control" value="<?= h($fName) ?>"></div>
            <div class="col-6 col-lg-2">
                <label class="form-label-dvd" for="filter-worker-shift"><?= t('shift_assignment') ?></label>
                <select id="filter-worker-shift" name="filter_shift" class="form-select">
                    <option value=""><?= t('worker_status_all') ?></option>
                    <option value="0" <?= $fShift === '0' ? 'selected' : '' ?>>0</option>
                    <?php foreach ($shiftOptions as $option): if ((string)$option === '0') continue; ?><option value="<?= h((string)$option) ?>" <?= $fShift === (string)$option ? 'selected' : '' ?>><?= h((string)$option) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label-dvd" for="filter-worker-role"><?= t('worker_role') ?></label>
                <select id="filter-worker-role" name="filter_role" class="form-select">
                    <option value=""><?= t('worker_status_all') ?></option>
                    <?php foreach ($roleOptions as $option): ?><option value="<?= h((string)$option) ?>" <?= $fRole === (string)$option ? 'selected' : '' ?>><?= h((string)$option) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label-dvd" for="filter-worker-class"><?= t('workers_class') ?></label>
                <select id="filter-worker-class" name="filter_class" class="form-select">
                    <option value=""><?= t('worker_status_all') ?></option>
                    <?php foreach ($classOptions as $option): ?><option value="<?= h((string)$option) ?>" <?= $fClass === (string)$option ? 'selected' : '' ?>><?= h((string)$option) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label-dvd" for="filter-worker-employment"><?= t('worker_status') ?></label>
                <select id="filter-worker-employment" name="employment" class="form-select">
                    <option value="all" <?= $fEmployment==='all'?'selected':'' ?>><?= t('worker_status_all') ?></option>
                    <option value="ended" <?= $fEmployment==='ended'?'selected':'' ?>><?= t('worker_status_archived') ?></option>
                    <option value="active" <?= $fEmployment==='active'?'selected':'' ?>><?= t('worker_status_active') ?></option>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-funnel me-1"></i><?= t('filter') ?></button>
                <?php if ($fInterniId !== '' || $fName !== '' || $fShift !== '' || $fRole !== '' || $fClass !== '' || $fEmployment !== 'all'): ?><a href="pracovnici.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible><span class="s-num">⬆</span><span class="s-title"><?= t('import_csv') ?> – <?= t('import_csv_workers') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
    <div class="card-body-dvd" style="display:none">
        <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="csv_import">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-6"><label class="form-label-dvd"><?= t('import_select') ?></label><input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required></div>
            <div class="col-auto d-flex gap-2">
                <a href="<?= APP_URL ?>/assets/templates/import_pracovnici_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download>
                    <i class="bi bi-download me-1"></i>Vzor CSV
                </a>
                <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
            </div>
        </div>
        <div class="mt-2" style="font-size:.7rem;color:var(--muted)"><?= t('format_workers') ?></div>
        </form>
    </div>
</div>

<div class="card-dvd mb-3">
    <div class="card-header-dvd" data-collapsible><span class="s-num"><i class="bi bi-tags"></i></span><span class="s-title"><?= t('workers_class_import_title') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
    <div class="card-body-dvd" style="display:none">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="csv_import_classes">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label-dvd" for="class-csv-file"><?= t('import_select') ?></label><input id="class-csv-file" type="file" name="class_csv_file" class="form-control" accept=".csv,text/csv" required></div>
                <div class="col-auto d-flex gap-2">
                    <a href="<?= APP_URL ?>/assets/templates/import_tridy_pracovnici_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download><i class="bi bi-download me-1"></i><?= t('workers_class_csv_template') ?></a>
                    <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
                </div>
            </div>
            <div class="mt-2" style="font-size:.7rem;color:var(--muted)"><?= t('workers_class_import_format') ?></div>
        </form>
    </div>
</div>

<?php $workerFormData = is_array($edit) ? $edit : []; ?>
<div class="modal fade" id="worker-modal" tabindex="-1" aria-labelledby="worker-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background:var(--surface);color:var(--text);border:1px solid var(--border)">
            <div class="modal-header" style="border-color:var(--border)">
                <h2 class="modal-title fs-5" id="worker-modal-title"><?= empty($workerFormData)?t('add'):t('edit') ?> – <?= t('nav_workers') ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
            </div>
            <div class="modal-body">
    <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int)($workerFormData['id']??0) ?>">
    <div class="row g-3">
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('internal_id') ?> <span class="req">*</span></label><input type="text" name="interni_id" class="form-control" value="<?= h($workerFormData['interni_id']??'') ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label-dvd"><?= t('name') ?> <span class="req">*</span></label><input type="text" name="jmeno" class="form-control" value="<?= h($workerFormData['jmeno']??'') ?>" required></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd"><?= t('shift_assignment') ?></label>
            <select name="smennost" class="form-select">
                <option value="">—</option>
                <?php foreach([t('shift_a'),t('shift_b'),t('shift_c'),t('shift_d')] as $s): ?><option <?=($workerFormData['smennost']??'')===$s?'selected':''?>><?= $s ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label-dvd" for="worker-role"><?= t('worker_role') ?></label><input type="text" id="worker-role" name="role" class="form-control" value="<?= h((string)($workerFormData['role'] ?? 'D')) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label-dvd" for="worker-end-date"><?= t('worker_end_date') ?></label><input type="date" id="worker-end-date" name="datum_ukonceni" class="form-control" value="<?= h((string)($workerFormData['datum_ukonceni']??'')) ?>"><small style="color:var(--muted)"><?= t('worker_end_date_hint') ?></small></div>
        <div class="col-6 col-md-2 d-flex align-items-end pb-1">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="aktivni" <?=($workerFormData['aktivni']??1)?'checked':''?>>
            <label class="form-check-label" style="font-size:.8rem"><?= t('active') ?></label></div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('save') ?></button>
            <button type="button" class="btn-outline" data-bs-dismiss="modal" style="padding:.35rem .9rem;font-size:.78rem"><?= t('cancel') ?></button>
        </div>
    </div>
    </form>
    </div>
        </div>
    </div>
</div>
<?php if ($edit !== null): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{const el=document.getElementById('worker-modal');if(el&&window.bootstrap)new bootstrap.Modal(el).show();});</script>
<?php endif; ?>

<div class="tbl-wrap">
<div class="overflow-x">
<table class="tbl">
    <thead><tr>
        <?php foreach ([
            'interni_id' => t('short_id'),
            'jmeno' => t('name'),
            'smennost' => t('shift_assignment'),
            'role' => t('worker_role'),
            'trida' => t('workers_class'),
            'status' => t('worker_status'),
        ] as $column => $label): ?>
        <th><a href="<?= h($sortUrl($column)) ?>" class="text-reset text-decoration-none"><?= h($label) ?> <i class="bi <?= h($sortIcon($column)) ?>"></i></a></th>
        <?php endforeach; ?>
        <th><?= t('actions') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
    <tr>
        <td class="mono"><?= h($p['interni_id']) ?></td>
        <td><?= h($p['jmeno']) ?></td>
        <td><?= h($p['smennost']??'—') ?></td>
        <td><?= h($p['role'] ?? 'D') ?></td>
        <td><?= h($p['trida'] ?? '—') ?></td>
        <td><?php $isCurrentlyEmployed = (int)$p['aktivni'] === 1 && !empty($p['datum_ukonceni']) && $p['datum_ukonceni'] > date('Y-m-d'); ?>
            <span class="<?= $isCurrentlyEmployed ? 'badge-ok' : 'badge-nok' ?>"><?= t($isCurrentlyEmployed ? 'worker_status_active' : 'worker_status_archived') ?></span>
        </td>
        <td>
            <a href="?edit=<?= $p['id'] ?>" class="btn-icon"><i class="bi bi-pencil"></i></a>
            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-icon danger" data-confirm="<?= t('confirm_delete') ?>"><i class="bi bi-trash3"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="tbl-footer"><span><?= count($list) ?> <?= count($list)==1?t('plural_worker'):t('plural_workers') ?></span></div>
</div>
</div>
<?php require APP_ROOT.'/includes/layout_end.php'; ?>
