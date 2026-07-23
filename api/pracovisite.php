<?php
// api/pracovisite.php
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$id = (int)get('id');
if (!$id) { echo json_encode([]); exit; }
$row = db_row('SELECT * FROM pracovisite WHERE id=? AND aktivni=1', [$id]);
echo json_encode($row ?: []);
