<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/ajax_helpers.php';
require_once __DIR__ . '/includes/sidebar.php';

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

// Enhanced Analytics Data
$totalMeds = 0;
$expiringSoon = 0;
$expiring7Days = 0;
$expiring14Days = 0;
$pendingRequests = 0;
$activePrograms = 0;
$totalRequests = 0;
$approvedRequests = 0;
$claimedRequests = 0;
$rejectedRequests = 0;
$approvalRate = 0.0;
$stockOuts = 0;
$lowStockBatches = 0;
$lowStockMedicines = 0;
$totalDispensed = 0;
$todayRequests = 0;
$todayDispensed = 0;
$totalResidents = 0;
$totalUsers = 0;
$avgApprovalTime = 0;
$totalStockValue = 0;
// Request trend rows for multi-status analytics chart
$requestTrendRows = [];
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

try {
    // Active medicines only
    $totalMeds = (int)db()->query('SELECT COUNT(*) AS c FROM medicines WHERE is_active = 1')->fetch()['c'];
} catch (Throwable $e) {}

// Request trend per status over the last 14 days
try {
    $requestTrendRows = db()->query("
        SELECT DATE(created_at) AS d, status, COUNT(*) AS c
        FROM requests
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at), status
        ORDER BY d
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $requestTrendRows = [];
}

