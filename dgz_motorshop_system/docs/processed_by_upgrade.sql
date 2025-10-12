-- Safely add the processed_by_user_id column and foreign key to the orders table.
-- This script is idempotent; it will only alter the schema when the pieces are missing.

SET @schema_name := DATABASE();

-- Add the column if it does not exist yet.
SET @missing_processed_by := (
    SELECT COUNT(*) = 0
    FROM information_schema.columns
    WHERE table_schema = @schema_name
      AND table_name = 'orders'
      AND column_name = 'processed_by_user_id'
);

SET @add_processed_by_sql := IF(
    @missing_processed_by,
    'ALTER TABLE orders ADD COLUMN processed_by_user_id INT NULL',
    'SELECT "processed_by_user_id column already exists" AS message'
);

PREPARE stmt FROM @add_processed_by_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the foreign key constraint if it is missing.
SET @missing_processed_by_fk := (
    SELECT COUNT(*) = 0
    FROM information_schema.table_constraints tc
    WHERE tc.table_schema = @schema_name
      AND tc.table_name = 'orders'
      AND tc.constraint_type = 'FOREIGN KEY'
      AND tc.constraint_name = 'fk_orders_processed_by'
);

SET @add_processed_by_fk_sql := IF(
    @missing_processed_by_fk,
    'ALTER TABLE orders ADD CONSTRAINT fk_orders_processed_by FOREIGN KEY (processed_by_user_id) REFERENCES users(id)',
    'SELECT "fk_orders_processed_by constraint already exists" AS message'
);

PREPARE stmt FROM @add_processed_by_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
