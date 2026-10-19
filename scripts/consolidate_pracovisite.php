#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only' . PHP_EOL);
}

$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);

function pracovisteGroupKey(array $row): string {
    return implode(' | ', [
        trim((string)($row['kod_pracovisite'] ?? '')),
        trim((string)($row['nazev_dilu'] ?? '')),
        trim((string)($row['zakaznik'] ?? '')),
    ]);
}

function pracovisteCompletenessScore(array $row): int {
    $score = 0;
    foreach (['projekt', 'verze', 'nazev_pracovisite', 'sap_cislo', 'nakladove_stredisko', 'oznaceni', 'norma_ks_min', 'cyklus_min', 'poznamka'] as $field) {
        $value = $row[$field] ?? null;
        if ($value !== null && trim((string)$value) !== '') {
            $score++;
        }
    }
    return $score;
}

function pracovisteUniqueNonEmpty(array $rows, string $field): array {
    $values = [];
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') {
            $values[$value] = true;
        }
    }
    return array_keys($values);
}

function pracovisteFirstFilled(array $rows, string $field, mixed $fallback = null): mixed {
    foreach ($rows as $row) {
        $value = $row[$field] ?? null;
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        return $value;
    }
    return $fallback;
}

$groups = db_all(
    'SELECT kod_pracovisite, nazev_dilu, zakaznik, COUNT(*) AS cnt
     FROM pracovisite
    WHERE aktivni=1
     GROUP BY kod_pracovisite, nazev_dilu, zakaznik
     HAVING COUNT(*) > 1
     ORDER BY cnt DESC, kod_pracovisite, nazev_dilu, zakaznik'
);

if (!$groups) {
    echo "No duplicate pracovisite groups found." . PHP_EOL;
    exit(0);
}

$summary = [
    'groups' => count($groups),
    'duplicate_rows' => 0,
    'moved_zapisy' => 0,
    'deactivated_rows' => 0,
    'canonicals' => [],
];

