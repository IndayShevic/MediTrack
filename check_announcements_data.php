<?php
/**
 * Check announcements data in database
 */

require_once 'config/db.php';

echo "<h1>Announcements Data Check</h1>";

try {
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check if announcements table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'announcements'")->rowCount() > 0;
    echo "<p>" . ($tableExists ? "✓" : "✗") . " Announcements table exists: " . ($tableExists ? "Yes" : "No") . "</p>";
    
    if ($tableExists) {
        // Check total count
        $totalCount = $pdo->query("SELECT COUNT(*) as count FROM announcements")->fetch()['count'];
        echo "<p><strong>Total announcements in database:</strong> {$totalCount}</p>";
        
        if ($totalCount > 0) {
            // Show all announcements
            $announcements = $pdo->query("SELECT id, title, start_date, end_date, is_active, created_at FROM announcements ORDER BY created_at DESC")->fetchAll();
            
            echo "<h3>All Announcements:</h3>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Title</th><th>Start Date</th><th>End Date</th><th>Active</th><th>Created</th></tr>";
            
            foreach ($announcements as $announcement) {
                echo "<tr>";
                echo "<td>{$announcement['id']}</td>";
                echo "<td>" . htmlspecialchars($announcement['title']) . "</td>";
                echo "<td>{$announcement['start_date']}</td>";
                echo "<td>{$announcement['end_date']}</td>";
                echo "<td>" . ($announcement['is_active'] ? 'Yes' : 'No') . "</td>";
                echo "<td>{$announcement['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Check what Super Admin query returns
            $adminQuery = "SELECT a.*, CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) as created_by_name FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.start_date DESC, a.created_at DESC";
            $adminResults = $pdo->query($adminQuery)->fetchAll();
            echo "<p><strong>Super Admin query results:</strong> " . count($adminResults) . " announcements</p>";
            
        } else {
            echo "<p style='color: red;'>✗ No announcements found in database!</p>";
            echo "<p>The announcements table exists but is empty. Let's insert the sample data.</p>";
            
            // Insert sample data
            echo "<h3>Inserting Sample Data:</h3>";
            $sampleData = [
                ['Medical Mission - Free Check-up', 'Free medical check-up for all residents. Bring valid ID and medical records if available. Services include blood pressure monitoring, blood sugar testing, and general consultation.', '2025-10-20', '2025-10-20'],
                ['Vaccination Drive - COVID-19 Booster', 'COVID-19 booster vaccination drive for eligible residents. Please bring vaccination card and valid ID. Walk-in basis, first come first served.', '2025-10-25', '2025-10-27'],
                ['Community Clean-up Day', 'Join us for a community clean-up activity. All residents are encouraged to participate. Cleaning materials will be provided. Refreshments will be served.', '2025-11-01', '2025-11-01'],
                ['Health Education Seminar', 'Learn about preventive healthcare, nutrition, and healthy lifestyle practices. Open to all residents. Certificate of attendance will be provided.', '2025-11-05', '2025-11-05'],
                ['Senior Citizen Health Program', 'Special health program for senior citizens including free medicines, health monitoring, and social activities. Registration required.', '2025-11-10', '2025-11-12']
            ];
            
            foreach ($sampleData as $data) {
                $stmt = $pdo->prepare("INSERT INTO announcements (title, description, start_date, end_date, created_by) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute($data);
                echo "<p style='color: green;'>✓ Inserted: {$data[0]}</p>";
            }
            
            // Check final count
            $finalCount = $pdo->query("SELECT COUNT(*) as count FROM announcements")->fetch()['count'];
            echo "<p style='color: green; font-weight: bold;'>✓ Final count: {$finalCount} announcements</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Announcements table does not exist!</p>";
        echo "<p>Please run the database migration first.</p>";
    }
    
    echo "<h3>Test Links:</h3>";
    echo "<ul>";
    echo "<li><a href='public/super_admin/announcements.php' target='_blank'>Super Admin Announcements</a></li>";
    echo "<li><a href='public/resident/announcements.php' target='_blank'>Resident Announcements</a></li>";
    echo "<li><a href='public/bhw/announcements.php' target='_blank'>BHW Announcements</a></li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}
?>
