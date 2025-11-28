<?php
// Helper function to determine if a link is active
if (!function_exists('is_active_page')) {
    function is_active_page($path) {
        $current_path = $_SERVER['SCRIPT_NAME'];
        return strpos($current_path, $path) !== false ? 'active' : '';
    }
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
            <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg" alt="Logo" />
        <?php else: ?>
            <div class="h-8 w-8 bg-white/20 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                </svg>
            </div>
        <?php endif; ?>
        <span><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
    </div>
    <nav class="sidebar-nav">
        <a class="<?php echo is_active_page('bhw/dashboard.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
            </svg>
            Dashboard
        </a>
        <a class="<?php echo is_active_page('bhw/requests.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <span style="flex: 1;">Medicine Requests</span>
            <?php if (isset($notification_counts['pending_requests']) && $notification_counts['pending_requests'] > 0): ?>
                <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
            <?php endif; ?>
        </a>
        <a class="<?php echo is_active_page('bhw/dispense_medicines.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/dispense_medicines.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Dispense Medicines
        </a>
        <a class="<?php echo is_active_page('bhw/walkin_dispensing.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/walkin_dispensing.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            Walk-in Dispensing
        </a>
        <a class="<?php echo is_active_page('bhw/residents.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
            </svg>
            Residents & Family
        </a>
        <a class="<?php echo is_active_page('bhw/allocations.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
            </svg>
            Allocations
        </a>
        <a class="<?php echo is_active_page('bhw/pending_residents.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span style="flex: 1;">Pending Registrations</span>
            <?php if (isset($notification_counts['pending_registrations']) && $notification_counts['pending_registrations'] > 0): ?>
                <span class="notification-badge"><?php echo $notification_counts['pending_registrations']; ?></span>
            <?php endif; ?>
        </a>
        <a class="<?php echo is_active_page('bhw/pending_family_additions.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <span style="flex: 1;">Pending Family Additions</span>
            <?php if (isset($notification_counts['pending_family_additions']) && !empty($notification_counts['pending_family_additions'])): ?>
                <span class="notification-badge"><?php echo (int)$notification_counts['pending_family_additions']; ?></span>
            <?php endif; ?>
        </a>
        <a class="<?php echo is_active_page('bhw/stats.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            Statistics
        </a>
        <a class="<?php echo is_active_page('bhw/announcements.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
            </svg>
            Announcements
        </a>
        <a class="<?php echo is_active_page('bhw/my_schedule.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/my_schedule.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            My Schedule
        </a>
        <a class="<?php echo is_active_page('bhw/profile.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/profile.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
            Profile
        </a>
    </nav>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="flex items-center mb-3">
            <div class="flex-shrink-0">
                <?php 
                // Ensure we have the profile image
                $profile_image = $user['profile_image'] ?? $user_data['profile_image'] ?? null;
                if (!empty($profile_image)): 
                    // Fix for uploads directory being in root, not public
                    $img_url = base_url($profile_image);
                    if (strpos($profile_image, 'uploads/') === 0) {
                        $img_url = base_url('../' . $profile_image);
                    }
                ?>
                    <img src="<?php echo htmlspecialchars($img_url); ?>" 
                         alt="Profile" 
                         class="w-10 h-10 rounded-full object-cover border-2 border-purple-500"
                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500 hidden">
                        <?php 
                        $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                        $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                        echo strtoupper($firstInitial . $lastInitial); 
                        ?>
                    </div>
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                        <?php 
                        $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                        $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                        echo strtoupper($firstInitial . $lastInitial); 
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ml-3 flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">
                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'BHW') . ' ' . ($user['last_name'] ?? 'Worker'))); ?>
                </p>
                <p class="text-xs text-gray-600 truncate">
                    <?php echo htmlspecialchars($user['email'] ?? 'bhw@example.com'); ?>
                </p>
            </div>
        </div>
        <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="flex items-center justify-center w-full px-4 py-2 text-sm text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            Logout
        </a>
    </div>
</aside>
