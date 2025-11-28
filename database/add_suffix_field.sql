-- Add suffix field to all name-related tables
-- This allows storing name suffixes like Jr., Sr., II, III, etc.

-- Add suffix to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add suffix to residents table
ALTER TABLE residents 
ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add suffix to family_members table (if using separate name fields)
ALTER TABLE family_members 
ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add suffix to pending_residents table
ALTER TABLE pending_residents 
ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add suffix to pending_family_members table (if using separate name fields)
ALTER TABLE pending_family_members 
ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add suffix to resident_family_additions table (if exists)
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables 
    WHERE table_schema = DATABASE() AND table_name = 'resident_family_additions');
SET @sql = IF(@table_exists > 0,
    'ALTER TABLE resident_family_additions ADD COLUMN IF NOT EXISTS suffix VARCHAR(10) DEFAULT NULL AFTER last_name;',
    'SELECT "Table resident_family_additions does not exist, skipping..." AS message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

