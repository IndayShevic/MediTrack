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

function send_announcement_notification_to_all_users(string $title, string $description, string $start_date, string $end_date, ?int $announcement_id = null, bool $is_update = false, ?array $old_data = null, ?string $start_time = null, ?string $end_time = null): array {
    $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
    
    try {
        // Build base query - get users with email addresses and construct name from first_name and last_name
        // For residents, get barangay_id from residents table
        // For BHW, get barangay_id from puroks table via purok_id
        $baseQuery = 'SELECT DISTINCT u.id, 
                             CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) AS name,
                             u.email, 
                             u.role,
                             u.purok_id,
                             COALESCE(r.barangay_id, p.barangay_id) AS barangay_id,
                             COALESCE(r.purok_id, u.purok_id) AS user_purok_id
                      FROM users u
                      LEFT JOIN residents r ON r.user_id = u.id AND u.role = "resident"
                      LEFT JOIN puroks p ON p.id = u.purok_id AND u.role = "bhw"
                      WHERE u.email IS NOT NULL AND u.email != "" AND u.role IN ("bhw", "resident")';
        
        $params = [];
        
        // If announcement_id is provided, apply targeting filters and get times
        if ($announcement_id) {
            $announcement = db()->prepare('SELECT target_audience, target_barangay_id, target_purok_id, start_time, end_time FROM announcements WHERE id = ?');
            $announcement->execute([$announcement_id]);
            $ann = $announcement->fetch();
            
            // Use times from database if not provided
            if ($ann && $start_time === null) {
                $start_time = $ann['start_time'];
            }
            if ($ann && $end_time === null) {
                $end_time = $ann['end_time'];
            }
            
            if ($ann) {
                // Filter by target audience
                if ($ann['target_audience'] === 'residents') {
                    $baseQuery .= ' AND u.role = "resident"';
                } elseif ($ann['target_audience'] === 'bhw') {
                    $baseQuery .= ' AND u.role = "bhw"';
                }
                // If 'all', no role filter needed
                
                // Filter by barangay
                if (!empty($ann['target_barangay_id'])) {
                    $baseQuery .= ' AND COALESCE(r.barangay_id, p.barangay_id) = ?';
                    $params[] = $ann['target_barangay_id'];
                    
                    // Filter by purok if specified
                    if (!empty($ann['target_purok_id'])) {
                        $baseQuery .= ' AND COALESCE(r.purok_id, u.purok_id) = ?';
                        $params[] = $ann['target_purok_id'];
                    }
                }
            }
        }
        
        // Execute query with parameters
        $stmt = db()->prepare($baseQuery);
        if (!empty($params)) {
            $stmt->execute($params);
        } else {
            $stmt->execute();
        }
        $users = $stmt->fetchAll();
        
        if (empty($users)) {
            $results['errors'][] = "No users found matching the announcement criteria. Please check that users have valid email addresses and match the target audience/location settings.";
            error_log("Announcement email: No users found. Query: " . $baseQuery . " | Params: " . json_encode($params));
            return $results;
        }
        
        error_log("Announcement email: Found " . count($users) . " users to notify");
        
        foreach ($users as $user) {
            // Skip if name is empty (users without first_name and last_name)
            $name = trim($user['name']);
            if (empty($name)) {
                $name = 'User'; // Fallback name
            }
            
            $success = send_announcement_email($user['email'], $name, $user['role'], $title, $description, $start_date, $end_date, $start_time, $end_time, $is_update, $old_data);
            
            if ($success) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to send to {$user['email']}";
            }
        }
        
    } catch (Throwable $e) {
        $results['errors'][] = "Database error: " . $e->getMessage();
        error_log("Announcement email error: " . $e->getMessage());
    }
    
    return $results;
}

