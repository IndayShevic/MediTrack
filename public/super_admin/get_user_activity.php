<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

header('Content-Type: application/json');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID'
    ]);
    exit;
}

try {
    // Get user info
    $user_stmt = db()->prepare('SELECT id, email, role, first_name, last_name FROM users WHERE id = ?');
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
        exit;
    }
    
    $activities = [];
    
    // 1. Get inventory transactions (for BHW and Super Admin)
    if (in_array($user['role'], ['bhw', 'super_admin'])) {
        $trans_stmt = db()->prepare('
            SELECT 
                it.id,
                "Inventory Transaction" as activity_type,
                it.transaction_type,
                it.quantity,
                m.name as medicine_name,
                mb.batch_code,
                it.reference_type,
                it.notes,
                DATE_FORMAT(it.created_at, "%b %d, %Y %H:%i") as formatted_date,
                it.created_at as sort_date
            FROM inventory_transactions it
            JOIN medicines m ON it.medicine_id = m.id
            LEFT JOIN medicine_batches mb ON it.batch_id = mb.id
            WHERE it.created_by = ?
            ORDER BY it.created_at DESC
            LIMIT 50
        ');
        $trans_stmt->execute([$user_id]);
        $transactions = $trans_stmt->fetchAll();
        
        foreach ($transactions as $trans) {
            $activities[] = [
                'type' => 'inventory',
                'title' => $trans['transaction_type'] . ' - ' . $trans['medicine_name'],
                'description' => $trans['notes'] ?: ($trans['transaction_type'] . ' ' . abs($trans['quantity']) . ' units of ' . $trans['medicine_name']),
                'details' => [
                    'Transaction Type' => $trans['transaction_type'],
                    'Quantity' => $trans['quantity'],
                    'Medicine' => $trans['medicine_name'],
                    'Batch' => $trans['batch_code'] ?: 'N/A',
                    'Reference Type' => $trans['reference_type']
                ],
                'date' => $trans['formatted_date'],
                'sort_date' => $trans['sort_date']
            ];
        }
    }
    
    // 2. Get requests handled by BHW (approved/rejected/updated)
    if ($user['role'] === 'bhw') {
        $req_stmt = db()->prepare('
            SELECT 
                r.id,
                "Request Action" as activity_type,
                r.status,
                m.name as medicine_name,
                CONCAT(IFNULL(res.first_name, ""), " ", IFNULL(res.last_name, "")) as resident_name,
                r.rejection_reason,
                DATE_FORMAT(COALESCE(r.updated_at, r.created_at), "%b %d, %Y %H:%i") as formatted_date,
                COALESCE(r.updated_at, r.created_at) as sort_date
            FROM requests r
            JOIN medicines m ON r.medicine_id = m.id
            LEFT JOIN residents res ON r.resident_id = res.id
            WHERE r.bhw_id = ? AND r.status != "submitted"
            ORDER BY sort_date DESC
            LIMIT 50
        ');
        $req_stmt->execute([$user_id]);
        $requests = $req_stmt->fetchAll();
        
        foreach ($requests as $req) {
            $status_text = ucfirst(str_replace('_', ' ', $req['status']));
            $resident_display = trim($req['resident_name']) ?: 'Unknown Resident';
            $activities[] = [
                'type' => 'request',
                'title' => $status_text . ' Request - ' . $req['medicine_name'],
                'description' => $req['status'] === 'rejected' 
                    ? 'Rejected request for ' . $req['medicine_name'] . ' from ' . $resident_display
                    : $status_text . ' request for ' . $req['medicine_name'] . ' from ' . $resident_display,
                'details' => [
                    'Status' => $status_text,
                    'Medicine' => $req['medicine_name'],
                    'Resident' => $resident_display,
                    'Request ID' => '#' . $req['id']
                ],
                'date' => $req['formatted_date'],
                'sort_date' => $req['sort_date']
            ];
        }
    }
    
    // 3. Get requests made by Resident
    if ($user['role'] === 'resident') {
        // Get resident_id from residents table
        $resident_stmt = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
        $resident_stmt->execute([$user_id]);
        $resident = $resident_stmt->fetch();
        
        if ($resident) {
            $req_stmt = db()->prepare('
                SELECT 
                    r.id,
                    "Medicine Request" as activity_type,
                    r.status,
                    m.name as medicine_name,
                    r.requested_for,
                    r.patient_name,
                    DATE_FORMAT(r.created_at, "%b %d, %Y %H:%i") as formatted_date,
                    r.created_at as sort_date
                FROM requests r
                JOIN medicines m ON r.medicine_id = m.id
                WHERE r.resident_id = ?
                ORDER BY r.created_at DESC
                LIMIT 50
            ');
            $req_stmt->execute([$resident['id']]);
            $requests = $req_stmt->fetchAll();
            
            foreach ($requests as $req) {
                $status_text = ucfirst(str_replace('_', ' ', $req['status']));
                $for_text = $req['requested_for'] === 'family' ? ' (for ' . ($req['patient_name'] ?: 'family member') . ')' : '';
                $activities[] = [
                    'type' => 'request',
                    'title' => 'Requested ' . $req['medicine_name'],
                    'description' => 'Requested ' . $req['medicine_name'] . $for_text . ' - Status: ' . $status_text,
                    'details' => [
                        'Status' => $status_text,
                        'Medicine' => $req['medicine_name'],
                        'Requested For' => $req['requested_for'] === 'family' ? ($req['patient_name'] ?: 'Family Member') : 'Self',
                        'Request ID' => '#' . $req['id']
                    ],
                    'date' => $req['formatted_date'],
                    'sort_date' => $req['sort_date']
                ];
            }
        }
    }
    
    // Sort activities by date (most recent first)
    usort($activities, function($a, $b) {
        return strtotime($b['sort_date']) - strtotime($a['sort_date']);
    });
    
    // Limit to 100 most recent
    $activities = array_slice($activities, 0, 100);
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'email' => $user['email'],
            'role' => $user['role']
        ],
        'activities' => $activities,
        'count' => count($activities)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

