-- Insert sample announcements with current dates (2025)
-- Run this in phpMyAdmin to populate the announcements table

-- First, make sure we have a user with ID 1 (super_admin)
INSERT IGNORE INTO users (id, name, email, password, role, created_at) 
VALUES (1, 'Super Administrator', 'admin@meditrack.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', NOW());

-- Insert sample announcements
INSERT INTO announcements (title, description, start_date, end_date, created_by, is_active) VALUES
('Medical Mission - Free Check-up', 'Free medical check-up for all residents. Bring valid ID and medical records if available. Services include blood pressure monitoring, blood sugar testing, and general consultation.', '2025-10-20', '2025-10-20', 1, 1),
('Vaccination Drive - COVID-19 Booster', 'COVID-19 booster vaccination drive for eligible residents. Please bring vaccination card and valid ID. Walk-in basis, first come first served.', '2025-10-25', '2025-10-27', 1, 1),
('Community Clean-up Day', 'Join us for a community clean-up activity. All residents are encouraged to participate. Cleaning materials will be provided. Refreshments will be served.', '2025-11-01', '2025-11-01', 1, 1),
('Health Education Seminar', 'Learn about preventive healthcare, nutrition, and healthy lifestyle practices. Open to all residents. Certificate of attendance will be provided.', '2025-11-05', '2025-11-05', 1, 1),
('Senior Citizen Health Program', 'Special health program for senior citizens including free medicines, health monitoring, and social activities. Registration required.', '2025-11-10', '2025-11-12', 1, 1),
('Weekly Health Check-up', 'Regular weekly health monitoring for all residents. Blood pressure, weight, and basic health assessments available.', '2025-10-15', '2025-10-15', 1, 1),
('Medicine Distribution Day', 'Monthly medicine distribution for residents with approved requests. Please bring your ID and request confirmation.', '2025-10-18', '2025-10-18', 1, 1),
('Nutrition Workshop', 'Learn about healthy eating habits and meal planning for families. Free samples and recipe cards will be provided.', '2025-10-22', '2025-10-22', 1, 1);

-- Verify the insert
SELECT COUNT(*) as total_announcements FROM announcements;
SELECT title, start_date, end_date, is_active FROM announcements ORDER BY start_date;
