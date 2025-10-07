# MediTrack - Medicine Management System

A comprehensive web-based medicine management system designed for barangay health workers and residents to efficiently manage medicine requests, allocations, and tracking.

## 🚀 Features

### For Residents
- **User Registration**: Multi-step registration with family member management
- **Medicine Requests**: Submit requests for medicines with proof uploads
- **Request Tracking**: Monitor request status from submission to approval
- **Family Management**: Add and manage family members for medicine requests
- **Profile Management**: Update personal information and contact details

### For Barangay Health Workers (BHW)
- **Resident Management**: Approve/reject resident registrations
- **Request Processing**: Review and approve medicine requests
- **Inventory Management**: Track medicine allocations and availability
- **Resident Dashboard**: View assigned residents and their requests
- **Email Notifications**: Automated notifications for new registrations and requests

### For Super Administrators
- **System Management**: Complete system administration
- **User Management**: Manage all users (residents, BHWs, admins)
- **Medicine Management**: Add, edit, and manage medicine inventory
- **Analytics**: System-wide analytics and reporting
- **Settings**: Configure system settings and branding

## 🛠️ Technology Stack

- **Backend**: PHP 8.0+
- **Database**: MySQL 8.0+
- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **Styling**: Tailwind CSS
- **Email**: PHPMailer with SMTP support
- **Server**: Apache (XAMPP)

## 📋 Requirements

- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache Web Server
- SMTP server for email notifications
- Modern web browser

## 🚀 Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/IndayShevic/MediTrack.git
   cd MediTrack
   ```

2. **Set up XAMPP**
   - Install XAMPP on your system
   - Start Apache and MySQL services
   - Place the project folder in `C:\xampp\htdocs\`

3. **Database Setup**
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Create a new database named `meditrack`
   - Import the database schema:
     ```sql
     -- Run database/schema.sql first
     -- Then run database/updates.sql
     -- Finally run database/update_age_to_birthdate.sql
     ```

4. **Configuration**
   - Update database credentials in `config/db.php`
   - Configure email settings in `config/mail.php`
   - Set up SMTP credentials for email notifications

5. **Access the Application**
   - Open your browser and navigate to `http://localhost/thesis/`
   - The application should be ready to use

## 📁 Project Structure

```
MediTrack/
├── config/                 # Configuration files
│   ├── db.php             # Database configuration
│   ├── mail.php           # Email configuration
│   └── email_notifications.php
├── database/              # Database files
│   ├── schema.sql         # Main database schema
│   ├── updates.sql        # Database updates
│   └── update_age_to_birthdate.sql
├── public/                # Public web files
│   ├── assets/           # CSS, JS, images
│   ├── bhw/              # BHW dashboard and features
│   ├── resident/         # Resident dashboard and features
│   ├── super_admin/      # Admin dashboard and features
│   └── uploads/          # File uploads
├── PHPMailer/            # Email library
├── index.php             # Main landing page
└── README.md             # This file
```

## 🔧 Configuration

### Database Configuration
Edit `config/db.php` to match your database settings:
```php
$DB_HOST = '127.0.0.1';
$DB_NAME = 'meditrack';
$DB_USER = 'your_username';
$DB_PASS = 'your_password';
```

### Email Configuration
Configure SMTP settings in `config/mail.php`:
```php
$mail->Host = 'smtp.gmail.com';
$mail->Username = 'your_email@gmail.com';
$mail->Password = 'your_app_password';
```

## 👥 User Roles

1. **Residents**: Can register, request medicines, and track requests
2. **Barangay Health Workers (BHW)**: Can approve residents and process requests
3. **Super Administrators**: Full system access and management

## 🔐 Security Features

- Password hashing with bcrypt
- SQL injection prevention with prepared statements
- XSS protection with input sanitization
- CSRF protection ready
- File upload validation
- Session management

## 📧 Email Notifications

The system sends automated emails for:
- Registration approval/rejection
- New registration notifications to BHWs
- Request status updates
- System notifications

## 🎨 UI/UX Features

- **Responsive Design**: Works on all devices
- **Modern Interface**: Clean, professional design
- **Multi-step Forms**: Enhanced user experience
- **Real-time Validation**: Immediate feedback
- **Loading States**: Better user feedback
- **Progress Indicators**: Clear step progression

## 🚀 Recent Updates

- ✅ Enhanced registration form with multi-step wizard
- ✅ Added middle initial support
- ✅ Changed age fields to birthdate for better accuracy
- ✅ Improved form validation (client-side and server-side)
- ✅ Modern UI design with Tailwind CSS
- ✅ Enhanced user experience with loading states
- ✅ Better error handling and user feedback

## 📝 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📞 Support

For support and questions, please contact the development team or create an issue in the repository.

## 🔮 Future Enhancements

- [ ] Mobile app development
- [ ] Advanced analytics dashboard
- [ ] SMS notifications
- [ ] Multi-language support
- [ ] API development
- [ ] Automated inventory management
- [ ] Integration with health systems

---

**MediTrack** - Streamlining medicine management for better healthcare delivery.
