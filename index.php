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
    
    // Sanitize input data - remove banned characters and prevent HTML/script injection
    function sanitizeInputBackend($value, $pattern = null) {
        if (empty($value)) return '';
        
        // Remove script tags and HTML tags (prevent XSS)
        $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi', '', $value);
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
        
        // HTML entity encoding for security (but decode for storage if needed)
        // Note: We'll use htmlspecialchars when displaying, not when storing
        return $sanitized;
    }
    
    // Validation rules with comprehensive sanitization
    // First name validation (letters only)
    if (empty($first) || strlen($first) < 2) {
        $errors[] = 'First name must be at least 2 characters long.';
    } elseif (preg_match('/\d/', $first)) {
        $errors[] = 'Only letters, spaces, hyphens, and apostrophes are allowed.';
    } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first)) {
        $errors[] = 'Only letters, spaces, hyphens, and apostrophes are allowed.';
    } else {
        $first = sanitizeInputBackend($first, 'A-Za-zÀ-ÿ\' -');
    }
    
    // Last name validation (letters only)
    if (empty($last) || strlen($last) < 2) {
        $errors[] = 'Last name must be at least 2 characters long.';
    } elseif (preg_match('/\d/', $last)) {
        $errors[] = 'Only letters, spaces, hyphens, and apostrophes are allowed.';
    } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last)) {
        $errors[] = 'Only letters, spaces, hyphens, and apostrophes are allowed.';
    } else {
        $last = sanitizeInputBackend($last, 'A-Za-zÀ-ÿ\' -');
    }
    
    // Middle initial validation
    if (!empty($middle)) {
        $middle = sanitizeInputBackend($middle, 'A-Za-zÀ-ÿ');
        if (strlen($middle) > 1) {
            $errors[] = 'Middle initial can only be 1 character.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ]+$/', $middle)) {
            $errors[] = 'Middle initial can only contain letters.';
        }
    }
    
    // Email validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,}$/', $email)) {
        $errors[] = 'Email format is invalid.';
    }
    
    // Password validation (comprehensive check)
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } else {
        $hasLetter = preg_match('/[A-Za-z]/', $password);
        $hasNumber = preg_match('/\d/', $password);
        $hasSpecial = preg_match('/[@$!%*?&]/', $password);
        $hasBanned = preg_match('/[#^&*()={}\[\]:;"<>?\/\\\|~`_]/', $password);
        
        if (!$hasLetter) {
            $errors[] = 'Password must contain at least 1 letter.';
        }
        if (!$hasNumber) {
            $errors[] = 'Password must contain at least 1 number.';
        }
        if (!$hasSpecial) {
            $errors[] = 'Password must contain at least 1 special character (@$!%*?&).';
        }
        if ($hasBanned) {
            $errors[] = 'Password contains invalid characters. Only @$!%*?& are allowed as special characters.';
        }
    }
    
    // Date of birth validation with age verification (exact formula: isAdult)
    if (empty($dob)) {
        $errors[] = 'Please select your date of birth.';
    } else {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        
        if ($birthDate > $today) {
            $errors[] = 'Date of birth cannot be in the future.';
        } else {
            // Calculate age using exact formula: isAdult function
            $age = (int)$today->format('Y') - (int)$birthDate->format('Y');
            $m = (int)$today->format('m') - (int)$birthDate->format('m');
            $d = (int)$today->format('d') - (int)$birthDate->format('d');
            
            if ($m < 0 || ($m === 0 && $d < 0)) {
                $age--;
            }
        
        if ($age < 18) {
                $errors[] = 'You must be 18 years or older to create a MediTrack account.';
            } elseif ($age > 120) {
                $errors[] = 'Please enter a valid date of birth.';
            }
        }
    }
    
    // Phone number validation (optional but if provided, must be valid)
    if (!empty($phone)) {
        $phone = sanitizeInputBackend($phone, '0-9+ ');
        if (!preg_match('/^[0-9+ ]{7,15}$/', $phone)) {
            $errors[] = 'Phone number can only contain digits, spaces, and + sign (7-15 characters).';
        }
        $phoneCleaned = preg_replace('/\s/', '', $phone);
        if (strlen($phoneCleaned) < 7 || strlen($phoneCleaned) > 15) {
            $errors[] = 'Phone number must be between 7 and 15 digits.';
        }
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
            $relationship_other = trim($member['relationship_other'] ?? '');
            $date_of_birth = $member['date_of_birth'] ?? '';
            
            // If "Other" is selected, use the custom relationship text
            if ($relationship === 'Other' && !empty($relationship_other)) {
                $relationship = sanitizeInputBackend($relationship_other, 'A-Za-zÀ-ÿ\' -');
            }
            
            // Only validate if at least one field is filled
            if (!empty($first_name) || !empty($last_name) || !empty($relationship) || !empty($date_of_birth)) {
                // Sanitize and validate first name (letters only)
                if (empty($first_name) || strlen($first_name) < 2) {
                    $errors[] = "Family member " . ($index + 1) . ": First name must be at least 2 characters long.";
                } elseif (preg_match('/\d/', $first_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Only letters, spaces, hyphens, and apostrophes are allowed.";
                } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Only letters, spaces, hyphens, and apostrophes are allowed.";
                } else {
                    $first_name = sanitizeInputBackend($first_name, 'A-Za-zÀ-ÿ\' -');
                }
                
                // Sanitize and validate last name (letters only)
                if (empty($last_name) || strlen($last_name) < 2) {
                    $errors[] = "Family member " . ($index + 1) . ": Last name must be at least 2 characters long.";
                } elseif (preg_match('/\d/', $last_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Only letters, spaces, hyphens, and apostrophes are allowed.";
                } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Only letters, spaces, hyphens, and apostrophes are allowed.";
                } else {
                    $last_name = sanitizeInputBackend($last_name, 'A-Za-zÀ-ÿ\' -');
                }
                
                // Middle initial validation for family members
                if (!empty($middle_initial)) {
                    $middle_initial = sanitizeInputBackend($middle_initial, 'A-Za-zÀ-ÿ');
                    if (strlen($middle_initial) > 1) {
                    $errors[] = "Family member " . ($index + 1) . ": Middle initial can only be 1 character.";
                    } elseif (!preg_match('/^[A-Za-zÀ-ÿ]+$/', $middle_initial)) {
                        $errors[] = "Family member " . ($index + 1) . ": Middle initial can only contain letters.";
                    }
                }
                
                // Date of birth validation
                if (!empty($date_of_birth)) {
                    $birthDate = new DateTime($date_of_birth);
                    $today = new DateTime();
                    if ($birthDate > $today) {
                        $errors[] = "Family member " . ($index + 1) . ": Date of birth cannot be in the future.";
                    }
                }
                
                if (empty($relationship)) {
                    $errors[] = "Family member " . ($index + 1) . ": Relationship is required.";
                } elseif ($relationship === 'Other' && empty($relationship_other)) {
                    $errors[] = "Family member " . ($index + 1) . ": Please specify the relationship when 'Other' is selected.";
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
        
        /* Navbar Active Link Styles */
        .nav-link {
            transition: all 0.2s ease;
            position: relative;
        }
        
        .nav-link.active {
            color: #2563eb;
            background-color: #eff6ff;
            font-weight: 600;
        }
        
        .nav-link:not(.active) {
            color: #374151;
        }
        
        .nav-link:not(.active):hover {
            color: #2563eb;
            background-color: #f9fafb;
        }
        
        /* Mobile Nav Link Active Styles */
        .nav-link-mobile {
            transition: all 0.2s ease;
        }
        
        .nav-link-mobile.active-mobile {
            color: #2563eb;
            background-color: #eff6ff;
            font-weight: 600;
        }
        
        .nav-link-mobile.active-mobile span {
            background-color: #2563eb;
        }
        
        .nav-link-mobile:not(.active-mobile):hover span {
            background-color: #d1d5db;
        }
        
        /* Mobile Menu Animation - Simple and Clean */
        #mobileMenu {
            max-height: 0;
            opacity: 0;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            overflow: hidden;
        }
        
        #mobileMenu:not(.hidden) {
            max-height: 400px;
            opacity: 1;
        }
        
        /* Mobile Menu Link Styles - Vertical Layout */
        #mobileMenu .flex.flex-col {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
        }
        
        .nav-link-mobile {
            display: block;
            width: 100%;
            text-align: left;
        }
        
        .nav-link-mobile.active-mobile {
            color: #2563eb;
            background-color: #eff6ff;
        }
        
        /* Ensure mobile menu is hidden on desktop */
        @media (min-width: 768px) {
            #mobileMenu {
                display: none !important;
            }
        }
        
        /* Mobile menu visibility */
        @media (max-width: 767px) {
            #mobileMenu.hidden {
                display: none;
            }
            
            #mobileMenu:not(.hidden) {
                display: block;
            }
        }
        
        /* Responsive Navbar Improvements */
        nav {
            overflow-x: hidden;
        }
        
        /* Navbar backdrop blur effect */
        @supports (backdrop-filter: blur(12px)) {
            nav {
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
            }
        }
        
        /* Ensure perfect horizontal alignment of logo, text, and hamburger - single row */
        nav > div > div {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: space-between !important;
            flex-wrap: nowrap !important;
        }
        
        /* Logo container alignment - stays left */
        nav > div > div > div:first-child {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            flex-shrink: 0 !important;
            margin-right: auto !important;
        }
        
        /* Logo text alignment */
        nav > div > div > div:first-child > span {
            display: inline-flex !important;
            align-items: center !important;
            line-height: 1 !important;
            white-space: nowrap !important;
        }
        
        /* Hamburger button alignment - stays right, hidden on desktop */
        nav button#mobileMenuBtn {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex-shrink: 0 !important;
            margin-left: auto !important;
        }
        
        /* Ensure hamburger is hidden on desktop (md and above) */
        @media (min-width: 768px) {
            nav button#mobileMenuBtn {
                display: none !important;
            }
        }
        
        /* Ensure hamburger is visible on mobile (below md) */
        @media (max-width: 767px) {
            nav button#mobileMenuBtn {
                display: flex !important;
            }
        }
        
        /* Ensure logo stays left and hamburger stays right - single row */
        @media (max-width: 767px) {
            /* Ensure single row layout */
            nav > div > div {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
            }
            
            /* Logo container - left side */
            nav > div > div > div:first-child {
                margin-right: auto !important;
                flex-shrink: 0 !important;
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
            }
            
            /* Ensure logo icon and text stay side by side */
            nav > div > div > div:first-child > div,
            nav > div > div > div:first-child > span {
                display: inline-flex !important;
                align-items: center !important;
                flex-shrink: 0 !important;
            }
            
            /* Hamburger button - right side */
            nav button#mobileMenuBtn {
                margin-left: auto !important;
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
        }
        
        /* Tablet adjustments */
        @media (min-width: 768px) and (max-width: 1023px) {
            nav .hidden.md\\:flex {
                gap: 1rem;
            }
        }
        
        /* Desktop enhancements */
        @media (min-width: 1024px) {
            nav .nav-link {
                padding: 0.5rem 1rem;
            }
        }
        
        /* Hamburger icon animation */
        #mobileMenuBtn[aria-expanded="true"] #hamburgerIcon {
            transform: rotate(90deg);
        }
        
            /* Smooth scroll offset for navbar - adjusted for larger navbar */
            html {
                scroll-padding-top: 68px;
            }
            
            @media (min-width: 768px) {
                html {
                    scroll-padding-top: 76px;
                }
            }
            
            @media (min-width: 1024px) {
                html {
                    scroll-padding-top: 84px;
                }
            }
        
        /* Enhanced Animations */
        @keyframes fadeInUp {
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
        
        /* About Section Modern Animations */
        .about-content {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .about-content.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        .about-feature {
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1), transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .about-feature.visible {
            opacity: 1;
            transform: translateX(0);
        }
        
        .about-stat {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1), transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .about-stat.visible {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        
        @keyframes numberCount {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .about-stat.visible > div:first-child {
            animation: numberCount 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        details summary {
            transition: all 0.3s ease;
        }
        
        details summary:hover {
            color: #3b82f6;
        }
        
        /* Modern CTA Section Styles */
        .cta-modern-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.98) 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(255, 255, 255, 0.5);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .cta-modern-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899, #3b82f6);
            background-size: 200% 100%;
            animation: gradientFlow 3s ease infinite;
        }
        
        @keyframes gradientFlow {
            0%, 100% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
        }
        
        .cta-modern-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 70px rgba(99, 102, 241, 0.15), 0 0 0 1px rgba(255, 255, 255, 0.5);
        }
        
        @media (max-width: 640px) {
            .cta-modern-card:hover {
                transform: translateY(-2px);
            }
        }
        
        .cta-modern-button {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #ec4899 100%);
            background-size: 200% 200%;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 44px; /* Better touch target on mobile */
        }
        
        .cta-modern-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .cta-modern-button:hover {
            background-position: 100% 0%;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
        }
        
        .cta-modern-button:hover::before {
            left: 100%;
        }
        
        .cta-modern-button:active {
            transform: translateY(0);
        }
        
        @media (max-width: 640px) {
            .cta-modern-button:hover {
                transform: translateY(-1px);
            }
        }
        
        .cta-title-modern {
            background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Simple Visual FAQ Styles */
        .faq-modern {
            background: #f9fafb;
        }
        
        .faq-item {
            background: #ffffff;
            border-left: 4px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .faq-item:hover {
            border-left-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateX(4px);
        }
        
        .faq-item[open] {
            border-left-color: #3b82f6;
            border-left-width: 5px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
            background: #eff6ff;
        }
        
        .faq-summary {
            position: relative;
            list-style: none;
            cursor: pointer;
            user-select: none;
        }
        
        .faq-summary::-webkit-details-marker {
            display: none;
        }
        
        .faq-summary::marker {
            display: none;
        }
        
        .faq-icon {
            transition: transform 0.3s ease;
            color: #6b7280;
            flex-shrink: 0;
            margin: 0;
            padding: 0;
            display: inline-block;
            vertical-align: middle;
            white-space: nowrap;
        }
        
        .faq-item[open] .faq-icon {
            transform: rotate(180deg);
            color: #3b82f6;
        }
        
        .faq-question {
            transition: color 0.3s ease;
            color: #111827;
            font-weight: 600;
            word-wrap: break-word;
            hyphens: auto;
        }
        
        .faq-item:hover .faq-question {
            color: #3b82f6;
        }
        
        .faq-item[open] .faq-question {
            color: #3b82f6;
        }
        
        .faq-answer {
            animation: fadeIn 0.3s ease-out;
            color: #4b5563;
            word-wrap: break-word;
            hyphens: auto;
        }
        
        .faq-question {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left !important;
            margin: 0;
            padding: 0;
            line-height: 1.5;
            display: inline-block;
            max-width: 100%;
        }
        
        .faq-summary {
            text-align: left;
        }
        
        .faq-summary > div {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin: 0;
            padding: 0;
            flex-wrap: nowrap;
            flex-direction: row;
        }
        
        /* Ensure justify-between works on mobile */
        @media (max-width: 640px) {
            .faq-summary > div {
                justify-content: space-between;
                gap: 0.5rem;
            }
            
            .faq-question {
                flex: 1 1 auto;
                min-width: 0;
                margin-right: 0.5rem;
            }
            
            .faq-icon {
                flex-shrink: 0;
                margin-left: auto;
                align-self: center;
                vertical-align: middle;
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .faq-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 0;
            border: none;
        }
        
        /* Minimalist Footer Styles */
        .footer-modern {
            background: #ffffff;
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
        
        /* Navbar clean styling */
        nav {
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        /* Ensure proper alignment */
        nav > div > div {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Responsive navbar improvements */
        @media (max-width: 640px) {
            nav {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }
            
            /* Prevent logo text overflow on very small screens */
            nav span[class*="text-base"] {
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
        
        @media (max-width: 380px) {
            nav span[class*="text-base"] {
                font-size: 0.875rem;
                max-width: 120px;
            }
            
            nav .w-9 {
                width: 2rem;
                height: 2rem;
            }
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
        
        /* Mobile Menu Dropdown Styles */
        #mobileMenu {
            transition: all 0.3s ease;
        }
        
        #mobileMenu.hidden {
            display: none;
        }
        
        /* Hamburger Button */
        #mobileMenuBtn {
            transition: transform 0.3s ease;
        }
        
        #mobileMenuBtn:hover {
            transform: scale(1.1);
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
        
        /* Modern Minimal Login Modal Styles */
        #loginModal {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease-out;
        }
        
        .login-modal-container {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.12),
                0 0 0 1px rgba(59, 130, 246, 0.08),
                0 0 0 2px transparent;
            position: relative;
            overflow: hidden;
            animation: modalFadeInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            transition: box-shadow 0.3s ease;
        }
        
        /* Gradient border effect on hover */
        .login-modal-container:hover {
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(59, 130, 246, 0.2),
                0 0 0 2px rgba(139, 92, 246, 0.15);
        }
        
        @keyframes modalFadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* Logo container */
        .login-logo-wrapper {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-top: 2rem;
            background: transparent;
            width: 100%;
        }
        
        .login-logo-wrapper img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            background: transparent !important;
            border: none !important;
            outline: none !important;
            border-radius: 0;
            padding: 0;
            margin: 0;
            display: block;
            box-shadow: none !important;
            -webkit-box-shadow: none !important;
        }
        
        /* Canvas for processed logo (hidden, used for processing) */
        .login-logo-canvas {
            display: none;
        }
        
        /* Remove any potential background box from logo wrapper */
        .login-logo-wrapper::before,
        .login-logo-wrapper::after {
            display: none !important;
            content: none !important;
        }
        
        /* Modal header text */
        .login-modal-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.5rem;
            text-align: center;
            letter-spacing: -0.02em;
        }
        
        .login-modal-subtitle {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        /* Input fields */
        .login-input-wrapper {
            position: relative;
            margin-bottom: 1.25rem;
        }
        
        .login-input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.25rem;
            height: 1.25rem;
            color: #9ca3af;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        
        .login-input-field {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.9375rem;
            color: #111827;
            transition: all 0.2s ease;
            outline: none;
        }
        
        .login-input-field::placeholder {
            color: #9ca3af;
        }
        
        .login-input-field:focus {
            background: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        
        .login-input-wrapper:focus-within .login-input-icon {
            color: #3b82f6;
        }
        
        /* Password toggle button */
        .login-password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease;
        }
        
        .login-password-toggle:hover {
            color: #6b7280;
        }
        
        .login-password-toggle svg {
            width: 1.25rem;
            height: 1.25rem;
        }
        
        /* Forgot password link */
        .login-forgot-link {
            display: block;
            text-align: right;
            font-size: 0.8125rem;
            color: #6b7280;
            text-decoration: none;
            margin-top: -0.75rem;
            margin-bottom: 1.5rem;
            transition: color 0.2s ease;
        }
        
        .login-forgot-link:hover {
            color: #3b82f6;
        }
        
        /* Error message */
        .login-error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .login-error-message svg {
            width: 1.125rem;
            height: 1.125rem;
            flex-shrink: 0;
        }
        
        /* Sign in button */
        .login-submit-button {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .login-submit-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .login-submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        .login-submit-button:hover::before {
            left: 100%;
        }
        
        .login-submit-button:active {
            transform: translateY(0);
        }
        
        .login-submit-button.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .login-submit-button.loading::after {
            content: '';
            position: absolute;
            width: 1rem;
            height: 1rem;
            top: 50%;
            left: 50%;
            margin-left: -0.5rem;
            margin-top: -0.5rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Register link section */
        .login-register-section {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .login-register-text {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.75rem;
        }
        
        .login-register-link {
            color: #3b82f6;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .login-register-link:hover {
            color: #8b5cf6;
        }
        
        /* Close button */
        .login-close-button {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e5e7eb;
            border-radius: 50%;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }
        
        .login-close-button:hover {
            background: #f3f4f6;
            color: #111827;
            transform: rotate(90deg);
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .login-modal-container {
                background: #ffffff;
            box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.4),
                    0 0 0 1px rgba(59, 130, 246, 0.2);
            }
            
            .login-modal-title {
                color: #111827;
            }
            
            .login-modal-subtitle {
                color: #6b7280;
            }
            
            .login-input-field {
                background: #ffffff;
                border-color: #e5e7eb;
                color: #111827;
            }
            
            .login-input-field:focus {
                background: #ffffff;
                border-color: #3b82f6;
            }
            
            .login-register-section {
                border-top-color: #e5e7eb;
            }
            
            .login-close-button {
                background: rgba(255, 255, 255, 0.9);
                border-color: #e5e7eb;
                color: #6b7280;
            }
            
            .login-close-button:hover {
                background: #f3f4f6;
                color: #111827;
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-modal-container {
                max-width: calc(100% - 2rem);
                border-radius: 20px;
            }
            
            .login-modal-title {
                font-size: 1.5rem;
            }
        }
        
        /* Modern Registration Modal Styles */
        #registerModal {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease-out;
        }
        
        .register-modal-container {
            width: 100%;
            max-width: 900px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(59, 130, 246, 0.08);
            position: relative;
            overflow: hidden;
            animation: modalFadeInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Enhanced Gradient Header */
        .register-modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 50%, #8b5cf6 100%);
            padding: 2.5rem 2.5rem 2rem 2.5rem;
            position: relative;
            overflow: hidden;
            border-radius: 1.5rem 1.5rem 0 0;
        }
        
        .register-modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        .register-modal-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            50% { transform: translateY(-20px) translateX(10px); }
        }
        
        .register-header-content {
            position: relative;
            z-index: 10;
        }
        
        .register-modal-title {
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            letter-spacing: -0.03em;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .register-modal-subtitle {
            font-size: 0.9375rem;
            color: rgba(255, 255, 255, 0.95);
            margin-top: 0.5rem;
            line-height: 1.5;
        }
        
        .register-close-button {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 2.75rem;
            height: 2.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 20;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .register-close-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        .register-close-button:active {
            transform: rotate(90deg) scale(0.95);
        }
        
        /* Step Indicator */
        .register-step-indicator {
            background: #ffffff;
            padding: 1.5rem 2.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .step-indicator-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }
        
        .step-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .step-number {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .step-number.active {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .step-number.inactive {
            background: #f3f4f6;
            color: #9ca3af;
        }
        
        .step-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            transition: color 0.3s ease;
        }
        
        .step-item.active .step-label {
            color: #111827;
            font-weight: 600;
        }
        
        .step-divider {
            width: 3rem;
            height: 2px;
            background: #e5e7eb;
            transition: background 0.3s ease;
        }
        
        .step-divider.completed {
            background: linear-gradient(90deg, #3b82f6 0%, #8b5cf6 100%);
        }
        
        /* Form Section */
        .register-form-body {
            padding: 2rem 2.5rem;
            overflow-y: auto;
            flex: 1;
        }
        
        .form-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 1.5rem;
        }
        
        .form-section-icon {
            width: 2.5rem;
            height: 2.5rem;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .form-section-icon svg {
            display: block;
        }
        
        /* Modern Input Fields */
        .register-input-field {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.9375rem;
            color: #111827;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            outline: none;
            box-sizing: border-box;
            height: 3rem; /* Fixed height to prevent layout shift */
            line-height: 1.5;
        }
        
        /* Select fields - CRITICAL: Need sufficient padding to prevent text cutoff */
        select.register-input-field {
            padding-right: 3.5rem !important; /* Space for dropdown arrow */
            padding-top: 0.75rem !important;
            padding-bottom: 0.75rem !important;
            cursor: pointer;
            width: 100%;
            box-sizing: border-box;
            line-height: 1.5;
            height: 3rem;
            display: block;
            vertical-align: middle;
        }
        
        /* Select fields with icon need extra right padding for dropdown arrow */
        select.register-input-field.has-icon {
            padding-left: 3rem !important; /* Space for icon on left */
            padding-right: 3.5rem !important; /* Space for dropdown arrow on right */
            padding-top: 0.75rem !important;
            padding-bottom: 0.75rem !important;
        }
        
        /* Ensure selected text in select is fully visible on all states */
        select.register-input-field:focus,
        select.register-input-field:active,
        select.register-input-field:hover {
            padding-right: 3.5rem !important;
        }
        
        /* Select option text should not be cut in dropdown */
        select.register-input-field option {
            white-space: normal;
            padding: 0.5rem;
            word-wrap: break-word;
        }
        
        .register-input-field::placeholder {
            color: #9ca3af;
        }
        
        .register-input-field:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        
        .register-input-wrapper {
            position: relative;
            min-height: 3.5rem; /* Reserve minimum height to prevent layout shift */
        }
        
        .register-input-icon {
            position: absolute;
            left: 1rem;
            top: 1.5rem; /* Center of 3rem input field */
            transform: translateY(-50%);
            width: 1.25rem;
            height: 1.25rem;
            color: #9ca3af;
            pointer-events: none;
            transition: color 0.2s ease;
            display: block;
            z-index: 10;
            margin: 0;
            padding: 0;
            will-change: auto;
            line-height: 1;
        }
        
        .register-input-icon svg {
            display: block;
            width: 100%;
            height: 100%;
            vertical-align: middle;
        }
        
        .register-input-wrapper:focus-within .register-input-icon {
            color: #3b82f6;
        }
        
        .register-input-field.has-icon {
            padding-left: 3rem;
            padding-right: 1rem;
        }
        
        /* Override for select fields with icon - need more right padding for dropdown arrow */
        select.register-input-field.has-icon {
            padding-left: 3rem !important;
            padding-right: 3.5rem !important; /* Space for icon on left + dropdown arrow on right */
        }
        
        .register-input-field {
            position: relative;
            z-index: 1;
        }
        
        /* Right side icons (password toggle, calendar, dropdown arrow, etc.) */
        .register-input-wrapper .absolute.right-0 {
            display: flex;
            align-items: center;
            justify-content: center;
            top: 50%;
            transform: translateY(-50%);
            right: 0.75rem !important;
            width: auto;
            min-width: 2rem;
            height: auto;
            pointer-events: none;
            z-index: 5;
            margin: 0;
            padding: 0;
        }
        
        .register-input-wrapper .absolute.right-0 svg {
            display: block;
            flex-shrink: 0;
            width: 1.25rem;
            height: 1.25rem;
        }
        
        /* Dropdown arrow specific styling */
        .register-dropdown-arrow {
            position: absolute;
            right: 0.75rem;
            top: 1.5rem; /* Center of 3rem input field */
            transform: translateY(-50%);
            pointer-events: none;
            z-index: 5;
            display: block;
            width: 1.25rem;
            height: 1.25rem;
            margin: 0;
            padding: 0;
        }
        
        .register-dropdown-arrow svg {
            display: block;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            vertical-align: middle;
        }
        
        /* Ensure select text area doesn't overlap with arrow */
        .register-input-wrapper select.register-input-field {
            text-indent: 0;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        
        /* Password toggle button */
        .password-toggle-btn {
            position: absolute;
            right: 0.75rem;
            top: 1.5rem; /* Center of 3rem input field */
            transform: translateY(-50%);
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            transition: color 0.2s ease;
            z-index: 10;
            margin: 0;
            width: 1.5rem;
            height: 1.5rem;
            will-change: auto;
        }
        
        .password-toggle-btn svg {
            display: block;
            width: 1.25rem;
            height: 1.25rem;
            vertical-align: middle;
        }
        
        .password-toggle-btn:hover {
            color: #374151;
        }
        
        .password-toggle-btn svg {
            width: 1.25rem;
            height: 1.25rem;
            display: block;
            flex-shrink: 0;
        }
        
        /* Date input calendar icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 0.75rem;
            top: 1.5rem; /* Center of 3rem input field */
            transform: translateY(-50%);
            cursor: pointer;
            opacity: 0.6;
            z-index: 1;
            width: 1.25rem;
            height: 1.25rem;
            margin: 0;
            padding: 0;
        }
        
        input[type="date"].has-icon {
            padding-right: 3rem;
        }
        
        /* Fix date input calendar icon positioning */
        .register-input-wrapper input[type="date"] {
            position: relative;
        }
        
        .register-input-wrapper input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 0.75rem;
            top: 1.5rem; /* Fixed position: half of input height (3rem / 2) */
            transform: translateY(-50%);
            cursor: pointer;
            opacity: 0.6;
            z-index: 1;
        }
        
        .form-section-title svg {
            flex-shrink: 0;
        }
        
        /* Validation Messages */
        .register-validation-message {
            font-size: 0.8125rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .register-validation-message svg {
            flex-shrink: 0;
            margin-top: 0.125rem;
        }
        
        /* Validation message container - reserves space to prevent layout shift */
        .validation-message-container {
            min-height: 1.5rem;
            margin-top: 0.5rem;
            position: relative;
            width: 100%;
        }
        
        .field-validation-message {
            font-size: 0.75rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            line-height: 1.4;
            position: relative;
            width: 100%;
            animation: fadeIn 0.2s ease;
        }
        
        .field-validation-message svg {
            flex-shrink: 0;
            margin-top: 0.125rem;
            width: 1rem;
            height: 1rem;
        }
        
        .field-validation-message span {
            line-height: 1.4;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .register-validation-message.error {
            color: #dc2626;
        }
        
        .register-validation-message.success {
            color: #16a34a;
        }
        
        .register-validation-message.info {
            color: #6b7280;
        }
        
        /* Email Validation Status */
        .email-validation-status {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .email-validation-status.success {
            background-color: #f0fdf4;
            border: 1px solid #86efac;
            color: #16a34a;
        }
        
        .email-validation-status.error {
            background-color: #fef2f2;
            border: 1px solid #fca5a5;
            color: #dc2626;
        }
        
        .email-validation-status.warning {
            background-color: #fffbeb;
            border: 1px solid #fcd34d;
            color: #d97706;
        }
        
        .email-validation-status.loading {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            color: #6b7280;
        }
        
        /* MailboxLayer Badge */
        .mailboxlayer-badge {
            padding: 0.5rem 0.75rem;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            display: inline-block;
        }
        
        .tooltip-wrapper {
            position: relative;
            display: inline-block;
        }
        
        .tooltip-content {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 0.5rem;
            padding: 0.5rem 0.75rem;
            background-color: #1f2937;
            color: #ffffff;
            font-size: 0.75rem;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 1000;
            max-width: 250px;
            white-space: normal;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .tooltip-content::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #1f2937;
        }
        
        .tooltip-wrapper:hover .tooltip-content {
            opacity: 1;
        }
        
        /* Disabled Button */
        .register-primary-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .register-primary-button:disabled:hover {
            box-shadow: none !important;
            transform: none !important;
        }
        
        /* Buttons */
        .register-primary-button {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .register-primary-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .register-primary-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        .register-primary-button:hover::before {
            left: 100%;
        }
        
        .register-secondary-link {
            display: block;
            text-align: center;
            color: #6b7280;
            font-size: 0.875rem;
            text-decoration: none;
            margin-top: 1rem;
            transition: color 0.2s ease;
        }
        
        .register-secondary-link:hover {
            color: #111827;
        }
        
        /* Back Button */
        .register-back-button {
            padding: 0.875rem 1.5rem;
            background: #ffffff;
            color: #6b7280;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .register-back-button:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #111827;
        }
        
        /* Step Content Animation */
        .step-content {
            animation: fadeInSlide 0.4s ease-out;
        }
        
        @keyframes fadeInSlide {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Family Members Section */
        .family-members-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
        }
        
        .family-member-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .family-member-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .family-member-card.expanded {
            border-color: #3b82f6;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.12);
        }
        
        .family-member-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
        }
        
        .family-member-header:hover {
            background-color: #f9fafb;
        }
        
        .family-member-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .family-member-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #111827;
        }
        
        .family-member-chevron {
            width: 1.25rem;
            height: 1.25rem;
            color: #6b7280;
            transition: transform 0.3s ease;
        }
        
        .family-member-card.expanded .family-member-chevron {
            transform: rotate(180deg);
        }
        
        .family-member-header-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .remove-family-member-btn {
            background: transparent;
            border: none;
            color: #dc2626;
            padding: 0.375rem;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .remove-family-member-btn:hover {
            background: #fef2f2;
            color: #b91c1c;
        }
        
        .family-member-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            padding: 0 1.25rem;
        }
        
        .family-member-card.expanded .family-member-content {
            max-height: 500px;
            padding: 0 1.25rem 1.25rem 1.25rem;
        }
        
        .family-member-fields {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .family-member-fields-row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 768px) {
            .family-member-fields {
                grid-template-columns: 1fr;
            }
            
            .family-member-fields-row2 {
                grid-template-columns: 1fr;
            }
        }
        
        .add-member-button {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .add-member-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        /* Review Section */
        .review-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
        }
        
        .review-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .review-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .review-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
        }
        
        .edit-link {
            color: #3b82f6;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        
        .edit-link:hover {
            color: #2563eb;
            text-decoration: underline;
        }
        
        .review-info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .review-info-item:last-child {
            border-bottom: none;
        }
        
        .review-info-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .review-info-value {
            font-size: 0.875rem;
            color: #111827;
            font-weight: 400;
        }
        
        /* Submit Button */
        .register-submit-button {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .register-submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(22, 163, 74, 0.4);
        }
        
        /* Email Verification Modal */
        .verification-modal-container {
            width: 100%;
            max-width: 450px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(59, 130, 246, 0.08);
            position: relative;
            overflow: hidden;
            animation: modalFadeInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .verification-code-input {
            width: 100%;
            padding: 1rem;
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            text-align: center;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .verification-code-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .resend-timer {
            text-align: center;
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 1rem;
        }
        
        .resend-timer.active {
            color: #3b82f6;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .register-modal-container {
                max-width: 100%;
                max-height: 100vh;
                border-radius: 0;
            }
            
            .register-modal-header {
                padding: 1.5rem 1.25rem;
            }
            
            .register-form-body {
                padding: 1.5rem 1.25rem;
            }
            
            .register-step-indicator {
                padding: 1rem 1.25rem;
            }
            
            .step-label {
                display: none;
            }
            
            .step-divider {
                width: 1.5rem;
            }
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
     <nav class="fixed top-0 left-0 right-0 w-full z-50 bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-3 sm:px-4 md:px-5 lg:px-6 xl:px-8">
            <div class="flex items-center justify-between py-2.5 sm:py-3 md:py-3.5 lg:py-4 w-full flex-nowrap">
                <!-- Logo Left - Always visible, forced left alignment -->
                <div class="flex items-center gap-1.5 sm:gap-2 md:gap-2.5 flex-shrink-0">
                    <?php $brand = get_setting('brand_name', 'MediTrack'); ?>
                    <div class="w-9 h-9 sm:w-10 sm:h-10 md:w-11 md:h-11 lg:w-12 lg:h-12 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-lg flex items-center justify-center shadow-md flex-shrink-0 transition-transform duration-200 hover:scale-105">
                        <svg class="w-5 h-5 sm:w-5 sm:h-5 md:w-6 md:h-6 lg:w-7 lg:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                        </svg>
                    </div>
                    <span class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600 whitespace-nowrap flex-shrink-0">
                        <?php echo htmlspecialchars($brand); ?>.
                    </span>
                </div>

                <!-- Desktop Nav Links - Center -->
                <div class="hidden md:flex md:items-center gap-x-5 lg:gap-x-7 xl:gap-x-8 flex-1 justify-center mx-4 lg:mx-8">
                    <a href="#home" id="nav-home" class="nav-link active text-sm sm:text-base font-semibold text-blue-600 hover:text-blue-700 transition-all duration-200 px-3.5 py-2.5 rounded-lg whitespace-nowrap">Home</a>
                    <a href="#features" id="nav-features" class="nav-link text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 transition-all duration-200 px-3.5 py-2.5 rounded-lg whitespace-nowrap">Features</a>
                    <a href="#about" id="nav-about" class="nav-link text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 transition-all duration-200 px-3.5 py-2.5 rounded-lg whitespace-nowrap">About</a>
                    <a href="#contact" id="nav-contact" class="nav-link text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 transition-all duration-200 px-3.5 py-2.5 rounded-lg whitespace-nowrap">Contact</a>
                </div>

                <!-- Desktop CTA Buttons - Right -->
                <div class="hidden md:flex md:items-center gap-2.5 lg:gap-3 flex-shrink-0">
                    <button onclick="openLoginModal()" class="text-gray-700 text-sm sm:text-base font-semibold hover:text-blue-600 transition-all duration-200 px-3.5 py-2.5 rounded-lg hover:bg-gray-50 whitespace-nowrap">Sign in</button>
                    <button onclick="openRegisterModal()" class="text-white text-sm sm:text-base font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 px-4.5 lg:px-6 py-2.5 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105 whitespace-nowrap">Register</button>
                </div>

                <!-- Mobile Hamburger Button - Right, aligned horizontally with logo -->
                <button id="mobileMenuBtn" class="md:hidden cursor-pointer text-blue-600 hover:text-blue-700 transition-all duration-200 flex-shrink-0 p-2 sm:p-2.5 rounded-lg hover:bg-blue-50 active:bg-blue-100 flex items-center justify-center" aria-label="Toggle menu" aria-expanded="false">
                    <svg id="hamburgerIcon" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 sm:w-7 sm:h-7 transition-transform duration-200" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12.9499909,17 C12.7183558,18.1411202 11.709479,19 10.5,19 C9.29052104,19 8.28164422,18.1411202 8.05000906,17 L3.5,17 C3.22385763,17 3,16.7761424 3,16.5 C3,16.2238576 3.22385763,16 3.5,16 L8.05000906,16 C8.28164422,14.8588798 9.29052104,14 10.5,14 C11.709479,14 12.7183558,14.8588798 12.9499909,16 L20.5,16 C20.7761424,16 21,16.2238576 21,16.5 C21,16.7761424 20.7761424,17 20.5,17 L12.9499909,17 Z M18.9499909,12 C18.7183558,13.1411202 17.709479,14 16.5,14 C15.290521,14 14.2816442,13.1411202 14.0500091,12 L3.5,12 C3.22385763,12 3,11.7761424 3,11.5 C3,11.2238576 3.22385763,11 3.5,11 L14.0500091,11 C14.2816442,9.85887984 15.290521,9 16.5,9 C17.709479,9 18.7183558,9.85887984 18.9499909,11 L20.5,11 C20.7761424,11 21,11.2238576 21,11.5 C21,11.7761424 20.7761424,12 20.5,12 L18.9499909,12 Z M9.94999094,7 C9.71835578,8.14112016 8.70947896,9 7.5,9 C6.29052104,9 5.28164422,8.14112016 5.05000906,7 L3.5,7 C3.22385763,7 3,6.77614237 3,6.5 C3,6.22385763 3.22385763,6 3.5,6 L5.05000906,6 C5.28164422,4.85887984 6.29052104,4 7.5,4 C8.70947896,4 9.71835578,4.85887984 9.94999094,6 L20.5,6 C20.7761424,6 21,6.22385763 21,6.5 C21,6.77614237 20.7761424,7 20.5,7 L9.94999094,7 Z M7.5,8 C8.32842712,8 9,7.32842712 9,6.5 C9,5.67157288 8.32842712,5 7.5,5 C6.67157288,5 6,5.67157288 6,6.5 C6,7.32842712 6.67157288,8 7.5,8 Z M16.5,13 C17.3284271,13 18,12.3284271 18,11.5 C18,10.6715729 17.3284271,10 16.5,10 C15.6715729,10 15,10.6715729 15,11.5 C15,12.3284271 15.6715729,13 16.5,13 Z M10.5,18 C11.3284271,18 12,17.3284271 12,16.5 C12,15.6715729 11.3284271,15 10.5,15 C9.67157288,15 9,15.6715729 9,16.5 C9,17.3284271 9.67157288,18 10.5,18 Z"/>
                    </svg>
                </button>
            </div>
            
            <!-- Mobile Menu Dropdown -->
            <div id="mobileMenu" class="hidden md:hidden bg-white border-t border-gray-100">
                <div class="flex flex-col py-3 px-4 space-y-1">
                    <a href="#home" id="nav-mobile-home" onclick="handleNavClick('home'); closeMobileMenu();" class="nav-link-mobile active-mobile text-sm sm:text-base font-semibold text-blue-600 bg-blue-50 py-2.5 px-4 rounded-lg transition-colors">
                        Home
                    </a>
                    <a href="#features" id="nav-mobile-features" onclick="handleNavClick('features'); closeMobileMenu();" class="nav-link-mobile text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 hover:bg-gray-50 py-2.5 px-4 rounded-lg transition-colors">
                        Features
                    </a>
                    <a href="#about" id="nav-mobile-about" onclick="handleNavClick('about'); closeMobileMenu();" class="nav-link-mobile text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 hover:bg-gray-50 py-2.5 px-4 rounded-lg transition-colors">
                        About
                    </a>
                    <a href="#contact" id="nav-mobile-contact" onclick="handleNavClick('contact'); closeMobileMenu();" class="nav-link-mobile text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 hover:bg-gray-50 py-2.5 px-4 rounded-lg transition-colors">
                        Contact
                    </a>
                    <div class="flex flex-col gap-2 border-t border-gray-200 pt-3 mt-2">
                        <button onclick="openLoginModal(); closeMobileMenu();" class="text-sm sm:text-base font-semibold text-gray-700 hover:text-blue-600 py-2.5 px-4 rounded-lg hover:bg-gray-50 transition-colors text-left">
                            Sign in
                        </button>
                        <button onclick="openRegisterModal(); closeMobileMenu();" class="text-sm sm:text-base font-semibold text-white bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2.5 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-colors text-center">
                            Register
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="relative overflow-hidden z-10 pt-28 sm:pt-28 md:pt-34 pb-14 sm:pb-16 md:pb-20 min-h-screen">
        <!-- Unique layered background -->
        <div class="aurora"></div>
        <div class="dot-grid absolute inset-0"></div>
        <div class="orb orb--blue"></div>
        <div class="orb orb--violet"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative mt-8 sm:mt-12 md:mt-16 lg:mt-20">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 lg:gap-14 items-center">
                <!-- Left content -->
                <div class="space-y-4 sm:space-y-5 md:space-y-6 animate-slide-in-left text-center lg:text-left">
                    <div class="badge-pill w-max mx-auto lg:mx-0 text-xs sm:text-sm font-medium mb-5">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" viewBox="0 0 24 24" fill="none">
                            <path d="M5 13l4 4L19 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Barangay-first healthcare
                    </div>

                    <h1 class="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-extrabold leading-tight text-gray-900 mb-4 sm:mb-6">
                        Protecting your Health,
                        <span class="block bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-fuchsia-600">
                            Our Priority
                        </span>
                    </h1>

                    <p class="text-sm sm:text-base md:text-lg text-gray-600 max-w-xl mx-auto lg:mx-0 leading-relaxed mb-6 sm:mb-8">
                    Track and distribute medicines efficiently at the barangay level—connecting residents, BHWs, and admins in one smart system for faster service, accurate inventory, and healthier communities.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 pt-2 justify-center lg:justify-start items-center">
                        <button onclick="openLoginModal()" class="btn btn-lg cta-primary rounded-full px-4 sm:px-5 md:px-6 py-2 sm:py-2.5 md:py-3 text-sm sm:text-base font-semibold shadow-glow hover:scale-105 transition-transform inline-flex items-center justify-center gap-2 group whitespace-nowrap min-w-[140px] sm:min-w-[155px]">
                            <span>Get started</span>
                            <svg class="w-4 h-4 sm:w-4 sm:h-4 group-hover:translate-x-1 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </button>
                        <a href="#features" class="btn btn-lg cta-secondary rounded-full px-4 sm:px-5 md:px-6 py-2 sm:py-2.5 md:py-3 text-sm sm:text-base font-semibold hover:scale-105 transition-transform inline-flex items-center justify-center whitespace-nowrap min-w-[140px] sm:min-w-[155px]">
                            Learn more
                        </a>
                    </div>                    
                </div>

                <!-- Right visual -->
                <div class="relative animate-slide-in-right mt-8 lg:mt-0">
                    <div class="glass-card-xl p-2 sm:p-3 md:p-4 lg:p-5 tilt dancing-card">
                        <div class="relative rounded-2xl overflow-hidden">
                            <div class="absolute -inset-2 sm:-inset-4 md:-inset-6 lg:-inset-8 bg-gradient-to-tr from-blue-400/20 via-indigo-400/20 to-fuchsia-400/20 blur-xl sm:blur-2xl"></div>
                            <svg class="relative rounded-xl shadow-2xl w-full h-auto" viewBox="0 0 600 380" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
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

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-1.5 md:gap-2 mt-2 sm:mt-3">
                            <div class="card p-2 sm:p-3 md:p-4">
                                <div class="text-xs sm:text-sm font-medium text-gray-500">Easy to Use</div>
                                <div class="mt-1 sm:mt-1.5 text-sm sm:text-base md:text-lg font-semibold text-gray-900">Simple Interface</div>
                                </div>
                            <div class="card p-2 sm:p-3 md:p-4">
                                <div class="text-xs sm:text-sm font-medium text-gray-500">Fast & Reliable</div>
                                <div class="mt-1 sm:mt-1.5 text-sm sm:text-base md:text-lg font-semibold text-gray-900">Real-time Updates</div>
                            </div>
                            <div class="card p-2 sm:p-3 md:p-4">
                                <div class="text-xs sm:text-sm font-medium text-gray-500">Secure</div>
                                <div class="mt-1 sm:mt-1.5 text-sm sm:text-base md:text-lg font-semibold text-gray-900">Protected Data</div>
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
        <section id="features" class="py-12 sm:py-16 lg:py-24 bg-white/60 mb-4">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-10 sm:mb-12 lg:mb-16">
                    <div class="flex flex-row items-center justify-center gap-2 sm:gap-3 mb-5 w-full">
                        <div class="h-px w-10 sm:w-16 bg-gradient-to-r from-transparent via-blue-400 to-blue-600 flex-shrink-0"></div>
                        <div class="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-blue-600 rounded-full flex-shrink-0"></div>
                        <div class="w-6 sm:w-8 h-px bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 flex-shrink-0"></div>
                        <div class="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-purple-600 rounded-full flex-shrink-0"></div>
                        <div class="h-px w-10 sm:w-16 bg-gradient-to-l from-transparent via-purple-400 to-purple-600 flex-shrink-0"></div>
                    </div>
                    <h2 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 mb-4 sm:mb-6 scroll-reveal">
                        <span class="hero-title">Core Features</span>
                    </h2>
                    <p class="text-sm sm:text-base md:text-lg text-gray-600 max-w-2xl mx-auto leading-relaxed mb-8 sm:mb-12">
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
                                <h3 class="text-lg sm:text-xl md:text-2xl font-bold text-gray-900 mb-2 sm:mb-3 group-hover:text-blue-600 transition-colors duration-300">Browse & Request</h3>
                                <p class="text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed">Residents can discover medicines and submit requests with proof and patient info.</p>
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
                                <h3 class="text-lg sm:text-xl md:text-2xl font-bold text-gray-900 mb-2 sm:mb-3 group-hover:text-green-600 transition-colors duration-300">BHW Approval</h3>
                                <p class="text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed">BHWs verify and approve requests, managing residents and families by purok.</p>
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
                                <h3 class="text-lg sm:text-xl md:text-2xl font-bold text-gray-900 mb-2 sm:mb-3 group-hover:text-purple-600 transition-colors duration-300">Admin Inventory</h3>
                                <p class="text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed">Super Admins manage medicines, batches, users, and senior allocations with FEFO.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="py-12 sm:py-16 lg:py-20 bg-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="sm:flex items-center gap-8 lg:gap-12">
                    <div class="sm:w-1/2 p-8 sm:p-10 lg:p-12">
                        <div class="image object-center text-center">
                            <img src="uploads/aboutus.png" alt="About MediTrack" class="mx-auto w-full max-w-lg lg:max-w-xl xl:max-w-2xl h-auto object-contain">
                        </div>
                    </div>
                    <div class="sm:w-1/2 p-6 sm:p-8 lg:p-10">
                        <div class="text">
                            <span class="text-gray-500 border-b-2 border-indigo-600 uppercase text-sm sm:text-base font-semibold">About us</span>
                            <h2 class="my-4 font-bold text-3xl sm:text-4xl lg:text-5xl">About <span class="text-indigo-600">MediTrack</span></h2>
                            <p class="text-gray-700 text-base sm:text-lg leading-relaxed text-justify">
                                MediTrack is a comprehensive web-based platform designed specifically for barangay health centers. Our system revolutionizes medicine management by connecting residents, health workers, and administrators in one seamless ecosystem. We streamline every aspect of the medicine request process—from initial submission to inventory tracking and distribution.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact CTA -->
        <section id="contact" class="py-10 sm:py-14 md:py-16 lg:py-20 xl:py-24 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 relative overflow-hidden">
            <!-- Decorative background elements -->
            <div class="absolute inset-0 opacity-20 sm:opacity-30">
                <div class="absolute top-0 right-0 w-48 h-48 sm:w-64 sm:h-64 md:w-80 md:h-80 lg:w-96 lg:h-96 bg-indigo-200 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 sm:w-64 sm:h-64 md:w-80 md:h-80 lg:w-96 lg:h-96 bg-purple-200 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2"></div>
            </div>
            
            <div class="max-w-7xl mx-auto px-4 sm:px-5 md:px-6 lg:px-8 relative z-10">
                <div class="cta-modern-card rounded-2xl sm:rounded-3xl p-5 sm:p-6 md:p-8 lg:p-10 xl:p-12">
                    <div class="flex flex-col md:flex-row items-center justify-between gap-4 sm:gap-5 md:gap-6 lg:gap-8 text-center md:text-left">
                        <div class="flex-1 w-full md:w-auto">
                            <h3 class="cta-title-modern text-lg sm:text-xl md:text-2xl lg:text-3xl xl:text-4xl font-bold mb-2 sm:mb-3 md:mb-4 leading-tight">
                                Need help getting started?
                            </h3>
                            <p class="text-xs sm:text-sm md:text-base lg:text-lg text-gray-600 max-w-2xl mx-auto md:mx-0 leading-relaxed mb-3 sm:mb-4 md:mb-5 lg:mb-6">
                                Visit your barangay health center or sign in below to access the platform.
                            </p>
                        </div>
                        <div class="flex-shrink-0 w-full sm:w-auto flex justify-center items-center md:justify-end">
                            <button onclick="openLoginModal()" class="cta-modern-button text-white font-semibold mx-auto sm:mx-0 w-auto max-w-[120px] sm:max-w-none sm:w-auto whitespace-nowrap px-5 py-1.5 sm:px-7 md:px-8 lg:px-10 xl:px-12 sm:py-3 md:py-3.5 lg:py-4 text-xs sm:text-sm md:text-base lg:text-lg rounded-md sm:rounded-xl shadow-lg">
                                Sign in
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        <!-- Testimonials -->
    

        <!-- FAQ -->
        <section id="faq" class="faq-modern py-16 sm:py-20 lg:py-24">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-12 sm:mb-16">
                    <h2 class="text-3xl sm:text-4xl md:text-5xl font-bold text-gray-900 mb-4 sm:mb-6">
                        Frequently Asked Questions
                    </h2>
                    <p class="text-base sm:text-lg text-gray-600 max-w-xl mx-auto">
                        Find answers to common questions about MediTrack
                    </p>
                </div>
                
                <div class="space-y-4 sm:space-y-5">
                    <details class="faq-item">
                        <summary class="faq-summary px-5 sm:px-6 py-4 sm:py-5">
                            <div class="flex items-center justify-between gap-3 sm:gap-4 ">
                                <span class="faq-question text-sm sm:text-base md:text-lg font-semibold text-left flex-1 truncate">
                                    How do residents request medicines?
                                </span>
                                <svg class="faq-icon w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <hr class="faq-divider">
                        <div class="faq-answer px-5 sm:px-6 pb-5 sm:pb-6 text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed pt-4">
                            Residents sign in, browse medicines, and submit a request with a proof image and patient details. The system ensures all required information is provided before submission.
                        </div>
                    </details>
                    
                    <details class="faq-item">
                        <summary class="faq-summary px-5 sm:px-6 py-4 sm:py-5">
                            <div class="flex items-center justify-between gap-3 sm:gap-4">
                                <span class="faq-question text-sm sm:text-base md:text-lg font-semibold text-left flex-1 truncate">
                                    How are approvals handled?
                                </span>
                                <svg class="faq-icon w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <hr class="faq-divider">
                        <div class="faq-answer px-5 sm:px-6 pb-5 sm:pb-6 text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed pt-4">
                            BHWs review requests and approve or reject them based on availability and validity. Approved requests automatically deduct stock using FEFO (First Expired, First Out) methodology from batches to ensure medicine quality and safety.
                        </div>
                    </details>
                    
                    <details class="faq-item">
                        <summary class="faq-summary px-5 sm:px-6 py-4 sm:py-5">
                            <div class="flex items-center justify-between gap-3 sm:gap-4">
                                <span class="faq-question text-sm sm:text-base md:text-lg font-semibold text-left flex-1 truncate">
                                    Do emails send automatically?
                                </span>
                                <svg class="faq-icon w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <hr class="faq-divider">
                        <div class="faq-answer px-5 sm:px-6 pb-5 sm:pb-6 text-xs sm:text-sm md:text-base text-gray-600 leading-relaxed pt-4">
                            Yes, the system automatically sends emails for request notifications and user events via PHPMailer. All email attempts are logged in the system for review and tracking purposes, ensuring reliable communication.
                        </div>
                    </details>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-gray-200 py-8 sm:py-10 bg-white/90 backdrop-blur-sm relative z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                 <div class="flex flex-row items-center justify-center gap-2 sm:gap-3 mb-5 w-full">
                    <div class="h-px w-10 sm:w-16 bg-gradient-to-r from-transparent via-blue-400 to-blue-600 flex-shrink-0"></div>
                    <div class="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-blue-600 rounded-full flex-shrink-0"></div>
                    <div class="w-6 sm:w-8 h-px bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 flex-shrink-0"></div>
                    <div class="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-purple-600 rounded-full flex-shrink-0"></div>
                    <div class="h-px w-10 sm:w-16 bg-gradient-to-l from-transparent via-purple-400 to-purple-600 flex-shrink-0"></div>
                </div>
                <p class="text-sm sm:text-base text-gray-600 mb-2">© <?php echo date('Y'); ?> <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 font-semibold"><?php echo htmlspecialchars($brand); ?></span>. All rights reserved.</p>
                <p class="text-xs sm:text-sm text-gray-500">Making healthcare accessible for everyone.</p>
                <p class="text-xs sm:text-sm text-gray-600 font-medium mb-2">Developed by:</p>
                <div class="flex flex-row items-center justify-center gap-2 sm:gap-3 text-xs sm:text-sm text-gray-500 flex-wrap">
                    <a href="mailto:lucianocanamocanjr@gmail.com" class="hover:text-blue-600 transition-colors duration-200 whitespace-nowrap">
                        Luciano C. Canamocan Jr.
                    </a>
                    <span class="text-gray-300">•</span>
                    <a href="mailto:vicvictacatane@gmail.com" class="hover:text-blue-600 transition-colors duration-200 whitespace-nowrap">
                        Shevic Tacatane
                    </a>
                </div>
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

    <!-- Modern Registration Modal -->
    <div id="registerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
        <div class="register-modal-container">
            <!-- Enhanced Gradient Header -->
            <div class="register-modal-header">
                <button onclick="closeRegisterModal()" class="register-close-button" aria-label="Close modal">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                
                <div class="register-header-content">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center border border-white/30">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="register-modal-title">Create Your Account</h2>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs font-medium text-white/80 bg-white/10 px-2 py-0.5 rounded-full">Step 1 of 3</span>
                                <span class="text-xs text-white/70">Personal Information</span>
                            </div>
                        </div>
                    </div>
                    <p class="register-modal-subtitle" id="register-modal-subtitle">Join MediTrack and start managing your medicine requests.</p>
                </div>
            </div>
                    
            <!-- Step Indicator -->
            <div class="register-step-indicator">
                <div class="step-indicator-wrapper">
                    <div class="step-item active" data-step="1">
                        <div class="step-number active" id="modal-step-1">1</div>
                        <span class="step-label">Personal Info</span>
                </div>
                    <div class="step-divider" id="divider-1-2"></div>
                    <div class="step-item" data-step="2">
                        <div class="step-number inactive" id="modal-step-2">2</div>
                        <span class="step-label">Family Members</span>
            </div>
                    <div class="step-divider" id="divider-2-3"></div>
                    <div class="step-item" data-step="3">
                        <div class="step-number inactive" id="modal-step-3">3</div>
                        <span class="step-label">Review</span>
                        </div>
                </div>
            </div>
                
            <!-- Form Body -->
            <div class="register-form-body">
                <form id="registerForm" action="" method="post">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="register-validation-message error mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Step 1: Personal Information -->
                    <div id="step-1" class="step-content">
                        <!-- Personal Details Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <div class="form-section-icon">
                                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <span>Personal Details</span>
                            </div>
                        
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <!-- First Name -->
                                <div>
                                <input name="first_name" required 
                                           class="register-input-field" 
                                           placeholder="First Name" />
                            </div>
                            
                            <!-- Middle Initial -->
                                <div>
                                <input name="middle_initial" 
                                           class="register-input-field" 
                                           placeholder="Middle Initial (optional)" 
                                       maxlength="1" 
                                       id="middle_initial_input" />
                            </div>
                            
                            <!-- Last Name -->
                                <div>
                                <input name="last_name" required 
                                           class="register-input-field" 
                                           placeholder="Last Name" />
                            </div>
                        </div>
                        
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Email -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                        </svg>
                                    <input type="email" name="email" id="email-input" required 
                                               class="register-input-field has-icon" 
                                               placeholder="Email Address" />
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                        <div id="email-status-icon" class="hidden">
                                            <svg id="email-success-icon" class="w-5 h-5 text-green-500 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            <svg id="email-error-icon" class="w-5 h-5 text-red-500 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            <svg id="email-loading-icon" class="w-5 h-5 text-gray-400 animate-spin hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                    <!-- Email Validation Status -->
                                    <div id="email-validation-message" class="email-validation-status hidden mt-2">
                                        <div class="flex items-center gap-2">
                                            <span id="email-status-icon-display"></span>
                                            <span id="email-message-text" class="text-sm font-medium"></span>
                                        </div>
                                    </div>
                                    
                                    <!-- MailboxLayer API Badge -->
                                    <div class="mailboxlayer-badge mt-2">
                                        <div class="flex items-center gap-2 text-xs text-gray-600">
                                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>Real-time email verification via MailboxLayer API</span>
                                            <div class="tooltip-wrapper">
                                                <svg class="w-3 h-3 text-gray-400 cursor-help" fill="none" stroke="currentColor" viewBox="0 0 24 24" onmouseover="showMailboxLayerTooltip()" onmouseout="hideMailboxLayerTooltip()">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <div id="mailboxlayer-tooltip" class="tooltip-content">
                                                    MediTrack uses MailboxLayer API to verify if your email is real before proceeding.
                                                </div>
                                            </div>
                                        </div>
                                </div>
                            </div>
                            
                            <!-- Password -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                        </svg>
                                    <input type="password" name="password" id="register-password" required 
                                               class="register-input-field has-icon" 
                                               placeholder="Password" />
                                    <button type="button" onclick="togglePasswordVisibility('register-password', 'register-eye-icon')" 
                                                class="password-toggle-btn">
                                        <svg id="register-eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                            <!-- Confirm Password -->
                            <div class="mb-4">
                                <div class="register-input-wrapper">
                                    <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    <input type="password" name="confirm_password" id="register-confirm-password" required 
                                           class="register-input-field has-icon" 
                                           placeholder="Confirm Password" />
                                    <button type="button" onclick="togglePasswordVisibility('register-confirm-password', 'register-confirm-eye-icon')" 
                                            class="password-toggle-btn">
                                        <svg id="register-confirm-eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Date of Birth -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    <input type="date" name="date_of_birth" required 
                                           id="date_of_birth_input"
                                           max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                               class="register-input-field has-icon" />
                                </div>
                            </div>
                            
                            <!-- Phone -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                        </svg>
                                    <input type="tel" 
                                           name="phone" 
                                           id="phone-input"
                                           pattern="09[0-9]{9}"
                                           maxlength="14"
                                               class="register-input-field has-icon" 
                                               placeholder="09XX XXX XXXX (optional)" />
                                </div>
                                    <p class="register-validation-message info mt-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Format: 09XX XXX XXXX (11 digits) - Optional
                                    </p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Barangay -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                        </svg>
                                    <select name="barangay_id" id="barangay-select" required 
                                                class="register-input-field has-icon appearance-none"
                                                style="padding-right: 3.5rem !important;">
                                        <option value="">Select your Barangay</option>
                                    <?php
                                    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
                                    foreach ($barangays as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                        <div class="register-dropdown-arrow">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Purok -->
                                <div>
                                    <div class="register-input-wrapper">
                                        <svg class="register-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                    <select name="purok_id" id="purok-select" required 
                                                class="register-input-field has-icon appearance-none"
                                                style="padding-right: 3.5rem !important;">
                                        <option value="">Select your Purok</option>
                                    </select>
                                        <div class="register-dropdown-arrow">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 1 Navigation -->
                        <div class="mt-6">
                            <button type="button" id="proceed-to-step2-btn" onclick="validateAndProceed()" class="register-primary-button" disabled>
                            <span>Proceed to Family Members</span>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </button>
                            <a href="#" onclick="event.preventDefault(); closeRegisterModal(); openLoginModal();" class="register-secondary-link">
                                Back to Login
                            </a>
                    </div>
                    </div>
                    
                    <!-- Step 2: Family Members -->
                    <div id="step-2" class="step-content hidden">
                    <!-- Family Members Section -->
                        <div class="family-members-section">
                            <div class="form-section-title">
                                <div class="form-section-icon">
                                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                                </div>
                                <span>Family Members <span class="text-gray-500 font-normal text-sm">(Optional)</span></span>
                            </div>
                        
                        <div id="family-members-container">
                                <div class="family-member-card expanded">
                                    <div class="family-member-header" onclick="toggleFamilyMemberCard(this)">
                                        <div class="family-member-header-left">
                                            <svg class="family-member-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                            <span class="family-member-title">Family Member 1</span>
                                        </div>
                                        <div class="family-member-header-right">
                                            <button type="button" class="remove-family-member-btn" onclick="event.stopPropagation(); removeFamilyMember(this)" aria-label="Remove family member">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="family-member-content">
                                        <!-- Row 1: First Name, Middle Initial, Last Name -->
                                        <div class="family-member-fields">
                                            <div>
                                        <input type="text" name="family_members[0][first_name]" 
                                                       class="register-input-field" 
                                                       placeholder="First Name" />
                                    </div>
                                            <div>
                                        <input type="text" name="family_members[0][middle_initial]" 
                                                       class="register-input-field" 
                                                       placeholder="M.I." 
                                               maxlength="1" />
                                    </div>
                                            <div>
                                        <input type="text" name="family_members[0][last_name]" 
                                                       class="register-input-field" 
                                                       placeholder="Last Name" />
                                            </div>
                                    </div>
                                    
                                        <!-- Row 2: Relationship, Date of Birth -->
                                        <div class="family-member-fields-row2">
                                            <div class="w-full">
                                                <select name="family_members[0][relationship]" 
                                                        class="register-input-field appearance-none pr-10 w-full"
                                                        onchange="handleRelationshipChange(this, 0)">
                                                    <?php echo get_relationship_options(null, true); ?>
                                                </select>
                                                <input type="text" 
                                                       name="family_members[0][relationship_other]" 
                                                       id="relationship_other_0"
                                                       class="register-input-field mt-2 w-full transition-all duration-300 ease-in-out opacity-0 max-h-0 overflow-hidden" 
                                                       placeholder="Specify relationship (e.g., Stepfather, Godmother, etc.)"
                                                       maxlength="50"
                                                       style="display: none;" />
                                            </div>
                                            <div>
                                                <input type="date" name="family_members[0][date_of_birth]" 
                                                       class="register-input-field" 
                                                       placeholder="Date of Birth" />
                                            </div>
                                        </div>
                                </div>
                            </div>
                        </div>
                        
                            <button type="button" id="add-family-member" class="add-member-button">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                                <span>+ Add Family Member</span>
                        </button>
                    </div>
                    
                    <!-- Step 2 Navigation -->
                        <div class="mt-6">
                            <div class="flex justify-between gap-4">
                                <button type="button" onclick="goToStep(1)" class="register-back-button">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                            </svg>
                                    <span>Back</span>
                        </button>
                                <button type="button" onclick="goToStep(3)" class="register-primary-button" style="flex: 1; max-width: 300px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                                    <span>Proceed to Review</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </button>
                            </div>
                    </div>
                    </div>
                    
                    <!-- Step 3: Review -->
                    <div id="step-3" class="step-content hidden">
                    <!-- Review Section -->
                        <div class="review-section">
                            <div class="form-section-title">
                                <div class="form-section-icon">
                                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                                </div>
                            <span>Review Your Information</span>
                            </div>
                        
                        <div class="space-y-4">
                                <!-- Personal Information Card -->
                                <div class="review-card">
                                    <div class="review-card-header">
                                        <h4 class="review-card-title">Personal Information</h4>
                                        <a href="#" onclick="event.preventDefault(); goToStep(1);" class="edit-link">Edit</a>
                                    </div>
                                    <div id="review-personal-info" class="space-y-2">
                                    <!-- Personal info will be populated here -->
                                </div>
                            </div>
                            
                                <!-- Family Members Card -->
                                <div class="review-card">
                                    <div class="review-card-header">
                                        <h4 class="review-card-title">Family Members</h4>
                                        <a href="#" onclick="event.preventDefault(); goToStep(2);" class="edit-link">Edit</a>
                                    </div>
                                    <div id="review-family-members" class="space-y-2">
                                    <!-- Family members will be populated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3 Navigation -->
                        <div class="mt-6">
                            <div class="flex justify-between gap-4">
                                <button type="button" onclick="goToStep(2)" class="register-back-button">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                            </svg>
                                    <span>Back</span>
                        </button>
                                <button type="button" id="submitRegistrationBtn" onclick="handleFormSubmission()" class="register-submit-button" style="flex: 1; max-width: 300px;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span id="submitText">Submit Registration</span>
                        </button>
                            </div>
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
    <div id="verificationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[60] p-4">
        <div class="verification-modal-container">
            <button onclick="closeVerificationModal()" class="register-close-button" style="position: absolute; top: 1.5rem; right: 1.5rem; background: rgba(255, 255, 255, 0.9); border: 1px solid #e5e7eb; color: #6b7280;" aria-label="Close modal">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            
            <div class="p-8">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Verify Your Email</h3>
                    <p class="text-gray-600 text-sm">We sent a 6-digit code to <span id="verification-email-display" class="font-semibold text-gray-900"></span></p>
                </div>

                <div id="verificationError" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm flex items-center gap-2">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span id="verificationErrorText"></span>
                </div>

                <div id="verificationSuccess" class="hidden mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm flex items-center gap-2">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span>Email verified successfully!</span>
                </div>

                <div class="space-y-4">
                    <div>
                        <input type="text" id="verificationCode" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" 
                               class="verification-code-input" 
                               placeholder="000000" 
                               autocomplete="one-time-code" />
                    </div>

                    <div id="resend-timer" class="resend-timer">
                        <span id="timer-text">Resend code in <span id="timer-count">60</span>s</span>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeVerificationModal()" 
                                class="flex-1 px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="button" onclick="requestVerificationCode()" id="resendBtn" 
                                class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed" 
                                disabled>
                            Resend Code
                        </button>
                        <button type="button" onclick="verifyCode()" 
                                class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg font-medium hover:from-blue-700 hover:to-purple-700 transition-all duration-200">
                            Verify
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modern Minimal Login Modal -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
        <div class="login-modal-container relative">
            <!-- Close Button -->
            <button onclick="closeLoginModal()" class="login-close-button" aria-label="Close modal">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                
            <!-- Modal Content -->
            <div class="p-8">
                <!-- Logo -->
                <div class="login-logo-wrapper">
                    <img id="login-logo-img" src="public/assets/brand/logo.png" alt="MediTrack Logo" onerror="this.style.display='none'">
                    <canvas id="login-logo-canvas" class="login-logo-canvas"></canvas>
                    </div>
                    
                <!-- Title and Subtitle -->
                <h2 class="login-modal-title">Welcome Back</h2>
                <p class="login-modal-subtitle">Sign in to access your account</p>
                
                <!-- Login Form -->
                <form id="loginForm" action="public/login.php" method="post">
                    <!-- Error Message -->
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="login-error-message">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Email Input -->
                    <div class="login-input-wrapper">
                        <input 
                            type="email" 
                            name="email" 
                            id="login-email"
                            required 
                            class="login-input-field" 
                            placeholder="Email Address"
                            autocomplete="email"
                        />
                        <svg class="login-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                </svg>
                    </div>
                    
                    <!-- Password Input -->
                    <div class="login-input-wrapper">
                        <input 
                            type="password" 
                            name="password" 
                            id="login-password" 
                            required 
                            class="login-input-field" 
                            placeholder="Password"
                            autocomplete="current-password"
                        />
                        <svg class="login-input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                   </svg>
                        <button 
                            type="button" 
                            onclick="togglePasswordVisibility('login-password', 'login-eye-icon')" 
                            class="login-password-toggle"
                            aria-label="Toggle password visibility"
                        >
                            <svg id="login-eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                   </svg>
                               </button>
                       </div>
                    
                    <!-- Forgot Password Link -->
                    <a href="#" class="login-forgot-link" onclick="event.preventDefault(); alert('Forgot password feature coming soon!'); return false;">Forgot password?</a>
                    
                    <!-- Sign In Button -->
                    <button type="submit" class="login-submit-button" id="login-submit-btn">
                        <span>Sign in</span>
                    </button>
                </form>
                
                <!-- Register Link Section -->
                <div class="login-register-section">
                    <p class="login-register-text">New to MediTrack?</p>
                    <button onclick="closeLoginModal(); openRegisterModal();" class="login-register-link">
                        Register as Resident
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="width: 1rem; height: 1rem;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let familyMemberCount = 1;
        let emailValidationTimeout = null;
        // Initialize email validation state on window object
        window.isEmailValid = false;
        
        // Barangay-Purok relationship data
        let puroksData = <?php echo json_encode(db()->query('SELECT id, name, barangay_id FROM puroks ORDER BY barangay_id, name')->fetchAll()); ?>;
        let barangaysData = <?php echo json_encode(db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll()); ?>;
        
        // Relationships data from database
        const relationshipsData = <?php echo json_encode(get_relationships()); ?>;
        
        // Helper function to generate relationship options HTML
        function generateRelationshipOptions(selectedValue = null) {
            let options = '<option value="">Relationship</option>';
            relationshipsData.forEach(function(rel) {
                const selected = (selectedValue === rel.name) ? ' selected' : '';
                options += '<option value="' + rel.name + '"' + selected + '>' + rel.name + '</option>';
            });
            return options;
        }
        
        // ============================================
        // FORM DATA PERSISTENCE (localStorage)
        // ============================================
        const REGISTER_FORM_STORAGE_KEY = 'registerFormData';
        let formSaveTimeout = null;
        
        // Save form data to localStorage
        function saveRegisterFormData() {
            try {
                const formData = {
                    // Step 1: Personal Information
                    first_name: document.querySelector('input[name="first_name"]')?.value || '',
                    middle_initial: document.querySelector('input[name="middle_initial"]')?.value || '',
                    last_name: document.querySelector('input[name="last_name"]')?.value || '',
                    email: document.querySelector('input[name="email"]')?.value || '',
                    password: document.querySelector('input[name="password"]')?.value || '',
                    confirm_password: document.querySelector('input[name="confirm_password"]')?.value || '',
                    date_of_birth: document.querySelector('input[name="date_of_birth"]')?.value || '',
                    phone: document.querySelector('input[name="phone"]')?.value || '',
                    barangay_id: document.querySelector('select[name="barangay_id"]')?.value || '',
                    purok_id: document.querySelector('select[name="purok_id"]')?.value || '',
                    
                    // Step 2: Family Members
                    family_members: [],
                    
                    // Current step
                    current_step: getCurrentStep() || 1
                };
                
                // Save family members
                const familyMemberInputs = document.querySelectorAll('[name^="family_members["]');
                const familyMembersMap = {};
                
                familyMemberInputs.forEach(input => {
                    const name = input.name;
                    const match = name.match(/family_members\[(\d+)\]\[(\w+)\]/);
                    if (match) {
                        const index = parseInt(match[1]);
                        const field = match[2];
                        
                        if (!familyMembersMap[index]) {
                            familyMembersMap[index] = {};
                        }
                        familyMembersMap[index][field] = input.value || '';
                    }
                });
                
                // Convert map to array
                formData.family_members = Object.keys(familyMembersMap).map(key => familyMembersMap[key]);
                
                // Save to localStorage
                localStorage.setItem(REGISTER_FORM_STORAGE_KEY, JSON.stringify(formData));
            } catch (error) {
                console.error('Error saving form data:', error);
            }
        }
        
        // Restore form data from localStorage
        function restoreRegisterFormData() {
            try {
                const savedData = localStorage.getItem(REGISTER_FORM_STORAGE_KEY);
                if (!savedData) return false;
                
                const formData = JSON.parse(savedData);
                
                // Restore Step 1 fields
                if (formData.first_name) {
                    const field = document.querySelector('input[name="first_name"]');
                    if (field) field.value = formData.first_name;
                }
                if (formData.middle_initial) {
                    const field = document.querySelector('input[name="middle_initial"]');
                    if (field) field.value = formData.middle_initial;
                }
                if (formData.last_name) {
                    const field = document.querySelector('input[name="last_name"]');
                    if (field) field.value = formData.last_name;
                }
                if (formData.email) {
                    const field = document.querySelector('input[name="email"]');
                    if (field) {
                        field.value = formData.email;
                        // Trigger email validation if email exists and function is available
                        if (typeof checkEmailAvailability === 'function') {
                            if (emailValidationTimeout) clearTimeout(emailValidationTimeout);
                            emailValidationTimeout = setTimeout(() => {
                                checkEmailAvailability(formData.email);
                            }, 500);
                        }
                    }
                }
                if (formData.password) {
                    const field = document.querySelector('input[name="password"]');
                    if (field) field.value = formData.password;
                }
                if (formData.confirm_password) {
                    const field = document.querySelector('input[name="confirm_password"]');
                    if (field) field.value = formData.confirm_password;
                }
                if (formData.date_of_birth) {
                    const field = document.querySelector('input[name="date_of_birth"]');
                    if (field) field.value = formData.date_of_birth;
                }
                if (formData.phone) {
                    const field = document.querySelector('input[name="phone"]');
                    if (field) field.value = formData.phone;
                }
                
                // Restore barangay and purok (need to handle purok options first)
                if (formData.barangay_id) {
                    const barangaySelect = document.querySelector('select[name="barangay_id"]');
                    if (barangaySelect) {
                        barangaySelect.value = formData.barangay_id;
                        // Trigger change to populate puroks
                        barangaySelect.dispatchEvent(new Event('change'));
                        
                        // Restore purok after puroks are loaded
                        setTimeout(() => {
                            if (formData.purok_id) {
                                const purokSelect = document.querySelector('select[name="purok_id"]');
                                if (purokSelect) {
                                    purokSelect.value = formData.purok_id;
                                }
                            }
                        }, 100);
                    }
                }
                
                // Restore family members
                if (formData.family_members && formData.family_members.length > 0) {
                    // Restore first family member (index 0)
                    if (formData.family_members[0]) {
                        restoreFamilyMember(0, formData.family_members[0]);
                    }
                    
                    // Add and restore additional family members if any
                    const container = document.getElementById('family-members-container');
                    if (container) {
                        for (let i = 1; i < formData.family_members.length; i++) {
                            // Create new family member card
                            const newMember = document.createElement('div');
                            newMember.className = 'family-member-card expanded';
                            newMember.innerHTML = `
                                <div class="family-member-header" onclick="toggleFamilyMemberCard(this)">
                                    <div class="family-member-header-left">
                                        <svg class="family-member-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                        <span class="family-member-title">Family Member ${i + 1}</span>
                                    </div>
                                    <div class="family-member-header-right">
                                        <button type="button" class="remove-family-member-btn" onclick="event.stopPropagation(); removeFamilyMember(this)" aria-label="Remove family member">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="family-member-content">
                                    <div class="family-member-fields">
                                        <div>
                                            <input type="text" name="family_members[${i}][first_name]" 
                                                   class="register-input-field" 
                                                   placeholder="First Name" />
                                        </div>
                                        <div>
                                            <input type="text" name="family_members[${i}][middle_initial]" 
                                                   class="register-input-field" 
                                                   placeholder="M.I." 
                                                   maxlength="1" />
                                        </div>
                                        <div>
                                            <input type="text" name="family_members[${i}][last_name]" 
                                                   class="register-input-field" 
                                                   placeholder="Last Name" />
                                        </div>
                                    </div>
                                    <div class="family-member-fields-row2">
                                        <div class="w-full">
                                            <select name="family_members[${i}][relationship]" 
                                                    class="register-input-field appearance-none pr-10 w-full"
                                                    onchange="handleRelationshipChange(this, ${i})">
                                                ${generateRelationshipOptions(formData.family_members[i]?.relationship || null)}
                                            </select>
                                            <input type="text" 
                                                   name="family_members[${i}][relationship_other]" 
                                                   id="relationship_other_${i}"
                                                   class="register-input-field mt-2 w-full transition-all duration-300 ease-in-out opacity-0 max-h-0 overflow-hidden" 
                                                   placeholder="Specify relationship (e.g., Stepfather, Godmother, etc.)"
                                                   maxlength="50"
                                                   style="display: none;" />
                                        </div>
                                        <div>
                                            <input type="date" name="family_members[${i}][date_of_birth]" 
                                                   class="register-input-field" 
                                                   placeholder="Date of Birth" />
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            container.appendChild(newMember);
                            
                            // Restore the data (relationship is already set in the template above)
                            restoreFamilyMember(i, formData.family_members[i]);
                        }
                        
                        // Update family member count to the highest index + 1
                        familyMemberCount = formData.family_members.length;
                    }
                }
                
                // Restore current step
                if (formData.current_step && formData.current_step > 1) {
                    setTimeout(() => {
                        goToStep(formData.current_step);
                    }, 200);
                }
                
                return true;
            } catch (error) {
                console.error('Error restoring form data:', error);
                return false;
            }
        }
        
        // Restore a single family member
        function restoreFamilyMember(index, memberData) {
            const inputs = {
                'first_name': document.querySelector(`input[name="family_members[${index}][first_name]"]`),
                'middle_initial': document.querySelector(`input[name="family_members[${index}][middle_initial]"]`),
                'last_name': document.querySelector(`input[name="family_members[${index}][last_name]"]`),
                'relationship': document.querySelector(`select[name="family_members[${index}][relationship]"]`),
                'relationship_other': document.querySelector(`input[name="family_members[${index}][relationship_other]"]`),
                'date_of_birth': document.querySelector(`input[name="family_members[${index}][date_of_birth]"]`)
            };
            
            if (inputs.first_name && memberData.first_name) {
                inputs.first_name.value = memberData.first_name;
            }
            if (inputs.middle_initial && memberData.middle_initial) {
                inputs.middle_initial.value = memberData.middle_initial;
            }
            if (inputs.last_name && memberData.last_name) {
                inputs.last_name.value = memberData.last_name;
            }
            if (inputs.relationship && memberData.relationship) {
                // Set the relationship value
                inputs.relationship.value = memberData.relationship;
                // Handle relationship change for "Other" option (must be called before setting relationship_other)
                if (memberData.relationship === 'Other' && typeof handleRelationshipChange === 'function') {
                    handleRelationshipChange(inputs.relationship, index);
                    // Set relationship_other after a small delay to ensure the field is visible
                    setTimeout(() => {
                        if (inputs.relationship_other && memberData.relationship_other) {
                            inputs.relationship_other.value = memberData.relationship_other;
                        }
                    }, 50);
                } else if (inputs.relationship_other && memberData.relationship_other) {
                    inputs.relationship_other.value = memberData.relationship_other;
                }
            }
            if (inputs.date_of_birth && memberData.date_of_birth) {
                inputs.date_of_birth.value = memberData.date_of_birth;
            }
        }
        
        // Get current step
        function getCurrentStep() {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');
            const step3 = document.getElementById('step-3');
            
            if (step1 && !step1.classList.contains('hidden')) return 1;
            if (step2 && !step2.classList.contains('hidden')) return 2;
            if (step3 && !step3.classList.contains('hidden')) return 3;
            return 1;
        }
        
        // Clear saved form data
        function clearRegisterFormData() {
            try {
                localStorage.removeItem(REGISTER_FORM_STORAGE_KEY);
            } catch (error) {
                console.error('Error clearing form data:', error);
            }
        }
        
        // Debounced save function
        function debouncedSaveFormData() {
            if (formSaveTimeout) {
                clearTimeout(formSaveTimeout);
            }
            formSaveTimeout = setTimeout(() => {
                saveRegisterFormData();
            }, 500); // Save 500ms after user stops typing
        }
        
        // Setup auto-save listeners for form fields
        function setupFormAutoSave() {
            // Step 1 fields
            const step1Fields = [
                'input[name="first_name"]',
                'input[name="middle_initial"]',
                'input[name="last_name"]',
                'input[name="email"]',
                'input[name="password"]',
                'input[name="confirm_password"]',
                'input[name="date_of_birth"]',
                'input[name="phone"]',
                'select[name="barangay_id"]',
                'select[name="purok_id"]'
            ];
            
            step1Fields.forEach(selector => {
                const field = document.querySelector(selector);
                if (field) {
                    field.addEventListener('input', debouncedSaveFormData);
                    field.addEventListener('change', debouncedSaveFormData);
                }
            });
            
            // Family member fields - use event delegation for dynamic fields
            const familyContainer = document.getElementById('family-members-container');
            if (familyContainer) {
                familyContainer.addEventListener('input', (e) => {
                    if (e.target.matches('[name^="family_members["]')) {
                        debouncedSaveFormData();
                    }
                });
                familyContainer.addEventListener('change', (e) => {
                    if (e.target.matches('[name^="family_members["]')) {
                        debouncedSaveFormData();
                    }
                });
            }
        }
        
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
        
        // ============================================
        // COMPREHENSIVE INPUT VALIDATION SYSTEM
        // ============================================
        
        // Global banned characters (excluding @ which is needed for emails)
        const BANNED_CHARS = /[!$%^&*()={}\[\]:;"<>?\/\\|~`]/g;
        const BANNED_CHARS_STR = '!$%^&*()={}[]:;"<>?/\\|~`';
        
        // Banned characters for names (includes _)
        const BANNED_CHARS_NAMES = /[!@#$%^&*()={}\[\]:;"<>?\/\\|~`_]/g;
        
        // Validation patterns
        const VALIDATION_PATTERNS = {
            name: /^[A-Za-zÀ-ÿ' -]+$/,
            address: /^[A-Za-z0-9\s.,'-]+$/,
            email: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,}$/,
            password: /^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/,
            phone: /^[0-9+ ]{7,15}$/
        };
        
        // Validation state tracking
        const validationStates = {};
        
        // Sanitize input by removing banned characters
        function sanitizeInput(value, allowedPattern, isEmail = false) {
            if (!value) return '';
            
            // For emails, use different banned chars (allow @, ., _, %, +, -)
            const bannedChars = isEmail ? /[!$%^&*()={}\[\]:;"<>?\/\\|~`]/g : BANNED_CHARS_NAMES;
            
            // Remove banned characters
            let sanitized = value.replace(bannedChars, '');
            
            // Remove control characters and emojis
            sanitized = sanitized.replace(/[\x00-\x1F\x7F-\x9F]/g, '');
            sanitized = sanitized.replace(/[\u{1F300}-\u{1F9FF}]/gu, '');
            
            // Trim leading/trailing spaces
            sanitized = sanitized.trim();
            
            // Apply pattern if provided
            if (allowedPattern) {
                sanitized = sanitized.split('').filter(char => allowedPattern.test(char)).join('');
            }
            
            return sanitized;
        }
        
        // Validate letters only field (First Name, Last Name, etc.)
        function validateLettersOnly(value, fieldName) {
            const trimmed = value ? value.trim() : '';
            
            // Only show "required" message if field is actually empty
            if (!trimmed) {
                return { valid: false, message: `${fieldName} is required.` };
            }
            
            const sanitized = sanitizeInput(trimmed, VALIDATION_PATTERNS.name);
            
            if (sanitized !== trimmed) {
                return { 
                    valid: false, 
                    message: '❌ Invalid input — special characters not allowed.',
                    sanitized: sanitized
                };
            }
            
            // Check for numbers
            if (/\d/.test(trimmed)) {
                return { 
                    valid: false, 
                    message: 'Only letters, spaces, hyphens, and apostrophes are allowed.' 
                };
            }
            
            if (!VALIDATION_PATTERNS.name.test(trimmed)) {
                return { 
                    valid: false, 
                    message: 'Only letters, spaces, hyphens, and apostrophes are allowed.' 
                };
            }
            
            if (trimmed.length < 2) {
                return { valid: false, message: `${fieldName} must be at least 2 characters.` };
            }
            
            // Field is valid - return success
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Validate numbers only field
        function validateNumbersOnly(value, fieldName, required = true) {
            if (!value || value.trim() === '') {
                if (required) {
                    return { valid: false, message: `${fieldName} is required.` };
                }
                return { valid: true, message: '' }; // Optional
            }
            
            // Remove spaces for validation
            const cleaned = value.replace(/\s/g, '');
            
            if (!/^\d+$/.test(cleaned)) {
                return { 
                    valid: false, 
                    message: '⚠️ This field accepts numbers only.' 
                };
            }
            
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Validate letters and numbers field (Address, Street, etc.)
        function validateLettersAndNumbers(value, fieldName) {
            if (!value || value.trim() === '') {
                return { valid: false, message: `${fieldName} is required.` };
            }
            
            const sanitized = sanitizeInput(value, VALIDATION_PATTERNS.address);
            
            if (sanitized !== value) {
                return { 
                    valid: false, 
                    message: '❌ Invalid input — special characters not allowed.',
                    sanitized: sanitized
                };
            }
            
            if (!VALIDATION_PATTERNS.address.test(value)) {
                return { 
                    valid: false, 
                    message: 'Only letters, numbers, commas, and periods are allowed.' 
                };
            }
            
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Validate name field (alias for letters only)
        function validateName(value, fieldName) {
            return validateLettersOnly(value, fieldName);
        }
        
        // Validate address field
        function validateAddress(value, fieldName) {
            if (!value || value.trim() === '') {
                return { valid: false, message: `${fieldName} is required.` };
            }
            
            const sanitized = sanitizeInput(value, VALIDATION_PATTERNS.address);
            
            if (sanitized !== value) {
                return { 
                    valid: false, 
                    message: '⚠️ Special characters are not allowed in this field.',
                    sanitized: sanitized
                };
            }
            
            if (!VALIDATION_PATTERNS.address.test(value)) {
                return { 
                    valid: false, 
                    message: `${fieldName} can only contain letters, numbers, spaces, commas, periods, and hyphens.` 
                };
            }
            
            return { valid: true, message: '' };
        }
        
        // Validate password
        function validatePassword(value) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Password is required.' };
            }
            
            if (value.length < 8) {
                return { valid: false, message: 'Password must be at least 8 characters.' };
            }
            
            // Check for required components
            const hasLetter = /[A-Za-z]/.test(value);
            const hasNumber = /\d/.test(value);
            const hasSpecial = /[@$!%*?&]/.test(value);
            
            if (!hasLetter) {
                return { valid: false, message: 'Password must contain at least 1 letter.' };
            }
            
            if (!hasNumber) {
                return { valid: false, message: 'Password must contain at least 1 number.' };
            }
            
            if (!hasSpecial) {
                return { valid: false, message: 'Password must contain at least 1 special character (@$!%*?&).' };
            }
            
            // Check for banned characters (excluding allowed special chars)
            const bannedInPassword = /[#^&*()={}\[\]:;"<>?\/\\|~`_]/;
            if (bannedInPassword.test(value)) {
                return { valid: false, message: 'Password contains invalid characters. Only @$!%*?& are allowed as special characters.' };
            }
            
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Validate phone number
        function validatePhone(value, required = false) {
            if (!value || value.trim() === '') {
                if (required) {
                    return { valid: false, message: 'Phone number is required.' };
                }
                return { valid: true, message: '' }; // Optional field
            }
            
            // Auto-remove extra spaces
            let cleaned = value.replace(/\s+/g, ' ').trim();
            
            if (!VALIDATION_PATTERNS.phone.test(cleaned)) {
                return { 
                    valid: false, 
                    message: '⚠️ Phone number can only contain digits, spaces, and + sign.' 
                };
            }
            
            // Remove spaces for length check
            const digitsOnly = cleaned.replace(/\s/g, '');
            
            if (digitsOnly.length < 7 || digitsOnly.length > 15) {
                return { valid: false, message: 'Phone number must be between 7 and 15 digits.' };
            }
            
            // Update field value with cleaned version
            if (cleaned !== value) {
                return { 
                    valid: true, 
                    message: '✅ Looks good!',
                    sanitized: cleaned
                };
            }
            
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Age verification function (exact formula as specified)
        function isAdult(dob) {
            if (!dob) return false;
            
            const today = new Date();
            // HTML5 date inputs always return YYYY-MM-DD format
            // Create date object directly - this handles YYYY-MM-DD correctly
            const birthDate = new Date(dob + 'T00:00:00'); // Add time to avoid timezone issues
            
            if (isNaN(birthDate.getTime())) return false;
            
            let age = today.getFullYear() - birthDate.getFullYear();
            const m = today.getMonth() - birthDate.getMonth();
            
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            return age >= 18;
        }
        
        // Validate date of birth with age verification
        function validateDateOfBirth(value, requireAdult = true) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Date of birth is required.' };
            }
            
            // HTML5 date inputs return YYYY-MM-DD format
            // Create date object with time to avoid timezone issues
            const date = new Date(value + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Reset time to midnight for accurate comparison
            
            if (isNaN(date.getTime())) {
                return { valid: false, message: 'Please enter a valid date.' };
            }
            
            if (date > today) {
                return { valid: false, message: 'Date of birth cannot be in the future.' };
            }
            
            // Age verification for main account holder - MUST check this first
            if (requireAdult) {
                const isUserAdult = isAdult(value);
                if (!isUserAdult) {
                    return { 
                        valid: false, 
                        message: 'You must be 18 years or older to create a MediTrack account.' 
                    };
                }
            }
            
            // Check for unreasonable age (over 120)
            const age = today.getFullYear() - date.getFullYear();
            const monthDiff = today.getMonth() - date.getMonth();
            const dayDiff = today.getDate() - date.getDate();
            const actualAge = (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) ? age - 1 : age;
            
            if (actualAge > 120) {
                return { valid: false, message: 'Please enter a valid date of birth.' };
            }
            
            return { valid: true, message: '✅ Looks good!' };
        }
        
        // Show field validation feedback with enhanced UX
        function showFieldValidation(field, isValid, message) {
            if (!field) return;
            
            const wrapper = field.closest('.register-input-wrapper') || field.parentElement;
            if (!wrapper) return;
            
            // Get current field value FIRST - use the actual current value from the DOM
            const fieldValue = field.value ? field.value.trim() : '';
            const isEmpty = fieldValue === '';
            
            // AGGRESSIVELY clear ALL validation messages from this field's wrapper
            // Remove ALL validation message containers
            const allContainers = wrapper.querySelectorAll('.validation-message-container');
            allContainers.forEach(container => container.remove());
            
            // Remove any standalone validation messages
            const allMessages = wrapper.querySelectorAll('.field-validation-message');
            allMessages.forEach(msg => msg.remove());
            
            // Create a SINGLE new validation message container
            const messageContainer = document.createElement('div');
            messageContainer.className = 'validation-message-container';
            wrapper.appendChild(messageContainer);
            
            // Update field border with smooth transition
            field.classList.remove('border-red-300', 'border-green-300', 'border-yellow-300', 'border-gray-300');
            
            if (isValid === true) {
                field.classList.add('border-green-300');
            } else if (isValid === false) {
                field.classList.add('border-red-300');
            } else {
                field.classList.add('border-gray-300');
            }
            
            // Only show message if provided
            if (message) {
                // CRITICAL: Don't show "is required" message if field has a value
                // This prevents the duplicate "required" message when field is filled
                if (!isEmpty && message.includes('is required')) {
                    // Field has value, don't show "required" message at all
                    // Instead, if field has value, validate it properly
                    return;
                }
                
                // Don't show empty messages
                if (!message.trim()) {
                    return;
                }
                
                const messageDiv = document.createElement('div');
                const isSuccess = isValid === true;
                const isWarning = message.includes('⚠️');
                const isError = isValid === false;
                
                let messageClass = 'text-gray-600';
                let icon = '';
                
                if (isSuccess) {
                    messageClass = 'text-green-600';
                    icon = `
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    `;
                } else if (isWarning) {
                    messageClass = 'text-yellow-600';
                    icon = `
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    `;
                } else if (isError) {
                    messageClass = 'text-red-600';
                    icon = `
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    `;
                }
                
                messageDiv.className = `field-validation-message ${messageClass} text-xs flex items-start gap-1.5`;
                messageDiv.innerHTML = `${icon}<span class="flex-1 leading-tight">${message}</span>`;
                messageContainer.appendChild(messageDiv);
            }
        }
        
        // Setup real-time validation for a field with enhanced sanitization
        function setupFieldValidation(field, validator, fieldName, options = {}) {
            const { 
                autoSanitize = true, 
                fieldType = 'text', // 'letters', 'numbers', 'alphanumeric', 'email', 'phone'
                requireAdult = false 
            } = options;
            
            // Initial validation - only if field has a value
            let initialValue = field.value ? field.value.trim() : '';
            if (fieldType === 'phone' && initialValue) {
                initialValue = initialValue.replace(/\s+/g, ' ').trim();
                field.value = initialValue;
            }
            
            // Only validate on initial load if field has value (don't show "required" on empty fields initially)
            if (initialValue) {
                const result = validator(initialValue, fieldName, requireAdult);
                // Don't show "required" message if field has value
                if (!(result.message && result.message.includes('is required'))) {
                    validationStates[field.name] = result.valid;
                    showFieldValidation(field, result.valid, result.message);
                    
                    // Apply sanitized value if provided
                    if (result.sanitized && result.sanitized !== initialValue) {
                        field.value = result.sanitized;
                    }
                } else {
                    // Field has value but validator says required - validate properly
                    validationStates[field.name] = true;
                    showFieldValidation(field, true, '✅ Looks good!');
                }
            } else {
                // Field is empty, set as invalid but don't show message yet (wait for user interaction)
                validationStates[field.name] = false;
            }
            
            // Real-time validation on input
            field.addEventListener('input', function(e) {
                let value = this.value;
                
                // Auto-sanitize based on field type
                if (autoSanitize) {
                    let sanitized = value;
                    
                    if (fieldType === 'letters') {
                        sanitized = sanitizeInput(value, VALIDATION_PATTERNS.name);
                        // Special handling for middle initial - limit to 1 character
                        if (field.name && field.name.includes('middle_initial')) {
                            sanitized = sanitized.slice(0, 1).toUpperCase();
                        }
                    } else if (fieldType === 'numbers') {
                        sanitized = value.replace(/[^\d]/g, '');
                    } else if (fieldType === 'alphanumeric') {
                        sanitized = sanitizeInput(value, VALIDATION_PATTERNS.address);
                    } else if (fieldType === 'phone') {
                        // Allow digits, spaces, and +
                        sanitized = value.replace(/[^0-9+ ]/g, '');
                        // Remove extra spaces
                        sanitized = sanitized.replace(/\s+/g, ' ');
                    }
                    
                    if (sanitized !== value) {
                        const cursorPos = this.selectionStart;
                        this.value = sanitized;
                        // Try to maintain cursor position
                        const newPos = Math.max(0, Math.min(cursorPos - (value.length - sanitized.length), sanitized.length));
                        this.setSelectionRange(newPos, newPos);
                        value = sanitized;
                    }
                }
                
                // Get the ACTUAL current value from the field (critical for preventing stale validation)
                const actualValue = this.value.trim();
                const valueToValidate = actualValue || value;
                
                // Validate with current actual value
                const result = validator(valueToValidate, fieldName, requireAdult);
                
                // CRITICAL FIX: If field has value but validator incorrectly says "required", treat as valid
                if (actualValue && result.message && result.message.includes('is required')) {
                    // Field clearly has value, so it's valid - show success
                    validationStates[field.name] = true;
                    showFieldValidation(field, true, '✅ Looks good!');
                } else {
                    validationStates[field.name] = result.valid;
                    
                    // Apply sanitized value if provided
                    if (result.sanitized && result.sanitized !== actualValue) {
                        this.value = result.sanitized;
                        // Re-validate with sanitized value
                        const sanitizedResult = validator(result.sanitized, fieldName, requireAdult);
                        validationStates[field.name] = sanitizedResult.valid;
                        showFieldValidation(field, sanitizedResult.valid, sanitizedResult.message);
                    } else {
                        showFieldValidation(field, result.valid, result.message);
                    }
                }
                
                checkFormValidation();
            });
            
            // Validate on blur with auto-trim
            field.addEventListener('blur', function() {
                let value = this.value.trim();
                this.value = value; // Auto-trim
                
                // Apply additional sanitization for phone
                if (fieldType === 'phone' && value) {
                    value = value.replace(/\s+/g, ' ').trim();
                    this.value = value;
                }
                
                // Apply additional sanitization for middle initial - limit to 1 character
                if (field.name && field.name.includes('middle_initial') && value.length > 1) {
                    value = value.slice(0, 1).toUpperCase();
                    this.value = value;
                }
                
                // Get the ACTUAL current value from the field (critical for preventing stale validation)
                const actualValue = this.value.trim();
                
                // Validate with current actual value
                const result = validator(actualValue, fieldName, requireAdult);
                
                // CRITICAL FIX: If field has value but validator incorrectly says "required", treat as valid
                if (actualValue && result.message && result.message.includes('is required')) {
                    // Field clearly has value, so it's valid - show success instead of "required"
                    validationStates[field.name] = true;
                    showFieldValidation(field, true, '✅ Looks good!');
                } else {
                    validationStates[field.name] = result.valid;
                    
                    if (result.sanitized && result.sanitized !== actualValue) {
                        this.value = result.sanitized;
                        // Re-validate with sanitized value
                        const sanitizedResult = validator(result.sanitized, fieldName, requireAdult);
                        validationStates[field.name] = sanitizedResult.valid;
                        showFieldValidation(field, sanitizedResult.valid, sanitizedResult.message);
                    } else {
                        showFieldValidation(field, result.valid, result.message);
                    }
                }
                
                checkFormValidation();
            });
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
        
        function showEmailStatus(status, message) {
            const validationMessage = document.getElementById('email-validation-message');
            const messageText = document.getElementById('email-message-text');
            const statusIconDisplay = document.getElementById('email-status-icon-display');
            const emailInput = document.getElementById('email-input');
            
            if (!validationMessage || !messageText || !statusIconDisplay) return;
            
            // Remove all status classes
            validationMessage.classList.remove('success', 'error', 'warning', 'loading', 'hidden');
            
            // Set status icon and message
            if (status === 'loading') {
                validationMessage.classList.add('loading');
                statusIconDisplay.innerHTML = `
                    <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                `;
                messageText.textContent = message || 'Verifying email...';
            } else if (status === 'success') {
                validationMessage.classList.add('success');
                statusIconDisplay.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                `;
                messageText.textContent = message || 'Email looks good and is deliverable.';
                if (emailInput) {
                    emailInput.classList.remove('border-red-300', 'border-yellow-300');
                emailInput.classList.add('border-green-300');
                }
            } else if (status === 'error') {
                validationMessage.classList.add('error');
                statusIconDisplay.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                `;
                messageText.textContent = message || 'Invalid or unreachable email.';
                if (emailInput) {
                    emailInput.classList.remove('border-green-300', 'border-yellow-300');
                emailInput.classList.add('border-red-300');
                }
            } else if (status === 'warning') {
                validationMessage.classList.add('warning');
                statusIconDisplay.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                `;
                messageText.textContent = message || 'Email already registered.';
                if (emailInput) {
                    emailInput.classList.remove('border-green-300', 'border-red-300');
                    emailInput.classList.add('border-yellow-300');
                }
            } else {
                validationMessage.classList.add('hidden');
                if (emailInput) {
                    emailInput.classList.remove('border-green-300', 'border-red-300', 'border-yellow-300');
                }
                checkFormValidation();
                return;
            }
            
                validationMessage.classList.remove('hidden');
            checkFormValidation();
        }
        
        // Tooltip functions
        function showMailboxLayerTooltip() {
            const tooltip = document.getElementById('mailboxlayer-tooltip');
            if (tooltip) {
                tooltip.style.opacity = '1';
            }
        }
        
        function hideMailboxLayerTooltip() {
            const tooltip = document.getElementById('mailboxlayer-tooltip');
            if (tooltip) {
                tooltip.style.opacity = '0';
            }
        }
        
        // Check form validation and enable/disable proceed button
        function checkFormValidation() {
            const proceedBtn = document.getElementById('proceed-to-step2-btn');
            if (!proceedBtn) return;
            
            // Get field values - try both name and id selectors for email
            const firstName = document.querySelector('input[name="first_name"]')?.value.trim();
            const lastName = document.querySelector('input[name="last_name"]')?.value.trim();
            const email = document.querySelector('input[name="email"]')?.value.trim() || 
                         document.querySelector('#email-input')?.value.trim();
            const password = document.querySelector('input[name="password"]')?.value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]')?.value;
            const dateOfBirth = document.querySelector('input[name="date_of_birth"]')?.value;
            const barangayId = document.querySelector('select[name="barangay_id"]')?.value;
            const purokId = document.querySelector('select[name="purok_id"]')?.value;
            
            // Check all validation states
            const firstNameValid = validationStates['first_name'] === true;
            const lastNameValid = validationStates['last_name'] === true;
            // Email validation: check if it's explicitly true (not just undefined/null)
            const emailValid = window.isEmailValid === true;
            const passwordValid = validationStates['password'] === true;
            const confirmPasswordValid = validationStates['confirm_password'] === true;
            const dateOfBirthValid = validationStates['date_of_birth'] === true;
            
            // Check if all required fields are filled
            const allFieldsFilled = firstName && lastName && email && password && confirmPassword && 
                                    dateOfBirth && barangayId && purokId;
            
            // Check if passwords match
            const passwordsMatch = password && confirmPassword && password === confirmPassword;
            
            // Enable button only if all conditions are met
            const canProceed = allFieldsFilled && firstNameValid && lastNameValid && emailValid && 
                passwordValid && confirmPasswordValid && dateOfBirthValid && passwordsMatch;
            
            if (canProceed) {
                proceedBtn.disabled = false;
                // Clear any previous validation logs when form is valid
                if (window.lastValidationLog) {
                    window.lastValidationLog = null;
                }
            } else {
                proceedBtn.disabled = true;
                // Only log once per state change to reduce console spam
                // Skip logging if user is still typing (fields are empty)
                const currentState = {
                    allFieldsFilled, firstNameValid, lastNameValid, emailValid,
                    passwordValid, confirmPasswordValid, dateOfBirthValid, passwordsMatch
                };
                
                if (!window.lastValidationLog || 
                    JSON.stringify(window.lastValidationLog) !== JSON.stringify(currentState)) {
                    
                    window.lastValidationLog = currentState;
                    
                    // Only log if at least some fields are filled (user is actively filling form)
                    if (firstName || lastName || email || password) {
                        // Detailed logging - but only when user has started filling the form
                        if (!allFieldsFilled && (firstName || lastName || email)) {
                            // Only log missing fields if user has started filling form
                            const missingFields = [];
                            if (!firstName) missingFields.push('firstName');
                            if (!lastName) missingFields.push('lastName');
                            if (!email) missingFields.push('email');
                            if (!password) missingFields.push('password');
                            if (!confirmPassword) missingFields.push('confirmPassword');
                            if (!dateOfBirth) missingFields.push('dateOfBirth');
                            if (!barangayId) missingFields.push('barangayId');
                            if (!purokId) missingFields.push('purokId');
                            
                            if (missingFields.length > 0) {
                                console.log('⚠️ Still missing:', missingFields.join(', '));
                            }
                        }
                        
                        // Only log validation failures if fields are filled but invalid
                        if (allFieldsFilled && (!firstNameValid || !lastNameValid || !passwordValid || 
                            !confirmPasswordValid || !dateOfBirthValid)) {
                            const failedValidations = [];
                            if (!firstNameValid) failedValidations.push('firstName');
                            if (!lastNameValid) failedValidations.push('lastName');
                            if (!passwordValid) failedValidations.push('password');
                            if (!confirmPasswordValid) failedValidations.push('confirmPassword');
                            if (!dateOfBirthValid) failedValidations.push('dateOfBirth');
                            
                            console.log('❌ Validation failed for:', failedValidations.join(', '));
                        }
                        
                        // Only log email validation failure if email is filled
                        if (email && !emailValid) {
                            console.log('⏳ Email validation in progress or failed. Status:', {
                                isEmailValid: window.isEmailValid,
                                hasEmail: !!email,
                                emailStatus: document.querySelector('#email-validation-message')?.classList.toString()
                            });
                        }
                        
                        if (!passwordsMatch && password && confirmPassword) {
                            console.log('❌ Passwords do not match');
                        }
                    }
                }
            }
        }
        
        async function checkEmailAvailability(email) {
            // First check basic format
            if (!validateEmail(email)) {
                // Provide more specific error messages
                if (!email || email.trim() === '') {
                    showEmailStatus(null);
                    window.isEmailValid = false;
                    return false;
                } else {
                    showEmailStatus('error', 'Invalid or unreachable email.');
                }
                window.isEmailValid = false;
                checkFormValidation(); // Update button state
                return false;
            }
            
            showEmailStatus('loading', 'Verifying email address exists and can receive emails...');
            
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
                    // Check if email is already registered
                    const checkRegisteredResponse = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=check_duplicate&type=personal_info&email=${encodeURIComponent(email)}`
                    });
                    const checkRegisteredData = await checkRegisteredResponse.json();
                    
                    if (checkRegisteredData.duplicate) {
                        showEmailStatus('warning', 'Email already registered.');
                        window.isEmailValid = false;
                        checkFormValidation(); // Update button state
                        return false;
                    }
                    
                    showEmailStatus('success', 'Email looks good and is deliverable.');
                    window.isEmailValid = true;
                    checkFormValidation(); // Update button state
                    return true;
                } else {
                    showEmailStatus('error', result.message || 'Invalid or unreachable email.');
                    window.isEmailValid = false;
                    checkFormValidation(); // Update button state
                    return false;
                }
            } catch (error) {
                console.error('Email validation error:', error);
                showEmailStatus('error', 'Unable to validate email. Please check your connection and try again.');
                window.isEmailValid = false;
                checkFormValidation(); // Update button state
                return false;
            }
        }
        
        function openRegisterModal() {
            const modal = document.getElementById('registerModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            
            // Reset email verification flag
            window.emailVerified = false;
            // Reset email validation state
            window.isEmailValid = false;
            
            // Initialize barangay-purok relationship first (needed for restore)
            initializeBarangayPurok();
            
            // Restore saved form data if available
            setTimeout(() => {
                const hasRestoredData = restoreRegisterFormData();
                
                // If no restored data, reset to step 1
                if (!hasRestoredData) {
                    document.querySelectorAll('.step-content').forEach(content => {
                        content.classList.add('hidden');
                    });
                    document.getElementById('step-1').classList.remove('hidden');
                    updateProgressIndicator(1);
                }
                
                // Setup auto-save listeners after a short delay to ensure DOM is ready
                setTimeout(() => {
                    setupFormAutoSave();
                }, 100);
            }, 50);
            
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
                        showEmailStatus(null);
                        window.isEmailValid = false;
                        checkFormValidation();
                        return;
                    }
                    
                    // Debounce the validation (wait 500ms after user stops typing)
                    emailValidationTimeout = setTimeout(() => {
                        checkEmailAvailability(email);
                    }, 500);
                });
            }
            
            // Initialize validation message containers for all input fields to prevent layout shift
            setTimeout(() => {
                document.querySelectorAll('.register-input-wrapper').forEach(wrapper => {
                    if (!wrapper.querySelector('.validation-message-container')) {
                        const container = document.createElement('div');
                        container.className = 'validation-message-container';
                        wrapper.appendChild(container);
                    }
                });
                // Also add containers for fields without wrappers
                document.querySelectorAll('input.register-input-field, select.register-input-field').forEach(field => {
                    if (!field.closest('.register-input-wrapper')) {
                        let wrapper = field.parentElement;
                        // Create wrapper if it doesn't exist
                        if (!wrapper || !wrapper.classList.contains('register-input-wrapper')) {
                            wrapper = document.createElement('div');
                            wrapper.className = 'register-input-wrapper';
                            field.parentNode.insertBefore(wrapper, field);
                            wrapper.appendChild(field);
                        }
                        if (!wrapper.querySelector('.validation-message-container')) {
                            const container = document.createElement('div');
                            container.className = 'validation-message-container';
                            wrapper.appendChild(container);
                        }
                    }
                });
            }, 50);
            
            // Setup validation for all form fields
            const firstNameField = document.querySelector('input[name="first_name"]');
            const lastNameField = document.querySelector('input[name="last_name"]');
            const middleInitialField = document.querySelector('input[name="middle_initial"]');
            const passwordField = document.querySelector('input[name="password"]');
            const confirmPasswordField = document.querySelector('input[name="confirm_password"]');
            const dateOfBirthField = document.querySelector('input[name="date_of_birth"]');
            const phoneField = document.querySelector('input[name="phone"]');
            
            if (firstNameField) {
                setupFieldValidation(firstNameField, validateLettersOnly, 'First name', { 
                    fieldType: 'letters' 
                });
            }
            
            if (lastNameField) {
                setupFieldValidation(lastNameField, validateLettersOnly, 'Last name', { 
                    fieldType: 'letters' 
                });
            }
            
            if (middleInitialField) {
                setupFieldValidation(middleInitialField, (value) => {
                    if (!value || value.trim() === '') {
                        return { valid: true, message: '' }; // Optional
                    }
                    
                    // Check length first
                    const trimmed = value.trim();
                    if (trimmed.length > 1) {
                        return { valid: false, message: 'Middle initial must be exactly 1 character.' };
                    }
                    
                    // Validate it's a letter only (no numbers, no special chars except apostrophe)
                    if (!/^[A-Za-zÀ-ÿ]$/.test(trimmed)) {
                        return { 
                            valid: false, 
                            message: 'Middle initial must be a single letter.' 
                        };
                    }
                    
                    return { valid: true, message: '✅ Looks good!' };
                }, 'Middle initial', { 
                    fieldType: 'letters' 
                });
            }
            
            if (passwordField) {
                setupFieldValidation(passwordField, validatePassword, 'Password', { 
                    autoSanitize: false // Don't auto-sanitize passwords
                });
                
                // Also check password match
                passwordField.addEventListener('input', function() {
                    const confirmField = document.querySelector('input[name="confirm_password"]');
                    if (confirmField && confirmField.value) {
                        validatePasswordMatch();
                    }
                });
            }
            
            if (confirmPasswordField) {
                confirmPasswordField.addEventListener('input', validatePasswordMatch);
                confirmPasswordField.addEventListener('blur', validatePasswordMatch);
            }
            
            if (dateOfBirthField) {
                // Add change event listener for date input (fires when date is selected via date picker)
                dateOfBirthField.addEventListener('change', function() {
                    const result = validateDateOfBirth(this.value, true);
                    validationStates['date_of_birth'] = result.valid;
                    showFieldValidation(this, result.valid, result.message);
                    checkFormValidation();
                });
                
                // Also validate on input (for manual typing)
                dateOfBirthField.addEventListener('input', function() {
                    if (this.value) {
                        const result = validateDateOfBirth(this.value, true);
                        validationStates['date_of_birth'] = result.valid;
                        showFieldValidation(this, result.valid, result.message);
                        checkFormValidation();
                    }
                });
                
                setupFieldValidation(dateOfBirthField, (value) => validateDateOfBirth(value, true), 'Date of birth', {
                    requireAdult: true
                });
            }
            
            if (phoneField) {
                setupFieldValidation(phoneField, (value) => validatePhone(value, false), 'Phone number', {
                    fieldType: 'phone'
                });
            }
            
            // Validate dropdowns
            const barangaySelect = document.querySelector('select[name="barangay_id"]');
            const purokSelect = document.querySelector('select[name="purok_id"]');
            
            if (barangaySelect) {
                barangaySelect.addEventListener('change', checkFormValidation);
            }
            
            if (purokSelect) {
                purokSelect.addEventListener('change', checkFormValidation);
            }
            
            // Password match validation
            function validatePasswordMatch() {
                const password = passwordField?.value;
                const confirmPassword = confirmPasswordField?.value;
                
                if (!confirmPassword) {
                    validationStates['confirm_password'] = undefined;
                    showFieldValidation(confirmPasswordField, undefined, '');
                    return;
                }
                
                if (password !== confirmPassword) {
                    validationStates['confirm_password'] = false;
                    showFieldValidation(confirmPasswordField, false, 'Passwords do not match.');
                } else if (password && password.length >= 8) {
                    validationStates['confirm_password'] = true;
                    showFieldValidation(confirmPasswordField, true, '✅ Looks good!');
                } else {
                    validationStates['confirm_password'] = undefined;
                    showFieldValidation(confirmPasswordField, undefined, '');
                }
                
                checkFormValidation();
            }
            
            // Initial validation check
            checkFormValidation();
        }
        
        async function goToStep(step) {
            // If trying to go to step 2, show verification modal instead (unless coming from verification)
            if (step === 2 && !window.emailVerified) {
                // Check if email is verified in session or allow if coming from verification
                const isValid = await validateStep1();
                if (!isValid) {
                    return;
                }
                // Show verification modal instead of going directly to step 2
                showVerificationModal();
                return;
            }
            
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
            
            // Save current step and form data
            saveRegisterFormData();
            
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
            if (!window.isEmailValid) {
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
            const password = document.querySelector('input[name="password"]').value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
            const dateOfBirth = document.querySelector('input[name="date_of_birth"]').value;
            const barangayId = document.querySelector('select[name="barangay_id"]')?.value;
            const purokId = document.querySelector('select[name="purok_id"]')?.value;
            
            if (!firstName || !lastName || !email || !password || !dateOfBirth || !barangayId || !purokId) {
                showToast('Please fill in all required fields.', 'error');
                return false;
            }
            
            // Validate password match
            if (password !== confirmPassword) {
                showToast('Passwords do not match. Please check and try again.', 'error');
                return false;
            }
            
            // Validate password strength (minimum 8 characters)
            if (password.length < 8) {
                showToast('Password must be at least 8 characters long.', 'error');
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
            const familyMembers = document.querySelectorAll('.family-member-card');
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
            const phone = document.querySelector('input[name="phone"]').value || 'Not provided';
            const dob = document.querySelector('input[name="date_of_birth"]').value;
            const barangaySelect = document.querySelector('select[name="barangay_id"]');
            const barangay = barangaySelect ? barangaySelect.options[barangaySelect.selectedIndex].text : '';
            const purokSelect = document.querySelector('select[name="purok_id"]');
            const purok = purokSelect ? purokSelect.options[purokSelect.selectedIndex].text : '';
            
            let fullName = firstName;
            if (middleInitial) fullName += ' ' + middleInitial + '.';
            if (lastName) fullName += ' ' + lastName;
            
            personalInfo.innerHTML = `
                <div class="review-info-item">
                    <span class="review-info-label">Full Name</span>
                    <span class="review-info-value">${fullName || 'Not provided'}</span>
                </div>
                <div class="review-info-item">
                    <span class="review-info-label">Email</span>
                    <span class="review-info-value">${email || 'Not provided'}</span>
                </div>
                <div class="review-info-item">
                    <span class="review-info-label">Phone</span>
                    <span class="review-info-value">${phone}</span>
                </div>
                <div class="review-info-item">
                    <span class="review-info-label">Date of Birth</span>
                    <span class="review-info-value">${dob || 'Not provided'}</span>
                </div>
                <div class="review-info-item">
                    <span class="review-info-label">Barangay</span>
                    <span class="review-info-value">${barangay || 'Not provided'}</span>
                </div>
                <div class="review-info-item">
                    <span class="review-info-label">Purok</span>
                    <span class="review-info-value">${purok || 'Not provided'}</span>
                </div>
            `;
            
            // Populate family members
            const familyMembers = document.getElementById('review-family-members');
            const familyMemberElements = document.querySelectorAll('.family-member-card');
            let familyHTML = '';
            
            if (familyMemberElements.length === 0) {
                familyHTML = '<p class="text-gray-500 text-sm">No family members added.</p>';
            } else {
                familyMemberElements.forEach((member, index) => {
                    const firstName = member.querySelector('input[name*="[first_name]"]')?.value || '';
                    const middleInitial = member.querySelector('input[name*="[middle_initial]"]')?.value || '';
                    const lastName = member.querySelector('input[name*="[last_name]"]')?.value || '';
                    const relationship = member.querySelector('select[name*="[relationship]"]')?.value || '';
                    const dob = member.querySelector('input[name*="[date_of_birth]"]')?.value || '';
                    
                    let fullName = firstName;
                    if (middleInitial) fullName += ' ' + middleInitial + '.';
                    if (lastName) fullName += ' ' + lastName;
                    
                    if (firstName || lastName || relationship) {
                        familyHTML += `
                            <div class="review-info-item">
                                <span class="review-info-label">Name</span>
                                <span class="review-info-value">${fullName || 'Not provided'}</span>
                                </div>
                            <div class="review-info-item">
                                <span class="review-info-label">Relationship</span>
                                <span class="review-info-value">${relationship || 'Not provided'}</span>
                            </div>
                            <div class="review-info-item">
                                <span class="review-info-label">Date of Birth</span>
                                <span class="review-info-value">${dob || 'Not provided'}</span>
                            </div>
                            ${index < familyMemberElements.length - 1 ? '<div style="margin: 1rem 0; border-top: 1px solid #e5e7eb;"></div>' : ''}
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
                    // Clear saved form data on successful submission
                    clearRegisterFormData();
                    
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
            const dateOfBirthInput = memberElement.querySelector('input[name*="[date_of_birth]"]');
            
            // Setup validation for first name
            if (firstNameInput) {
                setupFieldValidation(firstNameInput, validateLettersOnly, 'First name', { 
                    fieldType: 'letters' 
                });
                firstNameInput.addEventListener('blur', function() {
                            checkFamilyMemberDuplicate(memberElement);
                        });
                    }
            
            // Setup validation for last name
            if (lastNameInput) {
                setupFieldValidation(lastNameInput, validateLettersOnly, 'Last name', { 
                    fieldType: 'letters' 
                });
                lastNameInput.addEventListener('blur', function() {
                    checkFamilyMemberDuplicate(memberElement);
                });
            }
            
            // Setup validation for middle initial
            if (middleInitialInput) {
                setupFieldValidation(middleInitialInput, (value) => {
                    if (!value || value.trim() === '') {
                        return { valid: true, message: '' }; // Optional
                    }
                    
                    // Check length first
                    const trimmed = value.trim();
                    if (trimmed.length > 1) {
                        return { valid: false, message: 'Middle initial must be exactly 1 character.' };
                    }
                    
                    // Validate it's a letter only (no numbers, no special chars)
                    if (!/^[A-Za-zÀ-ÿ]$/.test(trimmed)) {
                        return { 
                            valid: false, 
                            message: 'Middle initial must be a single letter.' 
                        };
                    }
                    
                    return { valid: true, message: '✅ Looks good!' };
                }, 'Middle initial', { 
                    fieldType: 'letters' 
                });
            }
            
            // Setup validation for date of birth (no age requirement for family members)
            if (dateOfBirthInput) {
                setupFieldValidation(dateOfBirthInput, (value) => {
                    if (!value || value.trim() === '') {
                        return { valid: true, message: '' }; // Optional for family members
                    }
                    
                    const date = new Date(value);
                    const today = new Date();
                    
                    if (isNaN(date.getTime())) {
                        return { valid: false, message: 'Please enter a valid date.' };
                    }
                    
                    if (date > today) {
                        return { valid: false, message: 'Date of birth cannot be in the future.' };
                    }
                    
                    return { valid: true, message: '' };
                }, 'Date of birth');
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
            // Update header step indicator
            const stepBadge = document.querySelector('.register-header-content .text-xs.font-medium');
            const stepText = document.querySelector('.register-header-content .text-xs.text-white\\/70');
            const subtitle = document.getElementById('register-modal-subtitle');
            
            if (stepBadge && stepText) {
                const stepNames = {
                    1: { badge: 'Step 1 of 3', text: 'Personal Information' },
                    2: { badge: 'Step 2 of 3', text: 'Family Members' },
                    3: { badge: 'Step 3 of 3', text: 'Review & Submit' }
                };
                
                if (stepNames[currentStep]) {
                    stepBadge.textContent = stepNames[currentStep].badge;
                    stepText.textContent = stepNames[currentStep].text;
                }
            }
            
            // Show subtitle only on step 1, hide on steps 2 and 3
            if (subtitle) {
                if (currentStep === 1) {
                    subtitle.style.display = 'block';
                } else {
                    subtitle.style.display = 'none';
                }
            }
            
            // Update all steps
            for (let i = 1; i <= 3; i++) {
                const stepNumber = document.getElementById(`modal-step-${i}`);
                const stepItem = stepNumber.closest('.step-item');
                const stepLabel = stepItem.querySelector('.step-label');
                const dividerBefore = stepItem.previousElementSibling;
                const dividerAfter = stepItem.nextElementSibling;
                
                // Remove all active/inactive classes
                stepNumber.classList.remove('active', 'inactive');
                stepItem.classList.remove('active');
                
                if (i < currentStep) {
                    // Completed step
                    stepNumber.classList.add('active');
                    stepItem.classList.add('active');
                    if (dividerBefore && dividerBefore.classList.contains('step-divider')) {
                        dividerBefore.classList.add('completed');
                    }
                } else if (i === currentStep) {
                    // Current step
                    stepNumber.classList.add('active');
                    stepItem.classList.add('active');
                } else {
                    // Future step
                    stepNumber.classList.add('inactive');
                }
            }
        }
        
        function closeRegisterModal() {
            // Save form data before closing
            saveRegisterFormData();
            
            document.getElementById('registerModal').classList.add('hidden');
            document.getElementById('registerModal').classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        
        function openLoginModal() {
            const modal = document.getElementById('loginModal');
            const submitBtn = document.getElementById('login-submit-btn');
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            
            // Reset loading state if modal was previously opened
            if (submitBtn) {
                submitBtn.classList.remove('loading');
                const span = submitBtn.querySelector('span');
                if (span) {
                    span.textContent = 'Sign in';
                }
            }
            
            // Process logo to remove white background
            setTimeout(() => {
                initLogoProcessing();
            }, 100);
            
            // Focus on email input for better UX
            const emailInput = document.getElementById('login-email');
            if (emailInput) {
                setTimeout(() => emailInput.focus(), 100);
                
                // Setup login form validation
                setupLoginValidation();
            }
        }
        
        // Setup login form validation
        function setupLoginValidation() {
            const emailInput = document.getElementById('login-email');
            const passwordInput = document.getElementById('login-password');
            
            function validateLoginForm() {
                const email = emailInput?.value.trim();
                const password = passwordInput?.value;
                
                // Basic email format check
                const emailValid = email && VALIDATION_PATTERNS.email.test(email);
                const passwordValid = password && password.length > 0;
                
                // Show validation feedback
                if (emailInput) {
                    emailInput.classList.remove('border-red-300', 'border-green-300');
                    if (email && !emailValid) {
                        emailInput.classList.add('border-red-300');
                    } else if (email && emailValid) {
                        emailInput.classList.add('border-green-300');
                    }
                }
                
                if (passwordInput) {
                    passwordInput.classList.remove('border-red-300', 'border-green-300');
                    if (password && !passwordValid) {
                        passwordInput.classList.add('border-red-300');
                    } else if (password && passwordValid) {
                        passwordInput.classList.add('border-green-300');
                    }
                }
                
                return emailValid && passwordValid;
            }
            
            // Real-time validation
            if (emailInput) {
                emailInput.addEventListener('input', function() {
                    // Sanitize email input - remove banned characters but keep @, ., _, %, +, -
                    let value = this.value;
                    // Remove truly banned chars for emails (keep @ . _ % + - which are valid in emails)
                    const sanitized = value.replace(/[!$^&*()={}\[\]:;"<>?\/\\|~`]/g, '');
                    if (sanitized !== value) {
                        const cursorPos = this.selectionStart;
                        this.value = sanitized;
                        this.setSelectionRange(Math.max(0, cursorPos - 1), Math.max(0, cursorPos - 1));
                    }
                    validateLoginForm();
                });
            }
            
            if (passwordInput) {
                passwordInput.addEventListener('input', validateLoginForm);
            }
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
        
        // Toggle family member card expand/collapse
        function toggleFamilyMemberCard(headerElement) {
            const card = headerElement.closest('.family-member-card');
            if (card) {
                card.classList.toggle('expanded');
            }
        }
        
        // Remove family member card
        function removeFamilyMember(buttonElement) {
            const card = buttonElement.closest('.family-member-card');
            if (card) {
                // Add fade-out animation
                card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                card.style.opacity = '0';
                card.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    card.remove();
                    // Update family member numbers
                    updateFamilyMemberNumbers();
                }, 300);
            }
        }
        
        // Update family member numbers after removal
        function updateFamilyMemberNumbers() {
            const cards = document.querySelectorAll('.family-member-card');
            cards.forEach((card, index) => {
                const titleElement = card.querySelector('.family-member-title');
                if (titleElement) {
                    titleElement.textContent = `Family Member ${index + 1}`;
                }
            });
        }
        
        // Add family member functionality
        document.getElementById('add-family-member').addEventListener('click', function() {
            const container = document.getElementById('family-members-container');
            const newMember = document.createElement('div');
            newMember.className = 'family-member-card expanded';
            newMember.innerHTML = `
                <div class="family-member-header" onclick="toggleFamilyMemberCard(this)">
                    <div class="family-member-header-left">
                        <svg class="family-member-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                        <span class="family-member-title">Family Member ${familyMemberCount + 1}</span>
                </div>
                    <div class="family-member-header-right">
                        <button type="button" class="remove-family-member-btn" onclick="event.stopPropagation(); removeFamilyMember(this)" aria-label="Remove family member">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>
                    </div>
                <div class="family-member-content">
                    <!-- Row 1: First Name, Middle Initial, Last Name -->
                    <div class="family-member-fields">
                        <div>
                            <input type="text" name="family_members[${familyMemberCount}][first_name]" 
                                   class="register-input-field" 
                                   placeholder="First Name" />
                    </div>
                        <div>
                            <input type="text" name="family_members[${familyMemberCount}][middle_initial]" 
                                   class="register-input-field" 
                                   placeholder="M.I." 
                                   maxlength="1" />
                        </div>
                        <div>
                            <input type="text" name="family_members[${familyMemberCount}][last_name]" 
                                   class="register-input-field" 
                                   placeholder="Last Name" />
                        </div>
                    </div>
                    
                    <!-- Row 2: Relationship, Date of Birth -->
                    <div class="family-member-fields-row2">
                        <div class="w-full">
                            <select name="family_members[${familyMemberCount}][relationship]" 
                                    class="register-input-field appearance-none pr-10 w-full"
                                    onchange="handleRelationshipChange(this, ${familyMemberCount})">
                                ${generateRelationshipOptions()}
                            </select>
                            <input type="text" 
                                   name="family_members[${familyMemberCount}][relationship_other]" 
                                   id="relationship_other_${familyMemberCount}"
                                   class="register-input-field mt-2 w-full transition-all duration-300 ease-in-out opacity-0 max-h-0 overflow-hidden" 
                                   placeholder="Specify relationship (e.g., Stepfather, Godmother, etc.)"
                                   maxlength="50"
                                   style="display: none;" />
                        </div>
                        <div>
                            <input type="date" name="family_members[${familyMemberCount}][date_of_birth]" 
                                   class="register-input-field" 
                                   placeholder="Date of Birth" />
                        </div>
                    </div>
                </div>
            `;
            
            // Add fade-in animation
            newMember.style.opacity = '0';
            newMember.style.transform = 'translateY(-10px)';
            container.appendChild(newMember);
            
            // Trigger animation
            setTimeout(() => {
                newMember.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                newMember.style.opacity = '1';
                newMember.style.transform = 'translateY(0)';
            }, 10);
            
            familyMemberCount++;
            
            // Add real-time validation for family member fields
            setupFamilyMemberValidation(newMember);
            
            // Scroll to new member card
            newMember.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        
        // Handle relationship change to show/hide custom relationship input
        function handleRelationshipChange(selectElement, memberIndex) {
            const otherInput = document.getElementById('relationship_other_' + memberIndex);
            if (otherInput) {
                if (selectElement.value === 'Other') {
                    // Show with smooth animation
                    otherInput.classList.remove('opacity-0', 'max-h-0', 'overflow-hidden');
                    otherInput.classList.add('opacity-100', 'max-h-20', 'mb-2');
                    otherInput.style.display = 'block';
                    otherInput.required = true;
                    // Focus on the input
                    setTimeout(() => {
                        otherInput.focus();
                    }, 100);
                } else {
                    // Hide with smooth animation
                    otherInput.classList.remove('opacity-100', 'max-h-20', 'mb-2');
                    otherInput.classList.add('opacity-0', 'max-h-0', 'overflow-hidden');
                    otherInput.value = '';
                    otherInput.required = false;
                    setTimeout(() => {
                        if (otherInput.value === '') {
                            otherInput.style.display = 'none';
                        }
                    }, 300);
                }
            }
        }
        
        // Setup validation for initial family member on page load
        document.addEventListener('DOMContentLoaded', function() {
            const firstFamilyMember = document.querySelector('.family-member-card');
            if (firstFamilyMember) {
                setupFamilyMemberValidation(firstFamilyMember);
            }
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
        
        // Remove white background from logo using canvas
        function removeLogoWhiteBackground() {
            const logoImg = document.getElementById('login-logo-img');
            const canvas = document.getElementById('login-logo-canvas');
            
            if (!logoImg || !canvas) return;
            
            // Function to process the image
            function processImage(imgElement) {
                try {
                    const ctx = canvas.getContext('2d');
                    // Use a higher resolution for better quality, then scale down
                    const scale = 2; // 2x resolution for better quality
                    canvas.width = (logoImg.clientWidth || 56) * scale;
                    canvas.height = (logoImg.clientHeight || 56) * scale;
                    
                    // Draw the image scaled up
                    ctx.drawImage(imgElement, 0, 0, canvas.width, canvas.height);
                    
                    // Get image data
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const data = imageData.data;
                    
                    // Remove white/light pixels (make them transparent)
                    // Threshold for white detection (240-255 range, lower = more aggressive)
                    const whiteThreshold = 240;
                    
                    for (let i = 0; i < data.length; i += 4) {
                        const r = data[i];
                        const g = data[i + 1];
                        const b = data[i + 2];
                        const a = data[i + 3];
                        
                        // If pixel is white or very light, make it transparent
                        if (a > 0 && r >= whiteThreshold && g >= whiteThreshold && b >= whiteThreshold) {
                            data[i + 3] = 0; // Set alpha to 0 (transparent)
                        }
                    }
                    
                    // Put the modified image data back
                    ctx.putImageData(imageData, 0, 0);
                    
                    // Replace the image source with processed canvas
                    const processedDataUrl = canvas.toDataURL('image/png');
                    logoImg.src = processedDataUrl;
                    logoImg.style.width = '56px';
                    logoImg.style.height = '56px';
                } catch (e) {
                    // If processing fails, fall back to CSS blend mode
                    console.log('Logo processing failed, using CSS blend mode:', e);
                    logoImg.style.mixBlendMode = 'multiply';
                }
            }
            
            // If image is already loaded and has dimensions
            if (logoImg.complete && logoImg.naturalWidth > 0) {
                processImage(logoImg);
            } else {
                // Wait for image to load
                const handleLoad = function() {
                    processImage(logoImg);
                    logoImg.removeEventListener('load', handleLoad);
                };
                logoImg.addEventListener('load', handleLoad);
            }
        }
        
        // Initialize logo processing
        function initLogoProcessing() {
            const logoImg = document.getElementById('login-logo-img');
            if (!logoImg) return;
            
            // Small delay to ensure modal is rendered
            setTimeout(() => {
                removeLogoWhiteBackground();
            }, 150);
        }
        
        // Login form submission with loading state
        const loginForm = document.getElementById('loginForm');
        const loginSubmitBtn = document.getElementById('login-submit-btn');
        
        if (loginForm && loginSubmitBtn) {
            loginForm.addEventListener('submit', function(e) {
                const email = document.getElementById('login-email').value.trim();
                const password = document.getElementById('login-password').value;
                
                // Basic validation
                if (!email || !password) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading state
                loginSubmitBtn.classList.add('loading');
                loginSubmitBtn.querySelector('span').textContent = 'Signing in...';
                
                // Form will submit normally, but if there's an error, we'll handle it on return
                // The loading state will be reset if the page reloads with an error
            });
        }
        
        // Active Link Management
        function setActiveLink(sectionId) {
            // Remove active class from all desktop links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Remove active-mobile class from all mobile links
            document.querySelectorAll('.nav-link-mobile').forEach(link => {
                link.classList.remove('active-mobile');
                // Reset indicator dot
                const dot = link.querySelector('span');
                if (dot) {
                    dot.style.backgroundColor = 'transparent';
                }
            });
            
            // Add active class to the clicked desktop link
            const desktopLink = document.getElementById('nav-' + sectionId);
            if (desktopLink) {
                desktopLink.classList.add('active');
            }
            
            // Add active-mobile class to the clicked mobile link
            const mobileLink = document.getElementById('nav-mobile-' + sectionId);
            if (mobileLink) {
                mobileLink.classList.add('active-mobile');
                const dot = mobileLink.querySelector('span');
                if (dot) {
                    dot.style.backgroundColor = '#2563eb';
                }
            }
        }
        
        // Handle nav link clicks
        function handleNavClick(sectionId) {
            setActiveLink(sectionId);
            // Smooth scroll to section
            const section = document.getElementById(sectionId);
            if (section) {
                const offset = 80;
                const elementPosition = section.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - offset;
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        }
        
        // Make function globally available
        window.handleNavClick = handleNavClick;
        
        // Initialize: Set Home as active by default
        document.addEventListener('DOMContentLoaded', function() {
            setActiveLink('home');
            
            // Initialize logo processing on page load
            initLogoProcessing();
            
            // Add click handlers to all nav links
            document.querySelectorAll('.nav-link, .nav-link-mobile').forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href && href.startsWith('#')) {
                        const sectionId = href.substring(1);
                        handleNavClick(sectionId);
                    }
                });
            });
        });
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        
        // Function to toggle mobile menu
        function toggleMobileMenu() {
            if (mobileMenu && mobileMenuBtn) {
                const isHidden = mobileMenu.classList.contains('hidden');
                if (isHidden) {
                    mobileMenu.classList.remove('hidden');
                    mobileMenuBtn.setAttribute('aria-expanded', 'true');
                    document.body.style.overflow = 'hidden';
                } else {
                    mobileMenu.classList.add('hidden');
                    mobileMenuBtn.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                }
            }
        }
        
        // Function to close mobile menu
        function closeMobileMenu() {
            if (mobileMenu && mobileMenuBtn) {
                mobileMenu.classList.add('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }
        }
        
        // Make functions globally available
        window.closeMobileMenu = closeMobileMenu;
        window.toggleMobileMenu = toggleMobileMenu;
        
        if (mobileMenuBtn && mobileMenu) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleMobileMenu();
            });
            
            // Close mobile menu when clicking nav links
            mobileMenu.querySelectorAll('a, button').forEach(link => {
                link.addEventListener('click', () => {
                    closeMobileMenu();
                });
            });
            
            // Close menu on escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                    closeMobileMenu();
                }
            });
            
            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!mobileMenuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
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
        
        // Show/hide scroll to top button
        const scrollToTopBtn = document.getElementById('scrollToTop');
        const onScroll = () => {
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

        // About section animations
        const aboutElements = {
            content: document.querySelector('.about-content'),
            features: document.querySelectorAll('.about-feature'),
            stats: document.querySelectorAll('.about-stat')
        };

        const aboutObserverOptions = {
            threshold: 0.2,
            rootMargin: '0px 0px -50px 0px'
        };

        // Observe main content
        if (aboutElements.content) {
            const contentObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        contentObserver.unobserve(entry.target);
                    }
                });
            }, aboutObserverOptions);
            contentObserver.observe(aboutElements.content);
        }

        // Observe feature cards with stagger
        if (aboutElements.features.length > 0) {
            const featureObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('visible');
                        }, index * 150);
                        featureObserver.unobserve(entry.target);
                    }
                });
            }, aboutObserverOptions);
            
            aboutElements.features.forEach(feature => {
                featureObserver.observe(feature);
            });
        }

        // Observe stats with stagger
        if (aboutElements.stats.length > 0) {
            const statObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('visible');
                        }, index * 100);
                        statObserver.unobserve(entry.target);
                    }
                });
            }, aboutObserverOptions);
            
            aboutElements.stats.forEach(stat => {
                statObserver.observe(stat);
            });
        }

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
        let resendTimer = null;
        let timerSeconds = 60;
        
        function showVerificationModal() {
            const modal = document.getElementById('verificationModal');
            const emailInput = document.getElementById('email-input');
            const emailDisplay = document.getElementById('verification-email-display');
            
            if (emailInput && emailDisplay) {
                emailDisplay.textContent = emailInput.value;
            }
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            
            // Reset verification code input
            const codeInput = document.getElementById('verificationCode');
            if (codeInput) {
                codeInput.value = '';
                codeInput.focus();
            }
            
            // Hide previous errors/success
            document.getElementById('verificationError').classList.add('hidden');
            document.getElementById('verificationSuccess').classList.add('hidden');
            
            // Show info toast about email verification
            showToast('Sending verification code to your email...', 'info');
            
            // Request verification code immediately and start timer
            requestVerificationCode();
        }
        
        function closeVerificationModal() {
            const modal = document.getElementById('verificationModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = 'auto';
            
            const codeInput = document.getElementById('verificationCode');
            if (codeInput) {
                codeInput.value = '';
            }
            
            document.getElementById('verificationError').classList.add('hidden');
            document.getElementById('verificationSuccess').classList.add('hidden');
            
            // Clear timer
            if (resendTimer) {
                clearInterval(resendTimer);
                resendTimer = null;
            }
            
            // Reset timer UI
            timerSeconds = 60;
            updateResendTimer();
        }
        
        function startResendTimer() {
            // Clear existing timer
            if (resendTimer) {
                clearInterval(resendTimer);
            }
            
            timerSeconds = 60;
            const resendBtn = document.getElementById('resendBtn');
            const timerDiv = document.getElementById('resend-timer');
            const timerCount = document.getElementById('timer-count');
            
            resendBtn.disabled = true;
            timerDiv.classList.add('active');
            
            resendTimer = setInterval(() => {
                timerSeconds--;
                if (timerCount) {
                    timerCount.textContent = timerSeconds;
                }
                
                if (timerSeconds <= 0) {
                    clearInterval(resendTimer);
                    resendTimer = null;
                    resendBtn.disabled = false;
                    timerDiv.classList.remove('active');
                    if (timerCount) {
                        timerCount.textContent = '0';
                    }
                }
            }, 1000);
        }
        
        function updateResendTimer() {
            const timerCount = document.getElementById('timer-count');
            const resendBtn = document.getElementById('resendBtn');
            const timerDiv = document.getElementById('resend-timer');
            
            if (timerSeconds > 0) {
                if (timerCount) timerCount.textContent = timerSeconds;
                if (resendBtn) resendBtn.disabled = true;
                if (timerDiv) timerDiv.classList.add('active');
            } else {
                if (resendBtn) resendBtn.disabled = false;
                if (timerDiv) timerDiv.classList.remove('active');
            }
        }
        
        async function requestVerificationCode() {
            const email = document.querySelector('input[name="email"]').value.trim();
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            
            if (!email || !firstName || !lastName) {
                showVerificationError('Please fill in email, first name, and last name first.');
                return;
            }
            
            // Hide previous errors
            document.getElementById('verificationError').classList.add('hidden');
            document.getElementById('verificationSuccess').classList.add('hidden');
            
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
                    showToast('Verification code sent to your email!', 'success');
                    // Start resend timer
                    startResendTimer();
                } else {
                    showToast(result.message || 'Failed to send verification code', 'error');
                    showVerificationError(result.message || 'Failed to send verification code');
                }
            } catch (error) {
                console.error('Verification code request error:', error);
                showToast('Network error. Please check your connection and try again.', 'error');
                showVerificationError('Error sending verification code. Please try again.');
            }
        }
        
        async function verifyCode() {
            const code = document.getElementById('verificationCode').value.trim();
            const errorDiv = document.getElementById('verificationError');
            const successDiv = document.getElementById('verificationSuccess');
            const errorText = document.getElementById('verificationErrorText');
            
            // Hide previous messages
            errorDiv.classList.add('hidden');
            successDiv.classList.add('hidden');
            
            if (!code || code.length !== 6) {
                if (errorText) errorText.textContent = 'Please enter a 6-digit code';
                errorDiv.classList.remove('hidden');
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
                    // Show success message
                    successDiv.classList.remove('hidden');
                    
                    // Mark email as verified
                    window.emailVerified = true;
                    
                    // Clear timer
                    if (resendTimer) {
                        clearInterval(resendTimer);
                        resendTimer = null;
                    }
                    
                    // Wait a moment then proceed to step 2
                    setTimeout(() => {
                    closeVerificationModal();
                        // Directly show step 2 without validation since email is verified
                        document.querySelectorAll('.step-content').forEach(content => {
                            content.classList.add('hidden');
                        });
                        document.getElementById('step-2').classList.remove('hidden');
                        updateProgressIndicator(2);
                    }, 1500);
                } else {
                    if (errorText) errorText.textContent = result.message || 'Invalid verification code';
                    errorDiv.classList.remove('hidden');
                }
            } catch (error) {
                console.error('Verification error:', error);
                if (errorText) errorText.textContent = 'Error verifying code. Please try again.';
                errorDiv.classList.remove('hidden');
            }
        }
        
        function showVerificationError(message) {
            const errorDiv = document.getElementById('verificationError');
            const errorText = document.getElementById('verificationErrorText');
            
            if (errorText) errorText.textContent = message;
            errorDiv.classList.remove('hidden');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                errorDiv.classList.add('hidden');
            }, 5000);
        }
        
        // Allow Enter key to verify and auto-focus
        const verificationCodeInput = document.getElementById('verificationCode');
        if (verificationCodeInput) {
            verificationCodeInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyCode();
            }
        });
            
            // Auto-format: only numbers, max 6 digits
            verificationCodeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }
    </script>
</body>
</html>



