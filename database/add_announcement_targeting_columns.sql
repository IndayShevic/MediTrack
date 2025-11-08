-- Add targeting columns to announcements table
-- This allows announcements to be filtered by audience (all, residents, bhw) and location (barangay, purok)
--
-- NOTE: If columns already exist, you'll get "Duplicate column name" errors - that's fine, just ignore them.
-- To check if columns exist, run: DESCRIBE announcements;

-- Add target_audience column (all, residents, bhw)
-- If you get "Duplicate column name 'target_audience'" error, the column already exists - that's fine!
ALTER TABLE announcements
ADD COLUMN target_audience ENUM('all', 'residents', 'bhw') NOT NULL DEFAULT 'all'
AFTER is_active;

-- Add target_barangay_id column (NULL means all barangays)
-- If you get "Duplicate column name 'target_barangay_id'" error, the column already exists - that's fine!
ALTER TABLE announcements
ADD COLUMN target_barangay_id INT NULL
AFTER target_audience;

-- Add target_purok_id column (NULL means all puroks, requires target_barangay_id to be set)
-- If you get "Duplicate column name 'target_purok_id'" error, the column already exists - that's fine!
ALTER TABLE announcements
ADD COLUMN target_purok_id INT NULL
AFTER target_barangay_id;

-- Add foreign key constraints (only if they don't already exist)
-- If you get "Duplicate foreign key" errors, the constraints already exist - that's fine!
ALTER TABLE announcements
ADD CONSTRAINT fk_announcement_barangay FOREIGN KEY (target_barangay_id) REFERENCES barangays(id) ON DELETE SET NULL;

ALTER TABLE announcements
ADD CONSTRAINT fk_announcement_purok FOREIGN KEY (target_purok_id) REFERENCES puroks(id) ON DELETE SET NULL;

-- Add indexes for better query performance (only if they don't already exist)
-- If you get "Duplicate key name" errors, the indexes already exist - that's fine!
ALTER TABLE announcements
ADD INDEX idx_target_audience (target_audience);

ALTER TABLE announcements
ADD INDEX idx_target_barangay (target_barangay_id);

ALTER TABLE announcements
ADD INDEX idx_target_purok (target_purok_id);

