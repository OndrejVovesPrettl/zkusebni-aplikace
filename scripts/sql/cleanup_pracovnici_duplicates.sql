SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_pracovnici_keep;

CREATE TEMPORARY TABLE tmp_pracovnici_keep AS
SELECT
    jmeno,
    MIN(id) AS keep_id,
    COUNT(*) AS row_count
FROM pracovnici
GROUP BY jmeno
HAVING COUNT(*) > 1;

SET @has_zapis_pracovnici := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'zapis_pracovnici'
);

SET @sql_update_refs := IF(
    @has_zapis_pracovnici > 0,
    'UPDATE zapis_pracovnici zp
     JOIN pracovnici src ON src.id = zp.pracovnik_id
     JOIN tmp_pracovnici_keep k ON k.jmeno = src.jmeno
     SET zp.pracovnik_id = k.keep_id
     WHERE zp.pracovnik_id <> k.keep_id',
    'SELECT 0'
);
PREPARE stmt_update_refs FROM @sql_update_refs;
EXECUTE stmt_update_refs;
DEALLOCATE PREPARE stmt_update_refs;

DELETE p
FROM pracovnici p
JOIN tmp_pracovnici_keep k ON k.jmeno = p.jmeno
WHERE p.id <> k.keep_id;

SELECT
    'pracovnici_summary' AS check_name,
    COUNT(*) AS total_rows,
    COUNT(DISTINCT jmeno) AS distinct_names,
    COUNT(*) - COUNT(DISTINCT jmeno) AS duplicate_excess
FROM pracovnici;

SELECT
    'pracovnici_duplicate_groups' AS check_name,
    COUNT(*) AS duplicate_groups
FROM (
    SELECT jmeno
    FROM pracovnici
    GROUP BY jmeno
    HAVING COUNT(*) > 1
) dup;