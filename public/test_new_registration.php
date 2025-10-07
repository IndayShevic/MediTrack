<?php
require_once '../config/db.php';
require_once '../config/email_notifications.php';

echo "<h2>Test New Registration</h2>";

// Clean up any existing test data first
try {
    db()->query("DELETE FROM pending_residents WHERE email = 'test@example.com'");
    echo "✓ Cleaned up any existing test data<br>";
} catch (Exception $e) {
    // Ignore if no data exists
}

// Test registration data
$testData = [
    'email' => 'test@example.com',
    'password' => 'testpass123',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'date_of_birth' => '1990-05-15',
    'phone' => '09123456789',
    'address' => '123 Test Street',
    'purok_id' => 1, // Assuming purok 1 exists
    'family_members' => [
        [
            'full_name' => 'Jane Doe',
            'relationship' => 'Mother',
            'age' => 65
        ],
        [
            'full_name' => 'Bob Doe',
            'relationship' => 'Father',
            'age' => 70
        ]
    ]
];

try {
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
        echo "✓ " . count($testData['family_members']) . " family members added<br>";
    }
    
    $pdo->commit();
    echo "✓ Transaction committed successfully<br>";
    
    // Test email notification to BHW
    echo "<h3>Testing BHW Email Notification:</h3>";
    
    $bhwStmt = db()->prepare('SELECT u.email, u.first_name, u.last_name, p.name as purok_name FROM users u JOIN puroks p ON p.id = u.purok_id WHERE u.role = "bhw" AND u.purok_id = ? LIMIT 1');
    $bhwStmt->execute([$testData['purok_id']]);
    $bhw = $bhwStmt->fetch();
    
    if ($bhw) {
        echo "Found BHW: " . $bhw['first_name'] . " " . $bhw['last_name'] . " (" . $bhw['email'] . ")<br>";
        
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
    
    echo "<br><strong>✅ Test registration completed successfully!</strong><br>";
    echo "<p>Now you can:</p>";
    echo "<ol>";
    echo "<li>Go to <a href='check_pending.php'>check_pending.php</a> to see the pending registration</li>";
    echo "<li>Login as the assigned BHW and check 'Pending Registrations'</li>";
    echo "<li>Try registering through the normal form at <a href='../index.php'>the homepage</a></li>";
    echo "</ol>";
    
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
