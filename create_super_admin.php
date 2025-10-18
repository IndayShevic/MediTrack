<?php
// Script to create a super_admin user for testing
require_once 'config/db.php';

echo "<h1>Create Super Admin User</h1>";

try {
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check if super_admin users exist
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'super_admin'");
    $count = $stmt->fetch()['count'];
    
    if ($count > 0) {
        echo "<p style='color: blue;'>📊 Found {$count} super_admin user(s) in database</p>";
        
        // Show existing super admins
        $admins = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'super_admin'")->fetchAll();
        echo "<h3>Existing Super Admin Users:</h3>";
        echo "<ul>";
        foreach ($admins as $admin) {
            echo "<li>ID: {$admin['id']}, Name: " . htmlspecialchars($admin['name']) . ", Email: " . htmlspecialchars($admin['email']) . "</li>";
        }
        echo "</ul>";
        
        echo "<p><a href='public/login.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Login with existing account</a></p>";
        
    } else {
        echo "<p style='color: orange;'>⚠ No super_admin users found</p>";
        
        // Create a super_admin user
        $name = "Super Administrator";
        $email = "admin@meditrack.local";
        $password = password_hash("admin123", PASSWORD_DEFAULT);
        $role = "super_admin";
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$name, $email, $password, $role]);
        
        $userId = $pdo->lastInsertId();
        echo "<p style='color: green;'>✓ Created super_admin user with ID: {$userId}</p>";
        echo "<h3>Login Credentials:</h3>";
        echo "<ul>";
        echo "<li><strong>Email:</strong> {$email}</li>";
        echo "<li><strong>Password:</strong> admin123</li>";
        echo "<li><strong>Role:</strong> {$role}</li>";
        echo "</ul>";
        
        echo "<p><a href='public/login.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Login Now</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<h3>All Users in Database:</h3>";
try {
    $users = $pdo->query("SELECT id, name, email, role FROM users ORDER BY role, name")->fetchAll();
    if (!empty($users)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>" . htmlspecialchars($user['name']) . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td>" . htmlspecialchars($user['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No users found in database</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching users: " . $e->getMessage() . "</p>";
}
?>
