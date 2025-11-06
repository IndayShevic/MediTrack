<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
$user = current_user();

// Get all medicines for dropdown
$medicines = [];
try {
    $stmt = db()->query('SELECT id, name FROM medicines WHERE is_active = 1 ORDER BY name');
    $medicines = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Error fetching medicines: " . $e->getMessage());
}

// Get report parameters
$selected_medicine_id = isset($_GET['medicine_id']) ? (int)$_GET['medicine_id'] : 0;
$report_type = $_GET['report_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$current_page = $page; // Alias for display purposes
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Get batches for selected medicine
$batches = [];
if ($selected_medicine_id > 0) {
    try {
        $stmt = db()->prepare('SELECT id, batch_code, expiry_date FROM medicine_batches WHERE medicine_id = ? ORDER BY expiry_date DESC');
        $stmt->execute([$selected_medicine_id]);
        $batches = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log("Error fetching batches: " . $e->getMessage());
    }
}

// Report data
$report_data = [];
$report_summary = [];
$total_records = 0;
$report_title = '';

// Check if inventory_transactions table exists
$has_inventory_transactions = false;
try {
    $check_stmt = db()->query("SHOW TABLES LIKE 'inventory_transactions'");
    $has_inventory_transactions = $check_stmt->rowCount() > 0;
} catch (Throwable $e) {
    $has_inventory_transactions = false;
}

// Debug: Check actual data in inventory_transactions
$debug_transactions = [];
if ($has_inventory_transactions) {
    try {
        $debug_stmt = db()->query("SELECT COUNT(*) as cnt FROM inventory_transactions WHERE medicine_id = " . (int)$selected_medicine_id);
        $debug_result = $debug_stmt->fetch();
        $debug_transactions['count'] = $debug_result['cnt'] ?? 0;
    } catch (Throwable $e) {
        $debug_transactions['error'] = $e->getMessage();
    }
}

if ($selected_medicine_id > 0 && !empty($report_type)) {
    $medicine_name = '';
    foreach ($medicines as $med) {
        if ($med['id'] == $selected_medicine_id) {
            $medicine_name = $med['name'];
            break;
        }
    }
    
    // Build WHERE clause
    $where_conditions = ['medicine_id = ?'];
    $params = [$selected_medicine_id];
    
    if (!empty($date_from)) {
        $where_conditions[] = 'DATE(created_at) >= ?';
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $where_conditions[] = 'DATE(created_at) <= ?';
        $params[] = $date_to;
    }
    if ($batch_id > 0) {
        $where_conditions[] = 'batch_id = ?';
        $params[] = $batch_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    try {
        switch ($report_type) {
            case 'dispensed':
                $report_title = "Advanced Dispensed Report";
                // Get dispensed transactions with purok information
                $req_where = ['r.medicine_id = ?', "r.status IN ('claimed', 'approved')"];
                $req_params = [$selected_medicine_id];
                
                if (!empty($date_from)) {
                    $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) >= ?';
                    $req_params[] = $date_from;
                }
                if (!empty($date_to)) {
                    $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) <= ?';
                    $req_params[] = $date_to;
                }
                if ($batch_id > 0) {
                    $req_where[] = 'rf.batch_id = ?';
                    $req_params[] = $batch_id;
                }
                
                $req_where_clause = implode(' AND ', $req_where);
                
                // Count total records
                $count_sql = "SELECT COUNT(DISTINCT rf.id) as cnt FROM request_fulfillments rf 
                    INNER JOIN requests r ON rf.request_id = r.id 
                    WHERE {$req_where_clause}";
                $count_stmt = db()->prepare($count_sql);
                $count_stmt->execute($req_params);
                $total_records = (int)$count_stmt->fetch()['cnt'];
                
                // Get dispensed data with purok full name and remaining stock calculation
                $sql = "SELECT 
                    rf.id,
                    DATE(COALESCE(rf.created_at, r.updated_at)) as dispense_date,
                    m.name as medicine_name,
                    mb.batch_code,
                    rf.quantity,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
                    CASE 
                        WHEN r.requested_for = 'family' AND r.patient_name != '' THEN r.patient_name
                        WHEN r.resident_id IS NOT NULL THEN CONCAT(COALESCE(res.first_name, ''), ' ', COALESCE(res.last_name, ''))
                        ELSE 'Walk-in Patient'
                    END as patient_name,
                    r.reason as remarks,
                    -- Get purok full name (Purok X, BarangayName)
                    CONCAT(COALESCE(pr.name, ''), ', ', COALESCE(bg.name, '')) as purok_full_name,
                    res.purok_id,
                    u.purok_id as staff_purok_id,
                    -- Calculate remaining stock after each transaction
                    (SELECT COALESCE(SUM(mb2.quantity_available), 0) FROM medicine_batches mb2 
                     WHERE mb2.medicine_id = ? AND mb2.expiry_date > CURDATE() 
                     AND mb2.created_at <= rf.created_at) as remaining_stock
                FROM request_fulfillments rf
                INNER JOIN requests r ON rf.request_id = r.id
                INNER JOIN medicines m ON r.medicine_id = m.id
                LEFT JOIN medicine_batches mb ON rf.batch_id = mb.id
                LEFT JOIN users u ON r.bhw_id = u.id
                LEFT JOIN residents res ON r.resident_id = res.id
                LEFT JOIN puroks pr ON COALESCE(res.purok_id, u.purok_id) = pr.id
                LEFT JOIN barangays bg ON COALESCE(res.barangay_id, (SELECT barangay_id FROM puroks WHERE id = pr.id)) = bg.id
                WHERE {$req_where_clause}
                ORDER BY rf.created_at ASC, rf.id ASC
                LIMIT {$per_page} OFFSET {$offset}";
                
                $stmt = db()->prepare($sql);
                $stmt->execute(array_merge($req_params, [$selected_medicine_id]));
                $report_data = $stmt->fetchAll() ?: [];
                
                // Calculate actual remaining stock more accurately
                // Get initial stock before date range
                $initial_stock = 0;
                if (!empty($date_from)) {
                    $initial_stock_sql = "SELECT COALESCE(SUM(mb.quantity_available), 0) - COALESCE(SUM(rf.quantity), 0) as initial_stock 
                        FROM medicine_batches mb
                        LEFT JOIN request_fulfillments rf ON rf.batch_id = mb.id
                        LEFT JOIN requests r ON rf.request_id = r.id
                        WHERE mb.medicine_id = ? 
                        AND mb.created_at < ?
                        AND mb.expiry_date > CURDATE()
                        AND (rf.created_at IS NULL OR rf.created_at < ?)";
                    $initial_stmt = db()->prepare($initial_stock_sql);
                    $initial_stmt->execute([$selected_medicine_id, $date_from, $date_from]);
                    $initial_stock = (int)$initial_stmt->fetch()['initial_stock'];
                } else {
                    // Get all batches received before the first dispense
                    $all_batches_sql = "SELECT COALESCE(SUM(quantity), 0) as total FROM medicine_batches WHERE medicine_id = ? AND expiry_date > CURDATE()";
                    $all_batches_stmt = db()->prepare($all_batches_sql);
                    $all_batches_stmt->execute([$selected_medicine_id]);
                    $initial_stock = (int)$all_batches_stmt->fetch()['total'];
                }
                
                // Calculate remaining stock for each row
                $current_stock = $initial_stock;
                foreach ($report_data as &$row) {
                    // Get batches received up to this dispense date
                    $batches_received_sql = "SELECT COALESCE(SUM(quantity), 0) as total 
                        FROM medicine_batches 
                        WHERE medicine_id = ? AND DATE(created_at) <= ? AND expiry_date > CURDATE()";
                    $batches_stmt = db()->prepare($batches_received_sql);
                    $batches_stmt->execute([$selected_medicine_id, $row['dispense_date']]);
                    $total_received = (int)$batches_stmt->fetch()['total'];
                    
                    // Get total dispensed up to this date (exclusive)
                    $dispensed_sql = "SELECT COALESCE(SUM(rf.quantity), 0) as total 
                        FROM request_fulfillments rf
                        INNER JOIN requests r ON rf.request_id = r.id
                        WHERE r.medicine_id = ? AND DATE(COALESCE(rf.created_at, r.updated_at)) < ?";
                    $dispensed_stmt = db()->prepare($dispensed_sql);
                    $dispensed_stmt->execute([$selected_medicine_id, $row['dispense_date']]);
                    $total_dispensed_before = (int)$dispensed_stmt->fetch()['total'];
                    
                    // Current stock = received - dispensed before this transaction
                    $row['remaining_stock'] = max(0, $total_received - $total_dispensed_before - (int)$row['quantity']);
                }
                
                // Summary
                $summary_sql = "SELECT SUM(rf.quantity) as total_dispensed FROM request_fulfillments rf 
                    INNER JOIN requests r ON rf.request_id = r.id 
                    WHERE {$req_where_clause}";
                $summary_stmt = db()->prepare($summary_sql);
                $summary_stmt->execute($req_params);
                $summary_result = $summary_stmt->fetch();
                $total_dispensed = (int)($summary_result['total_dispensed'] ?? 0);
                
                // Get final remaining stock
                $final_stock_sql = "SELECT COALESCE(SUM(quantity_available), 0) as final_stock 
                    FROM medicine_batches 
                    WHERE medicine_id = ? AND expiry_date > CURDATE()";
                $final_stmt = db()->prepare($final_stock_sql);
                $final_stmt->execute([$selected_medicine_id]);
                $final_stock = (int)$final_stmt->fetch()['final_stock'];
                
                $report_summary = [
                    'total_dispensed' => $total_dispensed,
                    'final_stock' => $final_stock
                ];
                break;
                
            case 'remaining_stocks':
                $report_title = "Remaining Stocks Report - {$medicine_name}";
                // Current stock summary
                $sql = "SELECT 
                    mb.id,
                    mb.batch_code,
                    mb.quantity as total_received,
                    mb.quantity_available as current_stock,
                    (mb.quantity - mb.quantity_available) as total_dispensed,
                    mb.expiry_date,
                    CASE WHEN mb.expiry_date <= CURDATE() THEN mb.quantity_available ELSE 0 END as expired,
                    CASE WHEN mb.expiry_date <= CURDATE() THEN 1 ELSE 0 END as is_expired
                FROM medicine_batches mb
                WHERE mb.medicine_id = ?";
                
                $batch_params = [$selected_medicine_id];
                if ($batch_id > 0) {
                    $sql .= " AND mb.id = ?";
                    $batch_params[] = $batch_id;
                }
                $sql .= " ORDER BY mb.expiry_date ASC";
                
                $stmt = db()->prepare($sql);
                $stmt->execute($batch_params);
                $report_data = $stmt->fetchAll() ?: [];
                $total_records = count($report_data);
                
                // Calculate summary
                $total_stock = 0;
                $total_received = 0;
                $total_dispensed = 0;
                $total_expired = 0;
                $next_expiry = null;
                
                foreach ($report_data as $row) {
                    $total_stock += (int)$row['current_stock'];
                    $total_received += (int)$row['total_received'];
                    $total_dispensed += (int)$row['total_dispensed'];
                    $total_expired += (int)$row['expired'];
                    if (!$row['is_expired'] && $row['current_stock'] > 0 && (!$next_expiry || $row['expiry_date'] < $next_expiry)) {
                        $next_expiry = $row['expiry_date'];
                    }
                }
                
                $report_summary = [
                    'total_stock' => $total_stock,
                    'total_received' => $total_received,
                    'total_dispensed' => $total_dispensed,
                    'total_expired' => $total_expired,
                    'next_expiry' => $next_expiry
                ];
                break;
                
            case 'expiry':
                $report_title = "Expiry Report - {$medicine_name}";
                // Batches expiring within 2 months
                $sql = "SELECT 
                    mb.id,
                    mb.batch_code,
                    mb.quantity_available as batch_quantity,
                    mb.expiry_date,
                    DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry
                FROM medicine_batches mb
                WHERE mb.medicine_id = ? 
                AND mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                AND mb.quantity_available > 0";
                
                $batch_params = [$selected_medicine_id];
                if ($batch_id > 0) {
                    $sql .= " AND mb.id = ?";
                    $batch_params[] = $batch_id;
                }
                $sql .= " ORDER BY mb.expiry_date ASC";
                
                $stmt = db()->prepare($sql);
                $stmt->execute($batch_params);
                $report_data = $stmt->fetchAll() ?: [];
                $total_records = count($report_data);
                break;
                
            case 'restocking':
                $report_title = "Restocking History Report - {$medicine_name}";
                // Get IN transactions (restocking) - use inventory_transactions if available, otherwise use medicine_batches
                if ($has_inventory_transactions) {
                    $count_sql = "SELECT COUNT(*) as cnt FROM inventory_transactions WHERE transaction_type = 'IN' AND reference_type = 'BATCH_RECEIVED' AND {$where_clause}";
                    $count_stmt = db()->prepare($count_sql);
                    $count_stmt->execute($params);
                    $total_records = (int)$count_stmt->fetch()['cnt'];
                    
                    $sql = "SELECT 
                        it.id,
                        mb.batch_code,
                        it.quantity as added_quantity,
                        mb.received_at as date_received,
                        it.created_at as transaction_date,
                        CONCAT(u.first_name, ' ', u.last_name) as staff_name,
                        'Batch Received' as source
                    FROM inventory_transactions it
                    LEFT JOIN medicine_batches mb ON it.batch_id = mb.id
                    LEFT JOIN users u ON it.created_by = u.id
                    WHERE it.transaction_type = 'IN' AND it.reference_type = 'BATCH_RECEIVED' AND {$where_clause}
                    ORDER BY it.created_at DESC
                    LIMIT {$per_page} OFFSET {$offset}";
                    
                    $stmt = db()->prepare($sql);
                    $stmt->execute($params);
                    $report_data = $stmt->fetchAll() ?: [];
                    
                    // Summary
                    $summary_sql = "SELECT SUM(quantity) as total_received FROM inventory_transactions WHERE transaction_type = 'IN' AND reference_type = 'BATCH_RECEIVED' AND {$where_clause}";
                    $summary_stmt = db()->prepare($summary_sql);
                    $summary_stmt->execute($params);
                    $report_summary = $summary_stmt->fetch() ?: [];
                } else {
                    // Fallback: Use medicine_batches directly
                    $batch_where = ['mb.medicine_id = ?'];
                    $batch_params = [$selected_medicine_id];
                    
                    if (!empty($date_from)) {
                        $batch_where[] = 'DATE(mb.received_at) >= ?';
                        $batch_params[] = $date_from;
                    }
                    if (!empty($date_to)) {
                        $batch_where[] = 'DATE(mb.received_at) <= ?';
                        $batch_params[] = $date_to;
                    }
                    if ($batch_id > 0) {
                        $batch_where[] = 'mb.id = ?';
                        $batch_params[] = $batch_id;
                    }
                    
                    $batch_where_clause = implode(' AND ', $batch_where);
                    
                    $count_sql = "SELECT COUNT(*) as cnt FROM medicine_batches mb WHERE {$batch_where_clause}";
                    $count_stmt = db()->prepare($count_sql);
                    $count_stmt->execute($batch_params);
                    $total_records = (int)$count_stmt->fetch()['cnt'];
                    
                    $sql = "SELECT 
                        mb.id,
                        mb.batch_code,
                        mb.quantity as added_quantity,
                        mb.received_at as date_received,
                        mb.created_at as transaction_date,
                        'System' as staff_name,
                        'Batch Received' as source
                    FROM medicine_batches mb
                    WHERE {$batch_where_clause}
                    ORDER BY mb.created_at DESC
                    LIMIT {$per_page} OFFSET {$offset}";
                    
                    $stmt = db()->prepare($sql);
                    $stmt->execute($batch_params);
                    $report_data = $stmt->fetchAll() ?: [];
                    
                    // Summary
                    $summary_sql = "SELECT SUM(mb.quantity) as total_received FROM medicine_batches mb WHERE {$batch_where_clause}";
                    $summary_stmt = db()->prepare($summary_sql);
                    $summary_stmt->execute($batch_params);
                    $report_summary = $summary_stmt->fetch() ?: [];
                }
                break;
                
            case 'low_stock':
                $report_title = "Low Stock Alerts Report - {$medicine_name}";
                // Check if medicine has minimum_stock_level column
                $check_col = db()->query("SHOW COLUMNS FROM medicines LIKE 'minimum_stock_level'");
                $has_min_level = $check_col->rowCount() > 0;
                
                $sql = "SELECT 
                    mb.id,
                    mb.batch_code,
                    mb.quantity_available as current_stock,
                    mb.expiry_date,
                    CASE WHEN mb.quantity_available <= 10 THEN 1 ELSE 0 END as is_low_stock";
                
                if ($has_inventory_transactions) {
                    $sql .= ",
                    (SELECT MAX(created_at) FROM inventory_transactions WHERE medicine_id = mb.medicine_id AND batch_id = mb.id AND transaction_type = 'OUT' ORDER BY created_at DESC LIMIT 1) as last_dispensed_date";
                } else {
                    $sql .= ",
                    (SELECT MAX(r.updated_at) FROM request_fulfillments rf 
                     INNER JOIN requests r ON rf.request_id = r.id 
                     WHERE rf.batch_id = mb.id AND r.status IN ('claimed', 'approved') 
                     LIMIT 1) as last_dispensed_date";
                }
                
                $sql .= " FROM medicine_batches mb
                WHERE mb.medicine_id = ? 
                AND mb.quantity_available <= 10
                AND mb.expiry_date > CURDATE()";
                
                $batch_params = [$selected_medicine_id];
                if ($batch_id > 0) {
                    $sql .= " AND mb.id = ?";
                    $batch_params[] = $batch_id;
                }
                $sql .= " ORDER BY mb.quantity_available ASC";
                
                $stmt = db()->prepare($sql);
                $stmt->execute($batch_params);
                $report_data = $stmt->fetchAll() ?: [];
                $total_records = count($report_data);
                break;
                
            case 'activity_logs':
                $report_title = "Activity Logs Report - {$medicine_name}";
                // Combine ALL transaction sources: inventory_transactions, request_fulfillments, and medicine_batches
                
                // Build WHERE clauses for each source
                $batch_where = ['mb.medicine_id = ?'];
                $batch_params = [$selected_medicine_id];
                
                $req_where = ['r.medicine_id = ?', "r.status IN ('claimed', 'approved')"];
                $req_params = [$selected_medicine_id];
                
                $inv_where = ['it.medicine_id = ?'];
                $inv_params = [$selected_medicine_id];
                
                // Apply date filters
                if (!empty($date_from)) {
                    $batch_where[] = 'DATE(mb.created_at) >= ?';
                    $batch_params[] = $date_from;
                    $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) >= ?';
                    $req_params[] = $date_from;
                    $inv_where[] = 'DATE(it.created_at) >= ?';
                    $inv_params[] = $date_from;
                }
                if (!empty($date_to)) {
                    $batch_where[] = 'DATE(mb.created_at) <= ?';
                    $batch_params[] = $date_to;
                    $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) <= ?';
                    $req_params[] = $date_to;
                    $inv_where[] = 'DATE(it.created_at) <= ?';
                    $inv_params[] = $date_to;
                }
                
                // Apply batch filter
                if ($batch_id > 0) {
                    $batch_where[] = 'mb.id = ?';
                    $batch_params[] = $batch_id;
                    $req_where[] = 'rf.batch_id = ?';
                    $req_params[] = $batch_id;
                    $inv_where[] = 'it.batch_id = ?';
                    $inv_params[] = $batch_id;
                }
                
                $batch_where_clause = implode(' AND ', $batch_where);
                $req_where_clause = implode(' AND ', $req_where);
                $inv_where_clause = implode(' AND ', $inv_where);
                
                // Build UNION query combining all sources
                $union_parts = [];
                $union_params = [];
                
                // 1. Batch receipts (IN transactions)
                $union_parts[] = "SELECT 
                    mb.id as id,
                    'IN' as transaction_type,
                    'BATCH_RECEIVED' as reference_type,
                    mb.quantity as quantity,
                    mb.batch_code,
                    'System' as staff_name,
                    CONCAT('Initial batch received: ', mb.batch_code) as action_description,
                    mb.created_at as action_date
                FROM medicine_batches mb
                WHERE {$batch_where_clause}";
                $union_params = array_merge($union_params, $batch_params);
                
                // 2. Request fulfillments (OUT transactions)
                $union_parts[] = "SELECT 
                    rf.id as id,
                    'OUT' as transaction_type,
                    'REQUEST_DISPENSED' as reference_type,
                    rf.quantity as quantity,
                    mb2.batch_code,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
                    CONCAT('Dispensed to ', COALESCE(r.patient_name, CONCAT(COALESCE(res.first_name, ''), ' ', COALESCE(res.last_name, ''))), ' (Request #', r.id, ')') as action_description,
                    COALESCE(rf.created_at, r.updated_at) as action_date
                FROM request_fulfillments rf
                INNER JOIN requests r ON rf.request_id = r.id
                LEFT JOIN medicine_batches mb2 ON rf.batch_id = mb2.id
                LEFT JOIN residents res ON r.resident_id = res.id
                LEFT JOIN users u ON r.bhw_id = u.id
                WHERE {$req_where_clause}";
                $union_params = array_merge($union_params, $req_params);
                
                // 3. Inventory transactions (if table exists)
                if ($has_inventory_transactions) {
                    $union_parts[] = "SELECT 
                        it.id,
                        it.transaction_type,
                        it.reference_type,
                        ABS(it.quantity) as quantity,
                        mb3.batch_code,
                        CONCAT(COALESCE(u2.first_name, ''), ' ', COALESCE(u2.last_name, '')) as staff_name,
                        COALESCE(it.notes, CONCAT(it.transaction_type, ' transaction')) as action_description,
                        it.created_at as action_date
                    FROM inventory_transactions it
                    LEFT JOIN medicine_batches mb3 ON it.batch_id = mb3.id
                    LEFT JOIN users u2 ON it.created_by = u2.id
                    WHERE {$inv_where_clause}";
                    $union_params = array_merge($union_params, $inv_params);
                }
                
                // Combine all parts with UNION ALL
                $sql = "(" . implode(") UNION ALL (", $union_parts) . ") ORDER BY action_date DESC LIMIT {$per_page} OFFSET {$offset}";
                
                // Count total records from all sources
                $count_parts = [];
                $count_params = [];
                
                $count_parts[] = "SELECT COUNT(*) as cnt FROM medicine_batches mb WHERE {$batch_where_clause}";
                $count_params = array_merge($count_params, $batch_params);
                
                $count_parts[] = "SELECT COUNT(*) as cnt FROM request_fulfillments rf 
                                 INNER JOIN requests r ON rf.request_id = r.id 
                                 WHERE {$req_where_clause}";
                $count_params = array_merge($count_params, $req_params);
                
                if ($has_inventory_transactions) {
                    $count_parts[] = "SELECT COUNT(*) as cnt FROM inventory_transactions it WHERE {$inv_where_clause}";
                    $count_params = array_merge($count_params, $inv_params);
                }
                
                try {
                    // Execute count queries
                    $total_records = 0;
                    $param_offset = 0;
                    foreach ($count_parts as $count_sql) {
                        $param_count = substr_count($count_sql, '?');
                        $stmt = db()->prepare($count_sql);
                        $stmt->execute(array_slice($count_params, $param_offset, $param_count));
                        $result = $stmt->fetch();
                        $total_records += (int)($result['cnt'] ?? 0);
                        $param_offset += $param_count;
                    }
                    
                    // Execute main query
                    $stmt = db()->prepare($sql);
                    $stmt->execute($union_params);
                    $report_data = $stmt->fetchAll() ?: [];
                    
                } catch (Throwable $e) {
                    error_log("Activity Logs Query Error: " . $e->getMessage());
                    error_log("SQL: " . $sql);
                    error_log("Params: " . json_encode($union_params));
                    $report_data = [];
                    $total_records = 0;
                }
                break;
                
            case 'patient_requests':
                $report_title = "Patient Requests Report - {$medicine_name}";
                // Get requests for this medicine
                $count_sql = "SELECT COUNT(*) as cnt FROM requests r WHERE r.medicine_id = ?";
                $count_params = [$selected_medicine_id];
                if (!empty($date_from)) {
                    $count_sql .= " AND DATE(r.created_at) >= ?";
                    $count_params[] = $date_from;
                }
                if (!empty($date_to)) {
                    $count_sql .= " AND DATE(r.created_at) <= ?";
                    $count_params[] = $date_to;
                }
                
                $count_stmt = db()->prepare($count_sql);
                $count_stmt->execute($count_params);
                $total_records = (int)$count_stmt->fetch()['cnt'];
                
                $sql = "SELECT 
                    r.id,
                    CONCAT(res.first_name, ' ', res.last_name) as resident_name,
                    r.requested_for,
                    r.patient_name as requested_for_name,
                    CASE 
                        WHEN r.requested_for = 'self' THEN CONCAT(res.first_name, ' ', res.last_name)
                        ELSE r.patient_name
                    END as patient_name,
                    (SELECT SUM(quantity) FROM request_fulfillments WHERE request_id = r.id) as requested_qty,
                    r.status,
                    r.created_at as request_date,
                    CONCAT(u.first_name, ' ', u.last_name) as reviewed_by
                FROM requests r
                LEFT JOIN residents res ON r.resident_id = res.id
                LEFT JOIN users u ON r.bhw_id = u.id
                WHERE r.medicine_id = ?";
                
                $req_params = [$selected_medicine_id];
                if (!empty($date_from)) {
                    $sql .= " AND DATE(r.created_at) >= ?";
                    $req_params[] = $date_from;
                }
                if (!empty($date_to)) {
                    $sql .= " AND DATE(r.created_at) <= ?";
                    $req_params[] = $date_to;
                }
                $sql .= " ORDER BY r.created_at DESC LIMIT {$per_page} OFFSET {$offset}";
                
                $stmt = db()->prepare($sql);
                $stmt->execute($req_params);
                $report_data = $stmt->fetchAll() ?: [];
                break;
        }
    } catch (Throwable $e) {
        error_log("Error generating report: " . $e->getMessage());
        $report_data = [];
    }
}

