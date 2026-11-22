-- Run once after migration_candidate_ballot_builder.sql on existing installations.
ALTER TABLE elections
    ADD COLUMN support_email VARCHAR(254) NULL AFTER description;
