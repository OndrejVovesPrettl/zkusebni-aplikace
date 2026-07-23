#!/usr/bin/env php
<?php
/**
 * One-time script: Set password_changed_at to NOW() for all users with role 'read'
 */
require_once dirname(__DIR__) . '/includes/config.php';

try {
    $stmt = db()->prepare("UPDATE users SET password_changed_at = NOW() WHERE role = 'read'");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "✓ Aktualizováno $affected uživatelů s rolí 'read'.\n";
} catch (Throwable $e) {
    echo "✗ Chyba: " . $e->getMessage() . "\n";
    exit(1);
}
