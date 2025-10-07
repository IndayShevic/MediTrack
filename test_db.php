<?php
require_once 'config/db.php';

try {
    // Check if pending_residents table exists
    $result = db()->query('SHOW TABLES LIKE "pending_residents"');
    echo 'pending_residents table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . PHP_EOL;
    
    // Check if pending_family_members table exists
    $result = db()->query('SHOW TABLES LIKE "pending_family_members"');
    echo 'pending_family_members table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . PHP_EOL;
    
    // Check if email_notifications table exists
    $result = db()->query('SHOW TABLES LIKE "email_notifications"');
    echo 'email_notifications table exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . PHP_EOL;
    
    // Check if users table has purok_id column
    $result = db()->query('SHOW COLUMNS FROM users LIKE "purok_id"');
    echo 'users.purok_id column exists: ' . ($result->rowCount() > 0 ? 'YES' : 'NO') . PHP_EOL;
    
    // List all tables
    echo PHP_EOL . 'All tables:' . PHP_EOL;
    $result = db()->query('SHOW TABLES');
    while ($row = $result->fetch()) {
        echo '- ' . $row[0] . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>
