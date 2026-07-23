-- Fix typ assignment in zapisy and pracovisite based on index.php template rules.
-- Template rule:
--   filtry = projekt == "EMV Filter" OR text contains "Ostatni"/"Srot" (including "Srot" variants)
--   otherwise serie

START TRANSACTION;

-- 1) Normalize typ in workplace master according to template logic.
UPDATE pracovisite p
SET p.typ = CASE
    WHEN TRIM(COALESCE(p.projekt, '')) COLLATE utf8mb4_unicode_ci = 'EMV Filter' THEN 'filtry'
    WHEN CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, ''),
        COALESCE(p.projekt, ''),
        COALESCE(p.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Ostatni%'
      OR CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, ''),
        COALESCE(p.projekt, ''),
        COALESCE(p.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Srot%'
      OR CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, ''),
        COALESCE(p.projekt, ''),
        COALESCE(p.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Šrot%'
        THEN 'filtry'
    ELSE 'serie'
END;

-- 2) Normalize typ in zapisy using linked pracovisite when available,
--    with fallback to row-local fields when pracoviste_id is missing.
UPDATE zapisy z
LEFT JOIN pracovisite p ON p.id = z.pracovisite_id
SET z.typ = CASE
    WHEN TRIM(COALESCE(p.projekt, z.projekt, '')) COLLATE utf8mb4_unicode_ci = 'EMV Filter' THEN 'filtry'
    WHEN CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, z.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, z.nazev_dilu, ''),
        COALESCE(p.projekt, z.projekt, ''),
        COALESCE(p.kod_pracovisite, z.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Ostatni%'
      OR CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, z.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, z.nazev_dilu, ''),
        COALESCE(p.projekt, z.projekt, ''),
        COALESCE(p.kod_pracovisite, z.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Srot%'
      OR CONCAT_WS(' ',
        COALESCE(p.nazev_pracovisite, z.nazev_pracovisite, ''),
        COALESCE(p.nazev_dilu, z.nazev_dilu, ''),
        COALESCE(p.projekt, z.projekt, ''),
        COALESCE(p.kod_pracovisite, z.kod_pracovisite, '')
    ) COLLATE utf8mb4_unicode_ci LIKE '%Šrot%'
        THEN 'filtry'
    ELSE 'serie'
END;

COMMIT;

-- Verification snapshot
SELECT typ, COUNT(*) AS rows_count
FROM zapisy
GROUP BY typ
ORDER BY typ;
