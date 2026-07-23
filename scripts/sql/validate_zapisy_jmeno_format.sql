SET NAMES utf8mb4;

SELECT
    'summary_zapisy_jmeno' AS section_name,
    COUNT(*) AS total_rows,
    SUM(CASE WHEN jmeno IS NOT NULL AND TRIM(jmeno) <> '' THEN 1 ELSE 0 END) AS rows_with_name,
    SUM(CASE WHEN jmeno LIKE '%;%' THEN 1 ELSE 0 END) AS rows_with_semicolon,
    SUM(CASE WHEN TRIM(jmeno) REGEXP ';$' THEN 1 ELSE 0 END) AS rows_with_trailing_semicolon,
    SUM(CASE WHEN jmeno REGEXP '[[:space:]]{2,}' THEN 1 ELSE 0 END) AS rows_with_multiple_spaces
FROM zapisy;

SELECT
    id,
    datum,
    smena,
    jmeno
FROM zapisy
WHERE jmeno IS NOT NULL
  AND (
      jmeno LIKE '%;%'
      OR TRIM(jmeno) REGEXP ';$'
      OR jmeno REGEXP '[[:space:]]{2,}'
  )
ORDER BY id DESC
LIMIT 100;
