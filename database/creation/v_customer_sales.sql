-- View: Customer Sales Summary
CREATE VIEW v_customer_sales AS
SELECT 
    u.user_id,
    CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
    u.email,
    COUNT(s.sale_id) AS total_transactions,
    SUM(s.quantity) AS total_items_purchased,
    SUM(s.total_amount) AS total_spent,
    MAX(s.sale_date) AS last_purchase_date
FROM users u
LEFT JOIN sales s ON u.user_id = s.user_id
GROUP BY u.user_id, u.first_name, u.last_name, u.email;