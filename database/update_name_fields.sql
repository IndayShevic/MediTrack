-- Update database to use separate name fields instead of full_name
-- This script adds new columns and migrates existing data

-- Add new columns to family_members table
ALTER TABLE family_members 
ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '',
ADD COLUMN middle_initial VARCHAR(5) NOT NULL DEFAULT '',
ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT '';

-- Add new columns to resident_family_additions table
ALTER TABLE resident_family_additions 
ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '',
ADD COLUMN middle_initial VARCHAR(5) NOT NULL DEFAULT '',
ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT '';

-- Migrate existing full_name data to separate fields
-- This function will split full_name into first_name, middle_initial, last_name
UPDATE family_members 
SET 
    first_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 1), ' ', -1)),
    last_name = TRIM(SUBSTRING_INDEX(full_name, ' ', -1)),
    middle_initial = CASE 
        WHEN LENGTH(full_name) - LENGTH(REPLACE(full_name, ' ', '')) >= 2 
        THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 2), ' ', -1))
        ELSE ''
    END
WHERE full_name IS NOT NULL AND full_name != '';

UPDATE resident_family_additions 
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
-- ALTER TABLE family_members DROP COLUMN full_name;
-- ALTER TABLE resident_family_additions DROP COLUMN full_name;
