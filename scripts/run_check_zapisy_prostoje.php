<?php
require_once __DIR__ . '/../includes/config.php';

$checks = [
    'global_new_total' => "SELECT COALESCE(SUM(COALESCE(minuty,0)),0) FROM zapisy_prostoje",
    'orphan_rows_count' => "
        SELECT COUNT(*)
        FROM zapisy_prostoje zp
        LEFT JOIN zapisy z ON z.id = zp.zapis_id
        WHERE z.id IS NULL
    ",
    'rows_without_downtime_record' => "
        SELECT COUNT(*)
        FROM zapisy z
        LEFT JOIN zapisy_prostoje zp ON zp.zapis_id=z.id
        WHERE zp.zapis_id IS NULL
    ",
    'tech_detail_rows' => "SELECT COUNT(*) FROM zapisy_prostoje WHERE kategorie='tech' AND typ_kod<>'TECH_SUM'",
    'tech_summary_rows' => "SELECT COUNT(*) FROM zapisy_prostoje WHERE kategorie='tech' AND typ_kod='TECH_SUM'",
];

try {
    foreach ($checks as $label => $sql) {
        $val = db_val($sql);
        echo $label . '=' . (string)$val . PHP_EOL;
    }

    $samples = db_all("SELECT z.id, z.datum, z.smena,
            COALESCE(SUM(zp.minuty),0) AS downtime_total,
            SUM(CASE WHEN zp.kategorie='plan' THEN zp.minuty ELSE 0 END) AS plan_total,
            SUM(CASE WHEN zp.kategorie IN ('tech','vyr','kval','log','ost') THEN zp.minuty ELSE 0 END) AS neplan_total
        FROM zapisy z
        LEFT JOIN zapisy_prostoje zp ON zp.zapis_id=z.id
        GROUP BY z.id, z.datum, z.smena
        ORDER BY downtime_total DESC, z.id DESC
        LIMIT 5");

    if (!$samples) {
        echo "samples=none" . PHP_EOL;
    } else {
        foreach ($samples as $row) {
            echo 'sample=id:' . (int)$row['id']
                . ',datum:' . (string)$row['datum']
                . ',smena:' . (string)$row['smena']
                . ',total:' . (int)$row['downtime_total']
                . ',plan:' . (int)$row['plan_total']
                . ',neplan:' . (int)$row['neplan_total']
                . PHP_EOL;
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
