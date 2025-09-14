-- Add supplier column to products table
ALTER TABLE products ADD COLUMN supplier VARCHAR(100) DEFAULT NULL;