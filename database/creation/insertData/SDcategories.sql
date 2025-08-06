INSERT INTO categories (
  category_name, description, parent_category_id, is_active, created_at, updated_at
) VALUES 
('Motorcycle Parts', 'All essential and replacement parts for motorcycles.', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Oils & Lubricants', 'Engine oils, gear oils, and maintenance fluids.', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Accessories', 'Helmets, lights, and motorcycle add-ons.', NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Brakes', 'Brake pads, brake discs, and brake systems.', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Engine Parts', 'Pistons, cylinders, spark plugs, etc.', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Helmets', 'Full-face, half-face, and open-face helmets.', 3, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('Lights', 'LED lights, signal lights, and bulbs.', 3, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
