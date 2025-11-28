<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
require_once __DIR__ . '/../../config/mail.php';

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

$bhw_purok_id = $user['purok_id'] ?? 0;

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Approve or reject logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0) {
        if ($action === 'approve') {
            // Get BHW full name for approval log
            $bhwNameStmt = db()->prepare('SELECT CONCAT(IFNULL(first_name, ""), " ", IFNULL(middle_initial, ""), " ", IFNULL(last_name, "")) AS full_name FROM users WHERE id = ?');
            $bhwNameStmt->execute([$user['id']]);
            $bhwNameData = $bhwNameStmt->fetch();
            $bhwFullName = $bhwNameData['full_name'] ?? ($user['first_name'] . ' ' . $user['last_name']);
            
            // Get request details and verify it belongs to this BHW's purok
            $q = db()->prepare('
                SELECT r.medicine_id, r.resident_id, res.purok_id 
                FROM requests r 
                JOIN residents res ON res.id = r.resident_id 
                WHERE r.id = ? AND r.assigned_bhw_id = ? AND r.status = "submitted"
            ');
            $q->execute([$id, $user['id']]);
            $row = $q->fetch();
            
            if ($row && $row['purok_id'] == $bhw_purok_id) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // Update request status to approved (ready for dispensing)
                    $u = $pdo->prepare('UPDATE requests SET status="approved", is_ready_to_dispense=1 WHERE id=?');
                    $u->execute([$id]);
                    
                    // Create approval log
                    $approvalRemarks = trim($_POST['approval_remarks'] ?? '');
                    $approvalStmt = $pdo->prepare('
                        INSERT INTO request_approvals (request_id, approved_by, approval_status, approval_remarks) 
                        VALUES (?, ?, "approved", ?)
                    ');
                    $approvalStmt->execute([$id, $user['id'], $approvalRemarks ?: null]);
                    
                    $pdo->commit();
                    
                    // Notify resident
                    $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name, m.name AS medicine_name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id JOIN medicines m ON m.id=rq.medicine_id WHERE rq.id=?");
                    $r->execute([$id]);
                    $rec = $r->fetch();
                    if ($rec && !empty($rec['email'])) {
                        require_once __DIR__ . '/../../config/email_notifications.php';
                        $success = send_medicine_request_approval_to_resident($rec['email'], $rec['name'] ?? 'Resident', $rec['medicine_name'] ?? 'Unknown Medicine');
                        log_email_notification($user['id'], 'medicine_approval', 'Medicine Request Approved', 'Medicine request approval notification sent to resident', $success);
                    }
                    set_flash('Request approved successfully.', 'success');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Approval error: ' . $e->getMessage());
                    set_flash('Failed to approve request: ' . $e->getMessage(), 'error');
                }
            } else {
                set_flash('Request not found or you are not authorized to approve it.', 'error');
            }
            
            // Clear BHW sidebar notification cache so counts update immediately
            $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
            unset($_SESSION[$cache_key], $_SESSION[$cache_key . '_time']);
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if (empty($reason)) {
                set_flash('Rejection reason is required.', 'error');
                redirect_to('bhw/requests.php');
                exit;
            }
            
            // Get request details and verify it belongs to this BHW's purok
            $q = db()->prepare('
                SELECT r.resident_id, res.purok_id 
                FROM requests r 
                JOIN residents res ON res.id = r.resident_id 
                WHERE r.id = ? AND r.assigned_bhw_id = ? AND r.status = "submitted"
            ');
            $q->execute([$id, $user['id']]);
            $row = $q->fetch();
            
            if ($row && $row['purok_id'] == $bhw_purok_id) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // Update request status to rejected
                    $u = $pdo->prepare('UPDATE requests SET status="rejected", rejection_reason=? WHERE id=?');
                    $u->execute([$reason, $id]);
                    
                    // Create approval log with rejection status
                    $approvalStmt = $pdo->prepare('
                        INSERT INTO request_approvals (request_id, approved_by, approval_status, rejection_reason) 
                        VALUES (?, ?, "rejected", ?)
                    ');
                    $approvalStmt->execute([$id, $user['id'], $reason]);
                    
                    $pdo->commit();
                    
                    // Notify resident
                    $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name, m.name AS medicine_name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id JOIN medicines m ON m.id=rq.medicine_id WHERE rq.id=?");
                    $r->execute([$id]);
                    $rec = $r->fetch();
                    if ($rec && !empty($rec['email'])) {
                        require_once __DIR__ . '/../../config/email_notifications.php';
                        $success = send_medicine_request_rejection_to_resident($rec['email'], $rec['name'] ?? 'Resident', $rec['medicine_name'] ?? 'Unknown Medicine', $reason);
                        log_email_notification($user['id'], 'medicine_rejection', 'Medicine Request Rejected', 'Medicine request rejection notification sent to resident', $success);
                    }
                    set_flash('Request rejected successfully.', 'success');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Rejection error: ' . $e->getMessage());
                    set_flash('Failed to reject request: ' . $e->getMessage(), 'error');
                }
            } else {
                set_flash('Request not found or you are not authorized to reject it.', 'error');
            }
            
            // Clear BHW sidebar notification cache so counts update immediately
            $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
            unset($_SESSION[$cache_key], $_SESSION[$cache_key . '_time']);
        }
    }
    redirect_to('bhw/requests.php');
}