function send_announcement_email(string $email, string $name, string $role, string $title, string $description, string $start_date, string $end_date, ?string $start_time = null, ?string $end_time = null, bool $is_update = false, ?array $old_data = null): bool {
    // Determine subject and header based on whether it's an update or new announcement
    if ($is_update) {
        $subject = 'Announcement Updated - MediTrack';
        $headerTitle = 'Announcement Updated';
        $headerLead = 'There has been an update to a health center activity announcement.';
    } else {
        $subject = 'New Health Center Announcement - MediTrack';
        $headerTitle = 'New Health Center Announcement';
        $headerLead = 'Important health center activity announcement';
    }
    
    // Helper function to format time (HH:MM to 12-hour format)
    $formatTime = function(?string $time): string {
        if (!$time) return '';
        $parts = explode(':', $time);
        $hour = (int)$parts[0];
        $minute = $parts[1] ?? '00';
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12 ?: 12;
        return sprintf('%d:%s %s', $hour12, $minute, $ampm);
    };
    
    // Format dates with times if available
    $startFormatted = date('F j, Y', strtotime($start_date));
    $endFormatted = date('F j, Y', strtotime($end_date));
    
    if ($start_time) {
        $startFormatted .= ' at ' . $formatTime($start_time);
    }
    if ($end_time) {
        $endFormatted .= ' at ' . $formatTime($end_time);
    }
    
    $dateRange = $start_date === $end_date ? $startFormatted : "$startFormatted to $endFormatted";
    
    // Role-specific content
    $roleGreeting = $role === 'bhw' ? 'Barangay Health Worker' : 'Resident';
    $actionText = $role === 'bhw' ? 'Please inform residents in your area about this activity.' : 'Please mark your calendar and prepare any necessary requirements.';
    
    // Build change notification if it's an update
    $changeNotice = '';
    if ($is_update && $old_data) {
        $changes = [];
        
        // Check for title changes
        if (isset($old_data['title']) && $old_data['title'] !== $title) {
            $changes[] = '<strong>Title:</strong> Changed from "' . htmlspecialchars($old_data['title']) . '" to "' . htmlspecialchars($title) . '"';
        }
        
        // Check for date changes
        if (isset($old_data['start_date']) && $old_data['start_date'] !== $start_date) {
            $oldStartFormatted = date('F j, Y', strtotime($old_data['start_date']));
            if (isset($old_data['start_time']) && $old_data['start_time']) {
                $oldStartFormatted .= ' at ' . $formatTime($old_data['start_time']);
            }
            $changes[] = '<strong>Start Date:</strong> Changed from ' . $oldStartFormatted . ' to ' . $startFormatted;
        } elseif (isset($old_data['start_time']) && $old_data['start_time'] !== $start_time) {
            $oldStartFormatted = date('F j, Y', strtotime($start_date));
            if ($old_data['start_time']) {
                $oldStartFormatted .= ' at ' . $formatTime($old_data['start_time']);
            }
            $newStartFormatted = date('F j, Y', strtotime($start_date));
            if ($start_time) {
                $newStartFormatted .= ' at ' . $formatTime($start_time);
            }
            $changes[] = '<strong>Start Time:</strong> Changed from ' . ($old_data['start_time'] ? $formatTime($old_data['start_time']) : 'All Day') . ' to ' . ($start_time ? $formatTime($start_time) : 'All Day');
        }
        
        if (isset($old_data['end_date']) && $old_data['end_date'] !== $end_date) {
            $oldEndFormatted = date('F j, Y', strtotime($old_data['end_date']));
            if (isset($old_data['end_time']) && $old_data['end_time']) {
                $oldEndFormatted .= ' at ' . $formatTime($old_data['end_time']);
            }
            $changes[] = '<strong>End Date:</strong> Changed from ' . $oldEndFormatted . ' to ' . $endFormatted;
        } elseif (isset($old_data['end_time']) && $old_data['end_time'] !== $end_time) {
            $oldEndFormatted = date('F j, Y', strtotime($end_date));
            if ($old_data['end_time']) {
                $oldEndFormatted .= ' at ' . $formatTime($old_data['end_time']);
            }
            $newEndFormatted = date('F j, Y', strtotime($end_date));
            if ($end_time) {
                $newEndFormatted .= ' at ' . $formatTime($end_time);
            }
            $changes[] = '<strong>End Time:</strong> Changed from ' . ($old_data['end_time'] ? $formatTime($old_data['end_time']) : 'All Day') . ' to ' . ($end_time ? $formatTime($end_time) : 'All Day');
        }
        
        // Check for description changes
        if (isset($old_data['description']) && $old_data['description'] !== $description) {
            $changes[] = '<strong>Description:</strong> Has been updated';
        }
        
        if (!empty($changes)) {
            $changeItems = '';
            foreach ($changes as $change) {
                $changeItems .= '<li style="margin: 4px 0;">' . $change . '</li>';
            }
            $changeNotice = '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin: 16px 0; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; color: #92400e; font-weight: 600;">Changes Made:</p>
                <ul style="margin: 0; padding-left: 20px; color: #78350f;">
                    ' . $changeItems . '
                </ul>
            </div>';
        } else {
            $changeNotice = '<div style="background: #dbeafe; border-left: 4px solid #3b82f6; padding: 12px; margin: 16px 0; border-radius: 4px;">
                <p style="margin: 0; color: #1e40af;">This announcement has been updated. Please review the details below.</p>
            </div>';
        }
    }
    
    // Build email body
    $greeting = $is_update 
        ? '<p>Hello ' . htmlspecialchars($name) . ',</p><p>We are writing to inform you that a health center activity announcement has been updated.</p>'
        : '<p>Hello ' . htmlspecialchars($name) . ',</p><p>We are pleased to inform you about an upcoming health center activity.</p>';
    
    $html = email_template(
        $headerTitle,
        $headerLead,
        $greeting . 
        ($changeNotice ? $changeNotice : '') . '
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
