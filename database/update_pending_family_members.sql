-- Update pending_family_members table to use separate name fields
-- This script adds new columns and migrates existing data

-- Add new columns to pending_family_members table
ALTER TABLE pending_family_members 
ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '',
ADD COLUMN middle_initial VARCHAR(5) NOT NULL DEFAULT '',
ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT '';

-- Migrate existing full_name data to separate fields
UPDATE pending_family_members 
SET 
    first_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 1), ' ', -1)),
    last_name = TRIM(SUBSTRING_INDEX(full_name, ' ', -1)),
    middle_initial = CASE 
        WHEN LENGTH(full_name) - LENGTH(REPLACE(full_name, ' ', '')) >= 2 
        THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 2), ' ', -1))
        ELSE ''
    END
WHERE full_name IS NOT NULL AND full_name != '';

-- Remove the old full_name column (optional - you can keep it for backup)
-- ALTER TABLE pending_family_members DROP COLUMN full_name;
