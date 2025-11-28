<?php
if (!function_exists('render_super_admin_sidebar')) {
    /**
     * Renders the unified super admin sidebar used across all dashboard pages.
     * 
     * HOW THE SIDEBAR WORKS:
     * ======================
     * 
     * 1. STRUCTURE:
     *    - The sidebar is a PHP function that generates HTML
     *    - It uses an array ($nav_items) to define menu items
     *    - Each item can be either:
     *      a) A simple link: ['href' => 'path', 'icon' => 'icon-class', 'label' => 'Text']
     *      b) A dropdown group: ['type' => 'dropdown', 'label' => 'Group Name', 'icon' => 'icon-class', 'items' => [...]]
     * 
     * 2. ACTIVE PAGE DETECTION:
     *    - Compares current page filename with each menu item's href
     *    - Sets 'active' class on matching items
     *    - For dropdowns, checks if any child item is active
     * 
     * 3. RENDERING:
     *    - Loops through $nav_items array
     *    - Generates HTML for each item (link or dropdown)
     *    - Applies active states and styling
     * 
     * 4. DROPDOWN FUNCTIONALITY:
     *    - JavaScript handles click events on dropdown toggles
     *    - Toggles 'open' class to show/hide submenu
     *    - CSS handles animations and styling
     * 
     * TO ADD A NEW MENU ITEM:
     * =======================
     * Add to $nav_items array:
     * ['href' => 'super_admin/your_page.php', 'icon' => 'fas fa-icon', 'label' => 'Your Label']
     * 
     * TO CREATE A DROPDOWN:
     * ====================
     * ['type' => 'dropdown', 'label' => 'Group Name', 'icon' => 'fas fa-icon', 'items' => [
     *     ['href' => 'super_admin/page1.php', 'label' => 'Sub Item 1'],
     *     ['href' => 'super_admin/page2.php', 'label' => 'Sub Item 2'],
     * ]]
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

        // Navigation items structure
        // You can organize items into dropdown groups by using 'type' => 'dropdown'
        $nav_items = [
            // Main Dashboard (always visible)
            ['href' => 'super_admin/dashboardnew.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard'],
            
            // Medicine Management Dropdown
            [
                'type' => 'dropdown',
                'label' => 'Medicine Management',
                'icon' => 'fas fa-pills',
                'items' => [
                    ['href' => 'super_admin/medicines.php', 'label' => 'Medicines'],
                    ['href' => 'super_admin/categories.php', 'label' => 'Categories'],
                    ['href' => 'super_admin/batches.php', 'label' => 'Batches'],
                    ['href' => 'super_admin/inventory.php', 'label' => 'Inventory'],
                ]
            ],
            
            // User Management Dropdown
            [
                'type' => 'dropdown',
                'label' => 'User Management',
                'icon' => 'fas fa-users',
                'items' => [
                    ['href' => 'super_admin/users.php', 'label' => 'Users'],
                    ['href' => 'super_admin/allocations.php', 'label' => 'Allocations'],
                    ['href' => 'super_admin/duty_schedules.php', 'label' => 'Duty Schedules'],
                ]
            ],
            
            // Reports & Analytics Dropdown
            [
                'type' => 'dropdown',
                'label' => 'Reports & Analytics',
                'icon' => 'fas fa-chart-bar',
                'items' => [
                    ['href' => 'super_admin/analytics.php', 'label' => 'Analytics'],
                    ['href' => 'super_admin/reports_hub.php', 'label' => 'Reports Hub'],
                    ['href' => 'super_admin/report_settings.php', 'label' => 'Report Settings'],
                ]
            ],
            
            // Settings & Configuration
            [
                'type' => 'dropdown',
                'label' => 'Settings',
                'icon' => 'fas fa-cog',
                'items' => [
                    ['href' => 'super_admin/locations.php', 'label' => 'Barangays & Puroks'],
                    ['href' => 'super_admin/announcements.php', 'label' => 'Announcements'],
                ]
            ],
        ];

        // Helper function to check if a page is active
        $isPageActive = function($href) use ($current_page) {
            $item_page = basename($href);
            $normalized_current = strtolower(trim($current_page));
            $normalized_item = strtolower(trim($item_page));
            return ($normalized_current === $normalized_item);
        };

        // Helper function to check if any child in dropdown is active
        $hasActiveChild = function($items) use ($isPageActive) {
            foreach ($items as $item) {
                if (isset($item['href']) && $isPageActive($item['href'])) {
                    return true;
                }
            }
            return false;
        };

        ?>
        <aside id="sidebar-aside" class="hidden md:flex md:flex-shrink-0 md:relative fixed md:left-0 left-[-16rem] z-50">
            <div id="sidebar" class="sidebar flex flex-col w-64 bg-white border-r border-gray-200 transition-all duration-300 overflow-hidden h-screen">
                <!-- Sidebar Header/Brand -->
                <div class="flex items-center justify-center h-16 px-4 bg-gradient-to-r from-purple-600 to-purple-800">
                    <?php if (!empty($logo)): ?>
                        <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg mr-2" alt="Logo" />
                    <?php else: ?>
                        <i class="fas fa-heartbeat text-white text-2xl mr-2"></i>
                    <?php endif; ?>
                    <span class="brand-text text-2xl font-bold text-white"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
                </div>
                
                <!-- Sidebar Navigation -->
                <div id="super-admin-sidebar-scroll" class="flex flex-col flex-1 overflow-y-auto">
                    <nav class="flex-1 px-2 py-4 space-y-1">
                        <?php foreach ($nav_items as $item): 
                            // Check if it's a dropdown or regular link
                            if (isset($item['type']) && $item['type'] === 'dropdown'):
                                $dropdown_id = 'dropdown-' . preg_replace('/[^a-z0-9]/', '-', strtolower($item['label']));
                                $is_dropdown_active = $hasActiveChild($item['items']);
                                ?>
                                <!-- Dropdown Menu Item -->
                                <div class="sidebar-dropdown <?php echo $is_dropdown_active ? 'active' : ''; ?>">
                                    <button type="button" 
                                            class="sidebar-dropdown-toggle w-full flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg transition hover:bg-gray-50 <?php echo $is_dropdown_active ? 'active' : ''; ?>"
                                            data-dropdown="<?php echo htmlspecialchars($dropdown_id); ?>">
                                        <div class="flex items-center">
                                            <i class="<?php echo htmlspecialchars($item['icon']); ?> mr-3"></i>
                                            <span class="link-text"><?php echo htmlspecialchars($item['label']); ?></span>
                                        </div>
                                        <i class="fas fa-chevron-down dropdown-arrow text-xs transition-transform duration-200"></i>
                                    </button>
                                    <div id="<?php echo htmlspecialchars($dropdown_id); ?>" class="sidebar-dropdown-menu pl-4 mt-1 space-y-1 <?php echo $is_dropdown_active ? 'open' : ''; ?>" data-initial-state="<?php echo $is_dropdown_active ? 'open' : 'closed'; ?>">
                                        <?php foreach ($item['items'] as $sub_item): 
                                            $sub_href = htmlspecialchars(base_url($sub_item['href']));
                                            $sub_is_active = $isPageActive($sub_item['href']);
                                            ?>
                                            <a href="<?php echo $sub_href; ?>"
                                               class="sidebar-link sidebar-dropdown-item <?php echo $sub_is_active ? 'active' : ''; ?> flex items-center px-4 py-2.5 text-sm text-gray-600 rounded-lg transition hover:bg-gray-50 hover:text-gray-900">
                                                <span class="link-text"><?php echo htmlspecialchars($sub_item['label']); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: 
                                // Regular link item
                                $href = htmlspecialchars(base_url($item['href']));
                                $is_active = $isPageActive($item['href']);
                                ?>
                                <a href="<?php echo $href; ?>"
                                   class="sidebar-link <?php echo $is_active ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition">
                                    <i class="<?php echo htmlspecialchars($item['icon']); ?> mr-3"></i>
                                    <span class="link-text"><?php echo htmlspecialchars($item['label']); ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </div>
                
                <!-- Sidebar Footer (User Info & Logout) -->
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
        
        <!-- Sidebar Dropdown CSS -->
        <style>
        /* Dropdown Styles */
        .sidebar-dropdown {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-dropdown-toggle {
            font-weight: 500;
            font-size: 0.9375rem;
            min-height: 44px;
            border-left: 3px solid transparent;
            cursor: pointer;
            text-align: left;
        }
        
        .sidebar-dropdown-toggle:hover {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(59, 130, 246, 0.04) 100%);
            color: #2563eb;
        }
        
        .sidebar-dropdown.active > .sidebar-dropdown-toggle,
        .sidebar-dropdown.open > .sidebar-dropdown-toggle {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.12) 0%, rgba(59, 130, 246, 0.08) 100%);
            color: #1d4ed8;
            font-weight: 600;
            border-left: 3px solid #2563eb;
        }
        
        .sidebar-dropdown-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out, opacity 0.2s ease-out, padding 0.3s ease-out;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
        }
        
        .sidebar-dropdown-menu.open {
            max-height: 500px;
            opacity: 1;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        
        .sidebar-dropdown-item {
            position: relative;
            padding-left: 2.5rem !important;
        }
        
        .sidebar-dropdown-item::before {
            content: '•';
            position: absolute;
            left: 1rem;
            color: #9ca3af;
            font-size: 1.2rem;
        }
        
        .sidebar-dropdown-item:hover::before,
        .sidebar-dropdown-item.active::before {
            color: #2563eb;
        }
        
        .sidebar-dropdown-item.active {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.12) 0%, rgba(59, 130, 246, 0.08) 100%);
            color: #1d4ed8;
            font-weight: 600;
        }
        
        .dropdown-arrow {
            transition: transform 0.3s ease;
        }
        
        .sidebar-dropdown.open .dropdown-arrow {
            transform: rotate(180deg);
        }
        </style>
        
        <!-- Sidebar Dropdown JavaScript -->
        <script>
        (function() {
            // Use event delegation - attach once to document for maximum reliability
            function handleDropdownClick(e) {
                // Check if clicked element is a dropdown toggle or inside one
                const toggle = e.target.closest('.sidebar-dropdown-toggle');
                if (!toggle) return;
                
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownId = toggle.getAttribute('data-dropdown');
                const dropdownMenu = document.getElementById(dropdownId);
                const dropdown = toggle.closest('.sidebar-dropdown');
                
                if (!dropdownMenu) return;
                
                // Toggle the dropdown
                const isOpen = dropdownMenu.classList.contains('open');
                
                if (isOpen) {
                    // Close
                    dropdownMenu.classList.remove('open');
                    dropdown.classList.remove('open');
                } else {
                    // Close other open dropdowns
                    document.querySelectorAll('.sidebar-dropdown-menu.open').forEach(menu => {
                        if (menu !== dropdownMenu) {
                            menu.classList.remove('open');
                            menu.closest('.sidebar-dropdown')?.classList.remove('open');
                        }
                    });
                    
                    // Open
                    dropdownMenu.classList.add('open');
                    dropdown.classList.add('open');
                }
            }
            
            // Reset all dropdowns to their initial state on page load
            function resetDropdowns() {
                // First, close ALL dropdowns
                document.querySelectorAll('.sidebar-dropdown-menu').forEach(menu => {
                    menu.classList.remove('open');
                    const dropdown = menu.closest('.sidebar-dropdown');
                    if (dropdown) {
                        dropdown.classList.remove('open');
                    }
                });
                
                // Then, open only the ones that should be open (active dropdowns)
                document.querySelectorAll('.sidebar-dropdown-menu').forEach(menu => {
                    const initialState = menu.getAttribute('data-initial-state');
                    const dropdown = menu.closest('.sidebar-dropdown');
                    
                    if (initialState === 'open' && dropdown) {
                        menu.classList.add('open');
                        dropdown.classList.add('open');
                    }
                });
            }
            
            // Initialize - use document-level event delegation for maximum reliability
            function initSidebarDropdowns() {
                // Attach event listener to document (only once, using event delegation)
                if (!window.sidebarDropdownInitialized) {
                    document.addEventListener('click', handleDropdownClick, true);
                    window.sidebarDropdownInitialized = true;
                }
                
                // Reset all dropdowns to initial state (closed except for active ones)
                resetDropdowns();
            }
            
            // Initialize when DOM is ready
            function runInit() {
                setTimeout(initSidebarDropdowns, 10);
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', runInit);
            } else {
                runInit();
            }
            
            // Re-initialize on page show (for browser back/forward)
            window.addEventListener('pageshow', function(e) {
                // Reset on page show to ensure clean state
                setTimeout(resetDropdowns, 10);
            });
            
            // Also reset when page becomes visible (handles tab switching)
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    setTimeout(resetDropdowns, 10);
                }
            });
        })();
        </script>
        <?php
    }
}
