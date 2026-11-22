-- Run once on databases created from the original schema.sql.
-- Copies existing voter registration numbers before enforcing global uniqueness.
ALTER TABLE users
    ADD COLUMN reg_number VARCHAR(100) NULL AFTER email;

UPDATE users AS u
INNER JOIN voters AS v ON v.user_id = u.id
SET u.reg_number = v.reg_number;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_reg_number (reg_number);
