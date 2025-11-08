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

// Ensure upload dir
$logoDir = __DIR__ . '/../uploads/brand';
if (!is_dir($logoDir)) { @mkdir($logoDir, 0777, true); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = trim($_POST['brand_name'] ?? 'MediTrack');
    // Save brand name
    $stmt = db()->prepare('INSERT INTO settings (key_name, value_text) VALUES ("brand_name", ?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
    $stmt->execute([$brand]);

    // Save logo if provided
    if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','webp','svg'], true)) {
            $filename = 'logo_' . time() . '.' . $ext;
            if (@move_uploaded_file($_FILES['logo']['tmp_name'], $logoDir . '/' . $filename)) {
                $publicPath = 'uploads/brand/' . $filename;
                $s = db()->prepare('INSERT INTO settings (key_name, value_text) VALUES ("brand_logo_path", ?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
                $s->execute([$publicPath]);
            }
        }
    }
    set_flash('Brand settings updated','success');
    redirect_to('super_admin/settings_brand.php');
}

$brandName = get_setting('brand_name', 'MediTrack');
$logoPath = get_setting('brand_logo_path');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Brand Settings · Super Admin</title>
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
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
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
            <a href="<?php echo htmlspecialchars(base_url('super_admin/dashboard.php')); ?>">
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
            <a href="<?php echo htmlspecialchars(base_url('super_admin/reports.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Reports
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>">
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
                    <h1 class="text-3xl font-bold text-gray-900">Brand Settings</h1>
                    <p class="text-gray-600 mt-1">Customize your brand identity and logo</p>
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
            <?php [$flash, $ft] = get_flash(); if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $ft==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?> animate-fade-in">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?php if ($ft === 'success'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php endif; ?>
                        </svg>
                        <?php echo htmlspecialchars($flash); ?>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Brand Settings Form -->
            <div class="card mb-8">
                <div class="card-header">
                    <h3 class="text-lg font-semibold text-gray-900">Brand Configuration</h3>
                    <p class="text-sm text-gray-600">Update your brand name and logo settings.</p>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="form-group">
                            <label class="form-label">Brand Name</label>
                            <input name="brand_name" value="<?php echo htmlspecialchars($brandName); ?>" class="form-input" placeholder="Enter brand name" />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Logo Upload</label>
                            <input type="file" name="logo" accept="image/*" class="form-input" />
                            <p class="text-xs text-gray-500 mt-1">Supported formats: PNG, JPG, JPEG, WEBP, SVG</p>
                        </div>
                        <div class="md:col-span-2">
                            <button class="btn btn-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Save Brand Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($logoPath): ?>
            <!-- Current Logo Preview -->
            <div class="card">
                <div class="card-header">
                    <h3 class="text-lg font-semibold text-gray-900">Current Logo</h3>
                    <p class="text-sm text-gray-600">Preview of your current brand logo.</p>
                </div>
                <div class="card-body">
                    <div class="flex items-center space-x-6">
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <img src="<?php echo htmlspecialchars(base_url($logoPath)); ?>" class="h-16 w-auto" alt="Current logo" />
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Logo is displayed in:</p>
                            <ul class="text-sm text-gray-500 mt-1 space-y-1">
                                <li>• Dashboard sidebar</li>
                                <li>• Email templates</li>
                                <li>• System branding</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
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
            if (!window.superAdminProfileDropdownClickHandler) {
                window.superAdminProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && toggle && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.superAdminProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            if (!window.superAdminProfileDropdownKeyHandler) {
                window.superAdminProfileDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const menu = document.getElementById('profile-menu');
                        const arrow = document.getElementById('profile-arrow');
                        if (menu) menu.classList.add('hidden');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('keydown', window.superAdminProfileDropdownKeyHandler);
            }
        }

        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Update time immediately and then every second
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize night mode
            initNightMode();
            
            // Initialize profile dropdown
            initProfileDropdown();
            
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>


