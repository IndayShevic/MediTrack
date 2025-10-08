<?php
// Get notification counts for sidebar badges with caching
function get_bhw_notification_counts($bhw_purok_id) {
    // Use session cache for 30 seconds to reduce database queries
    $cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
    $cache_time = 30; // seconds
    
    if (isset($_SESSION[$cache_key]) && 
        isset($_SESSION[$cache_key . '_time']) && 
        (time() - $_SESSION[$cache_key . '_time']) < $cache_time) {
        return $_SESSION[$cache_key];
    }
    
    $counts = [
        'pending_requests' => 0,
        'pending_registrations' => 0,
        'pending_family_additions' => 0,
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
                 WHERE res.purok_id = ? AND rfa.status = "pending") as pending_family_additions
        ');
        $stmt->execute([$bhw_purok_id, $bhw_purok_id, $bhw_purok_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $counts['pending_requests'] = (int)($result['pending_requests'] ?? 0);
            $counts['pending_registrations'] = (int)($result['pending_registrations'] ?? 0);
            $counts['pending_family_additions'] = (int)($result['pending_family_additions'] ?? 0);
        }
    } catch (Throwable $e) {
        // Silent fail - return zeros
    }
    
    // Cache the result
    $_SESSION[$cache_key] = $counts;
    $_SESSION[$cache_key . '_time'] = time();
    
    return $counts;
}

