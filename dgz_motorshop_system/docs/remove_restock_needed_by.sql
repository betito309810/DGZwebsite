-- Run this script in MySQL or phpMyAdmin to remove the legacy needed_by column from restock requests.
-- The script only drops the column when it exists so it can be executed safely multiple times.

SET @schema_name := DATABASE();

SELECT COUNT(*)
INTO @restock_table_exists
FROM information_schema.tables
WHERE table_schema = @schema_name
  AND table_name = 'restock_requests';

SELECT COUNT(*)
INTO @needed_by_exists
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'restock_requests'
  AND column_name = 'needed_by';

SET @drop_needed_by_sql := CASE
    WHEN @restock_table_exists = 0 THEN 'SELECT "restock_requests table not found" AS message'
    WHEN @needed_by_exists = 0 THEN 'SELECT "needed_by column already removed" AS message'
    ELSE 'ALTER TABLE restock_requests DROP COLUMN needed_by'
END;

PREPARE stmt FROM @drop_needed_by_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
