<?php
require_once dirname(__DIR__) . '/includes/config.php';
logAction('LOGOUT', 'user', null, ['username' => $_SESSION['admin_user'] ?? null], []);
$_SESSION = [];
session_destroy();
redirect(APP_URL . '/admin/login.php');
