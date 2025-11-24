<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'add') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'bhw';
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $purok_id = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : null;
        
        if ($email !== '' && $password !== '' && in_array($role, ['super_admin','bhw'], true)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare('INSERT INTO users(email, password_hash, role, first_name, last_name, purok_id) VALUES(?,?,?,?,?,?)');
            try {
                $stmt->execute([$email, $hash, $role, $first, $last, $purok_id]);
                // Send welcome email
                $html = email_template(
                    'Welcome to MediTrack',
                    'Your account has been created successfully.',
                    '<p>Hello <b>' . htmlspecialchars($first) . '</b>,</p><p>Your account role is <b>' . htmlspecialchars($role) . '</b>. You can now sign in to your dashboard.</p>',
                    'Open MediTrack',
                    base_url('index.php#login')
                );
                send_email($email, trim($first . ' ' . $last), 'Welcome to MediTrack', $html);
            } catch (Throwable $e) {}
            redirect_to('super_admin/users.php');
        }
    } elseif ($action === 'edit') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $purok_id = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : null;
        
        if ($user_id > 0) {
            $stmt = db()->prepare('UPDATE users SET first_name = ?, last_name = ?, purok_id = ? WHERE id = ?');
            try {
                $stmt->execute([$first, $last, $purok_id, $user_id]);
            } catch (Throwable $e) {}
            redirect_to('super_admin/users.php');
        }
    }
}

