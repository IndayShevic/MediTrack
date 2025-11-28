<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

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

// Debug: Log ALL POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST RECEIVED ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("Current URL: " . $_SERVER['REQUEST_URI']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    error_log("Action detected: " . $action);
    
    if ($action === 'add') {
        // Sanitize input data - remove banned characters and prevent HTML/script injection
        function sanitizeInputBackend($value, $pattern = null) {
            if (empty($value)) return '';
            
            // Remove script tags and HTML tags (prevent XSS)
            $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', (string)$value);
            $sanitized = preg_replace('/<[^>]+>/', '', $sanitized);
            
            // Remove banned characters: !@#$%^&*()={}[]:;"<>?/\|~`_
            $banned = '/[!@#$%^&*()={}\[\]:;"<>?\/\\\|~`_]/';
            $sanitized = preg_replace($banned, '', $sanitized);
            
            // Remove control characters and emojis
            $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $sanitized);
            $sanitized = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $sanitized);
            
            // Trim leading/trailing spaces
            $sanitized = trim($sanitized);
            
            // Apply pattern if provided
            if ($pattern && $sanitized) {
                $sanitized = preg_replace('/[^' . $pattern . ']/', '', $sanitized);
            }
            
            return $sanitized;
        }
        
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'bhw';
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $purok_id = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : null;
        
        $errors = [];
        
        // Validate first name (letters only, no digits)
        if (empty($first) || strlen($first) < 2) {
            $errors[] = 'First name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $first)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $first = sanitizeInputBackend($first, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Validate last name (letters only, no digits)
        if (empty($last) || strlen($last) < 2) {
            $errors[] = 'Last name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $last)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $last = sanitizeInputBackend($last, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Suffix validation (only allowed values)
        if (!empty($suffix)) {
            $allowed_suffixes = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
            if (!in_array($suffix, $allowed_suffixes)) {
                $errors[] = 'Invalid suffix selected.';
            }
        }
        
        // Validate required fields
        if ($email === '' || $password === '') {
            $errors[] = 'Email and password are required.';
        }
        
        if (!empty($errors)) {
            set_flash(implode(' ', $errors), 'error');
            redirect_to('super_admin/users.php');
            exit;
        }
        
        if (!in_array($role, ['super_admin','bhw'], true)) {
            set_flash('Invalid role selected.', 'error');
            redirect_to('super_admin/users.php');
            exit;
        }
        
        // If BHW role, purok is required
        if ($role === 'bhw' && empty($purok_id)) {
            set_flash('Please select a Barangay and Purok for BHW staff.', 'error');
            redirect_to('super_admin/users.php');
            exit;
        }
        
        // For Super Admin, set purok_id to null
        if ($role === 'super_admin') {
            $purok_id = null;
        }
        
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = db()->prepare('INSERT INTO users(email, password_hash, role, first_name, last_name, suffix, purok_id) VALUES(?,?,?,?,?,?,?)');
        try {
            $result = $stmt->execute([$email, $hash, $role, $first, $last, $suffix, $purok_id]);
            error_log("Insert result: " . ($result ? 'success' : 'failed'));
            
            // Send welcome email with credentials
            $html = email_template(
                'Welcome to MediTrack',
                'Your account has been created successfully.',
                '<p>Hello <b>' . htmlspecialchars($first) . '</b>,</p>
                 <p>Your account role is <b>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $role))) . '</b>.</p>
                 <div style="background-color: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 0 0 10px 0;">Here are your login credentials:</p>
                    <p style="margin: 5px 0;"><b>Email:</b> ' . htmlspecialchars($email) . '</p>
                    <p style="margin: 5px 0;"><b>Password:</b> ' . htmlspecialchars($password) . '</p>
                 </div>
                 <p>You can now sign in to your dashboard.</p>',
                'Login to MediTrack',
                base_url('index.php#login')
            );
            send_email($email, trim($first . ' ' . $last), 'Welcome to MediTrack - Login Credentials', $html);
            set_flash('Staff added successfully. Credentials sent to email.', 'success');
        } catch (Throwable $e) {
            error_log("Error adding staff: " . $e->getMessage());
            set_flash('Error adding staff: ' . $e->getMessage(), 'error');
        }
        redirect_to('super_admin/users.php');
    } elseif ($action === 'edit') {
        // Sanitize input data - remove banned characters and prevent HTML/script injection
        function sanitizeInputBackend($value, $pattern = null) {
            if (empty($value)) return '';
            
            // Remove script tags and HTML tags (prevent XSS)
            $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', (string)$value);
            $sanitized = preg_replace('/<[^>]+>/', '', $sanitized);
            
            // Remove banned characters: !@#$%^&*()={}[]:;"<>?/\|~`_
            $banned = '/[!@#$%^&*()={}\[\]:;"<>?\/\\\|~`_]/';
            $sanitized = preg_replace($banned, '', $sanitized);
            
            // Remove control characters and emojis
            $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $sanitized);
            $sanitized = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $sanitized);
            
            // Trim leading/trailing spaces
            $sanitized = trim($sanitized);
            
            // Apply pattern if provided
            if ($pattern && $sanitized) {
                $sanitized = preg_replace('/[^' . $pattern . ']/', '', $sanitized);
            }
            
            return $sanitized;
        }
        
        $user_id = (int)($_POST['user_id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $purok_id = !empty($_POST['purok_id']) ? (int)$_POST['purok_id'] : null;
        
        $errors = [];
        
        // Validate first name (letters only, no digits)
        if (empty($first) || strlen($first) < 2) {
            $errors[] = 'First name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $first)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $first = sanitizeInputBackend($first, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Validate last name (letters only, no digits)
        if (empty($last) || strlen($last) < 2) {
            $errors[] = 'Last name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $last)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $last = sanitizeInputBackend($last, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Suffix validation (only allowed values)
        if (!empty($suffix)) {
            $allowed_suffixes = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
            if (!in_array($suffix, $allowed_suffixes)) {
                $errors[] = 'Invalid suffix selected.';
            }
        }
        
        if ($user_id > 0 && empty($errors)) {
            $stmt = db()->prepare('UPDATE users SET first_name = ?, last_name = ?, suffix = ?, purok_id = ? WHERE id = ?');
            try {
                $stmt->execute([$first, $last, $suffix, $purok_id, $user_id]);
                set_flash('User updated successfully.', 'success');
            } catch (Throwable $e) {
                set_flash('Error updating user.', 'error');
            }
        } elseif (!empty($errors)) {
            set_flash(implode(' ', $errors), 'error');
        }
        redirect_to('super_admin/users.php');
    }
}

error_log("Before setup_dashboard_ajax_capture - REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
$isAjax = setup_dashboard_ajax_capture();
error_log("After setup_dashboard_ajax_capture - isAjax: " . ($isAjax ? 'true' : 'false'));
error_log("Before redirect_to_dashboard_shell");
redirect_to_dashboard_shell($isAjax);
error_log("After redirect_to_dashboard_shell - this should not appear if redirected");

$users = db()->query("
    SELECT u.id, u.email, u.role, u.purok_id, u.first_name, u.last_name, u.suffix,
           CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,''), IF(u.suffix IS NOT NULL AND u.suffix != '', CONCAT(' ', u.suffix), '')) AS name, 
           u.created_at, p.name AS purok_name, b.name AS barangay_name,
           r.date_of_birth, r.id as resident_id
    FROM users u 
    LEFT JOIN puroks p ON u.purok_id = p.id 
    LEFT JOIN barangays b ON p.barangay_id = b.id 
    LEFT JOIN residents r ON r.user_id = u.id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch family members for resident users
$family_members_by_user = [];
foreach ($users as $user) {
    $user_id = (int)$user['id'];
    $family_members_by_user[$user_id] = []; // Initialize as empty
    
    // Check if user is a resident
    if (strtolower($user['role']) === 'resident') {
        // Get resident_id - try from query result first, then fetch directly if needed
        $resident_id = null;
        
        // Check if resident_id exists in query result (may be NULL from LEFT JOIN)
        if (isset($user['resident_id']) && $user['resident_id'] !== null && $user['resident_id'] !== '') {
            $resident_id = (int)$user['resident_id'];
        } else {
            // If not in query result, fetch it directly from residents table
            try {
                $resident_check = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
                $resident_check->execute([$user_id]);
                $resident_row = $resident_check->fetch();
                if ($resident_row && !empty($resident_row['id'])) {
                    $resident_id = (int)$resident_row['id'];
                }
            } catch (Exception $e) {
                // Ignore error, resident_id stays null
            }
        }
        
        // If we have a resident_id, fetch family members from family_members table
        if ($resident_id && $resident_id > 0) {
            try {
                $family_members_stmt = db()->prepare('
                    SELECT id, full_name, relationship, age, created_at
                    FROM family_members
                    WHERE resident_id = ?
                    ORDER BY relationship, full_name
                ');
                $family_members_stmt->execute([$resident_id]);
                $fetched_family = $family_members_stmt->fetchAll();
                if ($fetched_family && count($fetched_family) > 0) {
                    $family_members_by_user[$user_id] = $fetched_family;
                }
            } catch (Exception $e) {
                // Keep empty array on error
                $family_members_by_user[$user_id] = [];
            }
        }
    }
}

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
    SELECT p.id, p.name, p.barangay_id, b.name AS barangay_name 
    FROM puroks p 
    JOIN barangays b ON p.barangay_id = b.id 
    ORDER BY b.name, p.name
")->fetchAll();

$barangays = db()->query("SELECT * FROM barangays ORDER BY name")->fetchAll();

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
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .hover-lift {
            transition: all 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        /* Fix for select dropdowns in modals */
        #addUserModal select,
        #editModal select {
            position: relative;
            z-index: 10;
        }
        
        /* Ensure modal content doesn't clip dropdowns */
        #addUserModal .p-8,
        #editModal .p-8 {
            position: relative;
        }
        
        /* Custom scrollbar for modal */
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #555;
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

        <!-- Content -->
        <div class="content-body">
            <?php
            list($msg, $type) = get_flash();
            if ($msg): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $type === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?> flex items-center justify-between animate-fade-in-up">
                    <div class="flex items-center">
                        <?php if ($type === 'success'): ?>
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <?php else: ?>
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($msg); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-sm font-semibold hover:underline">Dismiss</button>
                </div>
            <?php endif; ?>
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
                        Add Staff
                    </button>
                </div>
            </div>

            <!-- Users Data Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto -webkit-overflow-scrolling-touch" style="-webkit-overflow-scrolling: touch;">
                    <table class="min-w-full divide-y divide-gray-200" style="min-width: 800px;">
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
                                            <div class="flex-1">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($u['name']); ?>
                                                </div>
                                                <?php 
                                                $user_id_check = (int)$u['id'];
                                                // Check if user is a resident
                                                if (strtolower($u['role']) === 'resident') {
                                                    $family_members_list = $family_members_by_user[$user_id_check] ?? [];
                                                    $family_count = count($family_members_list);
                                                    
                                                    // Show link if user has family members
                                                    if ($family_count > 0):
                                                ?>
                                                        <button onclick="toggleUserFamilyMembers(<?php echo $user_id_check; ?>)" 
                                                                class="mt-1 text-xs text-blue-600 hover:text-blue-800 flex items-center space-x-1 transition-colors cursor-pointer">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            </svg>
                                                            <span><?php echo $family_count; ?> family member<?php echo $family_count !== 1 ? 's' : ''; ?></span>
                                                            <svg id="user-family-arrow-<?php echo $user_id_check; ?>" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                            </svg>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php } ?>
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
                                        <button onclick="openEditModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['first_name'] ?? ''); ?>', '<?php echo htmlspecialchars($u['last_name'] ?? ''); ?>', '<?php echo htmlspecialchars($u['suffix'] ?? ''); ?>', <?php echo $u['purok_id'] ?: 'null'; ?>)" 
                                                class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                                
                                <!-- Family Members Row (only for resident users) -->
                                <?php 
                                $user_id = (int)$u['id'];
                                $family_members = $family_members_by_user[$user_id] ?? [];
                                if (strtolower($u['role']) === 'resident' && !empty($family_members)): 
                                ?>
                                    <tr id="family-row-<?php echo $user_id; ?>" class="hidden bg-gradient-to-br from-blue-50 to-indigo-50" style="max-height: 0; opacity: 0; overflow: hidden;">
                                        <td colspan="8" class="px-6 py-4">
                                            <div class="space-y-3">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-10 h-10 bg-blue-500 rounded-xl flex items-center justify-center">
                                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <h4 class="font-semibold text-gray-900">Family Members</h4>
                                                            <p class="text-sm text-gray-600"><?php echo count($family_members); ?> member<?php echo count($family_members) !== 1 ? 's' : ''; ?> registered</p>
                                                        </div>
                                                    </div>
                                                    <button onclick="toggleUserFamilyMembers(<?php echo $user_id; ?>)" 
                                                            class="text-gray-400 hover:text-gray-600 transition-colors">
                                                        <svg id="user-family-toggle-<?php echo $user_id; ?>" class="w-5 h-5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                                
                                                <div id="user-family-members-<?php echo $user_id; ?>" class="space-y-2">
                                                    <?php foreach ($family_members as $fm): ?>
                                                        <div class="bg-white rounded-lg p-4 border border-blue-200 shadow-sm hover:shadow-md transition-all">
                                                            <div class="flex items-center justify-between">
                                                                <div class="flex items-center space-x-4 flex-1">
                                                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-xl flex items-center justify-center flex-shrink-0">
                                                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                                        </svg>
                                                                    </div>
                                                                    <div class="flex-1 min-w-0">
                                                                        <div class="flex items-center space-x-2 mb-1">
                                                                            <h5 class="font-semibold text-gray-900"><?php echo htmlspecialchars($fm['full_name']); ?></h5>
                                                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                                                Family Member
                                                                            </span>
                                                                        </div>
                                                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                                                            <span class="flex items-center space-x-1">
                                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                                                                </svg>
                                                                                <span><?php echo htmlspecialchars($fm['relationship']); ?></span>
                                                                            </span>
                                                                            <span class="flex items-center space-x-1">
                                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                                                </svg>
                                                                                <span>Age: <?php echo (int)$fm['age']; ?></span>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
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
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 transform transition-all scale-100" style="max-height: 90vh; display: flex; flex-direction: column; animation: modalSlideIn 0.3s ease-out;">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-green-600 to-emerald-700 p-6 text-white flex-shrink-0 rounded-t-2xl">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center shadow-inner">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white tracking-tight">Edit User Information</h3>
                            <p class="text-green-100 text-sm mt-0.5">Update user details and assignments</p>
                        </div>
                    </div>
                    <button onclick="closeEditModal()" class="text-white/70 hover:text-white transition-colors p-2 rounded-full hover:bg-white/10">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Modal Body -->
            <div class="p-8 overflow-y-auto custom-scrollbar" style="overflow-x: visible;">
                <form id="editForm" method="post" action="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="space-y-6">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <!-- Personal Information Section -->
                    <div class="bg-gradient-to-br from-gray-50 to-green-50 rounded-xl p-5 border border-gray-100">
                        <div class="flex items-center space-x-2 mb-4">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            <h4 class="font-semibold text-gray-800">Personal Information</h4>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700 flex items-center">
                                    <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="first_name" id="edit_first_name" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-200 bg-white shadow-sm hover:shadow-md" 
                                       placeholder="Enter first name" required />
                            </div>
                        
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700 flex items-center">
                                    <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="last_name" id="edit_last_name" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-200 bg-white shadow-sm hover:shadow-md" 
                                       placeholder="Enter last name" required />
                            </div>

                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700 flex items-center">
                                    <svg class="w-4 h-4 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                    </svg>
                                    Suffix <span class="text-gray-400 font-normal text-xs">(Optional)</span>
                                </label>
                                <div class="relative">
                                    <select name="suffix" id="edit_suffix" 
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-200 bg-white shadow-sm hover:shadow-md appearance-none" style="padding-right: 3.5rem !important;">
                                        <option value="">Select suffix (optional)</option>
                                        <option value="Jr.">Jr. (Junior)</option>
                                        <option value="Sr.">Sr. (Senior)</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                        <option value="IV">IV</option>
                                        <option value="V">V</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assignment Section -->
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-100">
                        <div class="flex items-center space-x-2 mb-4">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <h4 class="font-semibold text-gray-800">Location Assignment</h4>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Assigned Purok</label>
                            <select name="purok_id" id="edit_purok_id" 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white shadow-sm hover:shadow-md">
                                <option value="">Select Purok (optional)</option>
                                <?php foreach ($puroks as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['barangay_name'] . ' - ' . $p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1.5 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Only required for BHW staff members
                            </p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button type="button" onclick="closeEditModal()" 
                                class="inline-flex items-center px-6 py-3 border-2 border-gray-300 text-gray-700 font-semibold rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-200 shadow-sm hover:shadow-md">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Cancel
                        </button>
                        <button type="submit" 
                                class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-700 text-white font-semibold rounded-xl hover:from-green-700 hover:to-emerald-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
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

    <!-- Add Staff Modal -->
    <div id="addUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl width-full mx-4 transform transition-all scale-100" style="width: 100%; max-height: 90vh; display: flex; flex-direction: column;">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-purple-600 to-indigo-700 p-6 text-white flex-shrink-0">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center shadow-inner">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white tracking-tight">Add New Staff</h3>
                            <p class="text-purple-100 text-sm">Create a new staff account with credentials</p>
                        </div>
                    </div>
                    <button onclick="closeAddUserModal()" class="text-white/70 hover:text-white transition-colors p-2 rounded-full hover:bg-white/10">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Modal Body -->
            <div class="p-8 overflow-y-auto custom-scrollbar" style="overflow-x: visible;">
                <form action="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" method="post" id="addStaffForm" class="space-y-6">
                    <input type="hidden" name="action" value="add" />
                    
                    <!-- Account Credentials Section -->
                    <div class="bg-purple-50 rounded-xl p-5 border border-purple-100">
                        <div class="flex items-center space-x-2 mb-4">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                            </svg>
                            <h4 class="font-semibold text-gray-800">Account Credentials</h4>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Email Address <span class="text-red-500">*</span></label>
                                <input name="email" type="email" required 
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 bg-white" 
                                       placeholder="name@example.com" />
                            </div>
                            
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Password <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input name="password" type="text" required 
                                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 bg-white" 
                                           placeholder="Strong password" />
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personal Information Section -->
                    <div>
                        <h4 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4 border-b pb-2">Personal Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">First Name <span class="text-red-500">*</span></label>
                                <input name="first_name" required
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200" 
                                       placeholder="First name" />
                            </div>
                            
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Last Name <span class="text-red-500">*</span></label>
                                <input name="last_name" required
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200" 
                                       placeholder="Last name" />
                            </div>

                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Suffix <span class="text-gray-400 font-normal text-xs">(Optional)</span></label>
                                <select name="suffix" 
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 appearance-none" style="padding-right: 3.5rem !important;">
                                    <option value="">Suffix (optional)</option>
                                    <option value="Jr.">Jr. (Junior)</option>
                                    <option value="Sr.">Sr. (Senior)</option>
                                    <option value="II">II</option>
                                    <option value="III">III</option>
                                    <option value="IV">IV</option>
                                    <option value="V">V</option>
                                </select>
                                <div class="relative -mt-10 pointer-events-none flex items-center justify-end pr-3">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Role <span class="text-red-500">*</span></label>
                            <select name="role" id="roleSelect"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 bg-white">
                                <option value="bhw">Barangay Health Worker (BHW)</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                        </div>
                        
                        <div id="purok-group" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Barangay <span class="text-red-500">*</span></label>
                                <select id="barangaySelect"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 bg-white">
                                    <option value="">Select Barangay</option>
                                    <?php foreach ($barangays as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-gray-700">Purok <span class="text-red-500">*</span></label>
                                <select name="purok_id" id="purokSelect" disabled
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 bg-gray-50 cursor-not-allowed">
                                    <option value="">Select Purok</option>
                                    <?php foreach ($puroks as $p): ?>
                                        <option value="<?php echo (int)$p['id']; ?>" data-barangay-id="<?php echo (int)$p['barangay_id']; ?>">
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 flex justify-end space-x-3 border-t border-gray-100 mt-6">
                        <button type="button" onclick="closeAddUserModal()" class="px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-700 text-white font-semibold rounded-lg hover:from-purple-700 hover:to-indigo-800 transition-all duration-300 shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                            Add Staff Member
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
                // Reset form
                document.getElementById('addStaffForm').reset();
            }
        }
        
        function submitAddStaffForm() {
            const form = document.getElementById('addStaffForm');
            const formData = new FormData(form);
            
            // Use fetch to submit as POST
            fetch('/thesis/public/super_admin/users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Redirect to users page to see the result
                window.location.href = '/thesis/public/super_admin/users.php';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding staff. Please try again.');
            });
        }

        // Make functions globally available
        window.openEditModal = function(userId, firstName, lastName, suffix, purokId) {
            // Close any other open modals first
            if (typeof closeAddUserModal === 'function') {
                closeAddUserModal();
            }
            
            // Set form values
            const editUserId = document.getElementById('edit_user_id');
            const editFirstName = document.getElementById('edit_first_name');
            const editLastName = document.getElementById('edit_last_name');
            const editSuffix = document.getElementById('edit_suffix');
            const editPurokId = document.getElementById('edit_purok_id');
            
            if (editUserId) editUserId.value = userId || '';
            if (editFirstName) editFirstName.value = firstName || '';
            if (editLastName) editLastName.value = lastName || '';
            if (editSuffix) editSuffix.value = suffix || '';
            if (editPurokId) {
                // Handle null or empty purokId
                const purokValue = (purokId && purokId !== 'null' && purokId !== '') ? purokId : '';
                editPurokId.value = purokValue;
            }
            
            // Show modal
            const modal = document.getElementById('editModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '1';
            }
        };
        
        window.closeEditModal = function() {
            const modal = document.getElementById('editModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                modal.style.opacity = '0';
            }
        };
        
        // Toggle family members row for a user
        function toggleUserFamilyMembers(userId) {
            const familyRow = document.getElementById('family-row-' + userId);
            const toggleIcon = document.getElementById('user-family-toggle-' + userId);
            const arrowIcon = document.getElementById('user-family-arrow-' + userId);
            
            if (!familyRow) return;
            
            const isHidden = familyRow.classList.contains('hidden');
            
            if (isHidden) {
                // Show family members
                familyRow.classList.remove('hidden');
                familyRow.style.overflow = 'visible';
                familyRow.style.maxHeight = '0';
                familyRow.style.opacity = '0';
                
                // Animate in
                requestAnimationFrame(() => {
                    familyRow.style.transition = 'max-height 0.4s ease-out, opacity 0.4s ease-out';
                    const contentHeight = familyRow.querySelector('td').scrollHeight;
                    familyRow.style.maxHeight = contentHeight + 'px';
                    familyRow.style.opacity = '1';
                });
                
                // Rotate icons
                if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
                if (arrowIcon) arrowIcon.style.transform = 'rotate(180deg)';
            } else {
                // Hide family members
                familyRow.style.transition = 'max-height 0.3s ease-in, opacity 0.3s ease-in';
                familyRow.style.maxHeight = '0';
                familyRow.style.opacity = '0';
                familyRow.style.overflow = 'hidden';
                
                setTimeout(() => {
                    familyRow.classList.add('hidden');
                }, 300);
                
                // Rotate icons back
                if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
                if (arrowIcon) arrowIcon.style.transform = 'rotate(0deg)';
            }
        }
        
        
        // Optimized filter puroks based on selected barangay
        function filterPuroks() {
            const barangaySelect = document.getElementById('barangaySelect');
            const purokSelect = document.getElementById('purokSelect');
            
            if (!barangaySelect || !purokSelect) return;
            
            const selectedBarangayId = barangaySelect.value;
            
            // Reset purok selection
            purokSelect.value = '';
            
            if (!selectedBarangayId) {
                purokSelect.disabled = true;
                purokSelect.classList.add('bg-gray-50', 'cursor-not-allowed');
                purokSelect.classList.remove('bg-white');
                return;
            }
            
            // Enable purok select immediately for better UX
            purokSelect.disabled = false;
            purokSelect.classList.remove('bg-gray-50', 'cursor-not-allowed');
            purokSelect.classList.add('bg-white');
            
            // Filter options using hidden attribute for better performance
            const options = purokSelect.querySelectorAll('option');
            
            options.forEach(option => {
                if (option.value === '') {
                    option.hidden = false; // Always show placeholder
                    return;
                }
                
                const optionBarangayId = option.getAttribute('data-barangay-id');
                option.hidden = (optionBarangayId !== selectedBarangayId);
            });
        }

        // Show/hide purok field based on role and handle required attribute
        const roleSelect = document.querySelector('select[name="role"]');
        if (roleSelect) {
            roleSelect.addEventListener('change', function() {
                const purokGroup = document.getElementById('purok-group');
                const purokSelect = document.querySelector('select[name="purok_id"]');
                const barangaySelect = document.getElementById('barangaySelect');
                
                if (!purokGroup || !purokSelect || !barangaySelect) return;
                
                if (this.value === 'bhw') {
                    purokGroup.style.display = 'grid';
                    purokSelect.setAttribute('required', 'required');
                    barangaySelect.setAttribute('required', 'required');
                } else {
                    purokGroup.style.display = 'none';
                    purokSelect.removeAttribute('required');
                    barangaySelect.removeAttribute('required');
                    purokSelect.value = ''; // Clear selection
                    barangaySelect.value = ''; // Clear selection
                    // Reset purok state
                    purokSelect.disabled = true;
                    purokSelect.classList.add('bg-gray-50', 'cursor-not-allowed');
                    purokSelect.classList.remove('bg-white');
                }
            });
        }
        
        // Add event listener to barangay select
        const barangaySelect = document.getElementById('barangaySelect');
        if (barangaySelect) {
            barangaySelect.addEventListener('change', filterPuroks);
        }
        
        // Real-time input filtering to prevent invalid characters
        function filterInput(input, pattern, maxLength = null) {
            const originalValue = input.value;
            // Remove invalid characters based on pattern
            let filtered = originalValue.replace(new RegExp('[^' + pattern + ']', 'g'), '');
            
            // Apply max length if specified
            if (maxLength && filtered.length > maxLength) {
                filtered = filtered.substring(0, maxLength);
            }
            
            // Update value if it changed
            if (filtered !== originalValue) {
                const cursorPos = input.selectionStart;
                input.value = filtered;
                // Restore cursor position (adjust for removed characters)
                const newPos = Math.min(cursorPos - (originalValue.length - filtered.length), filtered.length);
                input.setSelectionRange(newPos, newPos);
            }
        }
        
        // Setup input filtering for name fields
        function setupNameFieldValidation(input) {
            if (!input) return;
            
            // First Name & Last Name: Only letters, spaces, hyphens, apostrophes
            input.addEventListener('input', function(e) {
                filterInput(this, 'A-Za-zÀ-ÿ\\s\\-\'');
            });
            
            input.addEventListener('keypress', function(e) {
                // Allow: letters, space, hyphen, apostrophe, backspace, delete, tab, arrow keys
                const char = String.fromCharCode(e.which || e.keyCode);
                if (!/[A-Za-zÀ-ÿ\s\-\']/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                    e.preventDefault();
                }
            });
            
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const filtered = pastedText.replace(/[^A-Za-zÀ-ÿ\s\-\']/g, '');
                const cursorPos = this.selectionStart;
                const textBefore = this.value.substring(0, cursorPos);
                const textAfter = this.value.substring(this.selectionEnd);
                this.value = textBefore + filtered + textAfter;
                const newPos = cursorPos + filtered.length;
                this.setSelectionRange(newPos, newPos);
            });
        }
        
        // Initialize purok field visibility
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.querySelector('select[name="role"]');
            const purokGroup = document.getElementById('purok-group');
            const purokSelect = document.querySelector('select[name="purok_id"]');
            const barangaySelect = document.getElementById('barangaySelect');
            
            if (roleSelect && purokGroup && purokSelect && barangaySelect) {
                if (roleSelect.value === 'bhw') {
                    purokGroup.style.display = 'grid';
                    purokSelect.setAttribute('required', 'required');
                    barangaySelect.setAttribute('required', 'required');
                } else {
                    purokGroup.style.display = 'none';
                    purokSelect.removeAttribute('required');
                    barangaySelect.removeAttribute('required');
                }
            }
            
            // Setup validation for add form fields
            const addFirstName = document.querySelector('#addUserModal input[name="first_name"]');
            const addLastName = document.querySelector('#addUserModal input[name="last_name"]');
            if (addFirstName) setupNameFieldValidation(addFirstName);
            if (addLastName) setupNameFieldValidation(addLastName);
            
            // Setup validation for edit form fields
            const editFirstName = document.getElementById('edit_first_name');
            const editLastName = document.getElementById('edit_last_name');
            if (editFirstName) setupNameFieldValidation(editFirstName);
            if (editLastName) setupNameFieldValidation(editLastName);

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
                
                if (!modal) {
                    console.error('Activity modal not found');
                    return;
                }
                
                if (!loading || !content || !empty || !list || !title || !subtitle) {
                    console.error('Activity modal elements not found');
                    return;
                }
                
                // Show modal and loading state
                modal.style.display = 'flex';
                loading.classList.remove('hidden');
                content.classList.add('hidden');
                empty.classList.add('hidden');
                list.innerHTML = '';
                
                try {
                    const activityUrl = '<?php echo base_url("super_admin/get_user_activity.php"); ?>?user_id=' + userId;
                    const response = await fetch(activityUrl);
                    
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
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
                    console.error('Error loading user activity:', error);
                    loading.classList.add('hidden');
                    Swal.fire({
                        title: 'Error',
                        text: 'Failed to load user activity. Please try again.',
                        icon: 'error',
                        confirmButtonColor: '#dc2626'
                    });
                    // Don't close modal on error, just show the error state
                    if (empty) {
                        empty.classList.remove('hidden');
                        empty.innerHTML = '<p class="text-gray-500 text-center">Failed to load activity data. Please try again.</p>';
                    }
                }
            };

            window.closeActivityModal = function() {
                const modal = document.getElementById('activityModal');
                if (modal) {
                    modal.style.display = 'none';
                    modal.style.visibility = 'hidden';
                    modal.style.opacity = '0';
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



