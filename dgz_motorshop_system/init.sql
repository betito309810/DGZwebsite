
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

-- products table
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50),
  name VARCHAR(200),
  description TEXT,
  price DECIMAL(10,2),
  quantity INT DEFAULT 0,
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

-- stock entries table
CREATE TABLE IF NOT EXISTS stock_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT,
  quantity_added INT NOT NULL,
  supplier VARCHAR(255) NOT NULL,
  notes TEXT,
  stock_in_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (stock_in_by) REFERENCES users(id)
);

-- orders table
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(200),
  contact VARCHAR(100),
  address TEXT,
  total DECIMAL(12,2),
  payment_method VARCHAR(50),
  payment_proof VARCHAR(255),
  status VARCHAR(50) DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- order_items
CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT,
  product_id INT,
  qty INT,
  price DECIMAL(10,2),
  description VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- sample admin user (password: password123)
INSERT INTO users (name,email,password,role) VALUES
('Admin','admin@example.com',SHA2('password123',256),'admin');

-- sample products
INSERT INTO products (code,name,description,price,quantity,low_stock_threshold) VALUES
('P001','Shell AX7 Scooter Oil','High-performance scooter oil',550.00,20,5),
('P002','Motul Scooter Oil','Premium scooter oil',650.00,15,5),
('P003','JVT V3 Pipe for Nmax/Aerox','Performance exhaust pipe',2500.00,5,2);

