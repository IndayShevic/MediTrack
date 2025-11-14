<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = false;
$tokenValid = false;
$email = '';

// Validate token
if (!empty($token)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT email, expires_at, used_at FROM password_reset_tokens WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $tokenData = $stmt->fetch();
        
        if ($tokenData) {
            if ($tokenData['used_at']) {
                $error = 'This password reset link has already been used. Please request a new one.';
            } elseif (strtotime($tokenData['expires_at']) < time()) {
                $error = 'This password reset link has expired. Please request a new one.';
            } else {
                $tokenValid = true;
                $email = $tokenData['email'];
            }
        } else {
            $error = 'Invalid password reset link. Please request a new one.';
        }
    } catch (Throwable $e) {
        error_log('Reset password token validation error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
} else {
    $error = 'No reset token provided.';
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword)) {
        $error = 'Password is required.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Update password
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
            $stmt->execute([$passwordHash, $email]);
            
            // Mark token as used
            $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?');
            $stmt->execute([$token]);
            
            $pdo->commit();
            $success = true;
            $tokenValid = false; // Prevent form from showing again
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Password reset error: ' . $e->getMessage());
            $error = 'An error occurred while resetting your password. Please try again.';
        }
    }
}

$brand = get_setting('brand_name', 'MediTrack');
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
        }
    </style>
</head>
<body>
    <main class="w-full max-w-md mx-auto p-6">
        <div class="mt-7 bg-white rounded-xl shadow-lg border-2 border-indigo-300">
            <div class="p-4 sm:p-7">
                <div class="text-center mb-6">
                    <h1 class="block text-2xl font-bold text-gray-800">Reset Password</h1>
                    <?php if ($success): ?>
                        <p class="mt-2 text-sm text-green-600">
                            Your password has been reset successfully!
                        </p>
                    <?php elseif ($error): ?>
                        <p class="mt-2 text-sm text-red-600">
                            <?php echo htmlspecialchars($error); ?>
                        </p>
                    <?php elseif ($tokenValid): ?>
                        <p class="mt-2 text-sm text-gray-600">
                            Enter your new password below.
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($success): ?>
                    <div class="text-center">
                        <div class="mb-4">
                            <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <a href="<?php echo htmlspecialchars(public_base_path() . 'index.php'); ?>" class="inline-flex justify-center items-center gap-2 rounded-md border border-transparent font-semibold bg-blue-500 text-white hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all text-sm px-4 py-3 w-full">
                            Go to Login
                        </a>
                    </div>
                <?php elseif ($tokenValid): ?>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="password" class="block text-sm font-bold ml-1 mb-2">New Password</label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="py-3 px-4 block w-full border-2 border-gray-200 rounded-md text-sm focus:border-blue-500 focus:ring-blue-500 shadow-sm" 
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                            <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long</p>
                        </div>

                        <div>
                            <label for="confirm_password" class="block text-sm font-bold ml-1 mb-2">Confirm Password</label>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="py-3 px-4 block w-full border-2 border-gray-200 rounded-md text-sm focus:border-blue-500 focus:ring-blue-500 shadow-sm" 
                                required
                                minlength="8"
                                autocomplete="new-password"
                            >
                        </div>

                        <button type="submit" class="py-3 px-4 inline-flex justify-center items-center gap-2 rounded-md border border-transparent font-semibold bg-blue-500 text-white hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all text-sm w-full">
                            Reset Password
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center">
                        <a href="<?php echo htmlspecialchars(public_base_path() . 'index.php'); ?>" class="inline-flex justify-center items-center gap-2 rounded-md border border-transparent font-semibold bg-blue-500 text-white hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all text-sm px-4 py-3 w-full">
                            Back to Home
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>

