<?php
require 'includes/config.php';

// Check gen3cina table exists
$tables = trace_db_all("SHOW TABLES LIKE 'gen3cina'");
echo "✓ gen3cina table exists: " . (count($tables) > 0 ? "YES" : "NO") . "\n";

// Check limits entry
$limits = trace_db_row("SELECT * FROM limits WHERE project = 'gen3cina'");
if ($limits) {
    echo "✓ gen3cina limits found:\n";
    echo "  - Limit: " . $limits['limit'] . " pcs\n";
    echo "  - Counter: " . $limits['counterOnPallete'] . "\n";
    echo "  - s pauzou: " . $limits['sPauzou'] . "\n";
    echo "  - bez pauzy: " . $limits['bezPauzy'] . "\n";
} else {
    echo "✗ gen3cina not found in limits table\n";
}

// Check it's in project list
$projects = getTraceabilityProjects();
echo "✓ gen3cina in project list: " . (in_array('gen3cina', $projects) ? "YES" : "NO") . "\n";
