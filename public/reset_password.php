<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$brand = get_setting('brand_name', 'MediTrack');
$error = '';
$success = false;
$email = '';

// Check if OTP was verified
if (!isset($_SESSION['otp_verified']) || !$_SESSION['otp_verified']) {
    header('Location: forgot_password.php?error=Please verify your OTP first');
    exit;
}

$email = $_SESSION['reset_password_email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.php?error=Invalid session');
    exit;
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate: required
    if (empty($newPassword)) {
        $error = 'Password is required';
    }
    // Validate: match
    elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    }
    // Validate: minimum strength (optional - minimum 8 characters)
    elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Hash the new password using password_hash
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update the password in the users table
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
            $stmt->execute([$passwordHash, $email]);
            
            // Delete/expire the OTP after successful reset
            $stmt = $pdo->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE email = ? AND used_at IS NULL');
            $stmt->execute([$email]);
            
            $pdo->commit();
            
            // Clear session
            unset($_SESSION['otp_verified']);
            unset($_SESSION['reset_password_email']);
            
            $success = true;
            
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Password reset error: ' . $e->getMessage());
            $error = 'An error occurred while resetting your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars($brand); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            padding: 1rem;
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
            padding: 0.75rem 3rem 0.75rem 0.875rem;
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.875rem;
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
        
        .login-input-wrapper:has(.login-password-toggle) .login-input-field {
            padding-right: 3rem;
        }
        
        .login-password-toggle {
            position: absolute;
            right: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease;
            z-index: 2;
        }
        
        .login-password-toggle:hover {
            color: #3b82f6;
        }
        
        .login-password-toggle svg {
            width: 1.25rem;
            height: 1.25rem;
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
        
        .password-help-text {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }
        
        .back-link {
            text-align: center;
            margin-top: 1rem;
        }
        
        .back-link a {
            font-size: 0.875rem;
            color: #6b7280;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .back-link a:hover {
            color: #3b82f6;
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                
                <h2 class="login-team-name">Password Reset</h2>
                
                <p class="login-modal-subtitle">
                    Your password has been reset successfully!
                </p>
                
                <a href="../index.php?login=1" class="login-submit-button">
                    Go to Login
                </a>
            <?php else: ?>
                <!-- Reset Password Form -->
                <h2 class="login-team-name">Reset Password</h2>
                
                <p class="login-modal-subtitle">
                    Enter your new password below.
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

                <form method="POST" id="resetForm">
                    <div class="login-input-wrapper">
                        <label for="password" class="login-input-label">New Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="login-input-field" 
                            required
                            minlength="8"
                            autocomplete="new-password"
                            placeholder=" "
                        >
                    </div>
                    <p class="password-help-text">Must be at least 8 characters long</p>

                    <div class="login-input-wrapper">
                        <label for="confirm_password" class="login-input-label">Confirm Password</label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="login-input-field" 
                            required
                            minlength="8"
                            autocomplete="new-password"
                            placeholder=" "
                        >
                    </div>

                    <button type="submit" class="login-submit-button">
                        Reset Password
                    </button>
                </form>
                
                <div class="back-link">
                    <a href="forgot_password.php">Back to Forgot Password</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Password match validation and floating label support
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const form = document.querySelector('form');
        
        if (form && passwordInput && confirmPasswordInput) {
            const passwordWrapper = passwordInput.closest('.login-input-wrapper');
            const confirmPasswordWrapper = confirmPasswordInput.closest('.login-input-wrapper');
            
            // Handle floating labels
            [passwordInput, confirmPasswordInput].forEach(input => {
                const wrapper = input.closest('.login-input-wrapper');
                input.addEventListener('focus', () => wrapper.classList.add('focused'));
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        wrapper.classList.remove('focused', 'has-value');
                    } else {
                        wrapper.classList.add('has-value');
                    }
                });
                input.addEventListener('input', function() {
                    if (this.value) {
                        wrapper.classList.add('has-value');
                    } else {
                        wrapper.classList.remove('has-value');
                    }
                });
            });
            
            function validatePasswords() {
                if (confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value) {
                    confirmPasswordInput.setCustomValidity('Passwords do not match');
                } else {
                    confirmPasswordInput.setCustomValidity('');
                }
            }
            
            passwordInput.addEventListener('input', validatePasswords);
            confirmPasswordInput.addEventListener('input', validatePasswords);
        }
    </script>
</body>
</html>
