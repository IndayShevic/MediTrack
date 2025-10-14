-- Add middle_initial column to users table
ALTER TABLE users ADD COLUMN middle_initial VARCHAR(10) DEFAULT NULL AFTER last_name;

-- Add middle_initial column to residents table  
ALTER TABLE residents ADD COLUMN middle_initial VARCHAR(10) DEFAULT NULL AFTER last_name;
