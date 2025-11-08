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

// Approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0) {
        if ($action === 'approve') {
            // allocate 1 unit FEFO
            $q = db()->prepare('SELECT medicine_id FROM requests WHERE id=? AND bhw_id=? AND status="submitted"');
            $q->execute([$id, $user['id']]);
            $row = $q->fetch();
            if ($row) {
                $allocated = fefoAllocate((int)$row['medicine_id'], 1, $id);
                if ($allocated >= 1) {
                    $u = db()->prepare('UPDATE requests SET status="approved" WHERE id=?');
                    $u->execute([$id]);
                    // notify resident
                    $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name, m.name AS medicine_name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id JOIN medicines m ON m.id=rq.medicine_id WHERE rq.id=?");
                    $r->execute([$id]);
                    $rec = $r->fetch();
                    if ($rec && !empty($rec['email'])) {
                        require_once __DIR__ . '/../../config/email_notifications.php';
                        $success = send_medicine_request_approval_to_resident($rec['email'], $rec['name'] ?? 'Resident', $rec['medicine_name'] ?? 'Unknown Medicine');
                        log_email_notification($user['id'], 'medicine_approval', 'Medicine Request Approved', 'Medicine request approval notification sent to resident', $success);
                    }
                }
            }
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            error_log('BHW Medicine Rejection: Request ID: ' . $id . ', Reason: ' . $reason);
            file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Request ID: ' . $id . ', Reason: ' . $reason . "\n", FILE_APPEND);
            
            $u = db()->prepare('UPDATE requests SET status="rejected", rejection_reason=? WHERE id=? AND bhw_id=?');
            $u->execute([$reason, $id, $user['id']]);
            
            // notify resident
            $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name, m.name AS medicine_name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id JOIN medicines m ON m.id=rq.medicine_id WHERE rq.id=?");
            $r->execute([$id]);
            $rec = $r->fetch();
            if ($rec && !empty($rec['email'])) {
                error_log('BHW Medicine Rejection: Sending email to ' . $rec['email'] . ' with reason: ' . $reason);
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Sending email to ' . $rec['email'] . ' with reason: ' . $reason . "\n", FILE_APPEND);
                
                require_once __DIR__ . '/../../config/email_notifications.php';
                $success = send_medicine_request_rejection_to_resident($rec['email'], $rec['name'] ?? 'Resident', $rec['medicine_name'] ?? 'Unknown Medicine', $reason);
                log_email_notification($user['id'], 'medicine_rejection', 'Medicine Request Rejected', 'Medicine request rejection notification sent to resident', $success);
                
                error_log('BHW Medicine Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No'));
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No') . "\n", FILE_APPEND);
            }
        }
    }
    redirect_to('bhw/requests.php');
}

