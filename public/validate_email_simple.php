<?php
// Simple email validation - more lenient approach
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

// Basic email format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Please enter a valid email address format',
        'valid' => false
    ]);
    exit;
}

// Check if email starts with @ (invalid)
if (strpos($email, '@') === 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email cannot start with @ symbol',
        'valid' => false
    ]);
    exit;
}

// Check if email ends with @ (invalid)
if (substr($email, -1) === '@') {
    echo json_encode([
        'success' => false, 
        'message' => 'Email cannot end with @ symbol',
        'valid' => false
    ]);
    exit;
}

// Check for multiple @ symbols
if (substr_count($email, '@') !== 1) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email must contain exactly one @ symbol',
        'valid' => false
    ]);
    exit;
}

$domain = substr(strrchr($email, "@"), 1);

// Check for disposable emails (simple list)
$disposableDomains = [
    '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
    'temp-mail.org', 'throwaway.email', 'getnada.com', 'maildrop.cc',
    'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com'
];

if (in_array(strtolower($domain), $disposableDomains)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Temporary/disposable email addresses are not allowed',
        'valid' => false
    ]);
    exit;
}

// Check for fake domains
$fakeDomains = [
    'fake.com', 'test.com', 'example.com', 'invalid.com', 'nonexistent.com',
    'dummy.com', 'sample.com', 'demo.com', 'placeholder.com', 'temp.com'
];

if (in_array(strtolower($domain), $fakeDomains)) {
    echo json_encode([
        'success' => false, 
        'message' => 'This appears to be a test or fake email address',
        'valid' => false
    ]);
    exit;
}

// Trust known good domains
$trustedDomains = [
    'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
    'aol.com', 'icloud.com', 'me.com', 'mac.com', 'protonmail.com',
    'yandex.com', 'mail.ru', 'zoho.com', 'fastmail.com', 'tutanota.com'
];

if (in_array(strtolower($domain), $trustedDomains)) {
    // Check if email already exists in database
    try {
        $pdo = db();
        
        // Check residents table
        $stmt = $pdo->prepare("SELECT id FROM residents WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false, 
                'message' => 'This email is already registered',
                'valid' => false,
                'exists' => true
            ]);
            exit;
        }
        
        // Check pending_residents table
        $stmt = $pdo->prepare("SELECT id FROM pending_residents WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false, 
                'message' => 'This email is already pending registration',
                'valid' => false,
                'exists' => true
            ]);
            exit;
        }
        
        // Check users table
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false, 
                'message' => 'This email is already registered as a BHW account',
                'valid' => false,
                'exists' => true
            ]);
            exit;
        }
        
        // Email is valid and available
        echo json_encode([
            'success' => true, 
            'message' => 'Email is valid and can receive verification codes',
            'valid' => true,
            'exists' => false
        ]);
        
    } catch (Exception $e) {
        error_log("Email validation error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Unable to validate email. Please try again.',
            'valid' => false
        ]);
    }
    exit;
}

// For unknown domains, just check basic format and database
try {
    $pdo = db();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM residents WHERE email = ? UNION SELECT id FROM pending_residents WHERE email = ? UNION SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email, $email, $email]);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false, 
            'message' => 'This email is already registered',
            'valid' => false,
            'exists' => true
        ]);
        exit;
    }
    
    // For unknown domains, assume valid if format is correct
    echo json_encode([
        'success' => true, 
        'message' => 'Email format is valid',
        'valid' => true,
        'exists' => false
    ]);
    
} catch (Exception $e) {
    error_log("Email validation error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Unable to validate email. Please try again.',
        'valid' => false
    ]);
}
?>
