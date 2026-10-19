<?php

function serizovacLower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function serizovacNormalizeProjectLabel(string $sheetName, string $rowProjectLabel = ''): string
{
    $sheet = trim($sheetName);
    $sheetLower = serizovacLower($sheet);

    if ($sheetLower === 'list3') {
        return '48w';
    }

    $rowLabel = trim($rowProjectLabel);
    if ($rowLabel !== '') {
        $compact = preg_replace('/\s+/', '', serizovacLower($rowLabel));
        if ($compact === '48w') {
            return '48w';
        }
    }

    return $sheet;
}

function serizovacValueHintMeansNoInput(string $valueHint): bool
{
    return serizovacLower(trim($valueHint)) === 'x';
}

function serizovacReadArchiveEntry($archive, string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');

    if ($archive instanceof ZipArchive) {
        $content = $archive->getFromName($path);
        return is_string($content) ? $content : '';
    }

    if (is_string($archive) && $archive !== '') {
        $pharPath = 'phar://' . str_replace('\\', '/', $archive) . '/' . $path;
        $content = @file_get_contents($pharPath);
        return is_string($content) ? $content : '';
    }

    return '';
}

function serizovacSlug(string $value): string
{
    $value = serizovacLower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'default';
}

function serizovacEnsureChecklistTables(): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        if (!ensureUsersRoleSupportsRework()) {
            $checked = false;
            return $checked;
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklist_catalog (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_key CHAR(40) NOT NULL UNIQUE,
                sheet_name VARCHAR(120) NOT NULL,
                project_key VARCHAR(64) NOT NULL,
                project_label VARCHAR(190) NOT NULL,
                afo_code VARCHAR(40) NOT NULL,
                task_text VARCHAR(500) NOT NULL,
                value_hint VARCHAR(120) NULL,
                requires_value TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_serizovac_catalog_project (project_key, active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklist_projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_key VARCHAR(64) NOT NULL UNIQUE,
                project_label VARCHAR(190) NOT NULL,
                sheet_name VARCHAR(120) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_serizovac_projects_active (active, project_label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_key VARCHAR(64) NOT NULL,
                project_label VARCHAR(190) NOT NULL,
                check_date DATE NOT NULL,
                created_by INT NULL,
                created_by_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_serizovac_checklists_date (check_date, project_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Backward compatibility migration: old project key/name from initial import.
        db()->exec("UPDATE serizovac_checklist_projects SET project_key = '48w', project_label = '48w' WHERE project_key = 'list3' OR LOWER(project_label) = 'list3'");
        db()->exec("UPDATE serizovac_checklist_catalog SET project_key = '48w', project_label = '48w' WHERE project_key = 'list3' OR LOWER(project_label) = 'list3' OR LOWER(sheet_name) = 'list3'");
        db()->exec("UPDATE serizovac_checklists SET project_key = '48w', project_label = '48w' WHERE project_key = 'list3' OR LOWER(project_label) = 'list3'");

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklist_rows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                checklist_id INT NOT NULL,
                catalog_id INT NOT NULL,
                result_status ENUM("OK","NOK","NA") NOT NULL,
                value_text VARCHAR(255) NULL,
                note_text VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_serizovac_checklist_row (checklist_id, catalog_id),
                INDEX idx_serizovac_rows_catalog (catalog_id),
                CONSTRAINT fk_serizovac_rows_checklist FOREIGN KEY (checklist_id) REFERENCES serizovac_checklists(id) ON DELETE CASCADE,
                CONSTRAINT fk_serizovac_rows_catalog FOREIGN KEY (catalog_id) REFERENCES serizovac_checklist_catalog(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklist_nok_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                checklist_id INT NOT NULL,
                action_text VARCHAR(500) NULL,
                resolve_date VARCHAR(50) NULL,
                person_signature VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_serizovac_nok_checklist (checklist_id),
                CONSTRAINT fk_serizovac_nok_checklist FOREIGN KEY (checklist_id) REFERENCES serizovac_checklists(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS serizovac_checklist_glue_weights (
                id INT AUTO_INCREMENT PRIMARY KEY,
                checklist_id INT NOT NULL,
                workplace VARCHAR(255) NULL,
                value_text VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_serizovac_glue_checklist (checklist_id),
                CONSTRAINT fk_serizovac_glue_checklist FOREIGN KEY (checklist_id) REFERENCES serizovac_checklists(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $checked = true;
    } catch (Throwable $e) {
        error_log('Serizovac checklist tables init failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function serizovacLoadSharedStrings($archive): array
{
    $xml = serizovacReadArchiveEntry($archive, 'xl/sharedStrings.xml');
    if (!is_string($xml) || trim($xml) === '') {
        return [];
    }

    $doc = @simplexml_load_string($xml);
    if (!$doc) {
        return [];
    }

    $doc->registerXPathNamespace('d', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $strings = [];
    $nodes = $doc->xpath('//d:si');
    if (!is_array($nodes)) {
        return [];
    }

    foreach ($nodes as $si) {
        $parts = $si->xpath('.//*[local-name()="t"]');
        if (!is_array($parts) || $parts === []) {
            $strings[] = '';
            continue;
        }

        $text = '';
        foreach ($parts as $part) {
            $text .= (string)$part;
        }
        $strings[] = $text;
    }

    return $strings;
}

function serizovacLoadWorkbookSheets($archive): array
{
    $workbookXml = serizovacReadArchiveEntry($archive, 'xl/workbook.xml');
    $relsXml = serizovacReadArchiveEntry($archive, 'xl/_rels/workbook.xml.rels');
    if (!is_string($workbookXml) || !is_string($relsXml)) {
        return [];
    }

    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels) {
        return [];
    }

    $workbook->registerXPathNamespace('d', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $relMap = [];
    $relNodes = $rels->xpath('//r:Relationship');
    if (is_array($relNodes)) {
        foreach ($relNodes as $relNode) {
            $id = (string)$relNode['Id'];
            $target = (string)$relNode['Target'];
            if ($id !== '' && $target !== '') {
                $target = str_replace('\\', '/', $target);
                $target = ltrim($target, '/');
                if (strpos($target, 'xl/') !== 0) {
                    $target = 'xl/' . $target;
                }
                $relMap[$id] = $target;
            }
        }
    }

    $sheets = [];
    $sheetNodes = $workbook->xpath('//d:sheets/d:sheet');
    if (is_array($sheetNodes)) {
        foreach ($sheetNodes as $sheetNode) {
            $name = trim((string)$sheetNode['name']);
            $rid = trim((string)$sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id);
            $target = $relMap[$rid] ?? '';
            if ($name !== '' && $target !== '') {
                $sheets[] = ['name' => $name, 'target' => $target];
            }
        }
    }

    return $sheets;
}

function serizovacCellColumnIndex(string $ref): int
{
    if (!preg_match('/^[A-Z]+/', strtoupper($ref), $m)) {
        return 0;
    }

    $letters = $m[0];
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }

    return $idx;
}

function serizovacReadSheetRows($archive, string $sheetPath, array $sharedStrings): array
{
    $sheetXml = serizovacReadArchiveEntry($archive, $sheetPath);
    if (!is_string($sheetXml) || trim($sheetXml) === '') {
        return [];
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) {
        return [];
    }

    $sheet->registerXPathNamespace('d', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowNodes = $sheet->xpath('//d:sheetData/d:row');
    if (!is_array($rowNodes)) {
        return [];
    }

    $rows = [];
    foreach ($rowNodes as $rowNode) {
        $rowIndex = (int)($rowNode['r'] ?? 0);
        if ($rowIndex <= 0) {
            continue;
        }

        $rows[$rowIndex] = [];
        $cells = $rowNode->xpath('./*[local-name()="c"]');
        if (!is_array($cells)) {
            continue;
        }

        foreach ($cells as $cell) {
            $ref = (string)($cell['r'] ?? '');
            $colIndex = serizovacCellColumnIndex($ref);
            if ($colIndex <= 0) {
                continue;
            }

            $type = (string)($cell['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $valueNodes = $cell->xpath('./*[local-name()="v"]');
                $sharedIndex = (int)((is_array($valueNodes) && isset($valueNodes[0])) ? (string)$valueNodes[0] : -1);
                $value = $sharedStrings[$sharedIndex] ?? '';
            } elseif ($type === 'inlineStr') {
                $parts = $cell->xpath('.//*[local-name()="t"]');
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $value .= (string)$part;
                    }
                }
            } else {
                $valueNodes = $cell->xpath('./*[local-name()="v"]');
                $value = (is_array($valueNodes) && isset($valueNodes[0])) ? (string)$valueNodes[0] : '';
            }

            $rows[$rowIndex][$colIndex] = trim($value);
        }
    }

    return $rows;
}

function serizovacParseChecklistWorkbook(string $xlsxPath): array
{
    if (!is_file($xlsxPath)) {
        return [];
    }

    if (!function_exists('simplexml_load_string')) {
        error_log('Serizovac checklist parser unavailable: missing simplexml extension');
        return [];
    }

    $archive = null;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) === true) {
            $archive = $zip;
        }
    }

    if ($archive === null && class_exists('PharData')) {
        try {
            new PharData($xlsxPath);
            $archive = $xlsxPath;
        } catch (Throwable $e) {
            $archive = null;
        }
    }

    if ($archive === null) {
        error_log('Serizovac checklist parser unavailable: no archive reader for xlsx');
        return [];
    }

    $sharedStrings = serizovacLoadSharedStrings($archive);
    $sheets = serizovacLoadWorkbookSheets($archive);

    $items = [];
    $projects = [];
    foreach ($sheets as $sheetInfo) {
        $sheetName = (string)$sheetInfo['name'];
        $sheetPath = (string)$sheetInfo['target'];
        $rows = serizovacReadSheetRows($archive, $sheetPath, $sharedStrings);
        if ($rows === []) {
            continue;
        }

        $rowProjectLabel = trim((string)($rows[1][2] ?? ''));
        $projectLabel = serizovacNormalizeProjectLabel($sheetName, $rowProjectLabel);
        $projectKey = serizovacSlug($projectLabel);
        $projects[$projectKey] = [
            'project_key' => $projectKey,
            'project_label' => $projectLabel,
            'sheet_name' => $sheetName,
        ];

        $currentAfo = '';
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }

            $afoCell = trim((string)($row[1] ?? ''));
            $task = trim((string)($row[2] ?? ''));
            $valueHint = trim((string)($row[4] ?? ''));

            if ($task === '') {
                continue;
            }
            if (serizovacLower($task) === 'úkon') {
                continue;
            }
            if ($afoCell !== '') {
                $currentAfo = $afoCell;
            }

            $requiresValue = ($valueHint !== '' && !serizovacValueHintMeansNoInput($valueHint));
            $itemKey = sha1(serizovacLower($sheetName . '|' . $currentAfo . '|' . $task));

            $items[] = [
                'item_key' => $itemKey,
                'sheet_name' => $sheetName,
                'project_key' => $projectKey,
                'project_label' => $projectLabel,
                'afo_code' => $currentAfo,
                'task_text' => $task,
                'value_hint' => $valueHint,
                'requires_value' => $requiresValue ? 1 : 0,
                'sort_order' => ((int)$rowIndex) * 10,
            ];
        }
    }

    if ($archive instanceof ZipArchive) {
        $archive->close();
    }
    return [
        'items' => $items,
        'projects' => array_values($projects),
    ];
}

function serizovacSyncChecklistCatalogFromXlsx(string $xlsxPath): array
{
    if (!serizovacEnsureChecklistTables()) {
        return ['ok' => false, 'message' => 'Table init failed', 'inserted' => 0, 'updated' => 0];
    }

    $parsed = serizovacParseChecklistWorkbook($xlsxPath);
    $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
    $projects = is_array($parsed['projects'] ?? null) ? $parsed['projects'] : [];
    if ($items === []) {
        return ['ok' => false, 'message' => 'No items parsed', 'inserted' => 0, 'updated' => 0];
    }

    $inserted = 0;
    $updated = 0;

    $sql = 'INSERT INTO serizovac_checklist_catalog
            (item_key, sheet_name, project_key, project_label, afo_code, task_text, value_hint, requires_value, sort_order, active)
            VALUES
            (:item_key, :sheet_name, :project_key, :project_label, :afo_code, :task_text, :value_hint, :requires_value, :sort_order, 1)
            ON DUPLICATE KEY UPDATE
                sheet_name = VALUES(sheet_name),
                project_key = VALUES(project_key),
                project_label = VALUES(project_label),
                afo_code = VALUES(afo_code),
                task_text = VALUES(task_text),
                value_hint = VALUES(value_hint),
                requires_value = VALUES(requires_value),
                sort_order = VALUES(sort_order),
                active = 1';

    $stmt = db()->prepare($sql);
    $itemKeys = [];
    foreach ($items as $item) {
        $exists = (int)db_val('SELECT COUNT(*) FROM serizovac_checklist_catalog WHERE item_key = ?', [$item['item_key']]) > 0;
        $stmt->execute($item);
        $itemKeys[] = (string)$item['item_key'];
        if ($exists) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    if ($itemKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($itemKeys), '?'));
        db()->prepare('UPDATE serizovac_checklist_catalog SET active = 0 WHERE item_key NOT IN (' . $placeholders . ')')
            ->execute($itemKeys);
    }

    if ($projects !== []) {
        $projectStmt = db()->prepare(
            'INSERT INTO serizovac_checklist_projects (project_key, project_label, sheet_name, active)
             VALUES (:project_key, :project_label, :sheet_name, 1)
             ON DUPLICATE KEY UPDATE
                project_label = VALUES(project_label),
                sheet_name = VALUES(sheet_name),
                active = 1'
        );

        $projectKeys = [];
        foreach ($projects as $project) {
            $projectStmt->execute($project);
            $projectKeys[] = (string)$project['project_key'];
        }

        if ($projectKeys !== []) {
            $projectPlaceholders = implode(',', array_fill(0, count($projectKeys), '?'));
            db()->prepare('UPDATE serizovac_checklist_projects SET active = 0 WHERE project_key NOT IN (' . $projectPlaceholders . ')')
                ->execute($projectKeys);
        }
    }

    return [
        'ok' => true,
        'message' => 'Synced',
        'inserted' => $inserted,
        'updated' => $updated,
    ];
}
