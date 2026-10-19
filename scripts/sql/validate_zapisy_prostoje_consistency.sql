-- Post-cleanup validace konzistence normalizovanych prostojovych dat

-- 1) Orphan rows v zapisy_prostoje
SELECT
    'orphan_rows_count' AS metric,
    COUNT(*) AS value
FROM zapisy_prostoje zp
LEFT JOIN zapisy z ON z.id = zp.zapis_id
WHERE z.id IS NULL;

-- 2) Pocet zapisu bez downtime radku
SELECT
    'rows_without_downtime_record' AS metric,
    COUNT(*) AS value
FROM zapisy z
LEFT JOIN zapisy_prostoje zp ON zp.zapis_id = z.id
WHERE zp.zapis_id IS NULL;

-- 3) Globalni soucet downtime minut
SELECT
    'global_new_total' AS metric,
    COALESCE(SUM(COALESCE(minuty,0)),0) AS value
FROM zapisy_prostoje;

-- 4) Agregace podle kategorie
SELECT
    COALESCE(kategorie, 'UNKNOWN') AS kategorie,
    COUNT(*) AS rows_count,
    COALESCE(SUM(minuty),0) AS total_minuty
FROM zapisy_prostoje
GROUP BY kategorie
ORDER BY total_minuty DESC;

-- 5) Top 20 zapisu s nejvetsim prostojem
SELECT
    z.id,
    z.datum,
    z.smena,
    COALESCE(SUM(zp.minuty),0) AS downtime_total,
    SUM(CASE WHEN zp.kategorie='plan' THEN zp.minuty ELSE 0 END) AS plan_total,
    SUM(CASE WHEN zp.kategorie IN ('tech','vyr','kval','log','ost') THEN zp.minuty ELSE 0 END) AS neplan_total
FROM zapisy z
LEFT JOIN zapisy_prostoje zp ON zp.zapis_id=z.id
GROUP BY z.id, z.datum, z.smena
ORDER BY downtime_total DESC, z.id DESC
LIMIT 20;
