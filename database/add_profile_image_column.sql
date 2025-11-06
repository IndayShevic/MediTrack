-- Add profile_image column to users table if it doesn't exist
ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER purok_id;

