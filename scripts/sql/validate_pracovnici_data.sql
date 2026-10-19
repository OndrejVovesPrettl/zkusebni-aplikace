SET NAMES utf8mb4;

SELECT
    'pracovnici_summary' AS check_name,
    COUNT(*) AS total_rows,
    COUNT(DISTINCT jmeno) AS distinct_names,
    COUNT(DISTINCT interni_id) AS distinct_internal_ids,
    SUM(CASE WHEN aktivni = 1 THEN 1 ELSE 0 END) AS active_rows,
    SUM(CASE WHEN aktivni = 0 THEN 1 ELSE 0 END) AS inactive_rows
FROM pracovnici;

SELECT
    'duplicate_worker_names' AS check_name,
    COUNT(*) AS duplicate_groups,
    COALESCE(SUM(row_count - 1), 0) AS extra_rows
FROM (
    SELECT jmeno, COUNT(*) AS row_count
    FROM pracovnici
    GROUP BY jmeno
    HAVING COUNT(*) > 1
) d;

SELECT
    'duplicate_internal_ids' AS check_name,
    COUNT(*) AS duplicate_groups,
    COALESCE(SUM(row_count - 1), 0) AS extra_rows
FROM (
    SELECT interni_id, COUNT(*) AS row_count
    FROM pracovnici
    GROUP BY interni_id
    HAVING COUNT(*) > 1
) d;

SELECT
    'empty_required_fields' AS check_name,
    SUM(CASE WHEN TRIM(COALESCE(interni_id, '')) = '' THEN 1 ELSE 0 END) AS empty_internal_id,
    SUM(CASE WHEN TRIM(COALESCE(jmeno, '')) = '' THEN 1 ELSE 0 END) AS empty_name
FROM pracovnici;

SET @has_zapis_pracovnici := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'zapis_pracovnici'
);

SET @sql_worker_refs := IF(
    @has_zapis_pracovnici > 0,
    'SELECT
         ''zapis_pracovnici_reference_check'' AS check_name,
         COUNT(*) AS total_assignments,
         COALESCE(SUM(CASE WHEN pracovnik_id IS NULL THEN 1 ELSE 0 END), 0) AS null_worker_id,
         COALESCE(SUM(CASE WHEN pracovnik_id IS NOT NULL AND p.id IS NULL THEN 1 ELSE 0 END), 0) AS broken_worker_refs
     FROM zapis_pracovnici zp
     LEFT JOIN pracovnici p ON p.id = zp.pracovnik_id',
    'SELECT
         ''zapis_pracovnici_reference_check'' AS check_name,
         0 AS total_assignments,
         0 AS null_worker_id,
         0 AS broken_worker_refs'
);
PREPARE stmt_worker_refs FROM @sql_worker_refs;
EXECUTE stmt_worker_refs;
DEALLOCATE PREPARE stmt_worker_refs;

SELECT
    'duplicate_worker_name_detail' AS check_name,
    jmeno,
    COUNT(*) AS row_count,
    GROUP_CONCAT(CONCAT(id, ':', interni_id, ':', COALESCE(smennost, ''), ':', aktivni) ORDER BY aktivni DESC, id ASC SEPARATOR ' | ') AS rows_detail
FROM pracovnici
GROUP BY jmeno
HAVING COUNT(*) > 1
ORDER BY row_count DESC, jmeno ASC
LIMIT 50;