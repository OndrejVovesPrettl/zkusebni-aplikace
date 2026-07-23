<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireAnyRole(['superadmin', 'admin', 'traceability']);

redirect(APP_URL . '/admin/users.php?scope=traceability');
