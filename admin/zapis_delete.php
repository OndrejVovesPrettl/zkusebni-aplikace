<?php
// admin/zapis_delete.php
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$id = (int)get('id');
if ($id) {
    db()->prepare('DELETE FROM zapisy WHERE id=?')->execute([$id]);
}
redirect(APP_URL . '/admin/zaznamy.php?ok_del=1');
