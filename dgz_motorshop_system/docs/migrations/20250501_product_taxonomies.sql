-- Product catalog taxonomy table for managing brands, categories, and suppliers
CREATE TABLE IF NOT EXISTS product_taxonomies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_taxonomies_type_name (type, normalized_name),
    KEY idx_product_taxonomies_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE product_taxonomies
    ADD COLUMN IF NOT EXISTS normalized_name VARCHAR(190) NOT NULL AFTER name,
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER normalized_name,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL AFTER is_archived,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE UNIQUE INDEX IF NOT EXISTS uq_product_taxonomies_type_name ON product_taxonomies (type, normalized_name);
CREATE INDEX IF NOT EXISTS idx_product_taxonomies_type ON product_taxonomies (type);
