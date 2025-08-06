-- View: Product Sales Summary
CREATE VIEW v_product_sales AS
SELECT 
    p.product_id,
    p.product_name,
    p.sku,
    c.category_name,
    p.price AS current_price,
    p.stock_quantity,
    COALESCE(SUM(s.quantity), 0) AS total_sold,
    COALESCE(SUM(s.total_amount), 0) AS total_revenue,
    COUNT(DISTINCT s.sale_id) AS total_transactions
FROM products p
LEFT JOIN categories c ON p.category_id = c.category_id
LEFT JOIN sales s ON p.product_id = s.product_id
GROUP BY p.product_id, p.product_name, p.sku, c.category_name, p.price, p.stock_quantity;
