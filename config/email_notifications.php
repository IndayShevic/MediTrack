<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

function send_email_verification_code(string $email, string $name, string $code): bool {
    $subject = 'Verify your email - MediTrack';
    $verifyUrl = base_url('verify_email.php?email=' . urlencode($email));
    $html = email_template(
        'Verify your email address',
        'Use the code below to verify your email and complete your registration.',
        '<div style="font-size:14px;color:#111827">'
        . '<p>Hello ' . htmlspecialchars($name) . ',</p>'
        . '<p>Your verification code is:</p>'
        . '<div style="font-size:28px;font-weight:700;letter-spacing:4px;margin:12px 0;padding:12px 16px;background:#f3f4f6;border-radius:8px;text-align:center">'
        . htmlspecialchars($code)
        . '</div>'
        . '<p>This code will expire in 15 minutes. If you did not request this, you can ignore this email.</p>'
        . '</div>',
        'Verify Email',
        $verifyUrl
    );
    return send_email($email, $name, $subject, $html);
}

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

function send_announcement_notification_to_all_users(string $title, string $description, string $start_date, string $end_date): array {
    $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
    
    try {
        // Get all users (BHW and Residents) with email addresses
        $users = db()->query('SELECT id, name, email, role FROM users WHERE email IS NOT NULL AND email != "" AND role IN ("bhw", "resident")')->fetchAll();
        
        foreach ($users as $user) {
            $success = send_announcement_email($user['email'], $user['name'], $user['role'], $title, $description, $start_date, $end_date);
            
            if ($success) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to send to {$user['email']}";
            }
        }
        
    } catch (Throwable $e) {
        $results['errors'][] = "Database error: " . $e->getMessage();
    }
    
    return $results;
}

function send_announcement_email(string $email, string $name, string $role, string $title, string $description, string $start_date, string $end_date): bool {
    $subject = 'New Health Center Announcement - MediTrack';
    
    // Format dates
    $startFormatted = date('F j, Y', strtotime($start_date));
    $endFormatted = date('F j, Y', strtotime($end_date));
    $dateRange = $start_date === $end_date ? $startFormatted : "$startFormatted to $endFormatted";
    
    // Role-specific content
    $roleGreeting = $role === 'bhw' ? 'Barangay Health Worker' : 'Resident';
    $actionText = $role === 'bhw' ? 'Please inform residents in your area about this activity.' : 'Please mark your calendar and prepare any necessary requirements.';
    
    $html = email_template(
        'New Health Center Announcement',
        'Important health center activity announcement',
        '<p>Hello ' . htmlspecialchars($name) . ',</p>
        <p>We are pleased to inform you about an upcoming health center activity.</p>
        
        <div style="background: #f8fafc; border-left: 4px solid #3b82f6; padding: 16px; margin: 16px 0; border-radius: 4px;">
            <h3 style="margin: 0 0 8px 0; color: #1f2937;">' . htmlspecialchars($title) . '</h3>
            <p style="margin: 0 0 8px 0; color: #374151;">' . htmlspecialchars($description) . '</p>
            <p style="margin: 0; color: #6b7280; font-size: 14px;">
                <strong>Date:</strong> ' . $dateRange . '<br>
                <strong>For:</strong> ' . $roleGreeting . 's
            </p>
        </div>
        
        <p>' . $actionText . '</p>
        <p>For questions or clarifications, please contact your health center or Barangay Health Worker.</p>',
        'View All Announcements',
        base_url($role === 'bhw' ? 'bhw/announcements.php' : 'resident/announcements.php')
    );
    
    return send_email($email, $name, $subject, $html);
}

function log_email_notification(int $user_id, string $type, string $subject, string $body, bool $success): void {
    try {
        $stmt = db()->prepare('INSERT INTO email_notifications (user_id, notification_type, subject, body, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $type, $subject, $body, $success ? 'sent' : 'failed']);
    } catch (Throwable $e) {
        // Log error silently
    }
}
