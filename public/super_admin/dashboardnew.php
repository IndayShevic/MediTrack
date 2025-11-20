<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

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

// Get fresh user data for profile section
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Fetch real dashboard data with error handling
try {
    $total_medicines = db()->query('SELECT COUNT(*) as count FROM medicines WHERE is_active = 1')->fetch()['count'];
} catch (Exception $e) {
    $total_medicines = 0;
}

try {
    // Total stock units (available, non-expired)
    $total_stock_result = db()->query('
        SELECT COALESCE(SUM(quantity_available), 0) as total 
        FROM medicine_batches 
        WHERE quantity_available > 0 AND expiry_date > CURDATE()
    ')->fetch();
    $total_stock_units = (int)($total_stock_result['total'] ?? 0);
} catch (Exception $e) {
    $total_stock_units = 0;
}

try {
    // Low stock medicines (below minimum_stock_level or 0 stock)
    $low_stock_result = db()->query('
        SELECT COUNT(DISTINCT m.id) as count 
        FROM medicines m 
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
        WHERE m.is_active = 1
        AND (mb.expiry_date IS NULL OR mb.expiry_date > CURDATE())
        GROUP BY m.id, m.minimum_stock_level
        HAVING COALESCE(SUM(mb.quantity_available), 0) < COALESCE(m.minimum_stock_level, 10) 
        OR COALESCE(SUM(mb.quantity_available), 0) = 0
    ')->fetchAll();
    $low_stock_medicines = count($low_stock_result);
} catch (Exception $e) {
    $low_stock_medicines = 0;
}

try {
    // Today's dispensed units
    $today_dispensed_result = db()->query('
        SELECT COALESCE(SUM(ABS(quantity)), 0) as total 
        FROM inventory_transactions 
        WHERE transaction_type = "OUT" 
        AND DATE(created_at) = CURDATE()
    ')->fetch();
    $today_dispensed = (int)($today_dispensed_result['total'] ?? 0);
} catch (Exception $e) {
    $today_dispensed = 0;
}

try {
    // Total requests (all time)
    $total_requests = db()->query('SELECT COUNT(*) as count FROM requests')->fetch()['count'];
} catch (Exception $e) {
    $total_requests = 0;
}

try {
    // Pending requests
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "submitted"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
}

// Fetch recent activity data with error handling
try {
    $recent_medicines = db()->query('SELECT name, created_at FROM medicines ORDER BY created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_medicines = [];
}

try {
    $recent_users = db()->query('SELECT CONCAT(IFNULL(first_name,"")," ",IFNULL(last_name,"")) as name, role, created_at FROM users WHERE role = "bhw" ORDER BY created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_users = [];
}

try {
    $recent_requests = db()->query('SELECT r.id, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name, r.status, r.created_at FROM requests r LEFT JOIN residents res ON r.resident_id = res.id ORDER BY r.created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_requests = [];
}

// Fetch comprehensive chart data with error handling
try {
    // Last 30 days: Requests and Dispensed (for combination chart)
    $request_dispensed_trends = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        
        // Get request count for this date
        $req_stmt = db()->prepare('SELECT COUNT(*) as count FROM requests WHERE DATE(created_at) = ?');
        $req_stmt->execute([$date]);
        $req_result = $req_stmt->fetch();
        $request_count = (int)($req_result['count'] ?? 0);
        
        // Get dispensed units for this date
        $disp_stmt = db()->prepare('
            SELECT COALESCE(SUM(ABS(quantity)), 0) as total 
            FROM inventory_transactions 
            WHERE transaction_type = "OUT" AND DATE(created_at) = ?
        ');
        $disp_stmt->execute([$date]);
        $disp_result = $disp_stmt->fetch();
        $dispensed_units = (int)($disp_result['total'] ?? 0);
        
        $request_dispensed_trends[] = [
            'date' => $date,
            'request_count' => $request_count,
            'dispensed_units' => $dispensed_units
        ];
    }
} catch (Exception $e) {
    $request_dispensed_trends = [];
}

try {
    // Top medicines by dispensed quantity (last 30 days)
    $top_dispensed_medicines = db()->query('
        SELECT 
            m.name,
            COALESCE(SUM(CASE WHEN it.transaction_type = "OUT" THEN ABS(it.quantity) ELSE 0 END), 0) as dispensed_units
        FROM medicines m
        LEFT JOIN inventory_transactions it ON m.id = it.medicine_id 
            AND it.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND it.transaction_type = "OUT"
        WHERE m.is_active = 1
        GROUP BY m.id, m.name
        HAVING dispensed_units > 0
        ORDER BY dispensed_units DESC
        LIMIT 10
    ')->fetchAll();
} catch (Exception $e) {
    $top_dispensed_medicines = [];
}

try {
    // Request status distribution
    $request_status_dist = db()->query('
        SELECT 
            status,
            COUNT(*) as count
        FROM requests
        GROUP BY status
    ')->fetchAll();
} catch (Exception $e) {
    $request_status_dist = [];
}

try {
    // Stock levels distribution (histogram data)
    $stock_distribution = db()->query('
        SELECT 
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "Out of Stock"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 10 THEN "1-10 units"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 50 THEN "11-50 units"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 100 THEN "51-100 units"
                ELSE "100+ units"
            END as stock_range,
            COUNT(DISTINCT m.id) as medicine_count
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
            AND mb.expiry_date > CURDATE()
        WHERE m.is_active = 1
        GROUP BY m.id
    ')->fetchAll();
} catch (Exception $e) {
    $stock_distribution = [];
}

try {
    // Monthly trends (last 6 months)
    $monthly_trends = db()->query('
        SELECT 
            DATE_FORMAT(created_at, "%Y-%m") as month,
            DATE_FORMAT(created_at, "%b %Y") as month_label,
            COUNT(*) as request_count,
            COALESCE((
                SELECT SUM(ABS(quantity)) 
                FROM inventory_transactions 
                WHERE transaction_type = "OUT" 
                AND DATE_FORMAT(created_at, "%Y-%m") = DATE_FORMAT(r.created_at, "%Y-%m")
            ), 0) as dispensed_units
        FROM requests r
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, "%Y-%m"), DATE_FORMAT(created_at, "%b %Y")
        ORDER BY month
    ')->fetchAll();
} catch (Exception $e) {
    $monthly_trends = [];
}

// Greeting
$greeting = 'Welcome back';

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MediTrack</title>
    <script src="https://cdn.tailwindcss.com" onerror="console.error('Tailwind CSS failed to load')"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onerror="console.error('Font Awesome failed to load')">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" onerror="console.error('Chart.js failed to load')"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .sidebar-link:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .sidebar-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        /* Sidebar collapse */
        .sidebar {
            width: 16rem; /* w-64 */
        }
        .sidebar-collapsed .sidebar {
            width: 4rem; /* w-16 */
        }
        .sidebar-collapsed .brand-text,
        .sidebar-collapsed .link-text,
        .sidebar-collapsed .user-info {
            display: none;
        }
        .sidebar-collapsed .sidebar-link {
            justify-content: center;
        }
        .sidebar-collapsed .sidebar-link i {
            margin-right: 0;
        }
        
        /* Prevent white screen - ensure content is always visible */
        body {
            min-height: 100vh;
            background-color: #f9fafb !important;
        }
        #app {
            min-height: 100vh;
            display: flex !important;
        }
        
        /* Mobile sidebar overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
        }
        .sidebar-overlay.show {
            display: block;
        }
        
        /* Responsive styles */
        @media (max-width: 1024px) {
            aside {
                position: fixed;
                left: -16rem;
                z-index: 50;
                transition: left 0.3s ease;
                height: 100vh;
                top: 0;
                display: flex !important;
            }
            aside.show {
                left: 0;
            }
            aside .sidebar {
                height: 100vh;
                width: 16rem;
            }
            main {
                margin-left: 0 !important;
            }
            .grid.lg\\:grid-cols-2 {
                grid-template-columns: 1fr;
            }
        }
        
        @media (min-width: 1025px) {
            aside {
                position: relative !important;
                left: 0 !important;
            }
            .sidebar-overlay {
                display: none !important;
            }
        }
        
        /* Fix chart container to prevent jumping */
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Ensure chart containers don't resize */
        div[style*="position: relative"] {
            min-height: 250px;
        }
        
        @media (max-width: 768px) {
            .grid.md\\:grid-cols-2 {
                grid-template-columns: 1fr;
            }
            .grid.lg\\:grid-cols-4 {
                grid-template-columns: 1fr;
            }
            header h1 {
                font-size: 1.25rem;
            }
            .bg-white.rounded-xl {
                padding: 1rem;
            }
            table {
                font-size: 0.875rem;
            }
            table th, table td {
                padding: 0.5rem;
            }
        }
        
        @media (max-width: 640px) {
            main {
                padding: 1rem !important;
            }
            .gap-6 {
                gap: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Prevent white screen - keep content visible -->
    <noscript>
        <style>
            body { display: block !important; }
            #app { display: flex !important; }
        </style>
    </noscript>
    <div id="app" class="flex h-screen overflow-hidden">
        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        
        <!-- Sidebar -->
        <aside class="hidden md:flex md:flex-shrink-0 md:relative fixed md:left-0 left-[-16rem]">
            <div id="sidebar" class="sidebar flex flex-col w-64 bg-white border-r border-gray-200 transition-all duration-300 overflow-hidden">
                <div class="flex items-center justify-center h-16 px-4 bg-gradient-to-r from-purple-600 to-purple-800">
                    <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                        <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg mr-2" alt="Logo" />
                    <?php else: ?>
                        <i class="fas fa-heartbeat text-white text-2xl mr-2"></i>
                    <?php endif; ?>
                    <span class="brand-text text-2xl font-bold text-white"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
                </div>
                <div class="flex flex-col flex-1 overflow-y-auto">
                    <nav class="flex-1 px-2 py-4 space-y-1">
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/dashboardnew.php')); ?>" class="sidebar-link <?php echo ($current_page == 'dashboardnew.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-home mr-3"></i>
                            <span class="link-text">Dashboard</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>" class="sidebar-link <?php echo ($current_page == 'medicines.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-pills mr-3"></i>
                            <span class="link-text">Medicines</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/categories.php')); ?>" class="sidebar-link <?php echo ($current_page == 'categories.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-tags mr-3"></i>
                            <span class="link-text">Categories</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>" class="sidebar-link <?php echo ($current_page == 'batches.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-layer-group mr-3"></i>
                            <span class="link-text">Batches</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>" class="sidebar-link <?php echo ($current_page == 'inventory.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-boxes mr-3"></i>
                            <span class="link-text">Inventory</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="sidebar-link <?php echo ($current_page == 'users.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-users mr-3"></i>
                            <span class="link-text">Users</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="sidebar-link <?php echo ($current_page == 'allocations.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-user-friends mr-3"></i>
                            <span class="link-text">Allocations</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/announcements.php')); ?>" class="sidebar-link <?php echo ($current_page == 'announcements.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-bullhorn mr-3"></i>
                            <span class="link-text">Announcements</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/analytics.php')); ?>" class="sidebar-link <?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-chart-bar mr-3"></i>
                            <span class="link-text">Analytics</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/reports.php')); ?>" class="sidebar-link <?php echo ($current_page == 'reports.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-file-alt mr-3"></i>
                            <span class="link-text">Reports</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>" class="sidebar-link <?php echo ($current_page == 'settings_brand.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-cog mr-3"></i>
                            <span class="link-text">Brand Settings</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/locations.php')); ?>" class="sidebar-link <?php echo ($current_page == 'locations.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-map-marker-alt mr-3"></i>
                            <span class="link-text">Barangays & Puroks</span>
                        </a>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/email_logs.php')); ?>" class="sidebar-link <?php echo ($current_page == 'email_logs.php') ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                            <i class="fas fa-envelope mr-3"></i>
                            <span class="link-text">Email Logs</span>
                        </a>
                    </nav>
                </div>
                <div class="p-4 border-t border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                     alt="Profile" 
                                     class="w-10 h-10 rounded-full object-cover"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>">
                                <i class="fas fa-user text-purple-600"></i>
                            </div>
                        </div>
                        <div class="ml-3 user-info">
                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user_data['email'] ?? 'admin@meditrack.com'); ?></p>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="mt-3 w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>
                        <span class="link-text">Logout</span>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex flex-col flex-1 overflow-hidden">
            <!-- Top Header -->
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="flex items-center justify-between px-4 py-4 sm:px-6">
                    <div class="flex items-center">
                        <button id="mobileMenuToggle" class="md:hidden text-gray-500 hover:text-gray-700 mr-3">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <button id="sidebarToggle" class="hidden md:inline-flex text-gray-500 hover:text-gray-700 mr-4">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <!-- Mobile Logo -->
                        <div class="md:hidden flex items-center mr-3">
                            <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                                <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg" alt="Logo" />
                            <?php else: ?>
                                <i class="fas fa-heartbeat text-purple-600 text-2xl"></i>
                            <?php endif; ?>
                        </div>
                        <!-- Mobile Title -->
                        <div class="md:hidden">
                            <h1 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button class="relative text-gray-500 hover:text-gray-700">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($pending_requests > 0): ?>
                                <span class="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500"></span>
                            <?php endif; ?>
                        </button>
                        <div class="flex items-center space-x-3">
                            <div class="text-right hidden sm:block">
                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                                <p class="text-xs text-gray-500">Super Administrator</p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                                <?php if (!empty($user_data['profile_image'])): ?>
                                    <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                         alt="Profile" 
                                         class="w-10 h-10 rounded-full object-cover"
                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>
                                <i class="fas fa-user text-purple-600 <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main id="main-content-area" class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <!-- Greeting Section -->
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Welcome back, Super Admin</h1>
                    <p class="text-gray-600">Here's an overview of your medicine inventory today.</p>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Stock Units</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-stock"><?php echo number_format($total_stock_units); ?></p>
                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fas fa-arrow-up"></i> Available units
                                </p>
                            </div>
                            <div class="bg-purple-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-boxes text-purple-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-yellow-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Low Stock Medicines</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-low"><?php echo number_format($low_stock_medicines); ?></p>
                                <p class="text-xs text-yellow-600 mt-2">
                                    <i class="fas fa-exclamation-triangle"></i> Needs restocking
                                </p>
                            </div>
                            <div class="bg-yellow-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Today's Dispensed</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-dispensed"><?php echo number_format($today_dispensed); ?></p>
                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fas fa-arrow-up"></i> Units dispensed today
                                </p>
                            </div>
                            <div class="bg-green-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-pills text-green-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Requests</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-requests"><?php echo number_format($total_requests); ?></p>
                                <p class="text-xs text-blue-600 mt-2">
                                    <i class="fas fa-clipboard-list"></i> <?php echo $pending_requests; ?> pending
                                </p>
                            </div>
                            <div class="bg-blue-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-clipboard-list text-blue-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Combination Chart: Requests vs Dispensed -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Requests vs Dispensed</h3>
                            <p class="text-sm text-gray-600">Last 30 days trend</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="requestDispensedChart"></canvas>
                        </div>
                    </div>

                    <!-- Request Status Distribution -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Request Status</h3>
                            <p class="text-sm text-gray-600">Distribution by status</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <!-- Top Dispensed Medicines (Bar Chart) -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Top Dispensed Medicines</h3>
                            <p class="text-sm text-gray-600">Last 30 days</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="topMedicinesChart"></canvas>
                        </div>
                    </div>

                    <!-- Stock Distribution (Histogram) -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Stock Levels Distribution</h3>
                            <p class="text-sm text-gray-600">Current inventory levels</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="stockDistributionChart"></canvas>
                        </div>
                    </div>

                    <!-- Monthly Trends -->
                    <div class="bg-white rounded-xl shadow-md p-6 lg:col-span-2">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Monthly Trends</h3>
                            <p class="text-sm text-gray-600">Last 6 months overview</p>
                        </div>
                        <div style="position: relative; height: 250px;">
                            <canvas id="monthlyTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Recent Activity</h3>
                            <p class="text-sm text-gray-600">Latest system updates</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <?php if (!empty($recent_medicines)): ?>
                            <?php foreach ($recent_medicines as $medicine): ?>
                                <div class="flex items-center space-x-4 p-4 bg-purple-50 rounded-lg">
                                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-pills text-green-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">New medicine added</p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($medicine['name']); ?> added to inventory</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($medicine['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($recent_users)): ?>
                            <?php foreach ($recent_users as $user_item): ?>
                                <div class="flex items-center space-x-4 p-4 bg-blue-50 rounded-lg">
                                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-user-plus text-blue-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">New BHW registered</p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($user_item['name']); ?> joined the system</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($user_item['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($recent_requests)): ?>
                            <?php foreach ($recent_requests as $request): ?>
                                <div class="flex items-center space-x-4 p-4 bg-green-50 rounded-lg">
                                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-clipboard-list text-purple-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">Medicine request <?php echo htmlspecialchars($request['status']); ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($request['resident_name']); ?>'s request</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($request['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (empty($recent_medicines) && empty($recent_users) && empty($recent_requests)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-500">No recent activity</p>
                                <p class="text-sm text-gray-400">Activity will appear here as users interact with the system</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Users Table -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Recent BHW Users</h2>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="text-sm text-purple-600 hover:text-purple-700">View all</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Join Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($recent_users)): ?>
                                    <?php foreach ($recent_users as $user_item): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <div class="h-10 w-10 rounded-full bg-purple-100 flex items-center justify-center">
                                                        <i class="fas fa-user text-purple-600"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user_item['name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars(ucfirst($user_item['role'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($user_item['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="text-purple-600 hover:text-purple-900 mr-3"><i class="fas fa-edit"></i></a>
                                            <a href="#" class="text-red-600 hover:text-red-900"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            No recent users found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Global error handler to prevent white screen
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error, e.filename, e.lineno);
            e.preventDefault();
            return true;
        });

        // Prevent unhandled promise rejections from breaking the page
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
            e.preventDefault();
        });

        // Initialize charts
        function initCharts() {
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js is not loaded');
                return;
            }

            try {
                // 1. Combination Chart: Requests vs Dispensed (Line + Bar)
                const requestDispensedCtx = document.getElementById('requestDispensedChart');
                if (requestDispensedCtx) {
                    const ctx = requestDispensedCtx.getContext('2d');
                    const requestDispensedData = <?php echo json_encode($request_dispensed_trends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const labels = [];
                    const requestCounts = [];
                    const dispensedCounts = [];
                    
                    for (let i = 29; i >= 0; i--) {
                        const date = new Date();
                        date.setDate(date.getDate() - i);
                        const dateStr = date.toISOString().split('T')[0];
                        labels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                        
                        const dayData = requestDispensedData.find(item => item && item.date === dateStr);
                        requestCounts.push(dayData ? parseInt(dayData.request_count) || 0 : 0);
                        dispensedCounts.push(dayData ? parseInt(dayData.dispensed_units) || 0 : 0);
                    }
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Requests',
                                data: requestCounts,
                                type: 'line',
                                borderColor: 'rgb(139, 92, 246)',
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                tension: 0.4,
                                fill: false,
                                yAxisID: 'y'
                            }, {
                                label: 'Dispensed Units',
                                data: dispensedCounts,
                                backgroundColor: 'rgba(16, 185, 129, 0.6)',
                                borderColor: 'rgb(16, 185, 129)',
                                borderWidth: 1,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Requests'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Dispensed Units'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 2. Request Status Distribution (Doughnut Chart)
                const statusCtx = document.getElementById('statusChart');
                if (statusCtx) {
                    const ctx = statusCtx.getContext('2d');
                    const statusData = <?php echo json_encode($request_status_dist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const statusLabels = statusData.map(item => {
                        return item.status.charAt(0).toUpperCase() + item.status.slice(1).replace('_', ' ');
                    });
                    const statusCounts = statusData.map(item => parseInt(item.count) || 0);
                    
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: statusLabels,
                            datasets: [{
                                data: statusCounts,
                                backgroundColor: [
                                    'rgb(139, 92, 246)',
                                    'rgb(16, 185, 129)',
                                    'rgb(245, 158, 11)',
                                    'rgb(239, 68, 68)',
                                    'rgb(139, 69, 19)',
                                    'rgb(156, 163, 175)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }

                // 3. Top Dispensed Medicines (Horizontal Bar Chart)
                const topMedicinesCtx = document.getElementById('topMedicinesChart');
                if (topMedicinesCtx) {
                    const ctx = topMedicinesCtx.getContext('2d');
                    const topMedicinesData = <?php echo json_encode($top_dispensed_medicines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const medicineLabels = topMedicinesData.map(item => item.name.length > 20 ? item.name.substring(0, 20) + '...' : item.name);
                    const medicineUnits = topMedicinesData.map(item => parseInt(item.dispensed_units) || 0);
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: medicineLabels,
                            datasets: [{
                                label: 'Dispensed Units',
                                data: medicineUnits,
                                backgroundColor: 'rgba(139, 92, 246, 0.8)',
                                borderColor: 'rgb(139, 92, 246)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                y: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 4. Stock Distribution (Histogram/Bar Chart)
                const stockDistCtx = document.getElementById('stockDistributionChart');
                if (stockDistCtx) {
                    const ctx = stockDistCtx.getContext('2d');
                    const stockDistData = <?php echo json_encode($stock_distribution, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const stockRanges = ['Out of Stock', '1-10 units', '11-50 units', '51-100 units', '100+ units'];
                    const stockCounts = stockRanges.map(range => {
                        const found = stockDistData.find(item => item && item.stock_range === range);
                        return found ? parseInt(found.medicine_count) || 0 : 0;
                    });
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: stockRanges,
                            datasets: [{
                                label: 'Number of Medicines',
                                data: stockCounts,
                                backgroundColor: [
                                    'rgba(239, 68, 68, 0.8)',
                                    'rgba(245, 158, 11, 0.8)',
                                    'rgba(139, 92, 246, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(139, 69, 19, 0.8)'
                                ],
                                borderColor: [
                                    'rgb(239, 68, 68)',
                                    'rgb(245, 158, 11)',
                                    'rgb(139, 92, 246)',
                                    'rgb(16, 185, 129)',
                                    'rgb(139, 69, 19)'
                                ],
                                borderWidth: 1
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
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 5. Monthly Trends (Combination Chart)
                const monthlyTrendsCtx = document.getElementById('monthlyTrendsChart');
                if (monthlyTrendsCtx) {
                    const ctx = monthlyTrendsCtx.getContext('2d');
                    const monthlyData = <?php echo json_encode($monthly_trends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const monthLabels = monthlyData.map(item => item.month_label);
                    const monthRequests = monthlyData.map(item => parseInt(item.request_count) || 0);
                    const monthDispensed = monthlyData.map(item => parseInt(item.dispensed_units) || 0);
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: monthLabels,
                            datasets: [{
                                label: 'Requests',
                                data: monthRequests,
                                type: 'line',
                                borderColor: 'rgb(139, 69, 19)',
                                backgroundColor: 'rgba(139, 69, 19, 0.1)',
                                tension: 0.4,
                                fill: false,
                                yAxisID: 'y'
                            }, {
                                label: 'Dispensed Units',
                                data: monthDispensed,
                                backgroundColor: 'rgba(139, 92, 246, 0.6)',
                                borderColor: 'rgb(139, 92, 246)',
                                borderWidth: 1,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Requests'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Dispensed Units'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }

        // Animate stats on load
        function animateStats() {
            const stats = ['stat-stock', 'stat-low', 'stat-dispensed', 'stat-requests'];
            const values = [<?php echo (int)$total_stock_units; ?>, <?php echo (int)$low_stock_medicines; ?>, <?php echo (int)$today_dispensed; ?>, <?php echo (int)$total_requests; ?>];
            
            stats.forEach((statId, index) => {
                const element = document.getElementById(statId);
                if (!element) return;
                
                let current = 0;
                const target = values[index] || 0;
                if (target === 0) {
                    element.textContent = '0';
                    return;
                }
                const increment = Math.max(target / 50, 1);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    element.textContent = Math.floor(current).toLocaleString();
                }, 30);
            });
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            animateStats();
            
            // Initialize sidebar toggle
            const appRoot = document.getElementById('app');
            const desktopToggle = document.getElementById('sidebarToggle');
            const mobileToggle = document.getElementById('mobileMenuToggle');
            
            if (desktopToggle && appRoot) {
                desktopToggle.addEventListener('click', function () {
                    appRoot.classList.toggle('sidebar-collapsed');
                });
            }
            
            if (mobileToggle) {
                const sidebar = document.querySelector('aside');
                const overlay = document.getElementById('sidebarOverlay');
                
                function openMobileSidebar() {
                    if (sidebar) {
                        sidebar.classList.add('show');
                        sidebar.style.display = 'flex';
                    }
                    if (overlay) {
                        overlay.classList.add('show');
                    }
                    document.body.style.overflow = 'hidden';
                }
                
                function closeMobileSidebar() {
                    if (sidebar) {
                        sidebar.classList.remove('show');
                    }
                    if (overlay) {
                        overlay.classList.remove('show');
                    }
                    document.body.style.overflow = '';
                }
                
                mobileToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (sidebar && sidebar.classList.contains('show')) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                });
                
                // Close sidebar when clicking overlay
                if (overlay) {
                    overlay.addEventListener('click', function() {
                        closeMobileSidebar();
                    });
                }
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 1024) {
                        if (sidebar && sidebar.classList.contains('show')) {
                            if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                                closeMobileSidebar();
                            }
                        }
                    }
                });
                
                // Close sidebar when clicking on a link inside sidebar on mobile
                const sidebarLinks = document.querySelectorAll('aside .sidebar-link');
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', function(e) {
                        // Handle AJAX navigation
                        const href = this.getAttribute('href');
                        if (href && !href.includes('#') && !this.hasAttribute('data-no-ajax')) {
                            // Skip dashboard link - it's the current page
                            if (href.includes('dashboardnew.php')) {
                                if (window.innerWidth <= 1024) {
                                    closeMobileSidebar();
                                }
                                return;
                            }
                            
                            e.preventDefault();
                            
                            // Close mobile sidebar
                            if (window.innerWidth <= 1024) {
                                closeMobileSidebar();
                            }
                            
                            // Update active state
                            sidebarLinks.forEach(l => l.classList.remove('active'));
                            this.classList.add('active');
                            
                            // Load content via AJAX
                            loadPageContent(href);
                        } else {
                            // Normal navigation for links with data-no-ajax or anchors
                            if (window.innerWidth <= 1024) {
                                closeMobileSidebar();
                            }
                        }
                    });
                });
                
                // AJAX content loading function
                function loadPageContent(url) {
                    const mainContent = document.getElementById('main-content-area');
                    if (!mainContent) return;
                    
                    // Show loading state
                    mainContent.innerHTML = '<div class="flex items-center justify-center h-64"><div class="text-center"><div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600 mb-4"></div><p class="text-gray-600">Loading...</p></div></div>';
                    
                    // Add ajax parameter to URL
                    const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1';
                    
                    // Fetch content
                    fetch(ajaxUrl, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.text();
                    })
                    .then(html => {
                        // Replace content
                        mainContent.innerHTML = html;
                        
                        // Execute any scripts in the loaded content
                        const scripts = mainContent.querySelectorAll('script');
                        scripts.forEach(oldScript => {
                            const newScript = document.createElement('script');
                            if (oldScript.src) {
                                newScript.src = oldScript.src;
                            } else {
                                newScript.textContent = oldScript.textContent;
                            }
                            oldScript.parentNode.replaceChild(newScript, oldScript);
                        });
                        
                        // Update URL without reload
                        window.history.pushState({url: url}, '', url);
                    })
                    .catch(error => {
                        console.error('Error loading content:', error);
                        mainContent.innerHTML = '<div class="p-6 bg-red-50 border border-red-200 rounded-lg"><p class="text-red-700">Error loading content. Please <a href="' + url + '" class="underline">refresh the page</a>.</p></div>';
                    });
                }
                
                // Handle browser back/forward buttons
                window.addEventListener('popstate', function(e) {
                    if (e.state && e.state.url) {
                        loadPageContent(e.state.url);
                    } else {
                        // Reload dashboard
                        window.location.href = '<?php echo htmlspecialchars(base_url("super_admin/dashboardnew.php")); ?>';
                    }
                });
                
                // Handle form submissions - allow normal form posts
                document.addEventListener('submit', function(e) {
                    const form = e.target;
                    // If form has data-no-ajax attribute, allow normal submission
                    if (form.hasAttribute('data-no-ajax')) {
                        return;
                    }
                    // For forms in AJAX-loaded content, allow normal submission
                    // (they will redirect after POST)
                });
            }
        });
    </script>
</body>
</html>
