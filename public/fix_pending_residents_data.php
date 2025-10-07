<?php
require_once '../config/db.php';

echo "<h2>Fix Pending Residents Data</h2>";

try {
    // Check current pending residents
    echo "<h3>1. Current Pending Residents:</h3>";
    $pending = db()->query("SELECT * FROM pending_residents")->fetchAll();
    
    if (empty($pending)) {
        echo "❌ No pending residents found<br>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Barangay ID</th><th>Purok ID</th><th>Status</th></tr>";
        
        foreach ($pending as $resident) {
            echo "<tr>";
            echo "<td>" . $resident['id'] . "</td>";
            echo "<td>" . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($resident['email']) . "</td>";
            echo "<td>" . ($resident['barangay_id'] ?: 'NULL') . "</td>";
            echo "<td>" . ($resident['purok_id'] ?: 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($resident['status']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Get available barangays and puroks
    echo "<h3>2. Available Barangays and Puroks:</h3>";
    
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
    
    // Check BHW assignments
    echo "<h3>3. BHW Assignments:</h3>";
    $bhws = db()->query("
        SELECT u.id, u.first_name, u.last_name, u.email, u.purok_id, p.name as purok_name, b.name as barangay_name
        FROM users u 
        LEFT JOIN puroks p ON u.purok_id = p.id 
        LEFT JOIN barangays b ON p.barangay_id = b.id 
        WHERE u.role = 'bhw'
    ")->fetchAll();
    
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
    
    // Fix the pending residents by assigning them to the BHW's assigned purok
    echo "<h3>4. Fixing Pending Residents Assignment:</h3>";
    
    if (!empty($bhws)) {
        $firstBhw = $bhws[0]; // Use the first BHW
        $bhwPurokId = $firstBhw['purok_id'];
        $bhwBarangayId = null;
        
        if ($bhwPurokId) {
            // Get barangay_id for this purok
            $stmt = db()->prepare("SELECT barangay_id FROM puroks WHERE id = ?");
            $stmt->execute([$bhwPurokId]);
            $purok = $stmt->fetch();
            
            if ($purok) {
                $bhwBarangayId = $purok['barangay_id'];
                
                echo "Fixing pending residents to assign them to:<br>";
                echo "- BHW: " . $firstBhw['first_name'] . " " . $firstBhw['last_name'] . "<br>";
                echo "- Barangay ID: " . $bhwBarangayId . "<br>";
                echo "- Purok ID: " . $bhwPurokId . "<br><br>";
                
                // Update all pending residents to be assigned to this BHW's purok
                $stmt = db()->prepare("
                    UPDATE pending_residents 
                    SET barangay_id = ?, purok_id = ? 
                    WHERE status = 'pending'
                ");
                $result = $stmt->execute([$bhwBarangayId, $bhwPurokId]);
                
                if ($result) {
                    $affectedRows = $stmt->rowCount();
                    echo "✅ Updated " . $affectedRows . " pending residents<br>";
                    
                    // Check if BHW can now see them
                    echo "<h3>5. Testing BHW Query:</h3>";
                    $stmt = db()->prepare("
                        SELECT COUNT(*) as count 
                        FROM pending_residents 
                        WHERE purok_id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$bhwPurokId]);
                    $count = $stmt->fetch()['count'];
                    
                    echo "Pending registrations visible to BHW: " . $count . "<br>";
                    
                    if ($count > 0) {
                        echo "✅ SUCCESS! BHW should now see the pending registrations<br>";
                    } else {
                        echo "❌ Still no registrations visible to BHW<br>";
                    }
                } else {
                    echo "❌ Failed to update pending residents<br>";
                }
            } else {
                echo "❌ Could not find barangay for purok ID " . $bhwPurokId . "<br>";
            }
        } else {
            echo "❌ BHW has no purok assignment<br>";
        }
    } else {
        echo "❌ No BHW users found<br>";
    }
    
    echo "<br><strong>Next Steps:</strong><br>";
    echo "1. Login as BHW: <a href='bhw/pending_residents.php'>bhw/pending_residents.php</a><br>";
    echo "2. You should now see pending registrations<br>";
    echo "3. Test the approval process<br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
