-- Run once on installations created before election setup and ranked-ballot support.
ALTER TABLE elections
    MODIFY COLUMN description MEDIUMTEXT NULL,
    ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER end_datetime,
    ADD COLUMN auto_launch TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone,
    ADD COLUMN voting_system ENUM('fptp', 'multiple_selection', 'ranked_choice')
        NOT NULL DEFAULT 'fptp' AFTER auto_launch,
    ADD COLUMN verification_methods SET('email_token', 'voter_pin')
        NOT NULL DEFAULT '' AFTER voting_system,
    ADD COLUMN allow_abstentions TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_methods,
    ADD COLUMN organization_logo_path VARCHAR(255) NULL AFTER allow_abstentions;

ALTER TABLE votes
    DROP INDEX uq_votes_voter_position,
    ADD COLUMN rank SMALLINT UNSIGNED NULL AFTER voter_id,
    ADD UNIQUE KEY uq_votes_voter_position_candidate (voter_id, position_id, candidate_id),
    ADD UNIQUE KEY uq_votes_voter_position_rank (voter_id, position_id, rank);

CREATE TABLE ballot_submissions (
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    voter_id INT UNSIGNED NOT NULL,
    abstained TINYINT(1) NOT NULL DEFAULT 0,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (election_id, position_id, voter_id),
    KEY idx_ballot_submissions_election_submitted (election_id, submitted_at),
    KEY idx_ballot_submissions_voter (voter_id, election_id),
    CONSTRAINT fk_ballot_submissions_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_ballot_submissions_position
        FOREIGN KEY (position_id) REFERENCES positions (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_ballot_submissions_voter
        FOREIGN KEY (voter_id) REFERENCES voters (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Completed per-position ballot submissions, including abstentions.';

INSERT INTO ballot_submissions (election_id, position_id, voter_id, abstained, submitted_at)
SELECT election_id, position_id, voter_id, 0, MIN(cast_at)
FROM votes
GROUP BY election_id, position_id, voter_id;

CREATE TABLE election_voter_verifications (
    election_id INT UNSIGNED NOT NULL,
    voter_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NULL,
    expires_at DATETIME NULL,
    verified_at DATETIME NULL,
    last_sent_at DATETIME NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (election_id, voter_id),
    KEY idx_election_voter_verifications_expiry (expires_at),
    CONSTRAINT fk_election_voter_verifications_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_election_voter_verifications_voter
        FOREIGN KEY (voter_id) REFERENCES voters (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Email-token verification state for a voter and election.';
