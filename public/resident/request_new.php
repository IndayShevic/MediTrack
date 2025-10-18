<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['resident']);
$user = current_user();

$residentRow = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
if (!$resident) { echo 'Resident profile not found.'; exit; }
$residentId = (int)$resident['id'];

// Fetch family members for this resident
$familyMembers = [];
try {
    $stmt = db()->prepare('SELECT id, full_name, relationship, date_of_birth FROM family_members WHERE resident_id = ? ORDER BY full_name');
    $stmt->execute([$residentId]);
    $familyMembers = $stmt->fetchAll();
} catch (Throwable $e) {
    $familyMembers = [];
}

$medicine_id = (int)($_GET['medicine_id'] ?? 0);
$m = null;
if ($medicine_id > 0) {
    $s = db()->prepare('SELECT id, name FROM medicines WHERE id=?');
    $s->execute([$medicine_id]);
    $m = $s->fetch();
}

// Ensure upload directory
$proofDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($proofDir)) { @mkdir($proofDir, 0777, true); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    error_log('POST data received: ' . print_r($_POST, true));
    error_log('FILES data received: ' . print_r($_FILES, true));
    
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $requested_for = $_POST['requested_for'] ?? 'self';
    $family_member_id = ($_POST['family_member_id'] ?? null) ? (int)$_POST['family_member_id'] : null;
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_date_of_birth = $_POST['patient_date_of_birth'] ?? null;
    $relationship = trim($_POST['relationship'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $proof_path = null;
    
    error_log("Processing request - Medicine ID: $medicine_id, Requested for: $requested_for, Family member ID: $family_member_id, Reason: $reason");
    
    // Handle proof upload
    if (!empty($_FILES['proof']['name'])) {
        if (($_FILES['proof']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','pdf'], true)) {
                $filename = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['proof']['tmp_name'], $proofDir . '/' . $filename)) {
                    $proof_path = 'uploads/proofs/' . $filename;
                }
            }
        }
    }

    // If family member is selected, get their details from the database
    if ($requested_for === 'family' && $family_member_id) {
        $familyMember = db()->prepare('SELECT CONCAT(IFNULL(first_name, ""), " ", IFNULL(middle_initial, ""), " ", IFNULL(last_name, "")) as full_name, relationship, date_of_birth FROM family_members WHERE id = ? AND resident_id = ?');
        $familyMember->execute([$family_member_id, $residentId]);
        $member = $familyMember->fetch();
        
        if ($member) {
            $patient_name = trim($member['full_name']);
            $patient_date_of_birth = $member['date_of_birth'];
            $relationship = $member['relationship'];
            error_log("Family member found: $patient_name, DOB: $patient_date_of_birth, Relationship: $relationship");
        } else {
            error_log("Family member not found with ID: $family_member_id for resident: $residentId");
        }
    }
    
    $bhwId = getAssignedBhwIdForResident($residentId);
    error_log("BHW ID: $bhwId, Resident ID: $residentId");
    
    try {
        $stmt = db()->prepare('INSERT INTO requests (resident_id, medicine_id, requested_for, family_member_id, patient_name, patient_date_of_birth, relationship, reason, proof_image_path, status, bhw_id) VALUES (?,?,?,?,?,?,?,?,?,"submitted",?)');
        $result = $stmt->execute([$residentId, $medicine_id, $requested_for, $family_member_id, $patient_name, $patient_date_of_birth, $relationship, $reason, $proof_path, $bhwId]);
        
        if ($result) {
            $requestId = db()->lastInsertId();
            error_log("Request inserted successfully with ID: " . $requestId);
            
            // Notify assigned BHW
            if ($bhwId) {
                $b = db()->prepare('SELECT email, CONCAT(IFNULL(first_name,\'\'),\' \',IFNULL(last_name,\'\')) AS name FROM users WHERE id=?');
                $b->execute([$bhwId]);
                $bhw = $b->fetch();
                if ($bhw && !empty($bhw['email'])) {
                    // Fetch medicine name for email notification
                    $medicineQuery = db()->prepare('SELECT name FROM medicines WHERE id=?');
                    $medicineQuery->execute([$medicine_id]);
                    $medicineData = $medicineQuery->fetch();
                    
                    $residentName = $user['name'] ?? 'Resident';
                    $medicineName = $medicineData['name'] ?? 'Unknown Medicine';
                    $success = send_medicine_request_notification_to_bhw($bhw['email'], $bhw['name'] ?? 'BHW', $residentName, $medicineName);
                    log_email_notification($bhwId, 'medicine_request', 'New Medicine Request', 'New medicine request notification sent to BHW', $success);
                }
            }
            
            // Send success response
            http_response_code(200);
            echo "SUCCESS: Request submitted successfully";
            exit;
        } else {
            error_log("Failed to insert request");
            http_response_code(500);
            echo "ERROR: Failed to submit request";
            exit;
        }
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        http_response_code(500);
        echo "ERROR: " . $e->getMessage();
        exit;
    }
} // Close the POST method check
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request Medicine · Resident</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/resident-animations.css')); ?>">
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
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
                    <h1 class="text-3xl font-bold text-gray-900">Request Medicine</h1>
                    <p class="text-gray-600 mt-1">Submit a request for medicine with proof of need.</p>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Browse
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content-body">
            <div class="max-w-2xl mx-auto">
                <form method="post" enctype="multipart/form-data" class="card animate-fade-in-up">
                    <div class="card-body">
                        <input type="hidden" name="medicine_id" value="<?php echo (int)($m['id'] ?? 0); ?>" />
                        
                        <!-- Medicine Info -->
                        <div class="mb-8 p-6 bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-2xl shadow-lg">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-blue-900"><?php echo htmlspecialchars($m['name'] ?? 'Medicine'); ?></h3>
                                    <p class="text-sm text-blue-700 font-medium">Request this medicine</p>
                                </div>
                            </div>
                        </div>

                        <!-- Request Recipient Section -->
                        <div class="mb-8 p-6 bg-gray-50 border border-gray-200 rounded-2xl">
                            <h4 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                Request Recipient
                            </h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Requested For</label>
                                    <select name="requested_for" class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 bg-white shadow-sm" id="reqFor">
                                        <option value="self">Self</option>
                                        <option value="family">Family Member</option>
                                    </select>
                                </div>
                                
                                <?php if (!empty($familyMembers)): ?>
                                <div id="familyMemberSelect" style="display:none">
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Family Member</label>
                                    <select name="family_member_id" class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 bg-white shadow-sm">
                                        <option value="">Choose a family member</option>
                                        <?php foreach ($familyMembers as $member): ?>
                                            <option value="<?php echo (int)$member['id']; ?>"><?php echo htmlspecialchars($member['full_name'] . ' (' . $member['relationship'] . ', DOB: ' . $member['date_of_birth'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Patient Information Section -->
                        <div class="mb-8 p-6 bg-gradient-to-r from-amber-50 to-yellow-50 border-2 border-amber-200 rounded-2xl" id="familyFields" style="display:none">
                            <h4 class="text-lg font-bold text-amber-900 mb-4 flex items-center">
                                <svg class="w-5 h-5 text-amber-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                </svg>
                                Patient Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-amber-800 mb-3">Patient Name</label>
                                    <input name="patient_name" class="w-full px-4 py-3 border-2 border-amber-300 rounded-xl focus:border-amber-500 focus:ring-2 focus:ring-amber-200 transition-all duration-200 bg-white shadow-sm" placeholder="Enter patient name" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-amber-800 mb-3">Date of Birth</label>
                                    <input name="patient_date_of_birth" type="date" class="w-full px-4 py-3 border-2 border-amber-300 rounded-xl focus:border-amber-500 focus:ring-2 focus:ring-amber-200 transition-all duration-200 bg-white shadow-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-amber-800 mb-3">Relationship</label>
                                    <input name="relationship" class="w-full px-4 py-3 border-2 border-amber-300 rounded-xl focus:border-amber-500 focus:ring-2 focus:ring-amber-200 transition-all duration-200 bg-white shadow-sm" placeholder="e.g., Father, Mother" />
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reason for Request Section -->
                        <div class="mb-8 p-6 bg-gray-50 border border-gray-200 rounded-2xl">
                            <h4 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                                <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Reason for Request
                            </h4>
                            <textarea name="reason" class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 bg-white shadow-sm resize-none" rows="4" placeholder="Please explain why you need this medicine..."></textarea>
                            <p class="text-sm text-gray-600 mt-3 italic">Provide detailed information about your medical condition or symptoms</p>
                        </div>
                        
                        <!-- Proof of Need Section -->
                        <div class="mb-8 p-6 bg-gradient-to-r from-red-50 to-pink-50 border-2 border-red-200 rounded-2xl">
                            <h4 class="text-lg font-bold text-red-900 mb-4 flex items-center">
                                <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                </svg>
                                Proof of Need <span class="text-red-600 text-lg">*</span>
                            </h4>
                            <div class="border-3 border-dashed border-red-300 rounded-2xl p-8 text-center hover:border-red-400 hover:bg-red-50 transition-all duration-300 cursor-pointer bg-white shadow-sm">
                                <input type="file" name="proof" accept="image/*,application/pdf" required class="hidden" id="proofFile" />
                                <label for="proofFile" class="cursor-pointer block">
                                    <div class="w-16 h-16 bg-gradient-to-r from-red-500 to-red-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                        </svg>
                                    </div>
                                    <p class="text-lg font-semibold text-red-900 mb-2">Click to upload or drag and drop</p>
                                    <p class="text-sm text-red-700">JPG, PNG, or PDF (Max 10MB)</p>
                                </label>
                            </div>
                            <p class="text-sm text-red-800 mt-4 italic">Upload temperature reading, medical certificate, or other proof of illness</p>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="mt-8 flex justify-end space-x-6 pt-6 border-t-2 border-gray-200">
                            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>" class="px-8 py-3 border-2 border-gray-300 text-gray-600 font-semibold rounded-xl hover:border-gray-400 hover:text-gray-700 transition-all duration-200 shadow-sm hover:shadow-md">
                                Cancel
                            </a>
                            <button type="submit" class="px-8 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all duration-200 shadow-lg hover:shadow-xl flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                <span>Submit Request</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
<script>
document.getElementById('reqFor').addEventListener('change', function() {
  const familyFields = document.getElementById('familyFields');
  const familyMemberSelect = document.getElementById('familyMemberSelect');
  
  if (this.value === 'family') {
    if (familyMemberSelect) {
      familyMemberSelect.style.display = 'block';
    }
    familyFields.style.display = 'block';
  } else {
    if (familyMemberSelect) {
      familyMemberSelect.style.display = 'none';
    }
    familyFields.style.display = 'none';
    // Clear family member data when switching to self
    clearFamilyData();
  }
});

// Auto-populate family member data when selected
document.addEventListener('DOMContentLoaded', function() {
  const familyMemberSelect = document.getElementById('familyMemberSelect');
  if (familyMemberSelect) {
    const select = familyMemberSelect.querySelector('select[name="family_member_id"]');
    if (select) {
      select.addEventListener('change', function() {
        if (this.value) {
          // Parse the selected option text to extract family member details
          const optionText = this.options[this.selectedIndex].text;
          populateFamilyData(optionText);
        } else {
          clearFamilyData();
        }
      });
    }
  }
});

function populateFamilyData(optionText) {
  // Parse format: "Name (Relationship, DOB: YYYY-MM-DD)"
  const match = optionText.match(/^(.+?)\s*\((.+?),\s*DOB:\s*(\d{4}-\d{2}-\d{2})\)$/);
  
  if (match) {
    const name = match[1].trim();
    const relationship = match[2].trim();
    const dateOfBirth = match[3].trim();
    
    // Populate the form fields
    document.querySelector('input[name="patient_name"]').value = name;
    document.querySelector('input[name="patient_date_of_birth"]').value = dateOfBirth;
    document.querySelector('input[name="relationship"]').value = relationship;
    
    // Disable the fields since they're auto-populated
    document.querySelector('input[name="patient_name"]').readOnly = true;
    document.querySelector('input[name="patient_date_of_birth"]').readOnly = true;
    document.querySelector('input[name="relationship"]').readOnly = true;
  }
}

function clearFamilyData() {
  // Clear the form fields
  document.querySelector('input[name="patient_name"]').value = '';
  document.querySelector('input[name="patient_date_of_birth"]').value = '';
  document.querySelector('input[name="relationship"]').value = '';
  
  // Re-enable the fields
  document.querySelector('input[name="patient_name"]').readOnly = false;
  document.querySelector('input[name="patient_date_of_birth"]').readOnly = false;
  document.querySelector('input[name="relationship"]').readOnly = false;
}
</script>
</body>
</html>


