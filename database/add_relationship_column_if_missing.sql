-- Ensure relationship column exists in requests table
-- Run this SQL directly - if the column already exists, you'll get an error which you can ignore

-- Method 1: Simple ALTER TABLE (run this first)
-- If you get "Duplicate column name 'relationship'" error, the column already exists - that's fine!
ALTER TABLE requests 
ADD COLUMN relationship VARCHAR(100) NULL 
AFTER patient_age;

-- If the above gives an error saying the column already exists, that means it's already there!
-- You can verify by running:
-- DESCRIBE requests;
-- or
-- SHOW COLUMNS FROM requests;

