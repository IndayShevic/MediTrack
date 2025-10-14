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
        $middle_initial = trim($_POST['middle_initial'] ?? '');
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
                    $stmt = db()->prepare('UPDATE users SET first_name = ?, middle_initial = ?, last_name = ?, email = ? WHERE id = ?');
                    $stmt->execute([$first_name, $middle_initial, $last_name, $email, $user['id']]);
                    
                    // Update resident details (including name sync)
                    $stmt = db()->prepare('UPDATE residents SET first_name = ?, middle_initial = ?, last_name = ?, phone = ?, address = ? WHERE user_id = ?');
                    $stmt->execute([$first_name, $middle_initial, $last_name, $phone, $address, $user['id']]);
                    
                    // Update session
                    $_SESSION['user']['first_name'] = $first_name;
                    $_SESSION['user']['middle_initial'] = $middle_initial;
                    $_SESSION['user']['last_name'] = $last_name;
                    $_SESSION['user']['name'] = $first_name . ($middle_initial ? ' ' . $middle_initial . ' ' : ' ') . $last_name;
                    $_SESSION['user']['email'] = $email;
                    
                    set_flash('Profile updated successfully!', 'success');
                }
            } catch (Throwable $e) {
                error_log('Profile update error: ' . $e->getMessage());
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
                    error_log('Password change error: ' . $e->getMessage());
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
                    error_log('Avatar upload error: ' . $e->getMessage());
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
           b.name as barangay_name, p.name as purok_name,
           CONCAT(p.name, ", ", b.name) as formatted_address
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
    <style>
        /* CRITICAL: Override mobile menu CSS that's breaking sidebar */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 1000 !important;
            transform: none !important;
        }
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override mobile media queries */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                transform: none !important;
                width: 280px !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }

        /* Enhanced Profile UI Styles */
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
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .form-group {
            position: relative;
        }
        
        .form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-label-icon {
            width: 16px;
            height: 16px;
            color: #6b7280;
        }
        
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #9ca3af;
            box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.1);
        }
        
        .form-input:hover {
            border-color: #9ca3af;
        }
        
        .address-info {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .address-info-title {
            font-size: 0.85rem;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .address-info-content {
            font-size: 0.9rem;
            color: #374151;
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
        
        .btn-primary:active {
            transform: translateY(0);
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
        
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #f9fafb;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: #9ca3af;
            background: #f3f4f6;
        }
        
        .upload-area.dragover {
            border-color: #6b7280;
            background: #f3f4f6;
        }
        
        .upload-icon {
            width: 48px;
            height: 48px;
            background: #e5e7eb;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        
        .flash-message {
            background: white;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border-left: 3px solid #e5e7eb;
            animation: slideIn 0.3s ease;
        }
        
        .flash-message.error {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .loading-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: scale(1);
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
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-container {
                padding: 1rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .profile-name {
                font-size: 1.5rem;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }
        }
    </style>
    </style>
    <style>
        /* CRITICAL: Override design-system.css sidebar styles - MUST be after design-system.css */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 9999 !important;
            overflow-y: auto !important;
            transform: none !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1) !important;
            transition: none !important;
        }
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        /* Remove hover effects */
        .sidebar-nav a:hover {
            background: transparent !important;
            color: inherit !important;
        }
        
        .sidebar-nav a {
            transition: none !important;
        }
        
        /* CRITICAL: Override mobile menu transforms */
        .sidebar.open {
            transform: none !important;
        }
        
        /* Ensure sidebar never transforms */
        .sidebar {
            transform: none !important;
        }
    </style>
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
        /* FORCE SIDEBAR TO STAY FIXED - OVERRIDE ALL OTHER STYLES */
        * {
            box-sizing: border-box !important;
        }
        
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden !important;
            height: 100% !important;
        }
        
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 9999 !important;
            overflow-y: auto !important;
            transform: none !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1) !important;
            transition: none !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }

        /* Ensure main content has proper margin and doesn't affect sidebar */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            position: relative !important;
            min-height: 100vh !important;
            background: #f9fafb !important;
        }

        /* Prevent any container from affecting sidebar position */
        .container, .wrapper, .page-wrapper {
            position: relative !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure sidebar brand and nav stay in place */
        .sidebar-brand {
            position: relative !important;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            color: white !important;
            padding: 1.5rem !important;
            border-bottom: 1px solid #e5e7eb !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
        }
        
        .sidebar-nav {
            position: relative !important;
            padding: 1rem !important;
        }
        
        .sidebar-nav a {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            padding: 0.75rem 1rem !important;
            margin-bottom: 0.25rem !important;
            border-radius: 0.5rem !important;
            color: #374151 !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            transition: none !important;
        }
        
        .sidebar-nav a.active {
            background: #dbeafe !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
        }

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
            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Browse Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                My Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/medicine_history.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Medicine History
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/family_members.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Family Members
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
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
        <div class="profile-container">
            <!-- Flash Messages -->
            <?php [$flash, $flashType] = get_flash(); if ($flash): ?>
                <div class="flash-message <?php echo $flashType === 'error' ? 'error' : ''; ?>">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <?php if ($flashType === 'success'): ?>
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($flash); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="profile-card">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if ($user_data['profile_image']): ?>
                            <img src="<?php echo htmlspecialchars(base_url($user_data['profile_image'])); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <h1 class="profile-name"><?php echo htmlspecialchars($user_data['first_name'] . ($user_data['middle_initial'] ? ' ' . $user_data['middle_initial'] . ' ' : ' ') . $user_data['last_name']); ?></h1>
                    <p class="profile-email"><?php echo htmlspecialchars($user_data['email']); ?></p>
                    
                    <div class="profile-badges">
                        <div class="profile-badge">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Resident
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
                        <button onclick="openUploadAvatarModal()" class="btn-secondary">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Upload Avatar
                        </button>
                    </div>

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
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['first_name'] . ($user_data['middle_initial'] ? ' ' . $user_data['middle_initial'] . ' ' : ' ') . $user_data['last_name']); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="text-sm font-medium text-gray-600">Email:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['email']); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="text-sm font-medium text-gray-600">Phone:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['phone'] ?? 'Not provided'); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="text-sm font-medium text-gray-600">Date of Birth:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['date_of_birth'] ?? 'Not provided'); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-sm font-medium text-gray-600">Address:</span>
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($user_data['formatted_address'] ?? 'Not provided'); ?></span>
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
                                    <span class="text-sm font-medium text-gray-600">Member Since:</span>
                                    <span class="text-sm text-gray-900"><?php echo date('M Y', strtotime($user_data['created_at'])); ?></span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                    <span class="text-sm font-medium text-gray-600">Last Active:</span>
                                    <span class="text-sm text-gray-900">Today</span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="text-sm font-medium text-gray-600">Role:</span>
                                    <span class="text-sm text-gray-900">Resident</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Update Profile Modal -->
    <div id="updateProfileModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Profile</h3>
                <button onclick="closeModal('updateProfileModal')" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="post" class="modal-body">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            First Name
                        </label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" 
                               class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Middle Initial
                        </label>
                        <input type="text" name="middle_initial" value="<?php echo htmlspecialchars($user_data['middle_initial'] ?? ''); ?>" 
                               class="form-input" maxlength="10" placeholder="e.g., A">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Last Name
                        </label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" 
                               class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            Email Address
                        </label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" 
                               class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            Phone Number
                        </label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" 
                               class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Address
                        </label>
                        <div class="address-info">
                            <div class="address-info-title">Auto-generated from location:</div>
                            <div class="address-info-content"><?php echo htmlspecialchars($user_data['formatted_address'] ?? 'No location data'); ?></div>
                        </div>
                        <textarea name="address" rows="3" 
                                  class="form-input" 
                                  placeholder="Additional address details (optional)"><?php echo htmlspecialchars($user_data['address'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">Add any additional address information if needed</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('updateProfileModal')" class="btn-secondary">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Update Profile
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Change Password</h3>
                <button onclick="closeModal('changePasswordModal')" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="post" class="modal-body">
                <input type="hidden" name="action" value="change_password">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Current Password
                        </label>
                        <input type="password" name="current_password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            New Password
                        </label>
                        <input type="password" name="new_password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Confirm New Password
                        </label>
                        <input type="password" name="confirm_password" class="form-input" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('changePasswordModal')" class="btn-secondary">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Avatar Modal -->
    <div id="uploadAvatarModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Upload Profile Picture</h3>
                <button onclick="closeModal('uploadAvatarModal')" class="modal-close">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form method="post" enctype="multipart/form-data" class="modal-body" id="avatarForm">
                <input type="hidden" name="action" value="upload_avatar">
                <div class="upload-area" id="uploadArea">
                    <div class="upload-icon">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Upload Profile Picture</h3>
                    <p class="text-gray-600 mb-4">Drag and drop your image here, or click to browse</p>
                    <div class="flex items-center justify-center space-x-4">
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" class="hidden" required>
                        <label for="avatarInput" class="btn-primary cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                            Choose File
                        </label>
                    </div>
                    <div class="mt-4 text-sm text-gray-500">
                        <p>Max size: 5MB</p>
                        <p>Supported: JPEG, PNG, GIF, WebP</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('uploadAvatarModal')" class="btn-secondary">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Upload Image
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript for Enhanced UI -->
    <script>
        // Modal Functions
        function openUpdateProfileModal() {
            const modal = document.getElementById('updateProfileModal');
            modal.classList.remove('hidden');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function openChangePasswordModal() {
            const modal = document.getElementById('changePasswordModal');
            modal.classList.remove('hidden');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function openUploadAvatarModal() {
            const modal = document.getElementById('uploadAvatarModal');
            modal.classList.remove('hidden');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Reset the upload area to original state
            resetFileInput();
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('show');
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }, 300);
        }

        function resetFileInput() {
            const avatarInput = document.getElementById('avatarInput');
            const uploadArea = document.getElementById('uploadArea');
            
            // Reset file input
            avatarInput.value = '';
            
            // Reset upload area to original state
            uploadArea.innerHTML = `
                <div class="upload-icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Upload Profile Picture</h3>
                <p class="text-gray-600 mb-4">Drag and drop your image here, or click to browse</p>
                <div class="flex items-center justify-center space-x-4">
                    <input type="file" name="avatar" id="avatarInput" accept="image/*" class="hidden" required>
                    <label for="avatarInput" class="btn-primary cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        Choose File
                    </label>
                </div>
                <div class="mt-4 text-sm text-gray-500">
                    <p>Max size: 5MB</p>
                    <p>Supported: JPEG, PNG, GIF, WebP</p>
                </div>
            `;
            
            // Re-attach event listeners
            attachFileInputListeners();
        }

        // Close modal when clicking outside
        function closeModalOnOutsideClick(event) {
            if (event.target.classList.contains('modal')) {
                const modalId = event.target.id;
                closeModal(modalId);
            }
        }

        // Function to attach file input listeners
        function attachFileInputListeners() {
            const avatarInput = document.getElementById('avatarInput');
            const uploadArea = document.getElementById('uploadArea');

            if (avatarInput) {
                avatarInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // Validate file size (5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File size must be less than 5MB');
                            this.value = ''; // Clear the input
                            return;
                        }
                        
                        // Validate file type
                        if (!file.type.startsWith('image/')) {
                            alert('Please select an image file');
                            this.value = ''; // Clear the input
                            return;
                        }
                        
                        // Show file name and enable submit button
                        const fileName = file.name;
                        const fileSize = (file.size / 1024 / 1024).toFixed(2);
                        uploadArea.innerHTML = `
                            <div class="upload-icon">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">File Selected</h3>
                            <p class="text-gray-600 mb-2">${fileName}</p>
                            <p class="text-sm text-gray-500 mb-4">Size: ${fileSize} MB</p>
                            <div class="flex items-center justify-center space-x-4">
                                <button type="button" onclick="resetFileInput()" class="btn-secondary">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Remove File
                                </button>
                            </div>
                        `;
                    }
                });
            }

            // Drag and drop functionality
            if (uploadArea) {
                uploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    uploadArea.classList.add('dragover');
                });

                uploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    uploadArea.classList.remove('dragover');
                });

                uploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    uploadArea.classList.remove('dragover');
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const file = files[0];
                        if (file.type.startsWith('image/')) {
                            avatarInput.files = files;
                            avatarInput.dispatchEvent(new Event('change'));
                        } else {
                            alert('Please select an image file');
                        }
                    }
                });

                // Click to upload
                uploadArea.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'BUTTON') {
                        avatarInput.click();
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners for modal close on outside click
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', closeModalOnOutsideClick);
            });

            // Initialize file input listeners
            attachFileInputListeners();

            // Form submission with loading states
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<div class="loading-spinner"></div> Processing...';
                        submitBtn.disabled = true;
                        
                        // Re-enable after 5 seconds as fallback
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 5000);
                    }
                    
                    // Special handling for avatar upload
                    if (form.id === 'avatarForm') {
                        // Close modal after successful upload
                        setTimeout(() => {
                            closeModal('uploadAvatarModal');
                        }, 2000);
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
                        } else {
                            confirmPasswordInput.setCustomValidity('');
                        }
                    }
                }
                
                newPasswordInput.addEventListener('input', validatePasswords);
                confirmPasswordInput.addEventListener('input', validatePasswords);
            }

            // Enhanced form input interactions
            const formInputs = document.querySelectorAll('.form-input');
            formInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });

            // Add subtle animations to form sections
            const formSections = document.querySelectorAll('.form-section');
            formSections.forEach(section => {
                section.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                section.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Profile avatar hover effect
            const profileAvatar = document.querySelector('.profile-avatar');
            if (profileAvatar) {
                profileAvatar.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                
                profileAvatar.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            }

            // Auto-hide flash messages
            const flashMessages = document.querySelectorAll('.flash-message');
            flashMessages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        message.remove();
                    }, 300);
                }, 5000);
            });

            // Button click animations
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

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const openModal = document.querySelector('.modal.show');
                    if (openModal) {
                        const modalId = openModal.id;
                        closeModal(modalId);
                    }
                }
            });
        });
    </script>
</body>
</html>


