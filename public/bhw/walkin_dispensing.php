<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);

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

$bhw_purok_id = $user['purok_id'] ?? 0;

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Fetch pending requests for notifications
try {
    $stmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name as medicine_name, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id LEFT JOIN residents res ON r.resident_id = res.id WHERE r.status = "submitted" AND res.purok_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_requests_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_requests_list = [];
}

// Fetch pending registrations for notifications
try {
    $stmt = db()->prepare('SELECT id, first_name, last_name, created_at FROM pending_residents WHERE purok_id = ? AND status = "pending" ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_registrations_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_registrations_list = [];
}

// Fetch pending family additions for notifications
try {
    $stmt = db()->prepare('SELECT rfa.id, rfa.first_name, rfa.last_name, rfa.created_at FROM resident_family_additions rfa JOIN residents res ON res.id = rfa.resident_id WHERE res.purok_id = ? AND rfa.status = "pending" ORDER BY rfa.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_family_additions_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_family_additions_list = [];
}

// Handle walk-in dispensing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'dispense') {
        $resident_id = (int)($_POST['resident_id'] ?? 0);
        $medicine_id = (int)($_POST['medicine_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_relationship = trim($_POST['patient_relationship'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        
        // For walk-in residents without accounts
        $walkin_name = trim($_POST['walkin_name'] ?? '');
        $walkin_birthdate = trim($_POST['walkin_birthdate'] ?? '');
        $walkin_address = trim($_POST['walkin_address'] ?? '');
        
        // Validate required fields based on resident type
        $resident_type = $_POST['resident_type'] ?? 'registered';
        $is_valid = false;
        
        if ($resident_type === 'registered') {
            // For registered residents, resident_id must be provided
            $is_valid = ($resident_id > 0 && $medicine_id > 0 && $quantity > 0);
        } else {
            // For walk-in residents, walkin_name must be provided
            $is_valid = (!empty($walkin_name) && $medicine_id > 0 && $quantity > 0);
        }
        
        if ($is_valid) {
            try {
                // Check medicine availability from batches
                $check_med = db()->prepare('SELECT m.name, COALESCE(SUM(mb.quantity_available), 0) as total_stock FROM medicines m LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id WHERE m.id = ? AND mb.expiry_date > CURDATE() GROUP BY m.id');
                $check_med->execute([$medicine_id]);
                $medicine = $check_med->fetch();
                
                if ($medicine && $medicine['total_stock'] >= $quantity) {
                    // Handle walk-in residents without accounts
                    if ($resident_type === 'walkin' && $resident_id == 0 && !empty($walkin_name)) {
                        // Check if walk-in resident already exists
                        $existing_walkin_stmt = db()->prepare('
                            SELECT id FROM residents 
                            WHERE first_name = ? AND last_name = "Walk-in" AND purok_id = ?
                            ORDER BY created_at DESC 
                            LIMIT 1
                        ');
                        $existing_walkin_stmt->execute([$walkin_name, $bhw_purok_id]);
                        $existing_walkin = $existing_walkin_stmt->fetch();
                        
                        if ($existing_walkin) {
                            // Use existing walk-in resident
                            $resident_id = $existing_walkin['id'];
                        } else {
                            // Get barangay_id from purok
                            $purok_query = db()->prepare('SELECT barangay_id FROM puroks WHERE id = ?');
                            $purok_query->execute([$bhw_purok_id]);
                            $purok_data = $purok_query->fetch();
                            $barangay_id = $purok_data['barangay_id'] ?? 1;
                            
                            // Create new temporary resident record for walk-in
                            $temp_resident_stmt = db()->prepare('
                                INSERT INTO residents (first_name, last_name, date_of_birth, barangay_id, purok_id, created_at)
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ');
                            $temp_resident_stmt->execute([
                                $walkin_name,
                                'Walk-in',
                                $walkin_birthdate ?: '1900-01-01',
                                $barangay_id,
                                $bhw_purok_id
                            ]);
                            $resident_id = db()->lastInsertId();
                        }
                    }
                    
                    if ($resident_id > 0) {
                        // Determine requested_for and patient details based on relationship
                        $is_self = empty($patient_relationship) || strtolower(trim($patient_relationship)) === 'self';
                        
                        if ($is_self) {
                            // Request is for the resident themselves
                            $requested_for = 'self';
                            // For registered residents, patient_name should be null when for self
                            // For walk-in residents, use walkin_name as patient_name
                            $final_patient_name = ($resident_type === 'walkin' && !empty($walkin_name)) ? $walkin_name : null;
                            $final_relationship = null;
                        } else {
                            // Request is for a family member
                            $requested_for = 'family';
                            // Use provided patient_name, or walkin_name for walk-in residents if no patient_name
                            $final_patient_name = !empty($patient_name) ? $patient_name : (($resident_type === 'walkin' && !empty($walkin_name)) ? $walkin_name : null);
                            $final_relationship = $patient_relationship;
                        }
                        
                        // Create instant request and mark as approved/dispensed
                        $request_stmt = db()->prepare('
                            INSERT INTO requests (resident_id, medicine_id, requested_for, patient_name, relationship, reason, status, bhw_id, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, "claimed", ?, NOW(), NOW())
                        ');
                        $request_stmt->execute([
                            $resident_id, 
                            $medicine_id, 
                            $requested_for,
                            $final_patient_name,
                            $final_relationship,
                            !empty($reason) ? $reason : null,
                            $user['id']
                        ]);
                        
                        $request_id = (int)db()->lastInsertId();
                        
                        // Use FEFO allocation to properly manage batches
                        require_once __DIR__ . '/../../config/db.php';
                        $allocated = fefoAllocate($medicine_id, $quantity, $request_id);
                        
                        if ($allocated >= $quantity) {
                            $resident_type_text = ($resident_type === 'walkin') ? 'Walk-in resident' : 'Registered resident';
                            $_SESSION['flash_message'] = "Medicine dispensed successfully to {$resident_type_text}!";
                            $_SESSION['flash_type'] = 'success';
                        } else {
                            $_SESSION['flash_message'] = 'Insufficient stock in batches!';
                            $_SESSION['flash_type'] = 'error';
                        }
                    } else {
                        $_SESSION['flash_message'] = 'Please provide resident information!';
                        $_SESSION['flash_type'] = 'error';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Insufficient medicine stock!';
                    $_SESSION['flash_type'] = 'error';
                }
            } catch (Exception $e) {
                $_SESSION['flash_message'] = 'Error dispensing medicine: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            if ($resident_type === 'registered') {
                $_SESSION['flash_message'] = 'Please select a registered resident and fill in all required fields!';
            } else {
                $_SESSION['flash_message'] = 'Please provide walk-in resident name and fill in all required fields!';
            }
            $_SESSION['flash_type'] = 'error';
        }
        
        redirect_to('bhw/walkin_dispensing.php');
    }
}

// Fetch available medicines with stock from batches
try {
    $medicines = db()->query('
        SELECT m.id, m.name, COALESCE(SUM(mb.quantity_available), 0) as current_stock 
        FROM medicines m 
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
        WHERE mb.expiry_date > CURDATE() OR mb.expiry_date IS NULL
        GROUP BY m.id, m.name 
        HAVING current_stock > 0 
        ORDER BY m.name ASC
    ')->fetchAll();
} catch (Exception $e) {
    $medicines = [];
}

// Fetch residents in this BHW's purok
try {
    // Debug: Check if BHW has a valid purok_id
    if ($bhw_purok_id > 0) {
        $residents_stmt = db()->prepare('
            SELECT r.id, CONCAT(IFNULL(r.first_name, ""), " ", IFNULL(r.last_name, "")) as name, r.purok_id
            FROM residents r 
            WHERE r.purok_id = ? 
            ORDER BY r.first_name ASC
        ');
        $residents_stmt->execute([$bhw_purok_id]);
        $residents = $residents_stmt->fetchAll();
    } else {
        // Fallback: Get all residents if BHW doesn't have a purok_id
        $residents_stmt = db()->prepare('
            SELECT r.id, CONCAT(IFNULL(r.first_name, ""), " ", IFNULL(r.last_name, "")) as name, r.purok_id
            FROM residents r 
            ORDER BY r.first_name ASC
        ');
        $residents_stmt->execute();
        $residents = $residents_stmt->fetchAll();
    }
} catch (Exception $e) {
    $residents = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Walk-in Medicine Dispensing · BHW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
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
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Content Header Styles */
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
        
        .notification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.375rem;
            font-size: 0.6875rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 9999px;
            margin-left: auto;
            animation: pulse-badge 2s ease-in-out infinite;
        }
        
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
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
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
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
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.4);
        }
        .btn-success:active {
            transform: translateY(0);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
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
            <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span style="flex: 1;">Medicine Requests</span>
                <?php if ($notification_counts['pending_requests'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
                <?php endif; ?>
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/walkin_dispensing.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Walk-in Dispensing
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Residents & Family
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Registrations</span>
                <?php if ($notification_counts['pending_registrations'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_registrations']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Family Additions</span>
                <?php if (!empty($notification_counts['pending_family_additions'])): ?>
                    <span class="notification-badge"><?php echo (int)$notification_counts['pending_family_additions']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 01-2 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Statistics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="flex items-center mb-3">
                <div class="flex-shrink-0">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                             alt="Profile" 
                             class="w-10 h-10 rounded-full object-cover border-2 border-purple-500"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500 hidden">
                            <?php 
                            $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                            $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                            echo strtoupper($firstInitial . $lastInitial); 
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                            <?php 
                            $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                            $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                            echo strtoupper($firstInitial . $lastInitial); 
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">
                        <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'BHW') . ' ' . ($user['last_name'] ?? 'Worker'))); ?>
                    </p>
                    <p class="text-xs text-gray-600 truncate">
                        <?php echo htmlspecialchars($user['email'] ?? 'bhw@example.com'); ?>
                    </p>
                </div>
            </div>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="flex items-center justify-center w-full px-4 py-2 text-sm text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <?php 
        require_once __DIR__ . '/includes/header.php';
        render_bhw_header([
            'user_data' => $user_data,
            'notification_counts' => $notification_counts,
            'pending_requests' => $pending_requests_list,
            'pending_registrations' => $pending_registrations_list,
            'pending_family_additions' => $pending_family_additions_list
        ]);
        ?>
        
        <div class="p-6">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Walk-in Medicine Dispensing</h1>
            <p class="text-gray-600">Immediate medicine dispensing for both registered and walk-in residents</p>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <?php 
            $flash_msg = $_SESSION['flash_message'];
            $flash_type = $_SESSION['flash_type'] ?? 'info';
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            ?>
            <div class="mb-6 p-4 rounded-xl <?php 
                echo ($flash_type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 
                ($flash_type === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 
                'bg-red-100 text-red-800 border border-red-200')); 
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
            <!-- Walk-in Dispensing Form -->
            <div class="mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Dispense Medicine</h3>
                            <p class="text-gray-600">Fill out the form below to dispense medicine immediately to residents (registered or walk-in)</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="dispense">
                        
                        <!-- Resident Type Selection -->
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-gray-700 mb-3">Resident Type</label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input type="radio" name="resident_type" value="registered" checked class="mr-2" onchange="toggleResidentType()">
                                    <span class="text-gray-700">Registered Resident</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="resident_type" value="walkin" class="mr-2" onchange="toggleResidentType()">
                                    <span class="text-gray-700">Walk-in Resident (No Account)</span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Registered Resident Selection -->
                        <div id="registered-resident-section" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="resident_id" class="block text-sm font-semibold text-gray-700 mb-3">Select Registered Resident <span class="text-red-500">*</span></label>
                                <select id="resident_id" name="resident_id" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <option value="">Select resident</option>
                                    <?php foreach ($residents as $resident): ?>
                                        <option value="<?php echo $resident['id']; ?>"><?php echo htmlspecialchars($resident['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Walk-in Resident Information -->
                        <div id="walkin-resident-section" class="hidden grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="walkin_name" class="block text-sm font-semibold text-gray-700 mb-3">Resident Name <span class="text-red-500">*</span></label>
                                <input type="text" id="walkin_name" name="walkin_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" placeholder="Enter resident's full name">
                            </div>
                            
                            <div>
                                <label for="walkin_birthdate" class="block text-sm font-semibold text-gray-700 mb-3">Birth Date</label>
                                <input type="date" id="walkin_birthdate" name="walkin_birthdate" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="walkin_address" class="block text-sm font-semibold text-gray-700 mb-3">Address</label>
                                <input type="text" id="walkin_address" name="walkin_address" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" placeholder="Enter address">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Medicine Selection -->
                            <div>
                                <label for="medicine_id" class="block text-sm font-semibold text-gray-700 mb-3">Medicine <span class="text-red-500">*</span></label>
                                <select id="medicine_id" name="medicine_id" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <option value="">Select medicine</option>
                                    <?php foreach ($medicines as $medicine): ?>
                                        <option value="<?php echo $medicine['id']; ?>" data-stock="<?php echo $medicine['current_stock']; ?>">
                                            <?php echo htmlspecialchars($medicine['name']); ?> (<?php echo $medicine['current_stock']; ?> units available)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Quantity -->
                            <div>
                                <label for="quantity" class="block text-sm font-semibold text-gray-700 mb-3">Quantity <span class="text-red-500">*</span></label>
                                <input type="number" id="quantity" name="quantity" min="1" max="10" value="1" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                <p class="text-xs text-gray-500 mt-1" id="quantity-help">Select medicine first to see available stock</p>
                            </div>
                            
                            <!-- Patient Name -->
                            <div>
                                <label for="patient_name" class="block text-sm font-semibold text-gray-700 mb-3">Patient Name</label>
                                <input type="text" id="patient_name" name="patient_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" placeholder="Leave empty if for resident">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Patient Relationship -->
                            <div>
                                <label for="patient_relationship" class="block text-sm font-semibold text-gray-700 mb-3">Patient Relationship</label>
                                <select id="patient_relationship" name="patient_relationship" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400">
                                    <?php echo get_relationship_options(); ?>
                                </select>
                            </div>
                            
                            <!-- Reason -->
                            <div>
                                <label for="reason" class="block text-sm font-semibold text-gray-700 mb-3">Reason</label>
                                <input type="text" id="reason" name="reason" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400" placeholder="Brief reason for dispensing">
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                            <button type="button" onclick="resetForm()" class="px-6 py-3 text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-all duration-200 font-medium">
                                Reset Form
                            </button>
                            <button type="submit" class="btn-success">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Dispense Medicine
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Available Medicines -->
            <div class="mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Available Medicines</h3>
                            <p class="text-gray-600">Current stock levels for immediate dispensing</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($medicines as $medicine): ?>
                            <div class="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200 hover:shadow-lg transition-all duration-300">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($medicine['name']); ?></h4>
                                    <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full">
                                        <?php echo $medicine['current_stock']; ?> units
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600">Available for immediate dispensing</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Toggle between registered and walk-in resident sections
        function toggleResidentType() {
            const residentType = document.querySelector('input[name="resident_type"]:checked').value;
            const registeredSection = document.getElementById('registered-resident-section');
            const walkinSection = document.getElementById('walkin-resident-section');
            const residentSelect = document.getElementById('resident_id');
            const walkinName = document.getElementById('walkin_name');
            
            if (residentType === 'registered') {
                registeredSection.classList.remove('hidden');
                walkinSection.classList.add('hidden');
                residentSelect.required = true;
                walkinName.required = false;
                // Clear walk-in fields when switching to registered
                walkinName.value = '';
                document.getElementById('walkin_birthdate').value = '';
                document.getElementById('walkin_address').value = '';
            } else {
                registeredSection.classList.add('hidden');
                walkinSection.classList.remove('hidden');
                residentSelect.required = false;
                walkinName.required = true;
                // Clear registered resident selection when switching to walk-in
                residentSelect.value = '';
            }
        }
        
        // Update quantity help text when medicine is selected
        document.getElementById('medicine_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const stock = selectedOption.getAttribute('data-stock');
            const quantityHelp = document.getElementById('quantity-help');
            const quantityInput = document.getElementById('quantity');
            
            if (stock) {
                quantityHelp.textContent = `Available stock: ${stock} units`;
                quantityInput.max = stock;
            } else {
                quantityHelp.textContent = 'Select medicine first to see available stock';
                quantityInput.max = 10;
            }
        });
        
        function resetForm() {
            // Reset radio buttons to registered
            document.querySelector('input[name="resident_type"][value="registered"]').checked = true;
            toggleResidentType();
            
            // Reset all form fields
            document.getElementById('resident_id').value = '';
            document.getElementById('walkin_name').value = '';
            document.getElementById('walkin_birthdate').value = '';
            document.getElementById('walkin_address').value = '';
            document.getElementById('medicine_id').value = '';
            document.getElementById('quantity').value = '1';
            document.getElementById('patient_name').value = '';
            document.getElementById('patient_relationship').value = '';
            document.getElementById('reason').value = '';
            document.getElementById('quantity-help').textContent = 'Select medicine first to see available stock';
        }
        
        function refreshData() {
            location.reload();
        }
        
        // Old time update, night mode, and profile dropdown code removed - now handled by header include
        
        // Form validation before submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const residentType = document.querySelector('input[name="resident_type"]:checked').value;
            const residentSelect = document.getElementById('resident_id');
            const walkinName = document.getElementById('walkin_name');
            const medicineSelect = document.getElementById('medicine_id');
            const quantityInput = document.getElementById('quantity');
            
            let isValid = true;
            let errorMessage = '';
            
            // Check medicine selection
            if (!medicineSelect.value) {
                isValid = false;
                errorMessage = 'Please select a medicine.';
            }
            
            // Check quantity
            if (!quantityInput.value || quantityInput.value <= 0) {
                isValid = false;
                errorMessage = 'Please enter a valid quantity.';
            }
            
            // Check resident selection based on type
            if (residentType === 'registered') {
                if (!residentSelect.value) {
                    isValid = false;
                    errorMessage = 'Please select a registered resident.';
                }
            } else {
                if (!walkinName.value.trim()) {
                    isValid = false;
                    errorMessage = 'Please enter the walk-in resident name.';
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                alert(errorMessage);
                return false;
            }
            
            return true;
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
    </script>
</body>
</html>
