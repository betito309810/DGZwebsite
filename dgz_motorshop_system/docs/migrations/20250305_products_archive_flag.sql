ALTER TABLE products
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL AFTER is_archived;
CREATE INDEX IF NOT EXISTS idx_products_archived ON products (is_archived);
