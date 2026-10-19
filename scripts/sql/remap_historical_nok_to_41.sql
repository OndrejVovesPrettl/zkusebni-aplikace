START TRANSACTION;

CREATE TEMPORARY TABLE tmp_nok41_rollup AS
SELECT
    zapis_id,
    SUM(COALESCE(pocet, 0)) AS pocet,
    NULLIF(TRIM(BOTH ' | ' FROM GROUP_CONCAT(NULLIF(TRIM(poznamka), '') SEPARATOR ' | ')), '') AS poznamka
FROM zapis_nok
WHERE nok_kod_id <> 41
  AND COALESCE(pocet, 0) > 0
GROUP BY zapis_id;

DELETE FROM zapis_nok
WHERE nok_kod_id <> 41
  AND COALESCE(pocet, 0) > 0;

INSERT INTO zapis_nok (zapis_id, nok_kod_id, pocet, poznamka)
SELECT
    zapis_id,
    41,
    pocet,
    poznamka
FROM tmp_nok41_rollup
ON DUPLICATE KEY UPDATE
    pocet = COALESCE(zapis_nok.pocet, 0) + VALUES(pocet),
    poznamka = CASE
        WHEN zapis_nok.poznamka IS NULL OR TRIM(zapis_nok.poznamka) = '' THEN VALUES(poznamka)
        WHEN VALUES(poznamka) IS NULL OR TRIM(VALUES(poznamka)) = '' THEN zapis_nok.poznamka
        ELSE CONCAT(zapis_nok.poznamka, ' | ', VALUES(poznamka))
    END;

DROP TEMPORARY TABLE IF EXISTS tmp_nok41_rollup;

COMMIT;
