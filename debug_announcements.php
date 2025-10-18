<?php
/**
 * Debug announcements visibility
 */

require_once 'config/db.php';

echo "<h1>Announcements Debug</h1>";

try {
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check all announcements
    echo "<h3>All Announcements in Database:</h3>";
    $allAnnouncements = $pdo->query('SELECT id, title, start_date, end_date, is_active, created_at FROM announcements ORDER BY start_date')->fetchAll();
    
    if (empty($allAnnouncements)) {
        echo "<p style='color: red;'>✗ No announcements found in database</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Title</th><th>Start Date</th><th>End Date</th><th>Active</th><th>Created</th><th>Status</th></tr>";
        
        $today = date('Y-m-d');
        foreach ($allAnnouncements as $announcement) {
            $isPast = $announcement['end_date'] < $today;
            $isActive = $announcement['is_active'] == 1;
            $isVisible = $isActive && !$isPast;
            
            $statusColor = $isVisible ? 'green' : ($isPast ? 'red' : 'orange');
            $status = $isVisible ? 'Visible' : ($isPast ? 'Past' : 'Inactive');
            
            echo "<tr>";
            echo "<td>{$announcement['id']}</td>";
            echo "<td>" . htmlspecialchars($announcement['title']) . "</td>";
            echo "<td>{$announcement['start_date']}</td>";
            echo "<td>{$announcement['end_date']}</td>";
            echo "<td>" . ($isActive ? 'Yes' : 'No') . "</td>";
            echo "<td>{$announcement['created_at']}</td>";
            echo "<td style='color: {$statusColor}; font-weight: bold;'>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check what residents will see
    echo "<h3>What Residents Will See:</h3>";
    $residentQuery = 'SELECT * FROM announcements WHERE is_active = 1 AND end_date >= CURDATE() ORDER BY start_date ASC, created_at DESC';
    $residentAnnouncements = $pdo->query($residentQuery)->fetchAll();
    
    echo "<p><strong>Query:</strong> <code>{$residentQuery}</code></p>";
    echo "<p><strong>Today's Date:</strong> " . date('Y-m-d') . "</p>";
    echo "<p><strong>Results:</strong> " . count($residentAnnouncements) . " announcements</p>";
    
    if (empty($residentAnnouncements)) {
        echo "<p style='color: red;'>✗ No announcements visible to residents</p>";
        echo "<p>This is why residents don't see any announcements!</p>";
    } else {
        echo "<ul>";
        foreach ($residentAnnouncements as $announcement) {
            echo "<li><strong>" . htmlspecialchars($announcement['title']) . "</strong> - {$announcement['start_date']} to {$announcement['end_date']}</li>";
        }
        echo "</ul>";
    }
    
    // Check what BHW will see
    echo "<h3>What BHW Will See:</h3>";
    $bhwAnnouncements = $pdo->query($residentQuery)->fetchAll(); // Same query
    echo "<p><strong>Results:</strong> " . count($bhwAnnouncements) . " announcements</p>";
    
    // Check what Super Admin will see
    echo "<h3>What Super Admin Will See:</h3>";
    $adminQuery = 'SELECT a.*, u.name as created_by_name FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.start_date DESC, a.created_at DESC';
    $adminAnnouncements = $pdo->query($adminQuery)->fetchAll();
    echo "<p><strong>Results:</strong> " . count($adminAnnouncements) . " announcements (all announcements)</p>";
    
    echo "<h3>Quick Fix:</h3>";
    echo "<p>If no announcements are visible, run this SQL to update dates:</p>";
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
    echo "UPDATE announcements SET start_date = '2025-10-20', end_date = '2025-10-20' WHERE title = 'Medical Mission - Free Check-up';\n";
    echo "UPDATE announcements SET start_date = '2025-10-25', end_date = '2025-10-27' WHERE title = 'Vaccination Drive - COVID-19 Booster';\n";
    echo "UPDATE announcements SET start_date = '2025-11-01', end_date = '2025-11-01' WHERE title = 'Community Clean-up Day';\n";
    echo "UPDATE announcements SET start_date = '2025-11-05', end_date = '2025-11-05' WHERE title = 'Health Education Seminar';\n";
    echo "UPDATE announcements SET start_date = '2025-11-10', end_date = '2025-11-12' WHERE title = 'Senior Citizen Health Program';";
    echo "</pre>";
    
    echo "<p><a href='fix_announcement_dates.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Run Fix Script</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}
?>
