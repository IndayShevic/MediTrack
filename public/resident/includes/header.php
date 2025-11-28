<?php
if (!function_exists('render_resident_header')) {
    /**
     * Renders the unified resident header used across all resident pages.
     *
     * @param array{
     *     user_data?: array<string, mixed>,
     *     is_senior?: bool,
     *     pending_requests?: int,
     *     recent_requests?: array
     * } $args
     */
    function render_resident_header(array $args = []): void {
        $user_data = $args['user_data'] ?? [];
        $is_senior = $args['is_senior'] ?? false;
        $pending_requests = $args['pending_requests'] ?? 0;
        $recent_requests = $args['recent_requests'] ?? [];
        
        // Get user info
        $user = current_user();
        $first_name = $user['first_name'] ?? 'Resident';
        $last_name = $user['last_name'] ?? 'User';
        $email = $user['email'] ?? 'resident@example.com';
        $full_name = trim("$first_name $last_name");
        
        // Get initials for avatar
        $firstInitial = !empty($first_name) ? substr($first_name, 0, 1) : 'R';
        $lastInitial = !empty($last_name) ? substr($last_name, 0, 1) : 'E';
        $initials = strtoupper($firstInitial . $lastInitial);
        
        $logo = get_setting('brand_logo_path');
        $brand = get_setting('brand_name', 'MediTrack');
        ?>
        <style>
            /* Prevent header from expanding when dropdown opens */
            header.bg-white {
                height: 4rem !important;
                min-height: 4rem !important;
                max-height: 4rem !important;
                overflow: visible !important;
                position: relative !important;
            }
            header.bg-white > div {
                height: 4rem !important;
                min-height: 4rem !important;
                max-height: 4rem !important;
                overflow: visible !important;
                position: relative !important;
                display: flex !important;
                align-items: center !important;
            }
            /* Ensure dropdown containers don't affect header height */
            header.bg-white .relative {
                overflow: visible !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
            }
            /* Position dropdowns absolutely outside document flow */
            #notificationDropdown,
            #profileDropdown {
                position: absolute !important;
                z-index: 1000 !important;
                margin-top: 0.5rem !important;
                transform: translateZ(0);
                will-change: transform;
                top: 100% !important;
            }
            /* Ensure buttons don't expand header */
            #notificationBtn,
            #profileBtn {
                height: 2.5rem !important;
                min-height: 2.5rem !important;
                max-height: 2.5rem !important;
                flex-shrink: 0 !important;
                align-items: center !important;
            }
            /* Prevent profile button text container from expanding */
            #profileBtn > div {
                height: auto !important;
                max-height: 2.5rem !important;
            }
            /* Ensure header padding doesn't cause expansion */
            header.bg-white > div {
                padding-top: 0.75rem !important;
                padding-bottom: 0.75rem !important;
            }
        </style>
        <!-- New Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30" style="overflow: visible;">
            <div class="flex items-center justify-between px-3 py-3 sm:px-4 sm:py-4 md:px-6 h-16" style="overflow: visible; position: relative;">
                <!-- Left Section: Menu + Logo/Title -->
                <div class="flex items-center flex-1 min-w-0 h-full">
                    <button id="mobileMenuToggle" class="md:hidden text-gray-500 hover:text-gray-700 mr-2 sm:mr-3 flex-shrink-0 flex items-center justify-center w-10 h-10" aria-label="Toggle menu" type="button">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <!-- Mobile Logo + Title -->
                    <div class="md:hidden flex items-center min-w-0 flex-1 h-full">
                        <?php if ($logo): ?>
                            <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg flex-shrink-0 mr-2" alt="Logo" />
                        <?php else: ?>
                            <i class="fas fa-heartbeat text-purple-600 text-2xl mr-2 flex-shrink-0"></i>
                        <?php endif; ?>
                        <h1 class="text-lg sm:text-xl font-bold text-gray-900 truncate leading-none"><?php echo htmlspecialchars($brand); ?></h1>
                    </div>
                    <!-- Desktop Title (hidden on mobile) -->
                    <div class="hidden md:flex items-center h-full">
                        <h1 class="text-xl font-bold text-gray-900 leading-none"><?php echo htmlspecialchars($brand); ?></h1>
                    </div>
                </div>
                
                <!-- Right Section: Notifications + Profile -->
                <div class="flex items-center space-x-2 sm:space-x-3 flex-shrink-0 h-full" style="overflow: visible;">
                    <!-- Notifications Dropdown -->
                    <div class="relative" style="overflow: visible;">
                        <button id="notificationBtn" class="relative text-gray-500 hover:text-gray-700 flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors" aria-label="Notifications" type="button">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($pending_requests > 0): ?>
                                <span class="absolute -top-1 -right-1 flex items-center justify-center w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full border-2 border-white shadow-sm notification-badge"><?php echo $pending_requests > 9 ? '9+' : $pending_requests; ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notifications Dropdown Menu -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-3 w-80 sm:w-[28rem] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 z-50 overflow-hidden transform origin-top-right transition-all duration-200" style="position: absolute; top: 100%;">
                            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/80 backdrop-blur-sm flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-gray-900">Notifications</h3>
                                    <p class="text-xs text-gray-500 mt-0.5">Stay updated with your requests</p>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <?php if ($pending_requests > 0): ?>
                                        <span class="px-2.5 py-1 bg-red-100 text-red-600 text-[10px] font-bold uppercase tracking-wider rounded-full" id="notificationCount"><?php echo $pending_requests; ?> NEW</span>
                                        <button id="markAllReadBtn" class="text-xs text-blue-600 hover:text-blue-700 font-medium px-2 py-1 hover:bg-blue-50 rounded-md transition-colors" title="Mark all as read">
                                            Mark all read
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="overflow-y-auto max-h-[32rem] scrollbar-thin scrollbar-thumb-gray-200 scrollbar-track-transparent">
                                <?php if ($pending_requests === 0): ?>
                                    <div class="flex flex-col items-center justify-center py-12 px-4 text-center">
                                        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                            <i class="fas fa-bell-slash text-2xl text-gray-300"></i>
                                        </div>
                                        <h4 class="text-sm font-semibold text-gray-900">No new notifications</h4>
                                        <p class="text-xs text-gray-500 mt-1 max-w-[200px]">You're all caught up! Check back later for updates.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="divide-y divide-gray-50">
                                        <?php foreach (array_slice($recent_requests, 0, 5) as $req): ?>
                                            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>" 
                                               class="notification-item group block px-5 py-4 hover:bg-blue-50/50 transition-all duration-200 relative" 
                                               data-type="request" 
                                               data-id="<?php echo htmlspecialchars($req['id'] ?? ''); ?>">
                                                <div class="flex items-start gap-4">
                                                    <div class="flex-shrink-0 mt-1">
                                                        <div class="w-3 h-3 bg-blue-500 rounded-full ring-4 ring-blue-100 notification-dot shadow-sm"></div>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-sm font-semibold text-gray-900 group-hover:text-blue-700 transition-colors mb-0.5">
                                                            Request for <?php echo htmlspecialchars($req['medicine_name'] ?? 'Medicine'); ?>
                                                        </p>
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-yellow-100 text-yellow-800">
                                                                <?php echo htmlspecialchars(ucfirst($req['status'] ?? 'Pending')); ?>
                                                            </span>
                                                        </div>
                                                        <p class="text-xs text-gray-400 font-medium">
                                                            <?php echo date('M d, Y • h:i A', strtotime($req['created_at'] ?? 'now')); ?>
                                                        </p>
                                                    </div>
                                                    <div class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <?php if ($pending_requests > 5): ?>
                                        <div class="p-2 bg-gray-50 border-t border-gray-100">
                                            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>" class="block py-2 text-xs text-center text-blue-600 hover:text-blue-700 font-semibold hover:bg-blue-50 rounded-lg transition-colors">
                                                View <?php echo $pending_requests - 5; ?> more requests
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-3 border-t border-gray-100 bg-gray-50/80 backdrop-blur-sm">
                                <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>" class="flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:text-gray-900 hover:border-gray-300 transition-all shadow-sm">
                                    <span>View All Activity</span>
                                    <i class="fas fa-arrow-right ml-2 text-xs opacity-70"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Dropdown -->
                    <div class="relative" style="overflow: visible;">
                        <button id="profileBtn" class="flex items-center space-x-2 sm:space-x-3 rounded-lg hover:bg-gray-100 transition-colors px-2" type="button" style="height: 2.5rem; max-height: 2.5rem;">
                            <div class="text-right hidden sm:flex items-center h-full">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 leading-tight"><?php echo htmlspecialchars($full_name); ?></p>
                                    <p class="text-xs text-gray-500 leading-tight"><?php echo $is_senior ? 'Senior Citizen' : 'Resident'; ?></p>
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center flex-shrink-0 cursor-pointer border-2 border-gray-300">
                                <?php if (!empty($user_data['profile_image'])): ?>
                                    <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                         alt="Profile" 
                                         class="w-10 h-10 rounded-full object-cover"
                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>
                                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-gray-700 font-semibold text-sm border-2 border-gray-300 <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>">
                                    <?php echo $initials; ?>
                                </div>
                            </div>
                        </button>
                        
                        <!-- Profile Dropdown Menu -->
                        <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 z-50" style="position: absolute; top: 100%;">
                            <div class="p-4 border-b border-gray-200">
                                <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($email); ?></p>
                                <?php if ($is_senior): ?>
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fas fa-user-check text-xs mr-1"></i>
                                            Senior Citizen
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="py-2">
                                <a href="<?php echo htmlspecialchars(base_url('resident/profile.php')); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                    <i class="fas fa-user-circle w-5 mr-3 text-gray-400"></i>
                                    <span>My Profile</span>
                                </a>
                                <div class="border-t border-gray-200 my-2"></div>
                                <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <i class="fas fa-sign-out-alt w-5 mr-3"></i>
                                    <span>Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <script>
        // Initialize notification and profile dropdowns
        function initResidentHeaderDropdowns() {
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (!notificationBtn || !notificationDropdown || !profileBtn || !profileDropdown) {
                // Retry if elements not ready yet
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initResidentHeaderDropdowns);
                } else {
                    setTimeout(initResidentHeaderDropdowns, 100);
                }
                return;
            }
            
            // Only add listeners if not already added
            if (notificationBtn.hasAttribute('data-dropdown-listener')) {
                return; // Already initialized
            }
            
            notificationBtn.setAttribute('data-dropdown-listener', 'true');
            profileBtn.setAttribute('data-dropdown-listener', 'true');
            
            // Toggle notification dropdown
            notificationBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = !notificationDropdown.classList.contains('hidden');
                
                // Close profile dropdown if open
                if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
                
                // Toggle notification dropdown
                if (isOpen) {
                    notificationDropdown.classList.add('hidden');
                } else {
                    notificationDropdown.classList.remove('hidden');
                    // Ensure dropdown is positioned correctly
                    const rect = notificationBtn.getBoundingClientRect();
                    notificationDropdown.style.top = (rect.height + 8) + 'px';
                    notificationDropdown.style.right = '0';
                }
            });
            
            // Toggle profile dropdown
            profileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = !profileDropdown.classList.contains('hidden');
                
                // Close notification dropdown if open
                if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                    notificationDropdown.classList.add('hidden');
                }
                
                // Toggle profile dropdown
                if (isOpen) {
                    profileDropdown.classList.add('hidden');
                } else {
                    profileDropdown.classList.remove('hidden');
                    // Ensure dropdown is positioned correctly
                    const rect = profileBtn.getBoundingClientRect();
                    profileDropdown.style.top = (rect.height + 8) + 'px';
                    profileDropdown.style.right = '0';
                }
            });
            
            // Close dropdowns when clicking outside (only add once)
            if (!window.residentHeaderDropdownClickHandler) {
                window.residentHeaderDropdownClickHandler = function(e) {
                    const notifBtn = document.getElementById('notificationBtn');
                    const notifDropdown = document.getElementById('notificationDropdown');
                    const profBtn = document.getElementById('profileBtn');
                    const profDropdown = document.getElementById('profileDropdown');
                    
                    // Close notification dropdown
                    if (notifDropdown && !notifDropdown.classList.contains('hidden')) {
                        if (notifBtn && !notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                            notifDropdown.classList.add('hidden');
                        }
                    }
                    
                    // Close profile dropdown
                    if (profDropdown && !profDropdown.classList.contains('hidden')) {
                        if (profBtn && !profBtn.contains(e.target) && !profDropdown.contains(e.target)) {
                            profDropdown.classList.add('hidden');
                        }
                    }
                };
                document.addEventListener('click', window.residentHeaderDropdownClickHandler);
            }
            
            // Close dropdowns on escape key (only add once)
            if (!window.residentHeaderDropdownKeyHandler) {
                window.residentHeaderDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const notifDropdown = document.getElementById('notificationDropdown');
                        const profDropdown = document.getElementById('profileDropdown');
                        if (notifDropdown && !notifDropdown.classList.contains('hidden')) {
                            notifDropdown.classList.add('hidden');
                        }
                        if (profDropdown && !profDropdown.classList.contains('hidden')) {
                            profDropdown.classList.add('hidden');
                        }
                    }
                };
                document.addEventListener('keydown', window.residentHeaderDropdownKeyHandler);
            }
        }
        
        // Initialize mobile menu toggle
        function initResidentMobileMenu() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.querySelector('.sidebar');
            const mobileOverlay = document.querySelector('.mobile-overlay');
            
            if (!mobileMenuToggle || !sidebar) {
                // Retry if elements not ready yet
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initResidentMobileMenu);
                } else {
                    setTimeout(initResidentMobileMenu, 100);
                }
                return;
            }
            
            // Only add listener if not already added
            if (!mobileMenuToggle.hasAttribute('data-listener-added')) {
                mobileMenuToggle.setAttribute('data-listener-added', 'true');
                mobileMenuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    if (mobileOverlay) {
                        mobileOverlay.classList.toggle('active');
                    }
                });
            }
            
            // Close sidebar when clicking overlay
            if (mobileOverlay && !mobileOverlay.hasAttribute('data-listener-added')) {
                mobileOverlay.setAttribute('data-listener-added', 'true');
                mobileOverlay.addEventListener('click', function() {
                    if (sidebar) sidebar.classList.remove('open');
                    mobileOverlay.classList.remove('active');
                });
            }
        }
        
        // Get read notifications from localStorage
        function getReadNotifications() {
            const read = localStorage.getItem('resident_read_notifications');
            return read ? JSON.parse(read) : [];
        }
        
        // Save read notification to localStorage
        function saveReadNotification(type, id) {
            const read = getReadNotifications();
            const key = `${type}_${id}`;
            if (!read.includes(key)) {
                read.push(key);
                localStorage.setItem('resident_read_notifications', JSON.stringify(read));
            }
        }
        
        // Check if notification is read
        function isNotificationRead(type, id) {
            const read = getReadNotifications();
            return read.includes(`${type}_${id}`);
        }
        
        // Mark notification as read
        function markNotificationRead(type, id) {
            saveReadNotification(type, id);
            // Mark the notification item as read
            const notificationItem = document.querySelector(`.notification-item[data-type="${type}"][data-id="${id}"]`);
            if (notificationItem) {
                notificationItem.classList.add('opacity-60');
                const dot = notificationItem.querySelector('.notification-dot');
                if (dot) {
                    dot.style.display = 'none';
                }
            }
            updateNotificationCount();
        }
        
        // Mark all notifications as read
        function markAllNotificationsRead() {
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (!markAllBtn) return;
            
            // Mark all visible notifications as read
            document.querySelectorAll('.notification-item').forEach(item => {
                const type = item.getAttribute('data-type');
                const id = item.getAttribute('data-id');
                if (type && id) {
                    saveReadNotification(type, id);
                    item.classList.add('opacity-60');
                    const dot = item.querySelector('.notification-dot');
                    if (dot) {
                        dot.style.display = 'none';
                    }
                }
            });
            
            // Hide the "Mark all as read" button and update count
            markAllBtn.style.display = 'none';
            updateNotificationCount();
        }
        
        // Update notification count badge
        function updateNotificationCount() {
            const unreadItems = document.querySelectorAll('.notification-item:not(.opacity-60)');
            const count = unreadItems.length;
            const countBadge = document.getElementById('notificationCount');
            const notificationBadge = document.querySelector('#notificationBtn .notification-badge');
            
            if (countBadge) {
                if (count === 0) {
                    countBadge.style.display = 'none';
                    const markAllBtn = document.getElementById('markAllReadBtn');
                    if (markAllBtn) markAllBtn.style.display = 'none';
                } else {
                    countBadge.textContent = count + ' new';
                    countBadge.style.display = 'inline-block';
                }
            }
            
            if (notificationBadge) {
                if (count === 0) {
                    notificationBadge.style.display = 'none';
                } else {
                    notificationBadge.textContent = count > 9 ? '9+' : count;
                    notificationBadge.style.display = 'flex';
                }
            }
        }
        
        // Initialize notification read functionality
        function initNotificationRead() {
            // Mark already read notifications on load
            document.querySelectorAll('.notification-item').forEach(item => {
                const type = item.getAttribute('data-type');
                const id = item.getAttribute('data-id');
                if (type && id && isNotificationRead(type, id)) {
                    item.classList.add('opacity-60');
                    const dot = item.querySelector('.notification-dot');
                    if (dot) {
                        dot.style.display = 'none';
                    }
                }
            });
            
            // Add click handlers to notification items
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    const type = this.getAttribute('data-type');
                    const id = this.getAttribute('data-id');
                    if (type && id) {
                        markNotificationRead(type, id);
                    }
                });
            });
            
            // Add click handler to "Mark all as read" button
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    markAllNotificationsRead();
                });
            }
            
            // Update count on load
            updateNotificationCount();
        }
        
        // Initialize when DOM is ready - use multiple strategies to ensure it runs
        function initResidentHeader() {
            initResidentHeaderDropdowns();
            initResidentMobileMenu();
            initNotificationRead();
        }
        
        // Try multiple initialization strategies
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initResidentHeader);
        } else {
            // DOM already loaded, run immediately
            initResidentHeader();
        }
        
        // Also try after a short delay to catch any edge cases
        setTimeout(initResidentHeader, 200);
        </script>
        <?php
    }
}
?>

