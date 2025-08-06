-- View: Sales Transaction Details
CREATE VIEW v_sales_details AS
SELECT 
    s.sale_id,
    CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
    u.email,
    p.product_name,
    p.sku,
    c.category_name,
    s.quantity,
    s.unit_price,
    s.total_amount,
    s.discount_amount,
    s.payment_method,
    s.payment_status,
    s.sale_type,
    s.sale_date
FROM sales s
JOIN users u ON s.user_id = u.user_id
JOIN products p ON s.product_id = p.product_id
LEFT JOIN categories c ON p.category_id = c.category_id;