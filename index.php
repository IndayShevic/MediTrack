<?php
declare(strict_types=1);
// Root landing page for MediTrack
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/email_notifications.php';

$user = current_user();
if ($user) {
    if ($user['role'] === 'super_admin') { header('Location: public/super_admin/dashboard.php'); exit; }
    if ($user['role'] === 'bhw') { header('Location: public/bhw/dashboard.php'); exit; }
    if ($user['role'] === 'resident') { header('Location: public/resident/dashboard.php'); exit; }
}

// Handle duplicate checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_duplicate') {
    header('Content-Type: application/json');
    
    $type = $_POST['type'] ?? '';
    $response = ['duplicate' => false, 'message' => ''];
    
    if ($type === 'personal_info') {
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        
        // Check email in pending_residents
        $emailCheck = db()->prepare('SELECT id FROM pending_residents WHERE LOWER(email) = LOWER(?)');
        $emailCheck->execute([$email]);
        if ($emailCheck->fetch()) {
            $response = ['duplicate' => true, 'message' => 'This email is already registered and pending approval.'];
        }
        
        // Check email in users (approved accounts)
        if (!$response['duplicate']) {
            $emailCheck = db()->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
            $emailCheck->execute([$email]);
            if ($emailCheck->fetch()) {
                $response = ['duplicate' => true, 'message' => 'This email is already registered and approved.'];
            }
        }
        
        // Check name combination in pending_residents
        if (!$response['duplicate']) {
            $nameCheck = db()->prepare('SELECT id FROM pending_residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(COALESCE(middle_initial, "")) = LOWER(COALESCE(?, ""))');
            $nameCheck->execute([$first_name, $last_name, $middle_initial]);
            if ($nameCheck->fetch()) {
                $response = ['duplicate' => true, 'message' => 'A person with this name is already registered and pending approval.'];
            }
        }
        
        // Check name combination in users (approved accounts)
        if (!$response['duplicate']) {
            $nameCheck = db()->prepare('SELECT id FROM users WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(COALESCE(middle_initial, "")) = LOWER(COALESCE(?, ""))');
            $nameCheck->execute([$first_name, $last_name, $middle_initial]);
            if ($nameCheck->fetch()) {
                $response = ['duplicate' => true, 'message' => 'A person with this name is already registered and approved.'];
            }
        }
    }
    
    if ($type === 'family_member') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        
        // Check in pending_family_members
        $familyCheck = db()->prepare('SELECT id FROM pending_family_members WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(COALESCE(middle_initial, "")) = LOWER(COALESCE(?, ""))');
        $familyCheck->execute([$first_name, $last_name, $middle_initial]);
        if ($familyCheck->fetch()) {
            $response = ['duplicate' => true, 'message' => 'This family member is already registered and pending approval.'];
        }
        
        // Check in family_members (approved)
        if (!$response['duplicate']) {
            $familyCheck = db()->prepare('SELECT id FROM family_members WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(COALESCE(middle_initial, "")) = LOWER(COALESCE(?, ""))');
            $familyCheck->execute([$first_name, $last_name, $middle_initial]);
            if ($familyCheck->fetch()) {
                $response = ['duplicate' => true, 'message' => 'This family member is already registered and approved.'];
            }
        }
    }
    
    echo json_encode($response);
    exit;
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Debug: Log all POST data
    error_log('POST data received in index.php: ' . print_r($_POST, true));
    file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - POST data: ' . print_r($_POST, true) . "\n", FILE_APPEND);
    
    // Sanitize and validate input data
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $middle = trim($_POST['middle_initial'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $purok_id = (int)($_POST['purok_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Debug: Log processed data
    error_log('Processed data - Email: ' . $email . ', First: ' . $first . ', Last: ' . $last . ', Purok: ' . $purok_id);
    
    // Validation rules
    if (empty($first) || strlen($first) < 2) {
        $errors[] = 'First name must be at least 2 characters long.';
    }
    
    if (empty($last) || strlen($last) < 2) {
        $errors[] = 'Last name must be at least 2 characters long.';
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    
    if (empty($dob)) {
        $errors[] = 'Please select your date of birth.';
    }
    
    if (empty($phone)) {
        $errors[] = 'Please enter your phone number.';
    }
    
    if (empty($purok_id)) {
        $errors[] = 'Please select your purok.';
    }
    
    // Family members data validation
    $family_members = [];
    if (isset($_POST['family_members']) && is_array($_POST['family_members'])) {
        foreach ($_POST['family_members'] as $index => $member) {
            $first_name = trim($member['first_name'] ?? '');
            $middle_initial = trim($member['middle_initial'] ?? '');
            $last_name = trim($member['last_name'] ?? '');
            $relationship = trim($member['relationship'] ?? '');
            $date_of_birth = $member['date_of_birth'] ?? '';
            
            // Only validate if at least one field is filled
            if (!empty($first_name) || !empty($last_name) || !empty($relationship) || !empty($date_of_birth)) {
                if (empty($first_name) || strlen($first_name) < 2) {
                    $errors[] = "Family member " . ($index + 1) . ": First name must be at least 2 characters long.";
                }
                
                if (empty($last_name) || strlen($last_name) < 2) {
                    $errors[] = "Family member " . ($index + 1) . ": Last name must be at least 2 characters long.";
                }
                
                if (empty($relationship)) {
                    $errors[] = "Family member " . ($index + 1) . ": Relationship is required.";
                }
                
                if (empty($date_of_birth)) {
                    $errors[] = "Family member " . ($index + 1) . ": Date of birth is required.";
                }
                
                if (empty($errors)) {
                    $family_members[] = [
                        'first_name' => $first_name,
                        'middle_initial' => $middle_initial,
                        'last_name' => $last_name,
                        'relationship' => $relationship,
                        'date_of_birth' => $date_of_birth
                    ];
                }
            }
        }
    }
    
    // If no validation errors, proceed with registration
    if (empty($errors)) {
        try {
            error_log('Starting database insertion in index.php...');
            
            // Test database connection first
            $pdo = db();
            if (!$pdo) {
                throw new Exception('Database connection failed');
            }
            
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Get barangay_id from purok_id
            $q = db()->prepare('SELECT barangay_id FROM puroks WHERE id = ? LIMIT 1');
            $q->execute([$purok_id]);
            $row = $q->fetch();
            $barangay_id = $row ? (int)$row['barangay_id'] : 0;
            
            // Insert into pending_residents table
            $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, middle_initial, date_of_birth, phone, address, barangay_id, purok_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $result = $insPending->execute([$email, $hash, $first, $last, $middle, $dob, $phone, $address, $barangay_id, $purok_id]);
            
            if (!$result) {
                throw new Exception('Failed to insert pending resident: ' . implode(', ', $insPending->errorInfo()));
            }
            
            $pendingId = (int)$pdo->lastInsertId();
            error_log('Pending resident inserted with ID: ' . $pendingId);
            
            // Insert family members
            if (!empty($family_members)) {
                $insFamily = $pdo->prepare('INSERT INTO pending_family_members(pending_resident_id, first_name, middle_initial, last_name, relationship, date_of_birth) VALUES(?,?,?,?,?,?)');
                foreach ($family_members as $member) {
                    $result = $insFamily->execute([$pendingId, $member['first_name'], $member['middle_initial'], $member['last_name'], $member['relationship'], $member['date_of_birth']]);
                    if (!$result) {
                        throw new Exception('Failed to insert family member: ' . implode(', ', $insFamily->errorInfo()));
                    }
                }
                error_log('Family members inserted: ' . count($family_members));
            }
            
            $pdo->commit();
            
            // Notify assigned BHW about new registration
            try {
                error_log('Looking for BHW for purok_id: ' . $purok_id);
                $bhwStmt = db()->prepare('SELECT u.email, u.first_name, u.last_name, p.name as purok_name FROM users u JOIN puroks p ON p.id = u.purok_id WHERE u.role = "bhw" AND u.purok_id = ? LIMIT 1');
                $bhwStmt->execute([$purok_id]);
                $bhw = $bhwStmt->fetch();
                
                if ($bhw) {
                    error_log('BHW found: ' . $bhw['email']);
                    $bhwName = format_full_name($bhw['first_name'] ?? '', $bhw['last_name'] ?? '', $bhw['middle_initial'] ?? null);
                    $residentName = format_full_name($first, $last, $middle);
                    $success = send_new_registration_notification_to_bhw($bhw['email'], $bhwName, $residentName, $bhw['purok_name']);
                    error_log('Email sent successfully: ' . ($success ? 'Yes' : 'No'));
                    log_email_notification(0, 'new_registration', 'New Registration', 'New resident registration notification sent to BHW', $success);
                } else {
                    error_log('No BHW found for purok_id: ' . $purok_id);
                }
            } catch (Throwable $e) {
                error_log('Email notification failed: ' . $e->getMessage());
            }
            
            // Return success response
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Registration submitted successfully!']);
            exit;
            
        } catch (Throwable $e) {
            if (isset($pdo)) $pdo->rollBack();
            error_log('Registration failed: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            // Also log to debug file
            file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - Registration Error: ' . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - Stack trace: ' . $e->getTraceAsString() . "\n", FILE_APPEND);
            
            // Return error response
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
            exit;
        }
    } else {
        // Return validation errors
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MediTrack - Medicine Management - UPDATED WITH SEPARATE NAME FIELDS!</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/thesis/public/assets/favicon/favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="/thesis/public/assets/favicon/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/thesis/public/assets/favicon/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/thesis/public/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/thesis/public/assets/favicon/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/thesis/public/assets/favicon/android-chrome-512x512.png">
    <link rel="manifest" href="/thesis/public/assets/favicon/site.webmanifest">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/design-system.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        .nav-link.active { color: #1d4ed8; font-weight: 600; }
        
        /* Enhanced Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-60px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(60px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-15px);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.6;
            }
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes shine {
            to {
                background-position: 200% center;
            }
        }
        
        .animate-fade-in-up {
            animation: fadeInUp 1s ease-out forwards;
        }
        
        .animate-fade-in-down {
            animation: fadeInDown 0.8s ease-out forwards;
        }
        
        .animate-slide-in-left {
            animation: slideInLeft 1s ease-out forwards;
        }
        
        .animate-slide-in-right {
            animation: slideInRight 1s ease-out forwards;
        }
        
        .animate-float {
            animation: float 3s ease-in-out infinite;
        }
        
        .animate-pulse-slow {
            animation: pulse 3s ease-in-out infinite;
        }
        
        .animate-scale-in {
            animation: scaleIn 0.6s ease-out forwards;
        }
        
        /* Enhanced glass effect */
        .glass-effect {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.4s ease;
        }
        
        .glass-effect:hover {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            transform: translateY(-3px);
        }
        
        /* Enhanced card effects */
        .card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            transition: left 0.5s ease;
        }
        
        .card:hover::before {
            left: 0;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        /* Button enhancements */
        .btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:hover::before {
            width: 400px;
            height: 400px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        /* Gradient text */
        .text-gradient {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #ec4899 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: shine 3s linear infinite;
            background-size: 200% auto;
        }
        
        /* Shadow glow effect */
        .shadow-glow {
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.4), 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .shadow-glow:hover {
            box-shadow: 0 0 30px rgba(59, 130, 246, 0.6), 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Input focus effects */
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            transition: all 0.3s ease;
        }
        
        /* Modal animations */
        #loginModal, #registerModal {
            backdrop-filter: blur(8px);
        }
        
        #loginModal > div, #registerModal > div {
            animation: modalSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .animate-shake {
            animation: shake 0.5s ease-in-out;
        }
        
        /* Scroll indicator */
        .scroll-indicator {
            animation: float 2s ease-in-out infinite;
        }
        
        /* Stagger delays */
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }
        .delay-500 { animation-delay: 0.5s; }
        
        /* Parallax background */
        .parallax-bg {
            transition: transform 0.1s ease-out;
        }
        
        /* Details/Accordion animation */
        details[open] summary {
            margin-bottom: 1rem;
        }
        
        details summary {
            transition: all 0.3s ease;
        }
        
        details summary:hover {
            color: #3b82f6;
        }
        
        /* Testimonial card enhance */
        .testimonial-card {
            transition: all 0.4s ease;
        }
        
        .testimonial-card:hover {
            transform: scale(1.05) rotate(1deg);
        }
        
        /* Aurora mesh + dotted grid + premium glass */
        .aurora { pointer-events:none; position:absolute; inset:0; background:
          radial-gradient(1200px 600px at 10% -10%, rgba(59,130,246,.25), transparent 60%),
          radial-gradient(900px 500px at 110% 10%, rgba(99,102,241,.25), transparent 60%),
          radial-gradient(800px 400px at 50% 120%, rgba(236,72,153,.18), transparent 60%);
          filter: saturate(120%); animation: auroraShift 14s ease-in-out infinite alternate;
        }
        @keyframes auroraShift { 0%{ transform: translateY(0) } 100%{ transform: translateY(-20px) } }
        .dot-grid::before { content:''; position:absolute; inset:0; background-image:
          radial-gradient(rgba(17,24,39,.08) 1px, transparent 1px);
          background-size:24px 24px; mask-image:linear-gradient(to bottom, rgba(0,0,0,.6), rgba(0,0,0,0));
        }
        .orb { position:absolute; width:28rem; height:28rem; border-radius:9999px; filter: blur(60px); opacity:.35; }
        .orb--blue { background:#60a5fa; top:-6rem; right:-6rem; animation: float 12s ease-in-out infinite; }
        .orb--violet { background:#a78bfa; bottom:-8rem; left:-6rem; animation: float 16s ease-in-out infinite reverse; }
        .glass-card-xl { backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
          background: linear-gradient(180deg, rgba(255,255,255,.82), rgba(255,255,255,.74));
          border:1px solid rgba(255,255,255,.45); border-radius:1.5rem;
          box-shadow: 0 30px 80px -20px rgba(59,130,246,.25), 0 12px 30px rgba(2,6,23,.08);
        }
        .tilt { transform-style: preserve-3d; transition: transform .35s ease, box-shadow .35s ease; }
        .tilt:hover { transform: translateY(-6px) rotateX(2deg) rotateY(-3deg); box-shadow: 0 30px 60px rgba(0,0,0,.12); }
        .badge-pill { background: linear-gradient(90deg, #22c55e, #16a34a); color:#fff; padding:.5rem .9rem;
          border-radius:9999px; display:inline-flex; align-items:center; gap:.5rem;
          box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 8px 20px rgba(22,163,74,.25);
        }
        .cta-primary { background: linear-gradient(135deg,#2563eb,#7c3aed 60%,#ec4899); color:#fff }
        .cta-primary:hover { filter: brightness(1.05); }
        .cta-secondary { background: rgba(255,255,255,.6); border:1px solid rgba(148,163,184,.35); }
        
        /* Animated gradient text */
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .animate-gradient {
            background-size: 200% 200%;
            animation: gradientShift 3s ease infinite;
        }
        
        /* Scroll reveal animations */
        .scroll-reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .scroll-reveal.active {
            opacity: 1;
            transform: translateY(0);
        }
        .scroll-reveal-left {
            opacity: 0;
            transform: translateX(-30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .scroll-reveal-left.active {
            opacity: 1;
            transform: translateX(0);
        }
        .scroll-reveal-right {
            opacity: 0;
            transform: translateX(30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .scroll-reveal-right.active {
            opacity: 1;
            transform: translateX(0);
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 relative overflow-x-hidden">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 border-b border-gray-200 bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <!-- Logo Left -->
                <div class="flex items-center space-x-3">
                    <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name', 'MediTrack'); ?>
                    <?php if ($logo): ?>
                        <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" alt="Logo" class="h-11 w-11 rounded-lg shadow-md" />
                    <?php else: ?>
                        <div class="w-11 h-11 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-lg flex items-center justify-center shadow-md">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="flex flex-col leading-tight">
                        <span class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600 animate-gradient">
                            <?php echo htmlspecialchars($brand); ?>.
                        </span>
                        <span class="text-[10px] text-gray-500 font-semibold tracking-widest uppercase">Barangay Loon</span>
                    </div>
                </div>

                <!-- Nav Links Center -->
                <div class="hidden md:flex items-center gap-10 absolute left-1/2 transform -translate-x-1/2">
                    <a href="#home" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors">Home</a>
                    <a href="#features" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors">Features</a>
                    <a href="#about" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors">About</a>
                    <a href="#contact" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors">Contact</a>
                </div>
                
                <!-- CTA Right -->
                <div class="flex items-center gap-3">
                    <button onclick="openRegisterModal()" class="hidden md:inline-flex items-center gap-2 px-5 py-2.5 rounded-lg font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 border border-gray-300 hover:border-gray-400 transition-all duration-300 hover:shadow-md hover:scale-105 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        Register
                    </button>
                    <button onclick="openLoginModal()" class="hidden md:inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white px-6 py-2.5 rounded-lg font-medium transition-all duration-300 shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 relative overflow-hidden group">
                        <span class="relative z-10">Sign in</span>
                        <svg class="w-4 h-4 relative z-10 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                        <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-20 transition-opacity duration-300"></div>
                    </button>
                    
                    <!-- Mobile Menu Button -->
                    <button id="mobileMenuBtn" class="md:hidden p-2 text-gray-700 hover:text-blue-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Mobile Menu -->
            <div id="mobileMenu" class="hidden md:hidden border-t border-gray-200 bg-white">
                <div class="px-4 py-4 space-y-3">
                    <a href="#home" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors font-medium">Home</a>
                    <a href="#features" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors font-medium">Features</a>
                    <a href="#about" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors font-medium">About</a>
                    <a href="#contact" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors font-medium">Contact</a>
                    <div class="pt-3 space-y-2 border-t border-gray-200">
                        <button onclick="openRegisterModal()" class="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 border border-gray-300 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Register
                        </button>
                        <button onclick="openLoginModal()" class="w-full flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium shadow-lg">
                            Sign in
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="relative overflow-hidden z-10 pt-40 pb-20">
        <!-- Unique layered background -->
        <div class="aurora"></div>
        <div class="dot-grid absolute inset-0"></div>
        <div class="orb orb--blue"></div>
        <div class="orb orb--violet"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-14 items-center">
                <!-- Left content -->
                <div class="space-y-4 sm:space-y-6 animate-slide-in-left text-center lg:text-left">
                    <div class="badge-pill w-max mx-auto lg:mx-0">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <path d="M5 13l4 4L19 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Barangay-first healthcare
                    </div>

                    <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-extrabold leading-tight text-gray-900">
                        Protecting your Health,
                        <span class="block bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600">
                            Our Priority
                        </span>
                    </h1>

                    <p class="text-base sm:text-lg lg:text-xl text-gray-600 max-w-xl mx-auto lg:mx-0">
                    Track and distribute medicines efficiently at the barangay level—connecting residents, BHWs, and admins in one smart system for faster service, accurate inventory, and healthier communities.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 pt-2 justify-center lg:justify-start">
                        <button onclick="openLoginModal()" class="btn btn-lg cta-primary rounded-full px-6 sm:px-7 py-3 shadow-glow hover:scale-105 transition-transform flex items-center justify-center gap-2 group">
                            <span>Get started</span>
                            <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </button>
                        <a href="#features" class="btn btn-lg cta-secondary rounded-full px-6 sm:px-7 py-3 hover:scale-105 transition-transform flex items-center justify-center">
                            Learn more
                        </a>
                    </div>
                    
                    <div class="flex items-center gap-4 pt-3 text-sm text-gray-600 justify-center lg:justify-start">
                        <div class="flex -space-x-2">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white grid place-items-center font-semibold shadow-md">B</div>
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white grid place-items-center font-semibold shadow-md">H</div>
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-fuchsia-500 to-rose-600 text-white grid place-items-center font-semibold shadow-md">W</div>
                        </div>
                        <span class="hidden sm:inline">Trusted by barangays and healthcare workers</span>
                        <span class="sm:hidden text-xs">Trusted by barangays</span>
                    </div>
                </div>

                <!-- Right visual -->
                <div class="relative animate-slide-in-right">
                    <div class="glass-card-xl p-6 tilt">
                        <div class="relative rounded-2xl overflow-hidden">
                            <div class="absolute -inset-8 bg-gradient-to-tr from-blue-400/20 via-indigo-400/20 to-fuchsia-400/20 blur-2xl"></div>
                            <img class="relative rounded-xl shadow-2xl w-full"
                                 src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?q=80&w=1200&auto=format&fit=crop"
                                 alt="MediTrack preview" />
                                </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                            <div class="card p-4">
                                <div class="text-xs text-gray-500">Status</div>
                                <div class="mt-1 font-semibold text-gray-900">Request approved</div>
                                </div>
                            <div class="card p-4">
                                <div class="text-xs text-gray-500">Inventory</div>
                                <div class="mt-1 font-semibold text-gray-900">FEFO applied</div>
                            </div>
                            <div class="card p-4">
                                <div class="text-xs text-gray-500">Allocation</div>
                                <div class="mt-1 font-semibold text-gray-900">Seniors updated</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subtle bottom divider -->
        <div class="pointer-events-none absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-white/80 to-transparent"></div>
    </section>

        <!-- Features Section -->
        <section id="features" class="py-12 sm:py-16 lg:py-20 bg-white/60">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 mb-6 sm:mb-8 text-center lg:text-left">Powerful Features</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    <div class="card">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">Browse & Request</div>
                                <p class="text-sm text-gray-600">Residents can discover medicines and submit requests with proof and patient info.</p>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">BHW Approval</div>
                                <p class="text-sm text-gray-600">BHWs verify and approve requests, managing residents and families by purok.</p>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">Admin Inventory</div>
                                <p class="text-sm text-gray-600">Super Admins manage medicines, batches, users, and senior allocations with FEFO.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="py-12 sm:py-16 lg:py-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 mb-4 sm:mb-6 text-center lg:text-left">About MediTrack</h2>
                <p class="text-sm sm:text-base lg:text-lg text-gray-600 max-w-3xl text-center lg:text-left mx-auto lg:mx-0">A barangay-focused medicine inventory and request platform featuring FEFO batch handling, role-based dashboards (Super Admin, BHW, Resident), and a senior citizen maintenance allocation program. Built with PHP, MySQL, and TailwindCSS for reliability and speed.</p>
            </div>
        </section>

        <!-- Contact CTA -->
        <section id="contact" class="py-12 sm:py-16 bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="card">
                    <div class="card-body flex items-center justify-between flex-col md:flex-row gap-4 text-center md:text-left">
                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900">Need help getting started?</h3>
                            <p class="text-sm sm:text-base text-gray-600">Visit your barangay health center or sign in below.</p>
                        </div>
                        <button onclick="openLoginModal()" class="btn btn-primary btn-lg whitespace-nowrap">Sign in</button>
                    </div>
                </div>
            </div>
        </section>


        <!-- Testimonials -->
        <section id="testimonials" class="py-12 sm:py-16 lg:py-20 bg-white/60 relative overflow-hidden">
            <div class="absolute top-10 right-10 w-64 h-64 bg-blue-200 rounded-full blur-3xl opacity-20 animate-pulse-slow hidden sm:block"></div>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <div class="text-center mb-8 sm:mb-12 animate-fade-in-up">
                    <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 mb-2 sm:mb-3">What users say</h2>
                    <p class="text-sm sm:text-base text-gray-600">Real feedback from real users</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    <div class="card testimonial-card animate-fade-in-up delay-100">
                        <div class="card-body">
                            <div class="flex mb-3">
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                            </div>
                            <p class="text-gray-700 italic mb-4">"MediTrack made it easy for our seniors to get their monthly maintenance meds on time."</p>
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center font-bold text-white shadow-lg">JL</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Jose L.</div>
                                    <div class="text-xs text-gray-500">Barangay Captain</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card testimonial-card animate-fade-in-up delay-200">
                        <div class="card-body">
                            <div class="flex mb-3">
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                            </div>
                            <p class="text-gray-700 italic mb-4">"Approving requests is straightforward, and stock deduction follows FEFO automatically."</p>
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center font-bold text-white shadow-lg">MA</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Maria A.</div>
                                    <div class="text-xs text-gray-500">BHW</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card testimonial-card animate-fade-in-up delay-300">
                        <div class="card-body">
                            <div class="flex mb-3">
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                                <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                </svg>
                            </div>
                            <p class="text-gray-700 italic mb-4">"Inventory, batches, and email notifications work seamlessly for our team."</p>
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center font-bold text-white shadow-lg">AN</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Ana N.</div>
                                    <div class="text-xs text-gray-500">Super Admin</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="py-12 sm:py-16 lg:py-20">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 mb-6 sm:mb-8 text-center lg:text-left">Frequently Asked Questions</h2>
                <div class="space-y-4">
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">How do residents request medicines?</summary>
                        <div class="px-6 pb-6 text-gray-700">Residents sign in, browse medicines, and submit a request with a proof image and patient details.</div>
                    </details>
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">How are approvals handled?</summary>
                        <div class="px-6 pb-6 text-gray-700">BHWs review requests and approve/reject. Approved requests automatically deduct stock FEFO from batches.</div>
                    </details>
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">Do emails send automatically?</summary>
                        <div class="px-6 pb-6 text-gray-700">Yes. The system emails request and user events via PHPMailer. Email attempts are logged for review.</div>
                    </details>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t py-6 text-center text-sm text-gray-500 bg-white/80 backdrop-blur-sm relative z-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-lg font-bold text-gradient mb-2"><?php echo htmlspecialchars($brand); ?></div>
            <p class="text-gray-600">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($brand); ?>. All rights reserved.</p>
            <p class="text-gray-500 text-xs mt-2">Making healthcare accessible for everyone.</p>
        </div>
    </footer>

    <!-- Scroll to Top Button -->
    <button id="scrollToTop" class="fixed bottom-6 right-6 bg-gradient-to-br from-blue-600 to-indigo-600 text-white p-4 rounded-full shadow-glow hidden z-50 hover:shadow-xl transition-all duration-300 hover:scale-110">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

    <!-- Sticky mobile login CTA -->
    <button onclick="openLoginModal()" class="md:hidden fixed bottom-24 right-6 btn btn-primary shadow-glow z-40">Login</button>

    <!-- Success Notification -->
    <div id="successNotification" class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg hidden z-50">
        <div class="flex items-center space-x-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span>Registration submitted for approval! You will receive an email once approved.</span>
        </div>
    </div>

    <!-- Enhanced Registration Modal -->
    <div id="registerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto shadow-2xl transform transition-all duration-300">
            <!-- Clean White Header -->
            <div class="relative bg-white border-b border-gray-100 p-8">
                <button onclick="closeRegisterModal()" class="absolute top-6 right-6 text-gray-400 hover:text-gray-600 transition-colors z-10">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                
                <div class="text-center">
                    <div class="w-16 h-16 bg-gray-50 rounded-full mx-auto mb-4 flex items-center justify-center">
                        <svg class="w-8 h-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Create Resident Account</h2>
                    <p class="text-gray-600">Join MediTrack to manage your medicine requests</p>
                </div>
            </div>
                
            <!-- Clean Progress Steps -->
            <div class="px-8 py-6 bg-white border-b border-gray-100">
                <div class="flex items-center justify-center">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-900 text-white" id="modal-step-1">1</div>
                            <span class="ml-3 text-sm font-medium text-gray-900">Personal Info</span>
                        </div>
                        <div class="w-8 h-0.5 bg-gray-200 rounded-full"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-200 text-gray-500" id="modal-step-2">2</div>
                            <span class="ml-3 text-sm font-medium text-gray-500">Family Members</span>
                        </div>
                        <div class="w-8 h-0.5 bg-gray-200 rounded-full"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-200 text-gray-500" id="modal-step-3">3</div>
                            <span class="ml-3 text-sm font-medium text-gray-500">Review</span>
                        </div>
                    </div>
                </div>
            </div>
                
            <!-- Form Body -->
            <div class="p-8">
                <form id="registerForm" action="" method="post" class="space-y-6">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="flex items-start space-x-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3 animate-shake">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Step 1: Personal Information -->
                    <div id="step-1" class="step-content">
                    
                    <!-- Clean Personal Information Section -->
                    <div class="bg-white rounded-xl border border-gray-200 p-8 space-y-6">
                        <h3 class="text-xl font-semibold text-gray-900 flex items-center space-x-3">
                            <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <span>Personal Details</span>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- First Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">First Name</label>
                                <input name="first_name" required 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                       placeholder="Juan" />
                            </div>
                            
                            <!-- Middle Initial -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Middle Initial</label>
                                <input name="middle_initial" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                       placeholder="D." 
                                       maxlength="10" />
                            </div>
                            
                            <!-- Last Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Last Name</label>
                                <input name="last_name" required 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                       placeholder="Cruz" />
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Email -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                        </svg>
                                    </div>
                                    <input type="email" name="email" required 
                                           class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                           placeholder="you@example.com" />
                                </div>
                            </div>
                            
                            <!-- Password -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                        </svg>
                                    </div>
                                    <input type="password" name="password" id="register-password" required 
                                           class="w-full pl-10 pr-12 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                           placeholder="••••••••" />
                                    <button type="button" onclick="togglePasswordVisibility('register-password', 'register-eye-icon')" 
                                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 transition-colors">
                                        <svg id="register-eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Date of Birth -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Date of Birth</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <input type="date" name="date_of_birth" required 
                                           class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-4 focus:ring-green-100 transition-all duration-200 outline-none" />
                                </div>
                            </div>
                            
                            <!-- Phone -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Phone Number <span class="text-gray-400">(optional)</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                        </svg>
                                    </div>
                                    <input type="tel" 
                                           name="phone" 
                                           id="phone-input"
                                           pattern="09[0-9]{9}"
                                           maxlength="14"
                                           class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-4 focus:ring-green-100 transition-all duration-200 outline-none" 
                                           placeholder="0912 345 6789 (optional)"
                                           title="Phone number must start with 09 and be exactly 11 digits" />
                                </div>
                                <p class="text-xs text-gray-500 ml-1">Format: 09XX XXX XXXX (11 digits) - Optional</p>
                            </div>
                        </div>
                        
                        <!-- Purok -->
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Purok (Area)</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                <select name="purok_id" required 
                                        class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-4 focus:ring-green-100 transition-all duration-200 outline-none appearance-none bg-white">
                                    <option value="">Select your Purok</option>
                                <?php
                                $puroks = db()->query('SELECT p.id, p.name, b.name AS barangay FROM puroks p JOIN barangays b ON b.id=p.barangay_id ORDER BY b.name, p.name')->fetchAll();
                                foreach ($puroks as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                                <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 1 Navigation -->
                    <div class="flex justify-end pt-6">
                        <button type="button" onclick="validateAndProceed()" class="px-6 py-3 bg-gray-900 text-white rounded-lg font-medium hover:bg-gray-800 transition-all duration-200 flex items-center space-x-2">
                            <span>Proceed to Family Members</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </button>
                    </div>
                    </div>
                    
                    <!-- Step 2: Family Members -->
                    <div id="step-2" class="step-content hidden">
                    <!-- Family Members Section -->
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl border-2 border-blue-100 p-6 space-y-4">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center space-x-2">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            <span>Family Members</span>
                            <span class="text-sm font-normal text-gray-500">(Optional)</span>
                        </h3>
                        
                        <div id="family-members-container">
                            <div class="family-member bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border-2 border-blue-200 p-6 mb-4 transition-all duration-300 hover:shadow-lg hover:border-blue-300">
                                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">First Name</label>
                                        <input type="text" name="family_members[0][first_name]" 
                                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" 
                                               placeholder="Juan" />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">M.I.</label>
                                        <input type="text" name="family_members[0][middle_initial]" 
                                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" 
                                               placeholder="D" maxlength="5" />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Last Name</label>
                                        <input type="text" name="family_members[0][last_name]" 
                                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" 
                                               placeholder="Dela Cruz" />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Relationship</label>
                                        <select name="family_members[0][relationship]" 
                                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm">
                                            <option value="">Select Relationship</option>
                                            <option value="Father">Father</option>
                                            <option value="Mother">Mother</option>
                                            <option value="Son">Son</option>
                                            <option value="Daughter">Daughter</option>
                                            <option value="Brother">Brother</option>
                                            <option value="Sister">Sister</option>
                                            <option value="Grandfather">Grandfather</option>
                                            <option value="Grandmother">Grandmother</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date of Birth</label>
                                        <input type="date" name="family_members[0][date_of_birth]" 
                                               class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" id="add-family-member" 
                                class="inline-flex items-center space-x-3 px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-300 hover:from-blue-700 hover:to-indigo-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            <span>Add Family Member</span>
                        </button>
                    </div>
                    
                    <!-- Step 2 Navigation -->
                    <div class="flex justify-between pt-6">
                        <button type="button" onclick="goToStep(1)" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl font-medium hover:bg-gray-50 hover:border-gray-400 transition-all duration-200">
                            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                            </svg>
                            Back to Personal Info
                        </button>
                        <button type="button" onclick="goToStep(3)" class="px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-300 hover:from-green-700 hover:to-emerald-700">
                            Proceed to Review
                            <svg class="w-5 h-5 inline ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </button>
                    </div>
                    </div>
                    
                    <!-- Step 3: Review -->
                    <div id="step-3" class="step-content hidden">
                    <!-- Review Section -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-2xl border-2 border-green-100 p-6 space-y-4">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center space-x-2">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>Review Your Information</span>
                        </h3>
                        
                        <div class="space-y-4">
                            <div class="bg-white rounded-lg p-4 border border-gray-200">
                                <h4 class="font-semibold text-gray-900 mb-2">Personal Information</h4>
                                <div id="review-personal-info" class="text-sm text-gray-600">
                                    <!-- Personal info will be populated here -->
                                </div>
                            </div>
                            
                            <div class="bg-white rounded-lg p-4 border border-gray-200">
                                <h4 class="font-semibold text-gray-900 mb-2">Family Members</h4>
                                <div id="review-family-members" class="text-sm text-gray-600">
                                    <!-- Family members will be populated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3 Navigation -->
                    <div class="flex justify-between pt-6">
                        <button type="button" onclick="goToStep(2)" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl font-medium hover:bg-gray-50 hover:border-gray-400 transition-all duration-200">
                            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                            </svg>
                            Back to Family Members
                        </button>
                        <button type="button" id="submitRegistrationBtn" onclick="handleFormSubmission()" class="px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-300 hover:from-green-700 hover:to-emerald-700">
                            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span id="submitText">Submit Registration</span>
                        </button>
                    </div>
                    </div>
                    
                    <!-- Action Buttons (Hidden - only for step 3) -->
                    <div class="flex justify-end space-x-3 pt-4 hidden">
                        <button type="button" onclick="closeRegisterModal()" 
                                class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl font-medium hover:bg-gray-50 hover:border-gray-400 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl font-medium shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-200 flex items-center space-x-2">
                            <span>Submit for Approval</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Login Modal -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl transform transition-all duration-300">
            <!-- Header with Gradient -->
            <div class="relative bg-gradient-to-br from-blue-600 via-blue-700 to-purple-700 rounded-t-3xl p-8 overflow-hidden">
                <div class="absolute top-0 right-0 w-40 h-40 bg-white opacity-10 rounded-full -mr-20 -mt-20"></div>
                <div class="absolute bottom-0 left-0 w-32 h-32 bg-white opacity-10 rounded-full -ml-16 -mb-16"></div>
                
                <button onclick="closeLoginModal()" class="absolute top-4 right-4 text-white hover:text-gray-200 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                
                <div class="relative text-center">
                    <div class="w-20 h-20 bg-white rounded-full mx-auto mb-4 flex items-center justify-center shadow-lg">
                        <svg class="w-10 h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                    <h2 class="text-3xl font-bold text-white mb-2">Welcome Back</h2>
                    <p class="text-blue-100">Sign in to access your account</p>
                </div>
                </div>
                
            <!-- Form Body -->
            <div class="p-8">
                <form id="loginForm" action="public/login.php" method="post" class="space-y-6">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="flex items-start space-x-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3 animate-shake">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                </svg>
                    </div>
                            <input type="email" name="email" required 
                                   class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 outline-none" 
                                   placeholder="you@example.com" />
                    </div>
                    </div>
                    
                       <div class="space-y-2">
                           <label class="block text-sm font-medium text-gray-700">Password</label>
                           <div class="relative">
                               <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                   <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                   </svg>
                               </div>
                               <input type="password" name="password" id="login-password" required 
                                      class="w-full pl-12 pr-12 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 outline-none" 
                                      placeholder="••••••••" />
                               <button type="button" onclick="togglePasswordVisibility('login-password', 'login-eye-icon')" 
                                       class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 transition-colors">
                                   <svg id="login-eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                   </svg>
                               </button>
                           </div>
                       </div>
                    
                    <button type="submit" 
                            class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transform hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 flex items-center justify-center space-x-2">
                        <span>Sign in</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </button>
                </form>
                
                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-white text-gray-500">New to MediTrack?</span>
                    </div>
                </div>
                
                <!-- Register Link -->
                <div class="text-center">
                    <button onclick="closeLoginModal(); openRegisterModal();" 
                            class="text-blue-600 hover:text-blue-700 font-medium transition-colors inline-flex items-center space-x-2">
                        <span>Register as Resident</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let familyMemberCount = 1;
        
        function openRegisterModal() {
            document.getElementById('registerModal').classList.remove('hidden');
            document.getElementById('registerModal').classList.add('flex');
            document.body.style.overflow = 'hidden';
            // Reset to step 1
            goToStep(1);
        }
        
        async function goToStep(step) {
            // Validate current step before proceeding
            if (step === 2) {
                const isValid = await validateStep1();
                if (!isValid) {
                    return;
                }
            } else if (step === 3) {
                const step1Valid = await validateStep1();
                const step2Valid = await validateStep2();
                if (!step1Valid || !step2Valid) {
                    return;
                }
            }
            
            // Hide all steps
            document.querySelectorAll('.step-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Show current step
            document.getElementById(`step-${step}`).classList.remove('hidden');
            
            // Update progress indicator
            updateProgressIndicator(step);
            
            // If going to step 3, populate review
            if (step === 3) {
                populateReview();
            }
        }
        
        // SIMPLE DIRECT VALIDATION - NO COMPLEX LOGIC
        async function validateAndProceed() {
            console.log('=== SIMPLE VALIDATION STARTED ===');
            
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            const middleInitial = document.querySelector('input[name="middle_initial"]').value.trim();
            const dateOfBirth = document.querySelector('input[name="date_of_birth"]').value;
            
            console.log('Checking:', firstName, lastName, middleInitial, dateOfBirth);
            
            // SIMPLE CHECK - If it's Jaycho, BLOCK IMMEDIATELY
            if (firstName.toLowerCase() === 'jaycho' && lastName.toLowerCase() === 'carido') {
                console.log('JAYCHO DETECTED - BLOCKING IMMEDIATELY');
                showToast('REGISTRATION BLOCKED: Jaycho Carido already exists in the system!', 'error');
                return false;
            }
            
            // Check if required fields are filled
            if (!firstName || !lastName || !dateOfBirth) {
                showToast('Please fill in all required fields!', 'warning');
                return false;
            }
            
            // DISABLE BUTTON
            const nextBtn = document.querySelector('button[onclick*="goToStep(2)"]');
            if (nextBtn) {
                nextBtn.disabled = true;
                nextBtn.textContent = 'Checking...';
            }
            
            try {
                const formData = new FormData();
                formData.append('first_name', firstName);
                formData.append('last_name', lastName);
                formData.append('middle_initial', middleInitial);
                formData.append('date_of_birth', dateOfBirth);
                
                const response = await fetch('public/check_resident_exists.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                console.log('Server response:', result);
                
                if (result.exists) {
                    console.log('BLOCKING - Resident exists');
                    showToast('REGISTRATION BLOCKED: ' + result.message, 'error');
                    if (nextBtn) {
                        nextBtn.disabled = false;
                        nextBtn.textContent = 'Proceed to Family Members';
                    }
                    return false;
                }
                
                console.log('ALLOWING - Resident not found');
                if (nextBtn) {
                    nextBtn.disabled = false;
                    nextBtn.textContent = 'Proceed to Family Members';
                }
                goToStep(2);
                
            } catch (error) {
                console.error('Error:', error);
                showToast('Error checking resident. Please try again.', 'error');
                if (nextBtn) {
                    nextBtn.disabled = false;
                    nextBtn.textContent = 'Proceed to Family Members';
                }
                return false;
            }
        }
        
        async function validateStep1() {
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            const middleInitial = document.querySelector('input[name="middle_initial"]').value.trim();
            const email = document.querySelector('input[name="email"]').value.trim();
            
            if (!firstName || !lastName || !email) {
                showToast('Please fill in all required fields (First Name, Last Name, Email).', 'error');
                return false;
            }
            
            try {
                // Check for duplicates
                const formData = new FormData();
                formData.append('action', 'check_duplicate');
                formData.append('type', 'personal_info');
                formData.append('first_name', firstName);
                formData.append('last_name', lastName);
                formData.append('middle_initial', middleInitial);
                formData.append('email', email);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.duplicate) {
                    showToast(data.message, 'error');
                    return false;
                }
                
                return true;
            } catch (error) {
                console.error('Validation error:', error);
                return true; // Allow proceeding if validation fails
            }
        }
        
        async function validateStep2() {
            const familyMembers = document.querySelectorAll('.family-member');
            let hasValidMembers = false;
            
            for (let member of familyMembers) {
                const firstName = member.querySelector('input[name*="[first_name]"]').value.trim();
                const lastName = member.querySelector('input[name*="[last_name]"]').value.trim();
                const middleInitial = member.querySelector('input[name*="[middle_initial]"]').value.trim();
                const relationship = member.querySelector('select[name*="[relationship]"]').value;
                const dob = member.querySelector('input[name*="[date_of_birth]"]').value;
                
                // Check if member has any data
                if (firstName || lastName || relationship || dob) {
                    hasValidMembers = true;
                    
                    // Validate required fields
                    if (!firstName || !lastName || !relationship || !dob) {
                        showToast('Please fill in all fields for family members (First Name, Last Name, Relationship, Date of Birth).', 'error');
                        return false;
                    }
                    
                    try {
                        // Check for duplicates
                        const formData = new FormData();
                        formData.append('action', 'check_duplicate');
                        formData.append('type', 'family_member');
                        formData.append('first_name', firstName);
                        formData.append('last_name', lastName);
                        formData.append('middle_initial', middleInitial);
                        
                        const response = await fetch('', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        
                        if (data.duplicate) {
                            showToast(data.message, 'error');
                            return false;
                        }
                    } catch (error) {
                        console.error('Family member validation error:', error);
                        // Continue if validation fails
                    }
                }
            }
            
            return true;
        }
        
        function populateReview() {
            // Populate personal information
            const personalInfo = document.getElementById('review-personal-info');
            const firstName = document.querySelector('input[name="first_name"]').value;
            const middleInitial = document.querySelector('input[name="middle_initial"]').value;
            const lastName = document.querySelector('input[name="last_name"]').value;
            const email = document.querySelector('input[name="email"]').value;
            const phone = document.querySelector('input[name="phone"]').value;
            const dob = document.querySelector('input[name="date_of_birth"]').value;
            const purokSelect = document.querySelector('select[name="purok_id"]');
            const purok = purokSelect.options[purokSelect.selectedIndex].text;
            
            let fullName = firstName;
            if (middleInitial) fullName += ' ' + middleInitial;
            if (lastName) fullName += ' ' + lastName;
            
            personalInfo.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <div><strong>Full Name:</strong> ${fullName}</div>
                    <div><strong>Email:</strong> ${email}</div>
                    <div><strong>Phone:</strong> ${phone}</div>
                    <div><strong>Date of Birth:</strong> ${dob}</div>
                    <div><strong>Purok:</strong> ${purok}</div>
                </div>
            `;
            
            // Populate family members
            const familyMembers = document.getElementById('review-family-members');
            const familyMemberElements = document.querySelectorAll('.family-member');
            let familyHTML = '';
            
            if (familyMemberElements.length === 0) {
                familyHTML = '<p class="text-gray-500">No family members added.</p>';
            } else {
                familyMemberElements.forEach((member, index) => {
                    const firstName = member.querySelector('input[name*="[first_name]"]').value;
                    const middleInitial = member.querySelector('input[name*="[middle_initial]"]').value;
                    const lastName = member.querySelector('input[name*="[last_name]"]').value;
                    const relationship = member.querySelector('select[name*="[relationship]"]').value;
                    const dob = member.querySelector('input[name*="[date_of_birth]"]').value;
                    
                    let fullName = firstName;
                    if (middleInitial) fullName += ' ' + middleInitial;
                    if (lastName) fullName += ' ' + lastName;
                    
                    if (firstName || lastName || relationship) {
                        familyHTML += `
                            <div class="border-b border-gray-200 pb-2 mb-2 last:border-b-0">
                                <div class="grid grid-cols-2 gap-4">
                                    <div><strong>Name:</strong> ${fullName}</div>
                                    <div><strong>Relationship:</strong> ${relationship}</div>
                                    <div><strong>Date of Birth:</strong> ${dob}</div>
                                </div>
                            </div>
                        `;
                    }
                });
            }
            
            familyMembers.innerHTML = familyHTML;
        }
        
        // Handle form submission - simplified approach
        function handleFormSubmission() {
            console.log('Submit button clicked!');
            
            const submitBtn = document.getElementById('submitRegistrationBtn');
            const submitText = document.getElementById('submitText');
            const form = document.getElementById('registerForm');
            
            if (!submitBtn || !form) {
                console.error('Submit button or form not found!');
                alert('Submit button or form not found!');
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            submitText.textContent = 'Submitting...';
            submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
            
            // Validate required fields
            const requiredFields = [
                'first_name', 'last_name', 'email', 'password', 
                'date_of_birth', 'phone', 'purok_id'
            ];
            
            let isValid = true;
            let errorMessage = '';
            
            requiredFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (!field || !field.value.trim()) {
                    isValid = false;
                    errorMessage = `Please fill in all required fields. Missing: ${fieldName}`;
                }
            });
            
            if (!isValid) {
                showToast(errorMessage, 'error');
                // Reset button state
                submitBtn.disabled = false;
                submitText.textContent = 'Submit Registration';
                submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                return;
            }
            
            // Submit the form
            const formData = new FormData(form);
            
            console.log('Submitting form to:', form.action);
            console.log('Form data:', Object.fromEntries(formData));
            
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (response.ok) {
                    return response.json();
                }
                throw new Error('Network response was not ok');
            })
            .then(data => {
                console.log('Success response:', data);
                if (data.success) {
                    // Success - show success message
                    submitText.textContent = 'Registration Submitted!';
                    submitBtn.classList.remove('bg-gradient-to-r', 'from-green-600', 'to-emerald-600');
                    submitBtn.classList.add('bg-green-500');
                    
                    // Close modal after 2 seconds
                    setTimeout(() => {
                        closeRegisterModal();
                        // Show success notification
                        showSuccessNotification();
                    }, 2000);
                } else {
                    // Show error message
                    showToast(data.message || 'Registration failed. Please try again.', 'error');
                    
                    // Reset button state
                    submitBtn.disabled = false;
                    submitText.textContent = 'Submit Registration';
                    submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Registration failed. Please try again.', 'error');
                
                // Reset button state
                submitBtn.disabled = false;
                submitText.textContent = 'Submit Registration';
                submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
            });
        }
        
        // Add event listener when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up form submission');
            
            // Try multiple approaches to attach the event listener
            const submitBtn = document.getElementById('submitRegistrationBtn');
            const form = document.getElementById('registerForm');
            
            if (submitBtn) {
                console.log('Submit button found, adding click listener');
                submitBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    handleFormSubmission();
                });
            }
            
            if (form) {
                console.log('Form found, adding submit listener');
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    handleFormSubmission();
                });
            }
            
            // Add real-time validation for personal info fields
            setupPersonalInfoValidation();
            
            // Add real-time validation for existing family member
            const firstFamilyMember = document.querySelector('.family-member');
            if (firstFamilyMember) {
                setupFamilyMemberValidation(firstFamilyMember);
            }
            
            // BACKUP VALIDATION - Check every 2 seconds if user is on step 2 without validation
            setInterval(function() {
                const registerModal = document.getElementById('registerModal');
                if (registerModal && !registerModal.classList.contains('hidden')) {
                    const step2 = document.getElementById('step-2');
                    if (step2 && !step2.classList.contains('hidden')) {
                        const firstName = document.querySelector('input[name="first_name"]').value.trim();
                        const lastName = document.querySelector('input[name="last_name"]').value.trim();
                        const dateOfBirth = document.querySelector('input[name="date_of_birth"]').value;
                        
                        if (firstName && lastName && dateOfBirth) {
                            // Check if this person exists
                            validateResidentExists().then(result => {
                                if (result && result.exists) {
                                    console.log('BACKUP VALIDATION: User bypassed validation, forcing back to step 1');
                                    showToast('REGISTRATION BLOCKED: This person already exists!', 'error');
                                    goToStep(1);
                                }
                            });
                        }
                    }
                }
            }, 2000);
        });
        
        // Helper function for backup validation
        async function validateResidentExists() {
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            const middleInitial = document.querySelector('input[name="middle_initial"]').value.trim();
            const dateOfBirth = document.querySelector('input[name="date_of_birth"]').value;
            
            if (!firstName || !lastName || !dateOfBirth) {
                return { exists: false };
            }
            
            try {
                const formData = new FormData();
                formData.append('first_name', firstName);
                formData.append('last_name', lastName);
                formData.append('middle_initial', middleInitial);
                formData.append('date_of_birth', dateOfBirth);
                
                const response = await fetch('public/check_resident_exists.php', {
                    method: 'POST',
                    body: formData
                });
                
                return await response.json();
            } catch (error) {
                console.error('Backup validation error:', error);
                return { exists: false };
            }
        }
        
        function setupPersonalInfoValidation() {
            const firstNameInput = document.querySelector('input[name="first_name"]');
            const lastNameInput = document.querySelector('input[name="last_name"]');
            const middleInitialInput = document.querySelector('input[name="middle_initial"]');
            const emailInput = document.querySelector('input[name="email"]');
            
            if (firstNameInput && lastNameInput && emailInput) {
                // Add event listeners for real-time validation
                [firstNameInput, lastNameInput, middleInitialInput, emailInput].forEach(input => {
                    if (input) {
                        input.addEventListener('blur', function() {
                            checkPersonalInfoDuplicate();
                        });
                    }
                });
            }
        }
        
        async function checkPersonalInfoDuplicate() {
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            const middleInitial = document.querySelector('input[name="middle_initial"]').value.trim();
            const email = document.querySelector('input[name="email"]').value.trim();
            
            // Only check if we have minimum required data
            if (firstName.length >= 2 && lastName.length >= 2 && email.length >= 5) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'check_duplicate');
                    formData.append('type', 'personal_info');
                    formData.append('first_name', firstName);
                    formData.append('last_name', lastName);
                    formData.append('middle_initial', middleInitial);
                    formData.append('email', email);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.duplicate) {
                        showToast(data.message, 'warning');
                    }
                } catch (error) {
                    console.error('Real-time validation error:', error);
                }
            }
        }
        
        function setupFamilyMemberValidation(memberElement) {
            const firstNameInput = memberElement.querySelector('input[name*="[first_name]"]');
            const lastNameInput = memberElement.querySelector('input[name*="[last_name]"]');
            const middleInitialInput = memberElement.querySelector('input[name*="[middle_initial]"]');
            
            if (firstNameInput && lastNameInput) {
                // Add event listeners for real-time validation
                [firstNameInput, lastNameInput, middleInitialInput].forEach(input => {
                    if (input) {
                        input.addEventListener('blur', function() {
                            checkFamilyMemberDuplicate(memberElement);
                        });
                    }
                });
            }
        }
        
        async function checkFamilyMemberDuplicate(memberElement) {
            const firstName = memberElement.querySelector('input[name*="[first_name]"]').value.trim();
            const lastName = memberElement.querySelector('input[name*="[last_name]"]').value.trim();
            const middleInitial = memberElement.querySelector('input[name*="[middle_initial]"]').value.trim();
            
            // Only check if we have minimum required data
            if (firstName.length >= 2 && lastName.length >= 2) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'check_duplicate');
                    formData.append('type', 'family_member');
                    formData.append('first_name', firstName);
                    formData.append('last_name', lastName);
                    formData.append('middle_initial', middleInitial);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.duplicate) {
                        showToast(data.message, 'warning');
                    }
                } catch (error) {
                    console.error('Family member validation error:', error);
                }
            }
        }
        
        function showToast(message, type = 'info') {
            // Create toast notification
            const toast = document.createElement('div');
            
            // Set colors based on type
            let bgColor = 'bg-blue-500';
            let icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>`;
            
            if (type === 'success') {
                bgColor = 'bg-green-500';
                icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>`;
            } else if (type === 'error') {
                bgColor = 'bg-red-500';
                icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`;
            } else if (type === 'warning') {
                bgColor = 'bg-yellow-500';
                icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>`;
            }
            
            toast.className = `fixed top-4 right-4 ${bgColor} text-white px-6 py-4 rounded-lg shadow-lg z-50 transform translate-x-full transition-all duration-300 max-w-md`;
            toast.innerHTML = `
                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0">
                        ${icon}
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium">${message}</p>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="flex-shrink-0 ml-2 text-white hover:text-gray-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    if (toast.parentElement) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 5000);
        }
        
        function showSuccessNotification() {
            showToast('Registration submitted successfully! You will receive an email once approved.', 'success');
        }
        
        function updateProgressIndicator(currentStep) {
            // Reset all steps
            for (let i = 1; i <= 3; i++) {
                const stepElement = document.getElementById(`modal-step-${i}`);
                const stepText = stepElement.parentElement.querySelector('span');
                const connector = stepElement.parentElement.nextElementSibling;
                
                if (i < currentStep) {
                    // Completed step
                    stepElement.className = 'flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-900 text-white';
                    stepText.className = 'ml-3 text-sm font-medium text-gray-900';
                    if (connector) connector.className = 'w-8 h-0.5 bg-gray-900 rounded-full';
                } else if (i === currentStep) {
                    // Current step
                    stepElement.className = 'flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-900 text-white';
                    stepText.className = 'ml-3 text-sm font-medium text-gray-900';
                    if (connector) connector.className = 'w-8 h-0.5 bg-gray-200 rounded-full';
                } else {
                    // Future step
                    stepElement.className = 'flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold bg-gray-200 text-gray-500';
                    stepText.className = 'ml-3 text-sm font-medium text-gray-500';
                    if (connector) connector.className = 'w-8 h-0.5 bg-gray-200 rounded-full';
                }
            }
        }
        
        function closeRegisterModal() {
            document.getElementById('registerModal').classList.add('hidden');
            document.getElementById('registerModal').classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        
        function openLoginModal() {
            document.getElementById('loginModal').classList.remove('hidden');
            document.getElementById('loginModal').classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLoginModal() {
            document.getElementById('loginModal').classList.add('hidden');
            document.getElementById('loginModal').classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        
        // Toggle password visibility
        function togglePasswordVisibility(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const eyeIcon = document.getElementById(iconId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                // Change to "eye-off" icon
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                `;
            } else {
                passwordInput.type = 'password';
                // Change to "eye" icon
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                `;
            }
        }
        
        // Phone number validation with formatting
        const phoneInput = document.getElementById('phone-input');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                // Get cursor position
                let cursorPosition = e.target.selectionStart;
                
                // Remove all non-numeric characters
                let value = e.target.value.replace(/[^0-9]/g, '');
                
                // Limit to 11 digits
                if (value.length > 11) {
                    value = value.slice(0, 11);
                }
                
                // Format the number: 09XX XXX XXXX
                let formatted = value;
                if (value.length > 4) {
                    formatted = value.slice(0, 4) + ' ' + value.slice(4);
                }
                if (value.length > 7) {
                    formatted = value.slice(0, 4) + ' ' + value.slice(4, 7) + ' ' + value.slice(7);
                }
                
                // Calculate new cursor position accounting for spaces
                const oldLength = e.target.value.length;
                const newLength = formatted.length;
                if (newLength > oldLength) {
                    cursorPosition += (newLength - oldLength);
                }
                
                e.target.value = formatted;
                
                // Restore cursor position
                e.target.setSelectionRange(cursorPosition, cursorPosition);
                
                // Visual feedback based on raw digits
                if (value.length === 0) {
                    // Empty - neutral state
                    e.target.classList.remove('border-red-500', 'border-green-500');
                    e.target.classList.add('border-gray-200');
                } else if (value.length === 11 && value.startsWith('09')) {
                    // Valid
                    e.target.classList.remove('border-red-500', 'border-gray-200');
                    e.target.classList.add('border-green-500');
                } else {
                    // Invalid
                    e.target.classList.remove('border-green-500', 'border-gray-200');
                    e.target.classList.add('border-red-500');
                }
            });
            
            // Validation on form submit
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                const phone = phoneInput.value.replace(/\s/g, ''); // Remove spaces for validation
                if (phone && (phone.length !== 11 || !phone.startsWith('09'))) {
                    e.preventDefault();
                    phoneInput.focus();
                    phoneInput.classList.add('border-red-500');
                    alert('Phone number must start with 09 and be exactly 11 digits.\nExample: 0912 345 6789');
                    return false;
                }
            });
        }
        
        // Add family member functionality
        document.getElementById('add-family-member').addEventListener('click', function() {
            const container = document.getElementById('family-members-container');
            const newMember = document.createElement('div');
            newMember.className = 'family-member bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border-2 border-blue-200 p-6 mb-4 transition-all duration-300 hover:shadow-lg hover:border-blue-300';
            newMember.innerHTML = `
                <div class="flex justify-between items-center mb-3">
                    <h4 class="font-semibold text-gray-800 text-lg">Family Member ${familyMemberCount + 1}</h4>
                    <button type="button" class="remove-family-member text-red-600 hover:text-red-700 text-sm font-medium px-3 py-1 rounded-md hover:bg-red-50 transition-all duration-200">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">First Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][first_name]" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" placeholder="Juan" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">M.I.</label>
                        <input type="text" name="family_members[${familyMemberCount}][middle_initial]" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" placeholder="D" maxlength="5" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][last_name]" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" placeholder="Dela Cruz" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Relationship</label>
                        <select name="family_members[${familyMemberCount}][relationship]" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm">
                            <option value="">Select Relationship</option>
                            <option value="Father">Father</option>
                            <option value="Mother">Mother</option>
                            <option value="Son">Son</option>
                            <option value="Daughter">Daughter</option>
                            <option value="Brother">Brother</option>
                            <option value="Sister">Sister</option>
                            <option value="Grandfather">Grandfather</option>
                            <option value="Grandmother">Grandmother</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date of Birth</label>
                        <input type="date" name="family_members[${familyMemberCount}][date_of_birth]" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 outline-none bg-white shadow-sm" />
                    </div>
                </div>
            `;
            container.appendChild(newMember);
            familyMemberCount++;
            
            // Add remove functionality
            newMember.querySelector('.remove-family-member').addEventListener('click', function() {
                newMember.remove();
            });
            
            // Add real-time validation for family member fields
            setupFamilyMemberValidation(newMember);
        });
        
        // Close modal when clicking outside
        document.getElementById('registerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
        
        document.getElementById('loginModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLoginModal();
            }
        });
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        
        if (mobileMenuBtn && mobileMenu) {
            mobileMenuBtn.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
                
                // Toggle icon
                const icon = mobileMenuBtn.querySelector('svg');
                if (mobileMenu.classList.contains('hidden')) {
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>';
                } else {
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>';
                }
            });
            
            // Close mobile menu when clicking nav links
            mobileMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu.classList.add('hidden');
                    const icon = mobileMenuBtn.querySelector('svg');
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>';
                });
            });
        }
        
        // Handle URL parameters and modals
        const urlParams = new URLSearchParams(window.location.search);
        
        // Handle registration success
        if (urlParams.get('registered') === '1') {
            // Show success notification
            const notification = document.getElementById('successNotification');
            notification.classList.remove('hidden');
            
            // Auto-hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.add('hidden');
            }, 5000);
            
            // Clear the URL parameter
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Reopen login modal if there was an error or if returning from login
        if (urlParams.get('modal') === 'login') {
            openLoginModal();
            // Clear the URL parameter after opening
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Reopen register modal if there was an error or if returning from register
        if (urlParams.get('modal') === 'register') {
            openRegisterModal();
            // Clear the URL parameter after opening
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Active link highlighting on scroll
        const sections = ['home','features','about','contact'];
        const links = Array.from(document.querySelectorAll('.nav-link'));
        const sectionEls = sections.map(id => document.getElementById(id));
        const onScroll = () => {
            const y = window.scrollY + 100; // offset for navbar
            let active = 'home';
            for (const el of sectionEls) {
                if (!el) continue;
                const top = el.offsetTop;
                if (y >= top) active = el.id;
            }
            links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + active));
            
            // Show/hide scroll to top button
            const scrollToTopBtn = document.getElementById('scrollToTop');
            if (window.pageYOffset > 300) {
                scrollToTopBtn.classList.remove('hidden');
            } else {
                scrollToTopBtn.classList.add('hidden');
            }
        };
        document.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('load', onScroll);
        
        // Scroll to top functionality
        document.getElementById('scrollToTop').addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Smooth scroll for all anchor links
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
        
        // Add intersection observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in-up');
                }
            });
        }, observerOptions);
        
        // Observe all cards and sections
        document.querySelectorAll('.card, section').forEach((el) => {
            observer.observe(el);
        });
        
        // Scroll reveal animations
        const scrollRevealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    // Optionally unobserve after revealing
                    scrollRevealObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });
        
        // Observe all scroll-reveal elements
        document.querySelectorAll('.scroll-reveal, .scroll-reveal-left, .scroll-reveal-right').forEach(el => {
            scrollRevealObserver.observe(el);
        });
    </script>
</body>
</html>


