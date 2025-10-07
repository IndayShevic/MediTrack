<?php
require_once '../config/db.php';

echo "<h2>Fix BHW Assignment</h2>";

try {
    // Get all BHW users
    $bhws = db()->query("SELECT id, first_name, last_name, email, purok_id FROM users WHERE role = 'bhw'")->fetchAll();
    
    echo "<h3>Current BHW Assignments:</h3>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>BHW Name</th><th>Email</th><th>Current Purok ID</th><th>Action</th></tr>";
    
    foreach ($bhws as $bhw) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($bhw['email']) . "</td>";
        echo "<td>" . ($bhw['purok_id'] ?: 'NULL') . "</td>";
        echo "<td>";
        
        if (!$bhw['purok_id']) {
            echo "⚠ No purok assigned";
        } else {
            // Check if this BHW has any pending residents
            $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ? AND status = "pending"');
            $stmt->execute([$bhw['purok_id']]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                echo "✅ Has " . $count . " pending registrations";
            } else {
                echo "ℹ No pending registrations for this purok";
            }
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Get all puroks
    echo "<h3>Available Puroks:</h3>";
    $puroks = db()->query("
        SELECT p.id, p.name, b.name as barangay_name 
        FROM puroks p 
        JOIN barangays b ON p.barangay_id = b.id 
        ORDER BY b.name, p.name
    ")->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Purok ID</th><th>Purok Name</th><th>Barangay</th><th>Pending Residents</th></tr>";
    
    foreach ($puroks as $purok) {
        $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ? AND status = "pending"');
        $stmt->execute([$purok['id']]);
        $count = $stmt->fetch()['count'];
        
        echo "<tr>";
        echo "<td>" . $purok['id'] . "</td>";
        echo "<td>" . htmlspecialchars($purok['name']) . "</td>";
        echo "<td>" . htmlspecialchars($purok['barangay_name']) . "</td>";
        echo "<td>" . $count . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Auto-assign BHWs to puroks with pending residents
    echo "<h3>Auto-Assigning BHWs to Puroks with Pending Residents:</h3>";
    
    foreach ($puroks as $purok) {
        $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ? AND status = "pending"');
        $stmt->execute([$purok['id']]);
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            // Check if any BHW is already assigned to this purok
            $stmt = db()->prepare('SELECT COUNT(*) as count FROM users WHERE role = "bhw" AND purok_id = ?');
            $stmt->execute([$purok['id']]);
            $bhwCount = $stmt->fetch()['count'];
            
            if ($bhwCount == 0) {
                // Find a BHW without a purok assignment
                $stmt = db()->prepare('SELECT id, first_name, last_name FROM users WHERE role = "bhw" AND purok_id IS NULL LIMIT 1');
                $stmt->execute();
                $unassignedBhw = $stmt->fetch();
                
                if ($unassignedBhw) {
                    // Assign this BHW to the purok
                    $stmt = db()->prepare('UPDATE users SET purok_id = ? WHERE id = ?');
                    $stmt->execute([$purok['id'], $unassignedBhw['id']]);
                    echo "✅ Assigned " . $unassignedBhw['first_name'] . " " . $unassignedBhw['last_name'] . " to " . $purok['barangay_name'] . " - " . $purok['name'] . "<br>";
                } else {
                    echo "⚠ No unassigned BHW available for " . $purok['barangay_name'] . " - " . $purok['name'] . "<br>";
                }
            } else {
                echo "ℹ BHW already assigned to " . $purok['barangay_name'] . " - " . $purok['name'] . "<br>";
            }
        }
    }
    
    echo "<br><strong>BHW assignment completed!</strong><br>";
    echo "<p>Now try logging in as the BHW and check the 'Pending Registrations' page.</p>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
