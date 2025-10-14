-- Create announcements table for MediTrack system
-- This table stores health center activities and announcements

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    image_path VARCHAR(500) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Foreign key constraint
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Indexes for better performance
    INDEX idx_start_date (start_date),
    INDEX idx_end_date (end_date),
    INDEX idx_created_by (created_by),
    INDEX idx_is_active (is_active),
    INDEX idx_date_range (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample announcements for testing
INSERT INTO announcements (title, description, start_date, end_date, created_by) VALUES
('Medical Mission - Free Check-up', 'Free medical check-up for all residents. Bring valid ID and medical records if available. Services include blood pressure monitoring, blood sugar testing, and general consultation.', '2024-02-15', '2024-02-15', 1),
('Vaccination Drive - COVID-19 Booster', 'COVID-19 booster vaccination drive for eligible residents. Please bring vaccination card and valid ID. Walk-in basis, first come first served.', '2024-02-20', '2024-02-22', 1),
('Community Clean-up Day', 'Join us for a community clean-up activity. All residents are encouraged to participate. Cleaning materials will be provided. Refreshments will be served.', '2024-02-25', '2024-02-25', 1),
('Health Education Seminar', 'Learn about preventive healthcare, nutrition, and healthy lifestyle practices. Open to all residents. Certificate of attendance will be provided.', '2024-03-01', '2024-03-01', 1),
('Senior Citizen Health Program', 'Special health program for senior citizens including free medicines, health monitoring, and social activities. Registration required.', '2024-03-05', '2024-03-07', 1);
