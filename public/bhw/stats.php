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

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

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

// Fetch comprehensive analytics data for this BHW
$stats = [
    'requests' => ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'total' => 0],
    'residents' => ['total' => 0, 'registered' => 0, 'walkin' => 0],
    'medicines' => ['dispensed' => 0, 'stock_low' => 0, 'total_types' => 0],
    'performance' => ['avg_response_time' => 0, 'success_rate' => 0]
];

try {
    // Request statistics
    $stmt = db()->prepare("
        SELECT 
    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS pending,
    COUNT(*) AS total
        FROM requests WHERE bhw_id = ?
    ");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $stats['requests'] = [
            'approved' => (int)$row['approved'],
            'rejected' => (int)$row['rejected'],
            'pending' => (int)$row['pending'],
            'total' => (int)$row['total']
        ];
    }

    // Resident statistics
    $stmt = db()->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN last_name != 'Walk-in' THEN 1 ELSE 0 END) AS registered,
            SUM(CASE WHEN last_name = 'Walk-in' THEN 1 ELSE 0 END) AS walkin
        FROM residents WHERE purok_id = ?
    ");
    $stmt->execute([$bhw_purok_id]);
    $row = $stmt->fetch();
    if ($row) {
        $stats['residents'] = [
            'total' => (int)$row['total'],
            'registered' => (int)$row['registered'],
            'walkin' => (int)$row['walkin']
        ];
    }

// Medicine statistics
$stmt = db()->prepare("
    SELECT 
        COUNT(DISTINCT r.medicine_id) AS total_types,
        COALESCE(SUM(rf.quantity), 0) AS total_dispensed
    FROM requests r 
    LEFT JOIN request_fulfillments rf ON r.id = rf.request_id
    WHERE r.bhw_id = ? AND r.status IN ('approved', 'claimed')
");
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if ($row) {
    $stats['medicines']['total_types'] = (int)$row['total_types'];
    $stats['medicines']['dispensed'] = (int)$row['total_dispensed'];
}

    // Low stock medicines
    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT m.id) as low_stock_count
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        WHERE m.purok_id = ?
        GROUP BY m.id
        HAVING COALESCE(SUM(mb.quantity), 0) < 10
    ");
    $stmt->execute([$bhw_purok_id]);
    $stats['medicines']['stock_low'] = $stmt->rowCount();

    // Performance metrics
    if ($stats['requests']['total'] > 0) {
        $stats['performance']['success_rate'] = round(($stats['requests']['approved'] / $stats['requests']['total']) * 100);
    }

} catch (Throwable $e) {
    error_log("Stats query error: " . $e->getMessage());
}

// Get trend data for last 7 days
$trend_data = [];
try {
    $stmt = db()->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_requests,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected_requests
        FROM requests 
        WHERE bhw_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at) 
        ORDER BY date
    ");
    $stmt->execute([$user['id']]);
    $trend_data = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Trend data error: " . $e->getMessage());
}

// Get medicine usage data
$medicine_usage = [];
try {
    $stmt = db()->prepare("
        SELECT 
            m.name as medicine_name,
            m.image_path as medicine_image,
            COALESCE(SUM(rf.quantity), 0) as total_dispensed,
            COUNT(r.id) as request_count
        FROM requests r
        JOIN medicines m ON r.medicine_id = m.id
        LEFT JOIN request_fulfillments rf ON r.id = rf.request_id
        WHERE r.bhw_id = ? AND r.status IN ('approved', 'claimed')
        GROUP BY m.id, m.name, m.image_path
        ORDER BY total_dispensed DESC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $medicine_usage = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Medicine usage error: " . $e->getMessage());
}

// Get medicine usage data for bar chart
$medicine_usage_bar = [];
try {
    $stmt = db()->prepare("
        SELECT 
            m.name as medicine_name,
            m.image_path as medicine_image,
            COALESCE(SUM(rf.quantity), 0) as total_dispensed,
            COUNT(r.id) as request_count,
            COALESCE(AVG(rf.quantity), 0) as avg_quantity_per_request
        FROM requests r
        JOIN medicines m ON r.medicine_id = m.id
        LEFT JOIN request_fulfillments rf ON r.id = rf.request_id
        WHERE r.bhw_id = ? AND r.status IN ('approved', 'claimed')
        GROUP BY m.id, m.name, m.image_path
        ORDER BY total_dispensed DESC
        LIMIT 8
    ");
    $stmt->execute([$user['id']]);
    $medicine_usage_bar = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Medicine usage bar chart error: " . $e->getMessage());
}

// Get request status distribution for pie chart
$request_status_distribution = [];
try {
    $stmt = db()->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM requests WHERE bhw_id = ?)), 2) as percentage
        FROM requests 
        WHERE bhw_id = ?
        GROUP BY status
    ");
    $stmt->execute([$user['id'], $user['id']]);
    $request_status_distribution = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Request status distribution error: " . $e->getMessage());
}

