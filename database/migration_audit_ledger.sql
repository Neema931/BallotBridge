-- Run once after migration_election_settings.sql on existing installations.
ALTER TABLE audit_logs
    ADD COLUMN actor_name_snapshot VARCHAR(150) NULL AFTER actor_user_id,
    ADD COLUMN actor_role_snapshot VARCHAR(16) NULL AFTER actor_name_snapshot,
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER summary,
    ADD COLUMN before_json JSON NULL AFTER ip_address,
    ADD COLUMN after_json JSON NULL AFTER before_json;

UPDATE audit_logs a
INNER JOIN users u ON u.id = a.actor_user_id
SET a.actor_name_snapshot = u.full_name,
    a.actor_role_snapshot = u.role
WHERE a.actor_name_snapshot IS NULL
  AND a.actor_user_id IS NOT NULL;

CREATE TRIGGER audit_logs_reject_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records are immutable';

CREATE TRIGGER audit_logs_reject_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records are immutable';
