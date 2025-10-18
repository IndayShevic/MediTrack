<?php
// Debug authentication status
require_once 'config/db.php';

echo "<h1>Authentication Debug</h1>";

// Check if session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h2>Session Information:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Check current user
$user = current_user();
echo "<h2>Current User:</h2>";
if ($user) {
    echo "<p style='color: green;'>✓ User is logged in</p>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    echo "<h3>User Role: " . htmlspecialchars($user['role']) . "</h3>";
    
    if ($user['role'] === 'super_admin') {
        echo "<p style='color: green;'>✓ User has super_admin role - can access announcements</p>";
        echo "<p><a href='public/super_admin/announcements.php'>Access Announcements</a></p>";
    } else {
        echo "<p style='color: red;'>✗ User does not have super_admin role</p>";
        echo "<p>Current role: " . htmlspecialchars($user['role']) . "</p>";
        echo "<p>You need to log in as a super_admin user to access the announcements management page.</p>";
    }
} else {
    echo "<p style='color: red;'>✗ No user logged in</p>";
    echo "<p><a href='public/login.php'>Login Here</a></p>";
}

echo "<h2>Available User Roles:</h2>";
try {
    $roles = db()->query("SELECT DISTINCT role FROM users")->fetchAll();
    echo "<ul>";
    foreach ($roles as $role) {
        echo "<li>" . htmlspecialchars($role['role']) . "</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching roles: " . $e->getMessage() . "</p>";
}

echo "<h2>Super Admin Users:</h2>";
try {
    $super_admins = db()->query("SELECT id, name, email, role FROM users WHERE role = 'super_admin'")->fetchAll();
    if (!empty($super_admins)) {
        echo "<ul>";
        foreach ($super_admins as $admin) {
            echo "<li>ID: {$admin['id']}, Name: " . htmlspecialchars($admin['name']) . ", Email: " . htmlspecialchars($admin['email']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>No super_admin users found in database</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching super admins: " . $e->getMessage() . "</p>";
}
?>
