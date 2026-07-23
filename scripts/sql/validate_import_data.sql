-- Validation checks for imported DVD data

SELECT 'zapisy_summary' AS check_name,
       COUNT(*) AS total_rows,
       MIN(datum) AS min_datum,
       MAX(datum) AS max_datum,
       SUM(CASE WHEN imported = 1 THEN 1 ELSE 0 END) AS imported_rows,
       SUM(CASE WHEN pracovisite_id IS NULL THEN 1 ELSE 0 END) AS rows_without_pracovisite_id
FROM zapisy;

SELECT 'duplicate_import_hash' AS check_name,
       COUNT(*) AS duplicate_groups,
       COALESCE(SUM(row_count - 1), 0) AS extra_rows
FROM (
    SELECT import_hash, COUNT(*) AS row_count
    FROM zapisy
    WHERE import_hash IS NOT NULL
    GROUP BY import_hash
    HAVING COUNT(*) > 1
) d;

SELECT 'good_piece_mismatch' AS check_name,
       COUNT(*) AS bad_rows
FROM zapisy
WHERE COALESCE(vyrobeno_dobre_ks,0) <> GREATEST(COALESCE(vyrobeno_ks,0) - COALESCE(nok_ks,0) - COALESCE(k_posouzeni,0), 0);

SELECT 'oee_out_of_range' AS check_name,
       COUNT(*) AS bad_rows
FROM zapisy
WHERE oee_procent IS NOT NULL
  AND (oee_procent < 0 OR oee_procent > 1.0);

SELECT 'downtime_category_mismatch' AS check_name,
     COUNT(*) AS bad_rows
FROM (
  SELECT
    zapis_id,
    COALESCE(SUM(minuty),0) AS total_min,
    COALESCE(SUM(CASE WHEN kategorie IN ('tech','vyr','kval','log','ost','plan') THEN minuty ELSE 0 END),0) AS known_category_min
  FROM zapisy_prostoje
  GROUP BY zapis_id
) x
WHERE x.total_min <> x.known_category_min;

SELECT 'detail_table_population' AS check_name,
       (SELECT COUNT(*) FROM zapis_nok) AS zapis_nok_rows,
       (SELECT COUNT(*) FROM technicke_prostoje) AS technicke_prostoje_rows,
       (SELECT COUNT(*) FROM hodinove_sledovani) AS hodinove_sledovani_rows;

SELECT 'recent_days' AS check_name,
       datum,
       COUNT(*) AS cnt,
       SUM(vyrobeno_ks) AS vyrobeno_sum,
       SUM(nok_ks) AS nok_sum,
       ROUND(AVG(oee_procent) * 100, 2) AS avg_oee_pct
FROM zapisy
GROUP BY datum
ORDER BY datum DESC
LIMIT 14;