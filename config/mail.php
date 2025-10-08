<?php
declare(strict_types=1);
// Try to load PHPMailer via Composer or bundled sources
$autoloadTried = false;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $auto) {
    if (file_exists($auto)) { require_once $auto; $autoloadTried = true; break; }
}
if (!$autoloadTried) {
    // Fallback to local phpmailer directory if user copied it manually
    $fallbackDirs = [__DIR__ . '/../phpmailer/src', __DIR__ . '/../../phpmailer/src'];
    foreach ($fallbackDirs as $dir) {
        if (file_exists($dir . '/PHPMailer.php')) {
            require_once $dir . '/PHPMailer.php';
            require_once $dir . '/SMTP.php';
            require_once $dir . '/Exception.php';
            $autoloadTried = true;
            break;
        }
    }
}
if (!$autoloadTried) {
    // Last resort: soft error to help user set up PHPMailer
    error_log('PHPMailer not found. Install with "composer require phpmailer/phpmailer" or place PHPMailer in phpmailer/src.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    // SMTP config - adjust to your SMTP server (Gmail, Mailtrap, etc.)
    $mail->isSMTP();
    $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = getenv('SMTP_USER') ?: 's2peed5@gmail.com';
    $mail->Password = getenv('SMTP_PASS') ?: 'bhju elge uiom exjb';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
    $mail->CharSet = 'UTF-8';
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
    // Derive brand name and optional from address
    require_once __DIR__ . '/db.php';
    $brand = get_setting('brand_name', 'MediTrack');
    $from = getenv('SMTP_FROM') ?: $mail->Username;
    $mail->setFrom($from, $brand);
    return $mail;
}

function send_email(string $toEmail, string $toName, string $subject, string $html): bool {
    try {
        // First try SMTP
        $mail = mailer();
        if ((getenv('SMTP_DEBUG') ?: '') === '1') {
            $mail->SMTPDebug = 2; // verbose debug output in server logs
        }
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = strip_tags($html);
        $ok = $mail->send();
        if ($ok) {
            log_email($toEmail, $subject, $html, 'sent', null);
            return true;
        } else {
            log_email($toEmail, $subject, $html, 'failed', $mail->ErrorInfo ?: 'send() returned false');
        }
    } catch (Throwable $e) {
        log_email($toEmail, $subject, $html, 'failed', $e->getMessage());
    }
    
    // Fallback: Try PHP's built-in mail() function
    try {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: MediTrack <noreply@meditrack.local>\r\n";
        $headers .= "Reply-To: noreply@meditrack.local\r\n";
        
        $success = mail($toEmail, $subject, $html, $headers);
        if ($success) {
            log_email($toEmail, $subject, $html, 'sent (fallback)', null);
            return true;
        } else {
            log_email($toEmail, $subject, $html, 'failed (fallback)', 'PHP mail() function failed');
        }
    } catch (Throwable $e) {
        log_email($toEmail, $subject, $html, 'failed (fallback)', $e->getMessage());
    }
    
    return false;
}

function log_email(string $recipient, string $subject, string $body, string $status, ?string $error): void {
    require_once __DIR__ . '/db.php';
    try {
        $stmt = db()->prepare('INSERT INTO email_logs(recipient, subject, body, status, error) VALUES(?,?,?,?,?)');
        $stmt->execute([$recipient, $subject, $body, $status, $error]);
    } catch (Throwable $e) {}
}

// Fancy, responsive HTML email wrapper
function email_template(string $title, string $lead, string $bodyHtml, ?string $ctaLabel = null, ?string $ctaUrl = null): string {
    $btn = '';
    if ($ctaLabel && $ctaUrl) {
        $btn = '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td class="btn"><a href="' . htmlspecialchars($ctaUrl) . '">' . htmlspecialchars($ctaLabel) . '</a></td></tr></table>';
    }
    $year = date('Y');
    $app = 'MediTrack';
    return '<!doctype html>
<html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"/><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<title>' . htmlspecialchars($title) . '</title>
<style>body{background-color:#f7f7fb;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#111827} .container{max-width:640px;margin:0 auto;padding:24px} .card{background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.1);overflow:hidden} .header{background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);padding:20px 24px;color:#fff} .brand{font-weight:700;font-size:18px} .title{font-size:20px;margin:0} .content{padding:24px} p{line-height:1.6;margin:0 0 12px} .lead{font-size:16px;color:#374151;margin-bottom:16px} .divider{height:1px;background:#e5e7eb;margin:16px 0} .btn a{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600} .muted{color:#6b7280;font-size:12px;margin-top:12px} @media (prefers-color-scheme: dark){ body{background:#0b1220;color:#e5e7eb} .card{background:#111827;box-shadow:none} .header{background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%)} .lead{color:#9ca3af} .divider{background:#1f2937} .muted{color:#9ca3af} }</style></head>
<body>
  <div class="container">
    <div class="card">
      <div class="header"><div class="brand">' . $app . '</div><h1 class="title">' . htmlspecialchars($title) . '</h1></div>
      <div class="content">
        <p class="lead">' . htmlspecialchars($lead) . '</p>
        <div>' . $bodyHtml . '</div>
        ' . $btn . '
        <div class="divider"></div>
        <p class="muted">This is an automated message from ' . $app . '. Please do not reply.</p>
      </div>
    </div>
    <p class="muted" style="text-align:center">© ' . $year . ' ' . $app . '</p>
  </div>
</body></html>';
}


