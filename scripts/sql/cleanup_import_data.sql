-- Cleanup imported DVD data
-- Safe to run multiple times.

START TRANSACTION;

SET @has_import_hash := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zapisy'
      AND COLUMN_NAME = 'import_hash'
);
SET @sql_add_import_hash := IF(
    @has_import_hash = 0,
    'ALTER TABLE zapisy ADD COLUMN import_hash CHAR(64) NULL',
    'SELECT 1'
);
PREPARE stmt_add_import_hash FROM @sql_add_import_hash;
EXECUTE stmt_add_import_hash;
DEALLOCATE PREPARE stmt_add_import_hash;

-- 1) Normalize imported computed fields.
UPDATE zapisy
SET vyrobeno_dobre_ks = GREATEST(COALESCE(vyrobeno_ks,0) - COALESCE(nok_ks,0) - COALESCE(k_posouzeni,0), 0)
WHERE imported = 1;

UPDATE zapisy
SET oee_procent = CASE
    WHEN oee_dostupnost IS NOT NULL AND oee_vykon IS NOT NULL AND oee_kvalita IS NOT NULL
        THEN LEAST(1.0, GREATEST(0.0, ROUND(oee_dostupnost * oee_vykon * oee_kvalita, 8)))
    WHEN oee_procent IS NOT NULL
        THEN LEAST(1.0, GREATEST(0.0, oee_procent))
    ELSE NULL
END
WHERE imported = 1;

-- 2) Build deterministic deduplication hash for imported rows.
UPDATE zapisy
SET import_hash = SHA2(CONCAT_WS('|',
    'v2',
    COALESCE(typ,''),
    COALESCE(DATE_FORMAT(datum,'%Y-%m-%d'),''),
    COALESCE(CAST(cw AS CHAR),''),
    COALESCE(CAST(rok AS CHAR),''),
    COALESCE(CAST(mesic AS CHAR),''),
    COALESCE(smena,''),
    COALESCE(zakaznik,''),
    COALESCE(projekt,''),
    COALESCE(verze,''),
    COALESCE(kod_pracovisite,''),
    COALESCE(nazev_pracovisite,''),
    COALESCE(nazev_dilu,''),
    COALESCE(sap_cislo,''),
    COALESCE(CAST(nakladove_stredisko AS CHAR),''),
    COALESCE(jmeno,''),
    COALESCE(pracovni_pozice,''),
    COALESCE(CAST(vyrobeno_ks AS CHAR),''),
    COALESCE(CAST(nok_ks AS CHAR),''),
    COALESCE(CAST(k_posouzeni AS CHAR),''),
    COALESCE(CAST(cisty_cas_min AS CHAR),''),
    COALESCE(CAST(pracovni_doba_min AS CHAR),''),
    COALESCE(CAST(prestas_min AS CHAR),''),
    COALESCE(CAST(oee_dostupnost AS CHAR),''),
    COALESCE(CAST(oee_vykon AS CHAR),''),
    COALESCE(CAST(oee_kvalita AS CHAR),''),
    COALESCE(CAST(norma_ks AS CHAR),''),
    COALESCE(CAST(cycle_time_min AS CHAR),''),
    COALESCE(final_typ,''),
    COALESCE(zapsal,'')
), 256)
WHERE imported = 1;

-- 3) Remove exact duplicate imported rows, keep the oldest ID.
DELETE z1
FROM zapisy z1
JOIN zapisy z2
  ON z1.import_hash = z2.import_hash
 AND z1.id > z2.id
WHERE z1.imported = 1
  AND z2.imported = 1
  AND z1.import_hash IS NOT NULL;

-- 4) Backfill workplace master data from imported rows if missing.
INSERT INTO pracovisite (
    cislo_id, zakaznik, projekt, verze, kod_pracovisite, nazev_pracovisite,
    nazev_dilu, sap_cislo, nakladove_stredisko, oznaceni, norma_ks_min,
    cyklus_min, typ, aktivni, produktivita
)
SELECT
    base.max_cislo_id + ROW_NUMBER() OVER (ORDER BY src.kod_pracovisite, src.nazev_dilu, src.zakaznik, src.projekt, src.verze),
    src.zakaznik,
    src.projekt,
    src.verze,
    src.kod_pracovisite,
    src.nazev_pracovisite,
    src.nazev_dilu,
    src.sap_cislo,
    src.nakladove_stredisko,
    src.final_typ,
    src.norma_ks,
    src.cycle_time_min,
    src.typ,
    1,
    1
FROM (
    SELECT DISTINCT
        COALESCE(z.zakaznik,'') AS zakaznik,
        COALESCE(z.projekt,'') AS projekt,
        COALESCE(z.verze,'') AS verze,
        COALESCE(z.kod_pracovisite,'') AS kod_pracovisite,
        COALESCE(NULLIF(z.nazev_pracovisite,''), z.kod_pracovisite, '') AS nazev_pracovisite,
        COALESCE(z.nazev_dilu,'') AS nazev_dilu,
        COALESCE(z.sap_cislo,'') AS sap_cislo,
        z.nakladove_stredisko,
        COALESCE(NULLIF(z.final_typ,''), 'Pol') AS final_typ,
        z.norma_ks,
        z.cycle_time_min,
        COALESCE(z.typ,'serie') AS typ
    FROM zapisy z
    WHERE z.imported = 1
      AND (COALESCE(z.kod_pracovisite,'') <> '' OR COALESCE(z.nazev_dilu,'') <> '')
) src
CROSS JOIN (
    SELECT COALESCE(MAX(cislo_id),0) AS max_cislo_id FROM pracovisite
) base
LEFT JOIN pracovisite p
  ON COALESCE(p.kod_pracovisite,'') = src.kod_pracovisite
 AND COALESCE(p.nazev_dilu,'') = src.nazev_dilu
 AND COALESCE(p.zakaznik,'') = src.zakaznik
 AND COALESCE(p.projekt,'') = src.projekt
 AND COALESCE(p.verze,'') = src.verze
WHERE p.id IS NULL;

-- 5) Link imported records to workplace IDs.
UPDATE zapisy z
JOIN pracovisite p
  ON COALESCE(p.kod_pracovisite,'') = COALESCE(z.kod_pracovisite,'')
 AND COALESCE(p.nazev_dilu,'') = COALESCE(z.nazev_dilu,'')
 AND COALESCE(p.zakaznik,'') = COALESCE(z.zakaznik,'')
 AND COALESCE(p.projekt,'') = COALESCE(z.projekt,'')
 AND COALESCE(p.verze,'') = COALESCE(z.verze,'')
SET z.pracovisite_id = p.id
WHERE z.imported = 1
  AND z.pracovisite_id IS NULL;

COMMIT;

SET @has_import_hash_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zapisy'
      AND INDEX_NAME = 'uq_import_hash'
);
SET @sql_add_import_hash_index := IF(
    @has_import_hash_index = 0,
    'ALTER TABLE zapisy ADD UNIQUE INDEX uq_import_hash (import_hash)',
    'SELECT 1'
);
PREPARE stmt_add_import_hash_index FROM @sql_add_import_hash_index;
EXECUTE stmt_add_import_hash_index;
DEALLOCATE PREPARE stmt_add_import_hash_index;