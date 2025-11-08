-- Add profile_image column to family_members table
-- This allows each family member to have their own profile picture

-- Add profile_image column if it doesn't exist
ALTER TABLE family_members 
ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL
AFTER date_of_birth;

-- Add profile_image column to resident_family_additions table (for pending members)
ALTER TABLE resident_family_additions 
ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL
AFTER date_of_birth;

-- Note: If columns already exist, you'll get "Duplicate column name" errors - that's fine!

