<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $start_date = $_POST['start_date'] ?? '';
                $end_date = $_POST['end_date'] ?? '';
                $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
                $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
                $target_audience = $_POST['target_audience'] ?? 'all';
                $target_barangay_id = !empty($_POST['target_barangay_id']) ? (int)$_POST['target_barangay_id'] : null;
                $target_purok_id = !empty($_POST['target_purok_id']) ? (int)$_POST['target_purok_id'] : null;
                
                // Validate target_audience
                if (!in_array($target_audience, ['all', 'residents', 'bhw'])) {
                    $target_audience = 'all';
                }
                
                // If purok is selected, barangay must also be selected
                if ($target_purok_id && !$target_barangay_id) {
                    set_flash('Please select a barangay when selecting a purok.', 'error');
                    redirect_to('super_admin/announcements.php');
                    exit;
                }
                
                if (empty($title) || empty($description) || empty($start_date) || empty($end_date)) {
                    set_flash('All fields are required.', 'error');
                } elseif ($start_date > $end_date) {
                    set_flash('End date must be after or equal to start date.', 'error');
                } elseif ($start_date < date('Y-m-d')) {
                    set_flash('Start date cannot be in the past. Please select today or a future date.', 'error');
                } elseif ($start_date === $end_date && $start_time && $end_time && $start_time > $end_time) {
                    set_flash('End time must be after start time when dates are the same.', 'error');
                } else {
                    try {
                        $stmt = db()->prepare('INSERT INTO announcements (title, description, start_date, end_date, start_time, end_time, created_by, target_audience, target_barangay_id, target_purok_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$title, $description, $start_date, $end_date, $start_time, $end_time, $user['id'], $target_audience, $target_barangay_id, $target_purok_id]);
                        
                        // Get the ID of the newly created announcement (cast to int since lastInsertId can return string)
                        $announcement_id = (int)db()->lastInsertId();
                        
                        // Send email notifications to targeted users
                        $emailResults = send_announcement_notification_to_all_users($title, $description, $start_date, $end_date, $announcement_id, false, null, $start_time, $end_time);
                        
                        if ($emailResults['sent'] > 0) {
                            $message = "Announcement created successfully. Email notifications sent to {$emailResults['sent']} users.";
                            if ($emailResults['failed'] > 0) {
                                $message .= " ({$emailResults['failed']} emails failed to send)";
                            }
                            if (!empty($emailResults['errors'])) {
                                error_log("Announcement email errors: " . implode(", ", $emailResults['errors']));
                            }
                            set_flash($message, 'success');
                        } else {
                            $errorMsg = 'Announcement created successfully, but no email notifications were sent.';
                            if (!empty($emailResults['errors'])) {
                                $errorMsg .= ' ' . implode(" ", array_slice($emailResults['errors'], 0, 2));
                            }
                            set_flash($errorMsg, 'warning');
                        }
                        
                    } catch (Exception $e) {
                        set_flash('Error creating announcement: ' . $e->getMessage(), 'error');
                        error_log("Announcement creation error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $start_date = $_POST['start_date'] ?? '';
                $end_date = $_POST['end_date'] ?? '';
                $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
                $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
                $target_audience = $_POST['target_audience'] ?? 'all';
                $target_barangay_id = !empty($_POST['target_barangay_id']) ? (int)$_POST['target_barangay_id'] : null;
                $target_purok_id = !empty($_POST['target_purok_id']) ? (int)$_POST['target_purok_id'] : null;
                
                // Validate target_audience
                if (!in_array($target_audience, ['all', 'residents', 'bhw'])) {
                    $target_audience = 'all';
                }
                
                // If purok is selected, barangay must also be selected
                if ($target_purok_id && !$target_barangay_id) {
                    set_flash('Please select a barangay when selecting a purok.', 'error');
                    redirect_to('super_admin/announcements.php');
                    exit;
                }
                
                if ($id <= 0 || empty($title) || empty($description) || empty($start_date) || empty($end_date)) {
                    set_flash('All fields are required.', 'error');
                } elseif ($start_date > $end_date) {
                    set_flash('End date must be after or equal to start date.', 'error');
                } elseif ($start_date === $end_date && $start_time && $end_time && $start_time > $end_time) {
                    set_flash('End time must be after start time when dates are the same.', 'error');
                } else {
                    try {
                        // Check if announcement exists and get existing date for validation
                        $existingAnnouncement = db()->prepare('SELECT start_date, is_active FROM announcements WHERE id = ?');
                        $existingAnnouncement->execute([$id]);
                        $existing = $existingAnnouncement->fetch();
                        
                        if (!$existing) {
                            set_flash('Announcement not found.', 'error');
                        } else {
                            // For editing existing announcements, we allow keeping past dates
                            // But prevent changing to a date further in the past
                            $today = date('Y-m-d');
                            if ($start_date < $today && $start_date < $existing['start_date']) {
                                set_flash('You cannot change the start date to a date in the past.', 'error');
                            } else {
                                // Get old announcement data for comparison
                                $oldAnnouncement = db()->prepare('SELECT title, description, start_date, end_date, start_time, end_time, is_active, target_audience, target_barangay_id, target_purok_id FROM announcements WHERE id = ?');
                        $oldAnnouncement->execute([$id]);
                        $old = $oldAnnouncement->fetch();
                        
                                // Update the announcement
                                $stmt = db()->prepare('UPDATE announcements SET title = ?, description = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, target_audience = ?, target_barangay_id = ?, target_purok_id = ? WHERE id = ?');
                                $stmt->execute([$title, $description, $start_date, $end_date, $start_time, $end_time, $target_audience, $target_barangay_id, $target_purok_id, $id]);
                        
                                // Always send email notifications when an announcement is updated (any change)
                                // Prepare old data for change notification
                                $oldData = null;
                        if ($old) {
                                    $oldData = [
                                        'title' => $old['title'],
                                        'description' => $old['description'],
                                        'start_date' => $old['start_date'],
                                        'end_date' => $old['end_date'],
                                        'start_time' => $old['start_time'] ?? null,
                                        'end_time' => $old['end_time'] ?? null,
                                        'target_audience' => $old['target_audience'] ?? 'all',
                                        'target_barangay_id' => $old['target_barangay_id'] ?? null,
                                        'target_purok_id' => $old['target_purok_id'] ?? null
                                    ];
                        }
                        
                                // Send email notifications for the update
                                $emailResults = send_announcement_notification_to_all_users($title, $description, $start_date, $end_date, $id, true, $oldData, $start_time, $end_time);
                            
                            if ($emailResults['sent'] > 0) {
                                    $message = "Announcement updated successfully. Email notifications sent to {$emailResults['sent']} users about the changes.";
                                if ($emailResults['failed'] > 0) {
                                    $message .= " ({$emailResults['failed']} emails failed to send)";
                                }
                                    if (!empty($emailResults['errors'])) {
                                        error_log("Announcement email errors: " . implode(", ", $emailResults['errors']));
                                    }
                                set_flash($message, 'success');
                            } else {
                                    $errorMsg = 'Announcement updated successfully, but no email notifications were sent.';
                                    if (!empty($emailResults['errors'])) {
                                        $errorMsg .= ' ' . implode(" ", array_slice($emailResults['errors'], 0, 2));
                            }
                                    set_flash($errorMsg, 'warning');
                        }
                            }
                        }
                    } catch (Exception $e) {
                        set_flash('Error updating announcement: ' . $e->getMessage(), 'error');
                        error_log("Announcement update error: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    try {
                        $stmt = db()->prepare('DELETE FROM announcements WHERE id = ?');
                        $stmt->execute([$id]);
                        set_flash('Announcement deleted successfully.', 'success');
                    } catch (Exception $e) {
                        set_flash('Error deleting announcement: ' . $e->getMessage(), 'error');
                    }
                }
                break;
                
            case 'toggle_status':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    try {
                        $stmt = db()->prepare('UPDATE announcements SET is_active = NOT is_active WHERE id = ?');
                        $stmt->execute([$id]);
                        set_flash('Announcement status updated successfully.', 'success');
                    } catch (Exception $e) {
                        set_flash('Error updating announcement status: ' . $e->getMessage(), 'error');
                    }
                }
                break;
        }
    }
    redirect_to('super_admin/announcements.php');
}

// Fetch announcements
try {
    $announcements = db()->query('SELECT a.*, CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) as created_by_name FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.start_date DESC, a.created_at DESC')->fetchAll();
    
    // Ensure targeting fields exist (for announcements created before migration)
    foreach ($announcements as &$announcement) {
        if (!isset($announcement['target_audience'])) {
            $announcement['target_audience'] = 'all';
        }
        if (!isset($announcement['target_barangay_id'])) {
            $announcement['target_barangay_id'] = null;
        }
        if (!isset($announcement['target_purok_id'])) {
            $announcement['target_purok_id'] = null;
        }
    }
    unset($announcement); // Break reference
} catch (Exception $e) {
    $announcements = [];
}

// Fetch barangays and puroks for the form
try {
    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
} catch (Exception $e) {
    $barangays = [];
}

try {
    $puroks = db()->query('SELECT id, barangay_id, name FROM puroks ORDER BY barangay_id, name')->fetchAll();
} catch (Exception $e) {
    $puroks = [];
}

// Get count of users who will receive email notifications
try {
    $emailUserCount = db()->query('SELECT COUNT(*) as count FROM users WHERE email IS NOT NULL AND email != "" AND role IN ("bhw", "resident")')->fetch()['count'];
} catch (Exception $e) {
    $emailUserCount = 0;
}

// Get flash message
[$flash_msg, $flash_type] = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Announcements Management · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'fade-in': 'fadeIn 0.4s ease-out',
                        'slide-in-right': 'slideInRight 0.5s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'float': 'float 3s ease-in-out infinite',
                        'shimmer': 'shimmer 2s linear infinite',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideInRight: {
                            '0%': { opacity: '0', transform: 'translateX(20px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                        bounceIn: {
                            '0%': { opacity: '0', transform: 'scale(0.3)' },
                            '50%': { opacity: '1', transform: 'scale(1.05)' },
                            '70%': { transform: 'scale(0.9)' },
                            '100%': { opacity: '1', transform: 'scale(1)' }
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' }
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-200px 0' },
                            '100%': { backgroundPosition: 'calc(200px + 100%) 0' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Custom SweetAlert2 Styles */
        .swal2-popup {
            border-radius: 1.5rem !important;
            padding: 2.5rem !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0, 0, 0, 0.05) !important;
            backdrop-filter: blur(20px) !important;
        }
        
        .swal2-title {
            font-size: 1.75rem !important;
            font-weight: 700 !important;
            color: #1f2937 !important;
            margin-bottom: 1rem !important;
            letter-spacing: -0.025em !important;
        }
        
        .swal2-html-container {
            font-size: 1rem !important;
            color: #6b7280 !important;
            line-height: 1.6 !important;
            margin-top: 0.5rem !important;
        }
        
        .swal2-icon {
            width: 5rem !important;
            height: 5rem !important;
            margin: 0 auto 1.5rem !important;
            border: none !important;
        }
        
        .swal2-icon.swal2-question {
            color: #3b82f6 !important;
            border-color: #3b82f6 !important;
        }
        
        .swal2-icon.swal2-question .swal2-icon-content {
            font-size: 2.5rem !important;
        }
        
        .swal2-actions {
            margin-top: 2rem !important;
            gap: 0.75rem !important;
        }
        
        .swal2-confirm {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
            border: none !important;
            border-radius: 0.75rem !important;
            padding: 0.75rem 2rem !important;
            font-size: 0.9375rem !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4) !important;
            transition: all 0.2s ease !important;
        }
        
        .swal2-confirm:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.5) !important;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
        }
        
        .swal2-cancel {
            background: #ffffff !important;
            border: 2px solid #e5e7eb !important;
            border-radius: 0.75rem !important;
            padding: 0.75rem 2rem !important;
            font-size: 0.9375rem !important;
            font-weight: 600 !important;
            color: #6b7280 !important;
            transition: all 0.2s ease !important;
        }
        
        .swal2-cancel:hover {
            background: #f9fafb !important;
            border-color: #d1d5db !important;
            color: #374151 !important;
            transform: translateY(-2px) !important;
        }
        
        .swal2-backdrop-show {
            background: rgba(0, 0, 0, 0.5) !important;
            backdrop-filter: blur(4px) !important;
        }
        
        @keyframes swal2-show {
            0% {
                transform: scale(0.8) translateY(-20px);
                opacity: 0;
            }
            100% {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes swal2-hide {
            0% {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
            100% {
                transform: scale(0.8) translateY(-20px);
                opacity: 0;
            }
        }
        
        .swal2-show {
            animation: swal2-show 0.3s ease-out !important;
        }
        
        .swal2-hide {
            animation: swal2-hide 0.2s ease-in !important;
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
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .gradient-border {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2px;
            border-radius: 12px;
        }
        .gradient-border > div {
            background: white;
            border-radius: 10px;
        }
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4);
        }
        .announcement-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .announcement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4, #10b981);
            background-size: 200% 100%;
            animation: gradient-shift 3s ease-in-out infinite;
        }
        .announcement-card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15), 0 8px 16px -4px rgba(0, 0, 0, 0.1);
            border-color: rgba(59, 130, 246, 0.4);
        }
        .announcement-card:hover::before {
            animation-duration: 1s;
        }
        
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.4);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        .fc-event {
            border-radius: 6px !important;
            border: none !important;
            padding: 2px 4px !important;
        }
        .fc-event-title {
            font-weight: 600 !important;
        }
        .fc-daygrid-event {
            margin: 1px 0 !important;
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
        
        /* Clean Professional Calendar Styles */
        #calendar {
            min-height: 700px !important;
            height: auto !important;
            background: white !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
            border: 1px solid #e5e7eb !important;
            overflow: hidden !important;
        }
        
        .fc {
            font-size: 16px !important;
            height: 100% !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        
        .fc-header-toolbar {
            padding: 20px 24px !important;
            background: #f8fafc !important;
            border-radius: 12px 12px 0 0 !important;
            margin-bottom: 0 !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        
        .fc-toolbar-title {
            font-size: 24px !important;
            font-weight: 700 !important;
            color: #1f2937 !important;
            letter-spacing: -0.025em !important;
        }
        
        .fc-button {
            background: white !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            padding: 8px 16px !important;
            font-weight: 500 !important;
            color: #374151 !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        }
        
        .fc-button:hover {
            background: #f9fafb !important;
            border-color: #9ca3af !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .fc-button:active {
            transform: translateY(0) !important;
        }
        
        .fc-button-group {
            background: #f3f4f6 !important;
            border-radius: 8px !important;
            padding: 2px !important;
        }
        
        .fc-button-group .fc-button {
            background: transparent !important;
            border: none !important;
            margin: 0 !important;
            padding: 6px 12px !important;
            border-radius: 6px !important;
        }
        
        .fc-button-group .fc-button.fc-button-active {
            background: white !important;
            color: #1f2937 !important;
            font-weight: 600 !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        }
        
        .fc-daygrid {
            height: auto !important;
            background: white !important;
        }
        
        .fc-daygrid-body {
            height: auto !important;
        }
        
        .fc-scroller {
            overflow-y: auto !important;
            padding: 0 !important;
        }
        
        .fc-col-header {
            background: #f9fafb !important;
            border: none !important;
        }
        
        .fc-col-header-cell {
            padding: 12px 8px !important;
            font-weight: 600 !important;
            color: #6b7280 !important;
            font-size: 13px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            border: none !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        
        .fc-daygrid-day {
            min-height: 120px !important;
            border: 1px solid #f3f4f6 !important;
            transition: all 0.2s ease !important;
            position: relative !important;
            background: white !important;
        }
        
        .fc-daygrid-day:hover {
            background: #f9fafb !important;
            border-color: #d1d5db !important;
        }
        
        .fc-daygrid-day-number {
            font-weight: 600 !important;
            color: #374151 !important;
            padding: 8px 12px !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
        }
        
        .fc-day-today {
            background: #eff6ff !important;
            border-color: #3b82f6 !important;
        }
        
        .fc-day-today .fc-daygrid-day-number {
            color: #1d4ed8 !important;
            font-weight: 700 !important;
        }
        
        .fc-daygrid-day-events {
            max-height: 100px !important;
            overflow-y: auto !important;
            padding: 4px !important;
            margin-top: 4px !important;
        }
        
        .fc-event {
            border-radius: 6px !important;
            border: none !important;
            padding: 4px 8px !important;
            font-weight: 500 !important;
            font-size: 12px !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
            transition: all 0.2s ease !important;
            margin-bottom: 2px !important;
            background: #3b82f6 !important;
            color: white !important;
        }
        
        .fc-event:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .fc-event-title {
            font-weight: 500 !important;
            line-height: 1.3 !important;
        }
        
        /* Other month days styling */
        .fc-day-other .fc-daygrid-day-number {
            color: #9ca3af !important;
            font-weight: 400 !important;
        }
        
        .fc-day-other {
            background: #f9fafb !important;
        }
        
        /* Weekend styling */
        .fc-day-sat,
        .fc-day-sun {
            background: #fafafa !important;
        }
        
        .fc-day-sat .fc-daygrid-day-number,
        .fc-day-sun .fc-daygrid-day-number {
            color: #6b7280 !important;
        }
        
        /* Scrollbar styling */
        .fc-scroller::-webkit-scrollbar {
            width: 4px;
        }
        
        .fc-scroller::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Enhanced Status Badges */
        .status-badge {
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.5s;
        }
        .status-badge:hover::before {
            left: 100%;
        }
        
        /* Enhanced Action Buttons */
        .action-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .action-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s, height 0.3s;
        }
        .action-btn:hover::before {
            width: 100%;
            height: 100%;
        }
        
        /* Enhanced Calendar Events */
        .fc-event {
            border-radius: 8px !important;
            border: none !important;
            padding: 4px 8px !important;
            font-weight: 600 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
            transition: all 0.3s ease !important;
        }
        .fc-event:hover {
            transform: scale(1.05) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2) !important;
        }
        
        /* Enhanced Empty State */
        .empty-state {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 2px dashed rgba(148, 163, 184, 0.3);
            position: relative;
            overflow: hidden;
        }
        .empty-state::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.1); }
        }
        
        /* Enhanced Header Stats */
        .stat-glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced Modal */
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
        }
        
        .modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/announcements.php')); ?>">
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
                    <h1 class="text-3xl font-bold text-gray-900">Announcements Management</h1>
                    <p class="text-gray-600 mt-1">Manage health center activities and announcements</p>
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

        <!-- Flash Message -->
        <?php if ($flash_msg): ?>
            <div class="mb-6 p-4 rounded-lg <?php 
                echo $flash_type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 
                    ($flash_type === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 
                    'bg-red-100 text-red-800 border border-red-200'); 
            ?>">
                <div class="flex items-center space-x-2">
                    <?php if ($flash_type === 'success'): ?>
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    <?php elseif ($flash_type === 'warning'): ?>
                        <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($flash_msg); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Dashboard Content -->
        <div class="content-body">
            <!-- Full Calendar View -->
            <div class="mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Announcement Calendar</h3>
                            <p class="text-gray-600">Click on events to view details • Click on dates to create new announcements</p>
                            </div>
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <button onclick="openCreateModal()" class="btn-primary">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Add Announcement
                            </button>
                            <button onclick="openAnnouncementsModal()" class="btn-primary" style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                        </svg>
                                View All Announcements
                                    </button>
                                </div>
                                                </div>
                    <div id="calendar" class="rounded-lg overflow-hidden"></div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create/Edit Modal -->
    <div id="announcementModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 backdrop-blur-sm">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto transform transition-all duration-300 scale-95 opacity-0" id="modalContent">
                <div class="p-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                                </svg>
                            </div>
                            <h3 id="modalTitle" class="text-2xl font-bold text-gray-900">Create Announcement</h3>
                        </div>
                        <button onclick="closeModal()" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-all duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <form id="announcementForm" method="POST">
                        <input type="hidden" name="action" id="formAction" value="create">
                        <input type="hidden" name="id" id="formId">
                        
                        <div class="space-y-6">
                            <div>
                                <label for="title" class="block text-sm font-semibold text-gray-700 mb-3">Title</label>
                                <input type="text" id="title" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" placeholder="Enter announcement title">
                            </div>
                            
                            <div>
                                <label for="description" class="block text-sm font-semibold text-gray-700 mb-3">Description</label>
                                <textarea id="description" name="description" rows="4" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400 resize-none" placeholder="Describe the health center activity or announcement"></textarea>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="start_date" class="block text-sm font-semibold text-gray-700 mb-3">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <p class="text-xs text-gray-500 mt-1">Cannot be in the past</p>
                                </div>
                                
                                <div>
                                    <label for="end_date" class="block text-sm font-semibold text-gray-700 mb-3">End Date</label>
                                    <input type="date" id="end_date" name="end_date" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <p class="text-xs text-gray-500 mt-1">Must be after or equal to start date</p>
                                </div>
                            </div>
                            
                            <!-- Time Fields (Optional) -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="start_time" class="block text-sm font-semibold text-gray-700 mb-3">Start Time (Optional)</label>
                                    <input type="time" id="start_time" name="start_time" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <p class="text-xs text-gray-500 mt-1">Leave empty for all-day events</p>
                                </div>
                                
                                <div>
                                    <label for="end_time" class="block text-sm font-semibold text-gray-700 mb-3">End Time (Optional)</label>
                                    <input type="time" id="end_time" name="end_time" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <p class="text-xs text-gray-500 mt-1">Leave empty for all-day events</p>
                                </div>
                            </div>
                            
                            <div id="dateError" class="hidden p-3 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <p class="text-sm text-red-800 font-medium" id="dateErrorText"></p>
                                </div>
                            </div>
                            
                            <!-- Target Audience -->
                            <div>
                                <label for="target_audience" class="block text-sm font-semibold text-gray-700 mb-3">Target Audience</label>
                                <select id="target_audience" name="target_audience" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <option value="all">All (Residents and BHW)</option>
                                    <option value="residents">Residents Only</option>
                                    <option value="bhw">BHW Only</option>
                                </select>
                            </div>
                            
                            <!-- Location Filtering -->
                            <div id="locationFilterSection">
                                <label class="block text-sm font-semibold text-gray-700 mb-3">Location (Optional)</label>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="target_barangay_id" class="block text-sm font-medium text-gray-600 mb-2">Barangay</label>
                                        <select id="target_barangay_id" name="target_barangay_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                            <option value="">All Barangays</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo $barangay['id']; ?>"><?php echo htmlspecialchars($barangay['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="target_purok_id" class="block text-sm font-medium text-gray-600 mb-2">Purok</label>
                                        <select id="target_purok_id" name="target_purok_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" disabled>
                                            <option value="">All Puroks</option>
                                        </select>
                                        <p class="text-xs text-gray-500 mt-1">Select a barangay first</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-start space-x-3">
                                    <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-semibold text-blue-900 mb-1">Email Notifications</h4>
                                        <p class="text-sm text-blue-800">When you create this announcement, email notifications will be automatically sent to <strong><?php echo $emailUserCount; ?> users</strong> (BHW and Residents) with their email addresses on file.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-end space-x-4 mt-8 pt-6 border-t border-gray-200">
                            <button type="button" onclick="closeModal()" class="px-6 py-3 text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-all duration-200 font-medium">
                                Cancel
                            </button>
                            <button type="submit" class="btn-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span id="submitText">Create Announcement</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-semibold text-gray-900">Announcement Details</h3>
                        <button onclick="closeViewModal()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div id="viewContent" class="space-y-4">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Puroks data for dynamic filtering
        const puroksData = <?php echo json_encode($puroks); ?>;
        
        // Store announcements data globally for edit function
        const announcementsData = <?php echo json_encode($announcements, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        
        // Helper function to edit announcement by ID (used by buttons in the list)
        function editAnnouncementById(announcementId) {
            const announcement = announcementsData.find(a => a.id == announcementId);
            if (announcement) {
                editAnnouncement(announcement);
            } else {
                console.error('Announcement not found with ID:', announcementId);
                alert('Error: Announcement not found. Please refresh the page and try again.');
            }
        }
        
        // Initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                contentHeight: 'auto',
                aspectRatio: 1.2,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                events: [
                    <?php foreach ($announcements as $announcement): ?>
                    {
                        id: <?php echo $announcement['id']; ?>,
                        title: '<?php echo addslashes($announcement['title']); ?>',
                        start: '<?php echo $announcement['start_date']; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($announcement['end_date'] . ' +1 day')); ?>',
                        backgroundColor: '<?php echo $announcement['is_active'] ? '#3b82f6' : '#6b7280'; ?>',
                        borderColor: '<?php echo $announcement['is_active'] ? '#2563eb' : '#4b5563'; ?>',
                        textColor: '#ffffff'
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    const announcement = announcementsData.find(a => a.id == info.event.id);
                    if (announcement) {
                        viewAnnouncement(announcement);
                    }
                },
                dateClick: function(info) {
                    // Only allow clicking on today or future dates
                    const clickedDate = new Date(info.dateStr);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    if (clickedDate >= today) {
                        openCreateModal(info.dateStr);
                    } else {
                        alert('You cannot create announcements for past dates. Please select today or a future date.');
                    }
                },
                dayMaxEvents: 3,
                moreLinkClick: 'popover',
                eventDisplay: 'block',
                displayEventTime: false,
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    omitZeroMinute: true,
                    meridiem: 'short'
                }
            });
            calendar.render();
            
            // Store calendar instance globally for potential future use
            window.announcementCalendar = calendar;
        });

        // Modal functions
        function openCreateModal(selectedDate = null) {
            document.getElementById('modalTitle').textContent = 'Create Announcement';
            document.getElementById('formAction').value = 'create';
            document.getElementById('submitText').textContent = 'Create Announcement';
            document.getElementById('announcementForm').reset();
            document.getElementById('formId').value = '';
            
            // Reset target audience and location fields
            document.getElementById('target_audience').value = 'all';
            document.getElementById('target_barangay_id').value = '';
            document.getElementById('target_purok_id').innerHTML = '<option value="">All Puroks</option>';
            document.getElementById('target_purok_id').disabled = true;
            
            // Set minimum date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('start_date').min = today;
            document.getElementById('end_date').min = today;
            
            // If a date was clicked on the calendar, set it as the start date
            if (selectedDate) {
                document.getElementById('start_date').value = selectedDate;
                document.getElementById('end_date').value = selectedDate;
                document.getElementById('end_date').min = selectedDate;
            }
            
            // Hide any previous error messages
            document.getElementById('dateError').classList.add('hidden');
            
            const modal = document.getElementById('announcementModal');
            const modalContent = document.getElementById('modalContent');
            
            modal.classList.remove('hidden');
            
            // Animate modal in
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        function editAnnouncement(announcement) {
            // Validate announcement data
            if (!announcement || !announcement.id) {
                console.error('Invalid announcement data:', announcement);
                alert('Error: Invalid announcement data. Please try again.');
                return;
            }
            
            // Close view modal if open
            if (document.getElementById('viewModal') && !document.getElementById('viewModal').classList.contains('hidden')) {
                closeViewModal();
            }
            
            // Check if modal elements exist
            const modalTitle = document.getElementById('modalTitle');
            const formAction = document.getElementById('formAction');
            const submitText = document.getElementById('submitText');
            const formId = document.getElementById('formId');
            const titleInput = document.getElementById('title');
            const descriptionInput = document.getElementById('description');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            
            if (!modalTitle || !formAction || !submitText || !formId || !titleInput || !descriptionInput || !startDateInput || !endDateInput) {
                console.error('Required form elements not found');
                alert('Error: Form elements not found. Please refresh the page and try again.');
                return;
            }
            
            try {
                modalTitle.textContent = 'Edit Announcement';
                formAction.value = 'update';
                submitText.textContent = 'Update Announcement';
                formId.value = announcement.id;
                titleInput.value = announcement.title || '';
                descriptionInput.value = announcement.description || '';
                startDateInput.value = announcement.start_date || '';
                endDateInput.value = announcement.end_date || '';
                
                // Set time fields
                const startTimeInput = document.getElementById('start_time');
                const endTimeInput = document.getElementById('end_time');
                if (startTimeInput) {
                    startTimeInput.value = announcement.start_time || '';
                }
                if (endTimeInput) {
                    endTimeInput.value = announcement.end_time || '';
                }
            } catch (e) {
                console.error('Error setting form values:', e);
                alert('Error setting form values. Please try again.');
                return;
            }
            
            // Set target audience (default to 'all' if not set)
            const targetAudience = announcement.target_audience || 'all';
            document.getElementById('target_audience').value = targetAudience;
            
            // Handle barangay and purok (handle null values)
            const barangayId = (announcement.target_barangay_id && announcement.target_barangay_id !== null) ? String(announcement.target_barangay_id) : '';
            const purokId = (announcement.target_purok_id && announcement.target_purok_id !== null) ? String(announcement.target_purok_id) : '';
            
            document.getElementById('target_barangay_id').value = barangayId;
            
            // Populate puroks if barangay is selected
            if (barangayId) {
                // Wait a bit for the DOM to be ready, then update purok options
                setTimeout(function() {
                    updatePurokOptions(barangayId, purokId);
                }, 50);
            } else {
                document.getElementById('target_purok_id').innerHTML = '<option value="">All Puroks</option>';
                document.getElementById('target_purok_id').disabled = true;
                document.getElementById('target_purok_id').value = '';
            }
            
            // For editing, allow past dates if the announcement already has a past date
            // But set minimum to today for new dates
            const today = new Date().toISOString().split('T')[0];
            const announcementDate = new Date(announcement.start_date);
            const todayDate = new Date(today);
            
            // If announcement is in the past, allow editing it (set min to a very old date)
            // Otherwise, enforce today as minimum
            if (announcementDate < todayDate) {
                // Allow past dates for existing past announcements
                document.getElementById('start_date').removeAttribute('min');
            } else {
                // For future announcements, enforce today as minimum
                document.getElementById('start_date').min = today;
            }
            
            // Ensure end_date min is at least start_date
            if (announcement.start_date) {
                document.getElementById('end_date').min = announcement.start_date;
            } else {
                document.getElementById('end_date').min = today;
            }
            
            // Hide any previous error messages
            document.getElementById('dateError').classList.add('hidden');
            
            const modal = document.getElementById('announcementModal');
            const modalContent = document.getElementById('modalContent');
            
            modal.classList.remove('hidden');
            
            // Animate modal in
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
        
        // Function to update purok options based on selected barangay
        function updatePurokOptions(barangayId, selectedPurokId = '') {
            const purokSelect = document.getElementById('target_purok_id');
            if (!purokSelect) return;
            
            purokSelect.innerHTML = '<option value="">All Puroks</option>';
            
            if (barangayId) {
                // Convert to number for comparison
                const barangayIdNum = parseInt(barangayId);
                const selectedPurokIdNum = selectedPurokId ? parseInt(selectedPurokId) : null;
                
                const barangayPuroks = puroksData.filter(p => parseInt(p.barangay_id) === barangayIdNum);
                barangayPuroks.forEach(purok => {
                    const option = document.createElement('option');
                    option.value = purok.id;
                    option.textContent = purok.name;
                    if (selectedPurokIdNum && parseInt(purok.id) === selectedPurokIdNum) {
                        option.selected = true;
                    }
                    purokSelect.appendChild(option);
                });
                purokSelect.disabled = false;
                
                // Set the selected value if it exists
                if (selectedPurokIdNum) {
                    purokSelect.value = selectedPurokIdNum;
                }
            } else {
                purokSelect.disabled = true;
                purokSelect.value = '';
            }
        }

        function closeModal() {
            const modal = document.getElementById('announcementModal');
            const modalContent = document.getElementById('modalContent');
            
            // Animate modal out
            modalContent.classList.remove('scale-100', 'opacity-100');
            modalContent.classList.add('scale-95', 'opacity-0');
            
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function viewAnnouncement(announcement) {
            const content = `
                <div class="space-y-4">
                    <div class="flex items-center space-x-3">
                        <h4 class="text-2xl font-bold text-gray-900">${announcement.title}</h4>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${announcement.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">
                            ${announcement.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    
                    <div class="prose max-w-none">
                        <p class="text-gray-700 leading-relaxed">${announcement.description}</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-gray-200">
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Start Date${announcement.start_time ? ' & Time' : ''}</p>
                                <p class="font-medium text-gray-900">
                                    ${new Date(announcement.start_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                    ${announcement.start_time ? '<br><span class="text-sm text-gray-600">' + formatTime(announcement.start_time) + '</span>' : ''}
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">End Date${announcement.end_time ? ' & Time' : ''}</p>
                                <p class="font-medium text-gray-900">
                                    ${new Date(announcement.end_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                    ${announcement.end_time ? '<br><span class="text-sm text-gray-600">' + formatTime(announcement.end_time) + '</span>' : ''}
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Created By</p>
                                <p class="font-medium text-gray-900">${announcement.created_by_name}</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Created At</p>
                                <p class="font-medium text-gray-900">${new Date(announcement.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button onclick="closeViewModal(); editAnnouncementById(${announcement.id});" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Edit Announcement
                        </button>
                    </div>
                </div>
            `;
            
            document.getElementById('viewContent').innerHTML = content;
            document.getElementById('viewModal').classList.remove('hidden');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        function toggleStatus(id) {
            if (confirm('Are you sure you want to change the status of this announcement?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteAnnouncement(id) {
            if (confirm('Are you sure you want to delete this announcement? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modals when clicking outside
        document.getElementById('announcementModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
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

        // Add hover effects to announcement cards
        document.querySelectorAll('.announcement-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn-primary, button').forEach(button => {
            button.addEventListener('click', function(e) {
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

        // Date validation functions
        function validateDates() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const today = new Date().toISOString().split('T')[0];
            const errorDiv = document.getElementById('dateError');
            const errorText = document.getElementById('dateErrorText');
            
            // Hide error initially
            errorDiv.classList.add('hidden');
            
            if (startDate && endDate) {
                if (startDate < today) {
                    errorText.textContent = 'Start date cannot be in the past. Please select today or a future date.';
                    errorDiv.classList.remove('hidden');
                    return false;
                }
                
                if (endDate < startDate) {
                    errorText.textContent = 'End date must be after or equal to the start date.';
                    errorDiv.classList.remove('hidden');
                    return false;
                }
            }
            
            return true;
        }

        // Add date validation and barangay change event listeners (after DOM is loaded)
        setTimeout(function() {
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const barangaySelect = document.getElementById('target_barangay_id');
            
            if (startDateInput) {
                startDateInput.addEventListener('change', function() {
                    const startDate = this.value;
                    const today = new Date().toISOString().split('T')[0];
                    
                    // Ensure start date is not in the past
                    if (startDate && startDate < today) {
                        this.value = today;
                        const errorDiv = document.getElementById('dateError');
                        const errorText = document.getElementById('dateErrorText');
                        errorText.textContent = 'Start date cannot be in the past. Date has been set to today.';
                        errorDiv.classList.remove('hidden');
                        setTimeout(() => errorDiv.classList.add('hidden'), 5000);
                    }
                    
                    if (startDate && endDateInput) {
                endDateInput.min = startDate;
                if (endDateInput.value && endDateInput.value < startDate) {
                    endDateInput.value = startDate;
                }
            }
            validateDates();
        });
            }
            
            if (endDateInput) {
                endDateInput.addEventListener('change', validateDates);
            }
            
            // Time input validation
            const startTimeInput = document.getElementById('start_time');
            const endTimeInput = document.getElementById('end_time');
            
            if (startTimeInput) {
                startTimeInput.addEventListener('change', function() {
                    validateDates();
                    // If start date and end date are the same, ensure end time is after start time
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;
                    if (startDate === endDate && this.value && endTimeInput.value && this.value > endTimeInput.value) {
                        endTimeInput.value = this.value;
                    }
                });
            }
            
            if (endTimeInput) {
                endTimeInput.addEventListener('change', validateDates);
            }
            
            // Barangay change event listener
            if (barangaySelect) {
                barangaySelect.addEventListener('change', function() {
                    const barangayId = this.value;
                    updatePurokOptions(barangayId);
                });
            }
        }, 100);

        // Add loading states to forms
        setTimeout(function() {
            const form = document.getElementById('announcementForm');
            if (form) {
                form.addEventListener('submit', function(e) {
            // Validate dates before submitting
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;
                    const today = new Date().toISOString().split('T')[0];
                    
                    // Prevent past dates
                    if (startDate < today) {
                        e.preventDefault();
                        const errorDiv = document.getElementById('dateError');
                        const errorText = document.getElementById('dateErrorText');
                        errorText.textContent = 'Start date cannot be in the past. Please select today or a future date.';
                        errorDiv.classList.remove('hidden');
                        return false;
                    }
                    
            if (!validateDates()) {
                e.preventDefault();
                return false;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> Processing...';
            submitBtn.disabled = true;
            
            // Re-enable after 3 seconds (fallback)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
            }
        }, 100);

        // Function to check if announcement is in the past
        function isAnnouncementPast(announcement) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const endDate = new Date(announcement.end_date);
            return endDate < today;
        }

        // Make past announcements unclickable and add visual indicators
        document.addEventListener('DOMContentLoaded', function() {
            const announcementCards = document.querySelectorAll('.announcement-card');
            announcementCards.forEach(card => {
                const onclick = card.getAttribute('onclick');
                if (onclick) {
                    // Extract announcement data from onclick attribute
                    const match = onclick.match(/viewAnnouncement\(([^)]+)\)/);
                    if (match) {
                        try {
                            const announcementData = JSON.parse(match[1]);
                            if (isAnnouncementPast(announcementData)) {
                                // Make past announcements unclickable
                                card.removeAttribute('onclick');
                                card.style.cursor = 'not-allowed';
                                card.style.opacity = '0.6';
                                
                                // Add past indicator
                                const statusBadge = card.querySelector('.inline-flex');
                                if (statusBadge) {
                                    statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r from-gray-100 to-slate-100 text-gray-600 border border-gray-200';
                                    statusBadge.innerHTML = `
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Past Event
                                    `;
                                }
                                
                                // Add tooltip
                                card.title = 'This announcement has ended and is no longer active';
                            }
                        } catch (e) {
                            console.log('Could not parse announcement data');
                        }
                    }
                }
            });
        });

        // Function to open announcements list modal
        function openAnnouncementsModal() {
            document.getElementById('announcementsListModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        // Function to close announcements list modal
        function closeAnnouncementsModal() {
            document.getElementById('announcementsListModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
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
    </script>

    <!-- Announcements List Modal -->
    <div id="announcementsListModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 modal-backdrop">
        <div class="flex items-center justify-center min-h-screen" style="margin-left: 280px; width: calc(100% - 280px);">
            <div class="modal-content w-full max-h-[90vh] rounded-2xl overflow-hidden mx-4">
                <div class="p-6 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-purple-50">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">All Announcements</h3>
                            <p class="text-gray-600 mt-1">Manage your health center activities and announcements</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="text-right">
                                <div class="text-sm text-gray-500">Total</div>
                                <div class="text-xl font-bold text-gray-900"><?php echo count($announcements); ?></div>
                            </div>
                            <button onclick="closeAnnouncementsModal()" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition-all duration-300">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="p-6 max-h-[70vh] overflow-y-auto">
                    <div class="space-y-4">
                        <?php if (empty($announcements)): ?>
                            <div class="text-center py-16 empty-state rounded-2xl">
                                <div class="relative z-10">
                                    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-lg">
                                        <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                                        </svg>
                                    </div>
                                    <h4 class="text-2xl font-bold text-gray-900 mb-3">No announcements yet</h4>
                                    <p class="text-gray-500 mb-8 text-lg">Create your first announcement to start communicating with your community</p>
                                    <button onclick="openCreateModal(); closeAnnouncementsModal();" class="btn-primary hover-lift group">
                                        <svg class="w-5 h-5 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                        </svg>
                                        Create First Announcement
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($announcements as $index => $announcement): ?>
                                <div class="announcement-card p-6 bg-gradient-to-r from-white to-gray-50 border border-gray-200 rounded-xl hover:shadow-lg transition-all duration-300 animate-fade-in-up" style="animation-delay: <?php echo ($index + 1) * 0.1; ?>s">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-3">
                                                <h4 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                                <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium status-badge <?php echo $announcement['is_active'] ? 'bg-gradient-to-r from-green-100 to-emerald-100 text-green-800 border border-green-200' : 'bg-gradient-to-r from-gray-100 to-slate-100 text-gray-800 border border-gray-200'; ?>">
                                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <?php if ($announcement['is_active']): ?>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        <?php else: ?>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        <?php endif; ?>
                                                    </svg>
                                                    <?php echo $announcement['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                            <p class="text-gray-600 mb-4 line-clamp-3"><?php echo htmlspecialchars($announcement['description']); ?></p>
                                            <div class="flex items-center space-x-6 text-sm text-gray-500">
                                                <div class="flex items-center space-x-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span><?php echo date('M j, Y', strtotime($announcement['start_date'])); ?></span>
                                                    <?php if ($announcement['start_date'] !== $announcement['end_date']): ?>
                                                        <span>to</span>
                                                        <span><?php echo date('M j, Y', strtotime($announcement['end_date'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                    </svg>
                                                    <span>by <?php echo htmlspecialchars($announcement['created_by_name']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2 ml-4">
                                            <button onclick="viewAnnouncement(<?php echo htmlspecialchars(json_encode($announcement)); ?>); closeAnnouncementsModal();" class="action-btn p-3 text-blue-600 hover:bg-blue-50 rounded-xl transition-all duration-300 hover:scale-110" title="View Details">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="editAnnouncementById(<?php echo $announcement['id']; ?>); closeAnnouncementsModal();" class="action-btn p-3 text-yellow-600 hover:bg-yellow-50 rounded-xl transition-all duration-300 hover:scale-110" title="Edit">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="toggleStatus(<?php echo $announcement['id']; ?>)" class="action-btn p-3 <?php echo $announcement['is_active'] ? 'text-orange-600 hover:bg-orange-50' : 'text-green-600 hover:bg-green-50'; ?> rounded-xl transition-all duration-300 hover:scale-110" title="<?php echo $announcement['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <?php if ($announcement['is_active']): ?>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    <?php else: ?>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    <?php endif; ?>
                                                </svg>
                                            </button>
                                            <button onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>)" class="action-btn p-3 text-red-600 hover:bg-red-50 rounded-xl transition-all duration-300 hover:scale-110" title="Delete">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
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
            // Initialize profile dropdown
            initProfileDropdown();
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>
