<?php
require_once '../config/db.php';

echo "<h2>Creating Missing Tables</h2>";

try {
    // Create pending_residents table
    $sql = "CREATE TABLE IF NOT EXISTS pending_residents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        date_of_birth DATE NOT NULL,
        phone VARCHAR(50) NULL,
        address VARCHAR(255) NULL,
        barangay_id INT NOT NULL,
        purok_id INT NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        bhw_id INT NULL,
        rejection_reason TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pending_resident_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
        CONSTRAINT fk_pending_resident_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE CASCADE,
        CONSTRAINT fk_pending_resident_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB";
    
    db()->exec($sql);
    echo "✓ pending_residents table created/verified<br>";
    
    // Create pending_family_members table
    $sql = "CREATE TABLE IF NOT EXISTS pending_family_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pending_resident_id INT NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        relationship VARCHAR(100) NOT NULL,
        age INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_pending_family_resident FOREIGN KEY (pending_resident_id) REFERENCES pending_residents(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    db()->exec($sql);
    echo "✓ pending_family_members table created/verified<br>";
    
    // Create email_notifications table
    $sql = "CREATE TABLE IF NOT EXISTS email_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        notification_type VARCHAR(50) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
        CONSTRAINT fk_email_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    db()->exec($sql);
    echo "✓ email_notifications table created/verified<br>";
    
    // Add purok_id column to users table if it doesn't exist
    $sql = "ALTER TABLE users ADD COLUMN IF NOT EXISTS purok_id INT NULL AFTER role";
    try {
        db()->exec($sql);
        echo "✓ users.purok_id column added/verified<br>";
    } catch (Exception $e) {
        echo "⚠ users.purok_id column may already exist: " . $e->getMessage() . "<br>";
    }
    
    // Add foreign key constraint for purok_id
    $sql = "ALTER TABLE users ADD CONSTRAINT IF NOT EXISTS fk_user_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE SET NULL";
    try {
        db()->exec($sql);
        echo "✓ users.purok_id foreign key constraint added/verified<br>";
    } catch (Exception $e) {
        echo "⚠ users.purok_id foreign key constraint may already exist: " . $e->getMessage() . "<br>";
    }
    
    // Add indexes
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_pending_residents_purok ON pending_residents(purok_id)",
        "CREATE INDEX IF NOT EXISTS idx_pending_residents_status ON pending_residents(status)",
        "CREATE INDEX IF NOT EXISTS idx_pending_family_resident ON pending_family_members(pending_resident_id)",
        "CREATE INDEX IF NOT EXISTS idx_email_notifications_user ON email_notifications(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_users_purok ON users(purok_id)"
    ];
    
    foreach ($indexes as $index) {
        try {
            db()->exec($index);
        } catch (Exception $e) {
            // Index may already exist, continue
        }
    }
    echo "✓ Indexes created/verified<br>";
    
    echo "<br><strong>All tables and columns have been created successfully!</strong><br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
