<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';

// Helper function to get upload URL (uploads are at project root, not in public/)
function upload_url(string $path): string {
    // Remove leading slash if present
    $clean_path = ltrim($path, '/');
    
    // Get base path without /public/
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
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
        
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        $errors = [];
        
        // Validate first name (letters only, no digits)
        if (empty($first_name) || strlen($first_name) < 2) {
            $errors[] = 'First name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $first_name)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first_name)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $first_name = sanitizeInputBackend($first_name, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Validate last name (letters only, no digits)
        if (empty($last_name) || strlen($last_name) < 2) {
            $errors[] = 'Last name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $last_name)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last_name)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $last_name = sanitizeInputBackend($last_name, 'A-Za-zÀ-ÿ\' -');
        }
        
        if ($first_name && $last_name && $email && empty($errors)) {
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
        } elseif (!empty($errors)) {
            set_flash(implode(' ', $errors), 'error');
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
    
    // Redirect to prevent form resubmission
    redirect_to('super_admin/profile.php');
}

// Get updated user data (after form processing)
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Fetch notification data (similar to dashboardnew.php)
try {
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "submitted"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
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

$total_notifications = $pending_requests + $alerts_count;

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Profile - Super Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com" onerror="console.error('Tailwind CSS failed to load')"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onerror="console.error('Font Awesome failed to load')">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
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
        .sidebar-link {
            transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
        }
        .sidebar-link:hover:not(.active) {
            background: linear-gradient(135deg, #e5e7eb 0%, #e5e7eb 100%);
            color: #4b5563;
        }
        .sidebar-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
        }
        /* Sidebar styles */
        .sidebar {
            width: 16rem; /* w-64 */
        }
        
        /* Prevent white screen - ensure content is always visible */
        body {
            min-height: 100vh;
            background-color: #f9fafb !important;
        }
        #app {
            min-height: 100vh;
            display: flex !important;
            height: auto;
            overflow: visible;
        }
        
        /* Ensure main content can scroll */
        #main-content-area {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            height: auto;
            min-height: calc(100vh - 4rem);
            max-height: none;
        }
        
        #dashboard-main-wrapper {
            height: auto;
            min-height: 100vh;
            overflow: visible;
        }
        
        /* On desktop, allow proper scrolling */
        @media (min-width: 1025px) {
            #app {
                height: 100vh;
                overflow: hidden;
            }
            
            #dashboard-main-wrapper {
                height: 100vh;
                overflow: hidden;
            }
            
            #main-content-area {
                overflow-y: auto;
                height: calc(100vh - 4rem);
                max-height: calc(100vh - 4rem);
            }
        }
        
        /* On mobile and tablet, allow full page scroll */
        @media (max-width: 1024px) {
            #app {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }
            
            #dashboard-main-wrapper {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }
            
            #main-content-area {
                overflow-y: visible;
                height: auto;
                min-height: calc(100vh - 4rem);
                max-height: none;
            }
            
            /* Ensure body can scroll on mobile */
            body {
                overflow-x: hidden;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
        }

        /* Dashboard shell layout: offset main content by sidebar width on desktop
           without affecting other pages that use the shared design system */
        .dashboard-shell #dashboard-main-wrapper {
            margin-left: 0;
            transition: margin-left 0.3s ease;
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
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-overlay.show {
            display: block;
        }
        
        /* Container max-widths for readability */
        .container-responsive {
            width: 100%;
            max-width: 100%;
        }
        
        /* Inner content container for max-width on desktop */
        .content-container {
            width: 100%;
            margin: 0 auto;
        }
        
        /* Notification and Profile Dropdowns */
        #notificationDropdown,
        #profileDropdown {
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
        
        /* Ensure dropdowns are above other content */
        #notificationDropdown,
        #profileDropdown {
            z-index: 1000;
        }
        
        /* Mobile responsive dropdowns */
        @media (max-width: 640px) {
            #notificationDropdown {
                right: 0;
                left: auto;
                width: calc(100vw - 2rem);
                max-width: 20rem;
            }
            
            #profileDropdown {
                right: 0;
                left: auto;
                width: calc(100vw - 2rem);
                max-width: 16rem;
            }
        }
        
        /* Desktop sidebar visibility - match Tailwind md: breakpoint (768px) */
        @media (min-width: 768px) {
            /* Sidebar - always visible on desktop */
            #sidebar-aside.hidden {
                display: flex !important;
            }
            #sidebar-aside {
                position: relative !important;
                left: 0 !important;
            }
            
            .sidebar-overlay {
                display: none !important;
            }
            
            /* Ensure main content is offset by sidebar width */
            .dashboard-shell #dashboard-main-wrapper {
                margin-left: 16rem !important; /* Sidebar width (w-64 = 16rem) */
                width: calc(100% - 16rem) !important;
                max-width: calc(100% - 16rem) !important;
            }
            
            /* Ensure header and main content are properly positioned */
            .dashboard-shell #dashboard-main-wrapper header,
            .dashboard-shell #dashboard-main-wrapper main {
                width: 100% !important;
                padding-left: 1.5rem !important;
                padding-right: 1.5rem !important;
            }
            
            /* Ensure main content area has proper spacing */
            .dashboard-shell #main-content-area {
                padding-left: 1.5rem !important;
                padding-right: 1.5rem !important;
            }
        }
        
        /* Mobile sidebar */
        @media (max-width: 767px) {
            #sidebar-aside {
                display: none !important;
                position: fixed !important;
                left: -16rem !important;
                z-index: 50 !important;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
                height: 100vh !important;
                top: 0 !important;
            }
            
            #sidebar-aside.show {
                left: 0 !important;
                display: flex !important;
                visibility: visible !important;
            }
            
            .dashboard-shell #dashboard-main-wrapper {
                margin-left: 0;
            }
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
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.15);
        }
        .btn-primary {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.4);
        }
        
        /* Profile dropdown styles */
        #profile-dropdown {
            position: relative;
        }
        
        #profile-menu {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.5rem;
            z-index: 9999;
        }
        
        #profile-toggle {
            cursor: pointer;
            user-select: none;
        }
        
        #profile-toggle:hover {
            background-color: #f9fafb;
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
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.875rem;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        .form-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        /* Enhanced Profile UI Styles (from Resident Profile) */
        .profile-container {
            background: #f8fafc;
            min-height: 100vh;
            padding: 2rem;
        }
        
        .profile-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .profile-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }
        
        .profile-header {
            background: white;
            padding: 2rem;
            color: #1f2937;
            position: relative;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 3px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: #d1d5db;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        
        .profile-email {
            font-size: 1.1rem;
            color: #6b7280;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .profile-badges {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .profile-badge {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 20px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: #374151;
            transition: all 0.3s ease;
        }
        
        .profile-badge:hover {
            background: #e5e7eb;
            transform: translateY(-1px);
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .stat-item {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-item:hover {
            background: #f3f4f6;
            transform: translateY(-2px);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #1f2937;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .form-section:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            background: #f3f4f6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        .btn-primary {
            background: #374151;
            border: none;
            border-radius: 8px;
            padding: 0.875rem 1.5rem;
            color: white;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .btn-primary:hover {
            background: #1f2937;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6b7280;
            border: none;
            border-radius: 8px;
            padding: 0.875rem 1.5rem;
            color: white;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            transition: all 0.3s ease;
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s ease, opacity 0.3s ease;
            opacity: 0;
        }

        .modal.show .modal-content {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding: 1.5rem 2rem;
            border-top: 1px solid #e5e7eb;
        }

        .hidden {
            display: none !important;
        }
        
        .image-upload-area {
            border: 2px dashed #cbd5e1;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            cursor: pointer !important;
            pointer-events: auto !important;
        }
        .image-upload-area:hover {
            border-color: #dc2626;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }
        .image-upload-area.dragover {
            border-color: #dc2626;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            transform: scale(1.02);
        }
        .image-upload-area label {
            pointer-events: auto !important;
            cursor: pointer !important;
            display: block;
            width: 100%;
            height: 100%;
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
</head>
<body class="bg-gray-50">
    <div id="app" class="flex min-h-screen dashboard-shell">
        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        
    <!-- Sidebar -->
        <?php render_super_admin_sidebar([
            'current_page' => $current_page,
            'user_data' => $user_data
        ]); ?>

        <!-- Main Content -->
        <div id="dashboard-main-wrapper" class="flex flex-col flex-1 min-h-screen">
            <!-- Top Header -->
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
                            <h1 class="text-xl font-bold text-gray-900 leading-none"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
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

            <!-- Main Content Area -->
            <main id="main-content-area" class="flex-1 overflow-y-auto container-responsive">
                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Profile</h1>
                    <p class="text-gray-600">Manage your account settings and profile information</p>
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
            <div class="profile-container">
                <div class="profile-card">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php if ($user_data['profile_image']): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                            <?php endif; ?>
                                    </div>
                        <h1 class="profile-name"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h1>
                        <p class="profile-email"><?php echo htmlspecialchars($user_data['email']); ?></p>
                        
                        <div class="profile-badges">
                            <div class="profile-badge">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Super Administrator
                                </div>
                            <div class="profile-badge">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Verified
                            </div>
                        </div>
                        
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo date('M Y', strtotime($user_data['created_at'])); ?></div>
                                <div class="stat-label">Member Since</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">Today</div>
                                <div class="stat-label">Last Active</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">Active</div>
                                <div class="stat-label">Status</div>
                            </div>
                    </div>
                </div>

                    <div class="p-8">
                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-4 justify-center mb-8">
                            <button onclick="openUpdateProfileModal()" class="btn-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                Update Profile
                            </button>
                            <button onclick="openChangePasswordModal()" class="btn-secondary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Change Password
                            </button>
                            <button onclick="document.getElementById('avatarInput').click()" class="btn-secondary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                Upload Avatar
                            </button>
                        </div>

                        <!-- Profile Picture Upload Form (Hidden) -->
                        <form method="post" enctype="multipart/form-data" id="avatarUploadForm" style="display: none;">
                            <input type="hidden" name="action" value="upload_avatar">
                            <input type="file" id="avatarInput" name="avatar" accept="image/*" onchange="handleFileSelect(this)">
                        </form>

                        <!-- Profile Information Display -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="form-section">
                                <div class="section-header">
                                    <div class="section-icon">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                    <h2 class="section-title">Personal Information</h2>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-sm font-medium text-gray-600">Full Name:</span>
                                        <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-sm font-medium text-gray-600">Email:</span>
                                        <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['email']); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center py-2">
                                        <span class="text-sm font-medium text-gray-600">Member Since:</span>
                                        <span class="text-sm text-gray-900"><?php echo date('F Y', strtotime($user_data['created_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        
                            <div class="form-section">
                                <div class="section-header">
                                    <div class="section-icon">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <h2 class="section-title">Account Status</h2>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-sm font-medium text-gray-600">Status:</span>
                                        <span class="text-sm text-green-600 font-medium">Active</span>
                                    </div>
                                    <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                        <span class="text-sm font-medium text-gray-600">Last Active:</span>
                                        <span class="text-sm text-gray-900">Today</span>
                                    </div>
                                    <div class="flex justify-between items-center py-2">
                                        <span class="text-sm font-medium text-gray-600">Role:</span>
                                        <span class="text-sm text-gray-900">Super Administrator</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
        </div>
    </div>

    <script>
        // Mobile menu toggle and sidebar functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.getElementById('mobileMenuToggle');
            const sidebarAside = document.getElementById('sidebar-aside');
            const overlay = document.getElementById('sidebarOverlay');

            function openMobileSidebar() {
                if (sidebarAside) {
                    sidebarAside.classList.remove('hidden');
                    sidebarAside.classList.add('show');
                    sidebarAside.style.left = '0';
                    sidebarAside.style.display = 'flex';
                }
                if (overlay) {
                    overlay.classList.add('show');
                    overlay.style.display = 'block';
                }
            }

            function closeMobileSidebar() {
                if (sidebarAside) {
                    sidebarAside.classList.remove('show');
                    sidebarAside.style.left = '-16rem';
                }
                if (overlay) {
                    overlay.classList.remove('show');
                    overlay.style.display = 'none';
                }
            }

            if (mobileToggle) {
                mobileToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (sidebarAside && sidebarAside.classList.contains('show')) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function() {
                    closeMobileSidebar();
                });
            }

            // Notification and Profile Dropdowns
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');

            // Toggle notification dropdown
            if (notificationBtn && notificationDropdown) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = !notificationDropdown.classList.contains('hidden');
                    
                    if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                        profileDropdown.classList.add('hidden');
                    }
                    
                    if (isOpen) {
                        notificationDropdown.classList.add('hidden');
                    } else {
                        notificationDropdown.classList.remove('hidden');
                    }
                });
            }

            // Toggle profile dropdown
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = !profileDropdown.classList.contains('hidden');
                    
                    if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                        notificationDropdown.classList.add('hidden');
                    }
                    
                    if (isOpen) {
                        profileDropdown.classList.add('hidden');
                    } else {
                        profileDropdown.classList.remove('hidden');
                    }
                });
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                    if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                        notificationDropdown.classList.add('hidden');
                    }
                }
                
                if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                }
            });

            // Close dropdowns on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                        notificationDropdown.classList.add('hidden');
                    }
                    if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                        profileDropdown.classList.add('hidden');
                    }
                }
            });
        });
    </script>
