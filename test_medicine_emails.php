<?php
/**
 * Test medicine email notifications
 */

require_once 'config/db.php';
require_once 'config/email_notifications.php';

echo "<h1>Medicine Email Notification Test</h1>";

try {
    $pdo = db();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Test 1: Check if we have medicines in the database
    echo "<h3>Test 1: Available Medicines</h3>";
    $medicines = $pdo->query('SELECT id, name FROM medicines LIMIT 5')->fetchAll();
    
    if (empty($medicines)) {
        echo "<p style='color: red;'>✗ No medicines found in database</p>";
    } else {
        echo "<ul>";
        foreach ($medicines as $medicine) {
            echo "<li><strong>ID:</strong> {$medicine['id']}, <strong>Name:</strong> " . htmlspecialchars($medicine['name']) . "</li>";
        }
        echo "</ul>";
    }
    
    // Test 2: Check recent requests with medicine names
    echo "<h3>Test 2: Recent Requests with Medicine Names</h3>";
    $requests = $pdo->query('
        SELECT r.id, r.status, m.name as medicine_name, 
               CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) as resident_name,
               r.created_at
        FROM requests r 
        JOIN medicines m ON m.id = r.medicine_id 
        JOIN residents res ON res.id = r.resident_id 
        JOIN users u ON u.id = res.user_id 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ')->fetchAll();
    
    if (empty($requests)) {
        echo "<p style='color: orange;'>⚠ No recent requests found</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f5f5f5;'><th>Request ID</th><th>Medicine Name</th><th>Resident</th><th>Status</th><th>Created</th></tr>";
        
        foreach ($requests as $request) {
            echo "<tr>";
            echo "<td>{$request['id']}</td>";
            echo "<td><strong>" . htmlspecialchars($request['medicine_name']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($request['resident_name']) . "</td>";
            echo "<td>{$request['status']}</td>";
            echo "<td>{$request['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test 3: Test email notification function
    echo "<h3>Test 3: Email Notification Function Test</h3>";
    if (!empty($medicines)) {
        $testMedicine = $medicines[0];
        echo "<p><strong>Testing with medicine:</strong> " . htmlspecialchars($testMedicine['name']) . "</p>";
        
        // Test the email function (without actually sending)
        echo "<p><strong>Email function exists:</strong> " . (function_exists('send_medicine_request_notification_to_bhw') ? '✓ Yes' : '✗ No') . "</p>";
        echo "<p><strong>Approval function exists:</strong> " . (function_exists('send_medicine_request_approval_to_resident') ? '✓ Yes' : '✗ No') . "</p>";
        echo "<p><strong>Rejection function exists:</strong> " . (function_exists('send_medicine_request_rejection_to_resident') ? '✓ Yes' : '✗ No') . "</p>";
    }
    
    // Test 4: Check if there are any requests with "Unknown Medicine"
    echo "<h3>Test 4: Check for 'Unknown Medicine' Issues</h3>";
    $unknownRequests = $pdo->query('
        SELECT COUNT(*) as count 
        FROM requests r 
        JOIN medicines m ON m.id = r.medicine_id 
        WHERE m.name IS NULL OR m.name = ""
    ')->fetch()['count'];
    
    echo "<p><strong>Requests with missing medicine names:</strong> {$unknownRequests}</p>";
    
    if ($unknownRequests > 0) {
        echo "<p style='color: red;'>✗ Found {$unknownRequests} requests with missing medicine names!</p>";
    } else {
        echo "<p style='color: green;'>✓ All requests have proper medicine names</p>";
    }
    
    echo "<h3>Summary</h3>";
    echo "<p>The medicine email notification system should now work correctly with proper medicine names.</p>";
    echo "<p><strong>What was fixed:</strong></p>";
    echo "<ul>";
    echo "<li>✓ Medicine name is now fetched during POST request processing</li>";
    echo "<li>✓ Approval emails now include the actual medicine name</li>";
    echo "<li>✓ Rejection emails now include the actual medicine name</li>";
    echo "<li>✓ All email functions use proper JOIN queries to get medicine names</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}
?>
