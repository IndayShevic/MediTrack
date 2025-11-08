-- Add time columns to announcements table
-- This allows announcements to have specific start and end times

-- Add start_time column (TIME type, NULL means all day)
ALTER TABLE announcements
ADD COLUMN start_time TIME NULL
AFTER start_date;

-- Add end_time column (TIME type, NULL means all day)
ALTER TABLE announcements
ADD COLUMN end_time TIME NULL
AFTER end_date;

-- Add index for better query performance
ALTER TABLE announcements
ADD INDEX idx_start_datetime (start_date, start_time),
ADD INDEX idx_end_datetime (end_date, end_time);

-- Note: If columns already exist, you'll get "Duplicate column name" errors - that's fine!

