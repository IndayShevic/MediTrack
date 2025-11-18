-- Migration: Fix password_reset_otps table structure
-- Ensures expires_at is DATETIME and sets proper timezone

-- Set timezone for this session
SET time_zone = '+08:00';

-- Check if table exists, if not create it
CREATE TABLE IF NOT EXISTS password_reset_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_otp_code (otp_code),
    INDEX idx_expires (expires_at),
    INDEX idx_email_otp (email, otp_code)
) ENGINE=InnoDB;

-- If table exists but expires_at is not DATETIME, alter it
-- Note: This will fail gracefully if column is already DATETIME
ALTER TABLE password_reset_otps 
MODIFY COLUMN expires_at DATETIME NOT NULL;

-- If used_at is not DATETIME, alter it
ALTER TABLE password_reset_otps 
MODIFY COLUMN used_at DATETIME NULL;

