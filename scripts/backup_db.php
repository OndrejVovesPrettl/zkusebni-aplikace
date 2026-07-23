<?php
/**
 * backup_db.php – CLI skript pro zálohu databáze do složky backupSQL/
 * Spouštěn přes admin/scripts.php (PHP type).
 */

require_once dirname(__DIR__) . '/includes/config.php';

$backupDir = APP_ROOT . '/backupSQL';
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true)) {
        echo "ERROR: Nelze vytvořit složku backupSQL/\n";
        exit(1);
    }
}

// Najít mysqldump
$candidates = [
    'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe',
    'C:/laragon/bin/mysql/mysql-9.1.0-winx64/bin/mysqldump.exe',
    'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe',
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
];

$mysqldump = null;
foreach ($candidates as $c) {
    if (is_file($c)) {
        $mysqldump = $c;
        break;
    }
}

if ($mysqldump === null) {
    // Jako poslední pokus zkusit z PATH
    exec('mysqldump --version 2>&1', $out, $rc);
    if ($rc === 0) {
        $mysqldump = 'mysqldump';
    }
}

if ($mysqldump === null) {
    echo "ERROR: mysqldump nebyl nalezen.\n";
    exit(1);
}

$timestamp = date('Y-m-d_Hi');
$version    = defined('APP_VERSION') ? APP_VERSION : 'unknown';
$fileName   = "backup_{$timestamp}_v{$version}.sql";
$filePath   = $backupDir . '/' . $fileName;

$cmd = escapeshellarg($mysqldump)
    . ' -h ' . escapeshellarg(DB_HOST)
    . ' -u ' . escapeshellarg(DB_USER)
    . ' -p' . escapeshellarg(DB_PASS)
    . ' --single-transaction --routines --triggers'
    . ' ' . escapeshellarg(DB_NAME)
    . ' > ' . escapeshellarg($filePath)
    . ' 2>&1';

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !is_file($filePath)) {
    echo "ERROR: Záloha selhala (exit $exitCode).\n";
    if (!empty($output)) {
        echo implode("\n", $output) . "\n";
    }
    if (is_file($filePath)) {
        unlink($filePath);
    }
    exit(1);
}

$sizeKb = round(filesize($filePath) / 1024);
echo "OK: Záloha uložena.\n";
echo "Soubor: backupSQL/{$fileName}\n";
echo "Velikost: {$sizeKb} kB\n";
echo "Databáze: " . DB_NAME . "\n";
echo "Čas: " . date('Y-m-d H:i:s') . "\n";

// Uklidit zálohy starší 30 dní (ponechat poslední 30 souborů)
$files = glob($backupDir . '/backup_*.sql');
if ($files !== false && count($files) > 30) {
    usort($files, static fn($a, $b) => filemtime($a) - filemtime($b));
    $toDelete = array_slice($files, 0, count($files) - 30);
    foreach ($toDelete as $old) {
        unlink($old);
        echo "Smazána stará záloha: " . basename($old) . "\n";
    }
}
