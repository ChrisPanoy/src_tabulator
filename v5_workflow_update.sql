-- Lock Status for Scoring
-- We track if a panelist has "Finalized" their score for a specific team.
CREATE TABLE IF NOT EXISTS tab_score_locks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    team_id INT NOT NULL,
    panelist_id INT NOT NULL,
    is_locked BOOLEAN DEFAULT FALSE,
    locked_at TIMESTAMP NULL,
    FOREIGN KEY (event_id) REFERENCES tab_events(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES tab_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (panelist_id) REFERENCES tab_users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_lock (team_id, panelist_id)
);
