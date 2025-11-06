<?php
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

// Enhanced email format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Please enter a valid email address format (e.g., user@domain.com)',
        'valid' => false
    ]);
    exit;
}

// Additional format checks
$emailParts = explode('@', $email);
if (count($emailParts) !== 2) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email must have exactly one @ symbol',
        'valid' => false
    ]);
    exit;
}

// Check if email starts with @ (invalid)
if (strpos($email, '@') === 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email cannot start with @ symbol. Please enter a valid email like user@domain.com',
        'valid' => false
    ]);
    exit;
}

// Check if email ends with @ (invalid)
if (substr($email, -1) === '@') {
    echo json_encode([
        'success' => false, 
        'message' => 'Email cannot end with @ symbol. Please enter a valid email like user@domain.com',
        'valid' => false
    ]);
    exit;
}

$localPart = $emailParts[0];
$domainPart = $emailParts[1];

// Check local part (before @)
if (empty($localPart) || strlen($localPart) > 64) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email username part is invalid',
        'valid' => false
    ]);
    exit;
}

// Check domain part (after @)
if (empty($domainPart) || strlen($domainPart) > 255) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email domain part is invalid',
        'valid' => false
    ]);
    exit;
}

// Check if domain has at least one dot
if (strpos($domainPart, '.') === false) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email domain must include a valid domain extension (e.g., .com, .org)',
        'valid' => false
    ]);
    exit;
}

// Check if domain extension is valid (at least 2 characters)
$domainParts = explode('.', $domainPart);
$extension = end($domainParts);
if (strlen($extension) < 2) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email domain extension must be at least 2 characters',
        'valid' => false
    ]);
    exit;
}

// Check for common invalid patterns
$invalidPatterns = [
    '/^[^@]+@$/',  // ends with @
    '/^@[^@]+$/',  // starts with @
    '/\.{2,}/',    // consecutive dots
    '/^\.|\.$/',   // starts or ends with dot
    '/@.*@/',      // multiple @ symbols
];

foreach ($invalidPatterns as $pattern) {
    if (preg_match($pattern, $email)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Email format contains invalid characters or patterns',
            'valid' => false
        ]);
        exit;
    }
}

// Function to verify email using external API
function verifyEmailWithAPI($email) {
    // Use a free email verification API
    $apiUrl = "https://api.email-validator.net/api/verify";
    $apiKey = "your-api-key-here"; // You can get a free key from email-validator.net
    
    // Alternative: Use Hunter.io API (free tier available)
    $hunterApiKey = "your-hunter-api-key"; // Get from hunter.io
    $hunterUrl = "https://api.hunter.io/v2/email-verifier?email=" . urlencode($email) . "&api_key=" . $hunterApiKey;
    
    // Alternative: Use ZeroBounce API (free tier available)
    $zeroBounceApiKey = "your-zerobounce-api-key"; // Get from zerobounce.net
    $zeroBounceUrl = "https://api.zerobounce.net/v2/validate?api_key=" . $zeroBounceApiKey . "&email=" . urlencode($email);
    
    // For now, let's use a simple approach with multiple checks
    return verifyEmailWithMultipleChecks($email);
}

