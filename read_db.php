<?php
// Connect to DVD database using credentials from config.local.php

$db_host = 'localhost';
$db_name = 'dvd_vyroba';
$db_user = 'root';
$db_pass = 'InitAdmin123+';

try {
    // Create PDO connection with UTF-8 support
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Show errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC  // Return associative arrays
        ]
    );
    
    echo "✓ Database connection successful!\n";
    echo "Connected to: " . $db_name . " on " . $db_host . "\n\n";
    
    // List all tables in the database
    echo "=== Tables in database ===\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "  - {$table}\n";
    }
    
    // Show table structure for key tables
    echo "\n=== Table Structure ===\n";
    
    $keyTables = ['zapisy_prostoje', 'pracovnici', 'nok_kody', 'prostoj_typy'];
    foreach ($keyTables as $table) {
        if (in_array($table, $tables)) {
            echo "\n--- Table: {$table} ---\n";
            
            // Get column names
            $stmt = $pdo->query("DESCRIBE {$table}");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $col) {
                echo "  - {$col}\n";
            }
            
            // Count rows
            $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            echo "  Total rows: {$count}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>

