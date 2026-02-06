-- v9: Remove column length limits for criteria names and categories
-- This fixes "Data too long for column" errors by using TEXT instead of VARCHAR
-- TEXT can store up to 65,535 characters (no practical limit for criteria names)

-- Change criteria_name to TEXT to allow unlimited length
ALTER TABLE tab_criteria MODIFY criteria_name TEXT NOT NULL;

-- Change category to TEXT to allow unlimited length
ALTER TABLE tab_criteria MODIFY category TEXT;

-- Change category_name to TEXT to allow unlimited length
ALTER TABLE tab_rubric_categories MODIFY category_name TEXT NOT NULL;
