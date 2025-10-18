<?php
/**
 * Fix announcement dates to make them visible
 */

require_once 'config/db.php';

echo "<h1>Fixing Announcement Dates</h1>";

try {
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check current announcements
    $currentAnnouncements = $pdo->query('SELECT id, title, start_date, end_date, is_active FROM announcements ORDER BY start_date')->fetchAll();
    
    echo "<h3>Current Announcements:</h3>";
    echo "<ul>";
    foreach ($currentAnnouncements as $announcement) {
        $status = $announcement['is_active'] ? 'Active' : 'Inactive';
        $statusColor = $announcement['is_active'] ? 'green' : 'gray';
        echo "<li><strong>{$announcement['title']}</strong> - {$announcement['start_date']} to {$announcement['end_date']} - <span style='color: {$statusColor};'>{$status}</span></li>";
    }
    echo "</ul>";
    
    // Update dates to current year
    $updates = [
        ['Medical Mission - Free Check-up', '2025-10-20', '2025-10-20'],
        ['Vaccination Drive - COVID-19 Booster', '2025-10-25', '2025-10-27'],
        ['Community Clean-up Day', '2025-11-01', '2025-11-01'],
        ['Health Education Seminar', '2025-11-05', '2025-11-05'],
        ['Senior Citizen Health Program', '2025-11-10', '2025-11-12']
    ];
    
    echo "<h3>Updating Announcement Dates:</h3>";
    foreach ($updates as $update) {
        $stmt = $pdo->prepare('UPDATE announcements SET start_date = ?, end_date = ? WHERE title = ?');
        $stmt->execute([$update[1], $update[2], $update[0]]);
        echo "<p style='color: blue;'>✓ Updated: {$update[0]} → {$update[1]} to {$update[2]}</p>";
    }
    
    // Add some current announcements
    $newAnnouncements = [
        ['Weekly Health Check-up', 'Regular weekly health monitoring for all residents. Blood pressure, weight, and basic health assessments available.', '2025-10-15', '2025-10-15'],
        ['Medicine Distribution Day', 'Monthly medicine distribution for residents with approved requests. Please bring your ID and request confirmation.', '2025-10-18', '2025-10-18'],
        ['Nutrition Workshop', 'Learn about healthy eating habits and meal planning for families. Free samples and recipe cards will be provided.', '2025-10-22', '2025-10-22']
    ];
    
    echo "<h3>Adding New Current Announcements:</h3>";
    foreach ($newAnnouncements as $new) {
        $stmt = $pdo->prepare('INSERT INTO announcements (title, description, start_date, end_date, created_by) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute($new);
        echo "<p style='color: green;'>✓ Added: {$new[0]} - {$new[2]} to {$new[3]}</p>";
    }
    
    // Check final results
    $finalAnnouncements = $pdo->query('SELECT COUNT(*) as count FROM announcements WHERE is_active = 1 AND end_date >= CURDATE()')->fetch();
    echo "<h3>Final Results:</h3>";
    echo "<p style='color: green; font-weight: bold;'>✓ {$finalAnnouncements['count']} active announcements are now visible to residents and BHW!</p>";
    
    echo "<h3>Test Links:</h3>";
    echo "<ul>";
    echo "<li><a href='public/resident/announcements.php' target='_blank'>Resident Announcements</a></li>";
    echo "<li><a href='public/bhw/announcements.php' target='_blank'>BHW Announcements</a></li>";
    echo "<li><a href='public/super_admin/announcements.php' target='_blank'>Super Admin Management</a></li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}
?>
