<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

// Helper function to get upload URL
function upload_url(string $path): string {
    $clean_path = ltrim($path, '/');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos);
    } else {
        $base = dirname($script);
        if ($base === '.' || $base === '/') {
            $base = '';
        }
    }
    return rtrim($base, '/') . '/' . $clean_path;
}

$user = current_user();

// Get updated user data with profile image
$userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$user['id']]);
$user_data = $userStmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

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
// Separate pagination page number from sidebar "current_page" used for highlighting
$pagination_page = $page;
$print_all = isset($_GET['print_all']) && $_GET['print_all'] == '1';
$per_page = $print_all ? 999999 : 50; // Fetch all records if printing
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
    $medicine_details = null;
    foreach ($medicines as $med) {
        if ($med['id'] == $selected_medicine_id) {
            $medicine_name = $med['name'];
            break;
        }
    }
    
    // Get full medicine details for print header
    try {
        $med_stmt = db()->prepare('SELECT name, generic_name, dosage, form FROM medicines WHERE id = ? LIMIT 1');
        $med_stmt->execute([$selected_medicine_id]);
        $medicine_details = $med_stmt->fetch();
    } catch (Throwable $e) {
        error_log("Error fetching medicine details: " . $e->getMessage());
    }
    
    // Get location info for header
    $health_center_name = get_setting('health_center_name', 'Health Center');
    $barangay_name = get_setting('barangay_name', 'Barangay Loon');
    $province_name = get_setting('province_name', 'Bohol');
    $municipality_name = get_setting('municipality_name', 'Loon');
    
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
        
        /* Print-optimized styles */
        .print-header {
            display: none;
        }
        
        .official-print-header {
            display: none;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .official-print-header .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .official-print-header .logo-left {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        
        .official-print-header .header-center {
            text-align: center;
            flex: 1;
            margin: 0 20px;
        }
        
        .official-print-header .header-center h1 {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .official-print-header .header-center h2 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        
        .official-print-header .header-center h3 {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .official-print-header .header-center p {
            font-size: 11px;
            margin: 2px 0;
        }
        
        .official-print-header .logo-right {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        
        .print-report-title {
            text-align: center;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        
        .print-report-title h1 {
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }
        
        .print-report-title h2 {
            font-size: 13px;
            font-weight: 600;
            font-style: italic;
            margin-top: 5px;
            color: #333;
        }
        
        .print-medicine-details {
            display: none;
            margin: 12px 0;
            padding: 10px;
            border: 1px solid #000;
            background: #f9f9f9;
        }
        
        .print-medicine-details h3 {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        
        .print-medicine-details .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px 15px;
            font-size: 10px;
        }
        
        .print-medicine-details .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 18px;
        }
        
        .print-medicine-details .detail-label {
            font-weight: 600;
            text-align: left;
            min-width: 110px;
        }
        
        .print-medicine-details .detail-item span:last-child {
            text-align: right;
            flex: 1;
        }
        
        .print-summary-table {
            display: none;
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            border: 2px solid #000;
        }
        
        .print-summary-table th,
        .print-summary-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
            font-size: 10px;
        }
        
        .print-summary-table th {
            background-color: #e5e5e5;
            font-weight: bold;
        }
        
        .print-summary-table td {
            font-weight: 600;
        }
        
        .print-executive-summary {
            display: none;
            margin: 12px 0;
            padding: 10px;
            border: 1px solid #000;
            background: #f9f9f9;
        }
        
        .print-executive-summary h3 {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 8px;
            text-transform: uppercase;
            text-align: center;
        }
        
        .print-executive-summary .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        
        .print-executive-summary .summary-item {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
            background: white;
        }
        
        .print-executive-summary .summary-label {
            font-size: 9px;
            font-weight: normal;
            text-transform: uppercase;
            margin-bottom: 4px;
            color: #555;
        }
        
        .print-executive-summary .summary-value {
            font-size: 14px;
            font-weight: bold;
            color: #000;
        }
        
        .print-legend {
            display: none;
            margin: 15px 0 0 0;
            padding: 10px;
            border: 1px solid #000;
            background: #f9f9f9;
            page-break-after: always;
        }
        
        .print-legend h3 {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        
        .print-legend ul {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 9px;
        }
        
        .print-legend li {
            margin: 3px 0;
            padding-left: 12px;
            position: relative;
        }
        
        .print-legend li:before {
            content: "•";
            position: absolute;
            left: 0;
            font-weight: bold;
        }
        
        .print-signatures {
            display: none;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #000;
        }
        
        .print-signatures .signature-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .print-signatures .signature-header span {
            width: 45%;
        }
        
        .print-signatures .signature-row {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }
        
        .print-signatures .signature-box {
            text-align: center;
            width: 45%;
        }
        
        .print-signatures .signature-line {
            border-top: 1px solid #000;
            margin: 0 0 5px 0;
            padding-top: 5px;
            min-height: 50px;
        }
        
        .print-signatures .signature-label {
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .print-signatures .signature-position {
            font-size: 9px;
            color: #555;
        }
        
        .print-footer-custom {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            margin-top: 20px;
            padding: 8px 0;
            border-top: 1px solid #000;
            text-align: center;
            font-size: 8px;
            background: white;
        }
        
        .print-footer-custom .footer-line1 {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .print-footer-custom .footer-line2 {
            color: #555;
        }
        
        @media print {
            .print-footer-custom {
                position: fixed;
                bottom: 0;
            }
        }
        
        @media print {
            /* First, ensure everything is visible by default */
            * {
                visibility: visible !important;
            }
            
            /* Hide ONLY specific UI elements */
            .sidebar,
            .sidebar-footer,
            .content-header,
            .no-print,
            button,
            a[href],
            form,
            select,
            input,
            nav {
                display: none !important;
                visibility: hidden !important;
            }
            
            /* Ensure main content and report are visible */
            body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 12px !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                display: block !important;
            }
            
            .content-body {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Explicitly show report container and all its children */
            .content-body .report-container,
            .content-body .bg-white.report-container {
                display: block !important;
                visibility: visible !important;
            }
            
            .content-body .report-container * {
                visibility: visible !important;
            }
            
            /* Show official print header and hide regular headers */
            .official-print-header,
            .print-report-title,
            .print-medicine-details,
            .print-executive-summary,
            .print-legend,
            .print-signatures,
            .print-footer-custom {
                display: block !important;
            }
            
            .print-header {
                display: none !important;
            }
            
            /* Hide regular headers and UI elements during print */
            .report-header,
            .content-header,
            nav,
            .sidebar,
            .sidebar-footer {
                display: none !important;
            }
            
            /* Hide filter form and navigation */
            .content-body > .bg-white.rounded-lg.shadow-lg:first-child,
            .content-body > form {
                display: none !important;
            }
            
            /* Minimize blank spaces */
            .report-container {
                padding: 10px 15px !important;
                margin: 0 !important;
            }
            
            /* Remove excessive margins */
            .print-report-title {
                margin: 10px 0 !important;
                padding: 10px 0 !important;
            }
            
            .print-medicine-details {
                margin: 10px 0 !important;
                padding: 10px !important;
            }
            
            .print-summary-table {
                margin: 10px 0 !important;
            }
            
            .print-legend {
                margin: 10px 0 !important;
                padding: 10px !important;
            }
            
            /* Ensure no blank pages */
            .report-section {
                margin-bottom: 15px !important;
            }
            
            /* Page break before detailed table */
            .report-section:has(#report-table),
            .report-section:has(table#report-table) {
                page-break-before: always !important;
                margin-top: 0 !important;
            }
            
            /* Hide empty spaces */
            .bg-white.rounded-lg.shadow-lg {
                margin-bottom: 0 !important;
            }
            
            /* Ensure legend appears only once */
            .print-legend {
                page-break-after: always !important;
            }
            
            /* Reduce spacing between sections */
            .print-executive-summary + .print-legend {
                margin-top: 10px !important;
            }
            
            /* Ensure all report sections are visible */
            .report-section {
                display: block !important;
                visibility: visible !important;
                page-break-inside: avoid;
            }
            
            .print-summary {
                display: block !important;
                visibility: visible !important;
                page-break-inside: avoid;
                border: 1px solid #000 !important;
                background: #f9f9f9 !important;
                padding: 15px !important;
                margin-bottom: 20px !important;
            }
            
            /* Tables - ensure they're visible */
            table,
            #report-table {
                display: table !important;
                visibility: visible !important;
                width: 100% !important;
                border-collapse: collapse !important;
                margin: 10px 0 !important;
            }
            
            thead {
                display: table-header-group !important;
                visibility: visible !important;
            }
            
            tbody {
                display: table-row-group !important;
                visibility: visible !important;
            }
            
            tfoot {
                display: table-footer-group !important;
                visibility: visible !important;
            }
            
            tr {
                display: table-row !important;
                visibility: visible !important;
                page-break-inside: avoid !important;
            }
            
            th,
            td {
                display: table-cell !important;
                visibility: visible !important;
                border: 1px solid #000 !important;
                padding: 6px 8px !important;
                font-size: 10px !important;
                color: #000 !important;
                background: white !important;
            }
            
            th {
                background-color: #e5e5e5 !important;
                font-weight: bold !important;
                text-align: center !important;
            }
            
            td {
                text-align: center !important;
            }
            
            /* Footer */
            .report-footer {
                display: block !important;
                visibility: visible !important;
                page-break-inside: avoid;
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #000;
                text-align: center;
                font-size: 9px;
                color: #000;
            }
            
            /* Remove colors */
            .text-blue-600,
            .text-red-600,
            .text-green-600,
            .text-orange-600 {
                color: #000 !important;
            }
            
            /* Page settings */
            @page {
                margin: 1cm;
                size: A4;
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
    <?php
    // For the sidebar, use the actual script name so "Reports" is highlighted
    $sidebar_current_page = basename($_SERVER['PHP_SELF'] ?? '');
    render_super_admin_sidebar([
        'current_page' => $sidebar_current_page,
        'user_data' => $user_data
    ]);
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header (hidden, using global shell header instead) -->
        <div class="content-header" style="display: none;">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Reports</h1>
                    <p class="text-gray-600 mt-1">Generate and export detailed reports for your medicine inventory</p>
                </div>
                <div class="flex items-center space-x-6">
                    <!-- Current Time Display -->
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Current Time</div>
                        <div class="text-sm font-medium text-gray-900" id="current-time"><?php echo date('H:i:s'); ?></div>
                    </div>
                    
                    <!-- Night Mode Toggle -->
                    <button id="night-mode-toggle" class="p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200" title="Toggle Night Mode">
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                    </button>
                    
                    <!-- Notifications -->
                    <div class="relative">
                        <button class="p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200 relative" title="Notifications">
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM4.828 7l2.586 2.586a2 2 0 002.828 0L12 7H4.828zM4 5h16a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V7a2 2 0 012-2z"></path>
                            </svg>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">3</span>
                        </button>
                    </div>
                    
                    <!-- Profile Section -->
                    <div class="relative" id="profile-dropdown">
                        <button id="profile-toggle" class="flex items-center space-x-3 hover:bg-gray-50 rounded-lg p-2 transition-colors duration-200 cursor-pointer" type="button">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                     alt="Profile Picture" 
                                     class="w-8 h-8 rounded-full object-cover border-2 border-purple-500"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500" style="display:none;">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars(!empty($user['first_name']) ? $user['first_name'] : 'Super'); ?>
                                </div>
                                <div class="text-xs text-gray-500">Super Admin</div>
                            </div>
                            <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" id="profile-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <!-- Profile Dropdown Menu -->
                        <div id="profile-menu" class="absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border border-gray-200 py-2 z-50 hidden">
                            <!-- User Info Section -->
                            <div class="px-4 py-3 border-b border-gray-100">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'Super') . ' ' . ($user['last_name'] ?? 'Admin'))); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($user['email'] ?? 'admin@example.com'); ?>
                                </div>
                            </div>
                            
                            <!-- Menu Items -->
                            <div class="py-1">
                                <a href="<?php echo base_url('super_admin/profile.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Edit Profile
                                </a>
                                <a href="#" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Support
                                </a>
                            </div>
                            
                            <!-- Separator -->
                            <div class="border-t border-gray-100 my-1"></div>
                            
                            <!-- Sign Out -->
                            <div class="py-1">
                                <a href="<?php echo base_url('logout.php'); ?>" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
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
                <!-- Official Government-Style Print Header -->
                <div class="official-print-header">
                    <div class="header-top">
                        <img src="<?php echo htmlspecialchars(base_url('uploads/logoloon.png')); ?>" alt="LGU Logo" class="logo-left" onerror="this.style.display='none';">
                        <div class="header-center">
                            <h1>REPUBLIC OF THE PHILIPPINES</h1>
                            <h2><?php echo htmlspecialchars($municipality_name); ?> City Health Office</h2>
                            <h3><?php echo htmlspecialchars($health_center_name); ?></h3>
                            <p><?php echo htmlspecialchars($barangay_name); ?></p>
                            <p><?php echo htmlspecialchars($province_name); ?></p>
                        </div>
                        <div class="logo-right">
                            <!-- DOH/CHO Logo can be added here if available -->
                        </div>
                    </div>
                </div>
                
                <!-- Report Title -->
                <div class="print-report-title">
                    <h1><?php 
                        $report_titles = [
                            'dispensed' => 'DISPENSED REPORT',
                            'remaining_stocks' => 'REMAINING STOCKS REPORT',
                            'expiry' => 'EXPIRY REPORT',
                            'restocking' => 'RESTOCKING HISTORY REPORT',
                            'low_stock' => 'LOW STOCK ALERTS REPORT',
                            'activity_logs' => 'ACTIVITY LOGS REPORT',
                            'patient_requests' => 'PATIENT REQUESTS REPORT'
                        ];
                        echo $report_titles[$report_type] ?? 'INVENTORY REPORT';
                    ?></h1>
                    <h2>Medicine Name: <?php echo strtoupper(htmlspecialchars($medicine_name)); ?></h2>
                </div>
                
                <!-- Medicine Details Block -->
                <div class="print-medicine-details">
                    <h3>Medicine Details</h3>
                    <div class="details-grid">
                        <div class="detail-item">
                            <span class="detail-label">Generic Name:</span>
                            <span><?php echo htmlspecialchars($medicine_details['generic_name'] ?? $medicine_name); ?></span>
                        </div>
                        <?php if (!empty($medicine_details['dosage'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Dosage:</span>
                            <span><?php echo htmlspecialchars($medicine_details['dosage']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($medicine_details['form'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Form:</span>
                            <span><?php echo htmlspecialchars($medicine_details['form']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($batch_id > 0): ?>
                        <div class="detail-item">
                            <span class="detail-label">Batch No.:</span>
                            <span><?php 
                                foreach ($batches as $b) {
                                    if ($b['id'] == $batch_id) {
                                        echo htmlspecialchars($b['batch_code']);
                                        break;
                                    }
                                }
                            ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($date_from) || !empty($date_to)): ?>
                        <div class="detail-item">
                            <span class="detail-label">Date Range:</span>
                            <span><?php echo $date_from ? date('F d, Y', strtotime($date_from)) : 'Start'; ?> 
                                <?php echo $date_to ? ' to ' . date('F d, Y', strtotime($date_to)) : ''; ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <span class="detail-label">Generated:</span>
                            <span><?php echo date('F d, Y h:i A'); ?> PHT (GMT+8)</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Officer:</span>
                            <span><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>)</span>
                        </div>
                    </div>
                </div>
                
                <!-- Executive Summary (2-column format) -->
                <?php if (!empty($report_summary) || $total_records > 0): ?>
                <div class="print-header">
                    <div class="print-executive-summary">
                        <h3>Executive Summary</h3>
                        <div class="summary-grid">
                            <?php 
                            // Calculate beginning stock
                            $beginning_stock = 0;
                            if (!empty($date_from) && isset($report_summary['total_received'])) {
                                $beginning_stock = ($report_summary['total_received'] ?? 0) - ($report_summary['total_dispensed'] ?? 0);
                            } else {
                                $beginning_stock = ($report_summary['total_stock'] ?? $report_summary['final_stock'] ?? 0) + ($report_summary['total_dispensed'] ?? 0) - ($report_summary['total_received'] ?? 0);
                            }
                            ?>
                            <?php if (isset($report_summary['total_dispensed'])): ?>
                            <div class="summary-item">
                                <div class="summary-label">Total Dispensed</div>
                                <div class="summary-value"><?php echo number_format($report_summary['total_dispensed'] ?? 0); ?> units</div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($report_summary['total_received'])): ?>
                            <div class="summary-item">
                                <div class="summary-label">Total Received</div>
                                <div class="summary-value"><?php echo number_format($report_summary['total_received'] ?? 0); ?> units</div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($report_summary['total_expired'])): ?>
                            <div class="summary-item">
                                <div class="summary-label">Total Expired</div>
                                <div class="summary-value"><?php echo number_format($report_summary['total_expired'] ?? 0); ?> units</div>
                            </div>
                            <?php endif; ?>
                            <div class="summary-item">
                                <div class="summary-label">Ending Stock (Current)</div>
                                <div class="summary-value"><?php echo number_format($report_summary['total_stock'] ?? $report_summary['final_stock'] ?? 0); ?> units</div>
                            </div>
                            <?php if (isset($report_summary['total_received'])): ?>
                            <div class="summary-item">
                                <div class="summary-label">Beginning Stock</div>
                                <div class="summary-value"><?php echo number_format(max(0, $beginning_stock)); ?> units</div>
                            </div>
                            <?php endif; ?>
                            <div class="summary-item">
                                <div class="summary-label">Total Records</div>
                                <div class="summary-value"><?php echo number_format($total_records); ?> rows</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Report Legend (moved to bottom of page 1) -->
                <div class="print-legend">
                    <h3>Legend</h3>
                    <ul>
                        <li><strong>Total Received:</strong> All stocks received within date range</li>
                        <li><strong>Total Dispensed:</strong> All stocks given to patients</li>
                        <li><strong>Total Expired:</strong> Stocks disposed due to expiry</li>
                        <li><strong>Current Stock:</strong> Final balance as of report generation</li>
                    </ul>
                </div>
                
                <!-- Old Printable Header (hidden) -->
                <div class="print-header" style="display: none;">
                    <!-- Old header content hidden -->
                </div>
                
                <!-- Report Header - PDF Style -->
                <?php if ($report_type === 'dispensed'): ?>
                <div class="report-header text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($report_title); ?></h1>
                    <p class="text-sm text-gray-700 font-medium"><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?> - Medicine Inventory Management System</p>
                </div>
                
                <!-- Export Buttons -->
                <?php if ((!empty($report_data) || !empty($report_summary)) && !$print_all): ?>
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
                    <button onclick="printReport()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200 flex items-center space-x-2 text-sm font-medium shadow-md hover:shadow-lg" title="Tip: Uncheck 'Headers and Footers' in print dialog to remove browser URL and timestamps">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        <span>Print Report</span>
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
                            | Page <?php echo $pagination_page; ?> of <?php echo $total_pages; ?>
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
                
                <!-- Prepared By / Verified By Section -->
                <div class="print-signatures">
                    <div class="signature-header">
                        <span>Prepared by:</span>
                        <span>Verified by:</span>
                    </div>
                    <div class="signature-row">
                        <div class="signature-box">
                            <div class="signature-line"></div>
                            <div class="signature-label">Name / Position</div>
                            <div class="signature-position"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?><br><?php 
                                $role_titles = [
                                    'super_admin' => 'Administrator',
                                    'bhw' => 'Barangay Health Worker',
                                    'resident' => 'Resident'
                                ];
                                echo $role_titles[$user['role']] ?? ucfirst(str_replace('_', ' ', $user['role']));
                            ?></div>
                        </div>
                        <div class="signature-box">
                            <div class="signature-line"></div>
                            <div class="signature-label">Name / Position</div>
                            <div class="signature-position">_______________________</div>
                        </div>
                    </div>
                </div>
                
                <!-- Custom Footer -->
                <div class="print-footer-custom">
                    <div class="footer-line1"><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?> – Medicine Inventory Management System | Generated Automatically</div>
                    <div class="footer-line2">Page <span class="page-number"></span> of <span class="total-pages"></span></div>
                </div>
                
                <!-- Old Report Footer (hidden) -->
                <div class="report-footer" style="display: none;">
                    <p><strong><?php echo htmlspecialchars(get_setting('brand_name', 'MediTrack')); ?></strong> - Medicine Inventory Management System</p>
                    <p>Report Generated: <?php echo date('F d, Y h:i A'); ?><?php if (!$print_all && $total_pages > 1): ?> | Page <?php echo $pagination_page; ?> of <?php echo max(1, $total_pages); ?><?php endif; ?></p>
                    <p class="mt-2 text-xs">This is an automated report generated by the system. For inquiries, please contact the system administrator.</p>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1 && !$print_all): ?>
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
        
        // Print Report Function - Enhanced with support for printing all pages
        function printReport() {
            const totalPages = <?php echo max(1, $total_pages ?? 1); ?>;
            const currentPage = <?php echo $pagination_page ?? 1; ?>;
            const totalRecords = <?php echo $total_records ?? 0; ?>;
            
            // Update page numbers in footer
            const pageNumberSpans = document.querySelectorAll('.page-number');
            const totalPagesSpans = document.querySelectorAll('.total-pages');
            pageNumberSpans.forEach(span => span.textContent = currentPage);
            totalPagesSpans.forEach(span => span.textContent = totalPages);
            
            // Show instruction about browser headers/footers
            const printTip = 'IMPORTANT: For a clean professional report, please:\n\n' +
                '1. In the print dialog, click "More settings"\n' +
                '2. Uncheck "Headers and footers"\n' +
                '3. This will remove the browser URL and timestamps\n\n' +
                (totalPages > 1 ? 
                    `This report has ${totalPages} pages (${totalRecords.toLocaleString()} total records).\n\n` +
                    'Click OK to print ALL pages, or Cancel to print only the current page.' :
                    'Click OK to continue printing.');
            
            // If there are multiple pages, ask user if they want to print all data
            if (totalPages > 1) {
                const printAll = confirm(printTip);
                
                if (printAll) {
                    // Open a new window with print_all parameter to fetch all data
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('print_all', '1');
                    currentUrl.searchParams.delete('page'); // Remove page parameter
                    
                    const printWindow = window.open(currentUrl.toString(), '_blank');
                    
                    if (printWindow) {
                        // Wait for the page to load, then trigger print
                        printWindow.onload = function() {
                            setTimeout(function() {
                                // Try to minimize margins before printing
                                const style = printWindow.document.createElement('style');
                                style.textContent = '@page { margin: 0.3cm !important; }';
                                printWindow.document.head.appendChild(style);
                                printWindow.print();
                            }, 500);
                        };
                    } else {
                        alert('Please allow pop-ups for this site to print all pages.');
                    }
                    return;
                }
            } else {
                // Single page - show tip
                if (!confirm(printTip)) {
                    return;
                }
            }
            
            // Print current page
            setTimeout(function() {
                window.print();
            }, 100);
        }
        
        // Profile dropdown functionality
        function initProfileDropdown() {
            const toggle = document.getElementById('profile-toggle');
            const menu = document.getElementById('profile-menu');
            const arrow = document.getElementById('profile-arrow');
            
            if (!toggle || !menu || !arrow) return;
            
            toggle.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (menu.classList.contains('hidden')) {
                    menu.classList.remove('hidden');
                    arrow.classList.add('rotate-180');
                } else {
                    menu.classList.add('hidden');
                    arrow.classList.remove('rotate-180');
                }
            };
            
            // Close dropdown when clicking outside
            if (!window.superAdminProfileDropdownClickHandler) {
                window.superAdminProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && toggle && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.superAdminProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            if (!window.superAdminProfileDropdownKeyHandler) {
                window.superAdminProfileDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const menu = document.getElementById('profile-menu');
                        const arrow = document.getElementById('profile-arrow');
                        if (menu) menu.classList.add('hidden');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('keydown', window.superAdminProfileDropdownKeyHandler);
            }
        }
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize profile dropdown
            initProfileDropdown();
            // Logout confirmation is now handled by logout-confirmation.js
            
            // Update current time every second
            setInterval(function() {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
                const timeElement = document.getElementById('current-time');
                if (timeElement) {
                    timeElement.textContent = timeStr;
                }
            }, 1000);
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>


