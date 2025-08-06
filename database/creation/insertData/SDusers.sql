INSERT INTO users (
  first_name, last_name, email, password_hash, phone,
  address, city, state, postal_code, country,
  created_at, updated_at, is_active
) VALUES
(
  'John', 'Santos', 'john.santos@example.com', 'hashedpassword123',
  '09171234567', '123 Mabini St.', 'Manila', 'NCR', '1000', 'Philippines',
  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE
),
(
  'Maria', 'Dela Cruz', 'maria.dc@example.com', 'hashedpassword456',
  '09981234567', '456 Katipunan Ave.', 'Quezon City', 'NCR', '1101', 'Philippines',
  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE
),
(
  'Leo', 'Tan', 'leo.tan@example.com', 'hashedpassword789',
  '09221234567', '789 Taft Ave.', 'Pasay', 'NCR', '1300', 'Philippines',
  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE
),
(
  'Anna', 'Lopez', 'anna.lopez@example.com', 'hashedpasswordabc',
  '09081234567', '321 Rizal St.', 'Cebu City', 'Cebu', '6000', 'Philippines',
  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE
),
(
  'Carlos', 'Reyes', 'carlos.reyes@example.com', 'hashedpasswordxyz',
  '09391234567', '654 Magallanes St.', 'Davao City', 'Davao del Sur', '8000', 'Philippines',
  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE
);
