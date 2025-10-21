<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user = current_user();

try {
    if (isset($input['acknowledge_all']) && $input['acknowledge_all']) {
        // Acknowledge all unacknowledged alerts
        $stmt = db()->prepare('
            UPDATE inventory_alerts 
            SET is_acknowledged = TRUE,
                acknowledged_by = ?,
                acknowledged_at = NOW()
            WHERE is_acknowledged = FALSE
        ');
        $stmt->execute([$user['id']]);
        
        $count = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully acknowledged $count alert(s)",
            'count' => $count
        ]);
        
    } elseif (isset($input['alert_id'])) {
        // Acknowledge single alert
        $alert_id = (int)$input['alert_id'];
        
        $stmt = db()->prepare('
            UPDATE inventory_alerts 
            SET is_acknowledged = TRUE,
                acknowledged_by = ?,
                acknowledged_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$user['id'], $alert_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Alert acknowledged successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Alert not found'
            ]);
        }
        
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request parameters'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

