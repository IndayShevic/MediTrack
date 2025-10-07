<?php
require_once '../config/db.php';

echo "<h2>Registration Debug</h2>";

// Check if tables exist
echo "<h3>Table Check:</h3>";
$tables = ['pending_residents', 'pending_family_members', 'email_notifications'];
foreach ($tables as $table) {
    try {
        $result = db()->query("SHOW TABLES LIKE '$table'");
        echo "$table: " . ($result->rowCount() > 0 ? "EXISTS" : "MISSING") . "<br>";
    } catch (Exception $e) {
        echo "$table: ERROR - " . $e->getMessage() . "<br>";
    }
}

// Check users table structure
echo "<h3>Users Table Structure:</h3>";
try {
    $result = db()->query("DESCRIBE users");
    while ($row = $result->fetch()) {
        echo $row['Field'] . " - " . $row['Type'] . "<br>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Test a simple insert into pending_residents
echo "<h3>Test Insert:</h3>";
try {
    $stmt = db()->prepare("INSERT INTO pending_residents(email, password_hash, first_name, last_name, date_of_birth, phone, address, barangay_id, purok_id, status) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $result = $stmt->execute(['test@example.com', 'test_hash', 'Test', 'User', '1990-01-01', '123456789', 'Test Address', 1, 1, 'pending']);
    echo "Test insert: " . ($result ? "SUCCESS" : "FAILED") . "<br>";
    
    // Clean up test data
    db()->query("DELETE FROM pending_residents WHERE email = 'test@example.com'");
    echo "Test data cleaned up<br>";
} catch (Exception $e) {
    echo "Test insert failed: " . $e->getMessage() . "<br>";
}

// Check if there are any pending residents
echo "<h3>Current Pending Residents:</h3>";
try {
    $result = db()->query("SELECT COUNT(*) as count FROM pending_residents");
    $row = $result->fetch();
    echo "Total pending residents: " . $row['count'] . "<br>";
    
    if ($row['count'] > 0) {
        $result = db()->query("SELECT id, email, first_name, last_name, status, created_at FROM pending_residents ORDER BY created_at DESC LIMIT 5");
        while ($row = $result->fetch()) {
            echo "- " . $row['first_name'] . " " . $row['last_name'] . " (" . $row['email'] . ") - " . $row['status'] . " - " . $row['created_at'] . "<br>";
        }
    }
} catch (Exception $e) {
    echo "Error checking pending residents: " . $e->getMessage() . "<br>";
}
?>