// Fetch all requests for the table
$rows = db()->prepare('
    SELECT 
        r.id,
        r.resident_id,
        m.name AS medicine,
        m.image_path AS medicine_image_path,
        r.status,
        r.created_at,
        r.requested_for,
        r.patient_name,
        r.patient_date_of_birth,
        r.relationship,
        r.reason,
        r.proof_image_path,
        r.rejection_reason,
        r.updated_at,
        r.is_ready_to_dispense,
        res.first_name,
        res.last_name,
        res.purok_id,
        u.profile_image AS resident_profile_image,
        fm.first_name AS family_first_name,
        fm.middle_initial AS family_middle_initial,
        fm.last_name AS family_last_name,
        fm.relationship AS family_relationship,
        CONCAT(IFNULL(apb.first_name, ""), " ", IFNULL(apb.middle_initial, ""), " ", IFNULL(apb.last_name, "")) AS approved_by_name,
        ra.approved_at,
        ra.approval_remarks,
        ra.approval_status,
        CONCAT(IFNULL(dispb.first_name, ""), " ", IFNULL(dispb.middle_initial, ""), " ", IFNULL(dispb.last_name, "")) AS dispensed_by_name,
        rd.dispensed_at,
        rd.quantity_released,
        rd.dispensing_notes,
        mb.batch_code
    FROM requests r
    JOIN medicines m ON m.id = r.medicine_id
    JOIN residents res ON res.id = r.resident_id
    JOIN users u ON u.id = res.user_id
    LEFT JOIN family_members fm ON fm.id = r.family_member_id
    LEFT JOIN request_approvals ra ON ra.request_id = r.id AND ra.approval_status = "approved"
    LEFT JOIN users apb ON apb.id = ra.approved_by
    LEFT JOIN request_dispensings rd ON rd.request_id = r.id
    LEFT JOIN users dispb ON dispb.id = rd.dispensed_by
    LEFT JOIN medicine_batches mb ON mb.id = rd.batch_id
    WHERE (r.assigned_bhw_id = ? OR r.bhw_id = ?) AND res.purok_id = ?
    ORDER BY r.created_at DESC
');
$rows->execute([$user['id'], $user['id'], $bhw_purok_id]);
$reqs = $rows->fetchAll();

// Calculate Quick Stats
$stats_pending = 0;
$stats_approved = 0;
$stats_rejected = 0;
foreach ($reqs as $r) {
    if ($r['status'] === 'submitted') $stats_pending++;
    elseif ($r['status'] === 'approved') $stats_approved++;
    elseif ($r['status'] === 'rejected') $stats_rejected++;
}

// Fetch pending requests for notifications
try {
    $stmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name as medicine_name, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id LEFT JOIN residents res ON r.resident_id = res.id WHERE r.status = "submitted" AND res.purok_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_requests_list = $stmt->fetchAll();
} catch (Throwable $e) { $pending_requests_list = []; }

try {
    $stmt = db()->prepare('SELECT id, first_name, last_name, created_at FROM pending_residents WHERE purok_id = ? AND status = "pending" ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_registrations_list = $stmt->fetchAll();
} catch (Throwable $e) { $pending_registrations_list = []; }

try {
    $stmt = db()->prepare('SELECT rfa.id, rfa.first_name, rfa.last_name, rfa.created_at FROM resident_family_additions rfa JOIN residents res ON res.id = rfa.resident_id WHERE res.purok_id = ? AND rfa.status = "pending" ORDER BY rfa.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_family_additions_list = $stmt->fetchAll();
} catch (Throwable $e) { $pending_family_additions_list = []; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Medicine Requests · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        .filter-chip.active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        .filter-chip {
            background-color: white;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }
        .filter-chip:hover:not(.active) {
            background-color: #f9fafb;
        }
    </style>
</head>
<body class="bg-gray-50 bhw-theme">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php 
        require_once __DIR__ . '/includes/header.php';
        render_bhw_header([
            'user_data' => $user_data,
            'notification_counts' => $notification_counts,
            'pending_requests' => $pending_requests_list,
            'pending_registrations' => $pending_registrations_list,
            'pending_family_additions' => $pending_family_additions_list
        ]);
        ?>
        
        <div class="p-6 max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="mb-8 animate-fade-in-up">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Medicine Requests</h1>
                <p class="text-gray-600">Review and manage medicine requests from residents in your purok.</p>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 animate-fade-in-up" style="animation-delay: 0.1s">
                <div class="glass-card rounded-2xl p-5 shadow-sm border-l-4 border-l-orange-500">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Pending Review</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats_pending; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center text-orange-600">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5 shadow-sm border-l-4 border-l-green-500">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Approved</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats_approved; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center text-green-600">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5 shadow-sm border-l-4 border-l-red-500">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Rejected</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats_rejected; ?></p>
                        </div>
                        <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center text-red-600">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Body -->
            <div class="glass-card rounded-2xl shadow-sm overflow-hidden animate-fade-in-up" style="animation-delay: 0.2s">
                <!-- Toolbar -->
                <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row gap-4 justify-between items-center bg-white">
                    <div class="flex gap-2 w-full sm:w-auto overflow-x-auto pb-2 sm:pb-0">
                        <button class="filter-chip active px-4 py-2 rounded-xl text-sm font-medium transition-all duration-200 whitespace-nowrap" data-filter="all">
                            All Requests
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-xl text-sm font-medium transition-all duration-200 whitespace-nowrap" data-filter="submitted">
                            Pending
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-xl text-sm font-medium transition-all duration-200 whitespace-nowrap" data-filter="approved">
                            Approved
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-xl text-sm font-medium transition-all duration-200 whitespace-nowrap" data-filter="rejected">
                            Rejected
                        </button>
                    </div>
                    <div class="relative w-full sm:w-64">
                        <input type="text" id="searchInput" placeholder="Search resident or medicine..." 
                               class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Resident</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Medicine</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white" id="requestsTableBody">
                            <?php if (empty($reqs)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                        <div class="flex flex-col items-center justify-center">
                                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                                <i class="fas fa-inbox text-2xl text-gray-300"></i>
                                            </div>
                                            <p class="text-lg font-medium text-gray-900">No requests found</p>
                                            <p class="text-sm text-gray-500">New medicine requests will appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reqs as $r): ?>
                                    <tr class="request-row hover:bg-gray-50 transition-colors group" 
                                        data-resident="<?php echo strtolower(htmlspecialchars($r['first_name'] . ' ' . $r['last_name'])); ?>"
                                        data-medicine="<?php echo strtolower(htmlspecialchars($r['medicine'])); ?>"
                                        data-status="<?php echo $r['status']; ?>">
                                        
                                        <!-- Resident -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if (!empty($r['resident_profile_image'])): ?>
                                                        <img class="h-10 w-10 rounded-full object-cover border border-gray-200" src="<?php echo upload_url($r['resident_profile_image']); ?>" alt="">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center text-blue-600 font-bold border border-blue-100">
                                                            <?php echo strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></div>
                                                    <div class="text-xs text-gray-500">ID: #<?php echo $r['id']; ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Medicine -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($r['medicine']); ?></div>
                                            <?php if ($r['requested_for'] === 'family'): ?>
                                                <div class="text-xs text-gray-500 flex items-center mt-0.5">
                                                    <i class="fas fa-users mr-1 text-[10px]"></i>
                                                    For: <?php echo htmlspecialchars($r['patient_name']); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-xs text-gray-500">For: Self</div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Date -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($r['created_at'])); ?></div>
                                        </td>

                                        <!-- Status -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($r['status'] === 'submitted'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                    <span class="w-1.5 h-1.5 bg-orange-500 rounded-full mr-1.5"></span>
                                                    Pending
                                                </span>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>
                                                    Approved
                                                </span>
                                            <?php elseif ($r['status'] === 'rejected'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-1.5"></span>
                                                    Rejected
                                                </span>
                                            <?php elseif ($r['status'] === 'dispensed'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full mr-1.5"></span>
                                                    Dispensed
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <?php if ($r['status'] === 'submitted'): ?>
                                                <button onclick="openReviewModal(<?php echo htmlspecialchars(json_encode($r)); ?>)" 
                                                        class="text-blue-600 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                                    Review
                                                </button>
                                            <?php else: ?>
                                                <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" 
                                                        class="text-gray-600 hover:text-gray-900 bg-gray-50 hover:bg-gray-100 px-3 py-1.5 rounded-lg transition-colors">
                                                    Details
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Review Modal -->
    <!-- Review Modal -->
    <div id="reviewModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-900 bg-opacity-50 transition-opacity backdrop-blur-sm" aria-hidden="true" onclick="closeReviewModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl w-full border border-gray-100">
                <form action="" method="POST" id="reviewForm">
                    <input type="hidden" name="id" id="modalRequestId">
                    <input type="hidden" name="action" id="modalAction">
                    
                    <!-- Modal Header -->
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-5 py-3 flex justify-between items-center">
                        <h3 class="text-base font-bold text-white flex items-center" id="modal-title">
                            <i class="fas fa-clipboard-check mr-2 text-blue-200"></i>
                            Review Request
                        </h3>
                        <button type="button" onclick="closeReviewModal()" class="text-blue-100 hover:text-white transition-colors rounded-full p-1 hover:bg-white/10">
                            <i class="fas fa-times text-base"></i>
                        </button>
                    </div>
                    
                    <div class="px-5 py-5">
                        <!-- Resident Info Card -->
                        <div class="flex items-center justify-between p-3 bg-blue-50/50 rounded-xl border border-blue-100 mb-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0" id="modalResidentAvatar">
                                    <!-- Avatar injected via JS -->
                                </div>
                                <div class="ml-3">
                                    <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wider mb-0.5">Resident</p>
                                    <h4 class="text-base font-bold text-gray-900 leading-tight" id="modalResidentName"></h4>
                                    <p class="text-[11px] text-gray-500 mt-0.5" id="modalResidentId"></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">Requested</p>
                                <p class="text-xs font-bold text-gray-700" id="modalRequestDate"></p>
                                <p class="text-[10px] text-gray-500" id="modalRequestTime"></p>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <!-- Medicine & Reason -->
                            <div class="grid grid-cols-1 gap-3">
                                <div class="bg-gray-50 p-3 rounded-xl border border-gray-100 group hover:border-blue-200 transition-colors">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-1" id="modalMedicineImage">
                                            <!-- Medicine Image or Icon -->
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Medicine Requested</p>
                                            <p class="text-sm font-bold text-gray-900 mt-0.5" id="modalMedicineName"></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 p-3 rounded-xl border border-gray-100 group hover:border-blue-200 transition-colors">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-1">
                                            <div class="w-8 h-8 rounded-lg bg-orange-100 flex items-center justify-center text-orange-600">
                                                <i class="fas fa-quote-left text-xs"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3 w-full">
                                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Reason</p>
                                            <p class="text-sm text-gray-700 mt-1 italic bg-white p-2 rounded-lg border border-gray-100" id="modalReason"></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Proof Image -->
                                <div id="modalProofSection" class="hidden bg-gray-50 p-3 rounded-xl border border-gray-100 group hover:border-blue-200 transition-colors">
                                    <!-- Content injected via JS -->
                                </div>
                            </div>
                            
                            <!-- Action Selection -->
                            <div id="actionSelection" class="mt-6 pt-6 border-t border-gray-100">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-3 text-center">Select Action</p>
                                <div class="grid grid-cols-2 gap-4">
                                    <button type="button" onclick="selectAction('approve')" class="group relative flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-xl hover:border-green-500 hover:bg-green-50 transition-all duration-200">
                                        <div class="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center text-green-600 mb-2 group-hover:scale-110 transition-transform shadow-sm">
                                            <i class="fas fa-paper-plane text-lg"></i>
                                        </div>
                                        <span class="text-xs font-bold text-gray-700 group-hover:text-green-700">Approve & Forward</span>
                                        <span class="text-[10px] text-gray-400 group-hover:text-green-600 mt-0.5">To On-Duty BHW</span>
                                    </button>
                                    
                                    <button type="button" onclick="selectAction('reject')" class="group relative flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-xl hover:border-red-500 hover:bg-red-50 transition-all duration-200">
                                        <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center text-red-600 mb-2 group-hover:scale-110 transition-transform shadow-sm">
                                            <i class="fas fa-times text-lg"></i>
                                        </div>
                                        <span class="text-xs font-bold text-gray-700 group-hover:text-red-700">Reject</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Approval Fields -->
                            <div id="approvalFields" class="hidden mt-3 animate-fade-in bg-green-50 rounded-xl p-3 border border-green-100">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-bold text-green-800 flex items-center">
                                        <i class="fas fa-check-circle mr-1.5 text-green-600"></i> Approving & Forwarding
                                    </h4>
                                    <button type="button" onclick="resetAction()" class="text-[10px] font-bold text-green-700 hover:text-green-900 underline uppercase">Change</button>
                                </div>
                                <label class="block text-[10px] font-bold text-green-800 uppercase tracking-wide mb-1">Remarks (Optional)</label>
                                <textarea name="approval_remarks" rows="2" class="w-full border-green-200 rounded-lg shadow-sm focus:ring-green-500 focus:border-green-500 text-xs bg-white" placeholder="Add any notes for the resident..."></textarea>
                            </div>

                            <!-- Rejection Fields -->
                            <div id="rejectionFields" class="hidden mt-3 animate-fade-in bg-red-50 rounded-xl p-3 border border-red-100">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-bold text-red-800 flex items-center">
                                        <i class="fas fa-times-circle mr-1.5 text-red-600"></i> Rejecting Request
                                    </h4>
                                    <button type="button" onclick="resetAction()" class="text-[10px] font-bold text-red-700 hover:text-red-900 underline uppercase">Change</button>
                                </div>
                                <label class="block text-[10px] font-bold text-red-800 uppercase tracking-wide mb-1">Reason for Rejection <span class="text-red-600">*</span></label>
                                <textarea name="reason" rows="2" class="w-full border-red-200 rounded-lg shadow-sm focus:ring-red-500 focus:border-red-500 text-xs bg-white" placeholder="Please explain why this request is being rejected..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 px-5 py-3 flex flex-row-reverse gap-2" id="modalFooter">
                        <button type="button" onclick="closeReviewModal()" class="w-full sm:w-auto inline-flex justify-center items-center px-3 py-2 border border-gray-300 shadow-sm text-xs font-bold rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors uppercase tracking-wide">
                            Close
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Filter Logic
        document.querySelectorAll('.filter-chip').forEach(button => {
            button.addEventListener('click', () => {
                // Update active state
                document.querySelectorAll('.filter-chip').forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');

                const filter = button.getAttribute('data-filter');
                const rows = document.querySelectorAll('.request-row');

                rows.forEach(row => {
                    if (filter === 'all' || row.getAttribute('data-status') === filter) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

        // Search Logic
        document.getElementById('searchInput').addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.request-row');

            rows.forEach(row => {
                const resident = row.getAttribute('data-resident');
                const medicine = row.getAttribute('data-medicine');
                
                if (resident.includes(searchTerm) || medicine.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Modal Logic
        function openReviewModal(data) {
            const modal = document.getElementById('reviewModal');
            
            document.getElementById('modalRequestId').value = data.id;
            document.getElementById('modalResidentName').textContent = data.first_name + ' ' + data.last_name;
            document.getElementById('modalResidentId').textContent = 'Resident ID: #' + data.resident_id;
            document.getElementById('modalMedicineName').textContent = data.medicine;
            document.getElementById('modalReason').textContent = data.reason || 'No reason provided';
            
            // Date and Time
            const date = new Date(data.created_at);
            document.getElementById('modalRequestDate').textContent = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('modalRequestTime').textContent = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

            // Helper for upload url
            function upload_url(path) {
                if (!path) return '';
                const cleanPath = path.replace(/^uploads\//, ''); 
                
                // Profiles are in root uploads (thesis/uploads) -> Go up 2 levels
                if (cleanPath.startsWith('profiles/')) {
                    return '../../uploads/' + cleanPath;
                } 
                // Others (proofs, medicines) are in public uploads (thesis/public/uploads) -> Go up 1 level
                else {
                    return '../uploads/' + cleanPath;
                }
            }

            // Set Resident Avatar
            const avatarContainer = document.getElementById('modalResidentAvatar');
            if (data.resident_profile_image) {
                avatarContainer.innerHTML = `<img class="h-10 w-10 rounded-full object-cover border-2 border-white shadow-sm" src="${upload_url(data.resident_profile_image)}" alt="" onerror="this.onerror=null;this.src='<?php echo base_url('assets/images/default-avatar.png'); ?>';this.parentElement.innerHTML='<div class=\'h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-400\'><i class=\'fas fa-user\'></i></div>'">`;
            } else {
                const initials = (data.first_name.charAt(0) + data.last_name.charAt(0)).toUpperCase();
                avatarContainer.innerHTML = `<div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm border-2 border-white shadow-sm">${initials}</div>`;
            }

            // Set Medicine Image
            const medicineImageContainer = document.getElementById('modalMedicineImage');
            if (data.medicine_image_path) {
                medicineImageContainer.innerHTML = `<img class="w-8 h-8 rounded-lg object-cover border border-gray-200" src="${upload_url(data.medicine_image_path)}" alt="Med">`;
            } else {
                medicineImageContainer.innerHTML = `<div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center text-purple-600"><i class="fas fa-pills text-xs"></i></div>`;
            }

            // Set Proof Image
            const proofSection = document.getElementById('modalProofSection');
            const proofImage = document.getElementById('modalProofImage');
            
            // Always show the section, but update content based on availability
            proofSection.classList.remove('hidden');
            
            if (data.proof_image_path) {
                proofSection.innerHTML = `
                    <div class="flex flex-col">
                        <div class="flex items-center mb-2">
                            <div class="w-8 h-8 rounded-lg bg-teal-100 flex items-center justify-center text-teal-600 mr-3">
                                <i class="fas fa-image text-xs"></i>
                            </div>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Proof of Request</p>
                        </div>
                        <div class="relative rounded-xl overflow-hidden border border-gray-200 bg-gray-50 group-hover:border-blue-200 transition-colors h-64 flex items-center justify-center">
                            <img src="${upload_url(data.proof_image_path)}" alt="Proof" class="max-w-full max-h-full object-contain cursor-pointer hover:opacity-90 transition-opacity" onclick="window.open(this.src, '_blank')">
                            <div class="absolute bottom-0 left-0 right-0 bg-white/90 backdrop-blur-sm text-gray-600 text-[10px] px-3 py-2 text-center pointer-events-none border-t border-gray-100">
                                Click image to view full size
                            </div>
                        </div>
                    </div>`;
            } else {
                proofSection.innerHTML = `
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400 mr-3">
                            <i class="fas fa-image-slash text-xs"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Proof of Request</p>
                            <p class="text-xs text-gray-400 italic mt-0.5">No proof image provided</p>
                        </div>
                    </div>`;
            }
            
            // Reset state
            resetAction();
            
            modal.classList.remove('hidden');
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').classList.add('hidden');
        }
        
        function selectAction(action) {
            document.getElementById('modalAction').value = action;
            document.getElementById('actionSelection').classList.add('hidden');
            
            const footer = document.getElementById('modalFooter');
            
            // Remove existing confirm button if any
            const existingConfirm = document.getElementById('confirmBtn');
            if (existingConfirm) existingConfirm.remove();
            
            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button'; // Prevent auto submit
            confirmBtn.id = 'confirmBtn';
            confirmBtn.className = `w-full sm:w-auto inline-flex justify-center items-center px-4 py-2 border border-transparent shadow-sm text-xs font-bold rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all transform hover:scale-105 uppercase tracking-wide ${action === 'approve' ? 'bg-gradient-to-r from-green-600 to-green-500 hover:from-green-700 hover:to-green-600 focus:ring-green-500' : 'bg-gradient-to-r from-red-600 to-red-500 hover:from-red-700 hover:to-red-600 focus:ring-red-500'}`;
            confirmBtn.innerHTML = action === 'approve' ? '<i class="fas fa-paper-plane mr-1.5"></i> Confirm & Forward' : '<i class="fas fa-times mr-1.5"></i> Confirm Rejection';
            
            confirmBtn.onclick = function() {
                const form = document.getElementById('reviewForm');
                if (action === 'reject') {
                    if (!form.querySelector('[name="reason"]').value.trim()) {
                        Swal.fire('Error', 'Please provide a rejection reason', 'error');
                        return;
                    }
                }
                form.submit();
            };
            
            footer.insertBefore(confirmBtn, footer.firstChild);
            
            if (action === 'approve') {
                document.getElementById('approvalFields').classList.remove('hidden');
                document.getElementById('rejectionFields').classList.add('hidden');
            } else {
                document.getElementById('approvalFields').classList.add('hidden');
                document.getElementById('rejectionFields').classList.remove('hidden');
            }
        }
        
        function resetAction() {
            document.getElementById('modalAction').value = '';
            document.getElementById('actionSelection').classList.remove('hidden');
            document.getElementById('approvalFields').classList.add('hidden');
            document.getElementById('rejectionFields').classList.add('hidden');
            
            const existingConfirm = document.getElementById('confirmBtn');
            if (existingConfirm) existingConfirm.remove();
        }

        function viewDetails(data) {
            // Helper for upload url (same as in openReviewModal)
            function upload_url(path) {
                if (!path) return '';
                const cleanPath = path.replace(/^uploads\//, ''); 
                
                // Profiles are in root uploads (thesis/uploads) -> Go up 2 levels
                if (cleanPath.startsWith('profiles/')) {
                    return '../../uploads/' + cleanPath;
                } 
                // Others (proofs, medicines) are in public uploads (thesis/public/uploads) -> Go up 1 level
                else {
                    return '../uploads/' + cleanPath;
                }
            }

            // Build resident avatar
            let residentAvatar = '';
            if (data.resident_profile_image) {
                residentAvatar = `<img class="h-12 w-12 rounded-full object-cover border-2 border-blue-200 shadow-sm" src="${upload_url(data.resident_profile_image)}" alt="" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\\'h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center text-gray-400\\'><i class=\\'fas fa-user\\'></i></div>'">`;
            } else {
                const initials = (data.first_name.charAt(0) + data.last_name.charAt(0)).toUpperCase();
                residentAvatar = `<div class="h-12 w-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-base border-2 border-blue-200 shadow-sm">${initials}</div>`;
            }

            // Build medicine image
            let medicineImage = '';
            if (data.medicine_image_path) {
                medicineImage = `<img class="h-10 w-10 rounded-lg object-cover border border-gray-200 shadow-sm" src="${upload_url(data.medicine_image_path)}" alt="${data.medicine}" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\\'h-10 w-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-500\\'><i class=\\'fas fa-pills\\'></i></div>'">`;
            } else {
                medicineImage = `<div class="h-10 w-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-500"><i class="fas fa-pills"></i></div>`;
            }

            // Build proof section
            let proofSection = '';
            if (data.proof_image_path) {
                proofSection = `
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Proof of Request</p>
                        <div class="relative rounded-xl overflow-hidden border border-gray-200 bg-gray-50 h-48 flex items-center justify-center">
                            <img src="${upload_url(data.proof_image_path)}" alt="Proof" class="max-w-full max-h-full object-contain cursor-pointer hover:opacity-90 transition-opacity" onclick="window.open(this.src, '_blank')">
                            <div class="absolute bottom-0 left-0 right-0 bg-white/90 backdrop-blur-sm text-gray-600 text-xs px-3 py-2 text-center pointer-events-none border-t border-gray-100">
                                Click image to view full size
                            </div>
                        </div>
                    </div>`;
            } else {
                proofSection = `
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Proof of Request</p>
                        <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                            <i class="fas fa-image text-3xl mb-2"></i>
                            <p class="text-sm">No proof image provided</p>
                        </div>
                    </div>`;
            }

            const requestDate = new Date(data.created_at);
            const updatedDate = new Date(data.updated_at);

            Swal.fire({
                title: 'Request Details',
                html: `
                    <div class="text-left space-y-4">
                        <!-- Resident Info -->
                        <div class="flex items-center p-3 bg-blue-50/50 rounded-xl border border-blue-100">
                            ${residentAvatar}
                            <div class="ml-3 flex-1">
                                <p class="text-xs font-bold text-blue-600 uppercase tracking-wider">Resident</p>
                                <p class="text-base font-bold text-gray-900">${data.first_name} ${data.last_name}</p>
                                <p class="text-xs text-gray-500">ID: #${data.resident_id}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Requested</p>
                                <p class="text-xs font-bold text-gray-700">${requestDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</p>
                                <p class="text-xs text-gray-500">${requestDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</p>
                            </div>
                        </div>

                        <!-- Medicine & Reason -->
                        <div class="grid grid-cols-1 gap-3">
                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                                <div class="flex items-start">
                                    ${medicineImage}
                                    <div class="ml-3">
                                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Medicine Requested</p>
                                        <p class="text-sm font-bold text-gray-900 mt-1">${data.medicine}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Reason</p>
                                <p class="text-sm text-gray-700 italic bg-white p-2 rounded-lg border border-gray-100">${data.reason || 'No reason provided'}</p>
                            </div>
                        </div>

                        <!-- Proof Image -->
                        ${proofSection}

                        <!-- Status Info -->
                        <div class="bg-gray-50 p-3 rounded-xl border border-gray-100">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Status</p>
                                    <p class="font-bold text-sm capitalize ${data.status === 'approved' ? 'text-green-600' : data.status === 'rejected' ? 'text-red-600' : 'text-orange-600'}">${data.status}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Processed Date</p>
                                    <p class="font-medium text-sm">${updatedDate.toLocaleDateString()}</p>
                                </div>
                            </div>
                            ${data.approved_by_name ? `
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <p class="text-xs text-gray-500 mb-1">Processed By</p>
                                    <p class="font-medium text-sm">${data.approved_by_name}</p>
                                </div>` : ''}
                        </div>

                        ${data.rejection_reason ? `
                        <div class="bg-red-50 p-3 rounded-xl border border-red-200">
                            <p class="text-xs text-red-600 font-bold uppercase tracking-wide mb-1">Rejection Reason</p>
                            <p class="text-sm text-red-700">${data.rejection_reason}</p>
                        </div>` : ''}
                        
                        ${data.approval_remarks ? `
                        <div class="bg-green-50 p-3 rounded-xl border border-green-200">
                            <p class="text-xs text-green-600 font-bold uppercase tracking-wide mb-1">Approval Remarks</p>
                            <p class="text-sm text-green-700">${data.approval_remarks}</p>
                        </div>` : ''}
                    </div>
                `,
                confirmButtonText: 'Close',
                width: '600px',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold'
                }
            });
        }
    </script>
</body>
</html>
