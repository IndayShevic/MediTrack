<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = trim($_POST['email'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address']);
    exit;
}

try {
    $pdo = db();
    
    // Check if user exists
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // Always return success message (security best practice - don't reveal if email exists)
    // But only send email if user exists
    if ($user) {
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Delete any existing tokens for this email
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
        
        // Insert new token
        $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (email, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$email, $token, $expiresAt]);
        
        // Generate reset link
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = public_base_path();
        $resetUrl = $protocol . $host . $basePath . 'reset_password.php?token=' . urlencode($token);
        
        // Prepare email
        $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($userName)) {
            $userName = $email;
        }
        
        $brand = get_setting('brand_name', 'MediTrack');
        $subject = 'Reset Your Password - ' . $brand;
        
        $emailBody = email_template(
            'Reset Your Password',
            'You requested to reset your password. Click the button below to create a new password.',
            '<p>If you did not request this password reset, please ignore this email.</p><p>This link will expire in 1 hour.</p>',
            'Reset Password',
            $resetUrl
        );
        
        // Send email
        send_email($email, $userName, $subject, $emailBody);
    }
    
    // Always return success (security best practice)
    echo json_encode([
        'success' => true,
        'message' => 'If an account with that email exists, we have sent a password reset link to your email address.'
    ]);
    
} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ]);
}