try {
    // Expiring batches (only non-expired, non-zero stock)
    $expiringSoon = (int)db()->query("
        SELECT COUNT(*) AS c 
        FROM medicine_batches 
        WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND expiry_date > CURDATE() 
        AND quantity_available > 0
    ")->fetch()['c'];
    
    $expiring7Days = (int)db()->query("
        SELECT COUNT(*) AS c 
        FROM medicine_batches 
        WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
        AND expiry_date > CURDATE() 
        AND quantity_available > 0
    ")->fetch()['c'];
    
    $expiring14Days = (int)db()->query("
        SELECT COUNT(*) AS c 
        FROM medicine_batches 
        WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) 
        AND expiry_date > CURDATE() 
        AND quantity_available > 0
    ")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $pendingRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests WHERE status='submitted'")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $totalRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $approvedRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests WHERE status IN ('approved', 'ready_to_claim', 'claimed')")->fetch()['c'];
    $claimedRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests WHERE status='claimed'")->fetch()['c'];
    $rejectedRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests WHERE status='rejected'")->fetch()['c'];
} catch (Throwable $e) {}

if ($totalRequests > 0) { 
    $approvalRate = round(($approvedRequests / $totalRequests) * 100, 1); 
}

try {
    // Stock outs - medicines with zero or negative total stock
    $stockOuts = (int)db()->query("
        SELECT COUNT(*) AS c 
        FROM (
            SELECT m.id, COALESCE(SUM(b.quantity_available), 0) as qty 
            FROM medicines m 
            LEFT JOIN medicine_batches b ON b.medicine_id = m.id 
            WHERE m.is_active = 1 
            AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
            GROUP BY m.id 
            HAVING qty <= 0
        ) t
    ")->fetch()['c'];
} catch (Throwable $e) {}

try {
    // Low stock batches (non-expired only)
    $lowStockBatches = (int)db()->query("
        SELECT COUNT(*) AS c 
        FROM medicine_batches 
        WHERE quantity_available <= 10 
        AND quantity_available > 0
        AND expiry_date > CURDATE()
    ")->fetch()['c'];
    
    // Low stock medicines (using minimum_stock_level if available, default 10)
    $lowStockMedicines = (int)db()->query("
        SELECT COUNT(DISTINCT m.id) AS c 
        FROM medicines m
        LEFT JOIN medicine_batches b ON b.medicine_id = m.id
        WHERE m.is_active = 1
        AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
        GROUP BY m.id
        HAVING COALESCE(SUM(b.quantity_available), 0) > 0
        AND COALESCE(SUM(b.quantity_available), 0) <= COALESCE(m.minimum_stock_level, 10)
    ")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $activePrograms = (int)db()->query('SELECT COUNT(*) AS c FROM allocation_programs WHERE is_active=1')->fetch()['c'];
} catch (Throwable $e) {}

try {
    // Total dispensed quantity
    $totalDispensed = (int)db()->query("
        SELECT COALESCE(SUM(rf.quantity), 0) AS c 
        FROM request_fulfillments rf
        INNER JOIN requests r ON rf.request_id = r.id
        WHERE r.status IN ('claimed', 'approved', 'ready_to_claim')
    ")->fetch()['c'];
    
    // Today's dispensed
    $todayDispensed = (int)db()->query("
        SELECT COALESCE(SUM(rf.quantity), 0) AS c 
        FROM request_fulfillments rf
        INNER JOIN requests r ON rf.request_id = r.id
        WHERE r.status IN ('claimed', 'approved', 'ready_to_claim')
        AND DATE(rf.created_at) = CURDATE()
    ")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $todayRequests = (int)db()->query("SELECT COUNT(*) AS c FROM requests WHERE DATE(created_at) = CURDATE()")->fetch()['c'];
} catch (Throwable $e) {}

try {
    $totalResidents = (int)db()->query('SELECT COUNT(*) AS c FROM residents')->fetch()['c'];
} catch (Throwable $e) {}

try {
    $totalUsers = (int)db()->query('SELECT COUNT(*) AS c FROM users WHERE role IN ("bhw", "resident")')->fetch()['c'];
} catch (Throwable $e) {}

try {
    // Average approval time (in hours)
    $avgApprovalTime = db()->query("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) AS avg_hours
        FROM requests
        WHERE status IN ('approved', 'ready_to_claim', 'claimed', 'rejected')
        AND updated_at IS NOT NULL
        AND created_at != updated_at
    ")->fetch()['avg_hours'];
    $avgApprovalTime = $avgApprovalTime ? round($avgApprovalTime, 1) : 0;
} catch (Throwable $e) {}

try {
    // Total active stock value (approximate - using batch quantities)
    $totalStockValue = (int)db()->query("
        SELECT COALESCE(SUM(b.quantity_available), 0) AS c 
        FROM medicine_batches b
        INNER JOIN medicines m ON b.medicine_id = m.id
        WHERE m.is_active = 1
        AND b.expiry_date > CURDATE()
        AND b.quantity_available > 0
    ")->fetch()['c'];
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Analytics · Super Admin</title>
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
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header (hidden, using global shell header instead) -->
        <div class="content-header" style="display: none;">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Analytics Dashboard</h1>
                    <p class="text-gray-600 mt-1">Key performance metrics and insights for your inventory and requests</p>
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
                                <a href="<?php echo base_url('super_admin/settings_brand.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    Account Settings
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
            <!-- Primary Statistics Cards (focused on key KPIs) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <!-- Total Medicines Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-total-meds">0</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Total Medicines</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Active inventory</span>
                        </div>
                    </div>
                </div>

                <!-- Expiring Batches Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-orange-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-expiring">0</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Batches Expiring ≤ 30d</p>
                        <div class="space-y-1">
                            <?php if ($expiring7Days > 0): ?>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-red-600 font-medium">⚡ Critical (≤7d):</span>
                                <span class="text-gray-900 font-semibold"><?php echo number_format($expiring7Days); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($expiring14Days > 0): ?>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-orange-600 font-medium">⚠️ Urgent (≤14d):</span>
                                <span class="text-gray-900 font-semibold"><?php echo number_format($expiring14Days); ?></span>
                            </div>
                            <?php endif; ?>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Need attention</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Requests Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-purple-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-pending">0</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Pending Requests</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-purple-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Awaiting review</span>
                        </div>
                    </div>
                </div>

                <!-- Approval Rate Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.3s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-approval"><?php echo number_format($approvalRate, 1); ?>%</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Approval Rate</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            <span class="text-xs text-gray-500"><?php echo number_format($approvedRequests); ?> of <?php echo number_format($totalRequests); ?> requests</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Requests Trend Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.7s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Requests Trend</h3>
                                <p class="text-sm text-gray-600">Last 7 days activity</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Trend</div>
                            <div class="text-sm font-semibold text-blue-600">↗ Growing</div>
                        </div>
                    </div>
                    <div class="relative">
                        <canvas id="reqChart" height="200"></canvas>
                    </div>
                </div>

                <!-- Top Medicines Chart -->
                <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.8s">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Top Medicines</h3>
                                <p class="text-sm text-gray-600">Most requested items</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Popular</div>
                            <div class="text-sm font-semibold text-green-600">Top 5</div>
                        </div>
                    </div>
                    <div class="relative">
                        <canvas id="topMedChart" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Barangay Analytics Chart -->
            <div class="chart-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg mb-8" style="animation-delay: 0.9s">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Requests by Barangay</h3>
                            <p class="text-sm text-gray-600">Geographic distribution</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Distribution</div>
                        <div class="text-sm font-semibold text-purple-600">Top 8</div>
                    </div>
                </div>
                <div class="relative">
                    <canvas id="barangayChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Ensure Chart.js is loaded before initializing analytics charts
        function ensureChartJsLoaded(callback) {
            if (window.Chart) {
                callback();
                return;
            }

            const existing = document.querySelector('script[data-chartjs-global]');
            if (existing) {
                existing.addEventListener('load', () => callback(), { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.async = true;
            script.setAttribute('data-chartjs-global', '1');
            script.onload = () => callback();
            script.onerror = () => console.error('Failed to load Chart.js for analytics page');
            document.head.appendChild(script);
        }

        // Initialize analytics page logic and charts (works for full load and AJAX shell)
        function initAnalyticsPage() {
            // Animate primary stat cards with real data
            const statValues = [<?php echo $totalMeds; ?>, <?php echo $expiringSoon; ?>, <?php echo $pendingRequests; ?>];
            const statIds = ['stat-total-meds','stat-expiring','stat-pending'];
            
            statIds.forEach((id, i) => {
                const el = document.getElementById(id);
                let current = 0;
                const target = statValues[i];
                const increment = Math.max(1, target / 50);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    el.textContent = Math.floor(current);
                }, 30);
            });

            // Real-time clock update
            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: true 
                });
                document.getElementById('last-updated').textContent = timeString;
            }

            // Update clock every minute
            setInterval(updateClock, 60000);

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

            // Add ripple effect to buttons (excluding sidebar and all its children)
            document.querySelectorAll('a, button').forEach(element => {
                // Skip if element is inside sidebar
                if (element.closest('#sidebar, .sidebar, aside')) {
                    return;
                }
                
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

            // Enhanced Chart.js configurations and charts – only after Chart.js is ready
            ensureChartJsLoaded(function () {
                if (!window.Chart) {
                    return;
                }

                Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
                Chart.defaults.color = '#6b7280';
                Chart.defaults.plugins.legend.labels.usePointStyle = true;
                Chart.defaults.plugins.legend.labels.padding = 20;

                // Requests last 14 days chart with status breakdown (advanced funnel view)
                const trendRows = <?php echo json_encode($requestTrendRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                const days = [];
                const submittedCounts = [];
                const approvedCounts = [];
                const claimedCounts = [];
                const rejectedCounts = [];
                
                function countForStatus(rows, date, statusList) {
                    return rows
                        .filter(r => r.d === date && statusList.includes(r.status))
                        .reduce((sum, r) => sum + (parseInt(r.c, 10) || 0), 0);
                }
                
                for (let i = 13; i >= 0; i--) {
                    const dt = new Date();
                    dt.setDate(dt.getDate() - i);
                    const iso = dt.toISOString().slice(0, 10);
                    days.push(dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                    submittedCounts.push(countForStatus(trendRows, iso, ['submitted']));
                    approvedCounts.push(countForStatus(trendRows, iso, ['approved', 'ready_to_claim']));
                    claimedCounts.push(countForStatus(trendRows, iso, ['claimed']));
                    rejectedCounts.push(countForStatus(trendRows, iso, ['rejected', 'cancelled']));
                }

                new Chart(document.getElementById('reqChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: days,
                        datasets: [
                            {
                                label: 'Submitted',
                                data: submittedCounts,
                                backgroundColor: 'rgba(59, 130, 246, 0.6)',
                                borderColor: 'rgba(59, 130, 246, 1)',
                                borderWidth: 1,
                                borderRadius: 6,
                                stack: 'requests'
                            },
                            {
                                label: 'Approved / Ready',
                                data: approvedCounts,
                                backgroundColor: 'rgba(16, 185, 129, 0.6)',
                                borderColor: 'rgba(16, 185, 129, 1)',
                                borderWidth: 1,
                                borderRadius: 6,
                                stack: 'requests'
                            },
                            {
                                label: 'Claimed',
                                data: claimedCounts,
                                type: 'line',
                                borderColor: 'rgba(37, 99, 235, 1)',
                                backgroundColor: 'rgba(37, 99, 235, 0.15)',
                                borderWidth: 3,
                                tension: 0.4,
                                yAxisID: 'y',
                                fill: false,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Rejected / Cancelled',
                                data: rejectedCounts,
                                backgroundColor: 'rgba(239, 68, 68, 0.5)',
                                borderColor: 'rgba(239, 68, 68, 1)',
                                borderWidth: 1,
                                borderRadius: 6,
                                stack: 'requests'
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
                                    padding: 16
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                stacked: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    precision: 0
                                }
                            },
                            x: {
                                stacked: true,
                                grid: {
                                    display: false
                                }
                            }
                        },
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        animation: {
                            duration: 2200,
                            easing: 'easeOutQuart'
                        }
                    }
                });

                // Top requested medicines chart (enhanced - active medicines only)
                const topMed = <?php echo json_encode(db()->query('SELECT m.name n, COUNT(r.id) c FROM medicines m LEFT JOIN requests r ON r.medicine_id=m.id WHERE m.is_active = 1 GROUP BY m.id, m.name ORDER BY c DESC LIMIT 5')->fetchAll() ?: []); ?>;
                
                new Chart(document.getElementById('topMedChart').getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: topMed.map(x => x.n),
                        datasets: [{
                            data: topMed.map(x => parseInt(x.c)),
                            backgroundColor: [
                                'rgba(59, 130, 246, 0.8)',
                                'rgba(16, 185, 129, 0.8)',
                                'rgba(245, 158, 11, 0.8)',
                                'rgba(239, 68, 68, 0.8)',
                                'rgba(107, 114, 128, 0.8)'
                            ],
                            borderColor: [
                                'rgba(59, 130, 246, 1)',
                                'rgba(16, 185, 129, 1)',
                                'rgba(245, 158, 11, 1)',
                                'rgba(239, 68, 68, 1)',
                                'rgba(107, 114, 128, 1)'
                            ],
                            borderWidth: 2,
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
                                    padding: 20,
                                    usePointStyle: true
                                }
                            }
                        },
                        cutout: '60%',
                        animation: {
                            animateRotate: true,
                            animateScale: true,
                            duration: 2000,
                            easing: 'easeOutQuart'
                        }
                    }
                });

                // Requests by barangay chart
                const barangayData = <?php echo json_encode(db()->query('SELECT b.name n, COUNT(r.id) c FROM barangays b LEFT JOIN residents res ON res.barangay_id=b.id LEFT JOIN requests r ON r.resident_id=res.id GROUP BY b.id, b.name ORDER BY c DESC LIMIT 8')->fetchAll() ?: []); ?>;
                
                new Chart(document.getElementById('barangayChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: barangayData.map(x => x.n),
                        datasets: [{
                            label: 'Requests',
                            data: barangayData.map(x => parseInt(x.c)),
                            backgroundColor: 'rgba(147, 51, 234, 0.6)',
                            borderColor: 'rgba(147, 51, 234, 1)',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                precision: 0,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        animation: {
                            duration: 2000,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            });
        }

        // Run init depending on document state (full load vs. AJAX-loaded shell)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAnalyticsPage);
        } else {
            initAnalyticsPage();
        }
        
        // Time update functionality
        function updateTime() {
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
            
            // Check if elements exist
            if (!toggle || !menu || !arrow) {
                return;
            }
            
            // Remove any existing event listeners
            toggle.onclick = null;
            
            // Simple click handler
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
            
            // Close dropdown when clicking outside (use a single event listener)
            if (!window.profileDropdownClickHandler) {
                window.profileDropdownClickHandler = function(e) {
                    const allToggles = document.querySelectorAll('#profile-toggle');
                    const allMenus = document.querySelectorAll('#profile-menu');
                    
                    allToggles.forEach((toggle, index) => {
                        const menu = allMenus[index];
                        if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.add('hidden');
                            const arrow = toggle.querySelector('#profile-arrow');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                    });
                };
                document.addEventListener('click', window.profileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            if (!window.profileDropdownKeyHandler) {
                window.profileDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const allMenus = document.querySelectorAll('#profile-menu');
                        const allArrows = document.querySelectorAll('#profile-arrow');
                        allMenus.forEach(menu => menu.classList.add('hidden'));
                        allArrows.forEach(arrow => arrow.classList.remove('rotate-180'));
                    }
                };
                document.addEventListener('keydown', window.profileDropdownKeyHandler);
            }
        }

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Update time immediately and then every second
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize night mode
            initNightMode();
            
            // Initialize profile dropdown
            initProfileDropdown();
        });
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize profile dropdown
            initProfileDropdown();
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>


