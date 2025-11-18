<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

// Set timezone to Asia/Manila for PHP
date_default_timezone_set('Asia/Manila');

$brand = get_setting('brand_name', 'MediTrack');
$email = trim($_GET['email'] ?? '');
$error = '';
$otpValid = false;

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

// Handle OTP verification form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    // Validate: required + must be 6 digits
    if (empty($otp)) {
        $error = 'OTP code is required';
    } elseif (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'OTP must be exactly 6 digits';
    } else {
        try {
            $pdo = db();
            
            // Set MySQL timezone to match PHP (Asia/Manila = UTC+8)
            $pdo->exec("SET time_zone = '+08:00'");
            
            if (!ensure_otp_table_exists($pdo)) {
                throw new Exception('Database configuration error');
            }
            
            // Get current time in Asia/Manila timezone for comparison
            $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
            $nowFormatted = $now->format('Y-m-d H:i:s');
            
            // Log for debugging
            error_log("OTP Verification Attempt - Email: {$email}, OTP: {$otp}, Current Time (PHP): {$nowFormatted}");
            
            // First, check if OTP exists for this email and code (without expiration check)
            $checkStmt = $pdo->prepare('
                SELECT id, expires_at, used_at, created_at 
                FROM password_reset_otps 
                WHERE email = ? AND otp_code = ?
                ORDER BY created_at DESC 
                LIMIT 1
            ');
            $checkStmt->execute([$email, $otp]);
            $otpData = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$otpData) {
                error_log("OTP Verification Failed - No matching OTP found for email: {$email}, OTP: {$otp}");
                $error = 'Incorrect or expired code';
            } else {
                // Check if already used
                if (!empty($otpData['used_at'])) {
                    error_log("OTP Verification Failed - OTP already used. ID: {$otpData['id']}, Used At: {$otpData['used_at']}");
                    $error = 'Incorrect or expired code';
                } else {
                    // Check expiration - compare DATETIME values
                    $expiresAt = new DateTime($otpData['expires_at'], new DateTimeZone('Asia/Manila'));
                    
                    error_log("OTP Verification Check - OTP ID: {$otpData['id']}, Expires At (DB): {$otpData['expires_at']}, Expires At (Parsed): {$expiresAt->format('Y-m-d H:i:s')}, Current Time: {$nowFormatted}");
                    
                    if ($now > $expiresAt) {
                        error_log("OTP Verification Failed - OTP expired. Current: {$nowFormatted}, Expires: {$expiresAt->format('Y-m-d H:i:s')}");
                        $error = 'Incorrect or expired code';
                    } else {
                        // OTP is valid - mark as used and redirect to reset password
                        $updateStmt = $pdo->prepare('UPDATE password_reset_otps SET used_at = ? WHERE id = ?');
                        $updateStmt->execute([$nowFormatted, $otpData['id']]);
                        
                        error_log("OTP Verification Success - OTP ID: {$otpData['id']} verified and marked as used");
                        
                        // Store email in session for reset password page
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start();
                        }
                        $_SESSION['reset_password_email'] = $email;
                        $_SESSION['otp_verified'] = true;
                        
                        header('Location: reset_password.php');
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('OTP verification error: ' . $e->getMessage());
            error_log('OTP verification error trace: ' . $e->getTraceAsString());
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - <?php echo htmlspecialchars($brand); ?></title>
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
        
        .login-input-wrapper {
            position: relative;
            margin-bottom: 1.25rem;
        }
        
        .login-input-label {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.875rem;
            color: #9ca3af;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 1;
            background: #ffffff;
        }
        
        .login-input-field {
            width: 100%;
            padding: 0.75rem 0.875rem;
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            text-align: center;
            font-weight: 600;
            color: #111827;
            transition: all 0.2s ease;
            outline: none;
            line-height: 1.5;
        }
        
        .login-input-field:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .login-input-wrapper:has(.login-input-field:focus) .login-input-label,
        .login-input-wrapper:has(.login-input-field:not(:placeholder-shown)) .login-input-label,
        .login-input-wrapper.focused .login-input-label,
        .login-input-wrapper.has-value .login-input-label {
            top: -0.5rem;
            transform: translateY(0);
            left: 0.625rem;
            font-size: 0.6875rem;
            color: #3b82f6;
            background: #ffffff;
            padding: 0 0.25rem;
        }
        
        .login-input-field::placeholder {
            color: transparent;
        }
        
        .login-error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            line-height: 1.5;
        }
        
        .login-error-message.hidden {
            display: none;
        }
        
        .login-error-message svg {
            width: 1.125rem;
            height: 1.125rem;
            flex-shrink: 0;
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
        }
        
        .login-submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
        }
        
        .login-submit-button:active {
            transform: translateY(0);
        }
        
        .otp-help-text {
            font-size: 0.75rem;
            color: #6b7280;
            text-align: center;
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .otp-links {
            text-align: center;
            margin-top: 1rem;
        }
        
        .otp-links a {
            display: block;
            font-size: 0.875rem;
            color: #3b82f6;
            text-decoration: none;
            margin-bottom: 0.5rem;
            transition: color 0.2s ease;
        }
        
        .otp-links a:hover {
            color: #2563eb;
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
            
            <!-- Welcome Title -->
            <h2 class="login-team-name">Verify OTP</h2>
            
            <!-- Subtitle -->
            <p class="login-modal-subtitle">
                Enter the 6-digit OTP code sent to <strong><?php echo htmlspecialchars($email); ?></strong>
            </p>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="login-error-message">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- OTP Form -->
            <form method="POST" id="otpForm">
                <div class="login-input-wrapper">
                    <label for="otp" class="login-input-label">OTP Code</label>
                    <input 
                        type="text" 
                        id="otp" 
                        name="otp" 
                        class="login-input-field" 
                        required
                        maxlength="6"
                        pattern="[0-9]{6}"
                        placeholder="000000"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                    >
                </div>
                <p class="otp-help-text">Enter the 6-digit code from your email</p>

                <button type="submit" class="login-submit-button">
                    Verify OTP
                </button>
            </form>

            <div class="otp-links">
                <a href="send_otp.php?email=<?php echo urlencode($email); ?>">
                    Resend OTP Code
                </a>
                <a href="forgot_password.php">
                    Back to Forgot Password
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-format OTP input (only numbers, max 6 digits)
        const otpInput = document.getElementById('otp');
        const otpWrapper = otpInput.closest('.login-input-wrapper');
        
        if (otpInput && otpWrapper) {
            // Handle focus
            otpInput.addEventListener('focus', function() {
                otpWrapper.classList.add('focused');
            });
            
            otpInput.addEventListener('blur', function() {
                if (!this.value) {
                    otpWrapper.classList.remove('focused', 'has-value');
                }
            });
            
            // Handle input
            otpInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
                if (this.value) {
                    otpWrapper.classList.add('has-value');
                } else {
                    otpWrapper.classList.remove('has-value');
                }
            });
            
            // Focus on load
            setTimeout(() => otpInput.focus(), 100);
        }
    </script>
</body>
</html>
