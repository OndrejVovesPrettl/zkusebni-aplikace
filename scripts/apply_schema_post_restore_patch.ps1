$ErrorActionPreference = 'Stop'

$path = 'C:\laragon\www\pisek\v3.4\schema.sql'
if (!(Test-Path $path)) {
    throw "schema.sql not found: $path"
}

$content = [System.IO.File]::ReadAllText($path)
if ($content.Contains('schema_post_restore_v5')) {
    Write-Output 'SKIP already patched'
    exit 0
}

$insert = @'
-- schema_post_restore_v5: normalize downtime model after legacy dump restore
DROP TABLE IF EXISTS `zapisy_prostoje`;
CREATE TABLE `zapisy_prostoje` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `zapis_id` int NOT NULL,
  `kategorie` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `typ_kod` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `minuty` int unsigned NOT NULL DEFAULT '0',
  `cas_od` time DEFAULT NULL,
  `cas_do` time DEFAULT NULL,
  `pocet` int unsigned DEFAULT NULL,
  `poznamka` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_zapisy_prostoje_zapis_id` (`zapis_id`),
  KEY `idx_zapisy_prostoje_kategorie` (`kategorie`),
  KEY `idx_zapisy_prostoje_typ_kod` (`typ_kod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `zapisy_prostoje` (`zapis_id`, `kategorie`, `typ_kod`, `minuty`, `cas_od`, `cas_do`, `pocet`, `poznamka`)
SELECT z.id, 'plan', 'P1', z.p1_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p1_min > 0
UNION ALL SELECT z.id, 'plan', 'P2', z.p2_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p2_min > 0
UNION ALL SELECT z.id, 'plan', 'P3', z.p3_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p3_min > 0
UNION ALL SELECT z.id, 'plan', 'P4', z.p4_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p4_min > 0
UNION ALL SELECT z.id, 'plan', 'P5', z.p5_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p5_min > 0
UNION ALL SELECT z.id, 'plan', 'P6', z.p6_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p6_min > 0
UNION ALL SELECT z.id, 'plan', 'P7', z.p7_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p7_min > 0
UNION ALL SELECT z.id, 'plan', 'P8', z.p8_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p8_min > 0
UNION ALL SELECT z.id, 'plan', 'P9', z.p9_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p9_min > 0
UNION ALL SELECT z.id, 'plan', 'P10', z.p10_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.p10_min > 0
UNION ALL SELECT z.id, 'ost', 'O1', z.o1_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.o1_min > 0
UNION ALL SELECT z.id, 'log', 'L1', z.l1_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.l1_min > 0
UNION ALL SELECT z.id, 'log', 'L2', z.l2_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.l2_min > 0
UNION ALL SELECT z.id, 'vyr', 'V1', z.v1_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.v1_min > 0
UNION ALL SELECT z.id, 'vyr', 'V2', z.v2_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.v2_min > 0
UNION ALL SELECT z.id, 'vyr', 'V3', z.v3_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.v3_min > 0
UNION ALL SELECT z.id, 'kval', 'Q1', z.q1_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.q1_min > 0
UNION ALL SELECT z.id, 'kval', 'Q2', z.q2_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.q2_min > 0
UNION ALL SELECT z.id, 'kval', 'Q3', z.q3_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.q3_min > 0
UNION ALL SELECT z.id, 'kval', 'Q4', z.q4_min, NULL, NULL, 1, NULL FROM zapisy z WHERE z.q4_min > 0
UNION ALL
SELECT
    tp.zapis_id,
    'tech',
    UPPER(COALESCE(NULLIF(TRIM(tp.typ_kod), ''), 'TECH')),
    GREATEST(0, COALESCE(tp.minuty, 0)),
    tp.cas_od,
    tp.cas_do,
    GREATEST(1, COALESCE(tp.pocet, 1)),
    NULLIF(TRIM(COALESCE(tp.poznamka, '')), '')
FROM technicke_prostoje tp
WHERE COALESCE(tp.minuty, 0) > 0
UNION ALL
SELECT z.id, 'plan', 'PLAN_SUM',
       GREATEST(0, COALESCE(z.prostoj_planovany_min,0) - (
           COALESCE(z.p1_min,0)+COALESCE(z.p2_min,0)+COALESCE(z.p3_min,0)+COALESCE(z.p4_min,0)+COALESCE(z.p5_min,0)+
           COALESCE(z.p6_min,0)+COALESCE(z.p7_min,0)+COALESCE(z.p8_min,0)+COALESCE(z.p9_min,0)+COALESCE(z.p10_min,0)
       )),
       NULL, NULL, 1, NULL
