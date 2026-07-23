<?php
require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$targetExists = (int)db_val('SELECT COUNT(*) FROM nok_kody WHERE id = 41 AND aktivni = 1');
if ($targetExists === 0) {
    fwrite(STDERR, "Active NOK id=41 not found.\n");
    exit(2);
}

$before = [
    'rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok'),
    'positive_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE COALESCE(pocet, 0) > 0'),
    'target_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE nok_kod_id = 41'),
    'target_qty' => (int)(db_val('SELECT COALESCE(SUM(pocet), 0) FROM zapis_nok WHERE nok_kod_id = 41') ?? 0),
];

$sql = file_get_contents(APP_ROOT . '/scripts/sql/remap_historical_nok_to_41.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read SQL script.\n");
    exit(3);
}

try {
    db()->exec($sql);
} catch (Throwable $e) {
    fwrite(STDERR, 'Remap failed: ' . $e->getMessage() . PHP_EOL);
    exit(4);
}

$after = [
    'rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok'),
    'positive_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE COALESCE(pocet, 0) > 0'),
    'target_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE nok_kod_id = 41'),
    'target_qty' => (int)(db_val('SELECT COALESCE(SUM(pocet), 0) FROM zapis_nok WHERE nok_kod_id = 41') ?? 0),
];

$out = [
    'before' => $before,
    'after' => $after,
    'status' => 'ok',
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
