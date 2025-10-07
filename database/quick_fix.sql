-- Quick fix for existing database
-- Run this if you get duplicate column errors

-- Add family_member_id column if it doesn't exist
ALTER TABLE requests ADD COLUMN family_member_id INT NULL AFTER requested_for;

-- Add foreign key constraint for family_member_id
ALTER TABLE requests ADD CONSTRAINT fk_request_family_member FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE SET NULL;

-- Add indexes for better performance
CREATE INDEX idx_requests_family_member ON requests(family_member_id);
