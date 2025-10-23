-- Add email verification columns to pending_residents table
-- Run this in phpMyAdmin

ALTER TABLE `pending_residents` 
ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rejection_reason`,
ADD COLUMN `email_verification_code` VARCHAR(12) NULL AFTER `email_verified`,
ADD COLUMN `email_verification_expires_at` DATETIME NULL AFTER `email_verification_code`;

-- Add indexes to speed up verification lookups
CREATE INDEX IF NOT EXISTS idx_pending_residents_email ON pending_residents(email);
CREATE INDEX IF NOT EXISTS idx_pending_residents_verification ON pending_residents(email_verification_code);

