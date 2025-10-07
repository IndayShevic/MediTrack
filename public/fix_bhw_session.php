<?php
require_once '../config/db.php';

echo "<h2>Fix BHW Session</h2>";

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'bhw') {
    echo "❌ Please login as BHW first<br>";
    echo "<a href='login.php'>Go to login page</a>";
} else {
    $userId = $_SESSION['user']['id'];
    
    echo "🔄 Updating session for BHW user ID: " . $userId . "<br>";
    
    // Get updated user data from database
    $stmt = db()->prepare('SELECT id, email, role, first_name, last_name, purok_id FROM users WHERE id = ? AND role = "bhw" LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Update session with complete user data
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'purok_id' => $user['purok_id'],
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        ];
        
        echo "✅ Session updated successfully!<br>";
        echo "New session data:<br>";
        echo "- ID: " . $_SESSION['user']['id'] . "<br>";
        echo "- Name: " . htmlspecialchars($_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name']) . "<br>";
        echo "- Email: " . htmlspecialchars($_SESSION['user']['email']) . "<br>";
        echo "- Role: " . $_SESSION['user']['role'] . "<br>";
        echo "- Purok ID: " . ($_SESSION['user']['purok_id'] ?: 'NULL') . "<br>";
        
        // Get purok assignment details
        if ($_SESSION['user']['purok_id']) {
            $stmt = db()->prepare('SELECT p.name as purok_name, b.name as barangay_name FROM puroks p JOIN barangays b ON p.barangay_id = b.id WHERE p.id = ?');
            $stmt->execute([$_SESSION['user']['purok_id']]);
            $purokDetails = $stmt->fetch();
            
            if ($purokDetails) {
                echo "- Assignment: " . htmlspecialchars($purokDetails['barangay_name'] . ' - ' . $purokDetails['purok_name']) . "<br>";
            }
        }
        
        echo "<br><strong>🎉 Session fixed! Now try:</strong><br>";
        echo "<a href='bhw/pending_residents.php'>Go to Pending Registrations</a><br>";
        
    } else {
        echo "❌ BHW user not found in database<br>";
    }
}
?>
