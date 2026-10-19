-- Check dependencies for table pracovisite_import_stage
-- Usage:
--   mysql -u <user> -p <db_name> < scripts/sql/check_pracovisite_import_stage_dependencies.sql

SET @target_table := 'pracovisite_import_stage';
SET @target_schema := DATABASE();

SELECT 'table_exists' AS check_type,
       t.TABLE_SCHEMA,
       t.TABLE_NAME,
       t.ENGINE,
       t.TABLE_ROWS
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA = @target_schema
  AND t.TABLE_NAME = @target_table;

SELECT 'foreign_keys_from_other_tables' AS check_type,
       rc.CONSTRAINT_SCHEMA,
       rc.TABLE_NAME,
       rc.CONSTRAINT_NAME,
       rc.REFERENCED_TABLE_NAME
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = @target_schema
  AND rc.REFERENCED_TABLE_NAME = @target_table;

SELECT 'foreign_keys_defined_on_target' AS check_type,
       kcu.CONSTRAINT_SCHEMA,
       kcu.TABLE_NAME,
       kcu.CONSTRAINT_NAME,
       kcu.COLUMN_NAME,
       kcu.REFERENCED_TABLE_NAME,
       kcu.REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE kcu
WHERE kcu.CONSTRAINT_SCHEMA = @target_schema
  AND kcu.TABLE_NAME = @target_table
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL;

SELECT 'views_referencing_target' AS check_type,
       v.TABLE_SCHEMA,
       v.TABLE_NAME
FROM information_schema.VIEWS v
WHERE v.TABLE_SCHEMA = @target_schema
  AND LOWER(v.VIEW_DEFINITION) LIKE CONCAT('%', LOWER(@target_table), '%');

SELECT 'triggers_referencing_target' AS check_type,
       tr.TRIGGER_SCHEMA,
       tr.TRIGGER_NAME,
       tr.EVENT_OBJECT_TABLE,
       tr.ACTION_TIMING,
       tr.EVENT_MANIPULATION
FROM information_schema.TRIGGERS tr
WHERE tr.TRIGGER_SCHEMA = @target_schema
  AND LOWER(tr.ACTION_STATEMENT) LIKE CONCAT('%', LOWER(@target_table), '%');

SELECT 'routines_referencing_target' AS check_type,
       r.ROUTINE_SCHEMA,
       r.ROUTINE_NAME,
       r.ROUTINE_TYPE
FROM information_schema.ROUTINES r
WHERE r.ROUTINE_SCHEMA = @target_schema
  AND LOWER(r.ROUTINE_DEFINITION) LIKE CONCAT('%', LOWER(@target_table), '%');

SELECT 'events_referencing_target' AS check_type,
       e.EVENT_SCHEMA,
       e.EVENT_NAME
FROM information_schema.EVENTS e
WHERE e.EVENT_SCHEMA = @target_schema
  AND LOWER(e.EVENT_DEFINITION) LIKE CONCAT('%', LOWER(@target_table), '%');

-- Optional check for plain text SQL that may reference the table in metadata
SELECT 'columns_with_table_name_literal' AS check_type,
       c.TABLE_SCHEMA,
       c.TABLE_NAME,
       c.COLUMN_NAME
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @target_schema
  AND LOWER(c.COLUMN_COMMENT) LIKE CONCAT('%', LOWER(@target_table), '%');
