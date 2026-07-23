<?php
require 'includes/config.php';
$rows = trace_db_all('SELECT * FROM limits LIMIT 3');
echo "Existing limits entries:\n";
foreach ($rows as $row) {
    echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}
