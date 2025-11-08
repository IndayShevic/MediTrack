-- Create relationships lookup table for data consistency
-- This ensures all relationship values are standardized across the system

CREATE TABLE IF NOT EXISTS relationships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active_order (is_active, display_order)
) ENGINE=InnoDB;

-- Insert standard relationship options
INSERT INTO relationships (name, display_order, is_active) VALUES
('Self', 1, 1),
('Father', 2, 1),
('Mother', 3, 1),
('Son', 4, 1),
('Daughter', 5, 1),
('Brother', 6, 1),
('Sister', 7, 1),
('Husband', 8, 1),
('Wife', 9, 1),
('Spouse', 10, 1),
('Grandfather', 11, 1),
('Grandmother', 12, 1),
('Uncle', 13, 1),
('Aunt', 14, 1),
('Nephew', 15, 1),
('Niece', 16, 1),
('Cousin', 17, 1),
('Other', 99, 1)
ON DUPLICATE KEY UPDATE name = name; -- Ignore if already exists

-- Optional: Add foreign key constraints (if you want to enforce relationships)
-- Note: This requires updating existing data first to match relationship names

-- ALTER TABLE family_members 
-- ADD COLUMN relationship_id INT NULL AFTER relationship,
-- ADD CONSTRAINT fk_family_relationship FOREIGN KEY (relationship_id) REFERENCES relationships(id);

-- ALTER TABLE requests 
-- ADD COLUMN relationship_id INT NULL AFTER relationship,
-- ADD CONSTRAINT fk_request_relationship FOREIGN KEY (relationship_id) REFERENCES relationships(id);

