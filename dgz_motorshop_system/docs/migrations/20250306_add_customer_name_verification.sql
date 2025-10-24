-- Migration: add structured customer names and email verification support

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(120) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(120) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(120) NULL AFTER middle_name,
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER postal_code,
    ADD COLUMN IF NOT EXISTS verification_token VARCHAR(120) NULL AFTER email_verified_at;

UPDATE customers
SET
    first_name = CASE
        WHEN first_name IS NULL OR first_name = ''
            THEN TRIM(SUBSTRING_INDEX(full_name, ' ', 1))
        ELSE first_name
    END,
    last_name = CASE
        WHEN last_name IS NULL OR last_name = ''
            THEN TRIM(SUBSTRING_INDEX(full_name, ' ', -1))
        ELSE last_name
    END
WHERE (first_name IS NULL OR first_name = '')
   OR (last_name IS NULL OR last_name = '');
