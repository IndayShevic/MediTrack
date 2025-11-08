<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['resident']);

$user = current_user();

// Get resident ID
$stmt = db()->prepare('SELECT id, purok_id FROM residents WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$resident = $stmt->fetch();

if (!$resident) {
    redirect_to('index.php');
}

$resident_id = (int)$resident['id'];
$purok_id = (int)$resident['purok_id'];

// Get resident info for senior citizen check
$is_senior = false;
if ($resident) {
    $dobStmt = db()->prepare('SELECT date_of_birth FROM residents WHERE id = ? LIMIT 1');
    $dobStmt->execute([$resident_id]);
    $dobRow = $dobStmt->fetch();
    if ($dobRow && !empty($dobRow['date_of_birth'])) {
        $birth_date = new DateTime($dobRow['date_of_birth']);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        $is_senior = $age >= 60;
    }
}

// Get pending requests count for notifications
$pending_requests = 0;
try {
    $pendingStmt = db()->prepare('SELECT COUNT(*) as count FROM requests WHERE resident_id = ? AND status = "submitted"');
    $pendingStmt->execute([$resident_id]);
    $pending_requests = (int)$pendingStmt->fetch()['count'];
} catch (Throwable $e) {
    $pending_requests = 0;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_family_member') {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $relationship_other = trim($_POST['relationship_other'] ?? '');
        $dob = $_POST['date_of_birth'] ?? '';
        
        // If "Other" is selected, use the custom relationship text
        if ($relationship === 'Other' && !empty($relationship_other)) {
            $relationship = preg_replace('/[^A-Za-zÀ-ÿ\' -]/', '', $relationship_other);
        }
        
        $errors = [];
        
        if (empty($first_name) || strlen($first_name) < 2) {
            $errors[] = 'First name must be at least 2 characters.';
        }
        
        if (empty($last_name) || strlen($last_name) < 2) {
            $errors[] = 'Last name must be at least 2 characters.';
        }
        
        if (strlen($middle_initial) > 5) {
            $errors[] = 'Middle initial must be 5 characters or less.';
        }
        
        if (empty($relationship)) {
            $errors[] = 'Please select a relationship.';
        } elseif ($relationship === 'Other' && empty($relationship_other)) {
            $errors[] = 'Please specify the relationship when "Other" is selected.';
        }
        
        if (empty($dob)) {
            $errors[] = 'Please provide date of birth.';
        } else {
            $birthDate = new DateTime($dob);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            if ($age < 0 || $age > 120) {
                $errors[] = 'Please enter a valid date of birth.';
            }
        }
        
        if (empty($errors)) {
            // First check for exact duplicates (same name AND date of birth)
            $exact_duplicate_check = db()->prepare('
                -- Check approved family members in same account (exact match)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_same" as status,
                    fm.full_name,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.full_name)) = LOWER(TRIM(?)) 
                AND fm.date_of_birth = ?
                AND fm.resident_id = ?
                
                UNION ALL
                
                -- Check pending family members in same account (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_same" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id = ?
                AND rfa.status = "pending"
                
                UNION ALL
                
                -- Check approved family members in different accounts (exact match)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_different" as status,
                    fm.full_name,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.full_name)) = LOWER(TRIM(?)) 
                AND fm.date_of_birth = ?
                AND fm.resident_id != ?
                
                UNION ALL
                
                -- Check pending family members in different accounts (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_different" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id != ?
                AND rfa.status = "pending"
                
                UNION ALL
                
                -- Check approved pending family members in different accounts (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_pending_different" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id != ?
                AND rfa.status = "approved"
            ');
            $exact_duplicate_check->execute([
                $full_name, $dob, $resident_id,  // approved_same
                $full_name, $dob, $resident_id,  // pending_same
                $full_name, $dob, $resident_id,  // approved_different
                $full_name, $dob, $resident_id,  // pending_different
                $full_name, $dob, $resident_id   // approved_pending_different
            ]);
            $exact_duplicate = $exact_duplicate_check->fetch();
            
            // Check for name-only duplicates in same account (same person, different relationship)
            $name_duplicate_check = db()->prepare('
                -- Check approved family members in same account (name only)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_same_name" as status,
                    fm.first_name as fm_first_name,
                    fm.middle_initial as fm_middle_initial,
                    fm.last_name as fm_last_name,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.first_name)) = LOWER(TRIM(?)) 
                AND LOWER(TRIM(fm.last_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(COALESCE(fm.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND fm.resident_id = ?
                
                UNION ALL
                
                -- Check pending family members in same account (name only)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_same_name" as status,
                    rfa.first_name as fm_first_name,
                    rfa.middle_initial as fm_middle_initial,
                    rfa.last_name as fm_last_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.first_name)) = LOWER(TRIM(?)) 
                AND LOWER(TRIM(rfa.last_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(COALESCE(rfa.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND rfa.resident_id = ?
                AND rfa.status = "pending"
            ');
            $name_duplicate_check->execute([
                $first_name, $last_name, $middle_initial, $resident_id,  // approved_same_name
                $first_name, $last_name, $middle_initial, $resident_id   // pending_same_name
            ]);
            $duplicate = $name_duplicate_check->fetch();
            
            if ($duplicate) {
                $account_name = format_full_name($duplicate['first_name'], $duplicate['last_name'], $duplicate['middle_initial']);
                $full_name = format_full_name($first_name, $last_name, $middle_initial);
                
                if ($duplicate['status'] === 'approved_same') {
                    $errors[] = "❌ <strong>{$full_name}</strong> is already approved in your account.";
                } elseif ($duplicate['status'] === 'pending_same') {
                    $errors[] = "⏳ <strong>{$full_name}</strong> is already pending approval in your account.";
                } elseif ($duplicate['status'] === 'approved_different') {
                    $errors[] = "❌ <strong>{$full_name}</strong> is already registered under <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'pending_different') {
                    $errors[] = "⏳ <strong>{$full_name}</strong> is currently pending approval under <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'approved_pending_different') {
                    $errors[] = "✅ <strong>{$full_name}</strong> was recently approved and added to <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'approved_same_name') {
                    $existing_relationship = $duplicate['relationship'];
                    $errors[] = "❌ <strong>{$full_name}</strong> is already approved in your account as <strong>{$existing_relationship}</strong>.";
                } elseif ($duplicate['status'] === 'pending_same_name') {
                    $existing_relationship = $duplicate['relationship'];
                    $errors[] = "⏳ <strong>{$full_name}</strong> is already pending approval in your account as <strong>{$existing_relationship}</strong>.";
                }
                
                $errors[] = "<br><small class='text-gray-600'>💡 <strong>Tip:</strong> Each family member can only be registered once. Please verify the name and date of birth, or contact the BHW if you believe this is an error.</small>";
            }
        }
        
        if (empty($errors)) {
            try {
                // Insert as pending
                $stmt = db()->prepare('
                    INSERT INTO resident_family_additions 
                    (resident_id, first_name, middle_initial, last_name, relationship, date_of_birth, status) 
                    VALUES (?, ?, ?, ?, ?, ?, "pending")
                ');
                $stmt->execute([$resident_id, $first_name, $middle_initial, $last_name, $relationship, $dob]);
                
                $_SESSION['flash'] = 'Family member added! Awaiting BHW verification.';
                $_SESSION['flash_type'] = 'success';
                redirect_to('resident/family_members.php');
            } catch (Throwable $e) {
                $_SESSION['flash'] = 'Failed to add family member. Please try again.';
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            $_SESSION['flash'] = implode('<br>', $errors);
            $_SESSION['flash_type'] = 'error';
        }
    }
    
    if ($action === 'delete_pending') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // Can only delete own pending members
            $stmt = db()->prepare('
                DELETE FROM resident_family_additions 
                WHERE id = ? AND resident_id = ? AND status = "pending"
            ');
            $stmt->execute([$id, $resident_id]);
            $_SESSION['flash'] = 'Pending family member removed.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Failed to remove family member.';
            $_SESSION['flash_type'] = 'error';
        }
        redirect_to('resident/family_members.php');
    }
}

// Get approved family members
$approved_family = db()->prepare('
    SELECT id, first_name, middle_initial, last_name, relationship, date_of_birth, created_at
    FROM family_members 
    WHERE resident_id = ?
    ORDER BY last_name, first_name
');
$approved_family->execute([$resident_id]);
$approved_members = $approved_family->fetchAll();

// Get pending family additions
$pending_family = db()->prepare('
    SELECT id, first_name, middle_initial, last_name, relationship, date_of_birth, status, 
           rejection_reason, created_at, updated_at
    FROM resident_family_additions 
    WHERE resident_id = ?
    ORDER BY created_at DESC
');
$pending_family->execute([$resident_id]);
$pending_members = $pending_family->fetchAll();

function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Family Members · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/resident-animations.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
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
<body class="bg-gradient-to-br from-gray-50 to-blue-50 resident-theme">
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
            <a href="<?php echo htmlspecialchars(base_url('resident/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/family_members.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Family Members
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Profile
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
                    <h1 class="text-3xl font-bold text-gray-900">My Family Members</h1>
                    <p class="text-gray-600 mt-1">Manage your family members for medicine requests</p>
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
                            <?php if ($pending_requests > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center"><?php echo $pending_requests; ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    
                    <!-- Profile Section -->
                    <div class="relative" id="profile-dropdown">
                        <button id="profile-toggle" class="flex items-center space-x-3 hover:bg-gray-50 rounded-lg p-2 transition-colors duration-200 cursor-pointer" type="button">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                <?php 
                                $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'R';
                                $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'E';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                            <div class="text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars(!empty($user['first_name']) ? $user['first_name'] : 'Resident'); ?>
                                </div>
                                <div class="text-xs text-gray-500"><?php echo $is_senior ? 'Senior Citizen' : 'Resident'; ?></div>
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
                                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['last_name'] ?? 'User'))); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($user['email'] ?? 'resident@example.com'); ?>
                                </div>
                                <?php if ($is_senior): ?>
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                            Senior Citizen
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Menu Items -->
                            <div class="py-1">
                                <a href="<?php echo base_url('resident/profile.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Edit Profile
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

        <div class="content-body">
        <div class="flex items-center justify-between mb-6">
            <div></div>
            <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                    class="btn btn-primary inline-flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                <span>Add Family Member</span>
            </button>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> animate-fade-in-up">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?php echo $_SESSION['flash']; unset($_SESSION['flash'], $_SESSION['flash_type']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Pending Family Members -->
        <?php if (!empty($pending_members)): ?>
        <div class="card animate-fade-in-up mb-6">
            <div class="card-header">
                <h2 class="text-xl font-semibold flex items-center space-x-2">
                    <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Pending Verification (<?php echo count($pending_members); ?>)</span>
                </h2>
                <p class="text-sm text-gray-600">Awaiting BHW approval</p>
            </div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Relationship</th>
                                <th>Age</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_members as $member): ?>
                            <tr>
                                <td class="font-medium"><?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'])); ?></td>
                                <td><?php echo htmlspecialchars($member['relationship']); ?></td>
                                <td><?php echo calculateAge($member['date_of_birth']); ?> years</td>
                                <td>
                                    <?php if ($member['status'] === 'pending'): ?>
                                        <span class="badge badge-warning">⏳ Pending</span>
                                    <?php elseif ($member['status'] === 'approved'): ?>
                                        <span class="badge badge-success">✓ Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">✕ Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($member['created_at'])); ?></td>
                                <td>
                                    <?php if ($member['status'] === 'pending'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Remove this pending family member?')">
                                            <input type="hidden" name="action" value="delete_pending">
                                            <input type="hidden" name="id" value="<?php echo $member['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-700 text-sm">Remove</button>
                                        </form>
                                    <?php elseif ($member['status'] === 'rejected' && $member['rejection_reason']): ?>
                                        <button onclick="alert('Reason: <?php echo htmlspecialchars(addslashes($member['rejection_reason'])); ?>')" 
                                                class="text-gray-600 hover:text-gray-700 text-sm">View Reason</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approved Family Members -->
        <div class="card animate-fade-in-up delay-100">
            <div class="card-header">
                <h2 class="text-xl font-semibold flex items-center space-x-2">
                    <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Approved Family Members (<?php echo count($approved_members); ?>)</span>
                </h2>
                <p class="text-sm text-gray-600">Can be used for medicine requests</p>
            </div>
            <div class="card-body p-0">
                <?php if (empty($approved_members)): ?>
                    <div class="text-center py-12">
                        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Family Members Yet</h3>
                        <p class="text-gray-600">Add family members to request medicine on their behalf</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Relationship</th>
                                    <th>Age</th>
                                    <th>Added On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approved_members as $member): ?>
                                <tr>
                                    <td class="font-medium"><?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'])); ?></td>
                                    <td><?php echo htmlspecialchars($member['relationship']); ?></td>
                                    <td><?php echo calculateAge($member['date_of_birth']); ?> years</td>
                                    <td><?php echo date('M d, Y', strtotime($member['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Family Member Modal -->
    <div id="addModal" class="fixed top-0 right-0 bottom-0 left-0 bg-transparent hidden items-center justify-center z-[99999] p-4">
        <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl transform transition-all relative z-[100000] border border-gray-200 ml-[570px]">
            <div class="p-6 border-b">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-semibold">Add Family Member</h3>
                    <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-gray-600 mt-1">BHW will verify before approval</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add_family_member">
                
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                        <input type="text" name="first_name" required 
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Juan">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">M.I.</label>
                        <input type="text" name="middle_initial" maxlength="5"
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="D">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" required 
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Dela Cruz">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                    <select name="relationship" id="relationship_select" required 
                            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="handleRelationshipChangeResident(this)">
                        <?php echo get_relationship_options(null, true); ?>
                    </select>
                    <input type="text" 
                           name="relationship_other" 
                           id="relationship_other_resident"
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 mt-2 hidden" 
                           placeholder="Specify relationship (e.g., Stepfather, Godmother, etc.)"
                           maxlength="50" />
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth" required 
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary">Cancel</button>
                    <button type="submit" class="flex-1 btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>

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
        
        /* Removed hover effects for sidebar navigation */
        
        .sidebar-nav a.active {
            background: #dbeafe !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
        }

        /* Force disabled button styling */
        button[type="submit"][disabled] {
            background-color: #ffffff !important;
            color: #6b7280 !important;
            border: 1px solid #d1d5db !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }
        
        button[type="submit"][disabled]:hover,
        button[type="submit"][disabled]:focus,
        button[type="submit"][disabled]:active {
            background-color: #ffffff !important;
            color: #6b7280 !important;
            border: 1px solid #d1d5db !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }
    </style>
    <script>
        // Modal click outside to close
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
            }
        });

        // Real-time duplicate validation
        let validationTimeout;
        let isDuplicateDetected = false;
        const firstNameInput = document.querySelector('input[name="first_name"]');
        const middleInitialInput = document.querySelector('input[name="middle_initial"]');
        const lastNameInput = document.querySelector('input[name="last_name"]');
        const dobInput = document.querySelector('input[name="date_of_birth"]');
        const validationDiv = document.createElement('div');
        validationDiv.id = 'validation-message';
        validationDiv.className = 'mt-2 text-sm';
        
        // Insert validation div after the date of birth input
        dobInput.parentNode.insertAdjacentElement('afterend', validationDiv);

        function checkDuplicate() {
            const firstName = firstNameInput.value.trim();
            const middleInitial = middleInitialInput.value.trim();
            const lastName = lastNameInput.value.trim();
            
            if (firstName.length >= 2 && lastName.length >= 2) {
                // Show loading state
                validationDiv.innerHTML = '<div class="flex items-center text-blue-600"><svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Checking for duplicates...</div>';
                
                fetch('<?php echo htmlspecialchars(base_url('resident/check_duplicate_family.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `first_name=${encodeURIComponent(firstName)}&middle_initial=${encodeURIComponent(middleInitial)}&last_name=${encodeURIComponent(lastName)}`
                })
                .then(response => response.json())
                .then(data => {
                    // Get the current submit button (could be the original or a recreated one)
                    let submitBtn = window.currentSubmitBtn || document.querySelector('button[type="submit"]');
                    
                    if (data.duplicate) {
                        isDuplicateDetected = true;
                        validationDiv.innerHTML = `<div class="text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">
                            <div class="flex items-start">
                                <svg class="w-5 h-5 text-red-500 mt-0.5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="font-medium">❌ Duplicate Found!</div>
                                    <div class="text-sm mt-1">${data.message}</div>
                                    <div class="text-xs mt-2 text-red-500 font-medium">⚠️ Cannot add duplicate family member</div>
                                </div>
                            </div>
                        </div>`;
                        
                        // Find the button container and remove ALL buttons first
                        const buttonContainer = document.querySelector('.flex.space-x-3.pt-4');
                        if (buttonContainer) {
                            // Remove ALL existing buttons and divs
                            const allButtons = buttonContainer.querySelectorAll('button, div[style*="Cannot Add"]');
                            allButtons.forEach(btn => btn.remove());
                            
                            // Keep only the Cancel button
                            const cancelBtn = buttonContainer.querySelector('button[type="button"]');
                            
                            // Clear the container and add back only Cancel button
                            buttonContainer.innerHTML = '';
                            if (cancelBtn) {
                                buttonContainer.appendChild(cancelBtn);
                            }
                            
                            // Create completely unclickable div
                            const disabledDiv = document.createElement('div');
                            disabledDiv.className = 'flex-1';
                            disabledDiv.style.backgroundColor = '#ffffff';
                            disabledDiv.style.color = '#6b7280';
                            disabledDiv.style.border = '1px solid #d1d5db';
                            disabledDiv.style.cursor = 'not-allowed';
                            disabledDiv.style.pointerEvents = 'none';
                            disabledDiv.style.textAlign = 'center';
                            disabledDiv.style.padding = '0.5rem 1rem';
                            disabledDiv.style.borderRadius = '0.5rem';
                            disabledDiv.style.fontSize = '0.875rem';
                            disabledDiv.style.fontWeight = '500';
                            disabledDiv.innerHTML = '❌ Cannot Add (Duplicate)';
                            
                            // Add it to the button container
                            buttonContainer.appendChild(disabledDiv);
                            window.currentSubmitBtn = disabledDiv;
                        }
                        
                        // Completely disable the form
                        const form = document.querySelector('form');
                        form.style.pointerEvents = 'none';
                        form.style.opacity = '0.7';
                        
                        // Add overlay to block all interactions
                        const overlay = document.createElement('div');
                        overlay.style.position = 'absolute';
                        overlay.style.top = '0';
                        overlay.style.left = '0';
                        overlay.style.right = '0';
                        overlay.style.bottom = '0';
                        overlay.style.backgroundColor = 'transparent';
                        overlay.style.zIndex = '9999';
                        overlay.style.pointerEvents = 'auto';
                        overlay.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            alert('Cannot add duplicate family member. Please check the validation message above.');
                            return false;
                        });
                        form.style.position = 'relative';
                        form.appendChild(overlay);
                        
                    } else {
                        isDuplicateDetected = false;
                        validationDiv.innerHTML = `<div class="text-green-600 bg-green-50 border border-green-200 rounded-lg p-3">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="font-medium">✅ Name available!</span>
                            </div>
                        </div>`;
                        
                        // Recreate the submit button
                        const buttonContainer = document.querySelector('.flex.space-x-3.pt-4');
                        if (buttonContainer) {
                            // Remove ALL existing buttons and divs
                            const allButtons = buttonContainer.querySelectorAll('button, div[style*="Cannot Add"]');
                            allButtons.forEach(btn => btn.remove());
                            
                            // Keep only the Cancel button
                            const cancelBtn = buttonContainer.querySelector('button[type="button"]');
                            
                            // Clear the container and add back only Cancel button
                            buttonContainer.innerHTML = '';
                            if (cancelBtn) {
                                buttonContainer.appendChild(cancelBtn);
                            }
                            
                            // Create new submit button
                            const newSubmitBtn = document.createElement('button');
                            newSubmitBtn.type = 'submit';
                            newSubmitBtn.className = 'flex-1 btn btn-primary';
                            newSubmitBtn.innerHTML = 'Add Member';
                            
                            // Add it to the button container
                            buttonContainer.appendChild(newSubmitBtn);
                            window.currentSubmitBtn = newSubmitBtn;
                        }
                        
                        // Re-enable the form
                        const form = document.querySelector('form');
                        form.style.pointerEvents = 'auto';
                        form.style.opacity = '1';
                        
                        // Remove any overlays
                        const overlays = form.querySelectorAll('div[style*="z-index: 9999"]');
                        overlays.forEach(overlay => overlay.remove());
                    }
                })
                .catch(error => {
                    validationDiv.innerHTML = '<div class="text-yellow-600">Unable to check for duplicates. Please try again.</div>';
                });
            } else {
                validationDiv.innerHTML = '';
            }
        }

        // Add event listeners
        firstNameInput.addEventListener('input', function() {
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });
        
        middleInitialInput.addEventListener('input', function() {
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });
        
        lastNameInput.addEventListener('input', function() {
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });

        // Clear validation on modal close
        document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
            validationDiv.innerHTML = '';
            firstNameInput.value = '';
            middleInitialInput.value = '';
            lastNameInput.value = '';
            dobInput.value = '';
            isDuplicateDetected = false;
        });

        // Prevent form submission if duplicate is detected
        document.querySelector('form').addEventListener('submit', function(e) {
            if (isDuplicateDetected) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
        });
        
        // Prevent form submission when duplicate is detected
        document.querySelector('form').addEventListener('submit', function(e) {
            if (isDuplicateDetected) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
            
            // Also check if the current element is a div (disabled state)
            const currentElement = window.currentSubmitBtn;
            if (currentElement && currentElement.tagName === 'DIV') {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
            
            // Check if form is disabled
            const form = document.querySelector('form');
            if (form.style.pointerEvents === 'none') {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Form is disabled due to duplicate family member. Please check the validation message.');
                return false;
            }
        }, true);
        
        // Additional prevention - block all form events
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            // Only allow submission if no duplicate and form is enabled
            if (!isDuplicateDetected && document.querySelector('form').style.pointerEvents !== 'none') {
                // Re-enable the form temporarily for submission
                const form = document.querySelector('form');
                const originalPointerEvents = form.style.pointerEvents;
                const originalOpacity = form.style.opacity;
                
                form.style.pointerEvents = 'auto';
                form.style.opacity = '1';
                
                // Remove overlays temporarily
                const overlays = form.querySelectorAll('div[style*="z-index: 9999"]');
                overlays.forEach(overlay => overlay.style.display = 'none');
                
                // Submit the form
                setTimeout(() => {
                    form.submit();
                }, 10);
                
                return true;
            } else {
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
        }, false);
        
        // Handle relationship change to show/hide custom relationship input
        function handleRelationshipChangeResident(selectElement) {
            const otherInput = document.getElementById('relationship_other_resident');
            if (otherInput) {
                if (selectElement.value === 'Other') {
                    otherInput.classList.remove('hidden');
                    otherInput.required = true;
                } else {
                    otherInput.classList.add('hidden');
                    otherInput.value = '';
                    otherInput.required = false;
                }
            }
        }
    </script>
</body>
</html>

