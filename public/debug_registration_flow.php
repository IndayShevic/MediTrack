<?php
require_once '../config/db.php';

echo "<h2>Debug Registration Flow</h2>";

try {
    // Check if tables exist
    echo "<h3>1. Check Database Tables:</h3>";
    $tables = db()->query("SHOW TABLES LIKE 'pending_%'")->fetchAll();
    
    if (empty($tables)) {
        echo "❌ Missing tables! Please run: <a href='create_pending_tables.php'>create_pending_tables.php</a><br>";
    } else {
        echo "✅ Tables found:<br>";
        foreach ($tables as $table) {
            $tableName = array_values($table)[0];
            echo "- " . $tableName . "<br>";
        }
    }
    
    // Check pending residents count
    echo "<h3>2. Check Pending Registrations:</h3>";
    try {
        $count = db()->query("SELECT COUNT(*) as total FROM pending_residents")->fetch()['total'];
        echo "Total pending residents: " . $count . "<br>";
        
        if ($count > 0) {
            $allPendings = db()->query("SELECT * FROM pending_residents ORDER BY created_at DESC")->fetchAll();
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Purok ID</th><th>Status</th><th>Created</th></tr>";
            
            foreach ($allPendings as $pending) {
                echo "<tr>";
                echo "<td>" . $pending['id'] . "</td>";
                echo "<td>" . htmlspecialchars($pending['first_name'] . ' ' . $pending['last_name']) . "</td>";
                echo "<td>" . htmlspecialchars($pending['email']) . "</td>";
                echo "<td>" . $pending['purok_id'] . "</td>";
                echo "<td>" . $pending['status'] . "</td>";
                echo "<td>" . $pending['created_at'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "❌ No pending registrations found!<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Error accessing pending_residents: " . $e->getMessage() . "<br>";
    }
    
    // Check BHW assignments
    echo "<h3>3. Check BHW Assignments:</h3>";
    $bhws = db()->query("
        SELECT u.id, u.first_name, u.last_name, u.email, u.purok_id, p.name as purok_name, b.name as barangay_name
        FROM users u 
        LEFT JOIN puroks p ON u.purok_id = p.id 
        LEFT JOIN barangays b ON p.barangay_id = b.id 
        WHERE u.role = 'bhw'
        ORDER BY u.first_name
    ")->fetchAll();
    
    if (empty($bhws)) {
        echo "❌ No BHW users found!<br>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>BHW Name</th><th>Email</th><th>Barangay</th><th>Purok</th><th>Purok ID</th></tr>";
        
        foreach ($bhws as $bhw) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($bhw['email']) . "</td>";
            echo "<td>" . htmlspecialchars($bhw['barangay_name'] ?: 'Not assigned') . "</td>";
            echo "<td>" . htmlspecialchars($bhw['purok_name'] ?: 'Not assigned') . "</td>";
            echo "<td>" . ($bhw['purok_id'] ?: 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check barangay and purok data
    echo "<h3>4. Check Barangay and Purok Data:</h3>";
    $barangays = db()->query("SELECT id, name FROM barangays ORDER BY name")->fetchAll();
    echo "<strong>Barangays:</strong><br>";
    foreach ($barangays as $barangay) {
        echo "- ID " . $barangay['id'] . ": " . htmlspecialchars($barangay['name']) . "<br>";
    }
    
    echo "<br><strong>Puroks:</strong><br>";
    $puroks = db()->query("
        SELECT p.id, p.name, b.name as barangay_name, p.barangay_id
        FROM puroks p 
        JOIN barangays b ON p.barangay_id = b.id 
        ORDER BY b.name, p.name
    ")->fetchAll();
    
    foreach ($puroks as $purok) {
        echo "- ID " . $purok['id'] . ": " . htmlspecialchars($purok['barangay_name'] . ' - ' . $purok['name']) . "<br>";
    }
    
    // Test creating a pending registration
    echo "<h3>5. Test Registration Process:</h3>";
    echo "<p>Let's manually create a test pending registration...</p>";
    
    // Get the first BHW
    $firstBhw = $bhws[0] ?? null;
    if ($firstBhw && $firstBhw['purok_id']) {
        echo "Creating test registration for BHW: " . $firstBhw['first_name'] . " (" . $firstBhw['email'] . ")<br>";
        echo "Assigned to Purok ID: " . $firstBhw['purok_id'] . "<br>";
        
        try {
            $stmt = db()->prepare("
                INSERT INTO pending_residents (
                    email, password_hash, first_name, last_name, date_of_birth, 
                    phone, address, barangay_id, purok_id, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $hash = password_hash('test123', PASSWORD_BCRYPT);
            $success = $stmt->execute([
                'test@example.com',
                $hash,
                'Test',
                'User',
                '2000-01-01',
                '09123456789',
                'Test Address',
                $firstBhw['purok_id'], // This will be barangay_id, we need to get it properly
                1 // purok_id - this should match the BHW's purok
            ]);
            
            if ($success) {
                echo "✅ Test pending registration created!<br>";
            } else {
                echo "❌ Failed to create test registration<br>";
            }
            
        } catch (Exception $e) {
            echo "❌ Error creating test registration: " . $e->getMessage() . "<br>";
        }
        
    } else {
        echo "❌ No BHW with purok assignment found for testing<br>";
    }
    
    echo "<br><strong>Next Steps:</strong><br>";
    echo "1. Run: <a href='create_pending_tables.php'>create_pending_tables.php</a><br>";
    echo "2. Try registering: <a href='register.php'>register.php</a><br>";
    echo "3. Check BHW pending: <a href='bhw/pending_residents.php'>bhw/pending_residents.php</a><br>";
    
} catch (Exception $e) {
    echo "❌ Global Error: " . $e->getMessage() . "<br>";
}
?>
