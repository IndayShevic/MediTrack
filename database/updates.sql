-- Updates for resident approval system and family members
-- Run this after the main schema.sql

-- Add pending residents table for approval workflow
CREATE TABLE IF NOT EXISTS pending_residents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  date_of_birth DATE NOT NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  barangay_id INT NOT NULL,
  purok_id INT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  bhw_id INT NULL,
  rejection_reason TEXT NULL,
  -- Email verification fields
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verification_code VARCHAR(12) NULL,
  email_verification_expires_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pending_resident_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
  CONSTRAINT fk_pending_resident_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pending_resident_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Add family members to pending residents
CREATE TABLE IF NOT EXISTS pending_family_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pending_resident_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  relationship VARCHAR(100) NOT NULL,
  age INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pending_family_resident FOREIGN KEY (pending_resident_id) REFERENCES pending_residents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Update requests table to include family member and proof
-- Check if columns exist before adding them
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'requests' 
     AND COLUMN_NAME = 'family_member_id') = 0,
    'ALTER TABLE requests ADD COLUMN family_member_id INT NULL AFTER requested_for',
    'SELECT "family_member_id column already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'requests' 
     AND COLUMN_NAME = 'proof_image_path') = 0,
    'ALTER TABLE requests ADD COLUMN proof_image_path VARCHAR(255) NULL AFTER rejection_reason',
    'SELECT "proof_image_path column already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'requests' 
     AND CONSTRAINT_NAME = 'fk_request_family_member') = 0,
    'ALTER TABLE requests ADD CONSTRAINT fk_request_family_member FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE SET NULL',
    'SELECT "fk_request_family_member constraint already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add email notification preferences
CREATE TABLE IF NOT EXISTS email_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  notification_type VARCHAR(50) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  CONSTRAINT fk_email_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Add purok_id column to users table for BHW assignment
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'purok_id') = 0,
    'ALTER TABLE users ADD COLUMN purok_id INT NULL AFTER role',
    'SELECT "purok_id column already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint for purok_id
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND CONSTRAINT_NAME = 'fk_user_purok') = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_user_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE SET NULL',
    'SELECT "fk_user_purok constraint already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for better performance
CREATE INDEX idx_pending_residents_purok ON pending_residents(purok_id);
CREATE INDEX idx_pending_residents_status ON pending_residents(status);
-- Add indexes to speed up verification lookups
CREATE INDEX IF NOT EXISTS idx_pending_residents_email ON pending_residents(email);
CREATE INDEX IF NOT EXISTS idx_pending_residents_verification ON pending_residents(email_verification_code);
CREATE INDEX idx_requests_family_member ON requests(family_member_id);
CREATE INDEX idx_users_purok ON users(purok_id);
