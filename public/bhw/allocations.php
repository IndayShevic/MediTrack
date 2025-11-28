<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
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
$bhw_barangay_id = $user['barangay_id'] ?? 0;

// If BHW doesn't have barangay_id but has purok_id, get barangay from purok
if ($bhw_barangay_id == 0 && $bhw_purok_id > 0) {
    $purok = db()->query("SELECT barangay_id FROM puroks WHERE id = " . $bhw_purok_id)->fetch(PDO::FETCH_ASSOC);
    if ($purok && isset($purok['barangay_id'])) {
        $bhw_barangay_id = (int)$purok['barangay_id'];
    }
}

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Fetch allocation programs for this BHW's area
// BHW should see programs if:
// 1. Barangay-wide programs with no specific barangay (barangay_id IS NULL) - shows to all
// 2. Barangay-wide programs for their barangay (if they have one)
// 3. Purok-specific programs for their purok
$programs_query = '
    SELECT 
        ap.id,
        ap.program_name,
        ap.quantity_per_senior,
        ap.frequency,
        ap.scope_type,
        ap.claim_window_days,
        ap.is_active,
        m.name AS medicine_name,
        m.id AS medicine_id,
        COUNT(DISTINCT ad.id) AS total_allocations,
        SUM(CASE WHEN ad.status = "pending" THEN 1 ELSE 0 END) AS pending_claims,
        SUM(CASE WHEN ad.status = "claimed" THEN 1 ELSE 0 END) AS claimed_count,
        SUM(CASE WHEN ad.status = "expired" THEN 1 ELSE 0 END) AS expired_count
    FROM allocation_programs ap
    LEFT JOIN medicines m ON m.id = ap.medicine_id
    LEFT JOIN allocation_distributions ad ON ad.program_id = ap.id
    WHERE ap.is_active = 1
        AND (
            -- Barangay-wide programs: show if barangay_id is NULL (all barangays) OR matches BHW barangay
            (ap.scope_type = "barangay" AND (ap.barangay_id IS NULL OR (? > 0 AND ap.barangay_id = ?)))
            OR 
            -- Purok-specific programs: show if matches BHW purok
            (ap.scope_type = "purok" AND ? > 0 AND ap.purok_id = ?)
        )
    GROUP BY ap.id
    ORDER BY ap.created_at DESC
';

$programs = db()->prepare($programs_query);
$programs->execute([$bhw_barangay_id, $bhw_barangay_id, $bhw_purok_id, $bhw_purok_id]);
$programs = $programs->fetchAll();

// Get recent allocation claims (pending for this BHW to process)
$recent_claims_query = '
    SELECT 
        ad.id,
        ad.quantity_allocated,
        ad.quantity_claimed,
        ad.status,
        ad.claim_deadline,
        ad.created_at,
        ap.program_name,
        m.name AS medicine_name,
        r.first_name,
        r.last_name,
        r.id AS resident_id
    FROM allocation_distributions ad
    JOIN allocation_programs ap ON ap.id = ad.program_id
    JOIN medicines m ON m.id = ad.medicine_id
    JOIN residents r ON r.id = ad.resident_id
    WHERE ad.status = "pending"
        AND r.purok_id = ?
    ORDER BY ad.created_at DESC
    LIMIT 20
';

$claims_stmt = db()->prepare($recent_claims_query);
$claims_stmt->execute([$bhw_purok_id]);
$recent_claims = $claims_stmt->fetchAll();

