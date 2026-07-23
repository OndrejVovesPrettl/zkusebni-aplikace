SET NAMES utf8mb4;

SELECT 'validate_import_data.sql' AS report_name;
SOURCE scripts/sql/validate_import_data.sql;

SELECT 'validate_pracovnici_data.sql' AS report_name;
SOURCE scripts/sql/validate_pracovnici_data.sql;

SELECT 'validate_zapisy_jmeno_format.sql' AS report_name;
SOURCE scripts/sql/validate_zapisy_jmeno_format.sql;