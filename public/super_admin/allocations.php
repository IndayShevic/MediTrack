<?php

require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';



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

// Create allocation_programs table if it doesn't exist
try {
    db()->exec('
        CREATE TABLE IF NOT EXISTS allocation_programs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_name VARCHAR(255) NOT NULL,
            medicine_id INT NOT NULL,
            quantity_per_senior INT NOT NULL,
            frequency ENUM("monthly", "quarterly") NOT NULL DEFAULT "monthly",
            scope_type ENUM("barangay", "purok") NOT NULL DEFAULT "barangay",
            barangay_id INT NULL,
            purok_id INT NULL,
            claim_window_days INT DEFAULT 14,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            INDEX idx_active (is_active),
            INDEX idx_frequency (frequency),
            INDEX idx_scope (scope_type)
        ) ENGINE=InnoDB
    ');
} catch (Exception $e) {
    // Table might already exist
}

// Create allocation_distributions table for tracking
try {
    db()->exec('
        CREATE TABLE IF NOT EXISTS allocation_distributions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program_id INT NOT NULL,
            resident_id INT NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            quantity_allocated INT NOT NULL,
            quantity_claimed INT DEFAULT 0,
            distribution_month VARCHAR(7) NOT NULL,
            status ENUM("pending", "claimed", "expired") DEFAULT "pending",
            claim_deadline DATE NOT NULL,
            claimed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (program_id) REFERENCES allocation_programs(id) ON DELETE CASCADE,
            FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_resident (resident_id),
            INDEX idx_month (distribution_month),
            UNIQUE KEY unique_allocation (program_id, resident_id, distribution_month)
        ) ENGINE=InnoDB
    ');
} catch (Exception $e) {
    // Table might already exist
}

$medicines = db()->query('SELECT id, name FROM medicines ORDER BY name')->fetchAll();
$barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();

$success_message = '';
$error_message = '';

// Handle DELETE action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = db()->prepare('DELETE FROM allocation_programs WHERE id = ?');
    try {
        $stmt->execute([$id]);
        $success_message = 'Allocation program deleted successfully!';
    } catch (Throwable $e) {
        $error_message = 'Failed to delete program. It may have active distributions.';
    }
    header('Location: ' . base_url('super_admin/allocations.php'));
    exit;
}

// Handle CREATE or UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $program_name = trim($_POST['program_name'] ?? '');
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $quantity_per_senior = (int)($_POST['quantity_per_senior'] ?? 0);
    $frequency = $_POST['frequency'] ?? 'monthly';
    $scope_type = $_POST['scope_type'] ?? 'barangay';
    $barangay_id = isset($_POST['barangay_id']) && $_POST['barangay_id'] !== '' ? (int)$_POST['barangay_id'] : null;
    $purok_id = isset($_POST['purok_id']) && $_POST['purok_id'] !== '' ? (int)$_POST['purok_id'] : null;
    $claim_window_days = (int)($_POST['claim_window_days'] ?? 14);
    
    if ($program_name !== '' && $medicine_id > 0 && $quantity_per_senior > 0 && in_array($frequency, ['monthly','quarterly'], true) && in_array($scope_type, ['barangay','purok'], true)) {
        if ($program_id > 0) {
            // UPDATE existing program
            $stmt = db()->prepare('UPDATE allocation_programs SET program_name=?, medicine_id=?, quantity_per_senior=?, frequency=?, scope_type=?, barangay_id=?, purok_id=?, claim_window_days=? WHERE id=?');
            try { 
                $stmt->execute([$program_name, $medicine_id, $quantity_per_senior, $frequency, $scope_type, $barangay_id, $purok_id, $claim_window_days, $program_id]);
                set_flash('Allocation program updated successfully!', 'success');
            } catch (Throwable $e) {
                set_flash('Failed to update program. Please try again.', 'error');
            }
        } else {
            // CREATE new program
        $stmt = db()->prepare('INSERT INTO allocation_programs (program_name, medicine_id, quantity_per_senior, frequency, scope_type, barangay_id, purok_id, claim_window_days) VALUES (?,?,?,?,?,?,?,?)');
            try { 
                $stmt->execute([$program_name, $medicine_id, $quantity_per_senior, $frequency, $scope_type, $barangay_id, $purok_id, $claim_window_days]);
                set_flash('Allocation program created successfully!', 'success');
            } catch (Throwable $e) {
                set_flash('Failed to create program. Please try again.', 'error');
            }
        }
        redirect_to('super_admin/allocations.php');
        exit;
    } else {
        set_flash('Please fill in all required fields correctly.', 'error');
        redirect_to('super_admin/allocations.php');
        exit;
    }
}

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

