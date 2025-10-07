<?php
require_once '../config/db.php';

try {
    // Check if pending_residents table exists
    $result = db()->query('SHOW TABLES LIKE "pending_residents"');
    echo 'pending_residents table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . '<br>';
    
    // Check if pending_family_members table exists
    $result = db()->query('SHOW TABLES LIKE "pending_family_members"');
    echo 'pending_family_members table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . '<br>';
    
    // Check if email_notifications table exists
    $result = db()->query('SHOW TABLES LIKE "email_notifications"');
    echo 'email_notifications table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . '<br>';
    
    // Check if users table has purok_id column
    $result = db()->query('SHOW COLUMNS FROM users LIKE "purok_id"');
    echo 'users.purok_id column exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . '<br>';
    
    // List all tables
    echo '<br><strong>All tables:</strong><br>';
    $result = db()->query('SHOW TABLES');
    while ($row = $result->fetch()) {
        echo '- ' . $row[0] . '<br>';
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . '<br>';
}
?>
