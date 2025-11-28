<?php
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo "Missing ID";
    exit;
}

$medicine_id = (int)$_GET['id'];

try {
    $pdo = db();
    
    // Fetch dispensing history
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            CONCAT(res.first_name, ' ', res.last_name) as resident_name,
            rf.quantity as quantity_received,
            DATE_FORMAT(rf.created_at, '%M %d, %Y %h:%i %p') as date_dispensed,
            CONCAT(u.first_name, ' ', u.last_name) as bhw_name,
            r.reason as remarks
        FROM request_fulfillments rf
        JOIN requests r ON rf.request_id = r.id
        JOIN residents res ON r.resident_id = res.id
        LEFT JOIN users u ON r.bhw_id = u.id
        WHERE r.medicine_id = ? 
        AND r.status IN ('claimed', 'approved')
        ORDER BY rf.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$medicine_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($history)) {
        echo '<tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No dispensing history found.</td></tr>';
    } else {
        foreach ($history as $row) {
            echo '<tr class="hover:bg-gray-50">';
            echo '<td class="px-6 py-4 text-sm text-gray-900">' . htmlspecialchars($row['date_dispensed']) . '</td>';
            echo '<td class="px-6 py-4 text-sm font-medium text-gray-900">' . htmlspecialchars($row['resident_name']) . '</td>';
            echo '<td class="px-6 py-4 text-sm text-center text-gray-900">' . htmlspecialchars($row['quantity_received']) . '</td>';
            echo '<td class="px-6 py-4 text-sm text-gray-500">' . htmlspecialchars($row['bhw_name'] ?? 'N/A') . '</td>';
            echo '<td class="px-6 py-4 text-sm text-gray-500 italic">' . htmlspecialchars($row['remarks'] ?? '') . '</td>';
            echo '</tr>';
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo '<tr><td colspan="5" class="px-6 py-4 text-center text-red-500">Error loading history.</td></tr>';
}
