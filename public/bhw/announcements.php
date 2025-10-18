<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
$user = current_user();

// Fetch active announcements
try {
    $announcements = db()->query('SELECT * FROM announcements WHERE is_active = 1 AND end_date >= CURDATE() ORDER BY start_date ASC, created_at DESC')->fetchAll();
} catch (Exception $e) {
    $announcements = [];
}

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($user['purok_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Announcements · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'fade-in': 'fadeIn 0.4s ease-out',
                        'slide-in-right': 'slideInRight 0.5s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'float': 'float 3s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideInRight: {
                            '0%': { opacity: '0', transform: 'translateX(20px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                        bounceIn: {
                            '0%': { opacity: '0', transform: 'scale(0.3)' },
                            '50%': { opacity: '1', transform: 'scale(1.05)' },
                            '70%': { transform: 'scale(0.9)' },
                            '100%': { opacity: '1', transform: 'scale(1)' }
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .notification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.375rem;
            font-size: 0.6875rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 9999px;
            margin-left: auto;
            animation: pulse-badge 2s ease-in-out infinite;
        }
        
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4);
        }
        .announcement-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        /* Clean Professional Calendar Styles */
        #calendar {
            min-height: 800px !important;
            height: auto !important;
            background: white !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
            border: 1px solid #e5e7eb !important;
            overflow: visible !important;
        }
        
        .fc {
            font-size: 16px !important;
            height: 100% !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        
        .fc-header-toolbar {
            padding: 20px 24px !important;
            background: #f8fafc !important;
            border-radius: 12px 12px 0 0 !important;
            margin-bottom: 0 !important;
            border-bottom: 1px solid #e5e7eb !important;
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 10px !important;
        }
        
        .fc-toolbar-title {
            font-size: 24px !important;
            font-weight: 700 !important;
            color: #1f2937 !important;
            letter-spacing: -0.025em !important;
        }
        
        .fc-button {
            background: white !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            padding: 8px 16px !important;
            font-weight: 500 !important;
            color: #374151 !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        }
        
        .fc-button:hover {
            background: #f9fafb !important;
            border-color: #9ca3af !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .fc-button:active {
            transform: translateY(0) !important;
        }
        
        .fc-button-group {
            background: #f3f4f6 !important;
            border-radius: 8px !important;
            padding: 2px !important;
        }
        
        .fc-button-group .fc-button {
            background: transparent !important;
            border: none !important;
            margin: 0 !important;
            padding: 6px 12px !important;
            border-radius: 6px !important;
        }
        
        .fc-button-group .fc-button.fc-button-active {
            background: white !important;
            color: #1f2937 !important;
            font-weight: 600 !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        }
        
        .fc-daygrid {
            height: auto !important;
            background: white !important;
        }
        
        .fc-daygrid-body {
            height: auto !important;
        }
        
        .fc-scroller {
            overflow-y: auto !important;
            padding: 0 !important;
        }
        
        .fc-col-header {
            background: #f9fafb !important;
            border: none !important;
        }
        
        .fc-col-header-cell {
            padding: 12px 8px !important;
            font-weight: 600 !important;
            color: #6b7280 !important;
            font-size: 13px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            border: none !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        
        .fc-daygrid-day {
            min-height: 120px !important;
            border: 1px solid #f3f4f6 !important;
            transition: all 0.2s ease !important;
            position: relative !important;
            background: white !important;
        }
        
        .fc-daygrid-day:hover {
            background: #f9fafb !important;
            border-color: #d1d5db !important;
        }
        
        .fc-daygrid-day-number {
            font-weight: 600 !important;
            color: #374151 !important;
            padding: 8px 12px !important;
            font-size: 15px !important;
            transition: all 0.2s ease !important;
        }
        
        .fc-day-today {
            background: #eff6ff !important;
            border-color: #3b82f6 !important;
        }
        
        .fc-day-today .fc-daygrid-day-number {
            color: #1d4ed8 !important;
            font-weight: 700 !important;
        }
        
        .fc-daygrid-day-events {
            max-height: 100px !important;
            overflow-y: auto !important;
            padding: 4px !important;
            margin-top: 4px !important;
        }
        
        .fc-event {
            border-radius: 6px !important;
            border: none !important;
            padding: 4px 8px !important;
            font-weight: 500 !important;
            font-size: 12px !important;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
            transition: all 0.2s ease !important;
            margin-bottom: 2px !important;
            background: #3b82f6 !important;
            color: white !important;
        }
        
        .fc-event:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .fc-event-title {
            font-weight: 500 !important;
            line-height: 1.3 !important;
        }
        
        /* Other month days styling */
        .fc-day-other .fc-daygrid-day-number {
            color: #9ca3af !important;
            font-weight: 400 !important;
        }
        
        .fc-day-other {
            background: #f9fafb !important;
        }
        
        /* Weekend styling */
        .fc-day-sat,
        .fc-day-sun {
            background: #fafafa !important;
        }
        
        .fc-day-sat .fc-daygrid-day-number,
        .fc-day-sun .fc-daygrid-day-number {
            color: #6b7280 !important;
        }
        
        /* Scrollbar styling */
        .fc-scroller::-webkit-scrollbar {
            width: 4px;
        }
        
        .fc-scroller::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
        
        .fc-scroller::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Custom Button Styling */
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.4);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
     </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
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
            <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span style="flex: 1;">Medicine Requests</span>
                <?php if ($notification_counts['pending_requests'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/walkin_dispensing.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Walk-in Dispensing
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Residents & Family
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Registrations</span>
                <?php if ($notification_counts['pending_registrations'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_registrations']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Family Additions</span>
                <?php if (!empty($notification_counts['pending_family_additions'])): ?>
                    <span class="notification-badge"><?php echo (int)$notification_counts['pending_family_additions']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Statistics
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/profile.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                <div class="animate-fade-in-up mb-4 lg:mb-0">
                    <div class="flex items-center space-x-3 mb-2">
                        <h1 class="text-2xl lg:text-4xl font-bold bg-gradient-to-r from-gray-900 via-blue-800 to-purple-800 bg-clip-text text-transparent">
                            Health Center Announcements
                        </h1>
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-gray-600 text-base lg:text-lg">Stay updated with upcoming health center activities and programs</p>
                    <div class="flex items-center space-x-2 mt-2">
                        <div class="w-1 h-1 bg-blue-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-purple-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-cyan-400 rounded-full"></div>
                        <span class="text-sm text-gray-500 ml-2">Live updates</span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-4 animate-slide-in-right">
                    <div class="text-right glass-effect px-4 py-2 rounded-xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Active announcements</div>
                        <div class="text-sm font-semibold text-gray-900" id="active-count"><?php echo count($announcements); ?></div>
                    </div>
                    <button class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105" onclick="refreshData()">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="content-body">
            <!-- Full Calendar View -->
            <div class="mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Announcement Calendar</h3>
                            <p class="text-gray-600">Click on events to view details</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <button onclick="openAnnouncementsModal()" class="btn-primary">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                </svg>
                                View All Announcements
                            </button>
                        </div>
                    </div>
                    <div id="calendar" class="rounded-lg overflow-hidden"></div>
                </div>
            </div>
        </div>
    </main>

    <!-- View Modal -->
    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-2xl font-bold text-gray-900">Announcement Details</h3>
                        <button onclick="closeViewModal()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div id="viewContent" class="space-y-6">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Announcements List Modal -->
    <div id="announcementsListModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 modal-backdrop">
        <div class="flex items-center justify-center min-h-screen" style="margin-left: 280px; width: calc(100% - 280px);">
            <div class="modal-content w-full max-h-[90vh] rounded-2xl overflow-hidden mx-4">
                <div class="bg-white p-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                </svg>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-900">All Announcements</h3>
                        </div>
                        <button onclick="closeAnnouncementsModal()" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-all duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <?php if (empty($announcements)): ?>
                            <div class="text-center py-12">
                                <div class="w-20 h-20 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                                    </svg>
                                </div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-2">No Active Announcements</h4>
                                <p class="text-gray-500 mb-4">Check back later for upcoming health center activities</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($announcements as $index => $announcement): ?>
                                <div class="p-6 bg-white border border-gray-200 rounded-xl hover:shadow-lg transition-all duration-300 cursor-pointer" onclick="viewAnnouncement(<?php echo htmlspecialchars(json_encode($announcement)); ?>)">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-3">
                                                <h4 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                    </svg>
                                                    Active
                                                </span>
                                            </div>
                                            <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($announcement['description']); ?></p>
                                            <div class="flex items-center space-x-6 text-sm text-gray-500">
                                                <div class="flex items-center space-x-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span><?php echo date('M j, Y', strtotime($announcement['start_date'])); ?></span>
                                                    <?php if ($announcement['start_date'] !== $announcement['end_date']): ?>
                                                        <span>to</span>
                                                        <span><?php echo date('M j, Y', strtotime($announcement['end_date'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    <span>
                                                        <?php
                                                        $start_date = new DateTime($announcement['start_date']);
                                                        $end_date = new DateTime($announcement['end_date']);
                                                        $now = new DateTime();
                                                        
                                                        if ($now < $start_date) {
                                                            $diff = $now->diff($start_date);
                                                            echo 'Starts in ' . $diff->days . ' day' . ($diff->days !== 1 ? 's' : '');
                                                        } elseif ($now >= $start_date && $now <= $end_date) {
                                                            echo 'Ongoing';
                                                        } else {
                                                            echo 'Ended';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center">
                                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 'auto',
                contentHeight: 'auto',
                aspectRatio: 1.2,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                events: [
                    <?php foreach ($announcements as $announcement): ?>
                    {
                        id: <?php echo $announcement['id']; ?>,
                        title: '<?php echo addslashes($announcement['title']); ?>',
                        start: '<?php echo $announcement['start_date']; ?>',
                        end: '<?php echo date('Y-m-d', strtotime($announcement['end_date'] . ' +1 day')); ?>',
                        backgroundColor: '#3b82f6',
                        borderColor: '#2563eb',
                        textColor: '#ffffff'
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    const announcement = <?php echo json_encode($announcements); ?>.find(a => a.id == info.event.id);
                    if (announcement) {
                        viewAnnouncement(announcement);
                    }
                },
                dayMaxEvents: 3,
                moreLinkClick: 'popover',
                eventDisplay: 'block',
                displayEventTime: false
            });
            calendar.render();
        });

        function viewAnnouncement(announcement) {
            const startDate = new Date(announcement.start_date);
            const endDate = new Date(announcement.end_date);
            const now = new Date();
            
            let statusText = '';
            let statusColor = '';
            
            if (now < startDate) {
                const diff = Math.ceil((startDate - now) / (1000 * 60 * 60 * 24));
                statusText = `Starts in ${diff} day${diff !== 1 ? 's' : ''}`;
                statusColor = 'bg-blue-100 text-blue-800';
            } else if (now >= startDate && now <= endDate) {
                statusText = 'Ongoing';
                statusColor = 'bg-green-100 text-green-800';
            } else {
                statusText = 'Ended';
                statusColor = 'bg-gray-100 text-gray-800';
            }
            
            const content = `
                <div class="space-y-6">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h4 class="text-3xl font-bold text-gray-900 mb-2">${announcement.title}</h4>
                            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium ${statusColor}">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                ${statusText}
                            </span>
                        </div>
                    </div>
                    
                    <div class="prose max-w-none">
                        <p class="text-gray-700 leading-relaxed text-lg">${announcement.description}</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-gray-200">
                        <div class="flex items-center space-x-4 p-4 bg-blue-50 rounded-xl">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Start Date</p>
                                <p class="font-semibold text-gray-900">${startDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-4 p-4 bg-green-50 rounded-xl">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">End Date</p>
                                <p class="font-semibold text-gray-900">${endDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-xl border border-blue-200">
                        <div class="flex items-center space-x-3 mb-3">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <h5 class="font-semibold text-blue-900">Important Information</h5>
                        </div>
                        <p class="text-blue-800">
                            Please mark your calendar and prepare any necessary documents or requirements for this health center activity. 
                            For questions or clarifications, contact your Barangay Health Worker or visit the health center.
                        </p>
                    </div>
                </div>
            `;
            
            document.getElementById('viewContent').innerHTML = content;
            document.getElementById('viewModal').classList.remove('hidden');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        function openAnnouncementsModal() {
            document.getElementById('announcementsListModal').classList.remove('hidden');
        }

        function closeAnnouncementsModal() {
            document.getElementById('announcementsListModal').classList.add('hidden');
        }

        function refreshData() {
            document.getElementById('last-updated').textContent = 'Just now';
            location.reload();
        }

        // Function to check if announcement is in the past
        function isAnnouncementPast(announcement) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const endDate = new Date(announcement.end_date);
            return endDate < today;
        }

        // Make past announcements unclickable and add visual indicators
        document.addEventListener('DOMContentLoaded', function() {
            const announcementCards = document.querySelectorAll('.announcement-card');
            announcementCards.forEach(card => {
                const onclick = card.getAttribute('onclick');
                if (onclick) {
                    // Extract announcement data from onclick attribute
                    const match = onclick.match(/viewAnnouncement\(([^)]+)\)/);
                    if (match) {
                        try {
                            const announcementData = JSON.parse(match[1]);
                            if (isAnnouncementPast(announcementData)) {
                                // Make past announcements unclickable
                                card.removeAttribute('onclick');
                                card.style.cursor = 'not-allowed';
                                card.style.opacity = '0.6';
                                
                                // Add past indicator
                                const statusBadge = card.querySelector('.inline-flex');
                                if (statusBadge) {
                                    statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r from-gray-100 to-slate-100 text-gray-600 border border-gray-200';
                                    statusBadge.innerHTML = `
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Past Event
                                    `;
                                }
                                
                                // Add tooltip
                                card.title = 'This announcement has ended and is no longer active';
                            }
                        } catch (e) {
                            console.log('Could not parse announcement data');
                        }
                    }
                }
            });
        });

        // Close modal when clicking outside
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
            }
        });

        // Add hover effects to announcement cards
        document.querySelectorAll('.announcement-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                if (this.style.cursor !== 'not-allowed') {
                    this.style.transform = 'translateY(-2px)';
                }
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>
