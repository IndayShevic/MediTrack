<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

function send_registration_approval_email(string $email, string $name): bool {
    $subject = 'Registration Approved - MediTrack';
    $html = email_template(
        'Registration Approved',
        'Your resident registration has been approved!',
        '<p>Hello ' . htmlspecialchars($name) . ',</p>
        <p>Your registration as a resident has been approved by your assigned BHW. You can now log in to your account and start requesting medicines.</p>
        <p>Please keep your login credentials safe and do not share them with others.</p>',
        'Login to MediTrack',
        base_url('index.php')
    );
    
    return send_email($email, $name, $subject, $html);
}

function send_registration_rejection_email(string $email, string $name, string $reason): bool {
    $subject = 'Registration Rejected - MediTrack';
    $html = email_template(
        'Registration Rejected',
        'Your resident registration has been rejected.',
        '<p>Hello ' . htmlspecialchars($name) . ',</p>
        <p>Unfortunately, your registration as a resident has been rejected by your assigned BHW.</p>
        <p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>
        <p>You may contact your BHW for more information or submit a new registration with corrected information.</p>',
        'Contact Support',
        base_url('index.php')
    );
    
    return send_email($email, $name, $subject, $html);
}

function send_new_registration_notification_to_bhw(string $bhw_email, string $bhw_name, string $resident_name, string $purok_name): bool {
    $subject = 'New Resident Registration - MediTrack';
    $html = email_template(
        'New Registration Request',
        'A new resident has registered in your purok.',
        '<p>Hello ' . htmlspecialchars($bhw_name) . ',</p>
        <p>A new resident registration is pending your approval in ' . htmlspecialchars($purok_name) . '.</p>
        <p><strong>Resident:</strong> ' . htmlspecialchars($resident_name) . '</p>
        <p>Please review the registration details and approve or reject the request.</p>',
        'Review Registration',
        base_url('bhw/pending_residents.php')
    );
    
    return send_email($bhw_email, $bhw_name, $subject, $html);
}

function send_medicine_request_notification_to_bhw(string $bhw_email, string $bhw_name, string $resident_name, string $medicine_name): bool {
    $subject = 'New Medicine Request - MediTrack';
    $html = email_template(
        'New Medicine Request',
        'A resident has submitted a new medicine request.',
        '<p>Hello ' . htmlspecialchars($bhw_name) . ',</p>
        <p>A new medicine request has been submitted by ' . htmlspecialchars($resident_name) . '.</p>
        <p><strong>Medicine:</strong> ' . htmlspecialchars($medicine_name) . '</p>
        <p>Please review the request and approve or reject it.</p>',
        'Review Request',
        base_url('bhw/requests.php')
    );
    
    return send_email($bhw_email, $bhw_name, $subject, $html);
}

function send_medicine_request_approval_to_resident(string $resident_email, string $resident_name, string $medicine_name): bool {
    $subject = 'Medicine Request Approved - MediTrack';
    $html = email_template(
        'Request Approved',
        'Your medicine request has been approved!',
        '<p>Hello ' . htmlspecialchars($resident_name) . ',</p>
        <p>Your request for ' . htmlspecialchars($medicine_name) . ' has been approved by your BHW.</p>
        <p>You can now visit the health center to claim your medicine. Please bring a valid ID.</p>',
        'View Request',
        base_url('resident/requests.php')
    );
    
    return send_email($resident_email, $resident_name, $subject, $html);
}

function send_medicine_request_rejection_to_resident(string $resident_email, string $resident_name, string $medicine_name, string $reason): bool {
    $subject = 'Medicine Request Rejected - MediTrack';
    $html = email_template(
        'Request Rejected',
        'Your medicine request has been rejected.',
        '<p>Hello ' . htmlspecialchars($resident_name) . ',</p>
        <p>Unfortunately, your request for ' . htmlspecialchars($medicine_name) . ' has been rejected.</p>
        <p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>
        <p>You may contact your BHW for more information or submit a new request with additional documentation.</p>',
        'View Request',
        base_url('resident/requests.php')
    );
    
    return send_email($resident_email, $resident_name, $subject, $html);
}

function log_email_notification(int $user_id, string $type, string $subject, string $body, bool $success): void {
    try {
        $stmt = db()->prepare('INSERT INTO email_notifications (user_id, notification_type, subject, body, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $type, $subject, $body, $success ? 'sent' : 'failed']);
    } catch (Throwable $e) {
        // Log error silently
    }
}
