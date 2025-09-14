CREATE TABLE product_add_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(32) NOT NULL DEFAULT 'add',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details TEXT
) ENGINE=InnoDB;