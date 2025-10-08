<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['resident']);

$user = current_user();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        if ($first_name && $last_name && $email) {
            try {
                // Check if email is already taken by another user
                $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetch()) {
                    set_flash('Email address is already taken by another user.', 'error');
                } else {
                    // Update user profile
                    $stmt = db()->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?');
                    $stmt->execute([$first_name, $last_name, $email, $user['id']]);
                    
                    // Update resident details
                    $stmt = db()->prepare('UPDATE residents SET phone = ?, address = ? WHERE user_id = ?');
                    $stmt->execute([$phone, $address, $user['id']]);
                    
                    // Update session
                    $_SESSION['user']['first_name'] = $first_name;
                    $_SESSION['user']['last_name'] = $last_name;
                    $_SESSION['user']['name'] = $first_name . ' ' . $last_name;
                    $_SESSION['user']['email'] = $email;
                    
                    set_flash('Profile updated successfully!', 'success');
                }
            } catch (Throwable $e) {
                set_flash('Failed to update profile. Please try again.', 'error');
            }
        } else {
            set_flash('Please fill in all required fields.', 'error');
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if ($current_password && $new_password && $confirm_password) {
            if ($new_password !== $confirm_password) {
                set_flash('New passwords do not match.', 'error');
            } elseif (strlen($new_password) < 6) {
                set_flash('New password must be at least 6 characters long.', 'error');
            } else {
                try {
                    // Verify current password
                    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
                    $stmt->execute([$user['id']]);
                    $user_data = $stmt->fetch();
                    
                    if (password_verify($current_password, $user_data['password_hash'])) {
                        // Update password
                        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $stmt->execute([$new_hash, $user['id']]);
                        
                        set_flash('Password changed successfully!', 'success');
                    } else {
                        set_flash('Current password is incorrect.', 'error');
                    }
                } catch (Throwable $e) {
                    set_flash('Failed to change password. Please try again.', 'error');
                }
            }
        } else {
            set_flash('Please fill in all password fields.', 'error');
        }
    } elseif ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                set_flash('Please upload a valid image file (JPEG, PNG, GIF, or WebP).', 'error');
            } elseif ($file['size'] > $max_size) {
                set_flash('Image file is too large. Maximum size is 5MB.', 'error');
            } else {
                try {
                    // Create profiles directory if it doesn't exist
                    $upload_dir = __DIR__ . '/../../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $user['id'] . '_' . time() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        // Delete old profile image if exists
                        if ($user['profile_image']) {
                            $old_file = __DIR__ . '/../../uploads/profiles/' . basename($user['profile_image']);
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                        
                        // Update database
                        $relative_path = 'uploads/profiles/' . $filename;
                        $stmt = db()->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
                        $stmt->execute([$relative_path, $user['id']]);
                        
                        // Update session
                        $_SESSION['user']['profile_image'] = $relative_path;
                        
                        set_flash('Profile image updated successfully!', 'success');
                    } else {
                        set_flash('Failed to upload image. Please try again.', 'error');
                    }
                } catch (Throwable $e) {
                    set_flash('Failed to upload image. Please try again.', 'error');
                }
            }
        } else {
            set_flash('Please select a valid image file.', 'error');
        }
    }
    
    redirect_to('resident/profile.php');
}

