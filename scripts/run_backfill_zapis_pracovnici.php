<?php
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = db();
    $rows = db_all("SELECT z.id, z.jmeno, z.pracovni_pozice
        FROM zapisy z
        LEFT JOIN (
            SELECT
                zapis_id,
                COUNT(*) AS worker_count,
                SUM(CASE WHEN pracovnik_id IS NULL THEN 1 ELSE 0 END) AS missing_worker_ids
            FROM zapis_pracovnici
            GROUP BY zapis_id
        ) zp ON zp.zapis_id = z.id
        WHERE (
            COALESCE(zp.worker_count, 0) = 0
            OR COALESCE(zp.missing_worker_ids, 0) > 0
        )
          AND COALESCE(TRIM(z.jmeno), '') <> ''
        ORDER BY z.id");

    $backfilled = 0;
    foreach ($rows as $row) {
        $workerRows = buildZapisyWorkerRowsFromSummary(
            (string)($row['jmeno'] ?? ''),
            (string)($row['pracovni_pozice'] ?? '')
        );
        if (!$workerRows) {
            continue;
        }
        syncZapisyPracovnici($pdo, (int)$row['id'], $workerRows);
        $backfilled++;
    }

    echo 'processed=' . count($rows) . PHP_EOL;
    echo 'backfilled=' . $backfilled . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}