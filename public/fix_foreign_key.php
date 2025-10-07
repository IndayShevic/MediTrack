<?php
require_once '../config/db.php';

echo "<h2>Fixing Foreign Key Constraint</h2>";

try {
    // First, check if the constraint already exists
    $result = db()->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'users' 
        AND CONSTRAINT_NAME = 'fk_user_purok'
    ");
    
    if ($result->rowCount() == 0) {
        // Add the foreign key constraint with proper MariaDB syntax
        $sql = "ALTER TABLE users ADD CONSTRAINT fk_user_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE SET NULL";
        db()->exec($sql);
        echo "✓ Foreign key constraint added successfully<br>";
    } else {
        echo "✓ Foreign key constraint already exists<br>";
    }
    
    // Verify the constraint was created
    $result = db()->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'users' 
        AND CONSTRAINT_NAME = 'fk_user_purok'
    ");
    
    if ($row = $result->fetch()) {
        echo "✓ Verified: " . $row['COLUMN_NAME'] . " -> " . $row['REFERENCED_TABLE_NAME'] . "." . $row['REFERENCED_COLUMN_NAME'] . "<br>";
    }
    
    echo "<br><strong>Foreign key constraint fixed successfully!</strong><br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
