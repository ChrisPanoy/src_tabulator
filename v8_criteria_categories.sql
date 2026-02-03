-- Add category column to criteria table
ALTER TABLE tab_criteria ADD COLUMN category VARCHAR(50) DEFAULT 'General' AFTER type;

-- Update existing group criteria based on names if possible (best effort)
UPDATE tab_criteria SET category = 'Documentation' WHERE type = 'group' AND (criteria_name LIKE '%Documentation%' OR criteria_name LIKE '%EMRAD%');
UPDATE tab_criteria SET category = 'Poster' WHERE type = 'group' AND (criteria_name LIKE '%Poster%');
UPDATE tab_criteria SET category = 'Brochure' WHERE type = 'group' AND (criteria_name LIKE '%Brochure%');
UPDATE tab_criteria SET category = 'Teaser' WHERE type = 'group' AND (criteria_name LIKE '%Teaser%' OR criteria_name LIKE '%Video%');
UPDATE tab_criteria SET category = 'General' WHERE category IS NULL;
