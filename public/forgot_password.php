<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

$brand = get_setting('brand_name', 'MediTrack');
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    // Step 1: Validate email - required
    if (empty($email)) {
        $error = 'Email is required';
    }
    // Step 2: Validate email format
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    }
    // Step 3: Check if email exists in users table
    else {
        try {
            $pdo = db();
            
            // Ensure OTP table exists
            ensure_otp_table_exists($pdo);
            
            $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // Step 3a: If email does NOT exist → show 'Email not registered'
                $error = 'Email not registered';
            } else {
                // Email exists - redirect to send OTP
                header('Location: send_otp.php?email=' . urlencode($email));
                exit;
            }
        } catch (Throwable $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $error = 'An error occurred. Please try again later.';
        }
    }
}

// Helper function to ensure OTP table exists
function ensure_otp_table_exists(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'password_reset_otps'");
        if ($stmt->rowCount() > 0) {
            return true;
        }
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_otps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL,
                otp_code VARCHAR(6) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_otp_code (otp_code),
                INDEX idx_expires (expires_at),
                INDEX idx_email_otp (email, otp_code)
            ) ENGINE=InnoDB
        ");
        return true;
    } catch (Throwable $e) {
        error_log('Failed to create password_reset_otps table: ' . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($brand); ?></title>
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
                    <h1 class="block text-2xl font-bold text-gray-800">Forgot Password</h1>
                    <p class="mt-2 text-sm text-gray-600">
                        Enter your registered email address to receive an OTP code.
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-bold ml-1 mb-2">Email Address</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            class="py-3 px-4 block w-full border-2 border-gray-200 rounded-md text-sm focus:border-blue-500 focus:ring-blue-500 shadow-sm" 
                            required
                            autocomplete="email"
                            placeholder="Enter your registered email"
                        >
                    </div>

                    <button type="submit" class="py-3 px-4 inline-flex justify-center items-center gap-2 rounded-md border border-transparent font-semibold bg-blue-500 text-white hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all text-sm w-full">
                        Send OTP Code
                    </button>
                </form>

                <div class="mt-4 text-center">
                    <a href="<?php echo htmlspecialchars(public_base_path() . 'index.php'); ?>" class="text-sm text-blue-600 hover:text-blue-700">
                        Back to Login
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
