-- Approval Logs Table
-- Tracks when Assigned BHW approves/rejects requests
CREATE TABLE IF NOT EXISTS request_approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  approved_by INT NOT NULL COMMENT 'BHW user ID who approved/rejected',
  approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approval_status ENUM('approved', 'rejected') NOT NULL,
  approval_remarks TEXT NULL COMMENT 'Optional remarks from BHW',
  rejection_reason TEXT NULL COMMENT 'Reason if rejected',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_approval_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_bhw FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_request_id (request_id),
  INDEX idx_approved_by (approved_by),
  INDEX idx_approved_at (approved_at)
) ENGINE=InnoDB;

-- Dispensing Logs Table
-- Tracks when Duty BHW dispenses medicines
CREATE TABLE IF NOT EXISTS request_dispensings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  dispensed_by INT NOT NULL COMMENT 'Duty BHW user ID who dispensed',
  dispensed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  batch_id INT NOT NULL COMMENT 'Medicine batch used (FEFO)',
  quantity_released INT NOT NULL DEFAULT 1,
  dispensing_notes TEXT NULL COMMENT 'Optional notes from BHW',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispensing_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_dispensing_bhw FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dispensing_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE CASCADE,
  INDEX idx_request_id (request_id),
  INDEX idx_dispensed_by (dispensed_by),
  INDEX idx_dispensed_at (dispensed_at),
  INDEX idx_batch_id (batch_id)
) ENGINE=InnoDB;

-- Duty Schedule Table (Optional Add-On)
-- Tracks which BHW is on duty for each date
CREATE TABLE IF NOT EXISTS bhw_duty_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bhw_id INT NOT NULL COMMENT 'BHW user ID',
  duty_date DATE NOT NULL,
  shift_start TIME NULL COMMENT 'Optional: shift start time',
  shift_end TIME NULL COMMENT 'Optional: shift end time',
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL COMMENT 'Super Admin who created the schedule',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_duty_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_duty_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_bhw_date (bhw_id, duty_date),
  INDEX idx_duty_date (duty_date),
  INDEX idx_bhw_id (bhw_id)
) ENGINE=InnoDB;

-- Update requests table to add assigned_bhw_id (for approval) separate from bhw_id
-- Note: bhw_id will now represent the Assigned BHW (for approval)
-- We'll add a new field to track if it's ready for dispensing
ALTER TABLE requests 
ADD COLUMN IF NOT EXISTS assigned_bhw_id INT NULL COMMENT 'BHW assigned to this purok (for approval)' AFTER bhw_id,
ADD COLUMN IF NOT EXISTS is_ready_to_dispense TINYINT(1) DEFAULT 0 COMMENT 'Flag if approved and ready for dispensing' AFTER status;

-- Add foreign key for assigned_bhw_id if column was just added
-- Note: This will fail if column already exists, which is fine
ALTER TABLE requests 
ADD CONSTRAINT fk_request_assigned_bhw FOREIGN KEY (assigned_bhw_id) REFERENCES users(id) ON DELETE SET NULL;

