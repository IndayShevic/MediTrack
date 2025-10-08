<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
require_once __DIR__ . '/includes/sidebar_counts.php';

$user = current_user();
$bhw_purok_id = $user['purok_id'] ?? 0;

// Clear cache to get fresh counts
$cache_key = 'bhw_notification_counts_' . $bhw_purok_id;
unset($_SESSION[$cache_key]);
unset($_SESSION[$cache_key . '_time']);

// Get fresh notification counts
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'counts' => $notification_counts
]);
?>
