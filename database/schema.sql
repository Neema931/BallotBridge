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

CREATE TABLE elections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    status ENUM('draft', 'open', 'closed') NOT NULL DEFAULT 'draft',
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
    max_votes INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_positions_election_id (election_id),
    CONSTRAINT fk_positions_election
        FOREIGN KEY (election_id) REFERENCES elections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Offices or positions available in each election.';

CREATE TABLE candidates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    manifesto TEXT NULL,
    photo_path VARCHAR(255) NULL,
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
  COMMENT='Candidate profiles and their election and position assignments.';

CREATE TABLE votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    voter_id INT UNSIGNED NOT NULL,
    cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votes_voter_position (voter_id, position_id),
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