$rows = db()->prepare('SELECT r.id, m.name AS medicine, r.status, r.created_at, r.requested_for, r.patient_name, r.patient_date_of_birth, r.relationship, r.reason, r.proof_image_path, r.rejection_reason, r.updated_at, res.first_name, res.last_name, fm.first_name AS family_first_name, fm.middle_initial AS family_middle_initial, fm.last_name AS family_last_name, fm.relationship AS family_relationship FROM requests r JOIN medicines m ON m.id=r.medicine_id JOIN residents res ON res.id=r.resident_id LEFT JOIN family_members fm ON fm.id=r.family_member_id WHERE r.bhw_id=? ORDER BY r.id DESC');
$rows->execute([$user['id']]);
$reqs = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Requests · BHW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
        /* Sidebar styles removed - using design-system.css with bhw-theme */
        
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
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
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
            <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span style="flex: 1;">Medicine Requests</span>
                <?php if ($notification_counts['pending_requests'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/walkin_dispensing.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Walk-in Dispensing
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Residents & Family
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Registrations</span>
                <?php if ($notification_counts['pending_registrations'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_registrations']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Family Additions</span>
                <?php if (!empty($notification_counts['pending_family_additions'])): ?>
                    <span class="notification-badge"><?php echo (int)$notification_counts['pending_family_additions']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Statistics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
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
                    <h1 class="text-3xl font-bold text-gray-900">Medicine Requests</h1>
                    <p class="text-gray-600 mt-1">Review and approve medicine requests from residents</p>
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
                            <?php 
                            $total_notifications = ($notification_counts['pending_requests'] ?? 0) + ($notification_counts['pending_registrations'] ?? 0) + ($notification_counts['pending_family_additions'] ?? 0);
                            if ($total_notifications > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center"><?php echo $total_notifications; ?></span>
                            <?php endif; ?>
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
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500" style="display:none;">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars(!empty($user['first_name']) ? $user['first_name'] : 'BHW'); ?>
                                </div>
                                <div class="text-xs text-gray-500">Barangay Health Worker</div>
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
                                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'BHW') . ' ' . ($user['last_name'] ?? 'User'))); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($user['email'] ?? 'bhw@example.com'); ?>
                                </div>
                            </div>
                            
                            <!-- Menu Items -->
                            <div class="py-1">
                                <a href="<?php echo base_url('bhw/profile.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
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
            <?php if (!empty($reqs)): ?>
                <!-- Search and Filter -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <div class="relative">
                                <input type="text" id="searchInput" placeholder="Search requests..." 
                                       class="w-full px-4 py-3 pl-12 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                                <svg class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button class="filter-chip active px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="all">
                                All
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="submitted">
                                Pending
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="approved">
                                Approved
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="rejected">
                                Rejected
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Requests Table -->
                <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-blue-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                            </svg>
                                            <span>Request ID</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                            <span>Resident</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                            </svg>
                                            <span>Medicine</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span>Date</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>Status</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center justify-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                                            </svg>
                                            <span>Actions</span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="requestsTableBody">
                                <?php foreach ($reqs as $r): ?>
                                    <tr class="request-row hover:bg-gray-50 transition-colors duration-200" 
                                        data-resident="<?php echo strtolower(htmlspecialchars($r['first_name'] . ' ' . $r['last_name'])); ?>"
                                        data-medicine="<?php echo strtolower(htmlspecialchars($r['medicine'])); ?>"
                                        data-status="<?php echo $r['status']; ?>">
                                        
                                        <!-- Request ID -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center mr-3">
                                                    <span class="text-white text-xs font-bold">#</span>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-mono font-semibold text-gray-900"><?php echo (int)$r['id']; ?></div>
                                                    <div class="text-xs text-gray-500">Request</div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Resident -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></div>
                                        </td>
                                        
                                        <!-- Medicine -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-blue-600"><?php echo htmlspecialchars($r['medicine']); ?></div>
                                        </td>
                                        
                                        <!-- Date -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                                        </td>
                                        
                                        <!-- Status -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($r['status'] === 'submitted'): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    Pending
                                                </span>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    Approved
                                                </span>
                                            <?php elseif ($r['status'] === 'claimed'): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                    </svg>
                                                    Dispensed
                                                </span>
                                            <?php elseif ($r['status'] === 'rejected'): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                    Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                    Rejected
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <div class="flex items-center justify-center space-x-2">
                                                <!-- View Details Button -->
                                                <button onclick="openViewDetailsModal(<?php echo (int)$r['id']; ?>)" class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-blue-500 to-blue-600 text-white text-xs font-medium rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all duration-200 shadow-sm hover:shadow-md">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                    View
                                                </button>
                                                
                                                <?php if ($r['status'] === 'submitted'): ?>
                                                    <!-- Approve Button -->
                                                    <button onclick="approveRequest(<?php echo (int)$r['id']; ?>)" class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-green-600 to-green-700 text-white text-xs font-medium rounded-lg hover:from-green-700 hover:to-green-800 transition-all duration-200 shadow-sm hover:shadow-md">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                        Approve
                                                    </button>
                                                    
                                                    <!-- Reject Button -->
                                                    <button onclick="openRejectModal(<?php echo (int)$r['id']; ?>)" class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-xs font-medium rounded-lg hover:from-red-700 hover:to-red-800 transition-all duration-200 shadow-sm hover:shadow-md">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                        </svg>
                                                        Reject
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-xs">No actions</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- No Results Message -->
                <div id="noResults" class="hidden text-center py-12">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                                                        </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No requests found</h3>
                    <p class="text-gray-600 mb-4">Try adjusting your search or filter criteria.</p>
                    <button onclick="clearFilters()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Clear Filters
                    </button>
                                                </div>
                                            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state-card hover-lift animate-fade-in-up p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">No Requests Found</h3>
                    <p class="text-gray-600 mb-6 text-lg">No medicine requests have been submitted by residents in your assigned area.</p>
                    
                    <div class="bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-xl p-6 max-w-2xl mx-auto">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">What you can do:</h4>
                        <div class="space-y-2 text-left">
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Check if residents are aware of the request system</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Verify your assigned area coverage</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Review resident registrations</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 flex items-center justify-center z-50 p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto animate-scale-in border border-gray-200">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Reject Request</h3>
                            <p class="text-gray-600">Provide a reason for rejection</p>
                        </div>
                    </div>
                    <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form method="post" class="space-y-4">
                    <input type="hidden" name="id" id="rejectRequestId" />
                    <input type="hidden" name="action" value="reject" />
                    
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Rejection Reason</label>
                        <textarea name="reason" rows="4" required
                                  class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-red-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm resize-none" 
                                  placeholder="Enter the reason for rejecting this request..."></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeRejectModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white font-medium rounded-xl hover:from-red-700 hover:to-red-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Reject Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="viewDetailsModal" class="fixed inset-0 bg-transparent hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-3xl max-w-4xl w-full max-h-[90vh] overflow-y-auto shadow-2xl border border-gray-100" style="box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.05);">
            <!-- Header with clean design -->
            <div class="bg-white border-b border-gray-200 rounded-t-3xl p-6">
                <div class="flex justify-between items-center">
                        <div>
                        <h2 class="text-2xl font-bold text-gray-900">Request Details</h2>
                        <p class="text-gray-500 text-sm">Complete medicine request information</p>
                        </div>
                    <button onclick="closeViewDetailsModal()" class="text-gray-400 hover:text-gray-600 p-2 rounded-lg transition-colors duration-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Content -->
            <div class="p-8">
                <div id="viewDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const filterChips = document.querySelectorAll('.filter-chip');
        const requestRows = document.querySelectorAll('.request-row');
        const requestsTableBody = document.getElementById('requestsTableBody');
        const noResults = document.getElementById('noResults');
        const requestCount = document.getElementById('request-count');

        let currentFilter = 'all';
        let currentSearch = '';

        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterRequests();
            });
        }

        // Filter functionality
        filterChips.forEach(chip => {
            chip.addEventListener('click', function() {
                // Update active state
                filterChips.forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                
                currentFilter = this.dataset.filter;
                filterRequests();
            });
        });

        function filterRequests() {
            let visibleCount = 0;
            
            requestRows.forEach(row => {
                const resident = row.dataset.resident;
                const medicine = row.dataset.medicine;
                const status = row.dataset.status;
                
                let matchesSearch = true;
                let matchesFilter = true;
                
                // Check search match
                if (currentSearch) {
                    matchesSearch = resident.includes(currentSearch) || medicine.includes(currentSearch);
                }
                
                // Check filter match
                if (currentFilter !== 'all') {
                    matchesFilter = status === currentFilter;
                }
                
                if (matchesSearch && matchesFilter) {
                    row.style.display = 'table-row';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update count
            if (requestCount) {
                requestCount.textContent = visibleCount;
            }
            
            // Show/hide no results message
            if (visibleCount === 0) {
                if (noResults) noResults.classList.remove('hidden');
                if (requestsTableBody) requestsTableBody.parentElement.parentElement.classList.add('hidden');
            } else {
                if (noResults) noResults.classList.add('hidden');
                if (requestsTableBody) requestsTableBody.parentElement.parentElement.classList.remove('hidden');
            }
        }

        // Clear filters function
        window.clearFilters = function() {
            if (searchInput) searchInput.value = '';
            currentSearch = '';
            currentFilter = 'all';
            
            filterChips.forEach(c => c.classList.remove('active'));
            filterChips[0].classList.add('active');
            
            filterRequests();
        };

        // Reject modal functions (will be redefined below for AJAX)

        // View Details modal functions
        window.openViewDetailsModal = function(requestId) {
            // Find the request data
            const requestData = <?php echo json_encode($reqs); ?>;
            const request = requestData.find(r => r.id == requestId);
            
            if (!request) {
                console.error('Request not found:', requestId);
                return;
            }
            
            // Populate modal content
            populateViewDetailsModal(request);
            
            // Show modal
            document.getElementById('viewDetailsModal').classList.remove('hidden');
            document.getElementById('viewDetailsModal').classList.add('flex');
        };

        window.closeViewDetailsModal = function() {
            document.getElementById('viewDetailsModal').classList.add('hidden');
            document.getElementById('viewDetailsModal').classList.remove('flex');
        };

        function populateViewDetailsModal(request) {
            const content = document.getElementById('viewDetailsContent');
            
            // Determine requested for display
            let requestedForDisplay = '';
            let patientInfo = '';
            
            if (request.requested_for === 'self') {
                requestedForDisplay = 'Self';
                patientInfo = `${request.first_name} ${request.last_name}`;
            } else if (request.requested_for === 'family') {
                if (request.family_first_name) {
                    requestedForDisplay = 'Family Member';
                    patientInfo = `${request.family_first_name} ${request.family_middle_initial || ''} ${request.family_last_name}`;
                } else {
                    requestedForDisplay = 'Family Member';
                    patientInfo = request.patient_name || 'Unknown';
                }
            }
            
            // Status badge
            let statusBadge = '';
            if (request.status === 'submitted') {
                statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Pending</span>';
            } else if (request.status === 'approved') {
                statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Approved</span>';
            } else if (request.status === 'claimed') {
                statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Dispensed</span>';
            } else if (request.status === 'rejected') {
                statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Rejected</span>';
            } else {
                statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Rejected</span>';
            }
            
            // Proof image section
            let proofImageSection = '';
            if (request.proof_image_path && request.proof_image_path.trim() !== '') {
                proofImageSection = `
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-gray-100 p-2 rounded-lg">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Proof Image</h3>
                                <p class="text-gray-600 text-sm">Submitted by resident</p>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 border border-gray-200">
                            <img src="<?php echo base_url(''); ?>${request.proof_image_path}" alt="Proof Image" class="w-full h-auto max-h-96 object-contain rounded-lg shadow-sm" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <div style="display: none;" class="text-center py-8 text-gray-500">
                                <svg class="w-12 h-12 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <p>Image not available</p>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                proofImageSection = `
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-gray-100 p-2 rounded-lg">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Proof Image</h3>
                                <p class="text-gray-600 text-sm">No image submitted</p>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 border border-gray-200 text-center py-8">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <p class="text-gray-500 font-medium">No proof image was submitted</p>
                            <p class="text-gray-400 text-sm mt-1">The resident did not upload any supporting documentation</p>
                        </div>
                    </div>
                `;
            }
            
            // Rejection reason section
            let rejectionSection = '';
            if (request.status === 'rejected' && request.rejection_reason) {
                rejectionSection = `
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-gray-100 p-2 rounded-lg">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Rejection Reason</h3>
                                <p class="text-gray-600 text-sm">Why this request was rejected</p>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-4 border border-gray-200">
                            <p class="text-gray-800">${request.rejection_reason}</p>
                        </div>
                    </div>
                `;
            }
            
            content.innerHTML = `
                <div class="bg-white border border-gray-200 rounded-lg p-8">
                    <!-- Header Section -->
                    <div class="border-b border-gray-200 pb-6 mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-2xl font-bold text-gray-900">Request #${request.id}</h3>
                                    ${statusBadge}
                                </div>
                        <div class="text-sm text-gray-500">
                            Created: ${new Date(request.created_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'short', 
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            })}
                            ${request.updated_at ? ` • Updated: ${new Date(request.updated_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'short', 
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            })}` : ''}
                            </div>
                        </div>

                    <!-- Main Content Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Left Column -->
                        <div class="space-y-6">
                        <!-- Medicine Information -->
                                <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-3">Medicine</h4>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <p class="text-xl font-medium text-gray-900">${request.medicine}</p>
                            </div>
                        </div>

                        <!-- Patient Information -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-3">Patient Information</h4>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-3">
                                    <div>
                                        <span class="text-sm text-gray-500">Requested For:</span>
                                        <p class="text-gray-900">${requestedForDisplay}</p>
                                </div>
                                <div>
                                        <span class="text-sm text-gray-500">Patient Name:</span>
                                        <p class="text-gray-900">${patientInfo}</p>
                                </div>
                                ${request.patient_date_of_birth ? `
                                    <div>
                                        <span class="text-sm text-gray-500">Date of Birth:</span>
                                        <p class="text-gray-900">${new Date(request.patient_date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                </div>
                                ` : ''}
                                ${request.relationship ? `
                                    <div>
                                        <span class="text-sm text-gray-500">Relationship:</span>
                                        <p class="text-gray-900">${request.relationship}</p>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">
                        <!-- Resident Information -->
                                <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-3">Resident</h4>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <p class="text-lg font-medium text-gray-900">${request.first_name} ${request.last_name}</p>
                            </div>
                        </div>

                        ${request.reason ? `
                            <!-- Reason -->
                                <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-3">Reason</h4>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <p class="text-gray-900">${request.reason}</p>
                            </div>
                        </div>
                        ` : ''}

                        ${proofImageSection}

                        ${rejectionSection}
                        </div>
                    </div>
                </div>
            `;
        }

        // Add intersection observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-fade-in, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });

        // Add hover effects to table rows
        document.querySelectorAll('.request-row').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f9fafb';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });

        // Add ripple effect to buttons (excluding sidebar links)
        document.querySelectorAll('a:not(.sidebar-nav a), button').forEach(element => {
            element.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add keyboard navigation for search
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterRequests();
                }
            });
        }

        // Close modal on outside click
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectModal();
            }
        });

        // Close view details modal on outside click
        document.getElementById('viewDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewDetailsModal();
            }
        });

        // Function to update notification badges
        function updateNotificationBadges() {
            fetch('<?php echo base_url('bhw/get_notification_counts.php'); ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const counts = data.counts;
                        
                        // Update Medicine Requests badge
                        const medicineRequestsBadge = document.querySelector('a[href*="requests.php"] .notification-badge');
                        if (counts.pending_requests > 0) {
                            if (medicineRequestsBadge) {
                                medicineRequestsBadge.textContent = counts.pending_requests;
                            } else {
                                // Create badge if it doesn't exist
                                const medicineRequestsLink = document.querySelector('a[href*="requests.php"]');
                                if (medicineRequestsLink) {
                                    const badge = document.createElement('span');
                                    badge.className = 'notification-badge';
                                    badge.textContent = counts.pending_requests;
                                    medicineRequestsLink.appendChild(badge);
                                }
                            }
                        } else {
                            // Remove badge if count is 0
                            if (medicineRequestsBadge) {
                                medicineRequestsBadge.remove();
                            }
                        }
                        
                        // Update Pending Registrations badge
                        const pendingRegistrationsBadge = document.querySelector('a[href*="pending_residents.php"] .notification-badge');
                        if (counts.pending_registrations > 0) {
                            if (pendingRegistrationsBadge) {
                                pendingRegistrationsBadge.textContent = counts.pending_registrations;
                            } else {
                                // Create badge if it doesn't exist
                                const pendingRegistrationsLink = document.querySelector('a[href*="pending_residents.php"]');
                                if (pendingRegistrationsLink) {
                                    const badge = document.createElement('span');
                                    badge.className = 'notification-badge';
                                    badge.textContent = counts.pending_registrations;
                                    pendingRegistrationsLink.appendChild(badge);
                                }
                            }
                        } else {
                            // Remove badge if count is 0
                            if (pendingRegistrationsBadge) {
                                pendingRegistrationsBadge.remove();
                            }
                        }
                        
                        // Update Pending Family Additions badge
                        const pendingFamilyBadge = document.querySelector('a[href*="pending_family_additions.php"] .notification-badge');
                        if (counts.pending_family_additions > 0) {
                            if (pendingFamilyBadge) {
                                pendingFamilyBadge.textContent = counts.pending_family_additions;
                            } else {
                                // Create badge if it doesn't exist
                                const pendingFamilyLink = document.querySelector('a[href*="pending_family_additions.php"]');
                                if (pendingFamilyLink) {
                                    const badge = document.createElement('span');
                                    badge.className = 'notification-badge';
                                    badge.textContent = counts.pending_family_additions;
                                    pendingFamilyLink.appendChild(badge);
                                }
                            }
                        } else {
                            // Remove badge if count is 0
                            if (pendingFamilyBadge) {
                                pendingFamilyBadge.remove();
                            }
                        }
                        
                        // Add visual feedback for badge updates
                        const updatedBadges = document.querySelectorAll('.notification-badge');
                        updatedBadges.forEach(badge => {
                            badge.style.transform = 'scale(1.2)';
                            badge.style.transition = 'transform 0.3s ease';
                            setTimeout(() => {
                                badge.style.transform = 'scale(1)';
                            }, 300);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error updating notification badges:', error);
                });
        }

        // AJAX approve request function
        function approveRequest(requestId) {
            const formData = new FormData();
            formData.append('id', requestId);
            formData.append('action', 'approve');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Update badges immediately
                updateNotificationBadges();
                
                // Reload page to show updated status
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            })
            .catch(error => {
                console.error('Error approving request:', error);
                alert('Error approving request. Please try again.');
            });
        }

        // AJAX reject request function
        function rejectRequest(requestId, reason) {
            const formData = new FormData();
            formData.append('id', requestId);
            formData.append('action', 'reject');
            formData.append('reason', reason);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Update badges immediately
                updateNotificationBadges();
                
                // Close reject modal
                closeRejectModal();
                
                // Reload page to show updated status
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            })
            .catch(error => {
                console.error('Error rejecting request:', error);
                alert('Error rejecting request. Please try again.');
            });
        }

        // Update reject modal form submission
        window.openRejectModal = function(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectModal').classList.remove('hidden');
            document.getElementById('rejectModal').classList.add('flex');
        };

        window.closeRejectModal = function() {
            document.getElementById('rejectModal').classList.add('hidden');
            document.getElementById('rejectModal').classList.remove('flex');
            // Reset form
            document.querySelector('#rejectModal form').reset();
        };

        // Override reject form submission to use AJAX
        document.addEventListener('DOMContentLoaded', function() {
            const rejectForm = document.querySelector('#rejectModal form');
            if (rejectForm) {
                rejectForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const requestId = document.getElementById('rejectRequestId').value;
                    const reason = document.querySelector('#rejectModal textarea[name="reason"]').value;
                    
                    if (!reason.trim()) {
                        alert('Please provide a rejection reason.');
                        return;
                    }
                    
                    rejectRequest(requestId, reason);
                });
            }
        });

        // Header Functions
        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update clock every second
        updateClock();
        setInterval(updateClock, 1000);

        // Night mode functionality
        function initNightMode() {
            const toggle = document.getElementById('night-mode-toggle');
            const body = document.body;
            
            if (!toggle) return;
            
            // Check for saved theme preference or default to light mode
            const currentTheme = localStorage.getItem('theme') || 'light';
            if (currentTheme === 'dark') {
                body.classList.add('dark');
                toggle.innerHTML = `
                    <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z"></path>
                    </svg>
                `;
            }
            
            toggle.addEventListener('click', function() {
                body.classList.toggle('dark');
                
                if (body.classList.contains('dark')) {
                    localStorage.setItem('theme', 'dark');
                    toggle.innerHTML = `
                        <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z"></path>
                        </svg>
                    `;
                } else {
                    localStorage.setItem('theme', 'light');
                    toggle.innerHTML = `
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                    `;
                }
            });
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
            if (!window.bhwRequestsProfileDropdownClickHandler) {
                window.bhwRequestsProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.bhwRequestsProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const menu = document.getElementById('profile-menu');
                    const arrow = document.getElementById('profile-arrow');
                    if (menu) menu.classList.add('hidden');
                    if (arrow) arrow.classList.remove('rotate-180');
                }
            });
        }

        // Initialize night mode and profile dropdown
        initNightMode();
        initProfileDropdown();
    });
    </script>
</body>
</html>