// Enhanced email verification with multiple checks
function verifyEmailWithMultipleChecks($email) {
    $domain = substr(strrchr($email, "@"), 1);
    $user = substr($email, 0, strpos($email, "@"));
    
    // Check 1: Common disposable email domains (fastest check)
    $disposableDomains = [
        '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
        'temp-mail.org', 'throwaway.email', 'getnada.com', 'maildrop.cc',
        'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com',
        'mailnesia.com', 'spamgourmet.com', 'mailcatch.com', 'mailmetrash.com',
        'trashmail.com', 'mailnull.com', 'spam4.me', 'guerrillamail.info'
    ];
    
    if (in_array(strtolower($domain), $disposableDomains)) {
        return false; // Disposable email
    }
    
    // Check 2: Common fake/test domains
    $fakeDomains = [
        'fake.com', 'test.com', 'example.com', 'invalid.com', 'nonexistent.com',
        'dummy.com', 'sample.com', 'demo.com', 'placeholder.com', 'temp.com',
        'mock.com', 'simulation.com', 'virtual.com', 'artificial.com'
    ];
    
    if (in_array(strtolower($domain), $fakeDomains)) {
        return false; // Fake/test domain
    }
    
    // Check 3: Trust known good domains (skip SMTP verification for these)
    $trustedDomains = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
        'aol.com', 'icloud.com', 'me.com', 'mac.com', 'protonmail.com',
        'yandex.com', 'mail.ru', 'zoho.com', 'fastmail.com', 'tutanota.com',
        'gmx.com', 'web.de', 't-online.de', 'orange.fr', 'free.fr',
        'laposte.net', 'wanadoo.fr', 'sfr.fr', 'alice.it', 'libero.it',
        'virgilio.it', 'tin.it', 'tiscali.it', 'aruba.it', 'email.it',
        'naver.com', 'daum.net', 'hanmail.net', 'nate.com', 'kakao.com',
        'qq.com', '163.com', '126.com', 'sina.com', 'sohu.com',
        'rediffmail.com', 'sify.com', 'indiatimes.com', 'yahoo.co.in',
        'yahoo.co.uk', 'yahoo.ca', 'yahoo.com.au', 'yahoo.co.jp',
        'hotmail.co.uk', 'hotmail.ca', 'hotmail.com.au', 'hotmail.fr',
        'hotmail.de', 'hotmail.it', 'hotmail.es', 'hotmail.com.br',
        'live.co.uk', 'live.ca', 'live.com.au', 'live.fr', 'live.de',
        'live.it', 'live.es', 'live.com.br', 'msn.com'
    ];
    
    if (in_array(strtolower($domain), $trustedDomains)) {
        return true; // Trust known good domains
    }
    
    // Check 4: Try external API first (if available)
    require_once __DIR__ . '/verify_email_api.php';
    $apiResult = verifyEmailWithExternalAPI($email);
    if ($apiResult !== null) {
        return $apiResult; // API gave us a definitive answer
    }
    
    // Check 5: DNS MX records for unknown domains
    if (!getmxrr($domain, $mxhosts)) {
        // Try A record as fallback
        if (!gethostbyname($domain) || gethostbyname($domain) === $domain) {
            return false; // Domain doesn't exist
        }
    }
    
    // Check 6: Try SMTP verification for unknown domains (more reliable but slower)
    $smtpResult = verifyEmailWithSMTP($email);
    
    // If SMTP verification fails, but domain has MX records, assume it's valid
    // This prevents blocking legitimate emails due to server restrictions
    if (!$smtpResult && getmxrr($domain, $mxhosts)) {
        return true; // Domain has MX records, assume valid
    }
    
    return $smtpResult;
}

// Function to perform real SMTP verification
function verifyEmailWithSMTP($email) {
    $domain = substr(strrchr($email, "@"), 1);
    $user = substr($email, 0, strpos($email, "@"));
    
    // Get MX records for the domain
    if (!getmxrr($domain, $mxhosts)) {
        return false; // No MX records
    }
    
    // Sort MX records by priority
    array_multisort($mxhosts);
    
    // Try to connect to the first MX server
    $mxhost = $mxhosts[0];
    
    // Create socket connection with timeout
    $socket = @fsockopen($mxhost, 25, $errno, $errstr, 3);
    if (!$socket) {
        return false; // Cannot connect to mail server
    }
    
    // Set socket timeout
    stream_set_timeout($socket, 3);
    
    // SMTP conversation
    $response = fgets($socket, 1024);
    if (substr($response, 0, 3) != '220') {
        fclose($socket);
        return false; // Server not ready
    }
    
    // Send HELO
    fputs($socket, "HELO " . $_SERVER['HTTP_HOST'] . "\r\n");
    $response = fgets($socket, 1024);
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        return false;
    }
    
    // Send MAIL FROM
    fputs($socket, "MAIL FROM: <test@" . $_SERVER['HTTP_HOST'] . ">\r\n");
    $response = fgets($socket, 1024);
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        return false;
    }
    
    // Send RCPT TO (this is the actual verification)
    fputs($socket, "RCPT TO: <$email>\r\n");
    $response = fgets($socket, 1024);
    
    // Send QUIT
    fputs($socket, "QUIT\r\n");
    fclose($socket);
    
    // Check if email exists
    return (substr($response, 0, 3) == '250');
}

