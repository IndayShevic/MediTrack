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
        
        $resident_id = (int)($_POST['resident_id'] ?? 0);
        $medicine_id = (int)($_POST['medicine_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        $patient_name = trim($_POST['patient_name'] ?? '');
        $patient_relationship = trim($_POST['patient_relationship'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        
        // For walk-in residents without accounts
        $walkin_name = trim($_POST['walkin_name'] ?? '');
        $walkin_birthdate = trim($_POST['walkin_birthdate'] ?? '');
        $walkin_barangay_id = (int)($_POST['walkin_barangay_id'] ?? 0);
        $walkin_purok_id = (int)($_POST['walkin_purok_id'] ?? 0);
        
        $errors = [];
        $resident_type = $_POST['resident_type'] ?? 'registered';
        
        // Validate walk-in name (only letters, spaces, hyphens, apostrophes)
        if ($resident_type === 'walkin') {
            if (empty($walkin_name) || strlen($walkin_name) < 2) {
                $errors[] = 'Walk-in resident name must be at least 2 characters long.';
            } elseif (preg_match('/\d/', $walkin_name)) {
                $errors[] = 'Walk-in name: Only letters, spaces, hyphens, and apostrophes are allowed.';
            } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $walkin_name)) {
                $errors[] = 'Walk-in name: Only letters, spaces, hyphens, and apostrophes are allowed.';
            } else {
                $walkin_name = sanitizeInputBackend($walkin_name, 'A-Za-zÀ-ÿ\' -');
            }
            
            // Validate walk-in birthdate (cannot be in future)
            if (!empty($walkin_birthdate)) {
                $birthDate = new DateTime($walkin_birthdate);
                $today = new DateTime();
                if ($birthDate > $today) {
                    $errors[] = 'Walk-in birth date cannot be in the future.';
                }
            }
            
            // Validate walk-in barangay and purok
            if ($walkin_barangay_id <= 0) {
                $errors[] = 'Please select a barangay for walk-in resident.';
            }
            if ($walkin_purok_id <= 0) {
                $errors[] = 'Please select a purok for walk-in resident.';
            }
        }
        
        // Validate patient name (only letters, spaces, hyphens, apostrophes)
        if (!empty($patient_name)) {
            if (strlen($patient_name) < 2) {
                $errors[] = 'Patient name must be at least 2 characters long.';
            } elseif (preg_match('/\d/', $patient_name)) {
                $errors[] = 'Patient name: Only letters, spaces, hyphens, and apostrophes are allowed.';
            } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $patient_name)) {
                $errors[] = 'Patient name: Only letters, spaces, hyphens, and apostrophes are allowed.';
            } else {
                $patient_name = sanitizeInputBackend($patient_name, 'A-Za-zÀ-ÿ\' -');
            }
        }
        
        // Validate reason (allow letters, numbers, spaces, common punctuation)
        if (!empty($reason)) {
            $reason = sanitizeInputBackend($reason, 'A-Za-zÀ-ÿ0-9\s\-\'.,!?');
        }
        
        // Validate required fields based on resident type
        $is_valid = false;
        
        if (empty($errors)) {
            if ($resident_type === 'registered') {
                // For registered residents, resident_id must be provided
                $is_valid = ($resident_id > 0 && $medicine_id > 0 && $quantity > 0);
            } else {
                // For walk-in residents, walkin_name must be provided
                $is_valid = (!empty($walkin_name) && $medicine_id > 0 && $quantity > 0);
            }
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
                            // Use selected barangay and purok for walk-in resident
                            $barangay_id = $walkin_barangay_id > 0 ? $walkin_barangay_id : $bhw_purok_id;
                            
                            // If purok not selected, get default from BHW's purok
                            if ($walkin_purok_id <= 0) {
                                $purok_query = db()->prepare('SELECT barangay_id FROM puroks WHERE id = ?');
                                $purok_query->execute([$bhw_purok_id]);
                                $purok_data = $purok_query->fetch();
                                $barangay_id = $purok_data['barangay_id'] ?? 1;
                                $walkin_purok_id = $bhw_purok_id;
                            }
                            
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
                                $walkin_purok_id
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
            if (!empty($errors)) {
                $_SESSION['flash_message'] = implode(' ', $errors);
            } elseif ($resident_type === 'registered') {
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

// Fetch barangays and puroks for dropdowns
try {
    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
    $puroks = db()->query('SELECT id, name, barangay_id FROM puroks ORDER BY barangay_id, name')->fetchAll();
} catch (Exception $e) {
    $barangays = [];
    $puroks = [];
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
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

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
                            
                            <div>
                                <label for="walkin_barangay_id" class="block text-sm font-semibold text-gray-700 mb-3">Barangay <span class="text-red-500">*</span></label>
                                <select id="walkin_barangay_id" name="walkin_barangay_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400 appearance-none" style="padding-right: 3.5rem !important;">
                                    <option value="">Select Barangay</option>
                                    <?php foreach ($barangays as $barangay): ?>
                                        <option value="<?php echo (int)$barangay['id']; ?>"><?php echo htmlspecialchars($barangay['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="relative -mt-8 pointer-events-none flex items-center justify-end pr-3">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                            
                            <div>
                                <label for="walkin_purok_id" class="block text-sm font-semibold text-gray-700 mb-3">Purok <span class="text-red-500">*</span></label>
                                <select id="walkin_purok_id" name="walkin_purok_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 hover:border-gray-400 appearance-none" style="padding-right: 3.5rem !important;" disabled>
                                    <option value="">Select Barangay first</option>
                                </select>
                                <div class="relative -mt-8 pointer-events-none flex items-center justify-end pr-3">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
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
                document.getElementById('walkin_barangay_id').value = '';
                document.getElementById('walkin_purok_id').value = '';
                document.getElementById('walkin_purok_id').innerHTML = '<option value="">Select Barangay first</option>';
                document.getElementById('walkin_purok_id').disabled = true;
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
            document.getElementById('walkin_barangay_id').value = '';
            document.getElementById('walkin_purok_id').value = '';
            document.getElementById('walkin_purok_id').innerHTML = '<option value="">Select Barangay first</option>';
            document.getElementById('walkin_purok_id').disabled = true;
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
        
        // Walk-in Name: Only letters, spaces, hyphens, apostrophes
        const walkinNameInput = document.getElementById('walkin_name');
        if (walkinNameInput) {
            walkinNameInput.addEventListener('input', function(e) {
                filterInput(this, 'A-Za-zÀ-ÿ\\s\\-\'');
            });
            
            walkinNameInput.addEventListener('keypress', function(e) {
                // Allow: letters, space, hyphen, apostrophe, backspace, delete, tab, arrow keys
                const char = String.fromCharCode(e.which || e.keyCode);
                if (!/[A-Za-zÀ-ÿ\s\-\']/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                    e.preventDefault();
                }
            });
            
            walkinNameInput.addEventListener('paste', function(e) {
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
        
        // Patient Name: Only letters, spaces, hyphens, apostrophes
        const patientNameInput = document.getElementById('patient_name');
        if (patientNameInput) {
            patientNameInput.addEventListener('input', function(e) {
                filterInput(this, 'A-Za-zÀ-ÿ\\s\\-\'');
            });
            
            patientNameInput.addEventListener('keypress', function(e) {
                // Allow: letters, space, hyphen, apostrophe, backspace, delete, tab, arrow keys
                const char = String.fromCharCode(e.which || e.keyCode);
                if (!/[A-Za-zÀ-ÿ\s\-\']/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                    e.preventDefault();
                }
            });
            
            patientNameInput.addEventListener('paste', function(e) {
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
        
        // Walk-in Birthdate: Prevent future dates
        const walkinBirthdateInput = document.getElementById('walkin_birthdate');
        if (walkinBirthdateInput) {
            walkinBirthdateInput.addEventListener('change', function(e) {
                const selectedDate = new Date(this.value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                if (selectedDate > today) {
                    alert('Birth date cannot be in the future.');
                    this.value = '';
                    this.focus();
                }
            });
        }
        
        // Barangay-Purok relationship data
        const puroksData = <?php echo json_encode($puroks); ?>;
        
        // Update purok options based on selected barangay
        function updateWalkinPurokOptions(barangayId) {
            const purokSelect = document.getElementById('walkin_purok_id');
            if (!purokSelect) return;
            
            // Clear existing options
            purokSelect.innerHTML = '<option value="">Select Purok</option>';
            
            if (!barangayId) {
                purokSelect.disabled = true;
                purokSelect.innerHTML = '<option value="">Select Barangay first</option>';
                return;
            }
            
            // Filter puroks by selected barangay
            const filteredPuroks = puroksData.filter(purok => purok.barangay_id == barangayId);
            
            if (filteredPuroks.length === 0) {
                purokSelect.disabled = true;
                purokSelect.innerHTML = '<option value="">No puroks available</option>';
                return;
            }
            
            // Enable and populate purok select
            purokSelect.disabled = false;
            filteredPuroks.forEach(purok => {
                const option = document.createElement('option');
                option.value = purok.id;
                option.textContent = purok.name;
                purokSelect.appendChild(option);
            });
        }
        
        // Initialize barangay-purok relationship for walk-in
        const walkinBarangaySelect = document.getElementById('walkin_barangay_id');
        if (walkinBarangaySelect) {
            walkinBarangaySelect.addEventListener('change', function() {
                updateWalkinPurokOptions(this.value);
            });
        }
        
        // Reason: Allow letters, numbers, spaces, common punctuation
        const reasonInput = document.getElementById('reason');
        if (reasonInput) {
            reasonInput.addEventListener('input', function(e) {
                filterInput(this, 'A-Za-zÀ-ÿ0-9\\s\\-\'.,!?');
            });
            
            reasonInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const filtered = pastedText.replace(/[^A-Za-zÀ-ÿ0-9\s\-\'.,!?]/g, '');
                const cursorPos = this.selectionStart;
                const textBefore = this.value.substring(0, cursorPos);
                const textAfter = this.value.substring(this.selectionEnd);
                this.value = textBefore + filtered + textAfter;
                const newPos = cursorPos + filtered.length;
                this.setSelectionRange(newPos, newPos);
            });
        }
        
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
                } else if (walkinName.value.trim().length < 2) {
                    isValid = false;
                    errorMessage = 'Walk-in resident name must be at least 2 characters long.';
                }
                
                const walkinBarangay = document.getElementById('walkin_barangay_id');
                const walkinPurok = document.getElementById('walkin_purok_id');
                if (!walkinBarangay || !walkinBarangay.value) {
                    isValid = false;
                    errorMessage = 'Please select a barangay for walk-in resident.';
                } else if (!walkinPurok || !walkinPurok.value) {
                    isValid = false;
                    errorMessage = 'Please select a purok for walk-in resident.';
                }
            }
            
            // Validate patient name if provided
            const patientName = document.getElementById('patient_name');
            if (patientName && patientName.value.trim() && patientName.value.trim().length < 2) {
                isValid = false;
                errorMessage = 'Patient name must be at least 2 characters long.';
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
