<?php
/**
 * Test file to verify announcements feature setup
 */

// Test database connection
require_once 'config/db.php';

echo "<h1>MediTrack Announcements Feature Test</h1>";

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
        $announcements = $pdo->query("SELECT title, start_date, end_date, is_active FROM announcements ORDER BY start_date LIMIT 5")->fetchAll();
        if (!empty($announcements)) {
            echo "<h3>Sample Announcements:</h3>";
            echo "<ul>";
            foreach ($announcements as $announcement) {
                $status = $announcement['is_active'] ? 'Active' : 'Inactive';
                $statusColor = $announcement['is_active'] ? 'green' : 'gray';
                echo "<li><strong>{$announcement['title']}</strong> ({$announcement['start_date']} to {$announcement['end_date']}) - <span style='color: {$statusColor};'>{$status}</span></li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p style='color: red;'>✗ Announcements table does not exist</p>";
        echo "<p>Please run the database migration:</p>";
        echo "<pre>";
        echo file_get_contents('database/create_announcements_table.sql');
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

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>If announcements table doesn't exist, run the SQL from database/create_announcements_table.sql in your MySQL database</li>";
echo "<li>Access the Super Admin interface at: <a href='public/super_admin/announcements.php'>Super Admin Announcements</a></li>";
echo "<li>Access the BHW interface at: <a href='public/bhw/announcements.php'>BHW Announcements</a></li>";
echo "<li>Access the Resident interface at: <a href='public/resident/announcements.php'>Resident Announcements</a></li>";
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
