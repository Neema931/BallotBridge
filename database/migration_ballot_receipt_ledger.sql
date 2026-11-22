CREATE TABLE ballot_receipts (
    receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    election_id INT UNSIGNED NOT NULL,
    position_count INT UNSIGNED NOT NULL,
    cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (receipt_hash),
    UNIQUE KEY uq_ballot_receipts_election_hash (election_id, receipt_hash),
    KEY idx_ballot_receipts_election_cast_at (election_id, cast_at),
    CONSTRAINT fk_ballot_receipts_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Opaque participation receipt hashes and issue times; no voter identity or selections.';

ALTER TABLE ballot_submissions
    ADD COLUMN receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER submitted_at,
    ADD KEY idx_ballot_submissions_receipt (election_id, receipt_hash),
    ADD CONSTRAINT fk_ballot_submissions_receipt
        FOREIGN KEY (election_id, receipt_hash)
        REFERENCES ballot_receipts (election_id, receipt_hash)
        ON DELETE RESTRICT;
