<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';

$user = current_user();

// Fetch fresh user data
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Helper function to get upload URL
if (!function_exists('upload_url')) {
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
}

// Notifications Logic
try {
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "submitted"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
}

try {
    $recent_pending_requests = db()->query('
        SELECT r.id, r.status, r.created_at,
               CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) as requester_name,
               DATE_FORMAT(r.created_at, "%b %d, %Y") as formatted_date
        FROM requests r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status = "submitted"
        ORDER BY r.created_at DESC
        LIMIT 5
    ')->fetchAll();
} catch (Exception $e) {
    $recent_pending_requests = [];
}

try {
    $inventory_alerts = db()->query('
        SELECT ia.id, ia.severity, ia.message, ia.created_at,
               m.name as medicine_name,
               DATE_FORMAT(ia.created_at, "%b %d, %Y") as formatted_date
        FROM inventory_alerts ia
        JOIN medicines m ON ia.medicine_id = m.id
        WHERE ia.is_acknowledged = FALSE
        ORDER BY 
            CASE ia.severity
                WHEN "critical" THEN 1
                WHEN "high" THEN 2
                WHEN "medium" THEN 3
                ELSE 4
            END,
            ia.created_at DESC
        LIMIT 5
    ')->fetchAll();
    $alerts_count = count($inventory_alerts);
} catch (Exception $e) {
    $inventory_alerts = [];
    $alerts_count = 0;
}

$total_notifications = $pending_requests + $alerts_count;
$report_type = $_GET['report_type'] ?? 'scir';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$export = $_GET['export'] ?? '';

$report_data = [];
$report_title = '';

try {
    if ($report_type === 'scir') {
        $report_title = 'Stock Consumption & Inventory Report (SCIR)';
        $sql = "SELECT m.id, m.name as medicine_name, m.generic_name, m.dosage, m.form, m.unit,
                COALESCE(SUM(mb.quantity), 0) as total_received_ever,
                COALESCE(SUM(mb.quantity_available), 0) as current_stock
                FROM medicines m LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
                WHERE m.is_active = 1 GROUP BY m.id
                HAVING current_stock > 0 OR total_received_ever > 0 ORDER BY m.name ASC";
        
        $medicines = db()->query($sql)->fetchAll() ?: [];
        
        foreach ($medicines as $med) {
            $received = db()->prepare("SELECT COALESCE(SUM(quantity), 0) as qty FROM medicine_batches WHERE medicine_id = ? AND DATE(received_at) BETWEEN ? AND ?");
            $received->execute([$med['id'], $date_from, $date_to]);
            $qty_received = (int)$received->fetch()['qty'];
            
            $consumed = db()->prepare("SELECT COALESCE(SUM(rf.quantity), 0) as qty FROM request_fulfillments rf INNER JOIN requests r ON rf.request_id = r.id WHERE r.medicine_id = ? AND r.status IN ('claimed','approved') AND DATE(COALESCE(rf.created_at, r.updated_at)) BETWEEN ? AND ?");
            $consumed->execute([$med['id'], $date_from, $date_to]);
            $qty_consumed = (int)$consumed->fetch()['qty'];
            
            $ending = (int)$med['current_stock'];
            $beginning = max(0, $ending - $qty_received + $qty_consumed);
            $total = $beginning + $qty_received;
            
            $report_data[] = [
                'Medicine' => ($med['generic_name'] ?: $med['medicine_name']) . ($med['dosage'] ? ' ' . $med['dosage'] : ''),
                'Unit' => $med['unit'] ?: 'Tablet',
                'Beginning' => $beginning,
                'Received' => $qty_received,
                'Total Stock' => $total,
                'Consumed' => $qty_consumed,
                'Ending' => $ending
            ];
        }
    } elseif ($report_type === 'remaining_stocks') {
        $report_title = 'Remaining Stocks Report';
        $sql = "SELECT m.name as medicine, mb.batch_code, mb.quantity as initial, 
                mb.quantity_available as current_stock, mb.expiry_date, mb.received_at
                FROM medicine_batches mb INNER JOIN medicines m ON mb.medicine_id = m.id
                WHERE m.is_active = 1 AND mb.quantity_available > 0 ORDER BY mb.expiry_date ASC";
        
        $rows = db()->query($sql)->fetchAll() ?: [];
        foreach ($rows as $row) {
            $days_to_expiry = (strtotime($row['expiry_date']) - time()) / 86400;
            $status = $days_to_expiry < 0 ? 'EXPIRED' : ($days_to_expiry <= 30 ? 'CRITICAL' : ($days_to_expiry <= 90 ? 'WARNING' : 'GOOD'));
            
            $report_data[] = [
                'Medicine' => $row['medicine'],
                'Batch' => $row['batch_code'],
                'Initial Qty' => $row['initial'],
                'Current Stock' => $row['current_stock'],
                'Received Date' => date('M d, Y', strtotime($row['received_at'])),
                'Expiry Date' => date('M d, Y', strtotime($row['expiry_date'])),
                'Days to Expiry' => (int)$days_to_expiry,
                'Status' => $status
            ];
        }
    } elseif ($report_type === 'low_stock') {
        $report_title = 'Low Stock Alert Report';
        $sql = "SELECT m.name as medicine, m.minimum_stock_level,
                COALESCE(SUM(mb.quantity_available), 0) as current_stock
                FROM medicines m LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
                WHERE m.is_active = 1 GROUP BY m.id
                HAVING current_stock <= m.minimum_stock_level ORDER BY current_stock ASC";
        
        $rows = db()->query($sql)->fetchAll() ?: [];
        foreach ($rows as $row) {
            $level = $row['current_stock'] == 0 ? 'OUT OF STOCK' : ($row['current_stock'] <= $row['minimum_stock_level'] * 0.3 ? 'CRITICAL' : 'LOW');
            $report_data[] = [
                'Medicine' => $row['medicine'],
                'Minimum Level' => $row['minimum_stock_level'],
                'Current Stock' => $row['current_stock'],
                'Shortage' => max(0, $row['minimum_stock_level'] - $row['current_stock']),
                'Alert Level' => $level
            ];
        }
    }
} catch (Throwable $e) {
    error_log("Report Error: " . $e->getMessage());
}

if ($export === 'csv' && !empty($report_data)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', $report_title) . '_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, [$report_title]);
    fputcsv($output, ['Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to))]);
    fputcsv($output, []);
    fputcsv($output, array_keys($report_data[0]));
    foreach ($report_data as $row) fputcsv($output, $row);
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Hub · Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
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
        @media print { 
            /* Hide everything by default */
            body * {
                visibility: hidden;
            }
            /* Only show the report container and its children */
            .report-container, .report-container * {
                visibility: visible;
            }
            /* Position the report container at the top left */
            .report-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none !important;
                border: none !important;
            }
            
            /* Hide specific elements explicitly just in case */
            .no-print, header, aside, .sidebar, #sidebar-aside, .content-header { 
                display: none !important; 
            } 
            
            /* Reset body styles */
            body { 
                background: white !important; 
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            } 
            
            .main-content { 
                margin-left: 0 !important; 
                padding: 0 !important; 
                width: 100% !important; 
                overflow: visible !important;
            }
            
            /* Ensure table borders print correctly */
            table, th, td { border: 1px solid black !important; }
            
            /* Hide browser headers/footers if possible (standard way) */
            @page {
                margin: 0.5cm;
                size: auto;
            }
        }

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
        /* For Firefox */
        #super-admin-sidebar-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
        }
        
        /* Sidebar Active Link Style */
        .sidebar-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(102, 126, 234, 0.4);
        }
        .sidebar-link.active i {
            color: #ffffff;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => 'reports_hub.php',
        'user_data' => $user
    ]); ?>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Unified Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-3 py-3 sm:px-4 sm:py-4 md:px-6 h-16">
                <!-- Left Section: Menu + Logo/Title -->
                <div class="flex items-center flex-1 min-w-0 h-full">
                    <button id="mobileMenuToggle" class="md:hidden text-gray-500 hover:text-gray-700 mr-2 sm:mr-3 flex-shrink-0 flex items-center justify-center w-10 h-10" aria-label="Toggle menu" type="button">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <!-- Mobile Logo + Title -->
                    <div class="md:hidden flex items-center min-w-0 flex-1 h-full">
                        <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                            <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg flex-shrink-0 mr-2" alt="Logo" />
                        <?php else: ?>
                            <i class="fas fa-heartbeat text-purple-600 text-2xl mr-2 flex-shrink-0"></i>
                        <?php endif; ?>
                        <h1 class="text-lg sm:text-xl font-bold text-gray-900 truncate leading-none"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
                    </div>
                    <!-- Desktop Title (hidden on mobile) -->
                    <div class="hidden md:flex items-center h-full">
                        <h1 class="text-xl font-bold text-gray-900 leading-none">Reports Hub</h1>
                    </div>
                </div>
                
                <!-- Right Section: Notifications + Profile (aligned with hamburger and MediTrack) -->
                <div class="flex items-center space-x-2 sm:space-x-3 flex-shrink-0 h-full">
                    <!-- Notifications Dropdown -->
                    <div class="relative">
                        <button id="notificationBtn" class="relative text-gray-500 hover:text-gray-700 flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors" aria-label="Notifications" type="button">
                        <i class="fas fa-bell text-xl"></i>
                            <?php if ($total_notifications > 0): ?>
                                <span class="absolute top-1.5 right-1.5 block h-2 w-2 rounded-full bg-red-500"></span>
                                <span class="absolute -top-1 -right-1 flex items-center justify-center w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full"><?php echo $total_notifications > 9 ? '9+' : $total_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                        
                        <!-- Notifications Dropdown Menu -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-96 overflow-hidden">
                            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900">Notifications</h3>
                                <?php if ($total_notifications > 0): ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-600 text-xs font-semibold rounded-full"><?php echo $total_notifications; ?> new</span>
                                <?php endif; ?>
                        </div>
                            <div class="overflow-y-auto max-h-80">
                                <?php if ($total_notifications === 0): ?>
                                    <div class="p-6 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-3xl mb-2 text-gray-300"></i>
                                        <p>No new notifications</p>
                                    </div>
                                <?php else: ?>
                                    <?php if ($pending_requests > 0): ?>
                                        <div class="p-3 border-b border-gray-100 bg-blue-50">
                                            <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-2">Pending Requests</p>
                                            <?php foreach ($recent_pending_requests as $req): ?>
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="block p-2 hover:bg-blue-100 rounded transition-colors mb-1">
                                                    <div class="flex items-start space-x-2">
                                                        <div class="flex-shrink-0 w-2 h-2 bg-blue-500 rounded-full mt-2"></div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-medium text-gray-900 truncate">New request from <?php echo htmlspecialchars($req['requester_name'] ?: 'User'); ?></p>
                                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($req['formatted_date']); ?></p>
                                                        </div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if ($pending_requests > 5): ?>
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="block p-2 text-sm text-blue-600 hover:bg-blue-100 rounded text-center font-medium">
                                                    View all <?php echo $pending_requests; ?> requests
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($alerts_count > 0): ?>
                                        <div class="p-3 border-b border-gray-100 bg-red-50">
                                            <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-2">Inventory Alerts</p>
                                            <?php foreach ($inventory_alerts as $alert): ?>
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>" class="block p-2 hover:bg-red-100 rounded transition-colors mb-1">
                                                    <div class="flex items-start space-x-2">
                                                        <div class="flex-shrink-0">
                                                            <?php if ($alert['severity'] === 'critical'): ?>
                                                                <i class="fas fa-exclamation-circle text-red-600 mt-1"></i>
                                                            <?php elseif ($alert['severity'] === 'high'): ?>
                                                                <i class="fas fa-exclamation-triangle text-orange-500 mt-1"></i>
                                                            <?php else: ?>
                                                                <i class="fas fa-info-circle text-yellow-500 mt-1"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($alert['medicine_name']); ?></p>
                                                            <p class="text-xs text-gray-600 truncate"><?php echo htmlspecialchars($alert['message']); ?></p>
                                                            <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($alert['formatted_date']); ?></p>
                                                        </div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if ($alerts_count > 5): ?>
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>" class="block p-2 text-sm text-red-600 hover:bg-red-100 rounded text-center font-medium">
                                                    View all <?php echo $alerts_count; ?> alerts
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="p-3 border-t border-gray-200 bg-gray-50">
                                <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="block text-center text-sm text-gray-600 hover:text-gray-900 font-medium">
                                    View All Notifications
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button id="profileBtn" class="flex items-center space-x-2 sm:space-x-3 h-full rounded-lg hover:bg-gray-100 transition-colors px-2" type="button">
                            <div class="text-right hidden sm:flex items-center h-full">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 leading-tight"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                                    <p class="text-xs text-gray-500 leading-tight">Super Administrator</p>
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-white border-2 border-gray-300 flex items-center justify-center flex-shrink-0 cursor-pointer">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                     alt="Profile" 
                                     class="w-10 h-10 rounded-full object-cover"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                                <i class="fas fa-user text-gray-600 text-base <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>"></i>
                        </div>
                        </button>
                        
                        <!-- Profile Dropdown Menu -->
                        <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <div class="p-4 border-b border-gray-200">
                                <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user_data['email'] ?? 'admin@meditrack.com'); ?></p>
                            </div>
                            <div class="py-2">
                                <a href="<?php echo htmlspecialchars(base_url('super_admin/profile.php')); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                    <i class="fas fa-user-circle w-5 mr-3 text-gray-400"></i>
                                    <span>My Profile</span>
                                </a>
                                <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                    <i class="fas fa-cog w-5 mr-3 text-gray-400"></i>
                                    <span>Settings</span>
                                </a>
                                <div class="border-t border-gray-200 my-2"></div>
                                <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <i class="fas fa-sign-out-alt w-5 mr-3"></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- JavaScript for Dropdowns -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Notification Dropdown
                const notificationBtn = document.getElementById('notificationBtn');
                const notificationDropdown = document.getElementById('notificationDropdown');
                
                if (notificationBtn && notificationDropdown) {
                    notificationBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        notificationDropdown.classList.toggle('hidden');
                        if (profileDropdown) profileDropdown.classList.add('hidden');
                    });
                }
                
                // Profile Dropdown
                const profileBtn = document.getElementById('profileBtn');
                const profileDropdown = document.getElementById('profileDropdown');
                
                if (profileBtn && profileDropdown) {
                    profileBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        profileDropdown.classList.toggle('hidden');
                        if (notificationDropdown) notificationDropdown.classList.add('hidden');
                    });
                }
                
                // Close dropdowns when clicking outside
                document.addEventListener('click', function(e) {
                    if (notificationDropdown && !notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                        notificationDropdown.classList.add('hidden');
                    }
                    if (profileDropdown && !profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                });
            });
        </script>

        <div class="content-body px-8 pb-8">
            
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 mb-8 no-print">
                <form method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-900 mb-3">📋 Report Type *</label>
                            <select name="report_type" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 font-medium" onchange="this.form.submit()">
                                <option value="scir" <?php echo $report_type === 'scir' ? 'selected' : ''; ?>>📦 SCIR (Stock & Inventory)</option>
                                <option value="remaining_stocks" <?php echo $report_type === 'remaining_stocks' ? 'selected' : ''; ?>>📊 Remaining Stocks</option>
                                <option value="low_stock" <?php echo $report_type === 'low_stock' ? 'selected' : ''; ?>>⚠️ Low Stock Alerts</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-900 mb-3">📅 Date From</label>
                            <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-900 mb-3">📅 Date To</label>
                            <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between pt-6 border-t">
                        <button type="submit" class="px-8 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl hover:from-blue-700 hover:to-indigo-700 font-bold flex items-center space-x-2 shadow-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            <span>Generate Report</span>
                        </button>
                        
                        <?php if (!empty($report_data)): ?>
                        <div class="flex space-x-3">
                            <button type="button" onclick="window.print()" class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 font-bold flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                <span>Print</span>
                            </button>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 font-bold flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                <span>Export CSV</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <?php
    // Fetch report settings
    $stmt = db()->query("SELECT * FROM report_settings LIMIT 1");
    $report_settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
    
    // Default values if not set
    $center_name = $report_settings['center_name'] ?? 'BASDASCU HEALTH CENTER';
    $municipality = $report_settings['municipality'] ?? 'LOON';
    $province = $report_settings['province'] ?? 'Bohol';
    $rhu_cho = $report_settings['rhu_cho'] ?? 'RHU 1 - LOON';
    
    // Signatories
    $prepared_by = !empty($report_settings['bhw_name']) ? $report_settings['bhw_name'] : trim(($user['first_name'] ?? 'Super') . ' ' . ($user['last_name'] ?? 'Admin'));
    $submitted_to = !empty($report_settings['rural_staff']) ? $report_settings['rural_staff'] : '______________________';
    $approved_by = !empty($report_settings['municipal_staff']) ? $report_settings['municipal_staff'] : '______________________';
    ?>
            
            <?php if (!empty($report_data)): ?>
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 report-container">
                <div class="text-center mb-8">
                    <h4 class="text-lg font-bold text-gray-900 uppercase">REPUBLIC OF THE PHILIPPINES</h4>
                    <h3 class="text-xl font-bold text-gray-900 uppercase"><?php echo htmlspecialchars($center_name); ?></h3>
                    <p class="text-md text-gray-700 uppercase mb-4"><?php echo htmlspecialchars($rhu_cho . ', ' . $province); ?></p>
                    
                    <div class="border-b-2 border-black w-full mb-6"></div>
                    
                    <h2 class="text-2xl font-bold text-gray-900 uppercase mb-4"><?php echo htmlspecialchars($report_title); ?></h2>
                    
                    <div class="grid grid-cols-2 gap-4 max-w-2xl mx-auto text-sm mb-6 text-left">
                        <div><strong>Name of Health Facility:</strong> <?php echo htmlspecialchars($center_name); ?></div>
                        <div><strong>RHU / CHO:</strong> <?php echo htmlspecialchars($rhu_cho); ?></div>
                        <div><strong>City / Municipality:</strong> <?php echo htmlspecialchars($municipality); ?></div>
                        <div><strong>Province:</strong> <?php echo htmlspecialchars($province); ?></div>
                        <div class="col-span-2"><strong>Reporting Period:</strong> <?php echo date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)); ?></div>
                    </div>
                </div>
                
                <div class="mb-4 pb-4 border-b no-print">
                    <p class="text-sm text-gray-500">Total Records: <?php echo count($report_data); ?></p>
                </div>
                
                <div class="overflow-x-auto mb-12">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gradient-to-r from-blue-50 to-indigo-50">
                                <?php foreach (array_keys($report_data[0]) as $header): ?>
                                <th class="border-2 border-gray-300 px-4 py-3 text-left text-sm font-bold text-gray-900 uppercase"><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr class="hover:bg-blue-50 transition">
                                <?php foreach ($row as $value): ?>
                                <td class="border border-gray-200 px-4 py-3 text-sm"><?php echo htmlspecialchars((string)$value); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Signatories Section -->
                <div class="grid grid-cols-3 gap-8 mt-12 pt-8">
                    <div class="text-center">
                        <p class="font-bold text-sm mb-8">Prepared By:</p>
                        <div class="border-b border-black w-3/4 mx-auto mb-2"></div>
                        <p class="font-bold text-sm uppercase"><?php echo htmlspecialchars($prepared_by); ?></p>
                        <p class="text-xs text-gray-600">BHW / Midwife</p>
                        <p class="text-xs text-gray-600 mt-2">Date: <?php echo date('F d, Y'); ?></p>
                    </div>
                    
                    <div class="text-center">
                        <p class="font-bold text-sm mb-8">Submitted To:</p>
                        <div class="border-b border-black w-3/4 mx-auto mb-2"></div>
                        <p class="font-bold text-sm uppercase"><?php echo htmlspecialchars($submitted_to); ?></p>
                        <p class="text-xs text-gray-600">Rural Health Nurse / RHU Staff</p>
                        <p class="text-xs text-gray-600 mt-2">Date: ________________</p>
                    </div>
                    
                    <div class="text-center">
                        <p class="font-bold text-sm mb-8">Noted / Approved By:</p>
                        <div class="border-b border-black w-3/4 mx-auto mb-2"></div>
                        <p class="font-bold text-sm uppercase"><?php echo htmlspecialchars($approved_by); ?></p>
                        <p class="text-xs text-gray-600">Municipal Health Officer</p>
                        <p class="text-xs text-gray-600 mt-2">Date: ________________</p>
                    </div>
                </div>
            </div>
            <?php elseif (isset($_GET['report_type'])): ?>
            <div class="bg-yellow-50 border-2 border-yellow-200 rounded-2xl p-12 text-center">
                <svg class="w-20 h-20 text-yellow-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">No Data Available</h3>
                <p class="text-gray-600">No records found for the selected criteria.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
