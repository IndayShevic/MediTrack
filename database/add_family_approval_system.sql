-- Family Member Approval System
-- Allows residents to add family members that require BHW approval

-- Drop and recreate with proper structure
DROP TABLE IF EXISTS family_addition_notifications;
DROP TABLE IF EXISTS resident_family_additions;

-- Table for resident-initiated family member additions (different from registration pending)
CREATE TABLE IF NOT EXISTS resident_family_additions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    relationship VARCHAR(100) NOT NULL,
    date_of_birth DATE NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    bhw_id INT NULL COMMENT 'BHW who approved/rejected',
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    CONSTRAINT fk_res_family_add_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE,
    CONSTRAINT fk_res_family_add_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_resident_status (resident_id, status),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB COMMENT='Resident-initiated family member additions pending BHW approval';

-- Add notification tracking
CREATE TABLE IF NOT EXISTS family_addition_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_addition_id INT NOT NULL,
    recipient_type ENUM('resident', 'bhw') NOT NULL,
    recipient_id INT NOT NULL,
    notification_type ENUM('pending', 'approved', 'rejected') NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_family_add FOREIGN KEY (family_addition_id) REFERENCES resident_family_additions(id) ON DELETE CASCADE,
    INDEX idx_recipient (recipient_type, recipient_id, is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB COMMENT='Notifications for family member approvals';
