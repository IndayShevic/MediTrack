<?php
/**
 * Test script for announcement email notifications
 */

require_once 'config/db.php';
require_once 'config/email_notifications.php';

echo "<h1>Announcement Email Test</h1>";

try {
    // Test database connection
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check if users exist
    $users = $pdo->query("SELECT COUNT(*) as count FROM users WHERE email IS NOT NULL AND email != '' AND role IN ('bhw', 'resident')")->fetch();
    echo "<p style='color: blue;'>📊 Found {$users['count']} users with email addresses</p>";
    
    if ($users['count'] > 0) {
        // Show users who will receive emails
        $userList = $pdo->query("SELECT name, email, role FROM users WHERE email IS NOT NULL AND email != '' AND role IN ('bhw', 'resident') LIMIT 10")->fetchAll();
        echo "<h3>Users who will receive announcement emails:</h3>";
        echo "<ul>";
        foreach ($userList as $user) {
            echo "<li><strong>" . htmlspecialchars($user['name']) . "</strong> (" . htmlspecialchars($user['email']) . ") - " . htmlspecialchars($user['role']) . "</li>";
        }
        echo "</ul>";
        
        // Test email function
        echo "<h3>Testing Email Function:</h3>";
        $testResults = send_announcement_notification_to_all_users(
            'Test Announcement - Medical Mission',
            'This is a test announcement to verify email functionality. Please ignore this message.',
            date('Y-m-d', strtotime('+1 day')),
            date('Y-m-d', strtotime('+1 day'))
        );
        
        echo "<div style='background: #f0f9ff; border: 1px solid #0ea5e9; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
        echo "<h4>Email Test Results:</h4>";
        echo "<p><strong>Emails Sent:</strong> {$testResults['sent']}</p>";
        echo "<p><strong>Emails Failed:</strong> {$testResults['failed']}</p>";
        
        if (!empty($testResults['errors'])) {
            echo "<p><strong>Errors:</strong></p>";
            echo "<ul>";
            foreach ($testResults['errors'] as $error) {
                echo "<li style='color: red;'>" . htmlspecialchars($error) . "</li>";
            }
            echo "</ul>";
        }
        echo "</div>";
        
        if ($testResults['sent'] > 0) {
            echo "<p style='color: green;'>✓ Email test completed successfully!</p>";
        } else {
            echo "<p style='color: orange;'>⚠ No emails were sent. Check email configuration.</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ No users found with email addresses</p>";
        echo "<p>Please add email addresses to user accounts to test email notifications.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<h3>Email Configuration:</h3>";
echo "<ul>";
echo "<li><strong>SMTP Host:</strong> " . (getenv('SMTP_HOST') ?: 'smtp.gmail.com') . "</li>";
echo "<li><strong>SMTP User:</strong> " . (getenv('SMTP_USER') ?: 's2peed5@gmail.com') . "</li>";
echo "<li><strong>SMTP Port:</strong> " . (getenv('SMTP_PORT') ?: '587') . "</li>";
echo "</ul>";

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>If emails were sent successfully, check the recipients' inboxes</li>";
echo "<li>If no emails were sent, check the SMTP configuration in config/mail.php</li>";
echo "<li>Create a new announcement in the Super Admin panel to test the full workflow</li>";
echo "<li>Check the email logs in the Super Admin panel for delivery status</li>";
echo "</ol>";

echo "<p><a href='public/super_admin/announcements.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Announcements Management</a></p>";
?>
