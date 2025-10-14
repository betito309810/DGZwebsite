-- 2024-06-07: Add current_session_token column to enforce single active admin sessions.
--
-- This migration introduces a dedicated column on the `users` table for storing the
-- latest server-issued session token. The login flow updates this value whenever a
-- user signs in, allowing concurrent sessions to be detected and invalidated.
--
-- Usage:
--   1. Review and adjust the table name if your installation stores admin accounts
--      elsewhere.
--   2. Execute this script once against the application's database before deploying
--      the PHP changes that depend on the column.
--   3. Existing sessions without a token will be forced to re-authenticate on their
--      next request, ensuring consistent single-session enforcement.

ALTER TABLE users
    ADD COLUMN current_session_token CHAR(64) NULL
        AFTER password;
