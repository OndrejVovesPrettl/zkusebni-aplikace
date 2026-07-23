<?php
require 'includes/config.php';

try {
    // Drop old incorrect table
    trace_db()->exec('DROP TABLE IF EXISTS gen3cina');
    
    // Create table with correct column names (matching other projects)
    trace_db()->exec('
        CREATE TABLE gen3cina (
            id int NOT NULL AUTO_INCREMENT,
            snDil varchar(255) NOT NULL,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            userId int DEFAULT NULL,
            PRIMARY KEY (id),
            KEY snDil (snDil),
            KEY timestamp (timestamp),
            KEY userId (userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    
    // Ensure limits entry exists
    trace_db()->exec('
        INSERT IGNORE INTO limits (project, `limit`, counterOnPallete, sPauzou, bezPauzy)
        VALUES ("gen3cina", 280, 0, "0", "0")
    ');
    
    echo "✓ Table gen3cina recreated with correct schema\n";
    echo "✓ Limit set to 280 pcs\n";
    
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