// Get updated user data with resident details
$stmt = db()->prepare('
    SELECT u.*, r.phone, r.address, r.date_of_birth, 
           b.name as barangay_name, p.name as purok_name
    FROM users u 
    LEFT JOIN residents r ON r.user_id = u.id 
    LEFT JOIN barangays b ON b.id = r.barangay_id 
    LEFT JOIN puroks p ON p.id = r.purok_id 
    WHERE u.id = ?
');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Profile - Resident Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        'primary': {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .fade-in {
            animation: fadeIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .profile-avatar {
            width: 140px;
            height: 140px;
            object-fit: cover;
            border: 6px solid white;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }
        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .input-focus {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .input-focus:focus {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(34, 197, 94, 0.15);
        }
        .btn-primary {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(34, 197, 94, 0.4);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #374151 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(107, 114, 128, 0.4);
        }
        .status-badge {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.875rem;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        .form-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .image-upload-area {
            border: 2px dashed #cbd5e1;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        .image-upload-area:hover {
            border-color: #22c55e;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        }
        .image-upload-area.dragover {
            border-color: #22c55e;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            transform: scale(1.02);
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
        }
        .section-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
    </button>
    
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" onclick="closeMobileMenu()"></div>
    
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
            <a href="<?php echo htmlspecialchars(base_url('resident/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                My Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/request_new.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                New Request
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Browse Medicines
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
            </a>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="text-red-600 hover:text-red-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div class="fade-in">
                    <div class="flex items-center space-x-3 mb-2">
                        <div class="p-2 bg-primary-100 rounded-lg">
                            <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <h1 class="text-3xl font-bold text-gray-900">Profile Settings</h1>
                    </div>
                    <p class="text-gray-600 ml-11">Manage your account information and preferences.</p>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php [$flash, $flashType] = get_flash(); if ($flash): ?>
            <div class="mb-6 fade-in">
                <div class="px-4 py-4 rounded-xl border-l-4 <?php echo $flashType === 'success' ? 'bg-green-50 text-green-800 border-green-400' : 'bg-red-50 text-red-800 border-red-400'; ?> shadow-sm">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <?php if ($flashType === 'success'): ?>
                                <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($flash); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Content -->
        <div class="content-body">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Profile Overview -->
                <div class="lg:col-span-1">
                    <div class="card card-hover fade-in glass-effect">
                        <div class="card-body text-center p-8">
                            <div class="relative inline-block mb-6">
                                <div class="relative group">
                                    <?php if ($user_data['profile_image']): ?>
                                        <img src="<?php echo htmlspecialchars(base_url($user_data['profile_image'])); ?>" 
                                             alt="Profile" class="profile-avatar rounded-full mx-auto">
                                    <?php else: ?>
                                        <div class="profile-avatar rounded-full mx-auto bg-gradient-to-br from-green-100 to-green-200 flex items-center justify-center">
                                            <svg class="w-16 h-16 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                    <div class="absolute -bottom-2 -right-2 w-8 h-8 bg-green-500 rounded-full border-4 border-white flex items-center justify-center">
                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h3>
                            <p class="text-gray-600 mb-4 text-lg"><?php echo htmlspecialchars($user_data['email']); ?></p>
                            <div class="status-badge inline-block mb-6">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    <span>Resident</span>
                                </div>
                            </div>
                            <div class="space-y-3 text-sm text-gray-600">
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    <span>Member since <?php echo date('M Y', strtotime($user_data['created_at'])); ?></span>
                                </div>
                                <?php if ($user_data['barangay_name']): ?>
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    <span><?php echo htmlspecialchars($user_data['barangay_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($user_data['purok_name']): ?>
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                    <span>Purok <?php echo htmlspecialchars($user_data['purok_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span>Last active: Today</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Forms -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Profile Information -->
                    <div class="card card-hover fade-in form-section">
                        <div class="card-body p-8">
                            <div class="flex items-center space-x-3 mb-8">
                                <div class="p-3 bg-green-100 rounded-xl">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-2xl font-bold section-header">Profile Information</h3>
                            </div>
                            <form method="post" class="space-y-6">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                </svg>
                                                <span>First Name</span>
                                            </div>
                                        </label>
                                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                </svg>
                                                <span>Last Name</span>
                                            </div>
                                        </label>
                                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                            </svg>
                                            <span>Email Address</span>
                                        </div>
                                    </label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" 
                                           class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                                </svg>
                                                <span>Phone Number</span>
                                            </div>
                                        </label>
                                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm">
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                                <span>Date of Birth</span>
                                            </div>
                                        </label>
                                        <input type="date" value="<?php echo htmlspecialchars($user_data['date_of_birth'] ?? ''); ?>" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl bg-gray-100 cursor-not-allowed" disabled>
                                        <p class="text-xs text-gray-500 mt-1">Date of birth cannot be changed</p>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            <span>Address</span>
                                        </div>
                                    </label>
                                    <textarea name="address" rows="3" 
                                              class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm"><?php echo htmlspecialchars($user_data['address'] ?? ''); ?></textarea>
                                </div>
                                <div class="flex justify-end pt-4">
                                    <button type="submit" class="btn-primary px-8 py-4 text-white font-semibold rounded-xl shadow-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            <span>Update Profile</span>
                                        </div>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Profile Image -->
                    <div class="card card-hover fade-in form-section">
                        <div class="card-body p-8">
                            <div class="flex items-center space-x-3 mb-8">
                                <div class="p-3 bg-green-100 rounded-xl">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-2xl font-bold section-header">Profile Image</h3>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="space-y-6">
                                <input type="hidden" name="action" value="upload_avatar">
                                <div class="flex items-start space-x-8">
                                    <div class="flex-shrink-0">
                                        <div class="relative group">
                                            <?php if ($user_data['profile_image']): ?>
                                                <img src="<?php echo htmlspecialchars(base_url($user_data['profile_image'])); ?>" 
                                                     alt="Current Avatar" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-lg">
                                            <?php else: ?>
                                                <div class="w-24 h-24 rounded-full bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center border-4 border-white shadow-lg">
                                                    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <div class="absolute -bottom-1 -right-1 w-8 h-8 bg-green-500 rounded-full border-4 border-white flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <div class="image-upload-area rounded-xl p-6 text-center" id="imageUploadArea">
                                            <div class="space-y-4">
                                                <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h4 class="text-lg font-semibold text-gray-900 mb-2">Upload New Image</h4>
                                                    <p class="text-sm text-gray-600 mb-4">Drag and drop your image here, or click to browse</p>
                                                    <input type="file" name="avatar" accept="image/*" id="avatarInput" 
                                                           class="hidden">
                                                    <button type="button" onclick="document.getElementById('avatarInput').click()" 
                                                            class="btn-primary px-6 py-3 text-white font-semibold rounded-xl">
                                                        Choose File
                                                    </button>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <p>Max size: 5MB</p>
                                                    <p>Supported: JPEG, PNG, GIF, WebP</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="imagePreview" class="hidden mt-4">
                                            <img id="previewImg" class="w-32 h-32 rounded-xl object-cover border-2 border-green-200" alt="Preview">
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-4">
                                    <button type="submit" class="btn-primary px-8 py-4 text-white font-semibold rounded-xl shadow-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span>Upload Image</span>
                                        </div>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="card card-hover fade-in form-section">
                        <div class="card-body p-8">
                            <div class="flex items-center space-x-3 mb-8">
                                <div class="p-3 bg-green-100 rounded-xl">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-2xl font-bold section-header">Change Password</h3>
                            </div>
                            <form method="post" class="space-y-6">
                                <input type="hidden" name="action" value="change_password">
                                <div class="space-y-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                            <span>Current Password</span>
                                        </div>
                                    </label>
                                    <input type="password" name="current_password" 
                                           class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                </svg>
                                                <span>New Password</span>
                                            </div>
                                        </label>
                                        <input type="password" name="new_password" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>Confirm New Password</span>
                                            </div>
                                        </label>
                                        <input type="password" name="confirm_password" 
                                               class="w-full px-4 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 input-focus bg-white shadow-sm" required>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-4">
                                    <button type="submit" class="btn-primary px-8 py-4 text-white font-semibold rounded-xl shadow-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                            <span>Change Password</span>
                                        </div>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript for Enhanced UI -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Image upload and preview functionality
            const avatarInput = document.getElementById('avatarInput');
            const imageUploadArea = document.getElementById('imageUploadArea');
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');

            // File input change handler
            avatarInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImg.src = e.target.result;
                        imagePreview.classList.remove('hidden');
                        imageUploadArea.classList.add('hidden');
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Drag and drop functionality
            imageUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                imageUploadArea.classList.add('dragover');
            });

            imageUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                imageUploadArea.classList.remove('dragover');
            });

            imageUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                imageUploadArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (file.type.startsWith('image/')) {
                        avatarInput.files = files;
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewImg.src = e.target.result;
                            imagePreview.classList.remove('hidden');
                            imageUploadArea.classList.add('hidden');
                        };
                        reader.readAsDataURL(file);
                    }
                }
            });

            // Click to upload
            imageUploadArea.addEventListener('click', function() {
                avatarInput.click();
            });

            // Form validation and feedback
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = `
                            <div class="flex items-center space-x-2">
                                <svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Processing...</span>
                            </div>
                        `;
                        
                        // Re-enable button after 3 seconds (in case of errors)
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 3000);
                    }
                });
            });

            // Password confirmation validation
            const newPasswordInput = document.querySelector('input[name="new_password"]');
            const confirmPasswordInput = document.querySelector('input[name="confirm_password"]');
            
            if (newPasswordInput && confirmPasswordInput) {
                function validatePasswords() {
                    if (newPasswordInput.value && confirmPasswordInput.value) {
                        if (newPasswordInput.value !== confirmPasswordInput.value) {
                            confirmPasswordInput.setCustomValidity('Passwords do not match');
                            confirmPasswordInput.classList.add('border-red-500');
                        } else {
                            confirmPasswordInput.setCustomValidity('');
                            confirmPasswordInput.classList.remove('border-red-500');
                            confirmPasswordInput.classList.add('border-green-500');
                        }
                    }
                }
                
                newPasswordInput.addEventListener('input', validatePasswords);
                confirmPasswordInput.addEventListener('input', validatePasswords);
            }

            // Add smooth scrolling for better UX
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

            // Add loading states to buttons
            document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (!this.disabled) {
                        this.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            this.style.transform = 'scale(1)';
                        }, 150);
                    }
                });
            });
        });
    </script>
</body>
</html>


