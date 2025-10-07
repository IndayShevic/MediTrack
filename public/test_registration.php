<?php
require_once '../config/db.php';
require_once '../config/email_notifications.php';

echo "<h2>Registration Test</h2>";

// Test data
$testData = [
    'email' => 'testuser@example.com',
    'password' => 'testpassword123',
    'first_name' => 'Test',
    'last_name' => 'User',
    'date_of_birth' => '1990-01-01',
    'phone' => '1234567890',
    'address' => 'Test Address',
    'purok_id' => 1, // Assuming purok 1 exists
    'family_members' => [
        [
            'full_name' => 'Test Family Member',
            'relationship' => 'Father',
            'age' => 65
        ]
    ]
];

try {
    // Clean up any existing test data
    db()->query("DELETE FROM pending_residents WHERE email = 'testuser@example.com'");
    
    // Get barangay_id from purok
    $stmt = db()->prepare('SELECT barangay_id FROM puroks WHERE id = ? LIMIT 1');
    $stmt->execute([$testData['purok_id']]);
    $row = $stmt->fetch();
    $barangay_id = $row ? (int)$row['barangay_id'] : 1;
    
    echo "Using purok_id: " . $testData['purok_id'] . ", barangay_id: " . $barangay_id . "<br>";
    
    // Start transaction
    $pdo = db();
    $pdo->beginTransaction();
    
    // Insert into pending_residents
    $hash = password_hash($testData['password'], PASSWORD_BCRYPT);
    $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, date_of_birth, phone, address, barangay_id, purok_id) VALUES(?,?,?,?,?,?,?,?,?)');
    $insPending->execute([
        $testData['email'], 
        $hash, 
        $testData['first_name'], 
        $testData['last_name'], 
        $testData['date_of_birth'], 
        $testData['phone'], 
        $testData['address'], 
        $barangay_id, 
        $testData['purok_id']
    ]);
    $pendingId = (int)$pdo->lastInsertId();
    
    echo "✓ Pending resident created with ID: " . $pendingId . "<br>";
    
    // Insert family members
    if (!empty($testData['family_members'])) {
        $insFamily = $pdo->prepare('INSERT INTO pending_family_members(pending_resident_id, full_name, relationship, age) VALUES(?,?,?,?)');
        foreach ($testData['family_members'] as $member) {
            $insFamily->execute([$pendingId, $member['full_name'], $member['relationship'], $member['age']]);
        }
        echo "✓ Family members added<br>";
    }
    
    $pdo->commit();
    echo "✓ Transaction committed successfully<br>";
    
    // Test email notification
    echo "<h3>Testing Email Notification:</h3>";
    
    // Find BHW for this purok
    $bhwStmt = db()->prepare('SELECT u.email, u.first_name, u.last_name, p.name as purok_name FROM users u JOIN puroks p ON p.id = u.purok_id WHERE u.role = "bhw" AND u.purok_id = ? LIMIT 1');
    $bhwStmt->execute([$testData['purok_id']]);
    $bhw = $bhwStmt->fetch();
    
    if ($bhw) {
        echo "Found BHW: " . $bhw['first_name'] . " " . $bhw['last_name'] . " (" . $bhw['email'] . ")<br>";
        
        // Test email sending
        $bhwName = trim(($bhw['first_name'] ?? '') . ' ' . ($bhw['last_name'] ?? ''));
        $residentName = $testData['first_name'] . ' ' . $testData['last_name'];
        $success = send_new_registration_notification_to_bhw($bhw['email'], $bhwName, $residentName, $bhw['purok_name']);
        
        if ($success) {
            echo "✓ Email sent successfully to BHW<br>";
        } else {
            echo "⚠ Email sending failed (check email configuration)<br>";
        }
        
        log_email_notification(0, 'new_registration', 'New Registration', 'New resident registration notification sent to BHW', $success);
    } else {
        echo "⚠ No BHW found for purok " . $testData['purok_id'] . "<br>";
        echo "You need to assign a BHW to this purok in the super admin panel<br>";
    }
    
    // Check if the data was inserted correctly
    echo "<h3>Verification:</h3>";
    $result = db()->query("SELECT COUNT(*) as count FROM pending_residents WHERE email = 'testuser@example.com'");
    $row = $result->fetch();
    echo "Pending residents with test email: " . $row['count'] . "<br>";
    
    $result = db()->query("SELECT COUNT(*) as count FROM pending_family_members WHERE pending_resident_id = $pendingId");
    $row = $result->fetch();
    echo "Family members for test resident: " . $row['count'] . "<br>";
    
    echo "<br><strong>Registration test completed successfully!</strong><br>";
    echo "<p>You can now try registering through the normal form at <a href='../index.php'>the homepage</a></p>";
    
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
