<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonOut(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    jsonOut(['ok' => false, 'message' => 'Invalid JSON payload'], 400);
}

$csrf = (string)($data['_csrf'] ?? '');
if (!verifyCsrfToken($csrf)) {
    jsonOut(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

$sections = $data['sections'] ?? [];
if (!is_array($sections)) {
    jsonOut(['ok' => false, 'message' => 'Invalid sections payload'], 400);
}

if (!saveCurrentUserSidebarSectionsState($sections)) {
    jsonOut(['ok' => false, 'message' => 'Unable to save sidebar sections'], 500);
}

jsonOut(['ok' => true]);
