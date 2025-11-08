<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['bhw']);

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

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('BHW POST data received: ' . print_r($_POST, true));
    file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW POST data: ' . print_r($_POST, true) . "\n", FILE_APPEND);
    $action = $_POST['action'] ?? '';
    $pending_id = (int)($_POST['pending_id'] ?? 0);
    error_log('BHW Action: ' . $action . ', Pending ID: ' . $pending_id);
    file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Action: ' . $action . ', Pending ID: ' . $pending_id . "\n", FILE_APPEND);
    
    if ($action === 'approve' && $pending_id > 0) {
        try {
            error_log('BHW Approval: Starting approval process for pending_id: ' . $pending_id);
            $pdo = db();
            $pdo->beginTransaction();
            
            // Get pending resident data
            $stmt = $pdo->prepare('SELECT * FROM pending_residents WHERE id = ? AND purok_id = ? AND status = "pending"');
            $stmt->execute([$pending_id, $bhw_purok_id]);
            $pending = $stmt->fetch();
            
            if ($pending) {
                // Check if email is verified
                if ((int)$pending['email_verified'] !== 1) {
                    set_flash('Cannot approve: Resident has not verified their email address yet.', 'error');
                    redirect_to('bhw/pending_residents.php');
                }
                // Create user account
                $insUser = $pdo->prepare('INSERT INTO users(email, password_hash, role, first_name, last_name, middle_initial, purok_id) VALUES(?,?,?,?,?,?,?)');
                $insUser->execute([$pending['email'], $pending['password_hash'], 'resident', $pending['first_name'], $pending['last_name'], $pending['middle_initial'], $pending['purok_id']]);
                $userId = (int)$pdo->lastInsertId();
                
                // Create resident record
                $insRes = $pdo->prepare('INSERT INTO residents(user_id, barangay_id, purok_id, first_name, last_name, middle_initial, date_of_birth, email, phone, address) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $insRes->execute([$userId, $pending['barangay_id'], $pending['purok_id'], $pending['first_name'], $pending['last_name'], $pending['middle_initial'], $pending['date_of_birth'], $pending['email'], $pending['phone'], $pending['address']]);
                $residentId = (int)$pdo->lastInsertId();
                
                // Transfer family members
                $familyStmt = $pdo->prepare('SELECT * FROM pending_family_members WHERE pending_resident_id = ?');
                $familyStmt->execute([$pending_id]);
                $familyMembers = $familyStmt->fetchAll();
                
                foreach ($familyMembers as $member) {
                    $insFamily = $pdo->prepare('INSERT INTO family_members(resident_id, first_name, middle_initial, last_name, relationship, date_of_birth) VALUES(?,?,?,?,?,?)');
                    $insFamily->execute([$residentId, $member['first_name'], $member['middle_initial'], $member['last_name'], $member['relationship'], $member['date_of_birth']]);
                }
                
                // Update pending status
                $updateStmt = $pdo->prepare('UPDATE pending_residents SET status = "approved", bhw_id = ?, updated_at = NOW() WHERE id = ?');
                $updateStmt->execute([$user['id'], $pending_id]);
                
                // Send approval email to resident
                $success = send_registration_approval_email($pending['email'], format_full_name($pending['first_name'], $pending['last_name'], $pending['middle_initial'] ?? null));
                log_email_notification($userId, 'registration_approval', 'Registration Approved', 'Resident registration approved', $success);
                
                $pdo->commit();
                error_log('BHW Approval: Successfully approved pending_id: ' . $pending_id);
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Approval: Successfully approved pending_id: ' . $pending_id . "\n", FILE_APPEND);
                set_flash('Resident registration approved successfully.', 'success');
            }
        } catch (Throwable $e) {
            if (isset($pdo)) $pdo->rollBack();
            error_log('BHW Approval Error: ' . $e->getMessage());
            file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Approval Error: ' . $e->getMessage() . "\n", FILE_APPEND);
            set_flash('Failed to approve registration: ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'reject' && $pending_id > 0) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        error_log('BHW Rejection: Starting rejection process for pending_id: ' . $pending_id . ', reason: ' . $reason);
        try {
            // Get pending resident data for email
            $stmt = db()->prepare('SELECT * FROM pending_residents WHERE id = ? AND purok_id = ?');
            $stmt->execute([$pending_id, $bhw_purok_id]);
            $pending = $stmt->fetch();
            
            if ($pending) {
                $stmt = db()->prepare('UPDATE pending_residents SET status = "rejected", bhw_id = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ? AND purok_id = ?');
                $stmt->execute([$user['id'], $reason, $pending_id, $bhw_purok_id]);
                
                // Send rejection email to resident
                error_log('BHW Rejection: Sending email to ' . $pending['email'] . ' with reason: ' . $reason);
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Rejection: Sending email to ' . $pending['email'] . ' with reason: ' . $reason . "\n", FILE_APPEND);
                $success = send_registration_rejection_email($pending['email'], format_full_name($pending['first_name'], $pending['last_name'], $pending['middle_initial'] ?? null), $reason);
                error_log('BHW Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No'));
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No') . "\n", FILE_APPEND);
                log_email_notification(0, 'registration_rejection', 'Registration Rejected', 'Resident registration rejected: ' . $reason, $success);
                
                set_flash('Resident registration rejected.', 'success');
            }
        } catch (Throwable $e) {
            set_flash('Failed to reject registration.', 'error');
        }
    }
    
    redirect_to('bhw/pending_residents.php');
}

// Fetch pending residents for this BHW's purok
$pending_residents = [];
try {
    $stmt = db()->prepare('
        SELECT pr.*, b.name as barangay_name, p.name as purok_name,
               (SELECT COUNT(*) FROM pending_family_members WHERE pending_resident_id = pr.id) as family_count
        FROM pending_residents pr
        JOIN barangays b ON b.id = pr.barangay_id
        JOIN puroks p ON p.id = pr.purok_id
        WHERE pr.purok_id = ? AND pr.status = "pending"
        ORDER BY pr.created_at DESC
    ');
    $stmt->execute([$bhw_purok_id]);
    $pending_residents = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_residents = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Pending Residents - BHW Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        'primary': {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        'success': {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        },
                        'warning': {
                            50: '#fffbeb',
                            100: '#fef3c7',
                            200: '#fde68a',
                            300: '#fcd34d',
                            400: '#fbbf24',
                            500: '#f59e0b',
                            600: '#d97706',
                            700: '#b45309',
                            800: '#92400e',
                            900: '#78350f',
                        },
                        'danger': {
                            50: '#fef2f2',
                            100: '#fee2e2',
                            200: '#fecaca',
                            300: '#fca5a5',
                            400: '#f87171',
                            500: '#ef4444',
                            600: '#dc2626',
                            700: '#b91c1c',
                            800: '#991b1b',
                            900: '#7f1d1d',
                        }
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
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .status-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Toast Styles */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transform: translateX(100%);
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            backdrop-filter: blur(10px);
        }
        .toast.show {
            transform: translateX(0);
        }
        .toast.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .toast.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        .toast.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        .toast.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        .toast-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .toast-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
        }
        .toast-message {
            flex: 1;
        }
        .toast-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .toast-description {
            font-size: 13px;
            opacity: 0.9;
        }
        .toast-close {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .toast-close:hover {
            opacity: 1;
        }
        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 0 0 12px 12px;
            animation: toastProgress 5s linear forwards;
        }
        @keyframes toastProgress {
            from { width: 100%; }
            to { width: 0%; }
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
            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
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
                    <h1 class="text-3xl font-bold text-gray-900">Pending Resident Registrations</h1>
                    <p class="text-gray-600 mt-1">Review and approve resident registration requests for your purok</p>
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

        <!-- Flash Messages -->
        <?php [$flash, $flashType] = get_flash(); if ($flash): ?>
            <div class="mb-6 fade-in">
                <div class="px-4 py-4 rounded-xl border-l-4 <?php echo $flashType === 'success' ? 'bg-success-50 text-success-800 border-success-400' : 'bg-danger-50 text-danger-800 border-danger-400'; ?> shadow-sm">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <?php if ($flashType === 'success'): ?>
                                <svg class="w-5 h-5 text-success-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-danger-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($flash); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pending Residents List -->
        <div class="content-body">
            <?php if (empty($pending_residents)): ?>
                <div class="fade-in">
                    <div class="card card-hover">
                        <div class="card-body text-center py-16">
                            <div class="mx-auto w-24 h-24 bg-gradient-to-br from-primary-100 to-primary-200 rounded-full flex items-center justify-center mb-6">
                                <svg class="w-12 h-12 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-900 mb-3">No Pending Registrations</h3>
                            <p class="text-gray-600 mb-6 max-w-md mx-auto">There are no pending resident registration requests for your purok at this time. New registrations will appear here when residents submit their applications.</p>
                            <div class="flex items-center justify-center space-x-4 text-sm text-gray-500">
                                <div class="flex items-center space-x-2">
                                    <div class="w-2 h-2 bg-success-400 rounded-full"></div>
                                    <span>All caught up!</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    <span>Purok <?php echo $user['purok_id'] ?? 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid gap-6" x-data="{ expanded: {} }">
                    <?php foreach ($pending_residents as $index => $resident): ?>
                        <div class="card card-hover fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s">
                            <div class="card-body">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-4 mb-6">
                                            <div class="relative">
                                                <div class="w-16 h-16 bg-gradient-to-br from-primary-100 to-primary-200 rounded-full flex items-center justify-center shadow-lg">
                                                    <svg class="w-8 h-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                    </svg>
                                                </div>
                                                <div class="absolute -top-1 -right-1 w-6 h-6 bg-warning-400 rounded-full flex items-center justify-center">
                                                    <span class="text-xs font-bold text-white">!</span>
                                                </div>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-3 mb-2">
                                                    <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(format_full_name($resident['first_name'], $resident['last_name'], $resident['middle_initial'] ?? null)); ?></h3>
                                                    <span class="px-3 py-1 bg-warning-100 text-warning-800 text-xs font-semibold rounded-full">PENDING</span>
                                                    <?php if ((int)$resident['email_verified'] === 1): ?>
                                                        <span class="px-3 py-1 bg-success-100 text-success-800 text-xs font-semibold rounded-full">✓ EMAIL VERIFIED</span>
                                                    <?php else: ?>
                                                        <span class="px-3 py-1 bg-danger-100 text-danger-800 text-xs font-semibold rounded-full">✗ EMAIL NOT VERIFIED</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="space-y-1">
                                                    <div class="flex items-center space-x-2 text-sm text-gray-600">
                                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                        </svg>
                                                        <span><?php echo htmlspecialchars($resident['email']); ?></span>
                                                    </div>
                                                    <div class="flex items-center space-x-2 text-sm text-gray-500">
                                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        </svg>
                                                        <span><?php echo htmlspecialchars($resident['barangay_name'] . ' - ' . $resident['purok_name']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Details Grid -->
                                        <div class="bg-gray-50 rounded-xl p-4 mb-6">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div class="space-y-3">
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-8 h-8 bg-primary-100 rounded-lg flex items-center justify-center">
                                                            <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Phone</p>
                                                            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($resident['phone'] ?: 'Not provided'); ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-8 h-8 bg-success-100 rounded-lg flex items-center justify-center">
                                                            <svg class="w-4 h-4 text-success-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Date of Birth</p>
                                                            <p class="text-sm font-semibold text-gray-900"><?php echo date('M j, Y', strtotime($resident['date_of_birth'])); ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="space-y-3">
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-8 h-8 bg-warning-100 rounded-lg flex items-center justify-center">
                                                            <svg class="w-4 h-4 text-warning-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Address</p>
                                                            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($resident['address'] ?: 'Not provided'); ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-8 h-8 bg-primary-100 rounded-lg flex items-center justify-center">
                                                            <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Family Members</p>
                                                            <p class="text-sm font-semibold text-gray-900"><?php echo (int)$resident['family_count']; ?> member<?php echo (int)$resident['family_count'] !== 1 ? 's' : ''; ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Family Members Section -->
                                        <?php if ($resident['family_count'] > 0): ?>
                                            <div class="mb-6" x-data="{ showFamily: false }">
                                                <button @click="showFamily = !showFamily" class="flex items-center space-x-2 text-sm font-medium text-primary-600 hover:text-primary-700 mb-3">
                                                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': showFamily }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                    <span>View Family Members (<?php echo (int)$resident['family_count']; ?>)</span>
                                                </button>
                                                
                                                <div x-show="showFamily" x-transition class="bg-primary-50 rounded-xl p-4">
                                                    <?php
                                                    $familyStmt = db()->prepare('SELECT * FROM pending_family_members WHERE pending_resident_id = ?');
                                                    $familyStmt->execute([$resident['id']]);
                                                    $familyMembers = $familyStmt->fetchAll();
                                                    ?>
                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                        <?php foreach ($familyMembers as $member): ?>
                                                            <div class="bg-white rounded-lg p-3 border border-primary-200">
                                                                <div class="flex items-center justify-between">
                                                                    <div>
                                                                        <p class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($member['full_name']); ?></p>
                                                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($member['relationship']); ?></p>
                                                                    </div>
                                                                    <span class="px-2 py-1 bg-primary-100 text-primary-800 text-xs font-medium rounded-full">
                                                                        DOB: <?php echo $member['date_of_birth']; ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Submission Info -->
                                        <div class="flex items-center justify-between text-xs text-gray-500 mb-6">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>Submitted: <?php echo date('M j, Y g:i A', strtotime($resident['created_at'])); ?></span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <div class="w-2 h-2 bg-warning-400 rounded-full animate-pulse"></div>
                                                <span>Awaiting Review</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="flex flex-col space-y-3 ml-6">
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="approve" />
                                            <input type="hidden" name="pending_id" value="<?php echo (int)$resident['id']; ?>" />
                                            <button type="submit" <?php echo (int)$resident['email_verified'] !== 1 ? 'disabled title="Email not verified yet"' : ''; ?> class="w-full px-6 py-3 <?php echo (int)$resident['email_verified'] === 1 ? 'bg-gradient-to-r from-success-500 to-success-600 hover:from-success-600 hover:to-success-700' : 'bg-gray-300 cursor-not-allowed'; ?> text-white font-semibold rounded-xl transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl flex items-center justify-center space-x-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span><?php echo (int)$resident['email_verified'] === 1 ? 'Approve' : 'Email Not Verified'; ?></span>
                                            </button>
                                        </form>
                                        
                                        <button type="button" class="w-full px-6 py-3 bg-gradient-to-r from-danger-500 to-danger-600 text-white font-semibold rounded-xl hover:from-danger-600 hover:to-danger-700 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl flex items-center justify-center space-x-2" onclick="showRejectModal(<?php echo (int)$resident['id']; ?>)">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            <span>Reject</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Toast Container -->
    <div id="toast-container"></div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 hidden items-center justify-center z-50" x-data="{ show: false }" x-show="show" x-transition>
        <div class="bg-white rounded-2xl p-8 w-full max-w-lg mx-4 shadow-2xl" @click.away="show = false">
            <div class="flex items-center space-x-3 mb-6">
                <div class="w-12 h-12 bg-danger-100 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-danger-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Reject Registration</h3>
                    <p class="text-sm text-gray-600">Please provide a reason for rejection</p>
                </div>
            </div>
            
            <form method="post" id="rejectForm">
                <input type="hidden" name="action" value="reject" />
                <input type="hidden" name="pending_id" id="rejectPendingId" />
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Reason for rejection:</label>
                    <textarea 
                        name="rejection_reason" 
                        class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:border-danger-500 focus:ring-2 focus:ring-danger-200 transition-colors duration-200 resize-none" 
                        rows="4" 
                        placeholder="Please provide a clear reason for rejection so the resident can understand and potentially reapply..."
                        required
                    ></textarea>
                    <p class="text-xs text-gray-500 mt-2">This reason will be sent to the resident via email.</p>
                </div>
                
                <div class="flex justify-end space-x-4">
                    <button 
                        type="button" 
                        class="px-6 py-3 bg-gray-100 text-gray-700 font-semibold rounded-xl hover:bg-gray-200 transition-colors duration-200"
                        onclick="hideRejectModal()"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit" 
                        class="px-6 py-3 bg-gradient-to-r from-danger-500 to-danger-600 text-white font-semibold rounded-xl hover:from-danger-600 hover:to-danger-700 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl"
                    >
                        Reject Registration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toast Notification System - Version 2.0
        console.log('Toast system loaded - Version 2.0');
        class ToastManager {
            constructor() {
                this.container = document.getElementById('toast-container');
                this.toasts = [];
            }

            show(type, title, description = '', duration = 5000) {
                const toast = this.createToast(type, title, description, duration);
                this.container.appendChild(toast);
                this.toasts.push(toast);

                // Trigger animation
                setTimeout(() => toast.classList.add('show'), 100);

                // Auto remove
                setTimeout(() => this.remove(toast), duration);
            }

            createToast(type, title, description, duration) {
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                
                const icons = {
                    success: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
                    error: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>',
                    warning: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>',
                    info: '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                };

                toast.innerHTML = `
                    <div class="toast-content">
                        <div class="toast-icon">${icons[type] || icons.info}</div>
                        <div class="toast-message">
                            <div class="toast-title">${title}</div>
                            ${description ? `<div class="toast-description">${description}</div>` : ''}
                        </div>
                        <div class="toast-close" onclick="toastManager.remove(this.parentElement.parentElement)">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="toast-progress"></div>
                `;

                return toast;
            }

            remove(toast) {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                    const index = this.toasts.indexOf(toast);
                    if (index > -1) {
                        this.toasts.splice(index, 1);
                    }
                }, 300);
            }

            clear() {
                this.toasts.forEach(toast => this.remove(toast));
            }
        }

        // Initialize toast manager
        const toastManager = new ToastManager();

        // Custom confirmation function using toast
        function showConfirmation(message, onConfirm, onCancel = null) {
            // Create a custom modal for confirmation
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-8 w-full max-w-md mx-4 shadow-2xl">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-12 h-12 bg-warning-100 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-warning-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Confirm Action</h3>
                            <p class="text-sm text-gray-600">Please confirm your action</p>
                        </div>
                    </div>
                    <p class="text-gray-700 mb-6">${message}</p>
                    <div class="flex justify-end space-x-4">
                        <button id="confirm-cancel" class="px-6 py-3 bg-gray-100 text-gray-700 font-semibold rounded-xl hover:bg-gray-200 transition-colors duration-200">
                            Cancel
                        </button>
                        <button id="confirm-ok" class="px-6 py-3 bg-gradient-to-r from-success-500 to-success-600 text-white font-semibold rounded-xl hover:from-success-600 hover:to-success-700 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl">
                            Confirm
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // Add event listeners
            modal.querySelector('#confirm-ok').addEventListener('click', () => {
                document.body.removeChild(modal);
                if (onConfirm) onConfirm();
            });

            modal.querySelector('#confirm-cancel').addEventListener('click', () => {
                document.body.removeChild(modal);
                if (onCancel) onCancel();
            });

            // Close on outside click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    document.body.removeChild(modal);
                    if (onCancel) onCancel();
                }
            });

            // Close on Escape key
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    document.body.removeChild(modal);
                    document.removeEventListener('keydown', handleEscape);
                    if (onCancel) onCancel();
                }
            };
            document.addEventListener('keydown', handleEscape);
        }

        function showRejectModal(pendingId) {
            console.log('Showing reject modal for pending ID:', pendingId);
            document.getElementById('rejectPendingId').value = pendingId;
            const modal = document.getElementById('rejectModal');
            if (modal) {
                // Remove any conflicting classes and ensure proper display
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                modal.style.position = 'fixed';
                modal.style.top = '0';
                modal.style.left = '0';
                modal.style.width = '100%';
                modal.style.height = '100%';
                modal.style.zIndex = '9999';
                modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                modal.style.alignItems = 'center';
                modal.style.justifyContent = 'center';
                
                console.log('Reject modal shown');
                
                // Focus on textarea
                setTimeout(() => {
                    const textarea = modal.querySelector('textarea');
                    if (textarea) textarea.focus();
                }, 100);
            } else {
                console.error('Reject modal not found!');
                alert('Reject modal not found!');
            }
        }
        
        function hideRejectModal() {
            const modal = document.getElementById('rejectModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                
                // Clear form
                const form = document.getElementById('rejectForm');
                if (form) form.reset();
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideRejectModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideRejectModal();
            }
        });
        
        // Add loading states to buttons and replace confirm dialogs
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    console.log('Form submission detected');
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const action = form.querySelector('input[name="action"]').value;
                    console.log('Form action:', action);
                    
                    if (action === 'approve') {
                        e.preventDefault();
                        showConfirmation(
                            'Are you sure you want to approve this registration?',
                            () => {
                                if (submitBtn) {
                                    submitBtn.disabled = true;
                                    submitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
                                }
                                form.submit();
                            }
                        );
                    } else {
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
                        }
                    }
                });
            });

            // Show success/error toasts based on flash messages
            <?php if (isset($flash) && $flash): ?>
                toastManager.show('<?php echo $flashType === 'success' ? 'success' : 'error'; ?>', '<?php echo $flashType === 'success' ? 'Success!' : 'Error!'; ?>', '<?php echo addslashes($flash); ?>');
            <?php endif; ?>
            
            // Function to update notification badges
            function updateNotificationBadges() {
                fetch('<?php echo base_url('bhw/get_notification_counts.php'); ?>')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const counts = data.counts;
                            
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
            
            // Update badges after approve/reject actions
            const approveButtons = document.querySelectorAll('button[onclick*="approveResident"]');
            const rejectButtons = document.querySelectorAll('button[onclick*="showRejectModal"]');
            
            approveButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(updateNotificationBadges, 1000);
                });
            });
            
            rejectButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(updateNotificationBadges, 1000);
                });
            });
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
            if (!window.bhwPendingResidentsProfileDropdownClickHandler) {
                window.bhwPendingResidentsProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.bhwPendingResidentsProfileDropdownClickHandler);
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
    </script>
</body>
</html>
