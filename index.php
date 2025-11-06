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
    
    // Check if email is verified
    if (!isset($_SESSION['email_verified']) || $_SESSION['email_verified'] !== true) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please verify your email address first']);
        exit;
    }
    
    // Verify that the email matches the verified email
    $email = trim($_POST['email'] ?? '');
    $verified_email = $_SESSION['verified_email'] ?? '';
    
    if ($email !== $verified_email) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Email mismatch. Please verify your email again.']);
        exit;
    }
    
    // Debug: Log all POST data
    error_log('POST data received in index.php: ' . print_r($_POST, true));
    file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - POST data: ' . print_r($_POST, true) . "\n", FILE_APPEND);
    
    // Sanitize and validate input data
    $password = $_POST['password'] ?? '';
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $middle = trim($_POST['middle_initial'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $barangay_id = (int)($_POST['barangay_id'] ?? 0);
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
    } else {
        // Age validation - must be 18+
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        
        if ($age < 18) {
            $errors[] = 'You must be at least 18 years old to register.';
        }
    }
    
    // Middle initial validation - if provided, must be exactly 1 character
    if (!empty($middle) && strlen(trim($middle)) > 1) {
        $errors[] = 'Middle initial can only be 1 character.';
    }
    
    if (empty($phone)) {
        $errors[] = 'Please enter your phone number.';
    }
    
    if (empty($barangay_id)) {
        $errors[] = 'Please select your barangay.';
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
                
                // Middle initial validation for family members
                if (!empty($middle_initial) && strlen(trim($middle_initial)) > 1) {
                    $errors[] = "Family member " . ($index + 1) . ": Middle initial can only be 1 character.";
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
            
            // Get barangay and purok names for address generation
            $barangayQuery = db()->prepare('SELECT name FROM barangays WHERE id = ? LIMIT 1');
            $barangayQuery->execute([$barangay_id]);
            $barangayRow = $barangayQuery->fetch();
            $barangayName = $barangayRow ? $barangayRow['name'] : '';
            
            $purokQuery = db()->prepare('SELECT name FROM puroks WHERE id = ? LIMIT 1');
            $purokQuery->execute([$purok_id]);
            $purokRow = $purokQuery->fetch();
            $purokName = $purokRow ? $purokRow['name'] : '';
            
            // Generate address in format: "Purok X, BarangayName"
            $address = $purokName && $barangayName ? "$purokName, $barangayName" : '';
            
            // Insert into pending_residents table (email is already verified at this point)
            $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, middle_initial, date_of_birth, phone, address, barangay_id, purok_id, email_verified) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $result = $insPending->execute([$email, $hash, $first, $last, $middle, $dob, $phone, $address, $barangay_id, $purok_id, 1]);
            
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
            
            // Clear verification session BEFORE sending response
            unset($_SESSION['email_verified'], $_SESSION['verified_email']);
            
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
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
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
    <link rel="stylesheet" href="public/assets/css/design-system.css?v=<?php echo time(); ?>">
    <script src="https://cdn.tailwindcss.com?v=<?php echo time(); ?>"></script>
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
          background: linear-gradient(180deg, rgba(239,246,255,.95), rgba(224,242,254,.90));
          border:1px solid rgba(147,197,253,.3); border-radius:1.5rem;
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
        
        /* Enhanced Scroll reveal animations */
        .scroll-reveal {
            opacity: 0;
            transform: translateY(50px) scale(0.95);
            transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .scroll-reveal.active {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        .scroll-reveal-left {
            opacity: 0;
            transform: translateX(-50px) rotateY(-10deg);
            transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .scroll-reveal-left.active {
            opacity: 1;
            transform: translateX(0) rotateY(0deg);
        }
        .scroll-reveal-right {
            opacity: 0;
            transform: translateX(50px) rotateY(10deg);
            transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .scroll-reveal-right.active {
            opacity: 1;
            transform: translateX(0) rotateY(0deg);
        }
        .scroll-reveal-scale {
            opacity: 0;
            transform: scale(0.8);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }
        .scroll-reveal-scale.active {
            opacity: 1;
            transform: scale(1);
        }
        
        /* Enhanced card animations */
        .card {
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            transform-style: preserve-3d;
        }
        
        .card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(139, 92, 246, 0.05));
            opacity: 0;
            transition: opacity 0.5s ease;
            pointer-events: none;
        }
        
        .card:hover::after {
            opacity: 1;
        }
        
        .card:hover {
            transform: translateY(-8px) rotateX(2deg);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(59, 130, 246, 0.1);
        }
        
        /* Enhanced hero text animation */
        .hero-title {
            background: linear-gradient(135deg, #1e293b 0%, #3b82f6 50%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% auto;
            animation: gradientShift 4s ease infinite;
        }
        
        /* Floating animation for hero elements */
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        .float-animation-delayed {
            animation: float 6s ease-in-out infinite;
            animation-delay: 1.5s;
        }
        
        /* Pulse glow effect */
        @keyframes pulseGlow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
            }
            50% {
                box-shadow: 0 0 40px rgba(59, 130, 246, 0.6), 0 0 60px rgba(139, 92, 246, 0.4);
            }
        }
        
        .pulse-glow {
            animation: pulseGlow 3s ease-in-out infinite;
        }
        
        /* Stagger animation for feature cards */
        .feature-card {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        
        .feature-card.active {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Enhanced button ripple effect */
        .btn-ripple {
            position: relative;
            overflow: hidden;
        }
        
        .btn-ripple::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn-ripple:active::before {
            width: 300px;
            height: 300px;
        }
        
        /* Text typing animation */
        @keyframes typing {
            from { width: 0; }
            to { width: 100%; }
        }
        
        @keyframes blink {
            from, to { border-color: transparent; }
            50% { border-color: #3b82f6; }
        }
        
        /* Enhanced navigation */
        nav {
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        nav.scrolled {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            background: rgba(255, 255, 255, 0.95);
        }
        
        /* Navbar alignment - ensure logo left, hamburger right */
        nav > div > div {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            width: 100% !important;
        }
        
        /* Logo container - force left, no margin issues */
        nav > div > div > div:first-child {
            margin-right: auto !important;
            margin-left: 0 !important;
            flex-shrink: 0 !important;
        }
        
        /* Hamburger/CTA container - force right, no margin issues */
        nav > div > div > div:last-child {
            margin-left: auto !important;
            margin-right: 0 !important;
            flex-shrink: 0 !important;
        }
        
        
        /* Parallax effect */
        .parallax-element {
            transition: transform 0.1s ease-out;
        }
        
        /* Gradient border animation */
        @keyframes borderGradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .gradient-border {
            position: relative;
            background: white;
            border-radius: 1rem;
        }
        
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 1rem;
            padding: 2px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899);
            background-size: 200% 200%;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            animation: borderGradient 3s ease infinite;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .gradient-border:hover::before {
            opacity: 1;
        }
        
        /* Shimmer effect */
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        
        /* Dancing card animation */
        @keyframes dance {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-8px);
            }
        }
        
        .dancing-card {
            animation: dance 2.5s ease-in-out infinite;
        }
        
        /* Mobile Menu Overlay Styles */
        #mobileMenuOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 40;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        #mobileMenuOverlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        #mobileMenu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 85%;
            max-width: 320px;
            height: 100vh;
            background: white;
            z-index: 50;
            transition: right 0.3s ease;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        
        #mobileMenu.active {
            right: 0;
        }
        
        /* Hamburger Animation */
        #mobileMenuBtn {
            position: relative;
            width: 32px;
            height: 32px;
        }
        
        #mobileMenuBtn span {
            display: block;
            position: absolute;
            height: 2px;
            width: 100%;
            background: currentColor;
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: all 0.3s ease;
        }
        
        #mobileMenuBtn span:nth-child(1) {
            top: 8px;
        }
        
        #mobileMenuBtn span:nth-child(2) {
            top: 16px;
        }
        
        #mobileMenuBtn span:nth-child(3) {
            top: 24px;
        }
        
        #mobileMenuBtn.active span:nth-child(1) {
            top: 16px;
            transform: rotate(135deg);
        }
        
        #mobileMenuBtn.active span:nth-child(2) {
            opacity: 0;
            left: -20px;
        }
        
        #mobileMenuBtn.active span:nth-child(3) {
            top: 16px;
            transform: rotate(-135deg);
        }
        
        .shimmer {
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            background-size: 1000px 100%;
            animation: shimmer 2s infinite;
        }
        
        /* Number counter animation */
        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .count-up {
            animation: countUp 0.8s ease forwards;
        }
        
        /* Enhanced modal entrance */
        @keyframes modalEntrance {
            0% {
                opacity: 0;
                transform: scale(0.8) translateY(-20px);
                filter: blur(10px);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0);
                filter: blur(0);
            }
        }
        
        #loginModal > div, #registerModal > div {
            animation: modalEntrance 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        /* Magnetic effect for buttons */
        .magnetic {
            transition: transform 0.3s cubic-bezier(0.23, 1, 0.32, 1);
        }
        
        /* Loading spinner */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .spinner {
            border: 3px solid rgba(59, 130, 246, 0.1);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        /* Text reveal animation */
        @keyframes textReveal {
            from {
                clip-path: inset(0 100% 0 0);
            }
            to {
                clip-path: inset(0 0 0 0);
            }
        }
        
        .text-reveal {
            animation: textReveal 1s ease forwards;
        }
        
        /* Enhanced badge animation */
        .badge-pill {
            animation: scaleIn 0.6s ease forwards;
            transition: transform 0.3s ease;
        }
        
        .badge-pill:hover {
            transform: scale(1.05);
        }
        
        /* Icon rotation on hover */
        .icon-rotate {
            transition: transform 0.3s ease;
        }
        
        .icon-rotate:hover {
            transform: rotate(360deg);
        }
        
        /* 4D Logo Styles */
        .logo-4d-container {
            perspective: 1000px;
            transform-style: preserve-3d;
        }
        
        .logo-4d-panel {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.1) inset;
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.3s ease;
        }
        
        .logo-4d-panel:hover {
            transform: rotateY(-5deg) rotateX(2deg);
        }
        
        .logo-4d-emblem {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
            transform-style: preserve-3d;
        }
        
        .logo-cross {
            position: absolute;
            width: 100%;
            height: 100%;
            transform: translateZ(20px);
        }
        
        .logo-cross::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            height: 28%;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            border-radius: 10px;
            transform: translateY(-50%);
            box-shadow: 
                0 8px 20px rgba(37, 99, 235, 0.4),
                0 3px 10px rgba(0, 0, 0, 0.2),
                inset 0 2px 4px rgba(255, 255, 255, 0.3),
                inset 0 -2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .logo-cross::after {
            content: '';
            position: absolute;
            left: 50%;
            top: 0;
            width: 28%;
            height: 100%;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            border-radius: 10px;
            transform: translateX(-50%);
            box-shadow: 
                0 8px 20px rgba(37, 99, 235, 0.4),
                0 3px 10px rgba(0, 0, 0, 0.2),
                inset 0 2px 4px rgba(255, 255, 255, 0.3),
                inset 0 -2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .logo-pill {
            position: absolute;
            width: 35px;
            height: 80px;
            background: linear-gradient(180deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) translateZ(30px);
            box-shadow: 
                0 10px 25px rgba(0, 0, 0, 0.2),
                0 5px 10px rgba(0, 0, 0, 0.1),
                inset 0 2px 4px rgba(255, 255, 255, 0.8),
                inset 0 -2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .logo-pill-cap {
            position: absolute;
            width: 35px;
            height: 25px;
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
            border-radius: 20px 20px 0 0;
            top: 0;
            left: 0;
            box-shadow: 
                0 5px 10px rgba(34, 197, 94, 0.3),
                inset 0 2px 4px rgba(255, 255, 255, 0.4);
        }
        
        .logo-checkmark {
            position: absolute;
            width: 50px;
            height: 50px;
            bottom: 15px;
            right: 10px;
            transform: translateZ(40px) rotate(-10deg);
        }
        
        .logo-checkmark::before {
            content: '';
            position: absolute;
            width: 8px;
            height: 25px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            border-radius: 4px;
            bottom: 0;
            left: 12px;
            transform: rotate(45deg);
            box-shadow: 
                0 5px 15px rgba(249, 115, 22, 0.4),
                0 2px 5px rgba(0, 0, 0, 0.2),
                inset 0 1px 2px rgba(255, 255, 255, 0.4);
        }
        
        .logo-checkmark::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 18px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            border-radius: 4px;
            bottom: 8px;
            right: 0;
            transform: rotate(-45deg);
            box-shadow: 
                0 5px 15px rgba(249, 115, 22, 0.4),
                0 2px 5px rgba(0, 0, 0, 0.2),
                inset 0 1px 2px rgba(255, 255, 255, 0.4);
        }
        
        .logo-text-3d {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            text-align: center;
            margin-bottom: 0.5rem;
            text-shadow: 
                0 4px 8px rgba(30, 58, 138, 0.3),
                0 2px 4px rgba(0, 0, 0, 0.2),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            transform: translateZ(10px);
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 4px 6px rgba(30, 58, 138, 0.3));
        }
        
        .logo-tagline {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        
        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0) rotateY(0deg);
            }
            50% {
                transform: translateY(-10px) rotateY(5deg);
            }
        }
        
        .logo-4d-emblem {
            animation: logoFloat 6s ease-in-out infinite;
        }
        
        .logo-4d-panel::before {
            content: '';
            position: absolute;
            inset: -2px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 24px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .logo-4d-panel:hover::before {
            opacity: 1;
        }
        
        /* Login Modal Enhancements */
        .login-modal-header {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #7c3aed 100%);
            overflow: hidden;
            position: relative;
        }
        
        .login-modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 4s ease-in-out infinite;
        }
        
        .login-modal-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 5s ease-in-out infinite;
        }
        
        /* Mini Logo for Login Modal */
        .login-logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        
        .login-logo-mini {
            position: relative;
            width: 80px;
            height: 80px;
            transform-style: preserve-3d;
            animation: logoFloat 4s ease-in-out infinite;
        }
        
        .login-logo-cross-mini {
            position: absolute;
            width: 100%;
            height: 100%;
            transform: translateZ(15px);
        }
        
        .login-logo-cross-mini::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            height: 25%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.85) 100%);
            border-radius: 8px;
            transform: translateY(-50%);
            box-shadow: 
                0 6px 15px rgba(0, 0, 0, 0.2),
                inset 0 2px 4px rgba(255, 255, 255, 0.5),
                inset 0 -2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .login-logo-cross-mini::after {
            content: '';
            position: absolute;
            left: 50%;
            top: 0;
            width: 25%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.85) 100%);
            border-radius: 8px;
            transform: translateX(-50%);
            box-shadow: 
                0 6px 15px rgba(0, 0, 0, 0.2),
                inset 0 2px 4px rgba(255, 255, 255, 0.5),
                inset 0 -2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .login-logo-pill-mini {
            position: absolute;
            width: 24px;
            height: 55px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(255, 255, 255, 0.9) 100%);
            border-radius: 12px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) translateZ(20px);
            box-shadow: 
                0 6px 15px rgba(0, 0, 0, 0.25),
                inset 0 2px 4px rgba(255, 255, 255, 0.9),
                inset 0 -2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .login-logo-pill-cap-mini {
            position: absolute;
            width: 24px;
            height: 18px;
            background: linear-gradient(180deg, rgba(34, 197, 94, 0.9) 0%, rgba(22, 163, 74, 0.9) 100%);
            border-radius: 12px 12px 0 0;
            top: 0;
            left: 0;
            box-shadow: 
                0 3px 8px rgba(34, 197, 94, 0.3),
                inset 0 1px 3px rgba(255, 255, 255, 0.5);
        }
        
        .login-logo-checkmark-mini {
            position: absolute;
            width: 35px;
            height: 35px;
            bottom: 10px;
            right: 8px;
            transform: translateZ(25px) rotate(-10deg);
        }
        
        .login-logo-checkmark-mini::before {
            content: '';
            position: absolute;
            width: 6px;
            height: 18px;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.95) 0%, rgba(234, 88, 12, 0.95) 100%);
            border-radius: 3px;
            bottom: 0;
            left: 8px;
            transform: rotate(45deg);
            box-shadow: 
                0 3px 10px rgba(249, 115, 22, 0.4),
                inset 0 1px 2px rgba(255, 255, 255, 0.5);
        }
        
        .login-logo-checkmark-mini::after {
            content: '';
            position: absolute;
            width: 6px;
            height: 12px;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.95) 0%, rgba(234, 88, 12, 0.95) 100%);
            border-radius: 3px;
            bottom: 5px;
            right: 0;
            transform: rotate(-45deg);
            box-shadow: 
                0 3px 10px rgba(249, 115, 22, 0.4),
                inset 0 1px 2px rgba(255, 255, 255, 0.5);
        }
        
        /* Login Input Groups */
        .login-input-group {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .login-input-group:nth-child(1) {
            animation-delay: 0.1s;
        }
        
        .login-input-group:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .login-input-group input:focus {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.15);
        }
        
        /* Login Submit Button */
        .login-submit-btn {
            position: relative;
            overflow: hidden;
        }
        
        .login-submit-btn::before {
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
        
        .login-submit-btn:hover::before {
            width: 400px;
            height: 400px;
        }
        
        /* Register Modal Header Enhancements */
        .register-modal-header {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #7c3aed 100%);
            overflow: hidden;
            position: relative;
        }
        
        .register-modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 4s ease-in-out infinite;
        }
        
        .register-modal-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 5s ease-in-out infinite;
        }
        
        /* Smooth fade in for sections */
        section {
            opacity: 0;
            transition: opacity 0.8s ease;
        }
        
        section.visible {
            opacity: 1;
        }
    </style>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 relative overflow-x-hidden">
     <!-- Navigation -->
     <nav class="fixed top-0 w-full z-50 bg-white/95 backdrop-blur-md border-b border-gray-200/50 shadow-sm">
        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="relative flex items-center justify-between w-full h-16">
                <!-- Logo Left -->
                <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                    <?php $brand = get_setting('brand_name', 'MediTrack'); ?>
                    <div class="w-10 h-10 sm:w-11 sm:h-11 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-lg flex items-center justify-center shadow-md flex-shrink-0">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                        </svg>
                    </div>
                    <div class="flex flex-col leading-tight">
                        <span class="text-base sm:text-lg md:text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600">
                            <?php echo htmlspecialchars($brand); ?>.
                        </span>
                        <span class="text-[9px] sm:text-[10px] text-gray-500 font-semibold tracking-widest uppercase hidden sm:block">Barangay Loon</span>
                    </div>
                </div>

                <!-- Desktop Nav Links Center -->
                <div class="hidden lg:flex items-center gap-8 xl:gap-10 absolute left-1/2 transform -translate-x-1/2">
                    <a href="#home" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-all duration-200 relative group">
                        Home
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-blue-600 transition-all duration-200 group-hover:w-full"></span>
                    </a>
                    <a href="#features" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-all duration-200 relative group">
                        Features
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-blue-600 transition-all duration-200 group-hover:w-full"></span>
                    </a>
                    <a href="#about" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-all duration-200 relative group">
                        About
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-blue-600 transition-all duration-200 group-hover:w-full"></span>
                    </a>
                    <a href="#contact" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-all duration-200 relative group">
                        Contact
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-blue-600 transition-all duration-200 group-hover:w-full"></span>
                    </a>
                </div>
                
                <!-- Desktop CTA + Mobile Hamburger -->
                <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                    <!-- Desktop Buttons -->
                    <button onclick="openRegisterModal()" class="hidden lg:inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 border border-gray-200 transition-all duration-200 hover:shadow-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        Register
                    </button>
                    <button onclick="openLoginModal()" class="hidden lg:inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white px-6 py-2.5 rounded-xl font-semibold transition-all duration-200 shadow-md hover:shadow-lg">
                        Sign in
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </button>
                    
                    <!-- Mobile Hamburger Button -->
                    <button id="mobileMenuBtn" class="lg:hidden relative w-8 h-8 flex flex-col justify-center items-center text-gray-700 hover:text-blue-600 transition-colors" aria-label="Toggle menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu Overlay -->
        <div id="mobileMenuOverlay" onclick="closeMobileMenu()"></div>
        
        <!-- Mobile Menu Sidebar -->
        <div id="mobileMenu">
            <div class="p-6">
                <!-- Mobile Menu Header -->
                <div class="flex items-center justify-between mb-8 pb-6 border-b border-gray-200">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-bold text-gray-900"><?php echo htmlspecialchars($brand); ?>.</div>
                            <div class="text-xs text-gray-500">Barangay Loon</div>
                        </div>
                    </div>
                    <button onclick="closeMobileMenu()" class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <!-- Mobile Menu Links -->
                <nav class="space-y-2 mb-6">
                    <a href="#home" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-xl transition-all duration-200 font-medium group">
                        <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        Home
                    </a>
                    <a href="#features" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-xl transition-all duration-200 font-medium group">
                        <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Features
                    </a>
                    <a href="#about" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-xl transition-all duration-200 font-medium group">
                        <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        About
                    </a>
                    <a href="#contact" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-xl transition-all duration-200 font-medium group">
                        <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        Contact
                    </a>
                </nav>
                
                <!-- Mobile CTA Buttons -->
                <div class="pt-6 border-t border-gray-200 space-y-3">
                    <button onclick="openRegisterModal(); closeMobileMenu();" class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 border border-gray-200 transition-all duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        Register
                    </button>
                    <button onclick="openLoginModal(); closeMobileMenu();" class="w-full flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-4 py-3 rounded-xl font-semibold shadow-md hover:shadow-lg transition-all duration-200">
                        Sign in
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="relative overflow-hidden z-10 pt-24 sm:pt-32 md:pt-40 pb-12 sm:pb-16 md:pb-20">
        <!-- Unique layered background -->
        <div class="aurora"></div>
        <div class="dot-grid absolute inset-0"></div>
        <div class="orb orb--blue"></div>
        <div class="orb orb--violet"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 lg:gap-14 items-center">
                <!-- Left content -->
                <div class="space-y-4 sm:space-y-5 md:space-y-6 animate-slide-in-left text-center lg:text-left">
                    <div class="badge-pill w-max mx-auto lg:mx-0 text-xs sm:text-sm">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" viewBox="0 0 24 24" fill="none">
                            <path d="M5 13l4 4L19 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Barangay-first healthcare
                    </div>

                    <h1 class="text-3xl sm:text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-extrabold leading-tight text-gray-900">
                        Protecting your Health,
                        <span class="block bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600">
                            Our Priority
                        </span>
                    </h1>

                    <p class="text-xs sm:text-sm md:text-base lg:text-lg text-gray-600 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                    Track and distribute medicines efficiently at the barangay level—connecting residents, BHWs, and admins in one smart system for faster service, accurate inventory, and healthier communities.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 pt-2 justify-center lg:justify-start items-center">
                        <button onclick="openLoginModal()" class="btn btn-lg cta-primary rounded-full px-4 sm:px-5 md:px-6 py-2 sm:py-2.5 md:py-3 text-sm sm:text-base shadow-glow hover:scale-105 transition-transform inline-flex items-center justify-center gap-2 group whitespace-nowrap min-w-[140px] sm:min-w-[155px]">
                            <span>Get started</span>
                            <svg class="w-4 h-4 sm:w-4 sm:h-4 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </button>
                        <a href="#features" class="btn btn-lg cta-secondary rounded-full px-4 sm:px-5 md:px-6 py-2 sm:py-2.5 md:py-3 text-sm sm:text-base hover:scale-105 transition-transform inline-flex items-center justify-center whitespace-nowrap min-w-[140px] sm:min-w-[155px]">
                            Learn more
                        </a>
                    </div>                    
                </div>

                <!-- Right visual -->
                <div class="relative animate-slide-in-right mt-8 lg:mt-0">
                    <div class="glass-card-xl p-3 sm:p-5 tilt dancing-card">
                        <div class="relative rounded-2xl overflow-hidden">
                            <div class="absolute -inset-4 sm:-inset-8 bg-gradient-to-tr from-blue-400/20 via-indigo-400/20 to-fuchsia-400/20 blur-2xl"></div>
                            <svg class="relative rounded-xl shadow-2xl w-full h-auto max-w-full" viewBox="0 0 600 380" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
                                <defs>
                                    <linearGradient id="bg-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" style="stop-color:#eff6ff;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#ffffff;stop-opacity:1" />
                                    </linearGradient>
                                    <linearGradient id="primary-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" style="stop-color:#3b82f6;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#6366f1;stop-opacity:1" />
                                    </linearGradient>
                                    <linearGradient id="pill-gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#3b82f6;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#6366f1;stop-opacity:1" />
                                    </linearGradient>
                                    <filter id="shadow-main">
                                        <feGaussianBlur in="SourceAlpha" stdDeviation="4"/>
                                        <feOffset dx="0" dy="3" result="offsetblur"/>
                                        <feComponentTransfer>
                                            <feFuncA type="linear" slope="0.3"/>
                                        </feComponentTransfer>
                                        <feMerge>
                                            <feMergeNode/>
                                            <feMergeNode in="SourceGraphic"/>
                                        </feMerge>
                                    </filter>
                                    <filter id="shadow-soft">
                                        <feGaussianBlur in="SourceAlpha" stdDeviation="2"/>
                                        <feOffset dx="0" dy="1" result="offsetblur"/>
                                        <feComponentTransfer>
                                            <feFuncA type="linear" slope="0.2"/>
                                        </feComponentTransfer>
                                        <feMerge>
                                            <feMergeNode/>
                                            <feMergeNode in="SourceGraphic"/>
                                        </feMerge>
                                    </filter>
                                </defs>
                                
                                <!-- Background -->
                                <rect width="600" height="400" fill="url(#bg-gradient)" rx="12"/>
                                
                                <!-- Health Center Building -->
                                <g transform="translate(120, 80)">
                                    <!-- Building base -->
                                    <rect x="-50" y="0" width="100" height="80" rx="4" fill="url(#primary-gradient)" opacity="0.9" filter="url(#shadow-main)"/>
                                    <!-- Building roof -->
                                    <path d="M -55 0 L 0 -20 L 55 0 Z" fill="#2563eb" opacity="0.9"/>
                                    <!-- Windows -->
                                    <rect x="-35" y="20" width="15" height="15" rx="2" fill="#ffffff" opacity="0.9"/>
                                    <rect x="-10" y="20" width="15" height="15" rx="2" fill="#ffffff" opacity="0.9"/>
                                    <rect x="15" y="20" width="15" height="15" rx="2" fill="#ffffff" opacity="0.9"/>
                                    <rect x="-35" y="45" width="15" height="15" rx="2" fill="#60a5fa" opacity="0.7"/>
                                    <rect x="-10" y="45" width="15" height="15" rx="2" fill="#ffffff" opacity="0.9"/>
                                    <rect x="15" y="45" width="15" height="15" rx="2" fill="#60a5fa" opacity="0.7"/>
                                    <!-- Door -->
                                    <rect x="-12" y="60" width="24" height="20" rx="2" fill="#1e40af" opacity="0.8"/>
                                    <!-- Medical cross on door -->
                                    <rect x="-8" y="70" width="4" height="8" rx="2" fill="#ffffff" opacity="0.9"/>
                                    <rect x="-10" y="72" width="8" height="4" rx="2" fill="#ffffff" opacity="0.9"/>
                                </g>
                                
                                <!-- Inventory Clipboard/Tablet -->
                                <g transform="translate(320, 60)">
                                    <!-- Tablet base -->
                                    <rect x="-60" y="-50" width="120" height="140" rx="8" fill="#ffffff" stroke="#e2e8f0" stroke-width="2" filter="url(#shadow-main)"/>
                                    <!-- Screen header -->
                                    <rect x="-60" y="-50" width="120" height="25" rx="8" fill="url(#primary-gradient)" opacity="0.9"/>
                                    <!-- Signal bars (real-time indicator) -->
                                    <g transform="translate(40, -40)">
                                        <rect x="0" y="8" width="3" height="8" rx="1" fill="#ffffff" opacity="0.8"/>
                                        <rect x="5" y="5" width="3" height="11" rx="1" fill="#ffffff" opacity="0.8"/>
                                        <rect x="10" y="2" width="3" height="14" rx="1" fill="#ffffff" opacity="0.8"/>
                                    </g>
                                    <!-- Inventory list lines -->
                                    <line x1="-50" y1="10" x2="50" y2="10" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round"/>
                                    <line x1="-50" y1="30" x2="50" y2="30" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round"/>
                                    <line x1="-50" y1="50" x2="50" y2="50" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round"/>
                                    <line x1="-50" y1="70" x2="50" y2="70" stroke="#e2e8f0" stroke-width="1.5" stroke-linecap="round"/>
                                    <!-- Pills on list -->
                                    <ellipse cx="-35" cy="10" rx="6" ry="3" fill="url(#pill-gradient)" opacity="0.8"/>
                                    <ellipse cx="-35" cy="30" rx="6" ry="3" fill="#10b981" opacity="0.8"/>
                                    <ellipse cx="-35" cy="50" rx="6" ry="3" fill="#8b5cf6" opacity="0.8"/>
                                    <ellipse cx="-35" cy="70" rx="6" ry="3" fill="url(#pill-gradient)" opacity="0.8"/>
                                </g>
                                
                                <!-- Medicine Storage Box -->
                                <g transform="translate(480, 100)">
                                    <!-- Box -->
                                    <rect x="-40" y="-30" width="80" height="60" rx="4" fill="#ffffff" stroke="#e2e8f0" stroke-width="2" filter="url(#shadow-main)"/>
                                    <!-- Box lid -->
                                    <rect x="-42" y="-32" width="84" height="8" rx="4" fill="#f1f5f9" stroke="#e2e8f0" stroke-width="2"/>
                                    <!-- Pills inside -->
                                    <ellipse cx="-20" cy="0" rx="8" ry="4" fill="url(#pill-gradient)" opacity="0.7"/>
                                    <ellipse cx="0" cy="5" rx="7" ry="3.5" fill="#10b981" opacity="0.7"/>
                                    <ellipse cx="20" cy="-5" rx="8" ry="4" fill="#8b5cf6" opacity="0.7"/>
                                    <!-- Label -->
                                    <rect x="-30" y="-20" width="60" height="12" rx="2" fill="#3b82f6" opacity="0.1"/>
                                </g>
                                
                                <!-- Real-time Tracking Waves -->
                                <g transform="translate(300, 220)">
                                    <!-- Wave 1 -->
                                    <path d="M -100 0 Q -80 -15 -60 0 T -20 0 T 20 0 T 60 0 T 100 0" 
                                          fill="none" 
                                          stroke="#3b82f6" 
                                          stroke-width="3" 
                                          opacity="0.4" 
                                          stroke-linecap="round"/>
                                    <!-- Wave 2 -->
                                    <path d="M -100 10 Q -80 -5 -60 10 T -20 10 T 20 10 T 60 10 T 100 10" 
                                          fill="none" 
                                          stroke="#6366f1" 
                                          stroke-width="3" 
                                          opacity="0.3" 
                                          stroke-linecap="round"/>
                                    <!-- Wave 3 -->
                                    <path d="M -100 20 Q -80 5 -60 20 T -20 20 T 20 20 T 60 20 T 100 20" 
                                          fill="none" 
                                          stroke="#8b5cf6" 
                                          stroke-width="3" 
                                          opacity="0.2" 
                                          stroke-linecap="round"/>
                                </g>
                                
                                <!-- Medicine Pills Display -->
                                <g transform="translate(150, 300)">
                                    <ellipse cx="0" cy="0" rx="22" ry="11" fill="url(#pill-gradient)" opacity="0.9" filter="url(#shadow-soft)"/>
                                    <line x1="-14" y1="0" x2="14" y2="0" stroke="#ffffff" stroke-width="2" opacity="0.9"/>
                                    <circle cx="-9" cy="0" r="2.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="9" cy="0" r="2.5" fill="#ffffff" opacity="0.8"/>
                                </g>
                                
                                <g transform="translate(300, 305)">
                                    <ellipse cx="0" cy="0" rx="20" ry="10" fill="#10b981" opacity="0.9" filter="url(#shadow-soft)"/>
                                    <line x1="-13" y1="0" x2="13" y2="0" stroke="#ffffff" stroke-width="2" opacity="0.9"/>
                                </g>
                                
                                <g transform="translate(450, 300)">
                                    <ellipse cx="0" cy="0" rx="22" ry="11" fill="#8b5cf6" opacity="0.9" filter="url(#shadow-soft)"/>
                                    <line x1="-14" y1="0" x2="14" y2="0" stroke="#ffffff" stroke-width="2" opacity="0.9"/>
                                    <circle cx="-9" cy="0" r="2.5" fill="#ffffff" opacity="0.8"/>
                                    <circle cx="9" cy="0" r="2.5" fill="#ffffff" opacity="0.8"/>
                                </g>
                                
                                <!-- Connecting Lines (Network/System) -->
                                <g transform="translate(300, 190)" opacity="0.3">
                                    <line x1="-80" y1="0" x2="-20" y2="0" stroke="#3b82f6" stroke-width="2" stroke-dasharray="3,3"/>
                                    <line x1="20" y1="0" x2="80" y2="0" stroke="#3b82f6" stroke-width="2" stroke-dasharray="3,3"/>
                                    <circle cx="0" cy="0" r="3" fill="#3b82f6"/>
                                </g>
                            </svg>
                                </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mt-3">
                            <div class="card p-3 sm:p-4">
                                <div class="text-xs text-gray-500">Easy to Use</div>
                                <div class="mt-1 text-sm sm:text-base font-semibold text-gray-900">Simple Interface</div>
                                </div>
                            <div class="card p-3 sm:p-4">
                                <div class="text-xs text-gray-500">Fast & Reliable</div>
                                <div class="mt-1 text-sm sm:text-base font-semibold text-gray-900">Real-time Updates</div>
                            </div>
                            <div class="card p-3 sm:p-4">
                                <div class="text-xs text-gray-500">Secure</div>
                                <div class="mt-1 text-sm sm:text-base font-semibold text-gray-900">Protected Data</div>
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
        <section id="features" class="py-12 sm:py-16 lg:py-24 bg-white/60">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-10 sm:mb-12 lg:mb-16">
                    <div class="flex items-center justify-center gap-3 mb-4 sm:mb-5">
                        <div class="h-px w-12 sm:w-16 bg-gradient-to-r from-transparent via-blue-400 to-blue-600"></div>
                        <div class="w-2 h-2 bg-blue-600 rounded-full"></div>
                        <div class="w-8 h-px bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600"></div>
                        <div class="w-2 h-2 bg-purple-600 rounded-full"></div>
                        <div class="h-px w-12 sm:w-16 bg-gradient-to-l from-transparent via-purple-400 to-purple-600"></div>
                    </div>
                    <h2 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 mb-3 sm:mb-4 scroll-reveal">
                        <span class="hero-title">Core Features</span>
                    </h2>
                    <p class="text-sm sm:text-base lg:text-lg text-gray-600 max-w-2xl mx-auto leading-relaxed">
                        Everything you need to manage medicine inventory and requests efficiently
                    </p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
                    <div class="group relative bg-white border-2 border-gray-200 rounded-xl p-4 sm:p-5 hover:border-blue-500 transition-all duration-300 hover:shadow-lg overflow-hidden">
                        <div class="absolute top-0 right-0 w-16 h-16 bg-blue-100 rounded-bl-full opacity-0 group-hover:opacity-100 transition-opacity duration-300 -mr-px -mt-px"></div>
                        <div class="relative z-10">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-600 group-hover:scale-110 transition-all duration-300">
                                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600 group-hover:text-white transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-base sm:text-lg font-bold text-gray-900 mb-1.5 group-hover:text-blue-600 transition-colors duration-300">Browse & Request</div>
                                <p class="text-xs sm:text-sm text-gray-600 leading-relaxed">Residents can discover medicines and submit requests with proof and patient info.</p>
                            </div>
                        </div>
                    </div>
                    <div class="group relative bg-white border-2 border-gray-200 rounded-xl p-4 sm:p-5 hover:border-green-500 transition-all duration-300 hover:shadow-lg overflow-hidden">
                        <div class="absolute top-0 right-0 w-16 h-16 bg-green-100 rounded-bl-full opacity-0 group-hover:opacity-100 transition-opacity duration-300 -mr-px -mt-px"></div>
                        <div class="relative z-10">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-green-100 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-600 group-hover:scale-110 transition-all duration-300">
                                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600 group-hover:text-white transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-base sm:text-lg font-bold text-gray-900 mb-1.5 group-hover:text-green-600 transition-colors duration-300">BHW Approval</div>
                                <p class="text-xs sm:text-sm text-gray-600 leading-relaxed">BHWs verify and approve requests, managing residents and families by purok.</p>
                            </div>
                        </div>
                    </div>
                    <div class="group relative bg-white border-2 border-gray-200 rounded-xl p-4 sm:p-5 hover:border-purple-500 transition-all duration-300 hover:shadow-lg overflow-hidden">
                        <div class="absolute top-0 right-0 w-16 h-16 bg-purple-100 rounded-bl-full opacity-0 group-hover:opacity-100 transition-opacity duration-300 -mr-px -mt-px"></div>
                        <div class="relative z-10">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-purple-100 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-600 group-hover:scale-110 transition-all duration-300">
                                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600 group-hover:text-white transition-colors duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-base sm:text-lg font-bold text-gray-900 mb-1.5 group-hover:text-purple-600 transition-colors duration-300">Admin Inventory</div>
                                <p class="text-xs sm:text-sm text-gray-600 leading-relaxed">Super Admins manage medicines, batches, users, and senior allocations with FEFO.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="py-12 sm:py-16 lg:py-24 bg-gradient-to-br from-gray-50 via-blue-50/30 to-indigo-50/20 relative overflow-hidden">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(59,130,246,0.05),transparent_50%)]"></div>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <div class="text-center mb-10 sm:mb-12 lg:mb-16">
                    <h2 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 mb-4 sm:mb-6">
                        About <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600">MediTrack</span>
                    </h2>
                    <div class="w-24 h-1 bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600 mx-auto rounded-full"></div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 lg:gap-12 mb-8 sm:mb-12">
                    <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-6 sm:p-8 lg:p-10 shadow-lg border border-white/50">
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">What is MediTrack?</h3>
                        <p class="text-sm sm:text-base lg:text-lg text-gray-700 text-justify leading-relaxed">
                            MediTrack is a comprehensive web-based solution designed specifically for barangay health centers. It streamlines medicine inventory management and request processing, connecting residents, Barangay Health Workers (BHWs), and administrators in one unified platform.
                        </p>
                    </div>
                    
                    <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-6 sm:p-8 lg:p-10 shadow-lg border border-white/50">
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">Our Purpose</h3>
                        <p class="text-sm sm:text-base lg:text-lg text-gray-700 text-justify leading-relaxed">
                            We aim to improve healthcare accessibility at the barangay level by providing a reliable, efficient system for tracking medicine availability, processing requests, and ensuring timely distribution to residents who need them most.
                        </p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 lg:gap-12">
                    <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-6 sm:p-8 lg:p-10 shadow-lg border border-white/50">
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">How It Works</h3>
                        <p class="text-sm sm:text-base lg:text-lg text-gray-700 text-justify leading-relaxed">
                            Residents can browse available medicines and submit requests with necessary documentation. BHWs review and approve requests, while administrators manage inventory using FEFO (First Expired, First Out) batch handling to ensure medicine quality and safety.
                        </p>
                    </div>
                    
                    <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-6 sm:p-8 lg:p-10 shadow-lg border border-white/50">
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">Why MediTrack Matters</h3>
                        <p class="text-sm sm:text-base lg:text-lg text-gray-700 text-justify leading-relaxed">
                            Built with PHP, MySQL, and TailwindCSS, MediTrack offers role-based dashboards for Super Admins, BHWs, and Residents. It features a senior citizen maintenance allocation program and ensures accurate inventory tracking for healthier communities.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact CTA -->
        <section id="contact" class="py-12 sm:py-16 lg:py-20 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8 lg:p-10 border border-gray-100">
                    <div class="flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left">
                        <div class="flex-1">
                            <h3 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900 mb-2 sm:mb-3">Need help getting started?</h3>
                            <p class="text-sm sm:text-base lg:text-lg text-gray-600 max-w-2xl mx-auto md:mx-0">Visit your barangay health center or sign in below to access the platform.</p>
                        </div>
                        <button onclick="openLoginModal()" class="btn btn-primary btn-lg whitespace-nowrap px-6 sm:px-8 py-3 sm:py-4 text-sm sm:text-base font-semibold shadow-lg hover:shadow-xl transition-all duration-300">
                            Sign in
                        </button>
                    </div>
                </div>
            </div>
        </section>


        <!-- Testimonials -->
        <section id="testimonials" class="py-12 sm:py-16 lg:py-24 bg-white/60 relative overflow-hidden">
            <div class="absolute top-10 right-10 w-64 h-64 bg-blue-200 rounded-full blur-3xl opacity-20 animate-pulse-slow hidden sm:block"></div>
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <div class="text-center mb-10 sm:mb-12 lg:mb-16 animate-fade-in-up">
                    <h2 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 mb-3 sm:mb-4">What users say</h2>
                    <p class="text-sm sm:text-base lg:text-lg text-gray-600">Real feedback from real users</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6 lg:gap-8">
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
        <section id="faq" class="py-12 sm:py-16 lg:py-24 bg-gray-50/50">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-10 sm:mb-12 lg:mb-16">
                    <h2 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 mb-3 sm:mb-4">Frequently Asked Questions</h2>
                    <p class="text-sm sm:text-base lg:text-lg text-gray-600">Find answers to common questions about MediTrack</p>
                </div>
                <div class="space-y-4 sm:space-y-5">
                    <details class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden animate-fade-in hover:shadow-lg transition-shadow duration-300">
                        <summary class="px-5 sm:px-6 py-4 sm:py-5 cursor-pointer font-semibold text-base sm:text-lg text-gray-900 hover:text-blue-600 transition-colors duration-200 list-none">
                            <span class="flex items-center justify-between">
                                <span>How do residents request medicines?</span>
                                <svg class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </span>
                        </summary>
                        <div class="px-5 sm:px-6 pb-5 sm:pb-6 text-sm sm:text-base text-gray-700 leading-relaxed border-t border-gray-100 pt-4">
                            Residents sign in, browse medicines, and submit a request with a proof image and patient details.
                        </div>
                    </details>
                    <details class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden animate-fade-in hover:shadow-lg transition-shadow duration-300">
                        <summary class="px-5 sm:px-6 py-4 sm:py-5 cursor-pointer font-semibold text-base sm:text-lg text-gray-900 hover:text-blue-600 transition-colors duration-200 list-none">
                            <span class="flex items-center justify-between">
                                <span>How are approvals handled?</span>
                                <svg class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </span>
                        </summary>
                        <div class="px-5 sm:px-6 pb-5 sm:pb-6 text-sm sm:text-base text-gray-700 leading-relaxed border-t border-gray-100 pt-4">
                            BHWs review requests and approve/reject. Approved requests automatically deduct stock FEFO from batches.
                        </div>
                    </details>
                    <details class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden animate-fade-in hover:shadow-lg transition-shadow duration-300">
                        <summary class="px-5 sm:px-6 py-4 sm:py-5 cursor-pointer font-semibold text-base sm:text-lg text-gray-900 hover:text-blue-600 transition-colors duration-200 list-none">
                            <span class="flex items-center justify-between">
                                <span>Do emails send automatically?</span>
                                <svg class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </span>
                        </summary>
                        <div class="px-5 sm:px-6 pb-5 sm:pb-6 text-sm sm:text-base text-gray-700 leading-relaxed border-t border-gray-100 pt-4">
                            Yes. The system emails request and user events via PHPMailer. Email attempts are logged for review.
                        </div>
                    </details>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-gray-200 py-8 sm:py-10 bg-white/90 backdrop-blur-sm relative z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <div class="text-xl sm:text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600 mb-3 sm:mb-4">
                    <?php echo htmlspecialchars($brand); ?>
                </div>
                <p class="text-sm sm:text-base text-gray-600 mb-2">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($brand); ?>. All rights reserved.</p>
                <p class="text-xs sm:text-sm text-gray-500">Making healthcare accessible for everyone.</p>
            </div>
        </div>
    </footer>

    <!-- Scroll to Top Button -->
    <button id="scrollToTop" class="fixed bottom-6 right-6 sm:bottom-8 sm:right-8 bg-gradient-to-br from-blue-600 to-indigo-600 text-white p-3 sm:p-4 rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-110 hidden z-50">
        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

    <!-- Sticky mobile login CTA -->
    <button onclick="openLoginModal()" class="md:hidden fixed bottom-20 sm:bottom-24 right-4 sm:right-6 btn btn-primary shadow-lg z-40 px-4 py-2.5 text-sm font-semibold rounded-lg">Login</button>

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
            <!-- Enhanced Header with Logo -->
            <div class="register-modal-header relative bg-gradient-to-br from-blue-600 via-blue-700 to-purple-700 border-b border-gray-100 p-8">
                <button onclick="closeRegisterModal()" class="absolute top-4 right-4 text-white hover:text-gray-200 transition-colors z-20 bg-white/10 hover:bg-white/20 rounded-full p-2 backdrop-blur-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                
                <div class="relative text-center z-10">
                    <!-- 4D Logo -->
                    <div class="login-logo-container">
                        <div class="login-logo-mini">
                            <div class="login-logo-cross-mini"></div>
                            <div class="login-logo-pill-mini">
                                <div class="login-logo-pill-cap-mini"></div>
                    </div>
                            <div class="login-logo-checkmark-mini"></div>
                        </div>
                    </div>
                    
                    <h2 class="text-3xl font-bold text-white mb-2 drop-shadow-lg">Create Your Account</h2>
                    <p class="text-blue-100 text-sm">Join MediTrack and start managing your medicine requests</p>
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
                                       maxlength="1" 
                                       id="middle_initial_input" />
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
                                    <input type="email" name="email" id="email-input" required 
                                           class="w-full pl-10 pr-12 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                           placeholder="you@example.com" />
                                    <!-- Email validation status icon -->
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                        <div id="email-status-icon" class="hidden">
                                            <!-- Success icon -->
                                            <svg id="email-success-icon" class="w-5 h-5 text-green-500 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            <!-- Error icon -->
                                            <svg id="email-error-icon" class="w-5 h-5 text-red-500 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            <!-- Loading spinner -->
                                            <svg id="email-loading-icon" class="w-5 h-5 text-gray-400 animate-spin hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                <!-- Email validation message -->
                                <div id="email-validation-message" class="hidden text-sm">
                                    <p id="email-message-text"></p>
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
                                           id="date_of_birth_input"
                                           max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
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
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Barangay -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Barangay</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                        </svg>
                                    </div>
                                    <select name="barangay_id" id="barangay-select" required 
                                            class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-4 focus:ring-green-100 transition-all duration-200 outline-none appearance-none bg-white">
                                        <option value="">Select your Barangay</option>
                                    <?php
                                    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
                                    foreach ($barangays as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
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
                                    <select name="purok_id" id="purok-select" required 
                                            class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-4 focus:ring-green-100 transition-all duration-200 outline-none appearance-none bg-white">
                                        <option value="">Select your Purok</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
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
                                    <!-- First Name -->
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700">First Name</label>
                                        <input type="text" name="family_members[0][first_name]" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                               placeholder="Juan" />
                                    </div>
                                    
                                    <!-- Middle Initial -->
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700">Middle Initial</label>
                                        <input type="text" name="family_members[0][middle_initial]" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                               placeholder="D." 
                                               maxlength="1" />
                                    </div>
                                    
                                    <!-- Last Name -->
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700">Last Name</label>
                                        <input type="text" name="family_members[0][last_name]" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" 
                                               placeholder="Dela Cruz" />
                                    </div>
                                    
                                    <!-- Relationship -->
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700">Relationship</label>
                                        <select name="family_members[0][relationship]" 
                                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white">
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
                                    
                                    <!-- Date of Birth -->
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700">Date of Birth</label>
                                        <input type="date" name="family_members[0][date_of_birth]" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" />
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

    <!-- Email Verification Modal -->
    <div id="verificationModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl transform transition-all duration-300">
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Verify Your Email</h3>
                    <p class="text-gray-600">We sent a 6-digit code to your email</p>
                </div>

                <div id="verificationError" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm"></div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Verification Code</label>
                        <input type="text" id="verificationCode" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-all duration-200 text-center text-2xl tracking-widest" placeholder="000000" />
                    </div>

                    <div class="flex space-x-3">
                        <button type="button" onclick="closeVerificationModal()" class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="button" onclick="requestVerificationCode()" id="resendBtn" class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-all duration-200">
                            Resend Code
                        </button>
                        <button type="button" onclick="verifyCode()" class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-all duration-200">
                            Verify
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Login Modal -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl transform transition-all duration-300 overflow-hidden">
            <!-- Header with Gradient and Logo -->
            <div class="login-modal-header rounded-t-3xl p-8 relative">
                <button onclick="closeLoginModal()" class="absolute top-4 right-4 text-white hover:text-gray-200 transition-colors z-20 bg-white/10 hover:bg-white/20 rounded-full p-2 backdrop-blur-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                
                <div class="relative text-center z-10">
                    <!-- 4D Logo -->
                    <div class="login-logo-container">
                        <div class="login-logo-mini">
                            <div class="login-logo-cross-mini"></div>
                            <div class="login-logo-pill-mini">
                                <div class="login-logo-pill-cap-mini"></div>
                    </div>
                            <div class="login-logo-checkmark-mini"></div>
                        </div>
                    </div>
                    
                    <h2 class="text-3xl font-bold text-white mb-2 drop-shadow-lg">Welcome Back</h2>
                    <p class="text-blue-100 text-sm">Sign in to access your account</p>
                </div>
                </div>
                
            <!-- Form Body -->
            <div class="p-8 bg-gradient-to-b from-white to-gray-50">
                <form id="loginForm" action="public/login.php" method="post" class="space-y-6">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="flex items-start space-x-3 text-sm text-red-700 bg-red-50 border-2 border-red-200 rounded-xl px-4 py-3 animate-shake shadow-sm">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="font-medium"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="space-y-2 login-input-group">
                        <label class="block text-sm font-semibold text-gray-700">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                </svg>
                    </div>
                            <input type="email" name="email" required 
                                   class="w-full pl-12 pr-4 py-3.5 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 outline-none bg-white font-medium" 
                                   placeholder="you@example.com" />
                    </div>
                    </div>
                    
                    <div class="space-y-2 login-input-group">
                        <label class="block text-sm font-semibold text-gray-700">Password</label>
                           <div class="relative">
                               <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                   <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                   </svg>
                               </div>
                               <input type="password" name="password" id="login-password" required 
                                   class="w-full pl-12 pr-12 py-3.5 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 outline-none bg-white font-medium" 
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
                            class="login-submit-btn w-full bg-gradient-to-r from-blue-600 via-blue-700 to-purple-600 text-white py-3.5 rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 flex items-center justify-center space-x-2">
                        <span>Sign in</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </button>
                </form>
                
                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t-2 border-gray-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-gradient-to-b from-white to-gray-50 text-gray-500 font-medium">New to MediTrack?</span>
                    </div>
                </div>
                
                <!-- Register Link -->
                <div class="text-center">
                    <button onclick="closeLoginModal(); openRegisterModal();" 
                            class="text-blue-600 hover:text-blue-700 font-semibold transition-all duration-200 inline-flex items-center space-x-2 hover:scale-105">
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
        let emailValidationTimeout = null;
        let isEmailValid = false;
        
        // Barangay-Purok relationship data
        let puroksData = <?php echo json_encode(db()->query('SELECT id, name, barangay_id FROM puroks ORDER BY barangay_id, name')->fetchAll()); ?>;
        let barangaysData = <?php echo json_encode(db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll()); ?>;
        
        // Initialize barangay-purok relationship
        function initializeBarangayPurok() {
            const barangaySelect = document.getElementById('barangay-select');
            const purokSelect = document.getElementById('purok-select');
            
            if (barangaySelect && purokSelect) {
                barangaySelect.addEventListener('change', function() {
                    updatePurokOptions(this.value);
                });
            }
        }
        
        // Update purok options based on selected barangay
        function updatePurokOptions(barangayId) {
            const purokSelect = document.getElementById('purok-select');
            if (!purokSelect) return;
            
            // Clear existing options
            purokSelect.innerHTML = '<option value="">Select your Purok</option>';
            
            if (!barangayId) return;
            
            // Filter puroks by selected barangay
            const filteredPuroks = puroksData.filter(purok => purok.barangay_id == barangayId);
            
            // Add filtered puroks to select
            filteredPuroks.forEach(purok => {
                const option = document.createElement('option');
                option.value = purok.id;
                option.textContent = purok.name;
                purokSelect.appendChild(option);
            });
        }
        
        // Generate address from purok and barangay
        function generateAddress() {
            const purokSelect = document.getElementById('purok-select');
            const barangaySelect = document.getElementById('barangay-select');
            
            if (!purokSelect || !barangaySelect) return '';
            
            const selectedPurokId = purokSelect.value;
            const selectedBarangayId = barangaySelect.value;
            
            if (!selectedPurokId || !selectedBarangayId) return '';
            
            // Find purok and barangay names
            const purok = puroksData.find(p => p.id == selectedPurokId);
            const barangay = barangaysData.find(b => b.id == selectedBarangayId);
            
            if (purok && barangay) {
                return `${purok.name}, ${barangay.name}`;
            }
            
            return '';
        }
        
        // Enhanced email validation functions
        function validateEmail(email) {
            // First check if email is empty
            if (!email || email.trim() === '') {
                return false;
            }
            
            // Check for @ symbol - must have exactly one
            const atCount = (email.match(/@/g) || []).length;
            if (atCount !== 1) {
                return false;
            }
            
            // Check if email starts with @ (invalid)
            if (email.startsWith('@')) {
                return false;
            }
            
            // Check if email ends with @ (invalid)
            if (email.endsWith('@')) {
                return false;
            }
            
            // Basic regex check - must have @ and proper format
            const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            if (!emailRegex.test(email)) {
                return false;
            }
            
            // Split into parts
            const parts = email.split('@');
            if (parts.length !== 2) return false;
            
            const [localPart, domainPart] = parts;
            
            // Check local part (before @)
            if (!localPart || localPart.length === 0 || localPart.length > 64) return false;
            
            // Check for invalid characters in local part
            if (!/^[a-zA-Z0-9._%+-]+$/.test(localPart)) return false;
            
            // Check domain part (after @)
            if (!domainPart || domainPart.length === 0 || domainPart.length > 255) return false;
            
            // Check if domain has at least one dot
            if (!domainPart.includes('.')) return false;
            
            // Check domain extension (must be at least 2 characters, letters only)
            const domainParts = domainPart.split('.');
            const extension = domainParts[domainParts.length - 1];
            if (extension.length < 2 || !/^[a-zA-Z]+$/.test(extension)) return false;
            
            // Check for invalid patterns
            const invalidPatterns = [
                /^[^@]+@$/,  // ends with @
                /^@[^@]+$/,  // starts with @
                /\.{2,}/,    // consecutive dots
                /^\.|\.$/,   // starts or ends with dot
                /@.*@/,      // multiple @ symbols
                /[()]/,      // parentheses (like in the example)
                /[<>]/,      // angle brackets
                /[{}]/,      // curly braces
                /[\[\]]/,    // square brackets
                /[|\\]/,     // pipe or backslash
                /[;:]/,      // semicolon or colon
                /[,\s]/,     // comma or spaces
            ];
            
            for (const pattern of invalidPatterns) {
                if (pattern.test(email)) return false;
            }
            
            // Check that local part doesn't start or end with dot
            if (localPart.startsWith('.') || localPart.endsWith('.')) return false;
            
            // Check that domain doesn't start or end with dot
            if (domainPart.startsWith('.') || domainPart.endsWith('.')) return false;
            
            return true;
        }
        
        function showEmailStatus(icon, message, isSuccess) {
            const statusIcon = document.getElementById('email-status-icon');
            const successIcon = document.getElementById('email-success-icon');
            const errorIcon = document.getElementById('email-error-icon');
            const loadingIcon = document.getElementById('email-loading-icon');
            const validationMessage = document.getElementById('email-validation-message');
            const messageText = document.getElementById('email-message-text');
            const emailInput = document.getElementById('email-input');
            
            // Hide all icons first
            successIcon.classList.add('hidden');
            errorIcon.classList.add('hidden');
            loadingIcon.classList.add('hidden');
            
            // Show the appropriate icon
            if (icon === 'loading') {
                loadingIcon.classList.remove('hidden');
                statusIcon.classList.remove('hidden');
            } else if (icon === 'success') {
                successIcon.classList.remove('hidden');
                statusIcon.classList.remove('hidden');
                emailInput.classList.remove('border-red-300');
                emailInput.classList.add('border-green-300');
            } else if (icon === 'error') {
                errorIcon.classList.remove('hidden');
                statusIcon.classList.remove('hidden');
                emailInput.classList.remove('border-green-300');
                emailInput.classList.add('border-red-300');
            } else {
                statusIcon.classList.add('hidden');
                emailInput.classList.remove('border-red-300', 'border-green-300');
            }
            
            // Show/hide message
            if (message) {
                messageText.textContent = message;
                messageText.className = isSuccess ? 'text-green-600' : 'text-red-600';
                validationMessage.classList.remove('hidden');
            } else {
                validationMessage.classList.add('hidden');
            }
        }
        
        async function checkEmailAvailability(email) {
            // First check basic format
            if (!validateEmail(email)) {
                // Provide more specific error messages
                if (!email || email.trim() === '') {
                    showEmailStatus('error', 'Please enter an email address', false);
                } else if (email.startsWith('@')) {
                    showEmailStatus('error', 'Email cannot start with @ symbol. Please enter a valid email like user@domain.com', false);
                } else if (email.endsWith('@')) {
                    showEmailStatus('error', 'Email cannot end with @ symbol. Please enter a valid email like user@domain.com', false);
                } else if (!email.includes('@')) {
                    showEmailStatus('error', 'Email must contain an @ symbol (e.g., user@domain.com)', false);
                } else if ((email.match(/@/g) || []).length > 1) {
                    showEmailStatus('error', 'Email can only contain one @ symbol', false);
                } else if (email.includes('(') || email.includes(')')) {
                    showEmailStatus('error', 'Email cannot contain parentheses. Please use a valid format like user@domain.com', false);
                } else if (!email.includes('.')) {
                    showEmailStatus('error', 'Email must include a domain with extension (e.g., user@domain.com)', false);
                } else {
                    showEmailStatus('error', 'Please enter a valid email address format (e.g., user@domain.com)', false);
                }
                isEmailValid = false;
                return false;
            }
            
            showEmailStatus('loading', 'Verifying email address exists and can receive emails...', false);
            
            try {
                // Try the main validation first
                let response = await fetch('public/validate_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });
                
                let result = await response.json();
                
                // If main validation fails, try the simpler validation
                if (!result.success || !result.valid) {
                    console.log('Main validation failed, trying simple validation...');
                    response = await fetch('public/validate_email_simple.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ email: email })
                    });
                    
                    result = await response.json();
                }
                
                if (result.success && result.valid) {
                    showEmailStatus('success', 'Email is valid and can receive verification codes', true);
                    isEmailValid = true;
                    return true;
                } else {
                    showEmailStatus('error', result.message, false);
                    isEmailValid = false;
                    return false;
                }
            } catch (error) {
                console.error('Email validation error:', error);
                showEmailStatus('error', 'Unable to validate email. Please check your connection and try again.', false);
                isEmailValid = false;
                return false;
            }
        }
        
        function openRegisterModal() {
            document.getElementById('registerModal').classList.remove('hidden');
            document.getElementById('registerModal').classList.add('flex');
            document.body.style.overflow = 'hidden';
            // Reset to step 1
            goToStep(1);
            
            // Initialize barangay-purok relationship
            initializeBarangayPurok();
            
            // Add email input event listener for real-time validation
            const emailInput = document.getElementById('email-input');
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    const email = this.value.trim();
                    
                    // Clear previous timeout
                    if (emailValidationTimeout) {
                        clearTimeout(emailValidationTimeout);
                    }
                    
                    // Clear status if email is empty
                    if (!email) {
                        showEmailStatus(null, null, false);
                        isEmailValid = false;
                        return;
                    }
                    
                    // Debounce the validation (wait 500ms after user stops typing)
                    emailValidationTimeout = setTimeout(() => {
                        checkEmailAvailability(email);
                    }, 500);
                });
            }
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
            const email = document.querySelector('input[name="email"]').value.trim();
            
            console.log('Checking:', firstName, lastName, middleInitial, dateOfBirth, email);
            
            // SIMPLE CHECK - If it's Jaycho, BLOCK IMMEDIATELY
            if (firstName.toLowerCase() === 'jaycho' && lastName.toLowerCase() === 'carido') {
                console.log('JAYCHO DETECTED - BLOCKING IMMEDIATELY');
                showToast('REGISTRATION BLOCKED: Jaycho Carido already exists in the system!', 'error');
                return false;
            }
            
            // Check if required fields are filled
            const barangayId = document.querySelector('select[name="barangay_id"]').value;
            const purokId = document.querySelector('select[name="purok_id"]').value;
            
            if (!firstName || !lastName || !dateOfBirth || !email || !barangayId || !purokId) {
                showToast('Please fill in all required fields including Barangay and Purok!', 'warning');
                return false;
            }
            
            // Check if email is valid and available
            if (!isEmailValid) {
                showToast('Please ensure your email is valid and available before proceeding!', 'error');
                // Trigger email validation if not already done
                await checkEmailAvailability(email);
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
                // Show verification modal instead of going to step 2
                showVerificationModal();
                
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
                    
                    // Close modal and redirect to verification page
                    setTimeout(() => {
                        closeRegisterModal();
                        // Show success notification
                        showToast(data.message || 'Registration submitted! Please check your email for verification code.', 'success');
                        
                        // Redirect to verification page if provided
                        if (data.redirect) {
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 1000);
                        }
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
                
                // Age validation - must be 18+
                const dobInput = document.querySelector('input[name="date_of_birth"]');
                if (dobInput && dobInput.value) {
                    const birthDate = new Date(dobInput.value);
                    const today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    
                    if (age < 18) {
                        e.preventDefault();
                        dobInput.focus();
                        dobInput.classList.add('border-red-500');
                        alert('You must be at least 18 years old to register.');
                        return false;
                    }
                }
                
                // Middle initial validation - max 1 character if provided
                const middleInitialInput = document.getElementById('middle_initial_input');
                if (middleInitialInput && middleInitialInput.value.trim().length > 1) {
                    e.preventDefault();
                    middleInitialInput.focus();
                    middleInitialInput.classList.add('border-red-500');
                    alert('Middle initial can only be 1 character.');
                    return false;
                }
            });
            
            // Real-time validation for middle initial
            const middleInitialInputRealTime = document.getElementById('middle_initial_input');
            if (middleInitialInputRealTime) {
                middleInitialInputRealTime.addEventListener('input', function(e) {
                    const value = e.target.value.trim();
                    if (value.length > 1) {
                        e.target.value = value.charAt(0); // Keep only first character
                        e.target.classList.add('border-red-500');
                        setTimeout(() => {
                            e.target.classList.remove('border-red-500');
                        }, 2000);
                    } else {
                        e.target.classList.remove('border-red-500');
                    }
                });
            }
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
                        <label class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][first_name]" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" placeholder="Juan" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Middle Initial</label>
                        <input type="text" name="family_members[${familyMemberCount}][middle_initial]" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" placeholder="D." maxlength="1" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][last_name]" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" placeholder="Dela Cruz" />
                    </div>
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Relationship</label>
                        <select name="family_members[${familyMemberCount}][relationship]" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white">
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
                        <label class="block text-sm font-medium text-gray-700">Date of Birth</label>
                        <input type="date" name="family_members[${familyMemberCount}][date_of_birth]" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-gray-900 focus:ring-2 focus:ring-gray-100 transition-all duration-200 outline-none bg-white" />
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
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        
        // Function to open mobile menu
        function openMobileMenu() {
            if (mobileMenu) {
                mobileMenu.classList.add('active');
                if (mobileMenuOverlay) {
                    mobileMenuOverlay.classList.add('active');
                }
                if (mobileMenuBtn) {
                    mobileMenuBtn.classList.add('active');
                }
                document.body.style.overflow = 'hidden';
            }
        }
        
        // Function to close mobile menu
        function closeMobileMenu() {
            if (mobileMenu) {
                mobileMenu.classList.remove('active');
                if (mobileMenuOverlay) {
                    mobileMenuOverlay.classList.remove('active');
                }
                if (mobileMenuBtn) {
                    mobileMenuBtn.classList.remove('active');
                }
                document.body.style.overflow = '';
            }
        }
        
        // Make functions globally available
        window.closeMobileMenu = closeMobileMenu;
        window.openMobileMenu = openMobileMenu;
        
        if (mobileMenuBtn && mobileMenu) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (mobileMenu.classList.contains('active')) {
                    closeMobileMenu();
                } else {
                    openMobileMenu();
                }
            });
            
            // Close menu when clicking overlay
            if (mobileMenuOverlay) {
                mobileMenuOverlay.addEventListener('click', closeMobileMenu);
            }
            
            // Close mobile menu when clicking nav links
            mobileMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    closeMobileMenu();
                });
            });
            
            // Close menu on escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    closeMobileMenu();
                }
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
        // Enhanced Scroll Reveal Animations
        const scrollRevealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    // Also activate feature cards
                    if (entry.target.classList.contains('feature-card')) {
                        entry.target.classList.add('active');
                    }
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        // Observe all scroll reveal elements
        document.querySelectorAll('.scroll-reveal, .scroll-reveal-left, .scroll-reveal-right, .scroll-reveal-scale, .feature-card').forEach(el => {
            scrollRevealObserver.observe(el);
        });

        // Section visibility observer
        const sectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, {
            threshold: 0.1
        });

        document.querySelectorAll('section').forEach(section => {
            sectionObserver.observe(section);
        });

        // Navbar scroll effect
        let lastScroll = 0;
        const nav = document.querySelector('nav');
        
        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;
            
            if (currentScroll > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
            
            lastScroll = currentScroll;
        }, { passive: true });

        // Magnetic button effect
        document.querySelectorAll('.magnetic').forEach(button => {
            button.addEventListener('mousemove', (e) => {
                const rect = button.getBoundingClientRect();
                const x = e.clientX - rect.left - rect.width / 2;
                const y = e.clientY - rect.top - rect.height / 2;
                
                button.style.transform = `translate(${x * 0.1}px, ${y * 0.1}px)`;
            });
            
            button.addEventListener('mouseleave', () => {
                button.style.transform = '';
            });
        });

        // Parallax effect for hero elements
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const parallaxElements = document.querySelectorAll('.parallax-element');
            
            parallaxElements.forEach(element => {
                const speed = element.dataset.speed || 0.5;
                element.style.transform = `translateY(${scrolled * speed}px)`;
            });
        }, { passive: true });

        // Activate feature cards on load
        setTimeout(() => {
            document.querySelectorAll('.feature-card').forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('active');
                }, index * 100);
            });
        }, 500);

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
        
        
        // Email verification functions
        function showVerificationModal() {
            const modal = document.getElementById('verificationModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Show info toast about email verification
            showToast('Sending verification code to your email...', 'info');
            
            // Request verification code immediately
            requestVerificationCode();
        }
        
        function closeVerificationModal() {
            const modal = document.getElementById('verificationModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('verificationCode').value = '';
            document.getElementById('verificationError').classList.add('hidden');
        }
        
        async function requestVerificationCode() {
            const email = document.querySelector('input[name="email"]').value.trim();
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            
            if (!email || !firstName || !lastName) {
                showVerificationError('Please fill in email, first name, and last name first.');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'send_verification_code');
                formData.append('email', email);
                formData.append('first_name', firstName);
                formData.append('last_name', lastName);
                
                const response = await fetch('public/verify_email_step.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('Verification code sent!', 'success');
                } else {
                    // Show both toast and modal error
                    showToast(result.message || 'Failed to send verification code', 'error');
                    showVerificationError(result.message || 'Failed to send verification code');
                }
            } catch (error) {
                showToast('Network error. Please check your connection and try again.', 'error');
                showVerificationError('Error sending verification code');
            }
        }
        
        async function verifyCode() {
            const code = document.getElementById('verificationCode').value.trim();
            
            if (!code || code.length !== 6) {
                showVerificationError('Please enter a 6-digit code');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'verify_code');
                formData.append('code', code);
                
                const response = await fetch('public/verify_email_step.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('Email verified!', 'success');
                    closeVerificationModal();
                    goToStep(2);
                } else {
                    showVerificationError(result.message || 'Invalid verification code');
                }
            } catch (error) {
                showVerificationError('Error verifying code');
            }
        }
        
        function showVerificationError(message) {
            const errorDiv = document.getElementById('verificationError');
            errorDiv.textContent = message;
            errorDiv.classList.remove('hidden');
            setTimeout(() => {
                errorDiv.classList.add('hidden');
            }, 5000);
        }
        
        // Allow Enter key to verify
        document.getElementById('verificationCode').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyCode();
            }
        });
    </script>
</body>
</html>


