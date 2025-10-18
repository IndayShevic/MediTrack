# Announcements Feature - MediTrack System

## Overview
The Announcements Feature is a comprehensive module for the MediTrack system that allows Super Admins to create, manage, and display health center activities and announcements to Barangay Health Workers (BHW) and Residents.

## Features

### Super Admin Features
- **Create Announcements**: Add new health center activities with title, description, start/end dates
- **Edit Announcements**: Modify existing announcements
- **Delete Announcements**: Remove announcements from the system
- **Toggle Status**: Activate/deactivate announcements
- **Calendar View**: Visual calendar showing all scheduled announcements
- **List View**: Detailed list of all announcements with management options

### BHW & Resident Features
- **View Active Announcements**: See all current and upcoming health center activities
- **Calendar Integration**: Interactive calendar with clickable events
- **Detailed View**: Modal popup with full announcement details
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Database Schema

### Announcements Table
```sql
CREATE TABLE announcements (
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
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_start_date (start_date),
    INDEX idx_end_date (end_date),
    INDEX idx_created_by (created_by),
    INDEX idx_is_active (is_active),
    INDEX idx_date_range (start_date, end_date)
);
```

## File Structure

```
public/
├── super_admin/
│   └── announcements.php          # Super Admin management interface
├── bhw/
│   └── announcements.php          # BHW view interface
└── resident/
    └── announcements.php          # Resident view interface

database/
├── create_announcements_table.sql # Database schema
└── run_announcements_migration.php # Migration script
```

## Installation

### 1. Database Setup
Run the migration script to create the announcements table:

```bash
php database/run_announcements_migration.php
```

Or manually execute the SQL file:
```sql
-- Execute the contents of database/create_announcements_table.sql
```

### 2. File Permissions
Ensure the web server has read access to all announcement files.

### 3. Navigation Updates
The announcements links have been added to the sidebar navigation for all user roles.

## Usage

### Super Admin
1. Navigate to **Super Admin > Announcements**
2. Click **"New Announcement"** to create a new activity
3. Fill in the required fields:
   - Title (e.g., "Medical Mission - Free Check-up")
   - Description (detailed information about the activity)
   - Start Date (when the activity begins)
   - End Date (when the activity ends)
4. Click **"Create Announcement"** to save
5. Use the calendar view to see all scheduled activities
6. Click on announcement cards to view, edit, or delete

### BHW & Residents
1. Navigate to **Announcements** in the sidebar
2. View the calendar to see upcoming events
3. Click on any announcement card or calendar event to view details
4. The system shows only active announcements that haven't ended

## Technical Details

### Frontend Technologies
- **Tailwind CSS**: Modern, responsive styling
- **FullCalendar.js**: Interactive calendar component
- **Vanilla JavaScript**: Modal functionality and interactions
- **Responsive Design**: Mobile-first approach

### Backend Technologies
- **PHP 8+**: Server-side logic with strict types
- **MySQL**: Database storage with proper indexing
- **PDO**: Secure database interactions with prepared statements

### Security Features
- **Authentication**: Role-based access control
- **Input Validation**: Server-side validation for all inputs
- **SQL Injection Prevention**: Prepared statements
- **XSS Protection**: Proper HTML escaping

## API Endpoints

### Super Admin Actions
- `POST /super_admin/announcements.php` with `action=create`
- `POST /super_admin/announcements.php` with `action=update`
- `POST /super_admin/announcements.php` with `action=delete`
- `POST /super_admin/announcements.php` with `action=toggle_status`

### View Endpoints
- `GET /super_admin/announcements.php` - Super Admin management
- `GET /bhw/announcements.php` - BHW view
- `GET /resident/announcements.php` - Resident view

## Sample Data

The migration includes sample announcements:
- Medical Mission - Free Check-up
- Vaccination Drive - COVID-19 Booster
- Community Clean-up Day
- Health Education Seminar
- Senior Citizen Health Program

## Customization

### Styling
- Modify Tailwind classes in the PHP files
- Update color schemes in the `tailwind.config` sections
- Customize animations and transitions

### Functionality
- Add image upload support by modifying the form and database
- Implement email notifications for new announcements
- Add announcement categories or tags
- Create recurring announcement templates

## Browser Support
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## Performance Considerations
- Database queries are optimized with proper indexing
- Calendar events are loaded efficiently
- Images are optional to reduce storage requirements
- Responsive images for better mobile performance

## Troubleshooting

### Common Issues

1. **Calendar not loading**
   - Check FullCalendar.js CDN connection
   - Verify JavaScript console for errors

2. **Database connection errors**
   - Verify database credentials in `config/db.php`
   - Ensure MySQL server is running

3. **Permission denied errors**
   - Check file permissions (644 for files, 755 for directories)
   - Verify web server user has read access

4. **Announcements not displaying**
   - Check if announcements are marked as active
   - Verify end_date is not in the past
   - Check database for proper data insertion

### Debug Mode
Enable PHP error reporting for debugging:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

## Future Enhancements

### Planned Features
- **Email Notifications**: Automatic email alerts for new announcements
- **SMS Integration**: Text message notifications for urgent announcements
- **Image Upload**: Support for announcement images and attachments
- **Categories**: Organize announcements by type (medical, community, etc.)
- **Recurring Events**: Support for repeating announcements
- **Analytics**: Track announcement views and engagement
- **Multi-language**: Support for multiple languages
- **Push Notifications**: Browser push notifications for new announcements

### Integration Opportunities
- **Calendar Sync**: Export to Google Calendar, Outlook
- **Social Media**: Share announcements on social platforms
- **Mobile App**: Native mobile app integration
- **WhatsApp Integration**: Send announcements via WhatsApp

## Support

For technical support or feature requests, please contact the development team or create an issue in the project repository.

## License

This feature is part of the MediTrack system and follows the same licensing terms as the main project.
