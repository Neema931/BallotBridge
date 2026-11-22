-- Run once after migration_audit_ledger.sql on existing installations.
-- Historical closed elections remain NULL because their close-time voter roster
-- cannot be reconstructed reliably.
ALTER TABLE elections
    ADD COLUMN closed_at DATETIME NULL AFTER status,
    ADD COLUMN eligible_voter_count INT UNSIGNED NULL AFTER closed_at;
