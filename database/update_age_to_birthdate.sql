-- Update family members tables to use birthdate instead of age
-- Run this after the main schema.sql and updates.sql

-- Update family_members table
ALTER TABLE family_members 
CHANGE COLUMN age date_of_birth DATE NOT NULL;

-- Update pending_family_members table  
ALTER TABLE pending_family_members 
CHANGE COLUMN age date_of_birth DATE NOT NULL;

-- Update requests table to use patient_date_of_birth instead of patient_age
ALTER TABLE requests 
CHANGE COLUMN patient_age patient_date_of_birth DATE NULL;

-- Add comment to document the change
ALTER TABLE family_members 
MODIFY COLUMN date_of_birth DATE NOT NULL COMMENT 'Date of birth instead of age for better data accuracy';

ALTER TABLE pending_family_members 
MODIFY COLUMN date_of_birth DATE NOT NULL COMMENT 'Date of birth instead of age for better data accuracy';

ALTER TABLE requests 
MODIFY COLUMN patient_date_of_birth DATE NULL COMMENT 'Date of birth instead of age for better data accuracy';
