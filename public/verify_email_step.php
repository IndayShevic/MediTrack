<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_notifications.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_verification_code') {
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        
        if (!$email || !$first_name || !$last_name) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        // Check if email already exists
        $checkEmail = db()->prepare('SELECT id FROM pending_residents WHERE email = ? UNION SELECT id FROM users WHERE email = ?');
        $checkEmail->execute([$email, $email]);
        if ($checkEmail->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This email address is already registered.']);
            exit;
        }
        
        // Generate verification code
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store code in session temporarily (expires in 15 minutes)
        $_SESSION['verification_code'] = $code;
        $_SESSION['verification_email'] = $email;
        $_SESSION['verification_expires'] = time() + 900; // 15 minutes
        
        // Send email
        $name = $first_name . ' ' . $last_name;
        $success = send_email_verification_code($email, $name, $code);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Verification code sent to your email']);
        } else {
            // Check if it's a common email delivery issue
            $domain = substr(strrchr($email, "@"), 1);
            $commonIssues = [
                'gmail.com' => 'Gmail delivery issues',
                'yahoo.com' => 'Yahoo delivery issues', 
                'hotmail.com' => 'Hotmail delivery issues',
                'outlook.com' => 'Outlook delivery issues'
            ];
            
            $errorMessage = 'Failed to send verification code. ';
            
            // Check if it's a known provider with delivery issues
            if (isset($commonIssues[$domain])) {
                $errorMessage .= 'There may be temporary delivery issues with ' . $domain . '. Please try again in a few minutes.';
            } else {
                $errorMessage .= 'This email address may not exist or cannot receive emails. Please check your email address and try again.';
            }
            
            echo json_encode(['success' => false, 'message' => $errorMessage]);
        }
        exit;
    }
    
    if ($action === 'verify_code') {
        $code = trim($_POST['code'] ?? '');
        
        if (!$code) {
            echo json_encode(['success' => false, 'message' => 'Code is required']);
            exit;
        }
        
        // Check if code exists and not expired
        if (!isset($_SESSION['verification_code']) || !isset($_SESSION['verification_expires'])) {
            echo json_encode(['success' => false, 'message' => 'No verification code found. Please request a new one.']);
            exit;
        }
        
        if (time() > $_SESSION['verification_expires']) {
            echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new one.']);
            exit;
        }
        
        if (strtoupper($code) !== strtoupper((string)$_SESSION['verification_code'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code']);
            exit;
        }
        
        // Code is valid, mark as verified
        $_SESSION['email_verified'] = true;
        $_SESSION['verified_email'] = $_SESSION['verification_email'];
        
        // Clear verification code
        unset($_SESSION['verification_code'], $_SESSION['verification_expires']);
        
        echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
