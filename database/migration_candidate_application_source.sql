-- Run once to identify self-registered candidate applications in review queues.
ALTER TABLE candidates
    ADD COLUMN application_source ENUM('self_registration', 'admin')
        NOT NULL DEFAULT 'admin' AFTER user_id;

UPDATE candidates c
INNER JOIN users u ON u.id = c.user_id
SET c.application_source = 'self_registration'
WHERE c.status = 'pending' AND u.status = 'pending';
