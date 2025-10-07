<?php
require_once '../config/db.php';

echo "<h2>Check Pending Registrations</h2>";

try {
    // Check all pending residents
    echo "<h3>All Pending Registrations:</h3>";
    $pending = db()->query("
        SELECT pr.*, b.name as barangay_name, p.name as purok_name
        FROM pending_residents pr
        JOIN barangays b ON b.id = pr.barangay_id
        JOIN puroks p ON p.id = pr.purok_id


        ORDER BY pr.created_at DESC
    ")->fetchAll();
    
    if (empty($pending)) {
        echo "❌ No pending registrations found!<br><br>";
        
        // Let's check if there are any residents at all
        $allResidents = db()->query("SELECT COUNT(*) as count FROM pending_residents")->fetch()['count'];
        echo "Total pending_residents table records: " . $allResidents . "<br>";
        
        if ($allResidents == 0) {
            echo "<strong>Issue:</strong> The pending_residents table is empty, which means either:<br>";
            echo "1. No registration was submitted yet<br>";
            echo "2. The registration form isn't working<br>";
            echo "3. There's an error in the registration process<br><br>";
            
            echo "<strong>Next Steps:</strong><br>";
            echo "<a href='../register.php'>Try registering a new account here</a><br>";
        }
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Td</th><th>Name</th><th>Email</th><th>Purok</th><th>Status</th><th>Assigned BHW</th></tr>";
        
        foreach ($pending as $resident) {
            echo "<tr>";
            echo "<td>" . $resident['id'] . "</td>";
            echo "<td>" . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($resident['email']) . "</td>";
            echo "<td>" . htmlspecialchars($resident['barangay_name'] . ' - ' . $resident['purok_name']) . "</td>";
            echo "<td>" . htmlspecialchars($resident['status']) . "</td>";
            
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
    }
    
    // Check BHW assignments
    echo "<h3>BHW Assignments:</h3>";
    $bhws = db()->query("
        SELECT u.id, u.first_name, u.last_name, u.email, u.purok_id, p.name as purok_name, b.name as barangay_name
        FROM users u 
        LEFT JOIN puroks p ON u.purok_id = p.id 
        LEFT JOIN barangays b ON p.barangay_id = b.id 
        WHERE u.role = 'bhw'
    ")->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>BHW Name</th><th>Email</th><th>Assigned Purok</th><th>Pending Registrations</th></tr>";
    
    foreach ($bhws as $bhw) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($bhw['email']) . "</td>";
        
        if ($bhw['purok_id']) {
            echo "<td>✅ " . htmlspecialchars($bhw['barangay_name'] . ' - ' . $bhw['purok_name']) . "</td>";
            
            // Count pending residents for this BHW
            $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ? AND status = "pending"');
            $stmt->execute([$bhw['purok_id']]);
            $count = $stmt->fetch()['count'];
            echo "<td>" . $count . " pending</td>";
        } else {
            echo "<td>❌ No purok assigned</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><h3>Quick Test:</h3>";
    echo "<p>To test the system:</p>";
    echo "<ol>";
    echo "<li>Make sure your BHW (Ann Canamucan) is assigned to the correct purok</li>";
    echo "<li><a href='../register.php'>Register a new account</a> and select <strong>Basdacu - Purok 1</strong> (the same purok as Ann)</li>";
    echo "<li>After registration, login as Ann Canamucan (s2peed3@gmail.com) and check pending registrations</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
