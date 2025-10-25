
-- Init SQL for DGZ Motorshop minimal system
CREATE DATABASE IF NOT EXISTS dgz_db;
USE dgz_db;

-- users table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  email VARCHAR(100) UNIQUE,
  password VARCHAR(255),
  role ENUM('admin','staff') DEFAULT 'staff',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- customers table
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(120) NULL,
  middle_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  address_line1 TEXT NULL,
  city VARCHAR(120) NULL,
  postal_code VARCHAR(20) NULL,
  email_verified_at DATETIME NULL,
  verification_token VARCHAR(120) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (email IS NOT NULL OR phone IS NOT NULL),
  UNIQUE KEY idx_customers_email (email),
  UNIQUE KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- customer cart items allow authenticated shoppers to resume carts across devices
CREATE TABLE IF NOT EXISTS customer_cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  variant_id INT DEFAULT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_customer_cart_unique (customer_id, product_id, variant_id),
  CONSTRAINT fk_customer_cart_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_cart_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_cart_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- customer password reset requests
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

-- products table
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50),
  name VARCHAR(200),
  description TEXT,
  price DECIMAL(10,2),
  quantity INT DEFAULT 0,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  archived_at DATETIME NULL DEFAULT NULL,
  low_stock_threshold INT DEFAULT 5,
  image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Added: store any additional gallery images associated with a product.
CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Added: table that stores per-product variants/sizes (e.g., 50ml vs 100ml bottles).
CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  label VARCHAR(100) NOT NULL,
  sku VARCHAR(100) DEFAULT NULL,
  variant_code VARCHAR(100) DEFAULT NULL,
  price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  low_stock_threshold INT DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_product_variants_product (product_id),
  INDEX idx_product_variants_sort (product_id, sort_order)
);

-- stock entries table
CREATE TABLE IF NOT EXISTS stock_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  quantity_added INT NOT NULL,
  supplier VARCHAR(255) NOT NULL,
  notes TEXT,
  stock_in_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id), -- Potential blocker: live DBs that keep the default RESTRICT rule here
                                                   -- will stop product deletions unless related stock entries are cleared.
  FOREIGN KEY (stock_in_by) REFERENCES users(id)
);

-- orders table
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(200),
  customer_id INT UNSIGNED NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  contact VARCHAR(100),
  address TEXT,
  facebook_account VARCHAR(190) NULL,
  customer_note TEXT NULL,
  total DECIMAL(12,2),
  payment_method VARCHAR(50),
  payment_proof VARCHAR(255),
  postal_code VARCHAR(20) NULL,
  city VARCHAR(120) NULL,
  status ENUM('pending','approved','delivery','complete','cancelled_by_staff','cancelled_by_customer','completed','disapproved','cancelled','canceled') NOT NULL DEFAULT 'pending',
  processed_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (processed_by_user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders(customer_id);

-- order_items
CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT,
  product_id INT,
  variant_id INT DEFAULT NULL,
  qty INT,
  price DECIMAL(10,2),
  variant_label VARCHAR(100) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL, -- Potential blocker: if this constraint is altered to
                                                                      -- RESTRICT in production, the admin delete flow must
                                                                      -- null/delete dependents first or it will fail.
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

-- sample admin user (password: password123)
INSERT INTO users (name,email,password,role) VALUES
('Admin','admin@example.com',SHA2('password123',256),'admin');

-- sample products
INSERT INTO products (code,name,description,price,quantity,low_stock_threshold) VALUES
('P001','Shell AX7 Scooter Oil','High-performance scooter oil',550.00,20,5),
('P002','Motul Scooter Oil','Premium scooter oil',650.00,15,5),
('P003','JVT V3 Pipe for Nmax/Aerox','Performance exhaust pipe',2500.00,5,2);

