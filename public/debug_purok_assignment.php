<?php
require_once '../config/db.php';

echo "<h2>BHW Assignment Debug</h2>";

try {
    // Check all BHWs and their assignments
    echo "<h3>1. All BHW Users:</h3>";
    $bhws = db()->query("SELECT id, first_name, last_name, email, purok_id FROM users WHERE role = 'bhw'")->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>BHW ID</th><th>Name</th><th>Email</th><th>Purok ID</th><th>Purok Info</th></tr>";
    
    foreach ($bhws as $bhw) {
        echo "<tr>";
        echo "<td>" . $bhw['id'] . "</td>";
        echo "<td>" . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($bhw['email']) . "</td>";
        echo "<td>" . ($bhw['purok_id'] ?: 'NULL') . "</td>";
        
        if ($bhw['purok_id']) {
            $stmt = db()->prepare('SELECT p.name as purok_name, b.name as barangay_name FROM puroks p JOIN barangays b ON p.barangay_id = b.id WHERE p.id = ?');
            $stmt->execute([$bhw['purok_id']]);
            $purok = $stmt->fetch();
            if ($purok) {
                echo "<td>" . htmlspecialchars($purok['barangay_name'] . ' - ' . $purok['purok_name']) . "</td>";
            } else {
                echo "<td>❌ INVALID PUROK ID</td>";
            }
        } else {
            echo "<td>No assignment</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    // Check all available puroks
    echo "<h3>2. All Available Puroks:</h3>";
    $puroks = db()->query("
        SELECT p.id, p.name as purok_name, b.name as barangay_name 
        FROM puroks p 
        JOIN barangays b ON p.barangay_id = b.id 
        ORDER BY b.name, p.name
    ")->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Purok ID</th><th>Purok Name</th><th>Barangay</th><th>BHW Assigned</th><th>Pending Residents</th></tr>";
    
    foreach ($puroks as=$purok) {
        echo "<tr>";
        echo "<td>" . $purok['id'] . "</td>";
        echo "<td>" . htmlspecialchars($purok['purok_name']) . "</td>";
        echo "<td>" . htmlspecialchars($purok['barangay_name']) . "</td>";
        
        // Check if any BHW is assigned to this purok
        $stmt = db()->prepare('SELECT first_name, last_name FROM users WHERE role = "bhw" AND purok_id = ? LIMIT 1');
        $stmt->execute([$purok['id']]);
        $assignedBhw = $stmt->fetch();
        
        if ($assignedBhw) {
            echo "<td>✅ " . htmlspecialchars($assignedBhw['first_name'] . ' ' . $assignedBhw['last_name']) . "</td>";
        } else {
            echo "<td>❌ No BHW assigned</td>";
        }
        
        // Count pending residents in this purok
        $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ? AND status = "pending"');
        $stmt->execute([$purok['id']]);
        $count = $stmt->fetch()['count'];
        echo "<td>" . $count . "</td>";
        
        echo "</tr>";
    }
    echo "</table>";

    // Check all pending residents
    echo "<h3>3. All Pending Residents:</h3>";
    $pending = db()->query("
        SELECT pr.*, b.name as barangay_name, p.name as purok_name
        FROM pending_residents pr
        JOIN barangays b ON b.id = pr.barangay_id
        JOIN puroks p ON p.id = pr.purok_id
        WHERE pr.status = 'pending'
        ORDER BY pr.created_at DESC
    ")->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Purok</th><th>Assigned BHW</th></tr>";
    
    foreach ($pending as $resident) {
        echo "<tr>";
        echo "<td>" . $resident['id'] . "</td>";
        echo "<td>" . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($resident['email']) . "</td>";
        echo "<td>" . htmlspecialchars($resident['barangay_name'] . ' - ' . $resident['purok_name']) . "</td>";
        
        // Check if any BHW is assigned to this resident's purok
        $stmt = db()->prepare('SELECT first_name, last_name FROM users WHERE role = "bhw" AND purok_id = ? LIMIT 1');
        $stmt->execute([$resident['purok_id']]);
        $bhw = $stmt->fetch();
        
        if ($bhw) {
            echo "<td>✅ " . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . "</td>";
        } else {
            echo "<td>❌ No BHW assigned</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
