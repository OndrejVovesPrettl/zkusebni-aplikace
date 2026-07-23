<?php
require 'includes/config.php';
$projects = getTraceabilityProjects();
echo "Available traceability projects:\n";
foreach ($projects as $proj) {
    echo "  - $proj\n";
}
