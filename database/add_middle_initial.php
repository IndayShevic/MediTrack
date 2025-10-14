<?php
require_once '../config/db.php';

try {
    // Add middle_initial column to users table
    $stmt = db()->prepare("ALTER TABLE users ADD COLUMN middle_initial VARCHAR(10) DEFAULT NULL AFTER last_name");
    $stmt->execute();
    echo "Added middle_initial column to users table\n";
    
    // Add middle_initial column to residents table
    $stmt = db()->prepare("ALTER TABLE residents ADD COLUMN middle_initial VARCHAR(10) DEFAULT NULL AFTER last_name");
    $stmt->execute();
    echo "Added middle_initial column to residents table\n";
    
    echo "Database update completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
