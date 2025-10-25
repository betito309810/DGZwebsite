ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS current_session_token VARCHAR(128) NULL AFTER password_hash;
