-- Create categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add category_id column to medicines table
ALTER TABLE medicines ADD COLUMN category_id INT NULL;
ALTER TABLE medicines ADD FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;

-- Insert some default categories
INSERT INTO categories (name, description) VALUES 
('Pain Relief', 'Medicines for pain management and relief'),
('Antibiotics', 'Antibacterial medications'),
('Vitamins', 'Vitamin supplements and nutritional aids'),
('First Aid', 'Basic first aid supplies and medications'),
('Chronic Care', 'Medicines for chronic conditions'),
('Emergency', 'Emergency medications and supplies')
ON DUPLICATE KEY UPDATE name = name;