// Function to check if email domain exists and can receive emails
function checkEmailDeliverability($email) {
    $domain = substr(strrchr($email, "@"), 1);
    
    // List of known valid email providers (trusted)
    $trustedProviders = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
        'aol.com', 'icloud.com', 'me.com', 'mac.com', 'protonmail.com',
        'yandex.com', 'mail.ru', 'zoho.com', 'fastmail.com', 'tutanota.com',
        'gmx.com', 'web.de', 't-online.de', 'orange.fr', 'free.fr',
        'laposte.net', 'wanadoo.fr', 'sfr.fr', 'alice.it', 'libero.it',
        'virgilio.it', 'tin.it', 'tiscali.it', 'aruba.it', 'email.it',
        'naver.com', 'daum.net', 'hanmail.net', 'nate.com', 'kakao.com',
        'qq.com', '163.com', '126.com', 'sina.com', 'sohu.com',
        'rediffmail.com', 'sify.com', 'indiatimes.com', 'yahoo.co.in',
        'yahoo.co.uk', 'yahoo.ca', 'yahoo.com.au', 'yahoo.co.jp',
        'hotmail.co.uk', 'hotmail.ca', 'hotmail.com.au', 'hotmail.fr',
        'hotmail.de', 'hotmail.it', 'hotmail.es', 'hotmail.com.br',
        'live.co.uk', 'live.ca', 'live.com.au', 'live.fr', 'live.de',
        'live.it', 'live.es', 'live.com.br', 'msn.com', 'skynet.be',
        'telenet.be', 'scarlet.be', 'pandora.be', 'base.be', 'proximus.be'
    ];
    
    // Check if it's a known trusted provider
    if (in_array(strtolower($domain), $trustedProviders)) {
        return true; // Trust known providers
    }
    
    // For unknown domains, perform real SMTP verification
    return verifyEmailWithSMTP($email);
}

try {
    $pdo = db();
    
    // Check email deliverability first with enhanced verification
    $validationResult = verifyEmailWithMultipleChecks($email);
    
    // Debug mode - uncomment to see what's happening
    // error_log("Email validation for $email: " . ($validationResult ? 'VALID' : 'INVALID'));
    
    if ($validationResult === false) {
        $domain = substr(strrchr($email, "@"), 1);
        
        // Check if it's a common typo and suggest correction
        $commonTypos = [
            'gmai.com' => 'gmail.com',
            'gmial.com' => 'gmail.com',
            'gmaill.com' => 'gmail.com',
            'gmail.co' => 'gmail.com',
            'gmail.cm' => 'gmail.com',
            'gmai.co' => 'gmail.com',
            'yahooo.com' => 'yahoo.com',
            'yaho.com' => 'yahoo.com',
            'yahoo.co' => 'yahoo.com',
            'hotmial.com' => 'hotmail.com',
            'hotmai.com' => 'hotmail.com',
            'hotmail.co' => 'hotmail.com',
            'outlok.com' => 'outlook.com',
            'outlook.co' => 'outlook.com'
        ];
        
        if (isset($commonTypos[strtolower($domain)])) {
            $correctDomain = $commonTypos[strtolower($domain)];
            $correctEmail = str_replace($domain, $correctDomain, $email);
            echo json_encode([
                'success' => false, 
                'message' => "Did you mean {$correctEmail}? This email address cannot receive emails.",
                'valid' => false
            ]);
        } else {
            // Check if it's a disposable email
            $disposableDomains = [
                '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
                'temp-mail.org', 'throwaway.email', 'getnada.com', 'maildrop.cc',
                'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com',
                'mailnesia.com', 'spamgourmet.com', 'mailcatch.com', 'mailmetrash.com'
            ];
            
            if (in_array(strtolower($domain), $disposableDomains)) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Temporary/disposable email addresses are not allowed. Please use a permanent email address.',
                    'valid' => false
                ]);
            } else {
                // Check if it's a completely fake domain
                $fakeDomains = [
                    'test.com', 'example.com', 'fake.com', 'invalid.com', 'nonexistent.com',
                    'dummy.com', 'sample.com', 'demo.com', 'placeholder.com', 'temp.com',
                    'mock.com', 'simulation.com', 'virtual.com', 'artificial.com'
                ];
                if (in_array(strtolower($domain), $fakeDomains)) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'This appears to be a test or fake email address. Please use a real email address that you can access.',
                        'valid' => false
                    ]);
                } else {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'This email address does not exist or cannot receive emails. Please use a real, active email address.',
                        'valid' => false
                    ]);
                }
            }
        }
        exit;
    }
    
    // Check if email already exists in residents table
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
    
    // Check if email already exists in pending_residents table
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
    
    // Check if email already exists in users table (BHW accounts)
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
?>
