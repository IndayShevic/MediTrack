<?php
require_once '../config/db.php';

echo "<h2>Test Registration Submission</h2>";

if ($_POST) {
    echo "<h3>POST Data Received:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    // Check each required field
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $barangay_id = (int)($_POST['barangay_id'] ?? 0);
    $purok_id = (int)($_POST['purok_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    echo "<h3>Validation Check:</h3>";
    echo "Email: '" . htmlspecialchars($email) . "' (" . (!empty($email) ? "✅" : "❌") . ")<br>";
    echo "Password: '" . htmlspecialchars($password) . "' (" . (!empty($password) ? "✅" : "❌") . ")<br>";
    echo "First Name: '" . htmlspecialchars($first) . "' (" . (!empty($first) ? "✅" : "❌") . ")<br>";
    echo "Last Name: '" . htmlspecialchars($last) . "' (" . (!empty($last) ? "✅" : "❌") . ")<br>";
    echo "Date of Birth: '" . htmlspecialchars($dob) . "' (" . (!empty($dob) ? "✅" : "❌") . ")<br>";
    echo "Barangay ID: " . $barangay_id . " (" . ($barangay_id > 0 ? "✅" : "❌") . ")<br>";
    echo "Purok ID: " . $purok_id . " (" . ($purok_id > 0 ? "✅" : "❌") . ")<br>";
    echo "Phone: '" . htmlspecialchars($phone) . "'<br>";
    echo "Address: '" . htmlspecialchars($address) . "'<br>";
    
    $allValid = $email && $password && $first && $last && $dob && $purok_id > 0 && $barangay_id > 0;
    echo "<br>All validation passes: " . ($allValid ? "✅ YES" : "❌ NO") . "<br>";
    
    if ($allValid) {
        echo "<h3>Trying to Insert into Database...</h3>";
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, date_of_birth, phone, address, barangay_id, purok_id) VALUES(?,?,?,?,?,?,?,?,?)');
            $success = $insPending->execute([$email, $hash, $first, $last, $dob, $phone, $address, $barangay_id, $purok_id]);
            
            if ($success) {
                $pendingId = (int)$pdo->lastInsertId();
                $pdo->commit();
                echo "✅ Successfully created pending registration with ID: " . $pendingId . "<br>";
                
                // Check if BHW can see it
                $stmt = db()->prepare('SELECT first_name, last_name FROM users WHERE role = "bhw" AND purok_id = ?');
                $stmt->execute([$purok_id]);
                $bhw = $stmt->fetch();
                
                if ($bhw) {
                    echo "✅ BHW can see this: " . $bhw['first_name'] . " " . $bhw['last_name'] . "<br>";
                } else {
                    echo "❌ No BHW assigned to purok ID " . $purok_id . "<br>";
                }
                
            } else {
                $pdo->rollBack();
                echo "❌ Database insertion failed<br>";
            }
            
        } catch (Exception $e) {
            if (isset($pdo)) $pdo->rollBack();
            echo "❌ Database error: " . $e->getMessage() . "<br>";
        }
    }
    
} else {
    echo "<p>This page will test registration submission. Please submit the registration form or use this test form:</p>";
    
    // Show available data
    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
    $puroks = db()->query('SELECT p.id, p.name, b.name AS barangay, p.barangay_id FROM puroks p JOIN barangays b ON b.id=p.barangay_id ORDER BY b.name, p.name')->fetchAll();
    ?>
    
    <form method="post" class="space-y-4 max-w-md">
        <h3>Test Registration Form:</h3>
        
        <div>
            <label class="block">First Name *</label>
            <input name="first_name" required class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Last Name *</label>
            <input name="last_name" required class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Email *</label>
            <input name="email" type="email" required class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Password *</label>
            <input name="password" type="password" required class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Date of Birth *</label>
            <input name="date_of_birth" type="date" required class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Phone</label>
            <input name="phone" class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Address</label>
            <input name="address" class="w-full border rounded px-3 py-2" />
        </div>
        
        <div>
            <label class="block">Barangay *</label>
            <select name="barangay_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select Barangay</option>
                <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block">Purok *</label>
            <select name="purok_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select Purok</option>
                <?php foreach ($puroks as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['barangay'] . ' - ' . $p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Test Submit</button>
    </form>
    
    <br><hr><br>
    
    <p><strong>Or try the actual registration form:</strong> <a href="register.php">register.php</a></p>
    
    <?php
}
?>
