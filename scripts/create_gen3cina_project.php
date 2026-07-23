<?php
/**
 * scripts/create_gen3cina_project.php
 * Create gen3cina traceability project
 */
require_once dirname(__DIR__) . '/includes/config.php';

try {
    // Create table with correct column names (matching other projects)
    trace_db()->exec('
        CREATE TABLE IF NOT EXISTS gen3cina (
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
    
    // Add to limits
    trace_db()->exec('
        INSERT IGNORE INTO limits (project, `limit`, counterOnPallete, sPauzou, bezPauzy)
        VALUES ("gen3cina", 280, 0, "0", "0")
    ');
    
    echo "✓ Project gen3cina created successfully\n";
    echo "✓ Limit set to 280 pcs\n";
    
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
