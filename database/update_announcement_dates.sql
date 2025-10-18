-- Update sample announcements with current dates (2025)
-- This will make the announcements visible to residents and BHW

UPDATE announcements SET 
    start_date = '2025-10-20',
    end_date = '2025-10-20'
WHERE title = 'Medical Mission - Free Check-up';

UPDATE announcements SET 
    start_date = '2025-10-25',
    end_date = '2025-10-27'
WHERE title = 'Vaccination Drive - COVID-19 Booster';

UPDATE announcements SET 
    start_date = '2025-11-01',
    end_date = '2025-11-01'
WHERE title = 'Community Clean-up Day';

UPDATE announcements SET 
    start_date = '2025-11-05',
    end_date = '2025-11-05'
WHERE title = 'Health Education Seminar';

UPDATE announcements SET 
    start_date = '2025-11-10',
    end_date = '2025-11-12'
WHERE title = 'Senior Citizen Health Program';

-- Also add some announcements for the current week
INSERT INTO announcements (title, description, start_date, end_date, created_by) VALUES
('Weekly Health Check-up', 'Regular weekly health monitoring for all residents. Blood pressure, weight, and basic health assessments available.', '2025-10-15', '2025-10-15', 1),
('Medicine Distribution Day', 'Monthly medicine distribution for residents with approved requests. Please bring your ID and request confirmation.', '2025-10-18', '2025-10-18', 1),
('Nutrition Workshop', 'Learn about healthy eating habits and meal planning for families. Free samples and recipe cards will be provided.', '2025-10-22', '2025-10-22', 1);
