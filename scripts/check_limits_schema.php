<?php
require 'includes/config.php';
$desc = trace_db_all('DESCRIBE limits');
echo "Limits table columns:\n";
foreach ($desc as $col) {
    echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
