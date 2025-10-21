<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

header('Content-Type: application/json');

$medicine_id = isset($_GET['medicine_id']) ? (int)$_GET['medicine_id'] : 0;

if ($medicine_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid medicine ID'
    ]);
    exit;
}

try {
    // Get medicine name
    $medicine_stmt = db()->prepare('SELECT name FROM medicines WHERE id = ?');
    $medicine_stmt->execute([$medicine_id]);
    $medicine = $medicine_stmt->fetch();
    
    if (!$medicine) {
        echo json_encode([
            'success' => false,
            'message' => 'Medicine not found'
        ]);
        exit;
    }
    
    // Get complete transaction history from multiple sources
    
    // 1. Get inventory_transactions (manual adjustments)
    $inventory_trans_stmt = db()->prepare('
        SELECT 
            it.id,
            it.transaction_type,
            it.quantity,
            it.reference_type,
            it.reference_id,
            it.notes,
            DATE_FORMAT(it.created_at, "%b %d, %Y %H:%i") as created_at,
            it.created_at as sort_date,
            mb.batch_code as batch_number,
            CONCAT(IFNULL(u.first_name, ""), " ", IFNULL(u.last_name, "")) as performed_by,
            "inventory_adjustment" as source
        FROM inventory_transactions it
        LEFT JOIN medicine_batches mb ON it.batch_id = mb.id
        LEFT JOIN users u ON it.created_by = u.id
        WHERE it.medicine_id = ?
    ');
    $inventory_trans_stmt->execute([$medicine_id]);
    $inventory_transactions = $inventory_trans_stmt->fetchAll();
    
    // 2. Get request fulfillments (dispensing history) - one row per fulfillment
    // Check if request_fulfillments table exists
    $rf_table_check = db()->query("SHOW TABLES LIKE 'request_fulfillments'")->fetch();
    
    if (!empty($rf_table_check)) {
        // Use request_fulfillments table
        $request_trans_stmt = db()->prepare('
            SELECT 
                rf.id,
                "OUT" as transaction_type,
                -rf.quantity as quantity,
                "REQUEST_DISPENSED" as reference_type,
                r.id as reference_id,
                CONCAT(
                    "Dispensed to ",
                    CASE 
                        WHEN r.requested_for = "family" AND r.patient_name != "" THEN r.patient_name
                        WHEN r.resident_id IS NULL THEN "Walk-in Patient"
                        ELSE CONCAT(res.first_name, " ", IFNULL(CONCAT(res.middle_initial, ". "), ""), res.last_name)
                    END,
                    " (Request #", r.id, ")"
                ) as notes,
                DATE_FORMAT(rf.created_at, "%b %d, %Y %H:%i") as created_at,
                rf.created_at as sort_date,
                mb.batch_code as batch_number,
                CASE 
                    WHEN r.requested_for = "family" AND r.patient_name != "" THEN r.patient_name
                    WHEN r.resident_id IS NULL THEN "Walk-in Patient"
                    ELSE CONCAT(res.first_name, " ", res.last_name)
                END as performed_by,
                "request_fulfillment" as source
            FROM request_fulfillments rf
            JOIN requests r ON rf.request_id = r.id
            LEFT JOIN medicine_batches mb ON rf.batch_id = mb.id
            LEFT JOIN residents res ON r.resident_id = res.id
            WHERE r.medicine_id = ?
            ORDER BY rf.created_at DESC
        ');
        $request_trans_stmt->execute([$medicine_id]);
        $request_transactions = $request_trans_stmt->fetchAll();
    } else {
        // Fallback: use requests table directly - check for any dispensed/approved status
        $request_trans_stmt = db()->prepare('
            SELECT 
                r.id,
                "OUT" as transaction_type,
                -r.quantity as quantity,
                "REQUEST_DISPENSED" as reference_type,
                r.id as reference_id,
                CONCAT(
                    "Dispensed to ",
                    CASE 
                        WHEN r.resident_id IS NULL THEN "Walk-in Patient"
                        ELSE CONCAT(res.first_name, " ", IFNULL(CONCAT(res.middle_initial, ". "), ""), res.last_name)
                    END,
                    " (Request #", r.id, " - Status: ", r.status, ")"
                ) as notes,
                DATE_FORMAT(r.created_at, "%b %d, %Y %H:%i") as created_at,
                r.created_at as sort_date,
                NULL as batch_number,
                CASE 
                    WHEN r.resident_id IS NULL THEN "Walk-in Patient"
                    ELSE CONCAT(res.first_name, " ", res.last_name)
                END as performed_by,
                "request" as source
            FROM requests r
            LEFT JOIN residents res ON r.resident_id = res.id
            WHERE r.medicine_id = ? AND r.status NOT IN ("pending", "cancelled")
            ORDER BY r.created_at DESC
        ');
        $request_trans_stmt->execute([$medicine_id]);
        $request_transactions = $request_trans_stmt->fetchAll();
    }
    
    // 3. Get batch receipts (initial stock additions)
    $batch_receipts_stmt = db()->prepare('
        SELECT 
            mb.id,
            "IN" as transaction_type,
            mb.quantity as quantity,
            "BATCH_RECEIVED" as reference_type,
            mb.id as reference_id,
            CONCAT("Initial batch received: ", mb.batch_code) as notes,
            DATE_FORMAT(mb.received_at, "%b %d, %Y %H:%i") as created_at,
            mb.received_at as sort_date,
            mb.batch_code as batch_number,
            "System" as performed_by,
            "batch_receipt" as source
        FROM medicine_batches mb
        WHERE mb.medicine_id = ?
    ');
    $batch_receipts_stmt->execute([$medicine_id]);
    $batch_receipts = $batch_receipts_stmt->fetchAll();
    
    // Merge all transactions and sort by date
    $transactions = array_merge($inventory_transactions, $request_transactions, $batch_receipts);
    
    // Sort by date descending
    usort($transactions, function($a, $b) {
        return strtotime($b['sort_date']) - strtotime($a['sort_date']);
    });
    
    // Limit to 200 most recent
    $transactions = array_slice($transactions, 0, 200);
    
    // Get existing batches info with detailed tracking
    // First, check if request_fulfillments table exists
    $table_check = db()->query("SHOW TABLES LIKE 'request_fulfillments'")->fetch();
    $has_fulfillments_table = !empty($table_check);
    
    if ($has_fulfillments_table) {
        // Full query with request fulfillments
        $batches_stmt = db()->prepare('
            SELECT 
                mb.id,
                mb.batch_code,
                mb.quantity as initial_quantity,
                mb.quantity_available,
                (mb.quantity - mb.quantity_available) as quantity_dispensed,
                mb.expiry_date,
                DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry,
                DATE_FORMAT(mb.received_at, "%b %d, %Y %H:%i") as received_at,
                mb.received_at as received_timestamp,
                DATEDIFF(CURDATE(), DATE(mb.received_at)) as days_in_stock,
                CASE 
                    WHEN mb.expiry_date <= CURDATE() THEN "expired"
                    WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring_soon"
                    WHEN mb.quantity_available = 0 THEN "depleted"
                    ELSE "active"
                END as batch_status,
                CASE 
                    WHEN mb.quantity > 0 THEN ROUND((mb.quantity - mb.quantity_available) / mb.quantity * 100, 1)
                    ELSE 0
                END as usage_percentage,
                (
                    SELECT COUNT(*) 
                    FROM requests r 
                    JOIN request_fulfillments rf ON r.id = rf.request_id 
                    WHERE rf.batch_id = mb.id AND r.status = "claimed"
                ) as total_requests_fulfilled,
                (
                    SELECT MIN(DATE(r.created_at))
                    FROM requests r 
                    JOIN request_fulfillments rf ON r.id = rf.request_id 
                    WHERE rf.batch_id = mb.id
                ) as first_dispensed_date,
                (
                    SELECT MAX(DATE(r.created_at))
                    FROM requests r 
                    JOIN request_fulfillments rf ON r.id = rf.request_id 
                    WHERE rf.batch_id = mb.id
                ) as last_dispensed_date
            FROM medicine_batches mb
            WHERE mb.medicine_id = ?
            ORDER BY 
                CASE 
                    WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() THEN 1
                    WHEN mb.quantity_available > 0 AND mb.expiry_date <= CURDATE() THEN 2
                    WHEN mb.quantity_available = 0 THEN 3
                END,
                mb.expiry_date ASC
        ');
    } else {
        // Simplified query without request fulfillments
        $batches_stmt = db()->prepare('
            SELECT 
                mb.id,
                mb.batch_code,
                mb.quantity as initial_quantity,
                mb.quantity_available,
                (mb.quantity - mb.quantity_available) as quantity_dispensed,
                mb.expiry_date,
                DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry,
                DATE_FORMAT(mb.received_at, "%b %d, %Y %H:%i") as received_at,
                mb.received_at as received_timestamp,
                DATEDIFF(CURDATE(), DATE(mb.received_at)) as days_in_stock,
                CASE 
                    WHEN mb.expiry_date <= CURDATE() THEN "expired"
                    WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring_soon"
                    WHEN mb.quantity_available = 0 THEN "depleted"
                    ELSE "active"
                END as batch_status,
                CASE 
                    WHEN mb.quantity > 0 THEN ROUND((mb.quantity - mb.quantity_available) / mb.quantity * 100, 1)
                    ELSE 0
                END as usage_percentage,
                0 as total_requests_fulfilled,
                NULL as first_dispensed_date,
                NULL as last_dispensed_date
            FROM medicine_batches mb
            WHERE mb.medicine_id = ?
            ORDER BY 
                CASE 
                    WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() THEN 1
                    WHEN mb.quantity_available > 0 AND mb.expiry_date <= CURDATE() THEN 2
                    WHEN mb.quantity_available = 0 THEN 3
                END,
                mb.expiry_date ASC
        ');
    }
    
    $batches_stmt->execute([$medicine_id]);
    $batches = $batches_stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'medicine_name' => $medicine['name'],
        'medicine_id' => $medicine_id,
        'transactions' => $transactions,
        'batches' => $batches,
        'count' => count($transactions),
        'batches_count' => count($batches),
        'has_fulfillments_table' => $has_fulfillments_table,
        'has_rf_table' => !empty($rf_table_check),
        'inventory_count' => count($inventory_transactions),
        'request_count' => count($request_transactions),
        'batch_receipt_count' => count($batch_receipts),
        'debug' => 'Query executed successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

