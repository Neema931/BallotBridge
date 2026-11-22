-- Run once after migration_election_setup_wizard.sql on existing installations.
ALTER TABLE positions
    ADD COLUMN contest_type ENUM('candidate', 'referendum')
        NOT NULL DEFAULT 'candidate' AFTER title,
    ADD COLUMN randomize_candidates TINYINT(1) NOT NULL DEFAULT 0 AFTER max_votes;

ALTER TABLE candidates
    MODIFY COLUMN user_id INT UNSIGNED NULL,
    ADD COLUMN display_name VARCHAR(150) NULL AFTER user_id,
    ADD COLUMN affiliation VARCHAR(150) NULL AFTER photo_path;
