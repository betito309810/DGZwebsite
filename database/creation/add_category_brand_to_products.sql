-- Add category and brand columns to products table
ALTER TABLE products 
ADD COLUMN category VARCHAR(100) DEFAULT NULL,
ADD COLUMN brand VARCHAR(100) DEFAULT NULL;