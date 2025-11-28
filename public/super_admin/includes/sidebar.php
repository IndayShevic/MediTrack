<?php
if (!function_exists('render_super_admin_sidebar')) {
    /**
     * Renders the unified super admin sidebar used across all dashboard pages.
     *
     * @param array{
     *     current_page?: string,
     *     user_data?: array<string, mixed>
     * } $args
     */
    function render_super_admin_sidebar(array $args = []): void
    {
        $current_page = $args['current_page'] ?? basename($_SERVER['PHP_SELF'] ?? '');
        $user_data = $args['user_data'] ?? [];

        // Ensure user data fallback if not provided
        if (empty($user_data) && function_exists('current_user')) {
            $user_data = current_user();
        }

        $logo = get_setting('brand_logo_path');
        $brand = get_setting('brand_name', 'MediTrack');
        $full_name = trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'));
        $email = $user_data['email'] ?? 'admin@meditrack.com';

        $nav_items = [
            ['href' => 'super_admin/dashboardnew.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard'],
            ['href' => 'super_admin/medicines.php', 'icon' => 'fas fa-pills', 'label' => 'Medicines'],
            ['href' => 'super_admin/categories.php', 'icon' => 'fas fa-tags', 'label' => 'Categories'],
            ['href' => 'super_admin/batches.php', 'icon' => 'fas fa-layer-group', 'label' => 'Batches'],
            ['href' => 'super_admin/inventory.php', 'icon' => 'fas fa-boxes', 'label' => 'Inventory'],
            ['href' => 'super_admin/users.php', 'icon' => 'fas fa-users', 'label' => 'Users'],
            ['href' => 'super_admin/allocations.php', 'icon' => 'fas fa-user-friends', 'label' => 'Allocations'],
            ['href' => 'super_admin/announcements.php', 'icon' => 'fas fa-bullhorn', 'label' => 'Announcements'],
            ['href' => 'super_admin/analytics.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Analytics'],
            ['href' => 'super_admin/reports_hub.php', 'icon' => 'fas fa-file-alt', 'label' => 'Reports Hub'],
            ['href' => 'super_admin/settings_brand.php', 'icon' => 'fas fa-cog', 'label' => 'Brand Settings'],
            ['href' => 'super_admin/report_settings.php', 'icon' => 'fas fa-file-signature', 'label' => 'Report Settings'],
            ['href' => 'super_admin/locations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Barangays & Puroks'],
            ['href' => 'super_admin/duty_schedules.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'Duty Schedules'],
            ['href' => 'super_admin/email_logs.php', 'icon' => 'fas fa-envelope', 'label' => 'Email Logs'],
        ];

        ?>
        <aside id="sidebar-aside" class="hidden md:flex md:flex-shrink-0 md:relative fixed md:left-0 left-[-16rem] z-50">
            <div id="sidebar" class="sidebar flex flex-col w-64 bg-white border-r border-gray-200 transition-all duration-300 overflow-hidden h-screen">
                <div class="flex items-center justify-center h-16 px-4 bg-gradient-to-r from-purple-600 to-purple-800">
                    <?php if (!empty($logo)): ?>
                        <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg mr-2" alt="Logo" />
                    <?php else: ?>
                        <i class="fas fa-heartbeat text-white text-2xl mr-2"></i>
                    <?php endif; ?>
                    <span class="brand-text text-2xl font-bold text-white"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
                </div>
                <div id="super-admin-sidebar-scroll" class="flex flex-col flex-1 overflow-y-auto">
                    <nav class="flex-1 px-2 py-4 space-y-1">
                        <?php foreach ($nav_items as $item):
                            $href = htmlspecialchars(base_url($item['href']));
                            $item_page = basename($item['href']);
                            // Normalize both values for comparison (remove query strings, ensure lowercase)
                            $normalized_current = strtolower(trim($current_page));
                            $normalized_item = strtolower(trim($item_page));
                            // Strict comparison to avoid false positives (e.g. 'locations.php' inside 'allocations.php')
                            $is_active = ($normalized_current === $normalized_item);
                            ?>
                            <a href="<?php echo $href; ?>"
                               class="sidebar-link <?php echo $is_active ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                                <i class="<?php echo htmlspecialchars($item['icon']); ?> mr-3"></i>
                                <span class="link-text"><?php echo htmlspecialchars($item['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <div class="p-4 border-t border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>"
                                     alt="Profile"
                                     class="w-10 h-10 rounded-full object-cover"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>">
                                <i class="fas fa-user text-purple-600"></i>
                            </div>
                        </div>
                        <div class="ml-3 user-info">
                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($email); ?></p>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="mt-3 w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>
                        <span class="link-text">Logout</span>
                    </a>
                </div>
            </div>
        </aside>
        <?php
    }
}