$programs = db()->query('SELECT ap.id, ap.program_name, ap.medicine_id, m.name AS medicine, ap.frequency, ap.scope_type, ap.barangay_id, ap.purok_id, ap.quantity_per_senior, ap.claim_window_days FROM allocation_programs ap JOIN medicines m ON m.id=ap.medicine_id ORDER BY ap.id DESC')->fetchAll();

// Calculate statistics
$total_programs = count($programs);
$monthly_programs = 0;
$quarterly_programs = 0;
$barangay_programs = 0;
$purok_programs = 0;

foreach ($programs as $program) {
    if ($program['frequency'] === 'monthly') {
        $monthly_programs++;
    } else {
        $quarterly_programs++;
    }
    
    if ($program['scope_type'] === 'barangay') {
        $barangay_programs++;
    } else {
        $purok_programs++;
    }
}
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Allocation Programs Â· Super Admin</title>
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
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php
        list($msg, $type) = get_flash();
        if ($msg): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $type === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?> flex items-center justify-between animate-fade-in-up">
                <div class="flex items-center">
                    <?php if ($type === 'success'): ?>
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-sm font-semibold hover:underline">Dismiss</button>
            </div>
        <?php endif; ?>

        <!-- Header (hidden, using global shell header instead) -->
        <div class="content-header" style="display: none;">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Allocation Programs</h1>
                    <p class="text-gray-600 mt-1">Create and manage senior citizen medicine allocation programs</p>
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
                <!-- Total Programs Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-total-programs">0</p>
                            <p class="text-sm text-gray-500">Total</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Allocation Programs</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">All programs created</span>
                        </div>
                    </div>
                </div>

                <!-- Monthly Programs Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-monthly-programs">0</p>
                            <p class="text-sm text-gray-500">Monthly</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Monthly Programs</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Recurring monthly</span>
                        </div>
                    </div>
                </div>

                <!-- Quarterly Programs Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-purple-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-quarterly-programs">0</p>
                            <p class="text-sm text-gray-500">Quarterly</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Quarterly Programs</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-purple-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Every 3 months</span>
                        </div>
                    </div>
                </div>

                <!-- Active Programs Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.3s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-orange-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-active-programs"><?php echo $total_programs; ?></p>
                            <p class="text-sm text-gray-500">Active</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Active Programs</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Currently running</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Program Button -->
            <div class="flex justify-end mb-8">
                <button onclick="openAddProgramModal()" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 animate-fade-in-up">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add Program
                </button>
            </div>

            <!-- Search and Filter -->
            <div class="mb-8">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                    <!-- Search Bar -->
                    <div class="relative flex-1 max-w-md">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" id="searchInput" placeholder="Search programs..." 
                               class="block w-full pl-10 pr-3 py-3 border border-gray-200 rounded-xl leading-5 bg-white/50 backdrop-blur-sm placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200">
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2">
                        <button class="filter-chip active" data-filter="all">All</button>
                        <button class="filter-chip" data-filter="monthly">Monthly</button>
                        <button class="filter-chip" data-filter="quarterly">Quarterly</button>
                        <button class="filter-chip" data-filter="barangay">Barangay</button>
                        <button class="filter-chip" data-filter="purok">Purok</button>
                    </div>
                </div>
            </div>

            <!-- Programs Grid -->
            <div id="programsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <?php foreach ($programs as $index => $p): ?>
                    <div class="program-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                         data-name="<?php echo strtolower(htmlspecialchars($p['program_name'])); ?>"
                         data-medicine="<?php echo strtolower(htmlspecialchars($p['medicine'])); ?>"
                         data-frequency="<?php echo $p['frequency']; ?>"
                         data-scope="<?php echo $p['scope_type']; ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s">
                        
                        <!-- Program Header -->
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-mono text-sm text-gray-600">#<?php echo (int)$p['id']; ?></div>
                                    <div class="text-xs text-gray-500">Program</div>
                                </div>
                            </div>
                            
                            <!-- Status Badge -->
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Active
                            </span>
                        </div>

                        <!-- Program Info -->
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($p['program_name']); ?></h3>
                            <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($p['medicine']); ?></p>
                            
                            <!-- Program Details -->
                            <div class="space-y-3">
                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-700">Frequency</p>
                                            <p class="text-lg font-bold text-blue-600"><?php echo ucfirst(htmlspecialchars($p['frequency'])); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-green-50 to-green-100 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-700">Scope</p>
                                            <p class="text-lg font-bold text-green-600"><?php echo ucfirst(htmlspecialchars($p['scope_type'])); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-700">Quantity per Senior</p>
                                            <p class="text-lg font-bold text-purple-600"><?php echo (int)$p['quantity_per_senior']; ?> units</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-700">Claim Window</p>
                                            <p class="text-lg font-bold text-orange-600"><?php echo (int)$p['claim_window_days']; ?> days</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex justify-end space-x-3">
                            <button onclick='editProgram(<?php echo json_encode($p); ?>)' class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Edit
                            </button>
                            <button onclick="deleteProgram(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['program_name'])); ?>')" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white font-medium rounded-xl hover:from-red-700 hover:to-red-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- No Results Message -->
            <div id="noResults" class="hidden text-center py-12">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">No programs found</h3>
                <p class="text-gray-600 mb-6">Try adjusting your search or filter criteria.</p>
                <button onclick="clearFilters()" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Clear Filters
                </button>
            </div>

            <?php if (empty($programs)): ?>
                <div class="card">
                    <div class="card-body text-center py-12">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">No allocation programs yet</h3>
                        <p class="text-gray-600 mb-4">Create your first senior citizen medicine allocation program above.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add Program Modal -->
    <div id="addProgramModal" class="fixed inset-0 z-50 hidden backdrop-blur-sm" style="background-color: rgba(0, 0, 0, 0.6); animation: fadeIn 0.2s ease-out;">
        <div class="flex items-center justify-center min-h-screen p-4" onclick="event.target === this && closeAddProgramModal()">
            <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full max-h-[95vh] overflow-hidden relative" style="animation: slideUp 0.3s ease-out;">
                <!-- Header with Gradient -->
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 p-8 relative overflow-hidden">
                    <div class="absolute inset-0 opacity-20">
                        <div class="absolute top-0 left-0 w-40 h-40 bg-white rounded-full -translate-x-20 -translate-y-20"></div>
                        <div class="absolute bottom-0 right-0 w-60 h-60 bg-white rounded-full translate-x-20 translate-y-20"></div>
                    </div>
                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                        </div>
                        <div>
                                <h3 id="modalTitle" class="text-3xl font-bold text-white">New Allocation Program</h3>
                                <p class="text-blue-100 mt-1">Configure medicine distribution for seniors</p>
                        </div>
                    </div>
                        <button onclick="closeAddProgramModal()" class="text-white/80 hover:text-white hover:bg-white/20 p-2 rounded-xl transition-all duration-200">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    </div>
                </div>
                
                <!-- Form Content -->
                <div class="p-8 overflow-y-auto" style="max-height: calc(95vh - 180px);">
                    <form method="post" id="programForm" class="space-y-6">
                        <input type="hidden" name="program_id" id="programId" value="0">
                        <!-- Program Name -->
                    <div class="space-y-2">
                            <label class="flex items-center text-sm font-semibold text-gray-700">
                                <svg class="w-4 h-4 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                Program Name
                                <span class="text-red-500 ml-1">*</span>
                            </label>
                        <input name="program_name" required 
                                   class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white" 
                                   placeholder="e.g., Senior Citizen Medicine Assistance Program" />
                            <p class="text-xs text-gray-500 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                Give your program a descriptive name
                            </p>
                    </div>
                    
                        <!-- Medicine Selection -->
                    <div class="space-y-2">
                            <label class="flex items-center text-sm font-semibold text-gray-700">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                                Medicine
                                <span class="text-red-500 ml-1">*</span>
                            </label>
                            <select name="medicine_id" id="medicineSelect" required 
                                    class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white">
                                <option value="">Select a medicine to allocate</option>
                            <?php foreach ($medicines as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                        <!-- Grid Layout for Quantity and Claim Window -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                                <label class="flex items-center text-sm font-semibold text-gray-700">
                                    <svg class="w-4 h-4 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                    </svg>
                                    Quantity per Senior
                                    <span class="text-red-500 ml-1">*</span>
                                </label>
                                <div class="relative">
                                    <input type="number" min="1" max="1000" name="quantity_per_senior" id="quantityInput" required 
                                           class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white" 
                                           placeholder="0" />
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">units</span>
                                </div>
                                <p class="text-xs text-gray-500">Allocation amount per eligible senior</p>
                        </div>
                        
                        <div class="space-y-2">
                                <label class="flex items-center text-sm font-semibold text-gray-700">
                                    <svg class="w-4 h-4 mr-2 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Claim Window
                                </label>
                                <div class="relative">
                                    <input type="number" min="1" max="90" name="claim_window_days" value="14" 
                                           class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white" 
                                           placeholder="14" />
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">days</span>
                                </div>
                                <p class="text-xs text-gray-500">How long seniors can claim allocation</p>
                        </div>
                    </div>
                    
                        <!-- Frequency and Scope -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                                <label class="flex items-center text-sm font-semibold text-gray-700">
                                    <svg class="w-4 h-4 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    Frequency
                                </label>
                            <select name="frequency" 
                                        class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white">
                                    <option value="monthly">Monthly Distribution</option>
                                    <option value="quarterly">Quarterly Distribution</option>
                            </select>
                        </div>
                        
                        <div class="space-y-2">
                                <label class="flex items-center text-sm font-semibold text-gray-700">
                                    <svg class="w-4 h-4 mr-2 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    Scope
                                </label>
                                <select name="scope_type" id="scopeSelect"
                                        class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white">
                                    <option value="barangay">Barangay-wide</option>
                                    <option value="purok">Specific Purok</option>
                            </select>
                        </div>
                    </div>
                    
                        <!-- Barangay Selection -->
                    <div class="space-y-2">
                            <label class="flex items-center text-sm font-semibold text-gray-700">
                                <svg class="w-4 h-4 mr-2 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                Target Barangay
                                <span class="text-xs text-gray-400 ml-2">(Optional)</span>
                            </label>
                        <select name="barangay_id" 
                                    class="w-full px-4 py-3.5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-gray-50 hover:bg-white">
                                <option value="">All Barangays</option>
                            <?php foreach ($barangays as $b): ?>
                                    <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                            <p class="text-xs text-gray-500">Leave empty to apply to all barangays</p>
                    </div>
                    
                        <!-- Summary Box -->
                        <div id="programSummary" class="hidden bg-gradient-to-r from-blue-50 to-purple-50 border-2 border-blue-200 rounded-xl p-6">
                            <h4 class="font-semibold text-gray-900 mb-3 flex items-center">
                                <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                Program Summary
                            </h4>
                            <div id="summaryContent" class="text-sm text-gray-700 space-y-1"></div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-end space-x-4 pt-4 border-t">
                            <button type="button" onclick="closeAddProgramModal()" 
                                    class="px-6 py-3 border-2 border-gray-300 text-gray-700 font-semibold rounded-xl hover:bg-gray-50 transition-all duration-200">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 text-white font-semibold rounded-xl hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Create Program
                        </button>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        @keyframes slideOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }
        .animate-slide-in-right {
            animation: slideInRight 0.3s ease-out;
        }
    </style>

    <script>
        function openAddProgramModal() {
            document.getElementById('addProgramModal').classList.remove('hidden');
            document.getElementById('programForm').reset();
            document.getElementById('programId').value = '0';
            document.getElementById('modalTitle').textContent = 'New Allocation Program';
            document.getElementById('programSummary').classList.add('hidden');
        }

        function closeAddProgramModal() {
            document.getElementById('addProgramModal').classList.add('hidden');
        }
        
        function editProgram(program) {
            // Open modal
            document.getElementById('addProgramModal').classList.remove('hidden');
            
            // Update title
            document.getElementById('modalTitle').textContent = 'Edit Allocation Program';
            
            // Fill form with program data
            document.getElementById('programId').value = program.id;
            document.querySelector('[name="program_name"]').value = program.program_name;
            document.querySelector('[name="medicine_id"]').value = program.medicine_id;
            document.querySelector('[name="quantity_per_senior"]').value = program.quantity_per_senior;
            document.querySelector('[name="claim_window_days"]').value = program.claim_window_days;
            document.querySelector('[name="frequency"]').value = program.frequency;
            document.querySelector('[name="scope_type"]').value = program.scope_type;
            document.querySelector('[name="barangay_id"]').value = program.barangay_id || '';
            
            // Update summary
            updateProgramSummary();
        }
        
        function deleteProgram(id, name) {
            if (confirm(`Are you sure you want to delete "${name}"?\n\nThis action cannot be undone. Any existing distributions will also be affected.`)) {
                window.location.href = `allocations.php?action=delete&id=${id}`;
            }
        }
        
        // Live form preview and validation
        function updateProgramSummary() {
            const programName = document.querySelector('[name="program_name"]').value;
            const medicineSelect = document.getElementById('medicineSelect');
            const medicineName = medicineSelect.options[medicineSelect.selectedIndex]?.text;
            const quantity = document.getElementById('quantityInput').value;
            const claimWindow = document.querySelector('[name="claim_window_days"]').value;
            const frequency = document.querySelector('[name="frequency"]').value;
            const scope = document.querySelector('[name="scope_type"]').value;
            
            const summary = document.getElementById('programSummary');
            const summaryContent = document.getElementById('summaryContent');
            
            if (programName || quantity) {
                summary.classList.remove('hidden');
                let html = '';
                
                if (programName) {
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Program:</span><span class="text-gray-800">${programName}</span></div>`;
                }
                if (medicineName && medicineName !== 'Select a medicine to allocate') {
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Medicine:</span><span class="text-gray-800">${medicineName}</span></div>`;
                }
                if (quantity) {
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Quantity:</span><span class="text-gray-800">${quantity} units per senior</span></div>`;
                }
                if (frequency) {
                    const freqText = frequency === 'monthly' ? 'Monthly' : 'Quarterly';
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Frequency:</span><span class="text-gray-800">${freqText}</span></div>`;
                }
                if (scope) {
                    const scopeText = scope === 'barangay' ? 'Barangay-wide' : 'Specific Purok';
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Scope:</span><span class="text-gray-800">${scopeText}</span></div>`;
                }
                if (claimWindow) {
                    html += `<div class="flex items-start"><span class="font-semibold min-w-[120px]">Claim Window:</span><span class="text-gray-800">${claimWindow} days</span></div>`;
                }
                
                summaryContent.innerHTML = html;
            } else {
                summary.classList.add('hidden');
            }
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            document.querySelector('.filter-chip[data-filter="all"]').classList.add('active');
            filterPrograms();
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Close modal when clicking outside
            const modal = document.getElementById('addProgramModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeAddProgramModal();
                    }
                });
            }
            
            // ESC key to close modal
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAddProgramModal();
                }
            });
            
            // Live form summary update
            const formInputs = document.querySelectorAll('#programForm input, #programForm select');
            formInputs.forEach(input => {
                input.addEventListener('input', updateProgramSummary);
                input.addEventListener('change', updateProgramSummary);
            });
            
            // Add input animations
            formInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.transform = 'scale(1.02)';
                    this.style.transition = 'transform 0.2s ease';
                });
                input.addEventListener('blur', function() {
                    this.style.transform = 'scale(1)';
                });
            });
            const searchInput = document.getElementById('searchInput');
            const filterChips = document.querySelectorAll('.filter-chip');
            const programCards = document.querySelectorAll('.program-card');
            const programsGrid = document.getElementById('programsGrid');
            const noResults = document.getElementById('noResults');
            const programCount = document.getElementById('program-count');

            let currentFilter = 'all';
            let currentSearch = '';

            // Search functionality
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterPrograms();
            });

            // Filter functionality
            filterChips.forEach(chip => {
                chip.addEventListener('click', function() {
                    // Update active state
                    filterChips.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    
                    currentFilter = this.dataset.filter;
                    filterPrograms();
                });
            });

            function filterPrograms() {
                let visibleCount = 0;
                
                programCards.forEach(card => {
                    const name = card.dataset.name;
                    const medicine = card.dataset.medicine;
                    const frequency = card.dataset.frequency;
                    const scope = card.dataset.scope;
                    
                    let matchesSearch = true;
                    let matchesFilter = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = name.includes(currentSearch) || medicine.includes(currentSearch);
                    }
                    
                    // Check filter match
                    if (currentFilter !== 'all') {
                        matchesFilter = frequency === currentFilter || scope === currentFilter;
                    }
                    
                    if (matchesSearch && matchesFilter) {
                        card.style.display = 'block';
                        card.classList.add('animate-fade-in');
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                        card.classList.remove('animate-fade-in');
                    }
                });
                
                // Update count
                programCount.textContent = visibleCount;
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
                    programsGrid.classList.add('hidden');
                } else {
                    noResults.classList.add('hidden');
                    programsGrid.classList.remove('hidden');
                }
            }

            // Animate stats on load
            const stats = ['stat-total-programs', 'stat-monthly-programs', 'stat-quarterly-programs'];
            const values = [<?php echo $total_programs; ?>, <?php echo $monthly_programs; ?>, <?php echo $quarterly_programs; ?>];
            
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

            // Initialize profile dropdown when page loads
            document.addEventListener('DOMContentLoaded', function() {
                initProfileDropdown();
            });

            // Add click outside to close modal
            document.addEventListener('click', function(e) {
                const modal = document.getElementById('addProgramModal');
                if (modal && modal.classList.contains('flex')) {
                    const modalContent = modal.querySelector('div');
                    if (modalContent && !modalContent.contains(e.target)) {
                        closeAddProgramModal();
                    }
                }
            });

            // Add escape key to close modal
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('addProgramModal');
                    if (modal && modal.classList.contains('flex')) {
                        closeAddProgramModal();
                    }
                }
            });

            // Add keyboard navigation for search
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterPrograms();
                }
            });
        });
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>



