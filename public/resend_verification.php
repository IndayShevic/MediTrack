<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$email = trim($_POST['email'] ?? '');
if (!$email) {
    set_flash('Email is required to resend the code.','error');
    redirect_to('register.php');
}

try {
    $stmt = db()->prepare('SELECT id, first_name, last_name, COALESCE(middle_initial, "") AS middle_initial, email_verified FROM pending_residents WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        set_flash('No pending registration found for that email.','error');
        redirect_to('register.php');
    }
    if ((int)$row['email_verified'] === 1) {
        set_flash('Email already verified.','success');
        redirect_to('index.php');
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
    $upd = db()->prepare('UPDATE pending_residents SET email_verification_code = ?, email_verification_expires_at = ? WHERE id = ?');
    $upd->execute([$code, $expiresAt, (int)$row['id']]);

    $name = format_full_name($row['first_name'] ?? '', $row['last_name'] ?? '', $row['middle_initial'] ?? '');
    send_email_verification_code($email, $name, $code);

    set_flash('A new verification code has been sent.','success');
    redirect_to('verify_email.php?email=' . urlencode($email));
} catch (Throwable $e) {
    set_flash('Failed to resend verification code.','error');
    redirect_to('verify_email.php?email=' . urlencode($email));
}


