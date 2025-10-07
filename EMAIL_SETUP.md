# Email Setup Guide for MediTrack

## Current Issue
The application shows "Medicine saved, but email sending failed. Check SMTP settings."

## Quick Fix Options

### Option 1: Use Gmail SMTP (Recommended)
1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate App Password**:
   - Go to Google Account Settings
   - Security → 2-Step Verification → App passwords
   - Generate password for "Mail"
3. **Update config/mail.php**:
   ```php
   $mail->Username = 'your-email@gmail.com';
   $mail->Password = 'your-16-character-app-password';
   ```

### Option 2: Use Environment Variables
Create a `.env` file in your project root:
```
SMTP_HOST=smtp.gmail.com
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_PORT=587
```

### Option 3: Disable Email Temporarily
Comment out the email sending code in the medicine creation process.

## Testing Email
1. Go to Super Admin → Email Logs
2. Check the error messages
3. Verify SMTP credentials are correct

## Common Issues
- **"Could not connect to SMTP host"**: Check internet connection and SMTP settings
- **"Authentication failed"**: Verify email and app password
- **"SSL/TLS error"**: The code already includes SSL options for local development

## Alternative SMTP Services
- **Mailtrap** (for testing): Free tier available
- **SendGrid**: Professional email service
- **Amazon SES**: Scalable email service

The current configuration should work with Gmail once you set up the app password correctly.
