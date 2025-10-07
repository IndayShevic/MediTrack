<?php
require_once '../config/db.php';

echo "<h2>Current Pending Registrations</h2>";

try {
    // Check if there are any pending residents
    $result = db()->query("
        SELECT pr.*, p.name as purok_name, b.name as barangay_name 
        FROM pending_residents pr 
        LEFT JOIN puroks p ON pr.purok_id = p.id 
        LEFT JOIN barangays b ON pr.barangay_id = b.id 
        ORDER BY pr.created_at DESC
    ");
    
    $count = $result->rowCount();
    echo "Total pending registrations: <strong>" . $count . "</strong><br><br>";
    
    if ($count > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Name</th><th>Email</th><th>Purok</th><th>Status</th><th>Created</th></tr>";
        
        while ($row = $result->fetch()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['barangay_name'] . ' - ' . $row['purok_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No pending registrations found. This means:</p>";
        echo "<ul>";
        echo "<li>Either no one has registered yet, OR</li>";
        echo "<li>The previous registrations failed due to missing tables</li>";
        echo "</ul>";
        echo "<p><strong>You need to register again now that the tables are created.</strong></p>";
    }
    
    // Check BHW assignments
    echo "<h3>BHW Assignments:</h3>";
    $result = db()->query("
        SELECT u.first_name, u.last_name, u.email, p.name as purok_name, b.name as barangay_name
        FROM users u 
        LEFT JOIN puroks p ON u.purok_id = p.id 
        LEFT JOIN barangays b ON p.barangay_id = b.id 
        WHERE u.role = 'bhw'
        ORDER BY u.first_name
    ");
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>BHW Name</th><th>Email</th><th>Assigned Purok</th></tr>";
    
    while ($row = $result->fetch()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['barangay_name'] . ' - ' . $row['purok_name']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
