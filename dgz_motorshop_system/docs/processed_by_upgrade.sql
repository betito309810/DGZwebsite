-- Run this script in your MySQL client (e.g., phpMyAdmin) to add the column/constraint
-- only when they are actually missing. Each step emits a notice instead of failing when
-- the schema has already been upgraded.

SET @schema_name := DATABASE();

-- Add the column if it does not exist yet.
SELECT COUNT(*)
INTO @processed_by_missing
FROM information_schema.columns
WHERE table_schema = @schema_name
  AND table_name = 'orders'
  AND column_name = 'processed_by_user_id';

SET @add_processed_by_sql := IF(
    @processed_by_missing = 0,
    'ALTER TABLE orders ADD COLUMN processed_by_user_id INT NULL',
    'SELECT "processed_by_user_id column already exists" AS message'
);

PREPARE stmt FROM @add_processed_by_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the foreign key constraint if it is missing.
SELECT COUNT(*)
INTO @processed_by_fk_missing
FROM information_schema.table_constraints tc
WHERE tc.table_schema = @schema_name
  AND tc.table_name = 'orders'
  AND tc.constraint_type = 'FOREIGN KEY'
  AND tc.constraint_name = 'fk_orders_processed_by';

SET @add_processed_by_fk_sql := IF(
    @processed_by_fk_missing = 0,
    'ALTER TABLE orders ADD CONSTRAINT fk_orders_processed_by FOREIGN KEY (processed_by_user_id) REFERENCES users(id)',
    'SELECT "fk_orders_processed_by constraint already exists" AS message'
);

PREPARE stmt FROM @add_processed_by_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
