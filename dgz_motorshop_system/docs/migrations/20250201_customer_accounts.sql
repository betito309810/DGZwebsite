-- Migration: customer accounts and enhanced order statuses

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (email IS NOT NULL OR phone IS NOT NULL),
    UNIQUE KEY idx_customers_email (email),
    UNIQUE KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    contact VARCHAR(190) NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_customer_password_resets_token (token),
    CONSTRAINT fk_customer_password_resets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED NULL AFTER customer_name;

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','approved','delivery','complete','cancelled_by_staff','cancelled_by_customer','completed','disapproved','cancelled','canceled') NOT NULL DEFAULT 'pending';

ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_orders_customer (customer_id);

ALTER TABLE orders
    ADD CONSTRAINT IF NOT EXISTS fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;
