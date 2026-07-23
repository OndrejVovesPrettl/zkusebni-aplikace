<?php
require 'includes/config.php';

// Check all traceability project table structures
$projects = getTraceabilityProjects();

foreach ($projects as $proj) {
    $cols = trace_db_all("DESCRIBE $proj");
    echo "Table: $proj\n";
    foreach ($cols as $col) {
        echo "  - " . $col['Field'] . "\n";
    }
    echo "\n";
}
