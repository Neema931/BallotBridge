-- Run once on installations created before voter review and notification tracking.
ALTER TABLE users
    MODIFY COLUMN status ENUM('pending', 'active', 'suspended', 'rejected')
        NOT NULL DEFAULT 'pending';

ALTER TABLE voters
    ADD COLUMN review_email_status ENUM('not_required', 'pending', 'sent', 'failed')
        NOT NULL DEFAULT 'not_required' AFTER phone;
