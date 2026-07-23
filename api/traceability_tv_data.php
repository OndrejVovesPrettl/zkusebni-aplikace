<?php
/**
 * api/traceability_tv_data.php
 * JSON endpoint – live data for Traceability TV dashboard.
 * Returns palette limits + hourly production for all projects.
 * Public endpoint for TV dashboard.
 */
require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $traceabilityTvHours = 13;
    $activityHours = 12;
    $shiftMode = in_array($_GET['shift_mode'] ?? '', ['8h', '12h'], true) ? $_GET['shift_mode'] : '8h';
    $projects = getTraceabilityProjects();
    $data = [];
    foreach ($projects as $project) {
        $limits = getTraceabilityProjectLimits($project);
        $hourly = getTraceabilityHourlyProduction($project, $traceabilityTvHours);
        $worker = getTraceabilityProjectActiveWorker($project);
        $active = traceabilityProjectHasRecentActivity($project, $activityHours);
        $shiftCount = getTraceabilityShiftProduction($project, $shiftMode);
        $data[$project] = [
            'limits'      => $limits,
            'hourly'      => $hourly,
            'worker'      => $worker,
            'active'      => $active,
            'shift_count' => $shiftCount,
        ];
    }
    echo json_encode([
        'ok'       => true,
        'projects' => $data,
        'ts'       => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
