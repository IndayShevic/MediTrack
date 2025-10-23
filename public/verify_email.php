<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_notifications.php';

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$email || !$code) {
        set_flash('Email and code are required.','error');
        redirect_to('verify_email.php?email=' . urlencode($email));
    }

    try {
        $stmt = db()->prepare('SELECT id, email_verification_code, email_verification_expires_at, email_verified FROM pending_residents WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row) {
            set_flash('No pending registration found for that email.','error');
            redirect_to('register.php');
        }

        if ((int)$row['email_verified'] === 1) {
            set_flash('Email already verified. You can wait for BHW approval.','success');
            redirect_to('index.php');
        }

        if (empty($row['email_verification_code']) || strtoupper($code) !== strtoupper((string)$row['email_verification_code'])) {
            set_flash('Invalid verification code.','error');
            redirect_to('verify_email.php?email=' . urlencode($email));
        }

        if (!empty($row['email_verification_expires_at'])) {
            $now = new DateTimeImmutable('now');
            $exp = new DateTimeImmutable($row['email_verification_expires_at']);
            if ($now > $exp) {
                set_flash('Verification code has expired. Please resend a new code.','error');
                redirect_to('verify_email.php?email=' . urlencode($email));
            }
        }

        $upd = db()->prepare('UPDATE pending_residents SET email_verified = 1, email_verification_code = NULL, email_verification_expires_at = NULL WHERE id = ?');
        $upd->execute([(int)$row['id']]);

        set_flash('Email verified! Your registration is now pending BHW approval.','success');
        redirect_to('index.php');
    } catch (Throwable $e) {
        set_flash('Verification failed due to a system error.','error');
        redirect_to('verify_email.php?email=' . urlencode($email));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify Email · MediTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .form-input { @apply w-full border border-gray-300 rounded-lg px-4 py-3 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all duration-200; }
        .btn-primary { @apply w-full bg-blue-600 text-white py-3 px-4 rounded-lg font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all duration-200; }
    </style>
    <?php [$flash,$ft] = get_flash(); ?>
</head>
<body class="min-h-screen bg-gray-50 font-sans">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Verify your email</h1>
                <p class="text-gray-600 mt-1">We sent a 6-digit code to <?php echo htmlspecialchars($email ?: 'your email'); ?>.</p>
            </div>

            <?php if (!empty($flash)): ?>
                <div class="mb-4 p-3 rounded <?php echo $ft==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?>">
                    <?php echo htmlspecialchars($flash); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>" />
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Verification Code</label>
                    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required class="form-input" placeholder="Enter 6-digit code" />
                </div>
                <button type="submit" class="btn-primary">Verify</button>
            </form>

            <div class="mt-4 text-center">
                <form method="post" action="resend_verification.php" class="inline">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>" />
                    <button class="text-blue-600 hover:text-blue-700 text-sm font-medium" type="submit">Resend code</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>


