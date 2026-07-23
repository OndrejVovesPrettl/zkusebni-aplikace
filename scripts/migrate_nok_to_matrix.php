<?php
require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$before = [
    'zapis_nok_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok'),
    'non_41_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE nok_kod_id <> 41'),
    'nok_kody_rows' => (int)db_val('SELECT COUNT(*) FROM nok_kody WHERE aktivni=1'),
    'nok_matrix_rows' => (int)db_val('SELECT COUNT(*) FROM nok_matrix_afo'),
];

$sql = file_get_contents(APP_ROOT . '/scripts/sql/migrate_nok_to_matrix.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read SQL script.\n");
    exit(2);
}

try {
    foreach (explode(';', $sql) as $stmt) {
        $s = trim($stmt);
        if ($s !== '') {
            db()->exec($s);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(3);
}

$after = [
    'zapis_nok_rows' => (int)db_val('SELECT COUNT(*) FROM zapis_nok'),
    'all_on_41' => (int)db_val('SELECT COUNT(*) FROM zapis_nok WHERE nok_kod_id = 41'),
    'nok_matrix_rows' => (int)db_val('SELECT COUNT(*) FROM nok_matrix_afo'),
    'nok_matrix_with_meta' => (int)db_val('SELECT COUNT(*) FROM nok_matrix_afo WHERE kod > 0 AND popis <> \'\''),
];

$out = [
    'before' => $before,
    'after' => $after,
    'status' => 'ok',
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