</body>
</html>
    <div id="updateProfileModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 rounded-t-2xl flex items-center justify-between">
                <h3 class="text-xl font-bold text-white">Update Profile</h3>
                <button onclick="closeUpdateProfileModal()" class="text-white hover:text-gray-200 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="post" class="p-6">
                <input type="hidden" name="action" value="update_profile">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all input-focus" 
                                       placeholder="Enter first name" required>
                                            </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all input-focus" 
                                       placeholder="Enter last name" required>
                                            </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all input-focus" 
                                       placeholder="Enter email address" required>
                    </div>
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeUpdateProfileModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-colors font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all font-medium shadow-lg">
                                    Update Profile
                                    </button>
                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full">
            <div class="sticky top-0 bg-gradient-to-r from-orange-600 to-orange-700 px-6 py-4 rounded-t-2xl flex items-center justify-between">
                <h3 class="text-xl font-bold text-white">Change Password</h3>
                <button onclick="closeChangePasswordModal()" class="text-white hover:text-gray-200 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                </button>
                                </div>
            <form method="post" class="p-6">
                                <input type="hidden" name="action" value="change_password">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                    <input type="password" name="current_password" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all input-focus" 
                                       placeholder="Enter current password" required>
                                </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                        <input type="password" name="new_password" id="newPassword" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all input-focus" 
                               placeholder="Enter new password" required minlength="6">
                        <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
                                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirmPassword" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all input-focus" 
                                       placeholder="Confirm new password" required>
                        <p id="passwordMatch" class="text-xs mt-1 hidden"></p>
                                    </div>
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeChangePasswordModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-colors font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-orange-600 to-orange-700 text-white rounded-xl hover:from-orange-700 hover:to-orange-800 transition-all font-medium shadow-lg">
                                    Change Password
                                    </button>
                    </div>
                                </div>
                            </form>
                    </div>
                </div>

    <!-- JavaScript for Enhanced UI -->
    <script>
        // Modal Functions
        function openUpdateProfileModal() {
            document.getElementById('updateProfileModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeUpdateProfileModal() {
            document.getElementById('updateProfileModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function openChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.add('hidden');
            document.body.style.overflow = '';
            // Reset form
            document.querySelector('#changePasswordModal form').reset();
            document.getElementById('passwordMatch').classList.add('hidden');
        }

        // Handle file selection directly - submit form automatically
        function handleFileSelect(input) {
            const file = input.files[0];
            if (file) {
                // Validate file type
                if (!file.type.match('image.*')) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid File',
                            text: 'Please select an image file.',
                            customClass: {
                                popup: 'swal2-popup-enhanced',
                                title: 'swal2-title-enhanced',
                                htmlContainer: 'swal2-html-container-enhanced',
                                confirmButton: 'swal2-confirm-enhanced'
                            },
                            buttonsStyling: false
                        });
                    } else {
                        alert('Please select an image file.');
                    }
                    input.value = '';
                    return;
                }

                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'File Too Large',
                            text: 'Please select an image smaller than 5MB.',
                            customClass: {
                                popup: 'swal2-popup-enhanced',
                                title: 'swal2-title-enhanced',
                                htmlContainer: 'swal2-html-container-enhanced',
                                confirmButton: 'swal2-confirm-enhanced'
                            },
                            buttonsStyling: false
                        });
                    } else {
                        alert('Please select an image smaller than 5MB.');
                    }
                    input.value = '';
                    return;
                }

                // Submit the form automatically
                const form = document.getElementById('avatarUploadForm');
                if (form) {
                    form.submit();
                }
            }
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

        document.addEventListener('DOMContentLoaded', function() {
            // Setup validation for profile form fields
            const firstNameInput = document.querySelector('input[name="first_name"]');
            const lastNameInput = document.querySelector('input[name="last_name"]');
            if (firstNameInput) setupNameFieldValidation(firstNameInput);
            if (lastNameInput) setupNameFieldValidation(lastNameInput);
            
            // Image upload functionality
            const avatarInput = document.getElementById('avatarInput');
            
            if (avatarInput) {
                // File input change handler (backup - also handled by onchange attribute)
                avatarInput.addEventListener('change', function(e) {
                    handleFileSelect(this);
                });
            }

            // Password confirmation validation
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordMatch = document.getElementById('passwordMatch');
            
            if (newPasswordInput && confirmPasswordInput && passwordMatch) {
                function validatePasswords() {
                    if (newPasswordInput.value && confirmPasswordInput.value) {
                        if (newPasswordInput.value !== confirmPasswordInput.value) {
                            confirmPasswordInput.classList.add('border-red-500');
                            confirmPasswordInput.classList.remove('border-green-500');
                            passwordMatch.textContent = 'Passwords do not match';
                            passwordMatch.classList.remove('hidden', 'text-green-600');
                            passwordMatch.classList.add('text-red-600');
                        } else {
                            confirmPasswordInput.classList.remove('border-red-500');
                            confirmPasswordInput.classList.add('border-green-500');
                            passwordMatch.textContent = 'Passwords match';
                            passwordMatch.classList.remove('hidden', 'text-red-600');
                            passwordMatch.classList.add('text-green-600');
                        }
                    } else {
                        confirmPasswordInput.classList.remove('border-red-500', 'border-green-500');
                        passwordMatch.classList.add('hidden');
                    }
                }
                
                newPasswordInput.addEventListener('input', validatePasswords);
                confirmPasswordInput.addEventListener('input', validatePasswords);
            }

            // Form validation and feedback
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
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

            // Close modals when clicking outside
            document.getElementById('updateProfileModal')?.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeUpdateProfileModal();
                }
            });

            document.getElementById('changePasswordModal')?.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeChangePasswordModal();
                }
            });

            // Close modals on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeUpdateProfileModal();
                    closeChangePasswordModal();
                }
            });

            // Header Functions
            // Real-time clock update
        function updateClock() {
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

            // Update clock every second
            updateClock();
            setInterval(updateClock, 1000);
        
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
                        if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.add('hidden');
                            const arrow = document.getElementById('profile-arrow');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                };
                    document.addEventListener('click', window.superAdminProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const menu = document.getElementById('profile-menu');
                        const arrow = document.getElementById('profile-arrow');
                        if (menu) menu.classList.add('hidden');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                });
            }

            // Initialize night mode and profile dropdown
            initNightMode();
            initProfileDropdown();
        });
    </script>
