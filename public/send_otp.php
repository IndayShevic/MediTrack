<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

// Set timezone to Asia/Manila for PHP
date_default_timezone_set('Asia/Manila');

$brand = get_setting('brand_name', 'MediTrack');
$email = trim($_GET['email'] ?? '');
$error = '';
$success = false;

// Validate email parameter
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.php?error=Invalid email');
    exit;
}

// Helper function to ensure OTP table exists with DATETIME
function ensure_otp_table_exists(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'password_reset_otps'");
        if ($stmt->rowCount() > 0) {
            // Check if expires_at is DATETIME, if not, alter it
            $stmt = $pdo->query("SHOW COLUMNS FROM password_reset_otps WHERE Field = 'expires_at'");
            $column = $stmt->fetch();
            if ($column && strtoupper($column['Type']) !== 'DATETIME') {
                $pdo->exec("ALTER TABLE password_reset_otps MODIFY COLUMN expires_at DATETIME NOT NULL");
            }
            // Check if used_at is DATETIME
            $stmt = $pdo->query("SHOW COLUMNS FROM password_reset_otps WHERE Field = 'used_at'");
            $column = $stmt->fetch();
            if ($column && strtoupper($column['Type']) !== 'DATETIME') {
                $pdo->exec("ALTER TABLE password_reset_otps MODIFY COLUMN used_at DATETIME NULL");
            }
            return true;
        }
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_otps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL,
                otp_code VARCHAR(6) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_otp_code (otp_code),
                INDEX idx_expires (expires_at),
                INDEX idx_email_otp (email, otp_code)
            ) ENGINE=InnoDB
        ");
        return true;
    } catch (Throwable $e) {
        error_log('Failed to create/update password_reset_otps table: ' . $e->getMessage());
        return false;
    }
}

