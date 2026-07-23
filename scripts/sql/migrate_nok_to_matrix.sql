-- Step 1: Extend nok_matrix_afo with metadata from nok_kody
SET @col_exists_kod = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nok_matrix_afo' AND COLUMN_NAME='kod');
SET @col_exists_popis = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nok_matrix_afo' AND COLUMN_NAME='popis');
SET @col_exists_skupina = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nok_matrix_afo' AND COLUMN_NAME='skupina');
SET @col_exists_aktivni = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nok_matrix_afo' AND COLUMN_NAME='aktivni');

ALTER TABLE nok_matrix_afo ADD COLUMN kod SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER nok_kod_id;
ALTER TABLE nok_matrix_afo ADD COLUMN popis VARCHAR(255) NOT NULL DEFAULT '' AFTER kod;
ALTER TABLE nok_matrix_afo ADD COLUMN skupina VARCHAR(50) DEFAULT NULL AFTER popis;
ALTER TABLE nok_matrix_afo ADD COLUMN aktivni TINYINT(1) NOT NULL DEFAULT 1 AFTER skupina;

-- Step 2: Populate nok_matrix_afo with definitions from nok_kody
UPDATE nok_matrix_afo m
JOIN nok_kody n ON n.id = m.nok_kod_id
SET 
    m.kod = n.kod,
    m.popis = n.popis,
    m.skupina = n.skupina,
    m.aktivni = n.aktivni;

-- Step 3: Migrate all non-41 historical zapis_nok records to id=41 (simple roll-up)
INSERT INTO zapis_nok (zapis_id, nok_kod_id, pocet)
SELECT
    zapis_id,
    41 AS nok_kod_id,
    SUM(COALESCE(pocet, 0)) AS pocet
FROM zapis_nok
WHERE nok_kod_id <> 41
GROUP BY zapis_id
ON DUPLICATE KEY UPDATE
    pocet = pocet + VALUES(pocet);

-- Step 4: Delete old non-41 records
DELETE FROM zapis_nok WHERE nok_kod_id <> 41;

-- Step 5: Drop old FK and enable nok_matrix_afo as primary
ALTER TABLE nok_matrix_afo DROP FOREIGN KEY IF EXISTS fk_nok_matrix_afo_nok;