</body>
</html>
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">My Profile</h2>
                <button onclick="closeProfileModal()" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <!-- Profile Picture -->
                <div class="flex justify-center mb-6">
                    <div class="relative">
                        <?php if ($user_data['profile_image']): ?>
                            <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                 alt="Profile Picture" 
                                 class="w-32 h-32 rounded-full object-cover border-4 border-gray-200"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="w-32 h-32 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-3xl border-4 border-gray-200" style="display:none;">
                                <?php 
                                $firstInitial = !empty($user_data['first_name']) ? substr($user_data['first_name'], 0, 1) : 'S';
                                $lastInitial = !empty($user_data['last_name']) ? substr($user_data['last_name'], 0, 1) : 'A';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="w-32 h-32 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-3xl border-4 border-gray-200">
                                <?php 
                                $firstInitial = !empty($user_data['first_name']) ? substr($user_data['first_name'], 0, 1) : 'S';
                                $lastInitial = !empty($user_data['last_name']) ? substr($user_data['last_name'], 0, 1) : 'A';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Profile Information -->
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-500">Full Name</label>
                        <p class="text-lg font-semibold text-gray-900 mt-1">
                            <?php echo htmlspecialchars(trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''))); ?>
                        </p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Email</label>
                        <p class="text-lg font-semibold text-gray-900 mt-1">
                            <?php echo htmlspecialchars($user_data['email'] ?? 'admin@example.com'); ?>
                        </p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Role</label>
                        <p class="text-lg font-semibold text-gray-900 mt-1">Super Administrator</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-500">Status</label>
                        <p class="text-lg font-semibold text-green-600 mt-1">Active</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="flex-direction: column; gap: 0.75rem;">
                <button onclick="openUpdateProfileModal()" class="btn-primary w-full">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Update Profile
                </button>
                <button onclick="openChangePasswordModal()" class="btn-secondary w-full">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                    Change Password
                </button>
                <button onclick="openChangeProfilePicModal()" class="btn-secondary w-full">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    Change Profile Picture
                </button>
            </div>
        </div>
    </div>

    <!-- Update Profile Modal -->
    <div id="updateProfileModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Update Profile</h2>
                <button onclick="closeUpdateProfileModal()" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="POST" action="<?php echo base_url('super_admin/profile.php'); ?>" class="modal-body">
                <input type="hidden" name="action" value="update_profile">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeUpdateProfileModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Change Password</h2>
                <button onclick="closeChangePasswordModal()" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="POST" action="<?php echo base_url('super_admin/profile.php'); ?>" class="modal-body">
                <input type="hidden" name="action" value="change_password">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                        <input type="password" name="current_password" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                        <input type="password" name="new_password" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                        <input type="password" name="confirm_password" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeChangePasswordModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Profile Picture Modal -->
    <div id="changeProfilePicModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Change Profile Picture</h2>
                <button onclick="closeChangeProfilePicModal()" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="POST" action="<?php echo base_url('super_admin/profile.php'); ?>" enctype="multipart/form-data" class="modal-body">
                <input type="hidden" name="action" value="upload_avatar">
                <div class="space-y-4">
                    <div class="text-center">
                        <?php if ($user_data['profile_image']): ?>
                            <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                 alt="Current Profile Picture" 
                                 class="w-32 h-32 rounded-full object-cover border-4 border-gray-200 mx-auto mb-4"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="w-32 h-32 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-3xl border-4 border-gray-200 mx-auto mb-4" style="display:none;">
                                <?php 
                                $firstInitial = !empty($user_data['first_name']) ? substr($user_data['first_name'], 0, 1) : 'S';
                                $lastInitial = !empty($user_data['last_name']) ? substr($user_data['last_name'], 0, 1) : 'A';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="w-32 h-32 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-3xl border-4 border-gray-200 mx-auto mb-4">
                                <?php 
                                $firstInitial = !empty($user_data['first_name']) ? substr($user_data['first_name'], 0, 1) : 'S';
                                $lastInitial = !empty($user_data['last_name']) ? substr($user_data['last_name'], 0, 1) : 'A';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select New Profile Picture</label>
                        <input type="file" name="avatar" accept="image/*" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                        <p class="text-xs text-gray-500 mt-2">Accepted formats: JPEG, PNG, GIF, WebP (Max: 5MB)</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeChangeProfilePicModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Upload Picture</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>


