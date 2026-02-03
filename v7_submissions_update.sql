-- Submissions for EMRAD, Posters, and Brochures
CREATE TABLE IF NOT EXISTS tab_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    file_type ENUM('emrad', 'poster', 'brochure') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES tab_teams(id) ON DELETE CASCADE,
    UNIQUE KEY unique_submission (team_id, file_type)
);