$total_pages = ceil($total_records / $per_page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reports · Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <style>
        .content-header {
            position: sticky !important;
            top: 0 !important;
            z-index: 50 !important;
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            padding: 2rem !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 2rem !important;
        }
        /* Report Document Styles */
        .report-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 40px 50px;
            background: white;
        }
        
        .report-header {
            margin-bottom: 40px;
        }
        
        .report-section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }
        
        .report-footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 11px;
            color: #6b7280;
        }
        
        @media print {
            .no-print { display: none !important; }
            .content-header { position: static !important; }
            body { background: white !important; margin: 0; padding: 0; }
            .sidebar { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
            .report-container { padding: 20px 30px !important; box-shadow: none !important; }
            .bg-white { background: white !important; }
            .shadow-lg { box-shadow: none !important; }
            table { page-break-inside: auto !important; border-collapse: collapse !important; width: 100% !important; }
            tr { page-break-inside: avoid !important; page-break-after: auto !important; }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            @page { 
                margin: 2cm 1.5cm;
                size: A4;
            }
            .report-section {
                page-break-inside: avoid;
            }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'sans': ['Inter','system-ui','sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg" alt="Logo" />
            <?php else: ?>
                <div class="h-8 w-8 bg-white/20 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                </div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo htmlspecialchars(base_url('super_admin/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"/></svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/categories.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                </svg>
                Categories
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                Batches
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
                Inventory
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
                Users
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/analytics.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Analytics
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/reports.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Reports
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Brand Settings
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/locations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Barangays & Puroks
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/email_logs.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Email Logs
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Reports</h1>
                    <p class="text-gray-600 mt-1">Generate and export detailed reports for your medicine inventory</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content-body">
            <!-- Report Filters -->
            <div class="bg-white rounded-lg shadow-lg border border-gray-200 p-6 mb-6">
                <div class="mb-4 pb-3 border-b border-gray-300">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        Report Filters
                    </h2>
                    <p class="text-sm text-gray-600 mt-1">Configure your report parameters below</p>
                </div>
                
                <form method="GET" action="" id="report-form" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4" onsubmit="return validateReportForm()">
                    <!-- Medicine Dropdown -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Medicine <span class="text-red-500">*</span>
                        </label>
                        <select name="medicine_id" id="medicine-select" class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm font-medium" required>
                            <option value="">-- Select Medicine --</option>
                            <?php foreach ($medicines as $med): ?>
                                <option value="<?php echo $med['id']; ?>" <?php echo $selected_medicine_id == $med['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($med['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Report Type Dropdown -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Report Type <span class="text-red-500">*</span>
                        </label>
                        <select name="report_type" id="report-type-select" class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm font-medium <?php echo $selected_medicine_id == 0 ? 'bg-gray-100 cursor-not-allowed' : ''; ?>" <?php echo $selected_medicine_id == 0 ? 'disabled' : ''; ?> required>
                            <option value="">-- Select Type --</option>
                            <option value="dispensed" <?php echo $report_type == 'dispensed' ? 'selected' : ''; ?>>Dispensed Report</option>
                            <option value="remaining_stocks" <?php echo $report_type == 'remaining_stocks' ? 'selected' : ''; ?>>Remaining Stocks</option>
                            <option value="expiry" <?php echo $report_type == 'expiry' ? 'selected' : ''; ?>>Expiry Report</option>
                            <option value="restocking" <?php echo $report_type == 'restocking' ? 'selected' : ''; ?>>Restocking History</option>
                            <option value="low_stock" <?php echo $report_type == 'low_stock' ? 'selected' : ''; ?>>Low Stock Alerts</option>
                            <option value="activity_logs" <?php echo $report_type == 'activity_logs' ? 'selected' : ''; ?>>Activity Logs</option>
                            <option value="patient_requests" <?php echo $report_type == 'patient_requests' ? 'selected' : ''; ?>>Patient Requests</option>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Date From
                        </label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm font-medium">
                    </div>

                    <!-- Date To -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Date To
                        </label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm font-medium">
                    </div>

                    <!-- Batch Filter (conditional) -->
                    <div id="batch-select-container">
                        <?php if ($selected_medicine_id > 0 && count($batches) > 0): ?>
                        <div>
                            <label class="block text-sm font-semibold text-gray-900 mb-2">
                                Batch <span class="text-xs font-normal text-gray-500">(Optional)</span>
                            </label>
                            <select name="batch_id" class="w-full px-4 py-2.5 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors text-sm font-medium">
                                <option value="0">-- All Batches --</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?php echo $batch['id']; ?>" <?php echo $batch_id == $batch['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch['batch_code'] . ' (Exp: ' . date('M d, Y', strtotime($batch['expiry_date'])) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-2.5 rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all duration-200 font-semibold shadow-md hover:shadow-lg flex items-center justify-center space-x-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Generate Report</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Results -->
            <?php if ($selected_medicine_id > 0 && !empty($report_type)): ?>
            <div class="bg-white report-container">
                <!-- Report Header - PDF Style -->
                <?php if ($report_type === 'dispensed'): ?>
                <div class="report-header text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($report_title); ?></h1>
                    <p class="text-sm text-gray-700 font-medium"><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?> - Medicine Inventory Management System</p>
                </div>
                
                <!-- Export Buttons -->
                <?php if (!empty($report_data) || !empty($report_summary)): ?>
                <div class="flex justify-end space-x-2 mb-6 no-print">
                    <button onclick="exportPDF()" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        <span>Export PDF</span>
                    </button>
                    <button onclick="exportCSV()" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Export CSV</span>
                    </button>
                    <button onclick="window.print()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        <span>Print</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Report Summary Section -->
                <?php if (!empty($report_summary)): ?>
                <div class="report-section mb-6">
                    <h2 class="text-lg font-bold text-gray-900 mb-3">Report Summary</h2>
                    <div class="space-y-1 text-sm text-gray-900">
                        <p><strong>Total Quantity Dispensed:</strong> <?php echo number_format($report_summary['total_dispensed'] ?? 0); ?></p>
                        <p><strong>Final Remaining Stock:</strong> <?php echo number_format($report_summary['final_stock'] ?? 0); ?></p>
                        <?php if (!empty($date_from) || !empty($date_to)): ?>
                        <p><strong>Report Coverage:</strong> 
                            <?php echo $date_from ? date('F d', strtotime($date_from)) : 'Start'; ?> 
                            <?php echo $date_to ? '-' . date('d, Y', strtotime($date_to)) : ''; ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <!-- Default Report Header for other report types (if not dispensed) -->
                <?php if ($report_type !== 'dispensed'): ?>
                <div class="report-header border-b-2 border-gray-800 pb-8 mb-8">
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex-1">
                            <div class="mb-4">
                                <h1 class="text-4xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars(strtoupper($report_title)); ?></h1>
                                <div class="h-1 w-24 bg-blue-600"></div>
                            </div>
                            <div class="text-sm text-gray-700 space-y-1">
                                <p><strong>Medicine:</strong> <?php echo htmlspecialchars($medicine_name); ?></p>
                                <?php if (!empty($date_from) || !empty($date_to)): ?>
                                <p><strong>Period:</strong> 
                                    <?php echo $date_from ? date('F d, Y', strtotime($date_from)) : 'Start'; ?> 
                                    <?php echo $date_to ? ' to ' . date('F d, Y', strtotime($date_to)) : ''; ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($batch_id > 0): ?>
                                <p><strong>Batch:</strong> <?php 
                                    foreach ($batches as $b) {
                                        if ($b['id'] == $batch_id) {
                                            echo htmlspecialchars($b['batch_code']);
                                            break;
                                        }
                                    }
                                ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="mb-4">
                                <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?></p>
                                <p class="text-xs text-gray-600">Medicine Inventory Management System</p>
                            </div>
                            <div class="text-xs text-gray-600 border-t pt-2 mt-2">
                                <p><strong>Report Generated:</strong></p>
                                <p><?php echo date('F d, Y'); ?></p>
                                <p><?php echo date('h:i A'); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Export Buttons -->
                    <?php if (!empty($report_data) || !empty($report_summary)): ?>
                    <div class="flex space-x-2 no-print">
                        <button onclick="exportPDF()" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                            <span>Export PDF</span>
                        </button>
                        <button onclick="exportCSV()" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Export CSV</span>
                        </button>
                        <button onclick="window.print()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition-colors duration-200 flex items-center space-x-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                            </svg>
                            <span>Print</span>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Executive Summary -->
                <?php if (!empty($report_summary) && $report_type !== 'dispensed'): ?>
                <div class="report-section mb-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b-2 border-gray-300">EXECUTIVE SUMMARY</h2>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                        <?php if (isset($report_summary['total_dispensed'])): ?>
                            <div class="text-center">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total Dispensed</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo number_format($report_summary['total_dispensed'] ?? 0); ?></p>
                                <p class="text-xs text-gray-500 mt-1">units</p>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($report_summary['total_stock'])): ?>
                            <div class="text-center">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Current Stock</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo number_format($report_summary['total_stock'] ?? 0); ?></p>
                                <p class="text-xs text-gray-500 mt-1">units</p>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($report_summary['total_received'])): ?>
                            <div class="text-center">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total Received</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo number_format($report_summary['total_received'] ?? 0); ?></p>
                                <p class="text-xs text-gray-500 mt-1">units</p>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($report_summary['total_expired'])): ?>
                            <div class="text-center">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total Expired</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo number_format($report_summary['total_expired'] ?? 0); ?></p>
                                <p class="text-xs text-gray-500 mt-1">units</p>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($report_summary['next_expiry'])): ?>
                            <div class="text-center">
                                <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Next Expiry Date</p>
                                <p class="text-xl font-bold text-gray-900"><?php echo date('M d, Y', strtotime($report_summary['next_expiry'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Transaction Summary for Activity Logs -->
                <?php if ($report_type === 'activity_logs'): ?>
                <?php
                    // Calculate totals from all records
                    $total_in = 0;
                    $total_out = 0;
                    try {
                        $batch_where = ['mb.medicine_id = ?'];
                        $batch_params = [$selected_medicine_id];
                        
                        $req_where = ['r.medicine_id = ?', "r.status IN ('claimed', 'approved')"];
                        $req_params = [$selected_medicine_id];
                        
                        $inv_where = ['it.medicine_id = ?'];
                        $inv_params = [$selected_medicine_id];
                        
                        if (!empty($date_from)) {
                            $batch_where[] = 'DATE(mb.created_at) >= ?';
                            $batch_params[] = $date_from;
                            $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) >= ?';
                            $req_params[] = $date_from;
                            $inv_where[] = 'DATE(it.created_at) >= ?';
                            $inv_params[] = $date_from;
                        }
                        if (!empty($date_to)) {
                            $batch_where[] = 'DATE(mb.created_at) <= ?';
                            $batch_params[] = $date_to;
                            $req_where[] = 'DATE(COALESCE(rf.created_at, r.updated_at)) <= ?';
                            $req_params[] = $date_to;
                            $inv_where[] = 'DATE(it.created_at) <= ?';
                            $inv_params[] = $date_to;
                        }
                        if ($batch_id > 0) {
                            $batch_where[] = 'mb.id = ?';
                            $batch_params[] = $batch_id;
                            $req_where[] = 'rf.batch_id = ?';
                            $req_params[] = $batch_id;
                            $inv_where[] = 'it.batch_id = ?';
                            $inv_params[] = $batch_id;
                        }
                        
                        $batch_where_clause = implode(' AND ', $batch_where);
                        $req_where_clause = implode(' AND ', $req_where);
                        $inv_where_clause = implode(' AND ', $inv_where);
                        
                        $in_sql = "SELECT COALESCE(SUM(mb.quantity), 0) as total FROM medicine_batches mb WHERE {$batch_where_clause}";
                        $stmt = db()->prepare($in_sql);
                        $stmt->execute($batch_params);
                        $total_in = (int)$stmt->fetch()['total'];
                        
                        $out_sql = "SELECT COALESCE(SUM(rf.quantity), 0) as total FROM request_fulfillments rf 
                                   INNER JOIN requests r ON rf.request_id = r.id 
                                   WHERE {$req_where_clause}";
                        $stmt = db()->prepare($out_sql);
                        $stmt->execute($req_params);
                        $total_out = (int)$stmt->fetch()['total'];
                        
                        if ($has_inventory_transactions) {
                            $inv_in_sql = "SELECT COALESCE(SUM(ABS(quantity)), 0) as total FROM inventory_transactions it 
                                         WHERE {$inv_where_clause} AND it.transaction_type = 'IN'";
                            $stmt = db()->prepare($inv_in_sql);
                            $stmt->execute($inv_params);
                            $total_in += (int)$stmt->fetch()['total'];
                            
                            $inv_out_sql = "SELECT COALESCE(SUM(ABS(quantity)), 0) as total FROM inventory_transactions it 
                                          WHERE {$inv_where_clause} AND it.transaction_type = 'OUT'";
                            $stmt = db()->prepare($inv_out_sql);
                            $stmt->execute($inv_params);
                            $total_out += (int)$stmt->fetch()['total'];
                        }
                    } catch (Throwable $e) {
                        foreach ($report_data as $row) {
                            if ($row['transaction_type'] === 'IN') {
                                $total_in += (int)$row['quantity'];
                            } elseif ($row['transaction_type'] === 'OUT') {
                                $total_out += (int)$row['quantity'];
                            }
                        }
                    }
                ?>
                <div class="report-section mb-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b-2 border-gray-300">TRANSACTION SUMMARY</h2>
                    <div class="grid grid-cols-3 gap-8">
                        <div class="text-center border-r border-gray-300">
                            <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total In</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_in); ?></p>
                            <p class="text-xs text-gray-500 mt-1">units</p>
                        </div>
                        <div class="text-center border-r border-gray-300">
                            <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total Out</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_out); ?></p>
                            <p class="text-xs text-gray-500 mt-1">units</p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs font-medium text-gray-600 uppercase tracking-wider mb-2">Total Records</p>
                            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_records); ?></p>
                            <p class="text-xs text-gray-500 mt-1">transactions</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Detailed Data Section -->
                <div class="report-section">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-900 pb-2 border-b-2 border-gray-300">DETAILED DATA</h2>
                        <?php if (!empty($report_data)): ?>
                        <div class="text-sm text-gray-600 no-print">
                            Showing <span class="font-semibold"><?php echo count($report_data); ?></span> 
                            of <span class="font-semibold"><?php echo number_format($total_records); ?></span> records
                            <?php if ($total_pages > 1): ?>
                            | Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Report Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse" id="report-table" style="border: 2px solid #000;">
                            <thead>
                            <tr>
                                <?php
                                $headers = [];
                                if ($report_type === 'dispensed') {
                                    $headers = ['Date', 'Medicine', 'Batch No.', 'Quantity Dispensed', 'Remaining Stock', 'Dispensed By', 'Patient/Remarks'];
                                } elseif ($report_type === 'remaining_stocks') {
                                    $headers = ['Batch Code', 'Total Received', 'Current Stock', 'Total Dispensed', 'Expired', 'Expiry Date'];
                                } elseif ($report_type === 'expiry') {
                                    $headers = ['Batch Code', 'Quantity', 'Expiry Date', 'Days Until Expiry'];
                                } elseif ($report_type === 'restocking') {
                                    $headers = ['Date Received', 'Batch Code', 'Quantity Added', 'Source', 'Staff'];
                                } elseif ($report_type === 'low_stock') {
                                    $headers = ['Batch Code', 'Current Stock', 'Expiry Date', 'Status', 'Last Dispensed'];
                                } elseif ($report_type === 'activity_logs') {
                                    $headers = ['Date', 'Action', 'Type', 'Quantity', 'Batch', 'Staff', 'Description'];
                                } elseif ($report_type === 'patient_requests') {
                                    $headers = ['Date', 'Patient', 'Requested For', 'Quantity', 'Status', 'Reviewed By'];
                                }
                                foreach ($headers as $header): ?>
                                    <th class="px-4 py-3 text-xs font-bold text-gray-900 uppercase tracking-wider border-2 border-gray-900 bg-gray-100 <?php 
                                        // Center align numeric columns
                                        if (in_array($header, ['Quantity Dispensed', 'Remaining Stock'])) {
                                            echo 'text-center';
                                        } else {
                                            echo 'text-left';
                                        }
                                    ?>"><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($report_data)): ?>
                                <tr>
                                    <td colspan="<?php echo count($headers); ?>" class="px-6 py-8 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            <p class="text-lg font-medium text-gray-900 mb-2">No Data Available</p>
                                            <p class="text-sm text-gray-500">No records found for the selected criteria. Try adjusting your filters or date range.</p>
                                            <?php if ($report_type === 'activity_logs' && $has_inventory_transactions): ?>
                                            <p class="text-xs text-gray-400 mt-2">Tip: Try removing date filters to see all transactions.</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $index => $row): ?>
                                    <tr class="border-b-2 border-gray-900 hover:bg-gray-50">
                                        <?php if ($report_type === 'dispensed'): ?>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-2 border-gray-900 text-left" style="border: 1px solid #000;"><?php echo date('Y-m-d', strtotime($row['dispense_date'])); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-2 border-gray-900 text-left" style="border: 1px solid #000;"><?php echo htmlspecialchars($row['medicine_name'] ?? $medicine_name); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-2 border-gray-900 text-left" style="border: 1px solid #000;"><?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 font-semibold border-2 border-gray-900 text-center" style="border: 1px solid #000;"><?php echo number_format($row['quantity']); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 font-semibold border-2 border-gray-900 text-center" style="border: 1px solid #000;"><?php echo number_format($row['remaining_stock'] ?? 0); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-2 border-gray-900 text-left" style="border: 1px solid #000;"><?php echo htmlspecialchars($row['staff_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-2 border-gray-900 text-left" style="border: 1px solid #000;">
                                                <?php 
                                                $patient_remarks = $row['patient_name'] ?? 'N/A';
                                                if (!empty($row['remarks'])) {
                                                    $patient_remarks .= ($patient_remarks !== 'N/A' ? ' - ' : '') . htmlspecialchars($row['remarks']);
                                                }
                                                echo htmlspecialchars($patient_remarks);
                                                ?>
                                            </td>
                                        
                                        <?php elseif ($report_type === 'remaining_stocks'): ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($row['total_received']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?php echo $row['current_stock'] <= 10 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($row['current_stock']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($row['total_dispensed']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600"><?php echo number_format($row['expired']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $row['is_expired'] ? 'text-red-600' : 'text-gray-900'; ?>">
                                                <?php echo date('M d, Y', strtotime($row['expiry_date'])); ?>
                                                <?php if ($row['is_expired']): ?>
                                                    <span class="ml-2 text-xs bg-red-100 text-red-800 px-2 py-1 rounded">Expired</span>
                                                <?php endif; ?>
                                            </td>
                                        
                                        <?php elseif ($report_type === 'expiry'): ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold"><?php echo number_format($row['batch_quantity']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-orange-600 font-medium"><?php echo date('M d, Y', strtotime($row['expiry_date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $row['days_until_expiry'] <= 7 ? 'text-red-600 font-bold' : ($row['days_until_expiry'] <= 30 ? 'text-orange-600' : 'text-gray-900'); ?>">
                                                <?php echo $row['days_until_expiry']; ?> days
                                            </td>
                                        
                                        <?php elseif ($report_type === 'restocking'): ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($row['date_received'] ?? $row['transaction_date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-semibold"><?php echo number_format($row['added_quantity']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['source'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['staff_name'] ?? 'N/A'); ?></td>
                                        
                                        <?php elseif ($report_type === 'low_stock'): ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-bold"><?php echo number_format($row['current_stock']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M d, Y', strtotime($row['expiry_date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 rounded">Low Stock</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $row['last_dispensed_date'] ? date('M d, Y', strtotime($row['last_dispensed_date'])) : 'N/A'; ?></td>
                                        
                                        <?php elseif ($report_type === 'activity_logs'): ?>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">
                                                <?php echo date('M d, Y', strtotime($row['action_date'])); ?><br>
                                                <span class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($row['action_date'])); ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-sm border-b border-gray-200">
                                                <span class="px-2 py-1 text-xs font-semibold <?php 
                                                    echo $row['transaction_type'] === 'IN' ? 'bg-green-100 text-green-800' : 
                                                        ($row['transaction_type'] === 'OUT' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); 
                                                ?>">
                                                    <?php echo htmlspecialchars($row['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">
                                                <?php echo htmlspecialchars(str_replace('_', ' ', $row['reference_type'] ?? 'N/A')); ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 font-semibold text-right border-b border-gray-200">
                                                <?php echo $row['transaction_type'] === 'IN' ? '+' : '-'; ?><?php echo number_format($row['quantity']); ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">
                                                <?php echo htmlspecialchars($row['batch_code'] ?? 'N/A'); ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200">
                                                <?php echo htmlspecialchars($row['staff_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700 border-b border-gray-200">
                                                <?php echo htmlspecialchars($row['action_description'] ?? 'N/A'); ?>
                                            </td>
                                        
                                        <?php elseif ($report_type === 'patient_requests'): ?>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo date('M d, Y', strtotime($row['request_date'])); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($row['patient_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($row['requested_for'] ?? 'self'); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-900 font-semibold text-right border-b border-gray-200"><?php echo number_format($row['requested_qty'] ?? 0); ?></td>
                                            <td class="px-4 py-3 text-sm border-b border-gray-200">
                                                <span class="px-2 py-1 text-xs font-semibold <?php 
                                                    echo $row['status'] === 'claimed' ? 'bg-green-100 text-green-800' : 
                                                        ($row['status'] === 'approved' ? 'bg-blue-100 text-blue-800' : 
                                                        ($row['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800')); 
                                                ?>">
                                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($row['reviewed_by'] ?? 'N/A'); ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Report Footer -->
                <div class="report-footer">
                    <p><strong><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?></strong> - Medicine Inventory Management System</p>
                    <p>Report Generated: <?php echo date('F d, Y h:i A'); ?> | Page <?php echo $current_page; ?> of <?php echo max(1, $total_pages); ?></p>
                    <p class="mt-2 text-xs">This is an automated report generated by the system. For inquiries, please contact the system administrator.</p>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-6 flex items-center justify-between no-print">
                    <div class="text-sm text-gray-700">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?> results
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="px-4 py-2 border border-gray-300 rounded-lg <?php echo $i == $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Enable report type dropdown when medicine is selected
        document.addEventListener('DOMContentLoaded', function() {
            const medicineSelect = document.getElementById('medicine-select');
            const reportTypeSelect = document.getElementById('report-type-select');
            
            if (medicineSelect && reportTypeSelect) {
                // Check initial state
                if (medicineSelect.value && medicineSelect.value !== '') {
                    reportTypeSelect.disabled = false;
                } else {
                    reportTypeSelect.disabled = true;
                }
                
                // Listen for changes
                medicineSelect.addEventListener('change', function() {
                    if (this.value && this.value !== '') {
                        reportTypeSelect.disabled = false;
                    } else {
                        reportTypeSelect.disabled = true;
                        reportTypeSelect.value = '';
                    }
                });
            }
        });

        // Form validation
        function validateReportForm() {
            const medicineSelect = document.getElementById('medicine-select');
            const reportTypeSelect = document.getElementById('report-type-select');
            
            if (!medicineSelect.value || medicineSelect.value === '') {
                alert('Please select a medicine');
                medicineSelect.focus();
                return false;
            }
            
            if (!reportTypeSelect.value || reportTypeSelect.value === '') {
                alert('Please select a report type');
                reportTypeSelect.focus();
                return false;
            }
            
            return true;
        }

        // Export functions
        function exportCSV() {
            const table = document.getElementById('report-table');
            if (!table) {
                alert('No report data available to export');
                return;
            }
            
            // Check if table has data rows (more than just header)
            const dataRows = table.querySelectorAll('tbody tr');
            if (dataRows.length === 0) {
                alert('No data available to export');
                return;
            }
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                // Skip empty rows or rows with only empty cells
                let hasData = false;
                for (let j = 0; j < cols.length; j++) {
                    const text = cols[j].innerText.trim();
                    if (text) hasData = true;
                }
                if (!hasData && i > 0) continue; // Skip empty data rows but keep header
                
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.trim().replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                
                if (row.length > 0) {
                    csv.push(row.join(','));
                }
            }
            
            if (csv.length === 0) {
                alert('No data available to export');
                return;
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            const reportTitle = '<?php echo htmlspecialchars(addslashes($report_title ?: 'Report')); ?>';
            const dateStr = new Date().toISOString().split('T')[0];
            const sanitizedTitle = reportTitle.replace(/[^a-z0-9]/gi, '_').substring(0, 50);
            link.setAttribute('download', (sanitizedTitle || 'Report') + '_' + dateStr + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function exportPDF() {
            // Use window.print() for PDF export
            // The print CSS will handle formatting
            window.print();
        }
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>

