<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
$user = current_user();

// Get fresh user data for profile section
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch();

// Fetch real dashboard data with error handling
try {
    $total_medicines = db()->query('SELECT COUNT(*) as count FROM medicines')->fetch()['count'];
} catch (Exception $e) {
    $total_medicines = 0;
}

try {
    $expiring_batches = db()->query('SELECT COUNT(*) as count FROM medicine_batches WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date > CURDATE()')->fetch()['count'];
} catch (Exception $e) {
    $expiring_batches = 0;
}

try {
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "pending"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
}

try {
    $total_allocations = db()->query('SELECT COUNT(*) as count FROM allocations')->fetch()['count'];
} catch (Exception $e) {
    $total_allocations = 0;
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

// Fetch chart data with error handling
try {
    $request_trends = db()->query('SELECT DATE(created_at) as date, COUNT(*) as count FROM requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date')->fetchAll();
} catch (Exception $e) {
    $request_trends = [];
}

try {
    $medicine_distribution = db()->query('SELECT m.name, COUNT(r.id) as request_count FROM medicines m LEFT JOIN requests r ON m.id = r.medicine_id GROUP BY m.id, m.name ORDER BY request_count DESC LIMIT 5')->fetchAll();
} catch (Exception $e) {
    $medicine_distribution = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Super Admin Dashboard · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        /* Dark mode styles */
        .dark {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%) !important;
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
        .dark .card-header h3 {
            color: #f9fafb !important;
        }
        .dark .card-header p {
            color: #d1d5db !important;
        }
        .dark .sidebar {
            background: #1f2937 !important;
        }
        .dark .sidebar-brand span {
            color: #f9fafb !important;
        }
        .dark .sidebar-nav a {
            color: #d1d5db !important;
        }
        .dark .sidebar-nav a:hover {
            background: #374151 !important;
        }
        .dark .sidebar-nav a.active {
            background: #3b82f6 !important;
            color: #ffffff !important;
        }
        
        /* Profile dropdown styles */
        #profile-menu {
            animation: fadeInDown 0.2s ease-out;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dark #profile-menu {
            background: #374151 !important;
            border-color: #4b5563 !important;
        }
        
        .dark #profile-menu .text-gray-900 {
            color: #f9fafb !important;
        }
        
        .dark #profile-menu .text-gray-500 {
            color: #d1d5db !important;
        }
        
        .dark #profile-menu .text-gray-700 {
            color: #d1d5db !important;
        }
        
        .dark #profile-menu .hover\:bg-gray-100:hover {
            background: #4b5563 !important;
        }
        
        .dark #profile-menu .border-gray-100 {
            border-color: #4b5563 !important;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                </svg>
                Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/categories.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                </svg>
                Categories
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Batches
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
                Inventory
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Users
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                All Residents
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/analytics.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Analytics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Brand Settings
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/locations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Barangays & Puroks
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/email_logs.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Email Logs
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <!-- Logout removed - now accessible via profile dropdown -->
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Welcome back, <?php echo htmlspecialchars($user['name']); ?></h1>
                    <p class="text-gray-600 mt-1">Here's what's happening with your medicine inventory today.</p>
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
                            <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                <?php 
                                $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
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
                                <a href="#" onclick="showProfileSection(); return false;" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
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

        <!-- Dashboard Content -->
        <div class="content-body">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card animate-fade-in-up">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Medicines</p>
                                <p class="text-3xl font-bold text-gray-900" id="stat-meds">0</p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-sm text-green-600 font-medium">+12%</span>
                            <span class="text-sm text-gray-500 ml-2">from last month</span>
                        </div>
                    </div>
                </div>

                <div class="card animate-fade-in-up" style="animation-delay: 0.1s">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Expiring Soon</p>
                                <p class="text-3xl font-bold text-gray-900" id="stat-expiring">0</p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-sm text-orange-600 font-medium">Needs attention</span>
                            <span class="text-sm text-gray-500 ml-2">within 30 days</span>
                        </div>
                    </div>
                </div>

                <div class="card animate-fade-in-up" style="animation-delay: 0.2s">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Pending Requests</p>
                                <p class="text-3xl font-bold text-gray-900" id="stat-pending">0</p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-sm text-purple-600 font-medium">Awaiting review</span>
                            <span class="text-sm text-gray-500 ml-2">by BHWs</span>
                        </div>
                    </div>
                </div>

                <div class="card animate-fade-in-up" style="animation-delay: 0.3s">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Senior Allocations</p>
                                <p class="text-3xl font-bold text-gray-900" id="stat-alloc">0</p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-sm text-green-600 font-medium">Active programs</span>
                            <span class="text-sm text-gray-500 ml-2">this month</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="text-lg font-semibold text-gray-900">Request Trends</h3>
                        <p class="text-sm text-gray-600">Last 7 days</p>
                    </div>
                    <div class="card-body">
                        <canvas id="requestChart" height="200"></canvas>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="text-lg font-semibold text-gray-900">Medicine Distribution</h3>
                        <p class="text-sm text-gray-600">Top 5 medicines</p>
                    </div>
                    <div class="card-body">
                        <canvas id="medicineChart" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h3 class="text-lg font-semibold text-gray-900">Recent Activity</h3>
                    <p class="text-sm text-gray-600">Latest system updates</p>
                </div>
                <div class="card-body">
                    <div class="space-y-4">
                        <?php if (!empty($recent_medicines)): ?>
                            <?php foreach ($recent_medicines as $medicine): ?>
                                <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg animate-fade-in">
                                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                        </svg>
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
                            <?php foreach ($recent_users as $user): ?>
                                <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg animate-fade-in">
                                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">New BHW registered</p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($user['name']); ?> joined the system</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($user['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($recent_requests)): ?>
                            <?php foreach ($recent_requests as $request): ?>
                                <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg animate-fade-in">
                                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center">
                                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">Medicine request <?php echo $request['status']; ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($request['resident_name']); ?>'s request</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($request['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (empty($recent_medicines) && empty($recent_users) && empty($recent_requests)): ?>
                            <div class="text-center py-8">
                                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                <p class="text-gray-500">No recent activity</p>
                                <p class="text-sm text-gray-400">Activity will appear here as users interact with the system</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Section (Hidden by default) -->
        <div id="profile-section" class="content-body hidden">
            <!-- Main Profile Card -->
            <div class="card mb-6">
                <div class="card-body p-6">
                    <div class="flex items-center space-x-4">
                        <!-- Profile Avatar -->
                        <div class="relative">
                            <div id="profile-avatar" class="w-16 h-16 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-lg shadow-lg overflow-hidden">
                                <img id="profile-image-preview" src="" alt="Profile" class="w-full h-full object-cover hidden">
                                <span id="profile-initials">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </span>
                            </div>
                            <!-- Camera Icon -->
                            <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-blue-500 rounded-full flex items-center justify-center cursor-pointer shadow-lg" onclick="document.getElementById('profile-image-input').click()">
                                <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- User Info -->
                        <div class="flex-1">
                            <h2 class="text-lg font-bold text-gray-900 mb-1">
                                <?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?>
                            </h2>
                            <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($user_data['email'] ?? 'admin@example.com'); ?></p>
                            
                            <!-- Role Badge and Member Info -->
                            <div class="flex items-center space-x-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-purple-50 to-indigo-50 text-purple-700 border border-purple-200 shadow-sm">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Super Administrator
                                </span>
                                <span class="text-xs text-gray-500">Member since Sep 2025</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Cards -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Personal Information Card -->
                <div class="card">
                    <div class="card-body p-6">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="p-2 bg-blue-100 rounded-lg">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900">Personal Information</h3>
                        </div>
                        
                        <form method="post" action="<?php echo base_url('super_admin/profile.php'); ?>" class="space-y-4">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <!-- First Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">First Name</label>
                                <input type="text" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? 'Super'); ?>" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" 
                                       placeholder="Enter first name" required>
                            </div>
                            
                            <!-- Last Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Last Name</label>
                                <input type="text" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? 'Admin'); ?>" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" 
                                       placeholder="Enter last name" required>
                            </div>
                            
                            <!-- Email Address -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? 'admin@example.com'); ?>" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm" 
                                       placeholder="Enter email address" required>
                            </div>

                            <!-- Update Button -->
                            <div class="pt-4">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security Settings Card -->
                <div class="card">
                    <div class="card-body p-6">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="p-2 bg-green-100 rounded-lg">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900">Security Settings</h3>
                        </div>

                        <form method="post" action="<?php echo base_url('super_admin/profile.php'); ?>" class="space-y-4">
                            <input type="hidden" name="action" value="change_password">
                            
                            <!-- Current Password -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Current Password</label>
                                <input type="password" name="current_password" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-green-500 focus:border-green-500 sm:text-sm" 
                                       placeholder="Enter current password" required>
                            </div>
                            
                            <!-- New Password -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">New Password</label>
                                <input type="password" name="new_password" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-green-500 focus:border-green-500 sm:text-sm" 
                                       placeholder="Enter new password" required>
                                <p class="text-xs text-gray-500">Minimum 6 characters</p>
                            </div>
                            
                            <!-- Confirm New Password -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                <input type="password" name="confirm_password" 
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-green-500 focus:border-green-500 sm:text-sm" 
                                       placeholder="Confirm new password" required>
                            </div>
                            
                            <!-- Change Password Button -->
                            <div class="pt-4">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Hidden file input for profile image -->
            <form method="post" action="<?php echo base_url('super_admin/profile.php'); ?>" enctype="multipart/form-data" class="hidden">
                <input type="hidden" name="action" value="upload_avatar">
                <input type="file" id="profile-image-input" name="profile_image" accept="image/*" onchange="previewProfileImage(this)">
            </form>
        </div>
    </main>

    <script>
        // Initialize charts
        function initCharts() {
            // Request Trends Chart
            const requestCtx = document.getElementById('requestChart').getContext('2d');
            
            // Prepare chart data
            const last7Days = [];
            const requestData = [];
            for (let i = 6; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                const dateStr = date.toISOString().split('T')[0];
                last7Days.push(date.toLocaleDateString('en-US', { weekday: 'short' }));
                
                // Find data for this date
                const dayData = <?php echo json_encode($request_trends); ?>.find(item => item.date === dateStr);
                requestData.push(dayData ? parseInt(dayData.count) : 0);
            }
            
            new Chart(requestCtx, {
                type: 'line',
                data: {
                    labels: last7Days,
                    datasets: [{
                        label: 'Requests',
                        data: requestData,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true
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

            // Medicine Distribution Chart
            const medicineCtx = document.getElementById('medicineChart').getContext('2d');
            
            // Prepare medicine distribution data
            const medicineData = <?php echo json_encode($medicine_distribution); ?>;
            const medicineLabels = medicineData.map(item => item.name);
            const medicineCounts = medicineData.map(item => parseInt(item.request_count));
            
            // If no data, show placeholder
            if (medicineLabels.length === 0) {
                medicineLabels.push('No requests yet');
                medicineCounts.push(1);
            }
            
            new Chart(medicineCtx, {
                type: 'doughnut',
                data: {
                    labels: medicineLabels,
                    datasets: [{
                        data: medicineCounts,
                        backgroundColor: [
                            'rgb(59, 130, 246)',
                            'rgb(16, 185, 129)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)',
                            'rgb(156, 163, 175)',
                            'rgb(139, 69, 19)',
                            'rgb(75, 0, 130)',
                            'rgb(255, 20, 147)'
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

        // Refresh data function
        function refreshData() {
            // Update the current time display
            updateClock();
            // Add loading animation
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => card.classList.add('loading'));
            
            setTimeout(() => {
                cards.forEach(card => card.classList.remove('loading'));
            }, 1000);
        }

        // Show profile section function
        function showProfileSection() {
            // Hide dashboard content
            const dashboardContent = document.querySelector('.content-body:not(#profile-section)');
            if (dashboardContent) {
                dashboardContent.classList.add('hidden');
            }
            
            // Show profile section
            const profileSection = document.getElementById('profile-section');
            if (profileSection) {
                profileSection.classList.remove('hidden');
            }
            
            // Update header title
            const headerTitle = document.querySelector('.content-header h1');
            if (headerTitle) {
                headerTitle.textContent = 'Edit Profile';
            }
            
            const headerSubtitle = document.querySelector('.content-header p');
            if (headerSubtitle) {
                headerSubtitle.textContent = 'Manage your personal information and security settings';
            }
            
            // Close the dropdown menu
            const profileMenu = document.getElementById('profile-menu');
            const profileArrow = document.getElementById('profile-arrow');
            if (profileMenu) {
                profileMenu.classList.add('hidden');
            }
            if (profileArrow) {
                profileArrow.classList.remove('rotate-180');
            }
        }
        
        // Show dashboard function
        function showDashboard() {
            // Hide profile section
            const profileSection = document.getElementById('profile-section');
            if (profileSection) {
                profileSection.classList.add('hidden');
            }
            
            // Show dashboard content
            const dashboardContent = document.querySelector('.content-body:not(#profile-section)');
            if (dashboardContent) {
                dashboardContent.classList.remove('hidden');
            }
            
            // Update header title
            const headerTitle = document.querySelector('.content-header h1');
            if (headerTitle) {
                headerTitle.textContent = 'Welcome back, <?php echo htmlspecialchars($user['name']); ?>';
            }
            
            const headerSubtitle = document.querySelector('.content-header p');
            if (headerSubtitle) {
                headerSubtitle.textContent = 'Here\'s what\'s happening with your medicine inventory today.';
            }
        }

        // Preview profile image function
        function previewProfileImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const previewImg = document.getElementById('profile-image-preview');
                    const initialsSpan = document.getElementById('profile-initials');
                    const avatarDiv = document.getElementById('profile-avatar');
                    
                    // Show the image preview
                    previewImg.src = e.target.result;
                    previewImg.classList.remove('hidden');
                    initialsSpan.classList.add('hidden');
                    
                    // Remove gradient background when showing image
                    avatarDiv.classList.remove('bg-gradient-to-br', 'from-purple-500', 'to-blue-600');
                    avatarDiv.classList.add('bg-gray-200');
                    
                    // Auto-submit the form after preview
                    setTimeout(() => {
                        input.form.submit();
                    }, 1000);
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            
            // Animate stats on load with real data
            const stats = ['stat-meds', 'stat-expiring', 'stat-pending', 'stat-alloc'];
            const values = [<?php echo $total_medicines; ?>, <?php echo $expiring_batches; ?>, <?php echo $pending_requests; ?>, <?php echo $total_allocations; ?>];
            
            stats.forEach((statId, index) => {
                const element = document.getElementById(statId);
                let current = 0;
                const target = values[index];
                const increment = target / 50;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    element.textContent = Math.floor(current);
                }, 30);
            });
            
            // Initialize real-time clock
            updateClock();
            setInterval(updateClock, 1000);
            
            // Initialize night mode toggle
            initNightMode();
            
            // Initialize profile dropdown with a small delay to ensure DOM is ready
            setTimeout(() => {
                initProfileDropdown();
            }, 100);
        });
        
        // Real-time clock function
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('current-time').textContent = timeString;
        }
        
        // Night mode functionality
        function initNightMode() {
            const toggle = document.getElementById('night-mode-toggle');
            const body = document.body;
            
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
    </script>
</body>
</html>


