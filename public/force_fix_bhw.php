<?php
require_once '../config/db.php';

echo "<h2>Force Fix BHW Assignment</h2>";

try {
    // Get the BHW user
    $stmt = db()->prepare("SELECT * FROM users WHERE email = 's2peed3@gmail.com' AND role = 'bhw'");
    $stmt->execute();
    $bhw = $stmt->fetch();
    
    if (!$bhw) {
        echo "❌ BHW not found!<br>";
        exit;
    }
    
    echo "BHW found: " . $bhw['first_name'] . " " . $bhw['last_name'] . "<br>";
    echo "Current purok_id: " . ($bhw['purok_id'] ?: 'NULL') . "<br>";
    
    // Get the pending registration
    $stmt = db()->prepare("SELECT * FROM pending_residents WHERE status = 'pending' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $pending = $stmt->fetch();
    
    if (!$pending) {
        echo "❌ No pending registrations found!<br>";
        exit;
    }
    
    echo "Pending registration found: " . $pending['first_name'] . " " . $pending['last_name'] . "<br>";
    echo "Pending registration purok_id: " . $pending['purok_id'] . "<br>";
    
    // Force assign the BHW to the same purok as the pending registration
    if ($bhw['purok_id'] != $pending['purok_id']) {
        echo "Updating BHW purok_id from " . $bhw['purok_id'] . " to " . $pending['purok_id'] . "<br>";
        
        $stmt = db()->prepare("UPDATE users SET purok_id = ? WHERE id = ?");
        $stmt->execute([$pending['purok_id'], $bhw['id']]);
        
        echo "✅ BHW purok_id updated!<br>";
    } else {
        echo "✅ BHW purok_id already matches pending registration<br>";
    }
    
    // Test the query again
    echo "<h3>Testing Query After Fix:</h3>";
    $stmt = db()->prepare('
        SELECT pr.*, b.name as barangay_name, p.name as purok_name,
               (SELECT COUNT(*) FROM pending_family_members WHERE pending_resident_id = pr.id) as family_count
        FROM pending_residents pr
        JOIN barangays b ON b.id = pr.barangay_id
        JOIN puroks p ON p.id = pr.purok_id
        WHERE pr.purok_id = ? AND pr.status = "pending"
        ORDER BY pr.created_at DESC
    ');
    $stmt->execute([$pending['purok_id']]);
    $results = $stmt->fetchAll();
    
    echo "Query results: " . count($results) . " records<br>";
    
    if (count($results) > 0) {
        echo "✅ SUCCESS! BHW should now see pending registrations<br>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Name</th><th>Email</th><th>Purok</th><th>Status</th></tr>";
        foreach ($results as $result) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($result['first_name'] . ' ' . $result['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($result['email']) . "</td>";
            echo "<td>" . htmlspecialchars($result['barangay_name'] . ' - ' . $result['purok_name']) . "</td>";
            echo "<td>" . htmlspecialchars($result['status']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ Still no results found. There might be a deeper issue.<br>";
    }
    
    echo "<br><strong>Now try logging in as Ann Canamucan and check the Pending Registrations page!</strong><br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>

