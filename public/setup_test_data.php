<?php
require_once '../config/db.php';

echo "<h2>Setting Up Test Data</h2>";

try {
    // First, let's check what exists
    echo "<h3>1. Checking Current Data:</h3>";
    
    // Check barangays
    $barangays = db()->query("SELECT * FROM barangays ORDER BY name")->fetchAll();
    echo "<strong>Barangays:</strong><br>";
    foreach ($barangays as $barangay) {
        echo "- ID " . $barangay['id'] . ": " . htmlspecialchars($barangay['name']) . "<br>";
    }
    
    // Check puroks
    $puroks = db()->query("
        SELECT p.id, p.name, b.name as barangay_name 
        FROM puroks p 
        JOIN barangays b ON p.barangay_id = b.id 
        ORDER BY b.name, p.name
    ")->fetchAll();
    echo "<br><strong>Puroks:</strong><br>";
    foreach ($puroks as $purok) {
        echo "- ID " . $purok['id'] . ": " . htmlspecialchars($purok['barangay_name'] . ' - ' . $purok['name']) . "<br>";
    }
    
    // Check BHW users
    $bhws = db()->query("SELECT id, first_name, last_name, email, purok_id FROM users WHERE role = 'bhw'")->fetchAll();
    echo "<br><strong>BHW Users:</strong><br>";
    if (empty($bhws)) {
        echo "❌ No BHW users found!<br>";
    } else {
        foreach ($bhws as $bhw) {
            echo "- ID " . $bhw['id'] . ": " . htmlspecialchars($bhw['first_name'] . ' ' . $bhw['last_name']) . " (" . htmlspecialchars($bhw['email']) . ") - Purok ID: " . ($bhw['purok_id'] ?: 'NULL') . "<br>";
        }
    }
    
    // Add some test barangays and puroks if they don't exist
    echo "<h3>2. Adding Test Data:</h3>";
    
    $testData = [
        ['barangay' => 'Santa Rosa', 'puroks' => ['Purok 1', 'Purok 2', 'Purok 3']],
        ['barangay' => 'Malaya', 'puroks' => ['Purok 1', 'Purok 2']],
    ];
    
    foreach ($testData as $data) {
        // Check if barangay exists
        $stmt = db()->prepare("SELECT id FROM barangays WHERE name = ?");
        $stmt->execute([$data['barangay']]);
        $barangay = $stmt->fetch();
        
        $barangayId = null;
        if (!$barangay) {
            // Create barangay
            $stmt = db()->prepare("INSERT INTO barangays (name) VALUES (?)");
            $stmt->execute([$data['barangay']]);
            $barangayId = db()->lastInsertId();
            echo "✅ Created barangay: " . $data['barangay'] . " (ID: " . $barangayId . ")<br>";
        } else {
            $barangayId = $barangay['id'];
            echo "ℹ Barangay already exists: " . $data['barangay'] . " (ID: " . $barangayId . ")<br>";
        }
        
        // Create puroks for this barangay
        foreach ($data['puroks'] as $purokName) {
            $stmt = db()->prepare("SELECT id FROM puroks WHERE barangay_id = ? AND name = ?");
            $stmt->execute([$barangayId, $purokName]);
            $existingPurok = $stmt->fetch();
            
            if (!$existingPurok) {
                $stmt = db()->prepare("INSERT INTO puroks (barangay_id, name) VALUES (?, ?)");
                $stmt->execute([$barangayId, $purokName]);
                $purokId = db()->lastInsertId();
                echo "✅ Created purok: " . $data['barangay'] . " - " . $purokName . " (ID: " . $purokId . ")<br>";
            } else {
                echo "ℹ Purok already exists: " . $data['barangay'] . " - " . $purokName . " (ID: " . $existingPurok['id'] . ")<br>";
            }
        }
    }
    
    // Create a test BHW if none exist
    echo "<h3>3. Creating Test BHW:</h3>";
    if (empty($bhws)) {
        // Get the first purok
        $firstPurok = db()->query("SELECT id FROM puroks ORDER BY id LIMIT 1")->fetch();
        
        if ($firstPurok) {
            $stmt = db()->prepare("
                INSERT INTO users (email, password_hash, role, first_name, last_name, purok_id) 
                VALUES (?, ?, 'bhw', 'Test', 'BHW', ?)
            ");
            $hash = password_hash('password', PASSWORD_BCRYPT);
            $stmt->execute(['test.bhw@example.com', $hash, $firstPurok['id']]);
            $bhwId = db()->lastInsertId();
            echo "✅ Created test BHW: test.bhw@example.com (password: password) - Assigned to Purok ID " . $firstPurok['id'] . "<br>";
        } else {
            echo "❌ No puroks found to assign BHW to!<br>";
        }
    } else {
        echo "ℹ BHW users already exist<br>";
    }
    
    echo "<br><strong>✅ Setup completed!</strong><br>";
    echo "<p>Next steps:</p>";
    echo "<ol>";
    echo "<li>Go to the super admin panel and check users management</li>";
    echo "<li>Assign BHWs to specific puroks</li>";
    echo "<li>Register a new resident account</li>";
    echo "<li>Check the BHW's pending registrations page</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
