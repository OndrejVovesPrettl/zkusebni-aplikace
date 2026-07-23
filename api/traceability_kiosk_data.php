<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireTraceabilityKioskLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $project = getTraceabilitySelectedProject();
    if ($project === '' || !isTraceabilityProject($project)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => t('traceability_project_invalid'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    $limits = getTraceabilityProjectLimits($project);
    $paletteFull = $limits['limit'] > 0 && $limits['counter'] >= $limits['limit'];
    $shiftMode = in_array($_GET['shift_mode'] ?? '', ['8h', '12h'], true) ? $_GET['shift_mode'] : '8h';
    $shiftCount = getTraceabilityShiftProduction($project, $shiftMode);

    echo json_encode([
        'ok' => true,
        'project' => $project,
        'limits' => [
            'counter' => (int)($limits['counter'] ?? 0),
            'limit' => (int)($limits['limit'] ?? 0),
        ],
        'palette_full' => $paletteFull,
        'shift_count' => $shiftCount,
        'ts' => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
