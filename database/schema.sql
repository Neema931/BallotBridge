SET NAMES utf8mb4;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    reg_number VARCHAR(100) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'candidate', 'voter') NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_reg_number (reg_number),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Accounts and access roles for Ballot Bridge.';

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL,
    actor_name_snapshot VARCHAR(150) NULL,
    actor_role_snapshot VARCHAR(16) NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED NULL,
    summary VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created_at (created_at, id),
    KEY idx_audit_logs_actor (actor_user_id, created_at),
    KEY idx_audit_logs_entity (entity_type, entity_id, created_at),
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Administrative actions and security events for operational auditing.';

CREATE TRIGGER audit_logs_reject_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records are immutable';

CREATE TRIGGER audit_logs_reject_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records are immutable';

CREATE TABLE voters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    reg_number VARCHAR(100) NOT NULL,
    phone VARCHAR(32) NULL,
    review_email_status ENUM('not_required', 'pending', 'sent', 'failed')
        NOT NULL DEFAULT 'not_required',
    PRIMARY KEY (id),
    UNIQUE KEY uq_voters_user_id (user_id),
    UNIQUE KEY uq_voters_reg_number (reg_number),
    CONSTRAINT fk_voters_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Voter registration details and review notification status.';

CREATE TABLE voter_password_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voter_password_tokens_hash (token_hash),
    KEY idx_voter_password_tokens_user_expiry (user_id, expires_at),
    CONSTRAINT fk_voter_password_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hashed, expiring, single-use voter password setup and recovery tokens.';

CREATE TABLE active_voter_sessions (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (session_hash),
    KEY idx_active_voter_sessions_last_seen (last_seen_at),
    KEY idx_active_voter_sessions_user_last_seen (user_id, last_seen_at),
    CONSTRAINT fk_active_voter_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recent voter session activity for live administration metrics.';

CREATE TABLE elections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description MEDIUMTEXT NULL,
    support_email VARCHAR(254) NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    nomination_deadline DATETIME NULL,
    auto_launch TINYINT(1) NOT NULL DEFAULT 0,
    voting_system ENUM('fptp', 'multiple_selection', 'ranked_choice')
        NOT NULL DEFAULT 'fptp',
    verification_methods SET('email_token', 'voter_pin') NOT NULL DEFAULT '',
    allow_abstentions TINYINT(1) NOT NULL DEFAULT 0,
    organization_logo_path VARCHAR(255) NULL,
    status ENUM('draft', 'open', 'paused', 'closed') NOT NULL DEFAULT 'draft',
    closed_at DATETIME NULL,
    eligible_voter_count INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_elections_status_dates (status, start_datetime, end_datetime),
    KEY idx_elections_created_by (created_by),
    CONSTRAINT fk_elections_creator
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Election definitions, schedules, and lifecycle status.';

CREATE TABLE positions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    contest_type ENUM('candidate', 'referendum') NOT NULL DEFAULT 'candidate',
    max_votes INT UNSIGNED NOT NULL DEFAULT 1,
    randomize_candidates TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_positions_election_id (election_id),
    CONSTRAINT fk_positions_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Candidate positions and referendum contests on each election ballot.';

CREATE TABLE candidates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    application_source ENUM('self_registration', 'admin') NOT NULL DEFAULT 'admin',
    display_name VARCHAR(150) NULL,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    manifesto TEXT NULL,
    campaign_slogan VARCHAR(200) NULL,
    photo_path VARCHAR(255) NULL,
    affiliation VARCHAR(150) NULL,
    rejection_feedback TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    KEY idx_candidates_user_id (user_id),
    KEY idx_candidates_election_position_status (election_id, position_id, status),
    KEY idx_candidates_position_id (position_id),
    CONSTRAINT fk_candidates_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_candidates_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_candidates_position
        FOREIGN KEY (position_id) REFERENCES positions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Candidate profiles and referendum answer options assigned to ballot contests.';

CREATE TABLE votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    voter_id INT UNSIGNED NOT NULL,
    rank SMALLINT UNSIGNED NULL,
    cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votes_voter_position_candidate (voter_id, position_id, candidate_id),
    UNIQUE KEY uq_votes_voter_position_rank (voter_id, position_id, rank),
    KEY idx_votes_election_position (election_id, position_id),
    KEY idx_votes_candidate_id (candidate_id),
    KEY idx_votes_position_id (position_id),
    CONSTRAINT fk_votes_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_votes_position
        FOREIGN KEY (position_id) REFERENCES positions (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_votes_candidate
        FOREIGN KEY (candidate_id) REFERENCES candidates (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_votes_voter
        FOREIGN KEY (voter_id) REFERENCES voters (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ballots cast by registered voters; retained while referenced.';

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

-- This is intentionally a placeholder hash. Generate a real hash in PHP with
-- password_hash('your-password', PASSWORD_DEFAULT) and replace it before use.
INSERT INTO users (full_name, email, password_hash, role, status)
VALUES (
    'Ballot Bridge Admin',
    'admin@ballotbridge.local',
    '<GENERATE_WITH_PASSWORD_HASH>',
    'admin',
    'active'
);