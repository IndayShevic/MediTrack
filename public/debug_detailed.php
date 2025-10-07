<?php
require_once '../config/db.php';

echo "<h2>Detailed Debug - BHW Pending Registrations</h2>";

// Get the specific BHW user (Ann Canamucan)
echo "<h3>1. BHW User Details:</h3>";
try {
    $stmt = db()->prepare("SELECT * FROM users WHERE email = 's2peed3@gmail.com' AND role = 'bhw'");
    $stmt->execute();
    $bhw = $stmt->fetch();
    
    if ($bhw) {
        echo "BHW Found:<br>";
        echo "- ID: " . $bhw['id'] . "<br>";
        echo "- Name: " . $bhw['first_name'] . " " . $bhw['last_name'] . "<br>";
        echo "- Email: " . $bhw['email'] . "<br>";
        echo "- Purok ID: " . ($bhw['purok_id'] ?: 'NULL') . "<br>";
        echo "- Role: " . $bhw['role'] . "<br>";
    } else {
        echo "❌ BHW not found!<br>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Get all pending residents
echo "<h3>2. All Pending Residents:</h3>";
try {
    $result = db()->query("
        SELECT pr.*, p.name as purok_name, b.name as barangay_name
        FROM pending_residents pr
        LEFT JOIN puroks p ON pr.purok_id = p.id
        LEFT JOIN barangays b ON pr.barangay_id = b.id
        ORDER BY pr.created_at DESC
    ");
    
    $count = $result->rowCount();
    echo "Total pending residents: " . $count . "<br><br>";
    
    if ($count > 0) {
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
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Test the exact query that the BHW page uses
echo "<h3>3. Testing BHW Query:</h3>";
if ($bhw && $bhw['purok_id']) {
    try {
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
        
        echo "Query executed for purok_id: " . $bhw['purok_id'] . "<br>";
        echo "Results found: " . count($pending) . "<br><br>";
        
        if (count($pending) > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Purok</th><th>Family Count</th><th>Status</th></tr>";
            foreach ($pending as $p) {
                echo "<tr>";
                echo "<td>" . $p['id'] . "</td>";
                echo "<td>" . htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) . "</td>";
                echo "<td>" . htmlspecialchars($p['email']) . "</td>";
                echo "<td>" . htmlspecialchars($p['barangay_name'] . ' - ' . $p['purok_name']) . "</td>";
                echo "<td>" . $p['family_count'] . "</td>";
                echo "<td>" . htmlspecialchars($p['status']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "❌ No results found with the BHW query!<br>";
            
            // Let's check what's wrong
            echo "<h4>Debugging the query:</h4>";
            
            // Check if there are any pending residents for this purok (without status filter)
            $stmt = db()->prepare('SELECT COUNT(*) as count FROM pending_residents WHERE purok_id = ?');
            $stmt->execute([$bhw['purok_id']]);
            $total = $stmt->fetch()['count'];
            echo "- Total residents for purok " . $bhw['purok_id'] . ": " . $total . "<br>";
            
            // Check status of residents for this purok
            $stmt = db()->prepare('SELECT status, COUNT(*) as count FROM pending_residents WHERE purok_id = ? GROUP BY status');
            $stmt->execute([$bhw['purok_id']]);
            while ($row = $stmt->fetch()) {
                echo "- Status '" . $row['status'] . "': " . $row['count'] . " residents<br>";
            }
        }
    } catch (Exception $e) {
        echo "Error in BHW query: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ BHW has no purok_id assigned!<br>";
}

// Check if there are any issues with the database structure
echo "<h3>4. Database Structure Check:</h3>";
try {
    // Check if the tables exist and have data
    $tables = ['pending_residents', 'pending_family_members', 'users'];
    foreach ($tables as $table) {
        $result = db()->query("SELECT COUNT(*) as count FROM $table");
        $count = $result->fetch()['count'];
        echo "- $table: $count records<br>";
    }
} catch (Exception $e) {
    echo "Error checking tables: " . $e->getMessage() . "<br>";
}
?>

