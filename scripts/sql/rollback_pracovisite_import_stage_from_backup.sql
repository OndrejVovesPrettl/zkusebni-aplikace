-- Rollback template for restoring table pracovisite_import_stage from backup.
-- Replace the backup table name before running.
-- Usage:
--   mysql -u <user> -p <db_name> < scripts/sql/rollback_pracovisite_import_stage_from_backup.sql

SET @target_table := 'pracovisite_import_stage';
SET @backup_table := 'pracovisite_import_stage_backup_YYYYMMDD_HHMMSS';

SELECT @backup_table AS backup_to_restore;

-- Ensure backup exists first.
SELECT COUNT(*) INTO @backup_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = @backup_table;

SELECT @backup_exists AS backup_exists;

-- Recreate target from backup (drops target first if it exists).
SET @sql := IF(
  @backup_exists = 1,
  CONCAT('DROP TABLE IF EXISTS `', @target_table, '`'),
  'SELECT ''backup table not found, aborting'' AS error'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @backup_exists = 1,
  CONCAT('CREATE TABLE `', @target_table, '` LIKE `', @backup_table, '`'),
  'SELECT ''skip create'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @backup_exists = 1,
  CONCAT('INSERT INTO `', @target_table, '` SELECT * FROM `', @backup_table, '`'),
  'SELECT ''skip insert'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'rollback_finished' AS status, @target_table AS restored_table;
