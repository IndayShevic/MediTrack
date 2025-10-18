<?php
// Public test page for announcements (no authentication required)
require_once 'config/db.php';

echo "<h1>MediTrack Announcements Feature - Public Test</h1>";

try {
    // Test database connection
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check if announcements table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'announcements'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Announcements table exists</p>";
        
        // Count existing announcements
        $count = $pdo->query("SELECT COUNT(*) as count FROM announcements")->fetch()['count'];
        echo "<p style='color: blue;'>📊 Found {$count} announcements in database</p>";
        
        // Show sample announcements
        $announcements = $pdo->query("SELECT title, description, start_date, end_date, is_active FROM announcements ORDER BY start_date LIMIT 5")->fetchAll();
        if (!empty($announcements)) {
            echo "<h3>Sample Announcements:</h3>";
            echo "<div style='display: grid; gap: 20px; margin: 20px 0;'>";
            foreach ($announcements as $announcement) {
                $status = $announcement['is_active'] ? 'Active' : 'Inactive';
                $statusColor = $announcement['is_active'] ? 'green' : 'gray';
                echo "<div style='border: 1px solid #ddd; padding: 15px; border-radius: 8px; background: #f9f9f9;'>";
                echo "<h4 style='margin: 0 0 10px 0; color: #333;'>{$announcement['title']}</h4>";
                echo "<p style='margin: 0 0 10px 0; color: #666;'>" . htmlspecialchars($announcement['description']) . "</p>";
                echo "<div style='display: flex; gap: 20px; font-size: 14px; color: #888;'>";
                echo "<span>📅 {$announcement['start_date']} to {$announcement['end_date']}</span>";
                echo "<span style='color: {$statusColor}; font-weight: bold;'>● {$status}</span>";
                echo "</div>";
                echo "</div>";
            }
            echo "</div>";
        }
    } else {
        echo "<p style='color: red;'>✗ Announcements table does not exist</p>";
        echo "<h3>To create the table, run this SQL in your MySQL database:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
        echo htmlspecialchars(file_get_contents('database/create_announcements_table.sql'));
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database error: " . $e->getMessage() . "</p>";
}

echo "<h3>Feature Files Status:</h3>";
$files = [
    'public/super_admin/announcements.php' => 'Super Admin Management Interface',
    'public/bhw/announcements.php' => 'BHW View Interface', 
    'public/resident/announcements.php' => 'Resident View Interface',
    'database/create_announcements_table.sql' => 'Database Schema',
    'ANNOUNCEMENTS_FEATURE.md' => 'Documentation'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✓ {$description} - {$file}</p>";
    } else {
        echo "<p style='color: red;'>✗ Missing: {$description} - {$file}</p>";
    }
}

echo "<h3>Authentication Status:</h3>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = $_SESSION['user'] ?? null;
if ($user) {
    echo "<p style='color: green;'>✓ User is logged in as: " . htmlspecialchars($user['role']) . "</p>";
    if ($user['role'] === 'super_admin') {
        echo "<p><a href='public/super_admin/announcements.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Access Super Admin Announcements</a></p>";
    } elseif ($user['role'] === 'bhw') {
        echo "<p><a href='public/bhw/announcements.php' style='background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Access BHW Announcements</a></p>";
    } elseif ($user['role'] === 'resident') {
        echo "<p><a href='public/resident/announcements.php' style='background: #8b5cf6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Access Resident Announcements</a></p>";
    }
} else {
    echo "<p style='color: red;'>✗ No user logged in</p>";
    echo "<p><a href='public/login.php' style='background: #ef4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Login Here</a></p>";
}

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>If announcements table doesn't exist, run the SQL from database/create_announcements_table.sql in your MySQL database</li>";
echo "<li>Login with appropriate user role to access the announcement interfaces</li>";
echo "<li>Super Admin: Can create, edit, delete announcements</li>";
echo "<li>BHW & Resident: Can view active announcements</li>";
echo "</ol>";

echo "<h3>Features Implemented:</h3>";
echo "<ul>";
echo "<li>✅ Database schema with proper indexing</li>";
echo "<li>✅ Super Admin CRUD operations (Create, Read, Update, Delete)</li>";
echo "<li>✅ FullCalendar.js integration for visual calendar</li>";
echo "<li>✅ Responsive design with Tailwind CSS</li>";
echo "<li>✅ Modal popups for detailed views</li>";
echo "<li>✅ Role-based access control</li>";
echo "<li>✅ Sample data for testing</li>";
echo "<li>✅ Comprehensive documentation</li>";
echo "</ul>";
?>
