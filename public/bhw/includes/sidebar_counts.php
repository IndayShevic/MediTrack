<?php
// Get notification counts for sidebar badges with caching
function get_bhw_notification_counts($bhw_purok_id, $bhw_user_id = null) {
    // Use session cache for 30 seconds to reduce database queries
    $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
    $cache_time = 30; // seconds
    
    // Always check duty status for ready_to_dispense (don't cache this)
    $is_duty_today = false;
    if ($bhw_user_id !== null) {
        $today = date('Y-m-d');
        try {
            $dutyCheck = db()->prepare('
                SELECT id, shift_start, shift_end, is_active
                FROM bhw_duty_schedules 
                WHERE bhw_id = ? AND duty_date = ?
                ORDER BY is_active DESC
                LIMIT 1
            ');
            $dutyCheck->execute([$bhw_user_id, $today]);
            $duty_schedule = $dutyCheck->fetch();
            $is_duty_today = !empty($duty_schedule);
        } catch (Throwable $e) {
            // Silent fail
        }
    }
    
    if (isset($_SESSION[$cache_key]) && 
        isset($_SESSION[$cache_key . '_time']) && 
        (time() - $_SESSION[$cache_key . '_time']) < $cache_time) {
        $cached_counts = $_SESSION[$cache_key];
        
        // Override ready_to_dispense count based on current duty status
        if (!$is_duty_today) {
            $cached_counts['ready_to_dispense'] = 0;
        }
        
        return $cached_counts;
    }
    
    $counts = [
        'pending_requests' => 0,
        'pending_registrations' => 0,
        'pending_family_additions' => 0,
        'ready_to_dispense' => 0,
    ];
    
    try {
        // Single optimized query to get all counts at once
        $stmt = db()->prepare('
            SELECT 
                (SELECT COUNT(*) 
                 FROM requests r
                 JOIN residents res ON res.id = r.resident_id
                 WHERE r.status = "submitted" AND res.purok_id = ?) as pending_requests,
                (SELECT COUNT(*) 
                 FROM pending_residents 
                 WHERE purok_id = ? AND status = "pending") as pending_registrations,
                (SELECT COUNT(*)
                 FROM resident_family_additions rfa
                 JOIN residents res ON res.id = rfa.resident_id
                 WHERE res.purok_id = ? AND rfa.status = "pending") as pending_family_additions,
                (SELECT COUNT(*)
                 FROM requests r
                 WHERE r.status = "approved" AND r.is_ready_to_dispense = 1) as ready_to_dispense
        ');
        $stmt->execute([$bhw_purok_id, $bhw_purok_id, $bhw_purok_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $counts['pending_requests'] = (int)($result['pending_requests'] ?? 0);
            $counts['pending_registrations'] = (int)($result['pending_registrations'] ?? 0);
            $counts['pending_family_additions'] = (int)($result['pending_family_additions'] ?? 0);
            // Only set ready_to_dispense count if BHW is on duty today
            $counts['ready_to_dispense'] = $is_duty_today ? (int)($result['ready_to_dispense'] ?? 0) : 0;
        }
    } catch (Throwable $e) {
        // Silent fail - return zeros
    }
    
    // Cache the result
    $_SESSION[$cache_key] = $counts;
    $_SESSION[$cache_key . '_time'] = time();
    
    return $counts;
}