$pdo = db();
if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($groups as $group) {
        $rows = db_all(
            'SELECT p.*, COUNT(z.id) AS zapisy_cnt
             FROM pracovisite p
             LEFT JOIN zapisy z ON z.pracovisite_id = p.id
             WHERE COALESCE(p.kod_pracovisite, \'\') = ?
               AND COALESCE(p.nazev_dilu, \'\') = ?
               AND COALESCE(p.zakaznik, \'\') = ?
                             AND p.aktivni=1
             GROUP BY p.id
             ORDER BY p.id ASC',
            [$group['kod_pracovisite'], $group['nazev_dilu'], $group['zakaznik']]
        );

        usort($rows, static function (array $left, array $right): int {
            return [(int)$right['zapisy_cnt'], (int)$right['aktivni'], pracovisteCompletenessScore($right), -(int)$right['id']]
                <=> [(int)$left['zapisy_cnt'], (int)$left['aktivni'], pracovisteCompletenessScore($left), -(int)$left['id']];
        });

        $canonical = $rows[0];
        $duplicates = array_slice($rows, 1);
        $duplicateIds = array_map(static fn(array $row): int => (int)$row['id'], $duplicates);
        $summary['duplicate_rows'] += count($duplicateIds);

        $distinctProjects = pracovisteUniqueNonEmpty($rows, 'projekt');
        $distinctVersions = pracovisteUniqueNonEmpty($rows, 'verze');
        $distinctTypes = pracovisteUniqueNonEmpty($rows, 'typ');

        $mergedProject = trim((string)($canonical['projekt'] ?? ''));
        if ($mergedProject === '' && count($distinctProjects) === 1) {
            $mergedProject = $distinctProjects[0];
        }

        $mergedVersion = trim((string)($canonical['verze'] ?? ''));
        if ($mergedVersion === '' && count($distinctVersions) === 1) {
            $mergedVersion = $distinctVersions[0];
        }

        $mergedType = count($distinctTypes) <= 1
            ? ((string)($distinctTypes[0] ?? ($canonical['typ'] ?? 'serie')) ?: 'serie')
            : 'obe';

        $mergedNoteParts = [
            'Konsolidace ' . date('Y-m-d H:i:s'),
            'Sloučeno z ID: ' . implode(', ', array_map(static fn(array $row): string => (string)$row['id'], $rows)),
        ];
        if (count($distinctProjects) > 1) {
            $mergedNoteParts[] = 'Projekty ve skupině: ' . implode(', ', $distinctProjects);
        }
        if (count($distinctVersions) > 1) {
            $mergedNoteParts[] = 'Verze ve skupině: ' . implode(', ', $distinctVersions);
        }
        $existingNote = trim((string)($canonical['poznamka'] ?? ''));
        $mergedNote = trim($existingNote . ($existingNote !== '' ? PHP_EOL : '') . implode(' | ', $mergedNoteParts));

        $mergedData = [
            'zakaznik' => trim((string)($canonical['zakaznik'] ?? '')),
            'projekt' => $mergedProject,
            'verze' => $mergedVersion,
            'kod_pracovisite' => trim((string)($canonical['kod_pracovisite'] ?? '')),
            'nazev_pracovisite' => (string)pracovisteFirstFilled($rows, 'nazev_pracovisite', $canonical['kod_pracovisite'] ?? ''),
            'nazev_dilu' => trim((string)($canonical['nazev_dilu'] ?? '')),
            'sap_cislo' => (string)pracovisteFirstFilled($rows, 'sap_cislo', ''),
            'nakladove_stredisko' => pracovisteFirstFilled($rows, 'nakladove_stredisko'),
            'oznaceni' => (string)pracovisteFirstFilled($rows, 'oznaceni', 'Pol'),
            'produktivita' => in_array('1', array_map(static fn(array $row): string => (string)(int)($row['produktivita'] ?? 0), $rows), true) ? 1 : 0,
            'norma_ks_min' => pracovisteFirstFilled($rows, 'norma_ks_min'),
            'cyklus_min' => pracovisteFirstFilled($rows, 'cyklus_min'),
            'typ' => in_array($mergedType, ['serie', 'filtry', 'vstrikovna', 'obe'], true) ? $mergedType : 'serie',
            'aktivni' => 1,
            'poznamka' => $mergedNote,
        ];

        $summary['canonicals'][] = [
            'canonical_id' => (int)$canonical['id'],
            'key' => pracovisteGroupKey($canonical),
            'duplicates' => $duplicateIds,
        ];

        if (!$apply) {
            echo sprintf(
                "[DRY-RUN] Keep ID %d for %s; merge %d duplicates; move %d zapisy\n",
                (int)$canonical['id'],
                pracovisteGroupKey($canonical),
                count($duplicateIds),
                array_sum(array_map(static fn(array $row): int => (int)$row['zapisy_cnt'], $duplicates))
            );
            continue;
        }

        $updateCanonical = $pdo->prepare(
            'UPDATE pracovisite
             SET zakaznik=?, projekt=?, verze=?, kod_pracovisite=?, nazev_pracovisite=?, nazev_dilu=?,
                 sap_cislo=?, nakladove_stredisko=?, oznaceni=?, produktivita=?, norma_ks_min=?, cyklus_min=?,
                 typ=?, aktivni=?, poznamka=?
             WHERE id=?'
        );
        $updateCanonical->execute([
            $mergedData['zakaznik'],
            $mergedData['projekt'],
            $mergedData['verze'],
            $mergedData['kod_pracovisite'],
            $mergedData['nazev_pracovisite'],
            $mergedData['nazev_dilu'],
            $mergedData['sap_cislo'],
            $mergedData['nakladove_stredisko'],
            $mergedData['oznaceni'],
            $mergedData['produktivita'],
            $mergedData['norma_ks_min'],
            $mergedData['cyklus_min'],
            $mergedData['typ'],
            $mergedData['aktivni'],
            $mergedData['poznamka'],
            (int)$canonical['id'],
        ]);

        if ($duplicateIds) {
            $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));

            $moveZapisy = $pdo->prepare(
                'UPDATE zapisy SET pracovisite_id=? WHERE pracovisite_id IN (' . $placeholders . ')'
            );
            $moveZapisy->execute([(int)$canonical['id'], ...$duplicateIds]);
            $summary['moved_zapisy'] += $moveZapisy->rowCount();

            $deactivateStmt = $pdo->prepare(
                'UPDATE pracovisite
                 SET aktivni=0, poznamka=?
                 WHERE id=?'
            );

            foreach ($duplicates as $duplicate) {
                $note = trim((string)($duplicate['poznamka'] ?? ''));
                $note = trim($note . ($note !== '' ? PHP_EOL : '') . 'Sloučeno do pracoviště ID ' . (int)$canonical['id'] . ' dne ' . date('Y-m-d H:i:s'));
                $deactivateStmt->execute([$note, (int)$duplicate['id']]);
                $summary['deactivated_rows'] += $deactivateStmt->rowCount();
            }
        }

        logAction(
            'PRACOVISITE_CONSOLIDATE',
            'pracovisite',
            (int)$canonical['id'],
            ['merged_ids' => array_map(static fn(array $row): int => (int)$row['id'], $rows)],
            [
                'canonical_id' => (int)$canonical['id'],
                'duplicate_ids' => $duplicateIds,
                'moved_zapisy' => array_sum(array_map(static fn(array $row): int => (int)$row['zapisy_cnt'], $duplicates)),
                'key' => pracovisteGroupKey($canonical),
            ],
            'Konsolidace pracovišť: ' . pracovisteGroupKey($canonical)
        );
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Consolidation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'groups' => $summary['groups'],
    'duplicate_rows' => $summary['duplicate_rows'],
    'moved_zapisy' => $summary['moved_zapisy'],
    'deactivated_rows' => $summary['deactivated_rows'],
    'sample' => array_slice($summary['canonicals'], 0, 10),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;