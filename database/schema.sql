-- MediTrack Database Schema (MySQL)
-- Run this file in phpMyAdmin or MySQL client

CREATE DATABASE IF NOT EXISTS meditrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE meditrack;

-- Users and Roles
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','bhw','resident') NOT NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  purok_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Barangay Structure
CREATE TABLE IF NOT EXISTS barangays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS puroks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  barangay_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  UNIQUE KEY uniq_barangay_purok (barangay_id, name),
  CONSTRAINT fk_purok_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Residents and Families
CREATE TABLE IF NOT EXISTS residents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  barangay_id INT NOT NULL,
  purok_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  date_of_birth DATE NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resident_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_resident_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
  CONSTRAINT fk_resident_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS family_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  relationship VARCHAR(100) NOT NULL,
  age INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_family_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Medicines and Batches (FEFO)
CREATE TABLE IF NOT EXISTS medicines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_medicine_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicine_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  medicine_id INT NOT NULL,
  batch_code VARCHAR(100) NOT NULL,
  quantity INT NOT NULL,
  quantity_available INT NOT NULL,
  expiry_date DATE NOT NULL,
  received_at DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_batch_per_medicine (medicine_id, batch_code),
  KEY idx_expiry (expiry_date),
  CONSTRAINT fk_batch_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Requests by Residents
CREATE TABLE IF NOT EXISTS requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  medicine_id INT NOT NULL,
  requested_for ENUM('self','family') NOT NULL DEFAULT 'self',
  patient_name VARCHAR(150) NULL,
  patient_age INT NULL,
  relationship VARCHAR(100) NULL,
  reason TEXT NULL,
  proof_image_path VARCHAR(255) NULL,
  status ENUM('submitted','approved','rejected','ready_to_claim','claimed') NOT NULL DEFAULT 'submitted',
  bhw_id INT NULL,
  rejection_reason TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- FEFO Reservation/Allocation per Request (no long-term reserve; deducted on approval/claim)
CREATE TABLE IF NOT EXISTS request_fulfillments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  batch_id INT NOT NULL,
  quantity INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fulfill_req FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_fulfill_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Senior Allocation Programs
CREATE TABLE IF NOT EXISTS allocation_programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_name VARCHAR(191) NOT NULL,
  medicine_id INT NOT NULL,
  quantity_per_senior INT NOT NULL,
  frequency ENUM('monthly','quarterly') NOT NULL,
  scope_type ENUM('barangay','purok') NOT NULL,
  barangay_id INT NULL,
  purok_id INT NULL,
  claim_window_days INT NOT NULL DEFAULT 14,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prog_med FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
  CONSTRAINT fk_prog_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
  CONSTRAINT fk_prog_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Disburse stock to BHW for seniors (FEFO applied when disbursed)
CREATE TABLE IF NOT EXISTS allocation_disbursals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  bhw_id INT NOT NULL,
  disbursed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  total_quantity INT NOT NULL,
  CONSTRAINT fk_disbursal_program FOREIGN KEY (program_id) REFERENCES allocation_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_disbursal_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS allocation_disbursal_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  disbursal_id INT NOT NULL,
  batch_id INT NOT NULL,
  quantity INT NOT NULL,
  CONSTRAINT fk_disbursal_batches_d FOREIGN KEY (disbursal_id) REFERENCES allocation_disbursals(id) ON DELETE CASCADE,
  CONSTRAINT fk_disbursal_batches_b FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-senior generated allocations
CREATE TABLE IF NOT EXISTS senior_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  resident_id INT NOT NULL,
  bhw_id INT NOT NULL,
  status ENUM('pending','released','expired','returned') NOT NULL DEFAULT 'pending',
  must_claim_before DATE NOT NULL,
  released_at TIMESTAMP NULL,
  returned_at TIMESTAMP NULL,
  CONSTRAINT fk_salloc_program FOREIGN KEY (program_id) REFERENCES allocation_programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_salloc_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE,
  CONSTRAINT fk_salloc_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Emails log (for PHPMailer audit)
CREATE TABLE IF NOT EXISTS email_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(191) NOT NULL,
  subject VARCHAR(191) NOT NULL,
  body TEXT NOT NULL,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('sent','failed') NOT NULL,
  error TEXT NULL
) ENGINE=InnoDB;

-- App settings for branding
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(100) NOT NULL UNIQUE,
  value_text TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (key_name, value_text) VALUES
('brand_name', 'MediTrack'),
('brand_logo_path', NULL)
ON DUPLICATE KEY UPDATE key_name = key_name;

-- Convenience view: residents with computed is_senior flag
DROP VIEW IF EXISTS v_residents_with_senior;
CREATE VIEW v_residents_with_senior AS
SELECT r.*, (TIMESTAMPDIFF(YEAR, r.date_of_birth, CURRENT_DATE) >= 60) AS is_senior
FROM residents r;

-- Seed minimal roles/users (change passwords after import)
-- Initial Super Admin (email: admin@example.com, password: password)
INSERT INTO users (email, password_hash, role, first_name, last_name)
VALUES
('admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'Super', 'Admin')
ON DUPLICATE KEY UPDATE email = email;


