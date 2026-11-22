-- Run once on installations created before dashboard session tracking and pause support.
ALTER TABLE elections
    MODIFY COLUMN status ENUM('draft', 'open', 'paused', 'closed')
        NOT NULL DEFAULT 'draft';

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