// Calculate statistics
$total_programs = count($programs);
$total_pending_claims = array_sum(array_column($programs, 'pending_claims'));
$total_claimed = array_sum(array_column($programs, 'claimed_count'));

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Allocations · BHW</title>
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
    tailwind.config = { theme: { extend: { fontFamily: { 'sans': ['Inter','system-ui','sans-serif'] } } } }
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
        
        /* Sidebar styles removed - using design-system.css with bhw-theme */
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
<div class="min-h-screen flex">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

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
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Allocations Management</h1>
            <p class="text-gray-600">Senior citizen medicine distribution programs</p>
        </div>

        <div class="content-body">
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Active Programs -->
                <div class="relative group overflow-hidden bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl p-6 border border-blue-200 hover:shadow-xl transition-all duration-300 animate-fade-in-up">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 rounded-full -mr-16 -mt-16 opacity-50 group-hover:scale-150 transition-transform duration-500"></div>
                    <div class="relative">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
                            <span class="px-3 py-1 bg-blue-200 text-blue-700 text-xs font-bold rounded-full">ACTIVE</span>
                        </div>
                        <div>
                            <p class="text-blue-600 text-sm font-semibold mb-1 uppercase tracking-wide">Active Programs</p>
                            <p class="text-4xl font-extrabold text-blue-900"><?php echo $total_programs; ?></p>
                            <p class="text-xs text-blue-600 mt-2">Assigned to your area</p>
                        </div>
                    </div>
                </div>

                <!-- Pending Claims -->
                <div class="relative group overflow-hidden bg-gradient-to-br from-orange-50 to-orange-100 rounded-2xl p-6 border border-orange-200 hover:shadow-xl transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.1s">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-orange-200 rounded-full -mr-16 -mt-16 opacity-50 group-hover:scale-150 transition-transform duration-500"></div>
                    <div class="relative">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <?php if ($total_pending_claims > 0): ?>
                                <span class="px-3 py-1 bg-orange-500 text-white text-xs font-bold rounded-full animate-pulse">ACTION NEEDED</span>
                            <?php else: ?>
                                <span class="px-3 py-1 bg-orange-200 text-orange-700 text-xs font-bold rounded-full">UP TO DATE</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="text-orange-600 text-sm font-semibold mb-1 uppercase tracking-wide">Pending Claims</p>
                            <p class="text-4xl font-extrabold text-orange-900"><?php echo $total_pending_claims; ?></p>
                            <p class="text-xs text-orange-600 mt-2">Waiting for processing</p>
                        </div>
                    </div>
                </div>

                <!-- Total Claimed -->
                <div class="relative group overflow-hidden bg-gradient-to-br from-green-50 to-green-100 rounded-2xl p-6 border border-green-200 hover:shadow-xl transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.2s">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 rounded-full -mr-16 -mt-16 opacity-50 group-hover:scale-150 transition-transform duration-500"></div>
                    <div class="relative">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                            <span class="px-3 py-1 bg-green-200 text-green-700 text-xs font-bold rounded-full">COMPLETED</span>
                        </div>
                        <div>
                            <p class="text-green-600 text-sm font-semibold mb-1 uppercase tracking-wide">Claimed This Month</p>
                            <p class="text-4xl font-extrabold text-green-900"><?php echo $total_claimed; ?></p>
                            <p class="text-xs text-green-600 mt-2">Successfully distributed</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Allocation Programs -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                            Allocation Programs
                        </h2>
                        <p class="text-gray-600 mt-1">Senior citizen distribution programs in your area</p>
                    </div>
                    <?php if (count($programs) > 0): ?>
                        <div class="flex items-center space-x-2 px-4 py-2 bg-blue-50 rounded-xl border border-blue-200">
                            <span class="text-sm font-semibold text-blue-700"><?php echo count($programs); ?> Program<?php echo count($programs) > 1 ? 's' : ''; ?></span>
                        </div>
                    <?php endif; ?>
                    </div>
                
                <?php if (count($programs) > 0): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($programs as $idx => $prog): ?>
                            <div class="group relative bg-white border-2 border-gray-200 rounded-2xl p-6 hover:border-blue-400 hover:shadow-2xl transition-all duration-300 animate-fade-in-up" style="animation-delay: <?php echo ($idx * 0.1); ?>s">
                                <!-- Gradient overlay on hover -->
                                <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-purple-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                
                                <div class="relative">
                                    <!-- Header -->
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-start space-x-3 flex-1">
                                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0">
                                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                            </svg>
                        </div>
                                            <div class="flex-1">
                                                <h3 class="text-xl font-extrabold text-gray-900 mb-1 group-hover:text-blue-600 transition-colors">
                                                    <?php echo htmlspecialchars($prog['program_name']); ?>
                                                </h3>
                                                <div class="flex items-center space-x-2">
                                                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    <p class="text-blue-600 font-semibold"><?php echo htmlspecialchars($prog['medicine_name']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="px-3 py-1.5 bg-gradient-to-r from-green-100 to-emerald-100 text-green-700 text-xs font-bold rounded-full border border-green-200 shadow-sm">
                                            📅 <?php echo ucfirst($prog['frequency']); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Details Grid -->
                                    <div class="grid grid-cols-2 gap-3 mb-4">
                                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-4 border border-blue-200">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                                </svg>
                                                <p class="text-xs text-blue-700 font-bold uppercase tracking-wide">Per Senior</p>
                                            </div>
                                            <p class="text-2xl font-extrabold text-blue-900"><?php echo (int)$prog['quantity_per_senior']; ?> <span class="text-sm font-medium">units</span></p>
                    </div>
                                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-4 border border-purple-200">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                                                <p class="text-xs text-purple-700 font-bold uppercase tracking-wide">Claim Window</p>
                        </div>
                                            <p class="text-2xl font-extrabold text-purple-900"><?php echo (int)$prog['claim_window_days']; ?> <span class="text-sm font-medium">days</span></p>
                    </div>
                </div>

                                    <!-- Stats Bar -->
                                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                        <p class="text-xs text-gray-600 font-semibold uppercase tracking-wide mb-3">Distribution Status</p>
                                        <div class="grid grid-cols-3 gap-3">
                                            <div class="text-center">
                                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mx-auto mb-2 border-2 border-orange-200">
                                                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                                                </div>
                                                <p class="text-xs text-gray-600 font-medium mb-1">Pending</p>
                                                <p class="text-xl font-extrabold text-orange-600"><?php echo (int)$prog['pending_claims']; ?></p>
                                            </div>
                                            <div class="text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-2 border-2 border-green-200">
                                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                    </svg>
                                                </div>
                                                <p class="text-xs text-gray-600 font-medium mb-1">Claimed</p>
                                                <p class="text-xl font-extrabold text-green-600"><?php echo (int)$prog['claimed_count']; ?></p>
                                            </div>
                                            <div class="text-center">
                                                <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mx-auto mb-2 border-2 border-gray-300">
                                                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                </div>
                                                <p class="text-xs text-gray-600 font-medium mb-1">Expired</p>
                                                <p class="text-xl font-extrabold text-gray-600"><?php echo (int)$prog['expired_count']; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-gradient-to-br from-gray-50 to-blue-50 border-2 border-dashed border-gray-300 rounded-3xl p-16 text-center">
                        <div class="w-32 h-32 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                            <svg class="w-16 h-16 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-extrabold text-gray-900 mb-3">No Allocation Programs Yet</h3>
                        <p class="text-gray-600 mb-6 max-w-md mx-auto">No allocation programs have been assigned to your area. Contact the Super Admin to create programs for your barangay.</p>
                        <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-xl hover:shadow-lg transition-all duration-300">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                            Go to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
                </div>

            <!-- Recent Allocation Claims -->
            <?php if (count($recent_claims) > 0): ?>
            <div class="mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Pending Allocation Claims
                        </h2>
                        <p class="text-gray-600 mt-1">Senior citizens waiting to claim their allocated medicines</p>
                        </div>
                    <div class="flex items-center space-x-2 px-4 py-2 bg-orange-50 rounded-xl border border-orange-200">
                        <span class="w-2 h-2 bg-orange-500 rounded-full animate-pulse"></span>
                        <span class="text-sm font-semibold text-orange-700"><?php echo count($recent_claims); ?> Pending</span>
                        </div>
                    </div>
                
                <div class="bg-white border-2 border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-300">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-blue-50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                            <span>Resident</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                            </svg>
                                            <span>Medicine</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                            </svg>
                                            <span>Quantity</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span>Deadline</span>
                                        </div>
                                    </th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($recent_claims as $idx => $claim): ?>
                                    <tr class="hover:bg-blue-50/50 transition-colors duration-150" style="animation: fadeInUp 0.3s ease-out <?php echo ($idx * 0.05); ?>s backwards">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                                    <?php echo strtoupper(substr($claim['first_name'], 0, 1) . substr($claim['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-gray-900">
                                                        <?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($claim['program_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($claim['medicine_name']); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-sm font-bold rounded-lg">
                                                <?php echo (int)$claim['quantity_allocated']; ?> units
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900 font-medium"><?php echo date('M d, Y', strtotime($claim['claim_deadline'])); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($claim['claim_deadline'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-orange-100 to-amber-100 text-orange-700 text-xs font-bold rounded-full border border-orange-200">
                                                <span class="w-2 h-2 bg-orange-500 rounded-full mr-2 animate-pulse"></span>
                                                Pending
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-6 bg-gradient-to-r from-blue-50 to-purple-50 border-2 border-blue-200 rounded-2xl p-6">
                    <div class="flex items-start space-x-4">
                        <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-bold text-gray-900 mb-2">How to Process Claims</h4>
                            <p class="text-sm text-gray-700 mb-3">
                                To process allocation claims, navigate to the <strong>Medicine Requests</strong> page where seniors submit their allocation claims. You can approve and dispense medicines from there.
                            </p>
                            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-xl hover:shadow-lg transition-all duration-300">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                                Go to Medicine Requests
                    </a>
                </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Old time update, night mode, and profile dropdown code removed - now handled by header include

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

        // Add hover effects to cards
        document.querySelectorAll('.hover-lift').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
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
    });
    </script>
</div>
</body>
</html>