$users = db()->query("
    SELECT u.id, u.email, u.role, u.purok_id, 
           CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS name, 
           u.created_at, p.name AS purok_name, b.name AS barangay_name,
           r.date_of_birth
    FROM users u 
    LEFT JOIN puroks p ON u.purok_id = p.id 
    LEFT JOIN barangays b ON p.barangay_id = b.id 
    LEFT JOIN residents r ON r.user_id = u.id
    ORDER BY u.created_at DESC
")->fetchAll();

// Calculate age for resident users
foreach ($users as &$user) {
    if ($user['role'] === 'resident' && !empty($user['date_of_birth'])) {
        $birth_date = new DateTime($user['date_of_birth']);
        $today = new DateTime();
        $user['age'] = $today->diff($birth_date)->y;
        $user['is_senior'] = $user['age'] >= 60;
    } else {
        $user['age'] = null;
        $user['is_senior'] = false;
    }
}
unset($user); // Break reference

$puroks = db()->query("
    SELECT p.id, p.name, b.name AS barangay_name 
    FROM puroks p 
    JOIN barangays b ON p.barangay_id = b.id 
    ORDER BY b.name, p.name
")->fetchAll();

// Calculate user statistics
$total_users = count($users);
$super_admins = 0;
$bhws = 0;
$assigned_users = 0;
$unassigned_users = 0;

foreach ($users as $user) {
    if ($user['role'] === 'super_admin') {
        $super_admins++;
    } else {
        $bhws++;
    }
    
    if ($user['purok_name']) {
        $assigned_users++;
    } else {
        $unassigned_users++;
    }
}
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Users · Super Admin</title>
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
        
        .filter-chip {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.5);
            color: #6b7280;
        }
        
        .filter-chip:hover {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
            color: #2563eb;
        }
        
        .filter-chip.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-color: #3b82f6;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .user-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
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
        
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        
        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out forwards;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .hover-lift {
            transition: all 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Users Management</h1>
                    <p class="text-gray-600 mt-1">Manage system users and their roles</p>
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
            <!-- Statistics Dashboard -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Users Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg">
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
                            <p class="text-3xl font-bold text-gray-900" id="stat-total-users">0</p>
                            <p class="text-sm text-gray-500">Total</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">System Users</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">All registered users</span>
                        </div>
                    </div>
                </div>

                <!-- Super Admins Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-purple-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-super-admins">0</p>
                            <p class="text-sm text-gray-500">Admins</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Super Administrators</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-purple-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Full system access</span>
                        </div>
                    </div>
                </div>

                <!-- BHWs Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-bhws">0</p>
                            <p class="text-sm text-gray-500">BHWs</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Health Workers</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Field operations</span>
                        </div>
                    </div>
                </div>

                <!-- Assigned Users Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.3s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-orange-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-assigned">0</p>
                            <p class="text-sm text-gray-500">Assigned</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Purok Assignment</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">With purok assignment</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Toolbar: Search, Filters, and Add User Button -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <!-- Search Bar -->
                    <div class="relative flex-1 max-w-md">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" id="searchInput" placeholder="Search users..." 
                               class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 text-sm">
                    </div>
                    
                    <!-- Filter Dropdowns -->
                    <div class="flex items-center gap-3">
                        <select id="filterRole" class="px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="all">All Roles</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="bhw">BHW</option>
                            <option value="resident">Resident</option>
                        </select>
                        
                        <select id="filterStatus" class="px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        
                        <select id="filterAssignment" class="px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="all">All Assignments</option>
                            <option value="assigned">Assigned</option>
                            <option value="unassigned">Unassigned</option>
                        </select>
                    </div>
                    
                    <!-- Add User Button -->
                    <button onclick="openAddUserModal()" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 text-white font-medium rounded-lg hover:from-purple-700 hover:to-purple-800 transition-all duration-200 shadow-sm hover:shadow-md text-sm whitespace-nowrap">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        + Add User
                    </button>
                </div>
            </div>

            <!-- Users Data Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Age</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assignment</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created Date</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody" class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $u): ?>
                                <tr class="user-row hover:bg-gray-50 transition-colors" 
                                    data-name="<?php echo strtolower(htmlspecialchars($u['name'])); ?>"
                                    data-email="<?php echo strtolower(htmlspecialchars($u['email'])); ?>"
                                    data-role="<?php echo $u['role']; ?>"
                                    data-assigned="<?php echo $u['purok_name'] ? 'assigned' : 'unassigned'; ?>"
                                    data-status="active">
                                    <!-- Name -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center flex-shrink-0 mr-3">
                                                <span class="text-white font-semibold text-xs">
                                                    <?php echo strtoupper(substr($u['name'], 0, 2)); ?>
                                                </span>
                                            </div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($u['name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Email -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($u['email']); ?></div>
                                    </td>
                                    
                                    <!-- Role -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($u['role'] === 'super_admin'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                Super Admin
                                            </span>
                                        <?php elseif ($u['role'] === 'bhw'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                BHW
                                            </span>
                                        <?php elseif ($u['role'] === 'resident'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                Resident
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Age (only for residents) -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($u['role'] === 'resident' && isset($u['age']) && $u['age'] !== null): ?>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm text-gray-900"><?php echo (int)$u['age']; ?> years</span>
                                                <?php if ($u['is_senior']): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200">
                                                        Senior
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Assignment -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($u['role'] === 'bhw' && $u['purok_name']): ?>
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($u['purok_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($u['barangay_name']); ?></div>
                                        <?php elseif ($u['role'] === 'resident' || $u['role'] === 'super_admin' || !$u['purok_name']): ?>
                                            <span class="text-sm text-gray-400">—</span>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>
                                            Active
                                        </span>
                                    </td>
                                    
                                    <!-- Created Date -->
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo date('M j, Y', strtotime($u['created_at'])); ?>
                                    </td>
                                    
                                    <!-- Actions -->
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="relative inline-block text-left">
                                            <button onclick="toggleActionMenu(<?php echo $u['id']; ?>)" class="inline-flex items-center p-2 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-lg transition-colors" id="action-btn-<?php echo $u['id']; ?>">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                                                </svg>
                                            </button>
                                            
                                            <!-- Action Menu Dropdown -->
                                            <div id="action-menu-<?php echo $u['id']; ?>" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-10">
                                                <button onclick="openEditModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name']); ?>', <?php echo $u['purok_id'] ?: 'null'; ?>); closeActionMenu(<?php echo $u['id']; ?>);" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                    Edit
                                                </button>
                                                <button onclick="deactivateUser(<?php echo $u['id']; ?>); closeActionMenu(<?php echo $u['id']; ?>);" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                                    </svg>
                                                    Deactivate
                                                </button>
                                                <button onclick="viewUserActivity(<?php echo $u['id']; ?>); closeActionMenu(<?php echo $u['id']; ?>);" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                                    </svg>
                                                    View Activity
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- No Results Message -->
                <div id="noResults" class="hidden text-center py-12">
                    <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">No users found</h3>
                    <p class="text-sm text-gray-600">Try adjusting your search or filter criteria.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit User Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 1rem; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <div class="p-8">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">Edit User</h3>
                            <p class="text-gray-600">Update user information and assignments</p>
                        </div>
                    </div>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form id="editForm" method="post" class="space-y-6">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">First Name</label>
                            <input type="text" name="first_name" id="edit_first_name" 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter first name" required />
                    </div>
                    
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Last Name</label>
                            <input type="text" name="last_name" id="edit_last_name" 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter last name" required />
                        </div>
                    </div>
                    
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Assigned Purok</label>
                        <select name="purok_id" id="edit_purok_id" 
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                            <option value="">Select Purok</option>
                            <?php foreach ($puroks as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['barangay_name'] . ' - ' . $p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()" 
                                class="px-6 py-3 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white font-semibold rounded-xl hover:from-green-700 hover:to-green-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 1rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <div class="p-8">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">Add New User</h3>
                            <p class="text-gray-600">Create a new user account with appropriate role</p>
                        </div>
                    </div>
                    <button onclick="closeAddUserModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="add" />
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Email Address</label>
                            <input name="email" type="email" required 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter email address" />
                        </div>
                        
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Password</label>
                            <input name="password" type="text" required 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter password" />
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Role</label>
                            <select name="role" 
                                    class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                                <option value="bhw">Barangay Health Worker (BHW)</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                        
                        <div class="space-y-2" id="purok-group">
                            <label class="block text-sm font-semibold text-gray-700">Assigned Purok (BHW only)</label>
                            <select name="purok_id" 
                                    class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                                <option value="">Select Purok</option>
                                <?php foreach ($puroks as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['barangay_name'] . ' - ' . $p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">First Name</label>
                            <input name="first_name" 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter first name" />
                        </div>
                        
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Last Name</label>
                            <input name="last_name" 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm" 
                                   placeholder="Enter last name" />
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- User Activity Modal -->
    <div id="activityModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; padding: 0; border-radius: 1rem; max-width: 900px; width: 90%; max-height: 90vh; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); display: flex; flex-direction: column;">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6 text-white">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold" id="activityModalTitle">User Activity</h3>
                            <p class="text-blue-100 text-sm" id="activityModalSubtitle">Loading activity data...</p>
                        </div>
                    </div>
                    <button onclick="closeActivityModal()" class="text-white/80 hover:text-white transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Modal Content -->
            <div class="flex-1 overflow-y-auto p-6">
                <div id="activityLoading" class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                    <p class="mt-4 text-gray-600">Loading activity data...</p>
                </div>
                
                <div id="activityContent" class="hidden">
                    <div id="activityEmpty" class="hidden text-center py-12">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-1">No Activity Found</h3>
                        <p class="text-sm text-gray-600">This user has no recorded activity yet.</p>
                    </div>
                    
                    <div id="activityList" class="space-y-3">
                        <!-- Activity items will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openAddUserModal() {
            // Close any other open modals first
            closeEditModal();
            
            const modal = document.getElementById('addUserModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '1';
            }
        }

        function closeAddUserModal() {
            const modal = document.getElementById('addUserModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                modal.style.opacity = '0';
            }
        }

        function openEditModal(userId, fullName, purokId) {
            // Close any other open modals first
            closeAddUserModal();
            
            // Parse the full name to get first and last name
            const nameParts = fullName.trim().split(' ');
            const firstName = nameParts[0] || '';
            const lastName = nameParts.slice(1).join(' ') || '';
            
            // Set form values
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_first_name').value = firstName;
            document.getElementById('edit_last_name').value = lastName;
            document.getElementById('edit_purok_id').value = purokId || '';
            
            // Show modal
            const modal = document.getElementById('editModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '1';
            }
        }
        
        function closeEditModal() {
            const modal = document.getElementById('editModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                modal.style.opacity = '0';
            }
        }
        
        
        // Show/hide purok field based on role
        document.querySelector('select[name="role"]').addEventListener('change', function() {
            const purokGroup = document.getElementById('purok-group');
            if (this.value === 'bhw') {
                purokGroup.style.display = 'block';
            } else {
                purokGroup.style.display = 'none';
            }
        });
        
        // Initialize purok field visibility
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.querySelector('select[name="role"]');
            const purokGroup = document.getElementById('purok-group');
            if (roleSelect.value === 'bhw') {
                purokGroup.style.display = 'block';
            } else {
                purokGroup.style.display = 'none';
            }

            // Animate stats on load with real data
            const stats = ['stat-total-users', 'stat-super-admins', 'stat-bhws', 'stat-assigned'];
            const values = [<?php echo $total_users; ?>, <?php echo $super_admins; ?>, <?php echo $bhws; ?>, <?php echo $assigned_users; ?>];
            
            stats.forEach((statId, index) => {
                const element = document.getElementById(statId);
                if (element) {
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
                }
            });

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

            // Search and filter functionality
            const searchInput = document.getElementById('searchInput');
            const filterRole = document.getElementById('filterRole');
            const filterStatus = document.getElementById('filterStatus');
            const filterAssignment = document.getElementById('filterAssignment');
            const userRows = document.querySelectorAll('.user-row');
            const usersTableBody = document.getElementById('usersTableBody');
            const noResults = document.getElementById('noResults');

            let currentSearch = '';
            let currentRole = 'all';
            let currentStatus = 'all';
            let currentAssignment = 'all';

            // Search functionality
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    currentSearch = this.value.toLowerCase();
                    filterUsers();
                });
            }

            // Filter dropdowns
            if (filterRole) {
                filterRole.addEventListener('change', function() {
                    currentRole = this.value;
                    filterUsers();
                });
            }

            if (filterStatus) {
                filterStatus.addEventListener('change', function() {
                    currentStatus = this.value;
                    filterUsers();
                });
            }

            if (filterAssignment) {
                filterAssignment.addEventListener('change', function() {
                    currentAssignment = this.value;
                    filterUsers();
                });
            }

            function filterUsers() {
                let visibleCount = 0;
                
                userRows.forEach(row => {
                    const name = row.dataset.name || '';
                    const email = row.dataset.email || '';
                    const role = row.dataset.role || '';
                    const assigned = row.dataset.assigned || '';
                    const status = row.dataset.status || 'active';
                    
                    let matchesSearch = true;
                    let matchesRole = true;
                    let matchesStatus = true;
                    let matchesAssignment = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = name.includes(currentSearch) || email.includes(currentSearch);
                    }
                    
                    // Check role filter
                    if (currentRole !== 'all') {
                        matchesRole = role === currentRole;
                    }
                    
                    // Check status filter
                    if (currentStatus !== 'all') {
                        matchesStatus = status === currentStatus;
                    }
                    
                    // Check assignment filter
                    if (currentAssignment !== 'all') {
                        matchesAssignment = assigned === currentAssignment;
                    }
                    
                    if (matchesSearch && matchesRole && matchesStatus && matchesAssignment) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (usersTableBody) usersTableBody.style.display = 'none';
                    if (noResults) noResults.classList.remove('hidden');
                } else {
                    if (usersTableBody) usersTableBody.style.display = '';
                    if (noResults) noResults.classList.add('hidden');
                }
            }

            // Action menu functions
            window.toggleActionMenu = function(userId) {
                const menu = document.getElementById('action-menu-' + userId);
                if (!menu) return;
                
                // Close all other menus
                document.querySelectorAll('[id^="action-menu-"]').forEach(m => {
                    if (m.id !== 'action-menu-' + userId) {
                        m.classList.add('hidden');
                    }
                });
                
                menu.classList.toggle('hidden');
            };

            window.closeActionMenu = function(userId) {
                const menu = document.getElementById('action-menu-' + userId);
                if (menu) menu.classList.add('hidden');
            };

            // Close menus when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('[id^="action-menu-"]') && !e.target.closest('[id^="action-btn-"]')) {
                    document.querySelectorAll('[id^="action-menu-"]').forEach(menu => {
                        menu.classList.add('hidden');
                    });
                }
            });

            // Placeholder functions for action menu items
            window.deactivateUser = function(userId) {
                Swal.fire({
                    title: 'Deactivate User?',
                    text: 'This will deactivate the user account. They will not be able to log in.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, Deactivate',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // TODO: Implement deactivation logic
                        Swal.fire('Deactivated!', 'User has been deactivated.', 'success');
                    }
                });
            };

            // User Activity Modal Functions
            window.viewUserActivity = async function(userId) {
                const modal = document.getElementById('activityModal');
                const loading = document.getElementById('activityLoading');
                const content = document.getElementById('activityContent');
                const empty = document.getElementById('activityEmpty');
                const list = document.getElementById('activityList');
                const title = document.getElementById('activityModalTitle');
                const subtitle = document.getElementById('activityModalSubtitle');
                
                if (!modal) return;
                
                // Show modal and loading state
                modal.style.display = 'flex';
                loading.classList.remove('hidden');
                content.classList.add('hidden');
                empty.classList.add('hidden');
                list.innerHTML = '';
                
                try {
                    const response = await fetch(`get_user_activity.php?user_id=${userId}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update modal title
                        title.textContent = data.user.name + '\'s Activity';
                        subtitle.textContent = data.user.email + ' • ' + data.user.role.toUpperCase() + ' • ' + data.count + ' activities';
                        
                        // Hide loading
                        loading.classList.add('hidden');
                        content.classList.remove('hidden');
                        
                        if (data.activities && data.activities.length > 0) {
                            empty.classList.add('hidden');
                            
                            // Display activities
                            data.activities.forEach((activity, index) => {
                                const activityCard = document.createElement('div');
                                activityCard.className = 'bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow';
                                
                                // Determine icon and color based on activity type
                                let iconSvg = '';
                                let bgColor = 'bg-blue-100';
                                let iconColor = 'text-blue-600';
                                
                                if (activity.type === 'inventory') {
                                    if (activity.details['Transaction Type'] === 'IN') {
                                        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>';
                                        bgColor = 'bg-green-100';
                                        iconColor = 'text-green-600';
                                    } else {
                                        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>';
                                        bgColor = 'bg-red-100';
                                        iconColor = 'text-red-600';
                                    }
                                } else if (activity.type === 'request') {
                                    if (activity.details['Status'] === 'Approved' || activity.details['Status'] === 'Claimed') {
                                        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                                        bgColor = 'bg-green-100';
                                        iconColor = 'text-green-600';
                                    } else if (activity.details['Status'] === 'Rejected') {
                                        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                                        bgColor = 'bg-red-100';
                                        iconColor = 'text-red-600';
                                    } else {
                                        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                                        bgColor = 'bg-yellow-100';
                                        iconColor = 'text-yellow-600';
                                    }
                                }
                                
                                let detailsHtml = '';
                                for (const [key, value] of Object.entries(activity.details)) {
                                    detailsHtml += `
                                        <div class="flex justify-between py-1.5 border-b border-gray-100 last:border-0">
                                            <span class="text-sm text-gray-600">${key}:</span>
                                            <span class="text-sm font-medium text-gray-900">${value}</span>
                                        </div>
                                    `;
                                }
                                
                                activityCard.innerHTML = `
                                    <div class="flex items-start space-x-4">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 ${bgColor} rounded-lg flex items-center justify-center">
                                                <svg class="w-5 h-5 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    ${iconSvg}
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between mb-1">
                                                <h4 class="text-sm font-semibold text-gray-900">${activity.title}</h4>
                                                <span class="text-xs text-gray-500 ml-2">${activity.date}</span>
                                            </div>
                                            <p class="text-sm text-gray-600 mb-3">${activity.description}</p>
                                            <div class="bg-gray-50 rounded-lg p-3 text-xs">
                                                ${detailsHtml}
                                            </div>
                                        </div>
                                    </div>
                                `;
                                
                                list.appendChild(activityCard);
                            });
                        } else {
                            empty.classList.remove('hidden');
                        }
                    } else {
                        loading.classList.add('hidden');
                        Swal.fire({
                            title: 'Error',
                            text: data.message || 'Failed to load user activity',
                            icon: 'error',
                            confirmButtonColor: '#dc2626'
                        });
                        closeActivityModal();
                    }
                } catch (error) {
                    console.error('Error:', error);
                    loading.classList.add('hidden');
                    Swal.fire({
                        title: 'Error',
                        text: 'Failed to load user activity. Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#dc2626'
                    });
                    closeActivityModal();
                }
            };

            window.closeActivityModal = function() {
                const modal = document.getElementById('activityModal');
                if (modal) {
                    modal.style.display = 'none';
                }
            };

            // Add keyboard navigation for search
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        currentSearch = '';
                        filterUsers();
                    }
                });
            }

            // Add click outside to close modals
            document.addEventListener('click', function(e) {
                const addModal = document.getElementById('addUserModal');
                const editModal = document.getElementById('editModal');
                const activityModal = document.getElementById('activityModal');
                
                if (addModal && addModal.style.display === 'flex') {
                    const modalContent = addModal.querySelector('div > div');
                    if (modalContent && !modalContent.contains(e.target) && e.target === addModal) {
                        closeAddUserModal();
                    }
                }
                
                if (editModal && editModal.style.display === 'flex') {
                    const modalContent = editModal.querySelector('div > div');
                    if (modalContent && !modalContent.contains(e.target) && e.target === editModal) {
                        closeEditModal();
                    }
                }
                
                if (activityModal && activityModal.style.display === 'flex') {
                    const modalContent = activityModal.querySelector('div > div');
                    if (modalContent && !modalContent.contains(e.target) && e.target === activityModal) {
                        closeActivityModal();
                    }
                }
            });

            // Add escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const addModal = document.getElementById('addUserModal');
                    const editModal = document.getElementById('editModal');
                    const activityModal = document.getElementById('activityModal');
                    
                    if (addModal && addModal.style.display === 'flex') {
                        closeAddUserModal();
                    } else if (editModal && editModal.style.display === 'flex') {
                        closeEditModal();
                    } else if (activityModal && activityModal.style.display === 'flex') {
                        closeActivityModal();
                    }
                }
            });
        });

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



