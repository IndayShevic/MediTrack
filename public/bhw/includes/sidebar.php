<?php
// Helper function to determine if a link is active
if (!function_exists('is_active_page')) {
    function is_active_page($path) {
        $current_path = $_SERVER['SCRIPT_NAME'];
        return strpos($current_path, $path) !== false ? 'active' : '';
    }
}

// Ensure we have user data with profile image
if (!isset($user)) {
    $user = current_user();
}
if (!isset($user_data)) {
    $user_data = [];
}
// Fetch profile image if not in user or user_data
if (empty($user['profile_image']) && empty($user_data['profile_image']) && !empty($user['id'])) {
    try {
        $imgStmt = db()->prepare('SELECT profile_image FROM users WHERE id = ? LIMIT 1');
        $imgStmt->execute([$user['id']]);
        $imgData = $imgStmt->fetch();
        if ($imgData && !empty($imgData['profile_image'])) {
            $user_data['profile_image'] = $imgData['profile_image'];
            $user['profile_image'] = $imgData['profile_image'];
        }
    } catch (Throwable $e) {
        // Silently fail
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
        <!-- Dashboard (Standalone) -->
        <a class="<?php echo is_active_page('bhw/dashboard.php'); ?>" href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
            </svg>
            Dashboard
        </a>
        
        <!-- Medicine Operations Dropdown -->
        <?php
        $medicine_ops_active = is_active_page('bhw/requests.php') || 
                               is_active_page('bhw/dispense_medicines.php') || 
                               is_active_page('bhw/dispense_history.php') || 
                               is_active_page('bhw/walkin_dispensing.php');
        $medicine_ops_id = 'dropdown-medicine-operations';
        ?>
        <div class="sidebar-dropdown <?php echo $medicine_ops_active ? 'active' : ''; ?>">
            <button type="button" 
                    class="sidebar-dropdown-toggle w-full flex items-center justify-between <?php echo $medicine_ops_active ? 'active' : ''; ?>"
                    data-dropdown="<?php echo $medicine_ops_id; ?>">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                    </svg>
                    <span>Medicine Operations</span>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow text-xs transition-transform duration-200"></i>
            </button>
            <div id="<?php echo $medicine_ops_id; ?>" class="sidebar-dropdown-menu pl-4 mt-1 space-y-1 <?php echo $medicine_ops_active ? 'open' : ''; ?>" data-initial-state="<?php echo $medicine_ops_active ? 'open' : 'closed'; ?>">
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/requests.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                    <span style="flex: 1;">Medicine Requests</span>
                    <?php if (isset($notification_counts['pending_requests']) && $notification_counts['pending_requests'] > 0): ?>
                        <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
                    <?php endif; ?>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/dispense_medicines.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/dispense_medicines.php')); ?>">
                    <span style="flex: 1;">Dispense Medicines</span>
                    <?php if (isset($notification_counts['ready_to_dispense']) && $notification_counts['ready_to_dispense'] > 0): ?>
                        <span class="notification-badge"><?php echo $notification_counts['ready_to_dispense']; ?></span>
                    <?php endif; ?>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/dispense_history.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/dispense_history.php')); ?>">
                    <span>Dispense History</span>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/walkin_dispensing.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/walkin_dispensing.php')); ?>">
                    <span>Walk-in Dispensing</span>
                </a>
            </div>
        </div>
        
        <!-- Resident Management Dropdown -->
        <?php
        $resident_mgmt_active = is_active_page('bhw/residents.php') || 
                                is_active_page('bhw/pending_residents.php') || 
                                is_active_page('bhw/pending_family_additions.php');
        $resident_mgmt_id = 'dropdown-resident-management';
        ?>
        <div class="sidebar-dropdown <?php echo $resident_mgmt_active ? 'active' : ''; ?>">
            <button type="button" 
                    class="sidebar-dropdown-toggle w-full flex items-center justify-between <?php echo $resident_mgmt_active ? 'active' : ''; ?>"
                    data-dropdown="<?php echo $resident_mgmt_id; ?>">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                    </svg>
                    <span>Resident Management</span>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow text-xs transition-transform duration-200"></i>
            </button>
            <div id="<?php echo $resident_mgmt_id; ?>" class="sidebar-dropdown-menu pl-4 mt-1 space-y-1 <?php echo $resident_mgmt_active ? 'open' : ''; ?>" data-initial-state="<?php echo $resident_mgmt_active ? 'open' : 'closed'; ?>">
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/residents.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                    <span>Residents & Family</span>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/pending_residents.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                    <span style="flex: 1;">Pending Registrations</span>
                    <?php if (isset($notification_counts['pending_registrations']) && $notification_counts['pending_registrations'] > 0): ?>
                        <span class="notification-badge"><?php echo $notification_counts['pending_registrations']; ?></span>
                    <?php endif; ?>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/pending_family_additions.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
                    <span style="flex: 1;">Pending Family Additions</span>
                    <?php if (isset($notification_counts['pending_family_additions']) && !empty($notification_counts['pending_family_additions'])): ?>
                        <span class="notification-badge"><?php echo (int)$notification_counts['pending_family_additions']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        
        <!-- Reports & Data Dropdown -->
        <?php
        $reports_active = is_active_page('bhw/stats.php') || is_active_page('bhw/allocations.php');
        $reports_id = 'dropdown-reports-data';
        ?>
        <div class="sidebar-dropdown <?php echo $reports_active ? 'active' : ''; ?>">
            <button type="button" 
                    class="sidebar-dropdown-toggle w-full flex items-center justify-between <?php echo $reports_active ? 'active' : ''; ?>"
                    data-dropdown="<?php echo $reports_id; ?>">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span>Reports & Data</span>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow text-xs transition-transform duration-200"></i>
            </button>
            <div id="<?php echo $reports_id; ?>" class="sidebar-dropdown-menu pl-4 mt-1 space-y-1 <?php echo $reports_active ? 'open' : ''; ?>" data-initial-state="<?php echo $reports_active ? 'open' : 'closed'; ?>">
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/stats.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                    <span>Statistics</span>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/allocations.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                    <span>Allocations</span>
                </a>
            </div>
        </div>
        
        <!-- Settings Dropdown -->
        <?php
        $settings_active = is_active_page('bhw/announcements.php') || 
                          is_active_page('bhw/my_schedule.php') || 
                          is_active_page('bhw/profile.php');
        $settings_id = 'dropdown-settings';
        ?>
        <div class="sidebar-dropdown <?php echo $settings_active ? 'active' : ''; ?>">
            <button type="button" 
                    class="sidebar-dropdown-toggle w-full flex items-center justify-between <?php echo $settings_active ? 'active' : ''; ?>"
                    data-dropdown="<?php echo $settings_id; ?>">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>Settings</span>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow text-xs transition-transform duration-200"></i>
            </button>
            <div id="<?php echo $settings_id; ?>" class="sidebar-dropdown-menu pl-4 mt-1 space-y-1 <?php echo $settings_active ? 'open' : ''; ?>" data-initial-state="<?php echo $settings_active ? 'open' : 'closed'; ?>">
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/announcements.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
                    <span>Announcements</span>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/my_schedule.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/my_schedule.php')); ?>">
                    <span>My Schedule</span>
                </a>
                <a class="sidebar-link sidebar-dropdown-item <?php echo is_active_page('bhw/profile.php'); ?> flex items-center px-4 py-2.5 text-sm rounded-lg transition" href="<?php echo htmlspecialchars(base_url('bhw/profile.php')); ?>">
                    <span>Profile</span>
                </a>
            </div>
        </div>
    </nav>
    
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
        padding: 0.75rem 1rem;
        margin-bottom: 0.25rem;
        border-radius: 0.75rem;
        color: #4b5563;
        text-decoration: none;
        transition: all 0.2s ease;
        position: relative;
        overflow: hidden;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: transparent;
        border: none;
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
            if (!window.bhwSidebarDropdownInitialized) {
                document.addEventListener('click', handleDropdownClick, true);
                window.bhwSidebarDropdownInitialized = true;
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
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="flex items-center mb-3">
            <div class="flex-shrink-0 relative">
                <?php 
                // Ensure we have the profile image
                $profile_image = $user['profile_image'] ?? $user_data['profile_image'] ?? null;
                $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'B';
                $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'H';
                $initials = strtoupper($firstInitial . $lastInitial);
                
                if (!empty($profile_image)): 
                    // Helper function to get upload URL (uploads are at project root, not in public/)
                    $clean_path = ltrim($profile_image, '/');
                    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
                    $pos = strpos($script, '/public/');
                    if ($pos !== false) {
                                        $base = substr($script, 0, $pos);
                                    } else {
                                        $base = dirname($script);
                                        if ($base === '.' || $base === '/') {
                                            $base = '';
                                        }
                                    }
                    $img_url = rtrim($base, '/') . '/' . $clean_path;
                ?>
                    <img src="<?php echo htmlspecialchars($img_url); ?>" 
                         alt="Profile" 
                         class="w-10 h-10 rounded-full object-cover border-2 border-purple-500"
                         style="display: block;"
                         onerror="this.style.display='none'; const fallback = this.nextElementSibling; if (fallback) fallback.style.display='flex';">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500" style="display: none; position: absolute; top: 0; left: 0;">
                        <?php echo $initials; ?>
                    </div>
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                        <?php echo $initials; ?>
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