FROM zapisy z
WHERE (COALESCE(z.prostoj_planovany_min,0) - (
           COALESCE(z.p1_min,0)+COALESCE(z.p2_min,0)+COALESCE(z.p3_min,0)+COALESCE(z.p4_min,0)+COALESCE(z.p5_min,0)+
           COALESCE(z.p6_min,0)+COALESCE(z.p7_min,0)+COALESCE(z.p8_min,0)+COALESCE(z.p9_min,0)+COALESCE(z.p10_min,0)
       )) > 0
UNION ALL
SELECT z.id, 'vyr', 'VYR_SUM',
       GREATEST(0, COALESCE(z.prostoj_vyrobni_min,0) - (COALESCE(z.v1_min,0)+COALESCE(z.v2_min,0)+COALESCE(z.v3_min,0))),
       NULL, NULL, 1, NULL
FROM zapisy z
WHERE (COALESCE(z.prostoj_vyrobni_min,0) - (COALESCE(z.v1_min,0)+COALESCE(z.v2_min,0)+COALESCE(z.v3_min,0))) > 0
UNION ALL
SELECT z.id, 'kval', 'KVAL_SUM',
       GREATEST(0, COALESCE(z.prostoj_kvalitativni_min,0) - (COALESCE(z.q1_min,0)+COALESCE(z.q2_min,0)+COALESCE(z.q3_min,0)+COALESCE(z.q4_min,0))),
       NULL, NULL, 1, NULL
FROM zapisy z
WHERE (COALESCE(z.prostoj_kvalitativni_min,0) - (COALESCE(z.q1_min,0)+COALESCE(z.q2_min,0)+COALESCE(z.q3_min,0)+COALESCE(z.q4_min,0))) > 0
UNION ALL
SELECT z.id, 'log', 'LOG_SUM',
       GREATEST(0, COALESCE(z.prostoj_logisticky_min,0) - (COALESCE(z.l1_min,0)+COALESCE(z.l2_min,0))),
       NULL, NULL, 1, NULL
FROM zapisy z
WHERE (COALESCE(z.prostoj_logisticky_min,0) - (COALESCE(z.l1_min,0)+COALESCE(z.l2_min,0))) > 0
UNION ALL
SELECT z.id, 'ost', 'OST_SUM',
       GREATEST(0, COALESCE(z.prostoj_ostatni_min,0) - COALESCE(z.o1_min,0)),
       NULL, NULL, 1, NULL
FROM zapisy z
WHERE (COALESCE(z.prostoj_ostatni_min,0) - COALESCE(z.o1_min,0)) > 0
UNION ALL
SELECT z.id, 'tech', 'TECH_SUM',
       GREATEST(0, COALESCE(z.prostoj_technicky_min,0) - COALESCE(tp.sum_min,0)),
       NULL, NULL, 1, NULL
FROM zapisy z
LEFT JOIN (
    SELECT zapis_id, SUM(COALESCE(minuty,0)) AS sum_min
    FROM technicke_prostoje
    GROUP BY zapis_id
) tp ON tp.zapis_id=z.id
WHERE (COALESCE(z.prostoj_technicky_min,0) - COALESCE(tp.sum_min,0)) > 0;

ALTER TABLE zapisy
  DROP COLUMN p1_min,
  DROP COLUMN p2_min,
  DROP COLUMN p3_min,
  DROP COLUMN p4_min,
  DROP COLUMN p5_min,
  DROP COLUMN p6_min,
  DROP COLUMN p7_min,
  DROP COLUMN p8_min,
  DROP COLUMN p9_min,
  DROP COLUMN p10_min,
  DROP COLUMN o1_min,
  DROP COLUMN l1_min,
  DROP COLUMN l2_min,
  DROP COLUMN v1_min,
  DROP COLUMN v2_min,
  DROP COLUMN v3_min,
  DROP COLUMN q1_min,
  DROP COLUMN q2_min,
  DROP COLUMN q3_min,
  DROP COLUMN q4_min,
  DROP COLUMN prostoj_technicky_min,
  DROP COLUMN prostoj_vyrobni_min,
  DROP COLUMN prostoj_kvalitativni_min,
  DROP COLUMN prostoj_logisticky_min,
  DROP COLUMN prostoj_ostatni_min,
  DROP COLUMN prostoj_planovany_min,
  DROP COLUMN prostoj_neplanovany_min,
  DROP COLUMN prostoj_celkem_min;

'@

$marker = '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;'
if (-not $content.Contains($marker)) {
    throw 'Footer marker not found in schema.sql'
}

$content = $content.Replace($marker, $insert + $marker)
[System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)
Write-Output 'PATCHED schema.sql'
