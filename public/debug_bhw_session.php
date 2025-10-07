<?php
require_once '../config/db.php';

echo "<h2>Debug BHW Session Issue</h2>";

// Start session to check current user
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h3>1. Current Session Data:</h3>";
if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    echo "<strong>Logged in as:</strong><br>";
    echo "- ID: " . $user['id'] . "<br>";
    echo "- Name: " . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "<br>";
    echo "- Email: " . htmlspecialchars($user['email']) . "<br>";
    echo "- Role: " . $user['role'] . "<br>";
    echo "- Purok ID: " . ($user['purok_id'] ?: 'NULL') . "<br>";
    
    // Verify the BHW data in database
    echo "<br><h3>2. Verify BHW Data in Database:</h3>";
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'bhw'");
    $stmt->execute([$user['id']]);
    $dbBhw = $stmt->fetch();
    
    if ($dbBhw) {
        echo "✅ BHW found in database:<br>";
        echo "- Database Purok ID: " . ($dbBhw['purok_id'] ?: 'NULL') . "<br>";
        
        // Get purok details
        if ($dbBhw['purok_id']) {
            $stmt = db()->prepare("SELECT p.name as purok_name, b.name as barangay_name FROM puroks p JOIN barangays b ON p.barangay_id = b.id WHERE p.id = ?");
            $stmt->execute([$dbBhw['purok_id']]);
            $purokDetails = $stmt->fetch();
            
            if ($purokDetails) {
                echo "- Assignment: " . htmlspecialchars($purokDetails['barangay_name'] . ' - ' . $purokDetails['purok_name']) . "<br>";
            }
        }
    } else {
        echo "❌ BHW not found in database<br>";
    }
    
    // Check pending residents for this BHW's purok
    echo "<br><h3>3. Check Pending Residents Query:</h3>";
    if ($dbBhw && $dbBhw['purok_id']) {
        $stmt = db()->prepare("
            SELECT COUNT(*) as count 
            FROM pending_residents 
            WHERE purok_id = ? AND status = 'pending'
        ");
        $stmt->execute([$dbBhw['purok_id']]);
        $count = $stmt->fetch()['count'];
        
        echo "Pending residents for Purok ID " . $dbBhw['purok_id'] . ": " . $count . "<br>";
        
        if ($count > 0) {
            echo "✅ Found " . $count . " pending registrations<br>";
            
            // Show the registrations
            $stmt = db()->prepare("
                SELECT pr.*, p.name as purok_name, b.name as barangay_name
                FROM pending_residents pr
                JOIN puroks p ON p.id = pr.purok_id
                JOIN barangays b ON b.id = pr.barangay_id
                WHERE pr.purok_id = ? AND pr.status = 'pending'
                ORDER BY pr.created_at DESC
            ");
            $stmt->execute([$dbBhw['purok_id']]);
            $pendingResidents = $stmt->fetchAll();
            
            echo "<br><strong>Pending Registrations:</strong><br>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Purok</th><th>Created</th></tr>";
            
            foreach ($pendingResidents as $resident) {
                echo "<tr>";
                echo "<td>" . $resident['id'] . "</td>";
                echo "<td>" . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . "</td>";
                echo "<td>" . htmlspecialchars($resident['email']) . "</td>";
                echo "<td>" . htmlspecialchars($resident['barangay_name'] . ' - ' . $resident['purok_name']) . "</td>";
                echo "<td>" . $resident['created_at'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
        } else {
            echo "❌ No pending registrations found for this BHW's purok<br>";
        }
    } else {
        echo "❌ BHW has no purok assignment<br>";
    }
    
    // Simulate the exact query from pending_residents.php
    echo "<br><h3>4. Simulate Exact Query from Page:</h3>";
    $bhw_purok_id = $user['purok_id'] ?? 0;
    echo "Using purok_id from session: " . $bhw_purok_id . "<br>";
    
    if ($bhw_purok_id > 0) {
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
            $stmt->execute([$bhw_purok_id]);
            $pending_residents = $stmt->fetchAll();
            
            echo "Query results: " . count($pending_residents) . " registrations<br>";
            
            if (count($pending_residents) > 0) {
                echo "✅ SUCCESS! Query should return registrations on the page<br>";
            } else {
                echo "❌ Query returns empty - this explains why page shows no registrations<br>";
            }
        } catch (Exception $e) {
            echo "❌ Query error: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "❌ bhwpurok_id is 0 or NULL<br>";
    }
    
} else {
    echo "❌ Not logged in<br>";
    echo "<p><strong>Solution:</strong> Please login first at <a href='login.php'>login.php</a></p>";
}

echo "<br><h3>5. Debug Steps Summary:</h3>";
echo "<ol>";
echo "<li>Session shows correct BHW user: " . (isset($_SESSION['user']) ? "✅" : "❌") . "</li>";
echo "<li>Database has correct BHW assignment: " . (isset($dbBhw) && $dbBhw ? "✅" : "❌") . "</li>";
echo "<li>BHW has purok assignment: " . (isset($dbBhw) && $dbBhw['purok_id'] ? "✅" : "❌") . "</li>";
echo "<li>Query finds pending residents: " . (isset($count) && $count > 0 ? "✅" : "❌") . "</li>";
echo "</ol>";

if (!isset($_SESSION['user'])) {
    echo "<br><strong>SOLUTION: Please login first!</strong><br>";
    echo "<a href='login.php'>Click here to login with BHW account</a>";
}
?>
