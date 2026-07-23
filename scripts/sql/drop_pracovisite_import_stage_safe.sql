-- Safe drop for table pracovisite_import_stage with data backup.
-- Creates backup table in the same schema and then drops target table.
-- Usage:
--   mysql -u <user> -p <db_name> < scripts/sql/drop_pracovisite_import_stage_safe.sql

SET @target_table := 'pracovisite_import_stage';
SET @backup_table := CONCAT(@target_table, '_backup_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%S'));

-- Abort early if target table does not exist.
SELECT COUNT(*) INTO @table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = @target_table;

SELECT @table_exists AS target_exists, @target_table AS target_table;

-- Create structure copy.
SET @sql := IF(
  @table_exists = 1,
  CONCAT('CREATE TABLE `', @backup_table, '` LIKE `', @target_table, '`'),
  'SELECT ''target table does not exist, nothing to do'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Copy data.
SET @sql := IF(
  @table_exists = 1,
  CONCAT('INSERT INTO `', @backup_table, '` SELECT * FROM `', @target_table, '`'),
  'SELECT ''skip copy'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop target table.
SET @sql := IF(
  @table_exists = 1,
  CONCAT('DROP TABLE `', @target_table, '`'),
  'SELECT ''skip drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT @backup_table AS created_backup_table;
