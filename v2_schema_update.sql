-- Academic Structure
CREATE TABLE IF NOT EXISTS tab_school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(50) NOT NULL, -- e.g., "2025-2026"
    status ENUM('active', 'inactive') DEFAULT 'active'
);

CREATE TABLE IF NOT EXISTS tab_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL, -- e.g. "BSIT"
    course_name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS tab_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(50) NOT NULL, -- e.g. "4-A"
    course_id INT NOT NULL,
    FOREIGN KEY (course_id) REFERENCES tab_courses(id) ON DELETE CASCADE
);

-- Events
CREATE TABLE IF NOT EXISTS tab_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    event_date DATETIME NOT NULL,
    venue VARCHAR(200),
    school_year_id INT,
    status ENUM('upcoming', 'ongoing', 'completed') DEFAULT 'upcoming',
    is_results_released BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (school_year_id) REFERENCES tab_school_years(id) ON DELETE SET NULL
);

-- Update tab_teams
ALTER TABLE tab_teams ADD COLUMN event_id INT;
ALTER TABLE tab_teams ADD COLUMN section_id INT;
ALTER TABLE tab_teams ADD CONSTRAINT fk_team_event FOREIGN KEY (event_id) REFERENCES tab_events(id) ON DELETE SET NULL;
ALTER TABLE tab_teams ADD CONSTRAINT fk_team_section FOREIGN KEY (section_id) REFERENCES tab_sections(id) ON DELETE SET NULL;

-- Update tab_criteria
ALTER TABLE tab_criteria ADD COLUMN event_id INT;
ALTER TABLE tab_criteria ADD COLUMN type ENUM('group', 'individual') DEFAULT 'group';
-- If we want generic criteria that apply to all, event_id can be NULL. For now, let's assume criteria are specific or we copy them.

-- Panelist Assignments
CREATE TABLE IF NOT EXISTS tab_panelist_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT,
    team_id INT,
    panelist_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES tab_events(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES tab_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (panelist_id) REFERENCES tab_users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (team_id, panelist_id)
);

-- Individual Scores
CREATE TABLE IF NOT EXISTS tab_individual_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    panelist_id INT NOT NULL,
    criteria_id INT NOT NULL,
    score DECIMAL(5, 2) NOT NULL,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES tab_users(id) ON DELETE CASCADE,
    FOREIGN KEY (panelist_id) REFERENCES tab_users(id) ON DELETE CASCADE,
    FOREIGN KEY (criteria_id) REFERENCES tab_criteria(id) ON DELETE CASCADE,
    UNIQUE KEY unique_ind_score (student_id, panelist_id, criteria_id)
);

-- Seed Basic Academic Data
INSERT INTO tab_school_years (year_label, status) VALUES ('2025-2026', 'active');
INSERT INTO tab_courses (course_code, course_name) VALUES ('BSIT', 'Bachelor of Science in Information Technology');
INSERT INTO tab_sections (section_name, course_id) VALUES ('4-A', 1), ('4-B', 1);

-- Seed Event
INSERT INTO tab_events (title, event_date, venue, school_year_id, status) VALUES 
('Capstone Defense 2026', '2026-05-20 08:00:00', 'IT AVR', 1, 'ongoing');

-- Update Existing Teams (from prev seed) to link to this event
UPDATE tab_teams SET event_id = 1, section_id = 1 WHERE id IN (1, 2);

-- Update Existing Criteria to link to this event (and set as group)
UPDATE tab_criteria SET event_id = 1, type = 'group';

-- Insert Individual Criteria
INSERT INTO tab_criteria (criteria_name, description, weight, display_order, event_id, type) VALUES
('Individual Presentation', 'Clarity, confidence, and Q&A handling', 100.00, 10, 1, 'individual');

-- Assign Panelists to Teams
-- Assign panel1, panel2, panel3 to Team 1 and Team 2
INSERT INTO tab_panelist_assignments (event_id, team_id, panelist_id) VALUES
(1, 1, 2), (1, 1, 3), (1, 1, 4),
(1, 2, 2), (1, 2, 3), (1, 2, 4);