// Process OTP generation and sending
try {
    $pdo = db();
    
    // Set MySQL timezone to match PHP (Asia/Manila = UTC+8)
    $pdo->exec("SET time_zone = '+08:00'");
    
    // Ensure OTP table exists
    if (!ensure_otp_table_exists($pdo)) {
        throw new Exception('Failed to initialize OTP table');
    }
    
    // Verify email exists in users table
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: forgot_password.php?error=Email not registered');
        exit;
    }
    
    // Generate secure 6-digit OTP (random)
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    
    // Get current time in Asia/Manila timezone
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $expiresAt = clone $now;
    $expiresAt->modify('+5 minutes');
    
    // Format for database (DATETIME format: Y-m-d H:i:s)
    $expiresAtFormatted = $expiresAt->format('Y-m-d H:i:s');
    $nowFormatted = $now->format('Y-m-d H:i:s');
    
    // Log for debugging
    error_log("OTP Generation - Email: {$email}, OTP: {$otp}, Current Time (PHP): {$nowFormatted}, Expires At: {$expiresAtFormatted}");
    
    // Delete any existing unused OTPs for this email
    $deleteStmt = $pdo->prepare('DELETE FROM password_reset_otps WHERE email = ? AND (used_at IS NULL OR expires_at < ?)');
    $deleteStmt->execute([$email, $nowFormatted]);
    
    // Store the OTP in the database with email, otp code, expiration time
    $stmt = $pdo->prepare('INSERT INTO password_reset_otps (email, otp_code, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $otp, $expiresAtFormatted]);
    
    // Verify the OTP was saved correctly
    $verifyStmt = $pdo->prepare('SELECT id, expires_at FROM password_reset_otps WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1');
    $verifyStmt->execute([$email, $otp]);
    $savedOtp = $verifyStmt->fetch();
    
    if ($savedOtp) {
        error_log("OTP Saved Successfully - ID: {$savedOtp['id']}, Expires At (DB): {$savedOtp['expires_at']}");
    } else {
        error_log("ERROR: OTP was not saved correctly to database!");
    }
    
    // Send the OTP to the user's email
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    if (empty($userName)) {
        $userName = $email;
    }
    
    $subject = 'Password Reset OTP - ' . $brand;
    
    $emailBody = email_template(
        'Password Reset OTP',
        'Use the OTP code below to reset your password.',
        '<div style="font-size:14px;color:#111827">'
        . '<p>Hello ' . htmlspecialchars($userName) . ',</p>'
        . '<p>Your OTP code for password reset is:</p>'
        . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;margin:16px 0;padding:16px 24px;background:#f3f4f6;border-radius:8px;text-align:center;color:#2563eb">'
        . htmlspecialchars($otp)
        . '</div>'
        . '<p>This code will expire in 5 minutes. If you did not request this, please ignore this email.</p>'
        . '<p style="font-size:12px;color:#6b7280;margin-top:16px;">Requested at: ' . $now->format('F j, Y g:i A') . ' (Philippines Time)</p>'
        . '</div>',
        'Verify OTP',
        public_base_path() . 'verify_otp.php?email=' . urlencode($email)
    );
    
    // Try to send email, but don't fail if email sending fails
    try {
        send_email($email, $userName, $subject, $emailBody);
        error_log("OTP Email sent successfully to: {$email}");
    } catch (Throwable $emailError) {
        error_log('Email sending error: ' . $emailError->getMessage());
        // Continue even if email fails - OTP is already saved
    }
    
    $success = true;
    
} catch (PDOException $e) {
    error_log('Send OTP database error: ' . $e->getMessage());
    error_log('Send OTP database error trace: ' . $e->getTraceAsString());
    $error = 'Database error. Please try again later.';
} catch (Throwable $e) {
    error_log('Send OTP error: ' . $e->getMessage());
    error_log('Send OTP error trace: ' . $e->getTraceAsString());
    $error = 'An error occurred. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Sent - <?php echo htmlspecialchars($brand); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(to bottom right, #dbeafe, #ffffff, #eef2ff);
            position: relative;
            overflow-x: hidden;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Aurora background effect */
        .aurora {
            pointer-events: none;
            position: absolute;
            inset: 0;
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(59,130,246,.25), transparent 60%),
                radial-gradient(900px 500px at 110% 10%, rgba(99,102,241,.25), transparent 60%),
                radial-gradient(800px 400px at 50% 120%, rgba(236,72,153,.18), transparent 60%);
            filter: saturate(120%);
            animation: auroraShift 14s ease-in-out infinite alternate;
        }
        
        @keyframes auroraShift {
            0% { transform: translateY(0); }
            100% { transform: translateY(-20px); }
        }
        
        /* Dot grid background */
        .dot-grid {
            position: absolute;
            inset: 0;
        }
        
        .dot-grid::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(17,24,39,.08) 1px, transparent 1px);
            background-size: 24px 24px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.6), rgba(0,0,0,0));
        }
        
        /* Floating orbs */
        .orb {
            position: absolute;
            width: 28rem;
            height: 28rem;
            border-radius: 9999px;
            filter: blur(60px);
            opacity: .35;
        }
        
        .orb--blue {
            background: #60a5fa;
            top: -6rem;
            right: -6rem;
            animation: float 12s ease-in-out infinite;
        }
        
        .orb--violet {
            background: #a78bfa;
            bottom: -8rem;
            left: -6rem;
            animation: float 16s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0); }
            50% { transform: translateY(-20px) translateX(10px); }
        }
        
        .login-modal-container {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(37, 99, 235, 0.1);
            position: relative;
            overflow: hidden;
            animation: modalFadeInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 10;
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
        
        .login-logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem 2rem 1rem 2rem;
            margin-bottom: 0.5rem;
        }
        
        .login-logo-container img {
            max-width: 120px;
            height: auto;
            object-fit: contain;
            display: block;
        }
        
        .login-team-name {
            font-size: 1.875rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
            margin-top: 0;
            text-align: center;
            letter-spacing: -0.01em;
        }
        
        .login-modal-subtitle {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
            margin-bottom: 1.75rem;
        }
        
        .success-icon {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        
        .success-icon svg {
            width: 4rem;
            height: 4rem;
            color: #10b981;
        }
        
        .success-message {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .success-message p {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        
        .success-message strong {
            color: #1f2937;
            font-weight: 600;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .login-submit-button {
            width: 100%;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .login-submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
        }
        
        .login-submit-button:active {
            transform: translateY(0);
        }
        
        @media (max-width: 640px) {
            .login-modal-container {
                max-width: calc(100% - 2rem);
                border-radius: 14px;
            }
            
            .login-logo-container {
                padding: 1.5rem 1.5rem 0.75rem 1.5rem;
            }
            
            .login-logo-container img {
                max-width: 100px;
            }
            
            .login-team-name {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Background effects matching landing page -->
    <div class="aurora"></div>
    <div class="dot-grid"></div>
    <div class="orb orb--blue"></div>
    <div class="orb orb--violet"></div>
    
    <div class="login-modal-container">
        <div style="padding: 0 2.5rem 2.5rem 2.5rem;">
            <!-- Logo -->
            <div class="login-logo-container">
                <img src="<?php echo htmlspecialchars(base_url('assets/brand/logo.png')); ?>" alt="<?php echo htmlspecialchars($brand); ?> Logo" onerror="this.style.display='none'">
            </div>
            
            <?php if ($success): ?>
                <!-- Success State -->
                <div class="success-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                
                <h2 class="login-team-name">OTP Sent</h2>
                
                <div class="success-message">
                    <p>
                        We've sent a 6-digit OTP code to <strong><?php echo htmlspecialchars($email); ?></strong>
                    </p>
                    <p style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.5rem;">
                        The code will expire in 5 minutes.
                    </p>
                </div>
                
                <a href="verify_otp.php?email=<?php echo urlencode($email); ?>" class="login-submit-button">
                    Enter OTP Code
                </a>
            <?php else: ?>
                <!-- Error State -->
                <h2 class="login-team-name">Error</h2>
                
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <a href="forgot_password.php" class="login-submit-button">
                    Try Again
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
