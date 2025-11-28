<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
require_once __DIR__ . '/includes/header.php';
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

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id > 0) {
        // Verify this family addition belongs to a resident in BHW's purok
        $verify = db()->prepare('
            SELECT rfa.*, res.first_name, res.last_name, res.user_id
            FROM resident_family_additions rfa
            JOIN residents res ON res.id = rfa.resident_id
            WHERE rfa.id = ? AND res.purok_id = ? AND rfa.status = "pending"
        ');
        $verify->execute([$id, $bhw_purok_id]);
        $addition = $verify->fetch();
        
        if ($addition) {
            // Construct full name for email notifications
            $full_name = $addition['first_name'];
            if (!empty($addition['middle_initial'])) {
                $full_name .= ' ' . $addition['middle_initial'] . '.';
            }
            $full_name .= ' ' . $addition['last_name'];
            if (!empty($addition['suffix'])) {
                $full_name .= ' ' . $addition['suffix'];
            }

            if ($action === 'approve') {
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    
                    // Move to approved family_members table
                    $insert = $pdo->prepare('
                        INSERT INTO family_members (resident_id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth)
                        SELECT resident_id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth
                        FROM resident_family_additions
                        WHERE id = ?
                    ');
                    $insert->execute([$id]);
                    
                    // Update status
                    $update = $pdo->prepare('
                        UPDATE resident_family_additions 
                        SET status = "approved", bhw_id = ?, approved_at = NOW()
                        WHERE id = ?
                    ');
                    $update->execute([$user['id'], $id]);
                    
                    // Notify Resident via email
                    try {
                        $userStmt = db()->prepare('SELECT email, first_name FROM users WHERE id = ?');
                        $userStmt->execute([$addition['user_id']]);
                        $user = $userStmt->fetch();

                        if ($user && !empty($user['email'])) {
                            $subject = 'Family Member Request Approved';
                            $html = email_template(
                                'Request Approved',
                                'Your request to add a family member has been approved.',
                                "<p><strong>Family Member:</strong> {$full_name}</p>
                                 <p>They have been added to your family profile.</p>",
                                'View Family Members',
                                base_url('resident/family_members.php')
                            );
                            send_email($user['email'], $user['first_name'], $subject, $html);
                        }
                    } catch (Throwable $e) {
                        error_log('Failed to send resident notification email: ' . $e->getMessage());
                    }

                    $pdo->commit();
                    $_SESSION['flash'] = 'Family member approved successfully!';
                    $_SESSION['flash_type'] = 'success';
                    
                    // Clear BHW sidebar notification cache so counts update immediately
                    $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
                    unset($_SESSION[$cache_key], $_SESSION[$cache_key . '_time']);
                    
                } catch (Throwable $e) {
                    if (isset($pdo)) $pdo->rollBack();
                    $_SESSION['flash'] = 'Failed to approve. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                }
            } elseif ($action === 'reject') {
                $reason = trim($_POST['reason'] ?? 'Not verified');
                try {
                    $stmt = db()->prepare('
                        UPDATE resident_family_additions 
                        SET status = "rejected", bhw_id = ?, rejection_reason = ?, rejected_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([$user['id'], $reason, $id]);
                    

                    
                    $_SESSION['flash'] = 'Family member request rejected.';
                    $_SESSION['flash_type'] = 'success';
                    
                    // Clear BHW sidebar notification cache
                    $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
                    unset($_SESSION[$cache_key], $_SESSION[$cache_key . '_time']);

                    // Notify Resident via email
                    try {
                        $userStmt = db()->prepare('SELECT email, first_name FROM users WHERE id = ?');
                        $userStmt->execute([$addition['user_id']]);
                        $user = $userStmt->fetch();

                        if ($user && !empty($user['email'])) {
                            $subject = 'Family Member Request Rejected';
                            $html = email_template(
                                'Request Rejected',
                                'Your request to add a family member has been rejected.',
                                "<p><strong>Family Member:</strong> {$full_name}</p>
                                 <p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
                                 <p>Please contact your BHW for more information.</p>",
                                'View Requests',
                                base_url('resident/family_members.php')
                            );
                            send_email($user['email'], $user['first_name'], $subject, $html);
                        }
                    } catch (Throwable $e) {
                        error_log('Failed to send resident notification email: ' . $e->getMessage());
                    }
                    
                } catch (Throwable $e) {
                    $_SESSION['flash'] = 'Failed to reject. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
    }
    
    redirect_to('bhw/pending_family_additions.php');
}

// Get pending family additions
$pending = db()->prepare('
    SELECT rfa.*, 
           res.first_name as resident_first, 
           res.last_name as resident_last,
           res.phone,
           p.name as purok_name,
           (SELECT COUNT(*) FROM family_members WHERE resident_id = rfa.resident_id) as existing_family_count
    FROM resident_family_additions rfa
    JOIN residents res ON res.id = rfa.resident_id
    JOIN puroks p ON p.id = res.purok_id
    WHERE res.purok_id = ? AND rfa.status = "pending"
    ORDER BY rfa.created_at ASC
');
$pending->execute([$bhw_purok_id]);
$pending_additions = $pending->fetchAll();

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

// Fetch pending requests for notifications
try {
    $stmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name as medicine_name, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id LEFT JOIN residents res ON r.resident_id = res.id WHERE r.status = "submitted" AND res.purok_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_requests_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_requests_list = [];
}

// Fetch pending registrations for notifications
try {
    $stmt = db()->prepare('SELECT id, first_name, last_name, created_at FROM pending_residents WHERE purok_id = ? AND status = "pending" ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_registrations_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_registrations_list = [];
}

// Fetch pending family additions for notifications
try {
    $stmt = db()->prepare('SELECT rfa.id, rfa.first_name, rfa.last_name, rfa.created_at FROM resident_family_additions rfa JOIN residents res ON res.id = rfa.resident_id WHERE res.purok_id = ? AND rfa.status = "pending" ORDER BY rfa.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_family_additions_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_family_additions_list = [];
}

function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pending Family Additions · BHW Dashboard</title>
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
        /* Content Header Styles */
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
        
        .dark .content-header {
            background: #1f2937 !important;
            border-bottom-color: #374151 !important;
        }
        
        .dark .text-gray-900 {
            color: #f9fafb !important;
        }
        
        .dark .text-gray-600 {
            color: #d1d5db !important;
        }
        
        .dark .text-gray-500 {
            color: #9ca3af !important;
        }
        
        .dark .card {
            background: #374151 !important;
            border-color: #4b5563 !important;
        }
        
        .notification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.375rem;
            font-size: 0.6875rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 9999px;
            margin-left: auto;
            animation: pulse-badge 2s ease-in-out infinite;
        }
        
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .sidebar-nav a {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, background-color;
        }
        
        .sidebar-nav a:active {
            transform: scale(0.98);
        }
        
        .sidebar {
            will-change: scroll-position;
        }
        
        .sidebar-nav a:hover {
            transform: translateX(2px);
        }

        /* Enhanced UI/UX Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: calc(200px + 100%) 0; }
        }

        @keyframes ripple {
            0% {
                transform: scale(0);
                opacity: 1;
            }
            100% {
                transform: scale(4);
                opacity: 0;
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out forwards;
        }

        .animate-scale-in {
            animation: scaleIn 0.5s ease-out forwards;
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, box-shadow;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, background-color;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .badge-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .content-header {
            animation: slideInLeft 0.8s ease-out forwards;
        }

        .grid > div {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .grid > div:nth-child(1) { animation-delay: 0.1s; }
        .grid > div:nth-child(2) { animation-delay: 0.2s; }
        .grid > div:nth-child(3) { animation-delay: 0.3s; }
        .grid > div:nth-child(4) { animation-delay: 0.4s; }
        .grid > div:nth-child(5) { animation-delay: 0.5s; }

        .ripple-effect {
            position: relative;
            overflow: hidden;
        }

        .ripple-effect::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .ripple-effect:active::before {
            width: 300px;
            height: 300px;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }

        .modal-backdrop {
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.3);
        }

        .modal-content {
            animation: scaleIn 0.3s ease-out forwards;
        }

        .status-indicator {
            position: relative;
            display: inline-block;
        }

        .status-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: -10px;
            width: 6px;
            height: 6px;
            background: #f59e0b;
            border-radius: 50%;
            transform: translateY(-50%);
            animation: pulse 2s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
    <!-- Sidebar -->
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
        
        <div class="p-6">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Pending Family Additions</h1>
            <p class="text-gray-600">Review and approve family member additions from residents</p>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> animate-fade-in-up">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?php echo $_SESSION['flash']; unset($_SESSION['flash'], $_SESSION['flash_type']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (empty($pending_additions)): ?>
            <div class="card animate-scale-in">
                <div class="card-body text-center py-16">
                    <div class="animate-float">
                        <svg class="w-32 h-32 text-gray-300 mx-auto mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-3xl font-bold gradient-text mb-3">All Caught Up!</h3>
                    <p class="text-gray-600 text-lg">No pending family member additions to review</p>
                    <div class="mt-6">
                        <div class="inline-flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            Great job! All requests processed
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="grid gap-3">
                <?php foreach ($pending_additions as $index => $addition): ?>
                    <?php
                    // Construct full name from components to ensure it displays correctly
                    $fullName = trim(($addition['first_name'] ?? '') . ' ' . 
                                    ($addition['middle_initial'] ? $addition['middle_initial'] . '. ' : '') . 
                                    ($addition['last_name'] ?? '') . 
                                    ($addition['suffix'] ? ' ' . $addition['suffix'] : ''));
                    
                    // Fallback to existing full_name if construction fails (though it shouldn't)
                    if (empty($fullName)) {
                        $fullName = $addition['full_name'] ?? 'Unknown Name';
                    }
                    
                    // Update the addition array so it propagates to json_encode for the modal
                    $addition['full_name'] = $fullName;
                    ?>
                    <div class="card animate-fade-in-up" style="animation-delay: <?php echo ($index * 0.05) + 0.6; ?>s;">
                        <div class="card-body p-4">
                            <div class="flex items-center justify-between">
                                <!-- Left: Avatar and Info -->
                                <div class="flex items-center space-x-4 flex-1">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-sm overflow-hidden">
                                        <?php if (!empty($addition['profile_image'])): 
                                            $img_url = base_url($addition['profile_image']);
                                            if (strpos($addition['profile_image'], 'uploads/') === 0) {
                                                $img_url = base_url('../' . $addition['profile_image']);
                                            }
                                        ?>
                                            <img src="<?php echo htmlspecialchars($img_url); ?>" alt="Profile" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($addition['full_name'], 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($addition['full_name']); ?></h3>
                                            <span class="badge-warning text-xs px-2 py-1">Pending</span>
                                        </div>
                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                            <span><strong><?php echo htmlspecialchars($addition['resident_first'] . ' ' . $addition['resident_last']); ?></strong></span>
                                            <span>•</span>
                                            <span><?php echo htmlspecialchars($addition['relationship']); ?></span>
                                            <span>•</span>
                                            <span><?php echo calculateAge($addition['date_of_birth']); ?> years</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Date and Actions -->
                                <div class="flex items-center space-x-4">
                                    <div class="text-right text-sm text-gray-500 hidden sm:block">
                                        <div><?php echo date('M d, Y', strtotime($addition['created_at'])); ?></div>
                                        <div class="text-xs"><?php echo date('g:i A', strtotime($addition['created_at'])); ?></div>
                                    </div>
                                    
                                    <div class="flex space-x-2">
                                        <button onclick='showDetailsModal(<?php echo json_encode($addition); ?>)' 
                                                class="btn bg-blue-50 text-blue-600 hover:bg-blue-100 ripple-effect px-4 py-2 text-sm font-medium rounded-lg transition-colors">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </button>
                                        <form method="POST" id="approveForm_<?php echo $addition['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?php echo $addition['id']; ?>">
                                            <button type="button" onclick="confirmApprove('approveForm_<?php echo $addition['id']; ?>')" class="btn btn-success ripple-effect px-4 py-2 text-sm font-medium rounded-lg">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </button>
                                        </form>
                                        <button onclick="showRejectModal(<?php echo $addition['id']; ?>, '<?php echo htmlspecialchars(addslashes($addition['full_name'])); ?>')" 
                                                class="btn btn-danger ripple-effect px-4 py-2 text-sm font-medium rounded-lg">
                                            <i class="fas fa-times mr-1"></i> Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Details Modal -->
    <div id="detailsModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="modal-content bg-white rounded-2xl max-w-2xl w-full shadow-2xl overflow-hidden">
            <div class="relative h-32 bg-gradient-to-r from-blue-500 to-purple-600">
                <button onclick="closeDetailsModal()" class="absolute top-4 right-4 text-white hover:text-gray-200 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="px-8 pb-8">
                <div class="relative -mt-16 mb-6 flex justify-between items-end">
                    <div class="w-32 h-32 rounded-full border-4 border-white bg-white shadow-lg overflow-hidden flex items-center justify-center">
                        <img id="detailsImage" src="" alt="Profile" class="w-full h-full object-cover hidden">
                        <div id="detailsInitial" class="w-full h-full bg-gradient-to-br from-blue-100 to-purple-100 flex items-center justify-center text-4xl font-bold text-blue-600"></div>
                    </div>
                    <div class="flex space-x-3 mb-2">
                        <form method="POST" id="detailsApproveForm">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" id="detailsApproveId">
                            <button type="button" onclick="confirmApprove('detailsApproveForm')" class="btn btn-success ripple-effect px-6 py-2.5 text-sm font-medium rounded-lg shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all">
                                <i class="fas fa-check mr-2"></i> Approve Request
                            </button>
                        </form>
                        <button onclick="transferToReject()" 
                                class="btn btn-danger ripple-effect px-6 py-2.5 text-sm font-medium rounded-lg shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all">
                            <i class="fas fa-times mr-2"></i> Reject
                        </button>
                    </div>
                </div>
                
                <div class="mb-8">
                    <h2 id="detailsName" class="text-3xl font-bold text-gray-900 mb-1"></h2>
                    <p class="text-blue-600 font-medium flex items-center">
                        <span class="bg-blue-50 px-3 py-1 rounded-full text-sm">
                            <i class="fas fa-user-plus mr-2"></i>Pending Addition
                        </span>
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-6">
                        <div>
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Personal Information</h4>
                            <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                                <div>
                                    <span class="text-sm text-gray-500 block">Relationship</span>
                                    <span id="detailsRelationship" class="font-medium text-gray-900"></span>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500 block">Date of Birth</span>
                                    <span id="detailsDob" class="font-medium text-gray-900"></span>
                                    <span id="detailsAge" class="text-sm text-gray-500 ml-2"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div>
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Request Information</h4>
                            <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                                <div>
                                    <span class="text-sm text-gray-500 block">Requested By</span>
                                    <span id="detailsRequester" class="font-medium text-gray-900"></span>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500 block">Date Requested</span>
                                    <span id="detailsDate" class="font-medium text-gray-900"></span>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500 block">Current Family Size</span>
                                    <span id="detailsFamilyCount" class="font-medium text-gray-900"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="modal-content bg-white rounded-2xl max-w-md w-full shadow-2xl">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Reject Family Member Addition</h3>
                        <p class="text-sm text-gray-600 mt-1" id="rejectName"></p>
                    </div>
                </div>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                
                <label class="block text-sm font-semibold text-gray-700 mb-3">Reason for Rejection</label>
                <textarea name="reason" required rows="4" 
                          class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-red-100 focus:border-red-500 transition-all duration-200 resize-none"
                          placeholder="Please provide a specific reason for rejecting this family member addition..."></textarea>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="closeRejectModal()" 
                            class="flex-1 btn btn-secondary py-3 font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg">Cancel</button>
                    <button type="submit" class="flex-1 btn btn-danger py-3 font-semibold rounded-lg">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Reject Modal -->
    <div id="bulkRejectModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="modal-content bg-white rounded-2xl max-w-md w-full shadow-2xl">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Bulk Reject All</h3>
                        <p class="text-sm text-gray-600 mt-1">This will reject ALL pending family additions</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <label class="block text-sm font-semibold text-gray-700 mb-3">Reason for Bulk Rejection</label>
                <textarea id="bulkRejectReason" required rows="4" 
                          class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-red-100 focus:border-red-500 transition-all duration-200 resize-none"
                          placeholder="Please provide a specific reason for rejecting all family member additions..."></textarea>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="document.getElementById('bulkRejectModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary py-3 font-semibold">Cancel</button>
                    <button onclick="bulkReject()" class="flex-1 btn btn-danger py-3 font-semibold">Reject All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Helper to calculate age
        function calculateAge(dob) {
            const birthDate = new Date(dob);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const m = today.getMonth() - birthDate.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            return age;
        }

        // Details Modal Functions
        function showDetailsModal(data) {
            const modal = document.getElementById('detailsModal');
            
            // Populate Data
            document.getElementById('detailsApproveId').value = data.id;
            document.getElementById('detailsName').textContent = data.full_name;
            document.getElementById('detailsRelationship').textContent = data.relationship;
            document.getElementById('detailsDob').textContent = new Date(data.date_of_birth).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            document.getElementById('detailsAge').textContent = `(${calculateAge(data.date_of_birth)} years old)`;
            document.getElementById('detailsRequester').textContent = `${data.resident_first} ${data.resident_last}`;
            document.getElementById('detailsDate').textContent = new Date(data.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            document.getElementById('detailsFamilyCount').textContent = data.existing_family_count;

            // Handle Image
            const img = document.getElementById('detailsImage');
            const initial = document.getElementById('detailsInitial');
            
            if (data.profile_image) {
                let imgUrl = '<?php echo base_url(); ?>' + data.profile_image;
                // Fix for root uploads
                if (data.profile_image.startsWith('uploads/')) {
                    imgUrl = '<?php echo base_url('../'); ?>' + data.profile_image;
                }
                img.src = imgUrl;
                img.classList.remove('hidden');
                initial.classList.add('hidden');
            } else {
                img.classList.add('hidden');
                initial.classList.remove('hidden');
                initial.textContent = data.full_name.charAt(0).toUpperCase();
            }

            // Show Modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
                modal.querySelector('.modal-content').style.opacity = '1';
            }, 10);
        }

        function closeDetailsModal() {
            const modal = document.getElementById('detailsModal');
            const content = modal.querySelector('.modal-content');
            content.style.transform = 'scale(0.95)';
            content.style.opacity = '0';
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 200);
        }

        function transferToReject() {
            const id = document.getElementById('detailsApproveId').value;
            const name = document.getElementById('detailsName').textContent;
            closeDetailsModal();
            setTimeout(() => {
                showRejectModal(id, name);
            }, 200);
        }

        // Reject Modal Functions
        function showRejectModal(id, name) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectName').textContent = 'Rejecting: ' + name;
            const modal = document.getElementById('rejectModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            setTimeout(() => {
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
                modal.querySelector('.modal-content').style.opacity = '1';
            }, 10);
        }

        function closeRejectModal() {
            const modal = document.getElementById('rejectModal');
            const content = modal.querySelector('.modal-content');
            content.style.transform = 'scale(0.95)';
            content.style.opacity = '0';
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 200);
        }

        // Close on click outside
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'detailsModal') closeDetailsModal();
                    if (this.id === 'rejectModal') closeRejectModal();
                    if (this.id === 'bulkRejectModal') this.classList.add('hidden');
                }
            });
        });

        // Add ripple effect to buttons
        document.addEventListener('click', function(e) {
            if (e.target.closest('.ripple-effect')) {
                const button = e.target.closest('.ripple-effect');
                const ripple = document.createElement('span');
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                button.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            }
        });

        // Bulk actions
        function approveAll() {
            if (confirm('Are you sure you want to approve ALL pending family additions? This action cannot be undone.')) {
                const forms = document.querySelectorAll('form[action*="approve"]');
                forms.forEach((form, index) => {
                    setTimeout(() => {
                        form.submit();
                    }, index * 100); // Stagger submissions to avoid conflicts
                });
            }
        }

        function showBulkRejectModal() {
            const modal = document.getElementById('bulkRejectModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            setTimeout(() => {
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
            }, 10);
        }

        function bulkReject() {
            const reason = document.getElementById('bulkRejectReason').value.trim();
            if (!reason) {
                alert('Please provide a reason for bulk rejection.');
                return;
            }
            
            if (confirm('Are you sure you want to reject ALL pending family additions? This action cannot be undone.')) {
                const forms = document.querySelectorAll('form');
                forms.forEach((form, index) => {
                    if (form.querySelector('input[name="action"][value="reject"]')) {
                        const reasonInput = form.querySelector('textarea[name="reason"]');
                        if (reasonInput) {
                            reasonInput.value = reason;
                        }
                        setTimeout(() => {
                            form.submit();
                        }, index * 100);
                    }
                });
            }
        }

        // Confirm Approve with SweetAlert2
        function confirmApprove(formId) {
            Swal.fire({
                title: 'Approve Request?',
                text: "This will add the family member to the resident's profile.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, approve it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            });
        }

        // Add CSS for ripple effect
        const style = document.createElement('style');
        style.textContent = `
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
                pointer-events: none;
            }
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            .modal-content {
                transition: transform 0.2s ease-out, opacity 0.2s ease-out;
                transform: scale(0.95);
                opacity: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
