INSERT INTO sales (
  product_id, user_id, quantity, unit_price, total_amount,
  discount_amount, sale_type, payment_method, payment_status,
  sale_date, created_at
) VALUES
(1, 2, 1, 550.00, 550.00, 0.00, 'online', 'cash', 'paid', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 3, 2, 320.00, 600.00, 40.00, 'in_store', 'credit_card', 'paid', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(4, 2, 5, 900.00, 4000.00, 500.00, 'wholesale', 'bank_transfer', 'paid', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(3, 1, 1, 600.00, 600.00, 0.00, 'online', 'paypal', 'failed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(1, 4, 3, 550.00, 1650.00, 0.00, 'retail', 'debit_card', 'partial', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 5, 1, 320.00, 320.00, 0.00, 'in_store', 'cash', 'refunded', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
