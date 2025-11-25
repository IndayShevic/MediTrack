-- Add profile_image column to family_members and resident_family_additions tables
-- This allows each family member to have their own profile picture
-- Run this SQL script in your database

-- Add profile_image column to family_members table if it doesn't exist
-- Note: If the column already exists, you'll get a "Duplicate column name" error - that's fine, just ignore it
ALTER TABLE family_members 
ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL
AFTER date_of_birth;

-- Add profile_image column to resident_family_additions table (for pending members)
-- Note: If the column already exists, you'll get a "Duplicate column name" error - that's fine, just ignore it
ALTER TABLE resident_family_additions 
ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL
AFTER date_of_birth;