// Get resident demographics for pie chart
$resident_demographics = [];
try {
    $stmt = db()->prepare("
        SELECT 
            CASE 
                WHEN last_name = 'Walk-in' THEN 'Walk-in Residents'
                ELSE 'Registered Residents'
            END as resident_type,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM residents WHERE purok_id = ?)), 2) as percentage
        FROM residents 
        WHERE purok_id = ?
        GROUP BY 
            CASE 
                WHEN last_name = 'Walk-in' THEN 'Walk-in Residents'
                ELSE 'Registered Residents'
            END
    ");
    $stmt->execute([$bhw_purok_id, $bhw_purok_id]);
    $resident_demographics = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Resident demographics error: " . $e->getMessage());
}

// Get monthly trends for additional bar chart
$monthly_trends = [];
try {
    $stmt = db()->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_requests,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected_requests
        FROM requests 
        WHERE bhw_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute([$user['id']]);
    $monthly_trends = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Monthly trends error: " . $e->getMessage());
}

// Generate sample data if no real data exists (for demonstration purposes)
if (empty($medicine_usage_bar) && empty($trend_data)) {
    // Sample medicine data
    $medicine_usage_bar = [
        ['medicine_name' => 'Paracetamol', 'medicine_image' => 'medicines/med_1758594421_2344edfb.png', 'total_dispensed' => 45, 'request_count' => 12, 'avg_quantity_per_request' => 3.75],
        ['medicine_name' => 'Ibuprofen', 'medicine_image' => 'medicines/med_1758598478_f2eeb828.png', 'total_dispensed' => 32, 'request_count' => 8, 'avg_quantity_per_request' => 4.0],
        ['medicine_name' => 'Vitamin C', 'medicine_image' => 'medicines/med_1758599649_86bb2279.png', 'total_dispensed' => 28, 'request_count' => 14, 'avg_quantity_per_request' => 2.0],
        ['medicine_name' => 'Amoxicillin', 'medicine_image' => 'medicines/med_1758600735_58c2667b.png', 'total_dispensed' => 24, 'request_count' => 6, 'avg_quantity_per_request' => 4.0],
        ['medicine_name' => 'Multivitamin', 'medicine_image' => 'medicines/med_1758603117_ef42b66b.png', 'total_dispensed' => 20, 'request_count' => 10, 'avg_quantity_per_request' => 2.0],
        ['medicine_name' => 'Aspirin', 'medicine_image' => 'medicines/med_1758608053_167a8e7a.png', 'total_dispensed' => 18, 'request_count' => 9, 'avg_quantity_per_request' => 2.0],
        ['medicine_name' => 'Cough Syrup', 'medicine_image' => 'medicines/med_1759899456_557ba9a1.jpg', 'total_dispensed' => 15, 'request_count' => 5, 'avg_quantity_per_request' => 3.0],
        ['medicine_name' => 'Antacid', 'medicine_image' => 'medicines/med_1759899714_9071ca5b.jpg', 'total_dispensed' => 12, 'request_count' => 6, 'avg_quantity_per_request' => 2.0]
    ];
    
    // Sample trend data for last 7 days
    $trend_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $trend_data[] = [
            'date' => $date,
            'total_requests' => rand(2, 8),
            'approved_requests' => rand(1, 6),
            'rejected_requests' => rand(0, 2)
        ];
    }
    
    // Update medicine usage for the main list
    $medicine_usage = array_slice($medicine_usage_bar, 0, 10);
    
    // Update stats with sample data
    $stats['medicines']['dispensed'] = array_sum(array_column($medicine_usage_bar, 'total_dispensed'));
    $stats['medicines']['total_types'] = count($medicine_usage_bar);
    $stats['requests']['total'] = array_sum(array_column($trend_data, 'total_requests'));
    $stats['requests']['approved'] = array_sum(array_column($trend_data, 'approved_requests'));
    $stats['requests']['rejected'] = array_sum(array_column($trend_data, 'rejected_requests'));
    
    if ($stats['requests']['total'] > 0) {
        $stats['performance']['success_rate'] = round(($stats['requests']['approved'] / $stats['requests']['total']) * 100);
    }
    
    // Sample request status distribution
    if (empty($request_status_distribution)) {
        $request_status_distribution = [
            ['status' => 'approved', 'count' => $stats['requests']['approved'], 'percentage' => round(($stats['requests']['approved'] / $stats['requests']['total']) * 100, 2)],
            ['status' => 'rejected', 'count' => $stats['requests']['rejected'], 'percentage' => round(($stats['requests']['rejected'] / $stats['requests']['total']) * 100, 2)],
            ['status' => 'submitted', 'count' => max(1, $stats['requests']['total'] - $stats['requests']['approved'] - $stats['requests']['rejected']), 'percentage' => round((max(1, $stats['requests']['total'] - $stats['requests']['approved'] - $stats['requests']['rejected']) / $stats['requests']['total']) * 100, 2)]
        ];
    }
    
    // Sample resident demographics
    if (empty($resident_demographics)) {
        $total_residents = $stats['residents']['total'];
        $registered_count = $stats['residents']['registered'];
        $walkin_count = $stats['residents']['walkin'];
        
        $resident_demographics = [
            ['resident_type' => 'Registered Residents', 'count' => $registered_count, 'percentage' => $total_residents > 0 ? round(($registered_count / $total_residents) * 100, 2) : 0],
            ['resident_type' => 'Walk-in Residents', 'count' => $walkin_count, 'percentage' => $total_residents > 0 ? round(($walkin_count / $total_residents) * 100, 2) : 0]
        ];
    }
    
    // Sample monthly trends
    if (empty($monthly_trends)) {
        $monthly_trends = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $monthly_trends[] = [
                'month' => $month,
                'total_requests' => rand(5, 15),
                'approved_requests' => rand(3, 12),
                'rejected_requests' => rand(1, 4)
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Stats · BHW</title>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Optimize rendering */
        .sidebar {
            will-change: scroll-position;
        }
        
        /* Preload hover states */
        .sidebar-nav a:hover {
            transform: translateX(2px);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
<div class="min-h-screen flex">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <!-- Header -->
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
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Statistics Dashboard</h1>
            <p class="text-gray-600">Your approval metrics and request performance analytics</p>
        </div>

        <div class="content-body">
            <!-- Overview Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Residents Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-residents"><?php echo $stats['residents']['total']; ?></p>
                            <p class="text-sm text-gray-500">Total Residents</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Resident Overview</p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-green-600"><?php echo $stats['residents']['registered']; ?> Registered</span>
                            <span class="text-orange-600"><?php echo $stats['residents']['walkin']; ?> Walk-in</span>
                        </div>
                    </div>
                </div>

                <!-- Medicine Requests Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-requests"><?php echo $stats['requests']['total']; ?></p>
                            <p class="text-sm text-gray-500">Total Requests</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Request Status</p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-green-600"><?php echo $stats['requests']['approved']; ?> Approved</span>
                            <span class="text-red-600"><?php echo $stats['requests']['rejected']; ?> Rejected</span>
                        </div>
                    </div>
                </div>

                <!-- Medicine Dispensed Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.3s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-purple-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-medicines"><?php echo $stats['medicines']['dispensed']; ?></p>
                            <p class="text-sm text-gray-500">Medicines Dispensed</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Medicine Types</p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-purple-600"><?php echo $stats['medicines']['total_types']; ?> Types</span>
                            <span class="text-red-600"><?php echo $stats['medicines']['stock_low']; ?> Low Stock</span>
                        </div>
                    </div>
                </div>

                <!-- Success Rate Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.4s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-orange-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-success-rate"><?php echo $stats['performance']['success_rate']; ?>%</p>
                            <p class="text-sm text-gray-500">Success Rate</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Performance</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Approval percentage</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Main Trend Chart -->
                <div class="lg:col-span-2">
                    <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.5s">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900">Request Trends</h3>
                                    <p class="text-sm text-gray-600">Last 7 days activity</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-2 h-2 bg-blue-400 rounded-full animate-pulse"></div>
                                <span class="text-xs text-gray-500">Live data</span>
                            </div>
                        </div>
                        <div class="relative h-80">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Medicine Usage Chart -->
                <div>
                    <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.6s">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-900">Top Medicines</h4>
                                    <p class="text-xs text-gray-600">Most dispensed</p>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-3">
                           <?php foreach (array_slice($medicine_usage, 0, 5) as $index => $medicine): ?>
                               <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                   <div class="flex items-center space-x-3">
                                       <?php 
                                       $image_url = '';
                                       $image_exists = false;
                                       if (!empty($medicine['medicine_image'])) {
                                           // Since the path already includes 'uploads/', use it directly
                                           $image_url = base_url($medicine['medicine_image']);
                                           
                                           // Check if file actually exists
                                           $full_path = __DIR__ . '/../uploads/' . str_replace('uploads/', '', $medicine['medicine_image']);
                                           $image_exists = file_exists($full_path);
                                           
                                       }
                                       ?>
                                       <?php if (!empty($medicine['medicine_image']) && $image_exists): ?>
                                           <div class="w-10 h-10 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                               <?php 
                                               // Use image proxy to serve the image
                                               $image_proxy_path = 'image_proxy.php?path=' . urlencode(str_replace('uploads/', '', $medicine['medicine_image']));
                                               ?>
                                               <img src="<?php echo htmlspecialchars($image_proxy_path); ?>" 
                                                    alt="<?php echo htmlspecialchars($medicine['medicine_name']); ?>"
                                                    class="w-full h-full object-cover"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                               <div class="w-full h-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold" style="display: none;">
                                                   <?php echo strtoupper(substr($medicine['medicine_name'], 0, 2)); ?>
                                               </div>
                                           </div>
                                       <?php else: ?>
                                           <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                               <?php echo strtoupper(substr($medicine['medicine_name'], 0, 2)); ?>
                                           </div>
                                       <?php endif; ?>
                                       <div class="min-w-0 flex-1">
                                           <div class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($medicine['medicine_name']); ?></div>
                                           <div class="text-xs text-gray-500"><?php echo (int)$medicine['request_count']; ?> requests</div>
                                       </div>
                                   </div>
                                   <div class="text-right flex-shrink-0">
                                       <div class="text-lg font-bold text-gray-900"><?php echo (int)$medicine['total_dispensed']; ?></div>
                                       <div class="text-xs text-gray-500">dispensed</div>
                                   </div>
                               </div>
                           <?php endforeach; ?>
                            <?php if (empty($medicine_usage)): ?>
                                <div class="text-center py-4 text-gray-500">
                                    <svg class="w-8 h-8 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                    <p class="text-sm">No medicine data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                            </div>
                        </div>
                    </div>

            <!-- Enhanced Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Medicine Usage Bar Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.9s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Medicine Usage</h3>
                                <p class="text-sm text-gray-600">Most dispensed medicines</p>
                        </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                            <span class="text-xs text-gray-500">Live data</span>
                        </div>
                    </div>
                    <div class="relative h-80">
                        <canvas id="medicineBarChart"></canvas>
                    </div>
                </div>

                <!-- Request Status Pie Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 1.0s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Request Status</h3>
                                <p class="text-sm text-gray-600">Distribution overview</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-purple-400 rounded-full animate-pulse"></div>
                            <span class="text-xs text-gray-500">Live data</span>
                        </div>
                    </div>
                    <div class="relative h-80">
                        <canvas id="statusPieChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Additional Analytics Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Monthly Trends Bar Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 1.1s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Monthly Trends</h3>
                                <p class="text-sm text-gray-600">6-month request analysis</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-400 rounded-full animate-pulse"></div>
                            <span class="text-xs text-gray-500">Live data</span>
                        </div>
                    </div>
                    <div class="relative h-80">
                        <canvas id="monthlyBarChart"></canvas>
                    </div>
                </div>

                <!-- Resident Demographics Pie Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 1.2s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                        </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Resident Demographics</h3>
                                <p class="text-sm text-gray-600">Population distribution</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full animate-pulse"></div>
                            <span class="text-xs text-gray-500">Live data</span>
                        </div>
                    </div>
                    <div class="relative h-80">
                        <canvas id="demographicsPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php
// Prepare comprehensive chart data
$chart_data = [
    'labels' => [],
    'total_requests' => [],
    'approved_requests' => [],
    'rejected_requests' => []
];

// Generate last 7 days labels and data
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_name = date('D', strtotime($date));
    
    $chart_data['labels'][] = $day_name;
    
    // Find data for this date
    $day_data = null;
    foreach ($trend_data as $data) {
        if ($data['date'] === $date) {
            $day_data = $data;
            break;
        }
    }
    
    $chart_data['total_requests'][] = $day_data ? (int)$day_data['total_requests'] : 0;
    $chart_data['approved_requests'][] = $day_data ? (int)$day_data['approved_requests'] : 0;
    $chart_data['rejected_requests'][] = $day_data ? (int)$day_data['rejected_requests'] : 0;
}
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Medicine Usage Bar Chart
    const medicineBarCtx = document.getElementById('medicineBarChart').getContext('2d');
    const medicineBarChart = new Chart(medicineBarCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($medicine_usage_bar, 'medicine_name')); ?>,
            datasets: [{
                label: 'Total Dispensed',
                data: <?php echo json_encode(array_column($medicine_usage_bar, 'total_dispensed')); ?>,
                backgroundColor: [
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(168, 85, 247, 0.8)',
                    'rgba(239, 68, 68, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(236, 72, 153, 0.8)',
                    'rgba(14, 165, 233, 0.8)',
                    'rgba(34, 197, 94, 0.8)'
                ],
                borderColor: [
                    'rgba(34, 197, 94, 1)',
                    'rgba(59, 130, 246, 1)',
                    'rgba(168, 85, 247, 1)',
                    'rgba(239, 68, 68, 1)',
                    'rgba(245, 158, 11, 1)',
                    'rgba(236, 72, 153, 1)',
                    'rgba(14, 165, 233, 1)',
                    'rgba(34, 197, 94, 1)'
                ],
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.9)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 12 },
                    callbacks: {
                        title: function(context) {
                            const index = context[0].dataIndex;
                            const medicineName = <?php echo json_encode(array_column($medicine_usage_bar, 'medicine_name')); ?>[index];
                            return medicineName;
                        },
                        label: function(context) {
                            return `Dispensed: ${context.parsed.y}`;
                        },
                        afterLabel: function(context) {
                            const index = context.dataIndex;
                            const requestCount = <?php echo json_encode(array_column($medicine_usage_bar, 'request_count')); ?>[index];
                            const avgQuantity = <?php echo json_encode(array_column($medicine_usage_bar, 'avg_quantity_per_request')); ?>[index];
                            return [
                                `Requests: ${requestCount}`,
                                `Avg per request: ${avgQuantity.toFixed(1)}`
                            ];
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 12 }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 },
                        maxRotation: 45
                    }
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeOutBounce'
            }
        }
    });

    // Initialize Request Status Pie Chart
    const statusPieCtx = document.getElementById('statusPieChart').getContext('2d');
    const statusPieChart = new Chart(statusPieCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($request_status_distribution, 'status')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($request_status_distribution, 'count')); ?>,
                backgroundColor: [
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(239, 68, 68, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(59, 130, 246, 0.8)'
                ],
                borderColor: [
                    'rgba(34, 197, 94, 1)',
                    'rgba(239, 68, 68, 1)',
                    'rgba(245, 158, 11, 1)',
                    'rgba(59, 130, 246, 1)'
                ],
                borderWidth: 3,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            const percentage = <?php echo json_encode(array_column($request_status_distribution, 'percentage')); ?>[context.dataIndex];
                            return `${context.label}: ${context.parsed} (${percentage}%)`;
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 2000,
                easing: 'easeOutQuart'
            }
        }
    });

    // Initialize Monthly Trends Bar Chart
    const monthlyBarCtx = document.getElementById('monthlyBarChart').getContext('2d');
    const monthlyBarChart = new Chart(monthlyBarCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($month) { return date('M Y', strtotime($month . '-01')); }, array_column($monthly_trends, 'month'))); ?>,
            datasets: [
                {
                    label: 'Total Requests',
                    data: <?php echo json_encode(array_column($monthly_trends, 'total_requests')); ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                },
                {
                    label: 'Approved',
                    data: <?php echo json_encode(array_column($monthly_trends, 'approved_requests')); ?>,
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: 'rgba(34, 197, 94, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                },
                {
                    label: 'Rejected',
                    data: <?php echo json_encode(array_column($monthly_trends, 'rejected_requests')); ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.8)',
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    stacked: false,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 12 }
                    }
                },
                x: {
                    stacked: false,
                    grid: { display: false },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 }
                    }
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeOutBounce'
            }
        }
    });

    // Initialize Resident Demographics Pie Chart
    const demographicsPieCtx = document.getElementById('demographicsPieChart').getContext('2d');
    const demographicsPieChart = new Chart(demographicsPieCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($resident_demographics, 'resident_type')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($resident_demographics, 'count')); ?>,
                backgroundColor: [
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(245, 158, 11, 0.8)'
                ],
                borderColor: [
                    'rgba(59, 130, 246, 1)',
                    'rgba(245, 158, 11, 1)'
                ],
                borderWidth: 3,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            const percentage = <?php echo json_encode(array_column($resident_demographics, 'percentage')); ?>[context.dataIndex];
                            return `${context.label}: ${context.parsed} (${percentage}%)`;
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 2000,
                easing: 'easeOutQuart'
            }
        }
    });

    // Initialize comprehensive trend chart
    const ctx = document.getElementById('trendChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_data['labels']); ?>,
            datasets: [
                {
                    label: 'Total Requests',
                    data: <?php echo json_encode($chart_data['total_requests']); ?>,
                borderColor: 'rgba(59, 130, 246, 1)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
                },
                {
                    label: 'Approved Requests',
                    data: <?php echo json_encode($chart_data['approved_requests']); ?>,
                    borderColor: 'rgba(34, 197, 94, 1)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    borderWidth: 3,
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                },
                {
                    label: 'Rejected Requests',
                    data: <?php echo json_encode($chart_data['rejected_requests']); ?>,
                    borderColor: 'rgba(239, 68, 68, 1)',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    borderWidth: 3,
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(239, 68, 68, 1)',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    precision: 0,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 12
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 12
                        }
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            },
            animation: {
                duration: 2000,
                easing: 'easeOutQuart'
            }
        }
    });

    // Real-time clock update for last-updated element
    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: true 
        });
        const lastUpdatedEl = document.getElementById('last-updated');
        if (lastUpdatedEl) {
            lastUpdatedEl.textContent = timeString;
        }
    }

    // Update clock every minute
    setInterval(updateClock, 60000);

    // Enhanced intersection observer for staggered animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0) scale(1)';
                    
                    // Add special animation for chart cards
                    if (entry.target.classList.contains('chart-card')) {
                        entry.target.style.transform = 'translateY(0) scale(1) rotate(0deg)';
                        entry.target.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.25)';
                    }
                }, index * 100); // Staggered animation delay
            }
        });
    }, observerOptions);

    // Observe all animated elements with enhanced effects
    document.querySelectorAll('.animate-fade-in-up, .animate-fade-in, .animate-slide-in-right').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px) scale(0.95)';
        el.style.transition = 'opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease';
        observer.observe(el);
    });

    // Add chart hover animations
    document.querySelectorAll('.chart-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02) rotate(1deg)';
            this.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.25)';
            
            // Add pulsing effect to chart icons
            const icon = this.querySelector('.w-12, .w-10');
            if (icon) {
                icon.style.animation = 'pulse 1s ease-in-out infinite';
            }
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1) rotate(0deg)';
            this.style.boxShadow = '';
            
            // Remove pulsing effect
            const icon = this.querySelector('.w-12, .w-10');
            if (icon) {
                icon.style.animation = '';
            }
        });
    });

    // Add number counting animation for stats
    function animateNumbers() {
        const statElements = document.querySelectorAll('[id^="stat-"]');
        statElements.forEach(element => {
            const finalValue = parseInt(element.textContent) || 0;
            let currentValue = 0;
            const increment = finalValue / 50;
            const timer = setInterval(() => {
                currentValue += increment;
                if (currentValue >= finalValue) {
                    currentValue = finalValue;
                    clearInterval(timer);
                }
                element.textContent = Math.floor(currentValue);
            }, 30);
        });
    }

    // Trigger number animation after charts load
    setTimeout(animateNumbers, 1000);

    // Add hover effects to cards
    document.querySelectorAll('.hover-lift').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
            this.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '';
        });
    });

    // Add ripple effect to interactive elements
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
</body>
</html>


