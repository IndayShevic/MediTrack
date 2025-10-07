<?php
require_once '../config/db.php';

echo "<h2>BHW Pending Registrations Debug</h2>";

// Get all BHW users and their purok assignments
echo "<h3>All BHW Users and Their Purok Assignments:</h3>";
try {
    $result = db()->query("
        SELECT u.id, u.first_name, u.last_name, u.email, u.purok_id, p.name as purok_name, b.name as barangay_name
        FROM users u 
        LEFT JOIN puroks p ON u.purok_id = p.id 
        LEFT JOIN barangays b ON p.barangay_id = b.id 
        WHERE u.role = 'bhw'
        ORDER BY u.first_name
    ");
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>BHW ID</th><th>Name</th><th>Email</th><th>Purok ID</th><th>Purok Name</th><th>Barangay</th></tr>";
    
    while ($row = $result->fetch()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . ($row['purok_id'] ?: 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['purok_name'] ?: 'Not assigned') . "</td>";
        echo "<td>" . htmlspecialchars($row['barangay_name'] ?: 'Not assigned') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error fetching BHW data: " . $e->getMessage() . "<br>";
}

// Get all pending residents
echo "<h3>All Pending Residents:</h3>";
try {
    $result = db()->query("
        SELECT pr.*, p.name as purok_name, b.name as barangay_name
        FROM pending_residents pr
        LEFT JOIN puroks p ON pr.purok_id = p.id
        LEFT JOIN barangays b ON pr.barangay_id = b.id
        ORDER BY pr.created_at DESC
    ");
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Purok ID</th><th>Purok Name</th><th>Status</th><th>Created</th></tr>";
    
    while ($row = $result->fetch()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . $row['purok_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['barangay_name'] . ' - ' . $row['purok_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error fetching pending residents: " . $e->getMessage() . "<br>";
}

// Test the specific query that the BHW page uses
echo "<h3>Testing BHW Query for Each BHW:</h3>";
try {
    $bhws = db()->query("SELECT id, first_name, last_name, email, purok_id FROM users WHERE role = 'bhw'")->fetchAll();
    
    foreach ($bhws as $bhw) {
        echo "<h4>BHW: " . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . " (Purok ID: " . ($bhw['purok_id'] ?: 'NULL') . ")</h4>";
        
        if ($bhw['purok_id']) {
            $stmt = db()->prepare('
                SELECT pr.*, b.name as barangay_name, p.name as purok_name,
                       (SELECT COUNT(*) FROM pending_family_members WHERE pending_resident_id = pr.id) as family_count
                FROM pending_residents pr
                JOIN barangays b ON b.id = pr.barangay_id
                JOIN puroks p ON p.id = pr.purok_id
                WHERE pr.purok_id = ? AND pr.status = "pending"
                ORDER BY pr.created_at DESC
            ');
            $stmt->execute([$bhw['purok_id']]);
            $pending = $stmt->fetchAll();
            
            echo "Found " . count($pending) . " pending registrations for this BHW<br>";
            
            if (count($pending) > 0) {
                echo "<table border='1' cellpadding='5' cellspacing='0'>";
                echo "<tr><th>Name</th><th>Email</th><th>Purok</th><th>Family Count</th></tr>";
                foreach ($pending as $p) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($p['email']) . "</td>";
                    echo "<td>" . htmlspecialchars($p['barangay_name'] . ' - ' . $p['purok_name']) . "</td>";
                    echo "<td>" . $p['family_count'] . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } else {
            echo "⚠ This BHW has no purok assigned!<br>";
        }
        echo "<br>";
    }
    
} catch (Exception $e) {
    echo "Error testing BHW queries: " . $e->getMessage() . "<br>";
}
?>
