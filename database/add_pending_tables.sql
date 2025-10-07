-- Add pending residents tables to existing database
-- Run this script to add the missing tables

USE meditrack;

-- Pending Resident Registrations (awaiting BHW approval)
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pending_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
  CONSTRAINT fk_pending_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE CASCADE,
  CONSTRAINT fk_pending_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pending_family_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pending_resident_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  relationship VARCHAR(100) NOT NULL,
  age INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pending_family_resident FOREIGN KEY (pending_resident_id) REFERENCES pending_residents(id) ON DELETE CASCADE
) ENGINE=InnoDB;
