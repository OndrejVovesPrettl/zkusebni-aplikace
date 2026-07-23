<?php
require_once __DIR__ . '/../includes/config.php';

$sqlFile = __DIR__ . '/sql/migrate_zapisy_prostoje.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "ERR: Migration file not found: {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "ERR: Cannot read migration file.\n");
    exit(1);
}

try {
    $pdo = db();
    $pdo->exec($sql);

    $tableExists = (int)db_val("SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'zapisy_prostoje'");
    $countZapisy = (int)db_val('SELECT COUNT(*) FROM zapisy');
    $countProstojeRows = (int)db_val('SELECT COUNT(*) FROM zapisy_prostoje');
    $countWithDowntime = (int)db_val('SELECT COUNT(DISTINCT zapis_id) FROM zapisy_prostoje');

    fwrite(STDOUT, "OK: zapisy_prostoje schema ensured\n");
    fwrite(STDOUT, "zapisy_prostoje_table_exists={$tableExists}\n");
    fwrite(STDOUT, "zapisy_count={$countZapisy}\n");
    fwrite(STDOUT, "zapisy_prostoje_rows={$countProstojeRows}\n");
    fwrite(STDOUT, "zapisy_with_downtime={$countWithDowntime}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: ' . $e->getMessage() . "\n");
    exit(1);
}
