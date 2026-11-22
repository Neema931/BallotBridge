-- Adds candidate campaign presentation and application feedback fields.
ALTER TABLE elections
    ADD COLUMN nomination_deadline DATETIME NULL AFTER timezone;

ALTER TABLE candidates
    ADD COLUMN campaign_slogan VARCHAR(200) NULL AFTER manifesto,
    ADD COLUMN rejection_feedback TEXT NULL AFTER affiliation;
