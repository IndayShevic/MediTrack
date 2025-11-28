<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['resident']);
require_once __DIR__ . '/includes/header.php';

// Helper function to get upload URL
function upload_url(string $path): string {
    $clean_path = ltrim($path, '/');
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
    return rtrim($base, '/') . '/' . $clean_path;
}

$user = current_user();

// Get updated user data with profile image
$userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$user['id']]);
$user_data = $userStmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Get resident info for senior citizen check
$residentRow = db()->prepare('SELECT id, date_of_birth FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
$residentId = $resident ? (int)$resident['id'] : 0;

// Check if resident is senior citizen
$is_senior = false;
if ($resident && !empty($resident['date_of_birth'])) {
    $birth_date = new DateTime($resident['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birth_date)->y;
    $is_senior = $age >= 60;
}

// Get pending requests count for notifications
$pending_requests = 0;
try {
    $pendingStmt = db()->prepare('SELECT COUNT(*) as count FROM requests WHERE resident_id = ? AND status = "submitted"');
    $pendingStmt->execute([$residentId]);
    $pending_requests = (int)$pendingStmt->fetch()['count'];
} catch (Throwable $e) {
    $pending_requests = 0;
}

// Get recent requests for header notifications
$recent_requests = [];
try {
    $recentStmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name AS medicine_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id WHERE r.resident_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $recentStmt->execute([$residentId]);
    $recent_requests = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $recent_requests = [];
}

// Get all active categories
$categories = [];
try {
    $categories = db()->query('SELECT id, name FROM categories WHERE id IN (SELECT DISTINCT category_id FROM medicines WHERE category_id IS NOT NULL AND is_active = 1) ORDER BY name')->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Get medicines with their stock and expiry information
// Only show medicines that have at least one batch (even if expired or out of stock)
// Calculate available stock (excluding expired/out of stock batches)
$meds = db()->query('
    SELECT 
        m.id, 
        m.name, 
        m.description, 
        m.image_path,
        m.category_id,
        c.name as category_name,
        COALESCE(SUM(CASE 
            WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() 
            THEN mb.quantity_available 
            ELSE 0 
        END), 0) as total_stock,
        MIN(CASE 
            WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() 
            THEN mb.expiry_date 
            ELSE NULL 
        END) as earliest_expiry,
        COUNT(mb.id) as total_batches
    FROM medicines m 
    INNER JOIN medicine_batches mb ON m.id = mb.medicine_id
    LEFT JOIN categories c ON m.category_id = c.id
    WHERE m.is_active = 1 
    GROUP BY m.id, m.name, m.description, m.image_path, m.category_id, c.name
    ORDER BY m.name
')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Medicines · Resident</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/resident-animations.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <style>
        /* CRITICAL: Override mobile menu CSS that's breaking sidebar */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 1000 !important;
            transform: none !important;
        }
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override mobile media queries */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                transform: none !important;
                width: 280px !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }

        /* Custom scrollbar styling for modal */
        #requestModal::-webkit-scrollbar {
            width: 8px;
        }
        
        #requestModal::-webkit-scrollbar-track {
            background: #f9fafb;
            border-radius: 4px;
        }
        
        #requestModal::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }
        
        #requestModal::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
        
        /* Firefox scrollbar styling */
        #requestModal {
            scrollbar-width: thin;
            scrollbar-color: #d1d5db #f9fafb;
        }
    </style>
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
                        'shimmer': 'shimmer 2s linear infinite',
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
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-200% 0' },
                            '100%': { backgroundPosition: '200% 0' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .gradient-border {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2px;
            border-radius: 12px;
        }
        .gradient-border > div {
            background: white;
            border-radius: 10px;
        }
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hover-lift:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .medicine-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }
        .medicine-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4);
        }
        .stock-indicator {
            position: relative;
            overflow: hidden;
        }
        .stock-indicator::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 2s infinite;
        }
        .search-container {
            position: relative;
        }
        .search-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 12px;
            z-index: -1;
        }
        .filter-chip {
            transition: all 0.3s ease;
        }
        .filter-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .filter-chip.active {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
        }
        
        /* Force main content to be visible - override any conflicting styles */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            min-height: 100vh !important;
            background: #f9fafb !important;
            position: relative !important;
            z-index: 1 !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            left: 0 !important;
            top: 0 !important;
        }
        
        /* Content Header Styles */
        .content-header {
            position: sticky !important;
            top: 0 !important;
            z-index: 50 !important;
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            padding: 2rem !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 2rem !important;
        }
        
        .dark .content-header {
            background: #1f2937 !important;
            border-bottom-color: #374151 !important;
        }
        
        .dark .text-gray-900 {
            color: #f9fafb !important;
        }
        
        .dark .text-gray-600 {
            color: #d1d5db !important;
        }
        
        .dark .text-gray-500 {
            color: #9ca3af !important;
        }
        
        .dark .card {
            background: #374151 !important;
            border-color: #4b5563 !important;
        }
        
        .content-body {
            position: relative !important;
            z-index: 1 !important;
            background: #f9fafb !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            min-height: 500px !important;
            width: 100% !important;
        }
        
        /* Desktop layout - ensure sidebar and main content are properly positioned */
        @media (min-width: 769px) {
            .mobile-menu-toggle {
                display: none !important;
            }
            
            .mobile-overlay {
                display: none !important;
            }
            
            .sidebar {
                transform: translateX(0) !important;
                position: fixed !important;
                width: 280px !important;
                height: 100vh !important;
                z-index: 1000 !important;
            }
        }
        
        /* Mobile layout - only apply on actual mobile devices */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block !important;
                position: fixed !important;
                top: 1rem !important;
                left: 1rem !important;
                z-index: 1001 !important;
                background: #1f2937 !important;
                color: white !important;
                border: 2px solid #374151 !important;
                border-radius: 0.75rem !important;
                padding: 0.875rem !important;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1) !important;
                transition: all 0.2s ease-in-out !important;
                backdrop-filter: blur(10px) !important;
            }
            
            .mobile-menu-toggle:hover {
                background: #374151 !important;
                transform: scale(1.05) !important;
                box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.2) !important;
            }
            
            .mobile-menu-toggle:active {
                transform: scale(0.95) !important;
            }
            
            .mobile-overlay {
                display: block !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: rgba(0, 0, 0, 0.5) !important;
                z-index: 999 !important;
                opacity: 0 !important;
                transition: opacity 0.3s ease-in-out !important;
            }
            
            .mobile-overlay.active {
                opacity: 1 !important;
            }
            
            .sidebar {
                width: 280px !important;
                transform: translateX(-100%) !important;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1) !important;
            }
            
            .sidebar.open {
                transform: translateX(0) !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .content-header {
                padding: 1rem !important;
                margin-top: 4rem !important;
            }
            
            .content-body {
                padding: 1rem !important;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 resident-theme">
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
            <a href="<?php echo htmlspecialchars(base_url('resident/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Browse Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                My Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/medicine_history.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Medicine History
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/family_members.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Family Members
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="flex items-center mb-3">
                <div class="flex-shrink-0">
                    <?php if (!empty($user_data['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                             alt="Profile" 
                             class="w-10 h-10 rounded-full object-cover border-2 border-green-500"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-green-500 hidden">
                            <?php 
                            $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'R';
                            $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'E';
                            echo strtoupper($firstInitial . $lastInitial); 
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-green-500">
                            <?php 
                            $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'R';
                            $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'E';
                            echo strtoupper($firstInitial . $lastInitial); 
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">
                        <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['last_name'] ?? 'User'))); ?>
                    </p>
                    <p class="text-xs text-gray-600 truncate">
                        <?php echo htmlspecialchars($user['email'] ?? 'resident@example.com'); ?>
                    </p>
                </div>
            </div>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <?php render_resident_header([
            'user_data' => $user_data,
            'is_senior' => $is_senior,
            'pending_requests' => $pending_requests,
            'recent_requests' => $recent_requests
        ]); ?>
        
        <!-- Page Title -->
        <div class="p-4 sm:p-6 lg:p-8">
            <div class="mb-6">
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 mb-1">Browse Medicines</h1>
                <p class="text-gray-600">Discover and request available medicines</p>
            </div>

        <!-- Content -->
        <div class="content-body">
            <!-- Search and Filter Section -->
            <div class="mb-8">
                <div class="search-container p-6 rounded-2xl shadow-lg animate-fade-in-up" style="animation-delay: 0.1s">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <!-- Search Bar -->
                        <div class="flex-1">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="searchInput" placeholder="Search medicines..." class="block w-full pl-10 pr-3 py-2 text-sm border border-gray-300 rounded-xl leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300">
                            </div>
                        </div>
                        
                        <!-- Filter Chips -->
                        <div class="flex flex-wrap gap-2">
                            <button class="filter-chip active px-3 py-1.5 bg-white border border-gray-300 rounded-full text-xs font-medium text-gray-700 hover:bg-gray-50" data-filter="all">
                                All Medicines
                            </button>
                            <button class="filter-chip px-3 py-1.5 bg-white border border-gray-300 rounded-full text-xs font-medium text-gray-700 hover:bg-gray-50" data-filter="available">
                                Available
                            </button>
                            <button class="filter-chip px-3 py-1.5 bg-white border border-gray-300 rounded-full text-xs font-medium text-gray-700 hover:bg-gray-50" data-filter="low-stock">
                                Low Stock
                            </button>
                            <button class="filter-chip px-3 py-1.5 bg-white border border-gray-300 rounded-full text-xs font-medium text-gray-700 hover:bg-gray-50" data-filter="expiring">
                                Expiring Soon
                            </button>
                        </div>
                    </div>
                    
                    <!-- Category Filters -->
                    <?php if (!empty($categories)): ?>
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 text-gray-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                            <h3 class="text-sm font-semibold text-gray-700">Filter by Category</h3>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button class="category-filter active px-4 py-2 bg-blue-50 border-2 border-blue-500 rounded-full text-sm font-medium text-blue-700 hover:bg-blue-100 transition-all duration-200 shadow-sm" data-category="all">
                                All Categories
                            </button>
                            <?php foreach ($categories as $category): ?>
                                <button class="category-filter px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-blue-400 hover:shadow-sm transition-all duration-200" data-category="<?php echo (int)$category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Medicines Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="medicinesGrid">
                <?php foreach ($meds as $index => $m): 
                    $isAvailable = (int)$m['total_stock'] > 0 && (!$m['earliest_expiry'] || strtotime($m['earliest_expiry']) > time());
                    $isExpiringSoon = $m['earliest_expiry'] && strtotime($m['earliest_expiry']) < strtotime('+30 days');
                    $isLowStock = (int)$m['total_stock'] > 0 && (int)$m['total_stock'] <= 10;
                    $stockStatus = (int)$m['total_stock'] === 0 ? 'out-of-stock' : ($isExpiringSoon ? 'expiring' : ($isLowStock ? 'low-stock' : 'available'));
                ?>
                    <div class="medicine-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                         data-name="<?php echo strtolower(htmlspecialchars($m['name'])); ?>"
                         data-description="<?php echo strtolower(htmlspecialchars($m['description'] ?? '')); ?>"
                         data-stock="<?php echo $stockStatus; ?>"
                         data-category="<?php echo (int)($m['category_id'] ?? 0); ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s">
                        
                        <!-- Medicine Image -->
                        <div class="relative mb-6">
                            <?php if (!empty($m['image_path'])): ?>
                                <div class="relative overflow-hidden rounded-xl">
                                    <img src="<?php echo htmlspecialchars(base_url($m['image_path'])); ?>" 
                                         class="h-48 w-full object-cover transition-transform duration-300 hover:scale-105" 
                                         alt="<?php echo htmlspecialchars($m['name']); ?>" />
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent"></div>
                                </div>
                            <?php else: ?>
                                <div class="h-48 bg-gradient-to-br from-blue-100 via-purple-100 to-cyan-100 rounded-xl flex items-center justify-center relative overflow-hidden">
                                    <svg class="w-16 h-16 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                    <div class="absolute inset-0 bg-gradient-to-t from-blue-500/20 to-transparent"></div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Status Badge -->
                            <div class="absolute top-3 right-3">
                                <?php if ($stockStatus === 'out-of-stock'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        Out of Stock
                                    </span>
                                <?php elseif ($stockStatus === 'expiring'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Expiring Soon
                                    </span>
                                <?php elseif ($stockStatus === 'low-stock'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 border border-yellow-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                        </svg>
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Available
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Medicine Info -->
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-gray-900 mb-3"><?php echo htmlspecialchars($m['name']); ?></h3>
                            <p class="text-sm text-gray-600 leading-relaxed mb-4"><?php echo htmlspecialchars($m['description'] ?? 'No description available.'); ?></p>
                            
                            <!-- Stock and Expiry Information -->
                            <div class="space-y-3">
                                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-700">Stock Available</p>
                                            <p class="text-lg font-bold <?php echo (int)$m['total_stock'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                                <?php echo (int)$m['total_stock']; ?> units
                                            </p>
                                        </div>
                                    </div>
                                    <?php if ((int)$m['total_stock'] > 0): ?>
                                        <div class="text-right">
                                            <div class="w-12 h-12 relative">
                                                <svg class="w-12 h-12 transform -rotate-90" viewBox="0 0 36 36">
                                                    <path class="text-gray-200" stroke="currentColor" stroke-width="3" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                                                    <path class="text-blue-500" stroke="currentColor" stroke-width="3" stroke-dasharray="<?php echo min(100, ((int)$m['total_stock'] / 100) * 100); ?>, 100" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                                                </svg>
                                                <div class="absolute inset-0 flex items-center justify-center">
                                                    <span class="text-xs font-bold text-blue-600"><?php echo min(100, (int)$m['total_stock']); ?>%</span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($m['earliest_expiry']): ?>
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-700">Expires</p>
                                                <p class="text-lg font-bold <?php echo $isExpiringSoon ? 'text-red-600' : 'text-gray-600'; ?>">
                                                    <?php echo date('M j, Y', strtotime($m['earliest_expiry'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <?php 
                                            $daysUntilExpiry = ceil((strtotime($m['earliest_expiry']) - time()) / (60 * 60 * 24));
                                            ?>
                                            <p class="text-sm font-medium text-gray-700"><?php echo $daysUntilExpiry; ?> days</p>
                                            <p class="text-xs text-gray-500">remaining</p>
                                        </div>
                                </div>
                            <?php endif; ?>
                            </div>
                            </div>

                        <!-- Action Button -->
                            <div class="flex justify-end">
                            <?php if ($isAvailable): ?>
                                <button onclick="openRequestModal(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars($m['name']); ?>', '<?php echo htmlspecialchars($m['image_path'] ? base_url($m['image_path']) : ''); ?>')" 
                                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    Request Medicine
                                </button>
                            <?php else: ?>
                                <button class="inline-flex items-center px-6 py-3 bg-gray-300 text-gray-500 font-medium rounded-xl cursor-not-allowed opacity-60" 
                                        disabled 
                                        title="Out of stock or all batches expired">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Unavailable
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- No Results Message -->
            <div id="noResults" class="hidden">
                <div class="medicine-card p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No medicines found</h3>
                    <p class="text-gray-600 mb-6">Try adjusting your search or filter criteria</p>
                    <button onclick="clearFilters()" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Clear Filters
                    </button>
                </div>
            </div>

            <?php if (empty($meds)): ?>
                <div class="medicine-card p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No medicines available</h3>
                        <p class="text-gray-600">Check back later for available medicines.</p>
                    </div>
            <?php endif; ?>
                </div>
    </main>

    <script>
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const filterChips = document.querySelectorAll('.filter-chip');
            const categoryFilters = document.querySelectorAll('.category-filter');
            const medicineCards = document.querySelectorAll('.medicine-card');
            const medicinesGrid = document.getElementById('medicinesGrid');
            const noResults = document.getElementById('noResults');
            const medicineCount = document.getElementById('medicine-count');

            let currentFilter = 'all';
            let currentSearch = '';
            let currentCategory = 'all';

            // Search functionality
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterMedicines();
            });

            // Filter functionality
            filterChips.forEach(chip => {
                chip.addEventListener('click', function() {
                    // Update active state
                    filterChips.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    
                    currentFilter = this.dataset.filter;
                    filterMedicines();
                });
            });

            // Category filter functionality
            categoryFilters.forEach(categoryBtn => {
                categoryBtn.addEventListener('click', function() {
                    // Update active state
                    categoryFilters.forEach(c => {
                        c.classList.remove('active', 'bg-blue-50', 'border-blue-500', 'text-blue-700');
                        c.classList.add('bg-white', 'border-gray-300', 'text-gray-700');
                    });
                    this.classList.add('active', 'bg-blue-50', 'border-2', 'border-blue-500', 'text-blue-700');
                    this.classList.remove('bg-white', 'border-gray-300', 'text-gray-700');
                    
                    currentCategory = this.dataset.category;
                    filterMedicines();
                });
            });

            function filterMedicines() {
                let visibleCount = 0;
                
                medicineCards.forEach(card => {
                    const name = card.dataset.name;
                    const description = card.dataset.description;
                    const stock = card.dataset.stock;
                    const category = card.dataset.category || '0';
                    
                    let matchesSearch = true;
                    let matchesFilter = true;
                    let matchesCategory = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = name.includes(currentSearch) || description.includes(currentSearch);
                    }
                    
                    // Check filter match
                    if (currentFilter !== 'all') {
                        matchesFilter = stock === currentFilter;
                    }
                    
                    // Check category match
                    if (currentCategory !== 'all') {
                        matchesCategory = category === currentCategory;
                    }
                    
                    if (matchesSearch && matchesFilter && matchesCategory) {
                        card.style.display = 'block';
                        card.classList.add('animate-fade-in');
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                        card.classList.remove('animate-fade-in');
                    }
                });
                
                // Update count (if element exists)
                if (medicineCount) {
                medicineCount.textContent = `${visibleCount} medicines`;
                }
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (noResults) noResults.classList.remove('hidden');
                    if (medicinesGrid) medicinesGrid.classList.add('hidden');
                } else {
                    if (noResults) noResults.classList.add('hidden');
                    if (medicinesGrid) medicinesGrid.classList.remove('hidden');
                }
            }

            // Clear filters function
            window.clearFilters = function() {
                searchInput.value = '';
                currentSearch = '';
                currentFilter = 'all';
                currentCategory = 'all';
                
                filterChips.forEach(c => c.classList.remove('active'));
                filterChips[0].classList.add('active');
                
                categoryFilters.forEach(c => {
                    c.classList.remove('active', 'bg-blue-50', 'border-blue-500', 'text-blue-700');
                    c.classList.add('bg-white', 'border-gray-300', 'text-gray-700');
                });
                if (categoryFilters.length > 0) {
                    categoryFilters[0].classList.add('active', 'bg-blue-50', 'border-2', 'border-blue-500', 'text-blue-700');
                    categoryFilters[0].classList.remove('bg-white', 'border-gray-300', 'text-gray-700');
                }
                
                filterMedicines();
            };

            // Add intersection observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe all animated elements
            document.querySelectorAll('.animate-fade-in-up, .animate-fade-in, .animate-slide-in-right').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
                observer.observe(el);
            });

            // Add hover effects to cards
            document.querySelectorAll('.hover-lift').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

        // Add ripple effect to buttons (excluding sidebar links, filter chips, and category filters)
        document.querySelectorAll('a:not(.sidebar-nav a), button:not(.filter-chip):not(.category-filter)').forEach(element => {
            element.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

            // Add keyboard navigation for search
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterMedicines();
                }
            });
        });
    </script>

    <style>
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
        }

        /* Smooth transitions for all interactive elements */
        * {
            transition: all 0.2s ease-in-out;
        }

        /* Enhanced focus states */
        a:focus, button:focus, input:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        /* Loading skeleton animation */
        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }

        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }

        /* FORCE SIDEBAR TO STAY FIXED - OVERRIDE ALL OTHER STYLES */
        * {
            box-sizing: border-box !important;
        }
        
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden !important;
            height: 100% !important;
        }
        
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 9999 !important;
            overflow-y: auto !important;
            transform: none !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1) !important;
            transition: none !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }

        /* Ensure main content has proper margin and doesn't affect sidebar */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            position: relative !important;
            min-height: 100vh !important;
            background: #f9fafb !important;
        }

        /* Prevent any container from affecting sidebar position */
        .container, .wrapper, .page-wrapper {
            position: relative !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure sidebar brand and nav stay in place */
        /* Sidebar styling handled by design-system.css with resident-theme */

        /* Toast Notification Styles */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100000;
            min-width: 300px;
            max-width: 400px;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transform: translateX(100%);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .toast.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .toast.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .toast.info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .toast-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 600;
            font-size: 14px;
            margin: 0 0 2px 0;
        }

        .toast-message {
            font-size: 13px;
            margin: 0;
            opacity: 0.9;
        }

        /* Enhanced Modal Animations */
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
                max-height: 0;
            }
            to {
                opacity: 1;
                transform: translateY(0);
                max-height: 500px;
            }
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .shimmer:hover {
            animation: shimmer 0.5s ease-in-out;
        }

        /* Enhanced form styles */
        .form-section {
            transition: all 0.3s ease;
        }
        .form-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .input-focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
        }
        .file-upload-area {
            transition: all 0.3s ease;
        }
        .file-upload-area:hover {
            transform: scale(1.02);
        }
        .file-upload-area.dragover {
            border-color: #3b82f6;
            background-color: #f0f9ff;
            transform: scale(1.05);
        }
    </style>

    <!-- Request Medicine Modal -->
    <div id="requestModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); z-index: 99999; align-items: center; justify-content: center; padding: 24px; backdrop-filter: blur(8px);">
        <div style="background: white; border-radius: 16px; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.2); max-width: 600px; width: 100%; max-height: 95vh; overflow-y: auto; position: relative; animation: modalSlideIn 0.3s ease-out;">
            <!-- Modal Header -->
            <div style="background: white; padding: 24px 24px 0; border-bottom: 1px solid #e5e7eb;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0 0 4px 0;">Request Medicine</h3>
                        <p style="color: #6b7280; margin: 0; font-size: 14px;">Submit a request for medicine</p>
                    </div>
                    <button onclick="closeRequestModal()" style="color: #6b7280; background: #f9fafb; border: 1px solid #e5e7eb; cursor: pointer; padding: 8px; border-radius: 8px; transition: all 0.2s ease;" onmouseover="this.style.background='#f3f4f6'; this.style.color='#374151';" onmouseout="this.style.background='#f9fafb'; this.style.color='#6b7280';">
                        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div style="padding: 24px;">

                <!-- Modal Content -->
                <form id="requestForm" method="post" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 24px;">
                    <input type="hidden" name="medicine_id" id="modalMedicineId" />
                    
                    <!-- Medicine Info -->
                    <div style="padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div id="modalMedicineImage" style="width: 60px; height: 60px; background: #e5e7eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <svg style="width: 24px; height: 24px; color: #9ca3af;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0 0 2px 0;" id="modalMedicineName">Medicine Name</h4>
                                <p style="color: #6b7280; margin: 0; font-size: 14px;">Request this medicine</p>
                            </div>
                        </div>
                    </div>

                    <!-- Requested For -->
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Requested For</label>
                        <select name="requested_for" id="reqFor" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white; cursor: pointer;" onchange="toggleFamilyFields()" onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';">
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div id="familyMemberSelect" style="display: none;">
                        <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Select Family Member</label>
                        <select name="family_member_id" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white; cursor: pointer;" onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';">
                            <option value="">Choose a family member</option>
                            <!-- Family members will be populated by JavaScript -->
                        </select>
                    </div>

                    <!-- Family Fields (hidden by default) -->
                    <div id="familyFields" style="display: none;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Patient Name</label>
                                <input name="patient_name" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white;" placeholder="Enter patient name" onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';" />
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Date of Birth</label>
                                <input name="patient_date_of_birth" type="date" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white;" onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';" />
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Relationship</label>
                                <input name="relationship" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white;" placeholder="e.g., Father, Mother" onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';" />
                            </div>
                        </div>
                    </div>

                    <!-- Reason for Request -->
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Reason for Request</label>
                        <textarea name="reason" rows="4" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: white; resize: none; min-height: 100px; font-family: inherit; line-height: 1.5;" placeholder="Please explain why you need this medicine..." onfocus="this.style.borderColor='#6b7280'; this.style.outline='none';" onblur="this.style.borderColor='#d1d5db';"></textarea>
                        <p style="font-size: 12px; color: #6b7280; margin: 8px 0 0 0;">Provide detailed information about your medical condition or symptoms</p>
                    </div>

                    <!-- Proof of Need -->
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Proof of Need <span style="color: #ef4444;">*</span></label>
                        
                        <!-- File Upload Area -->
                        <div id="proofUploadAreaModal" style="border: 2px dashed #d1d5db; border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; background: #f9fafb;" onmouseover="this.style.borderColor='#6b7280'; this.style.backgroundColor='#f3f4f6';" onmouseout="this.style.borderColor='#d1d5db'; this.style.backgroundColor='#f9fafb';">
                            <input type="file" name="proof" accept="image/*,application/pdf" required style="display: none;" id="proofFileModal" />
                            <label for="proofFileModal" style="cursor: pointer; display: block;">
                                <div style="width: 48px; height: 48px; background: #6b7280; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
                                    <svg style="width: 24px; height: 24px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                    </svg>
                                </div>
                                <p style="font-size: 16px; color: #374151; margin: 0 0 4px 0; font-weight: 500;">Click to upload or drag and drop</p>
                                <p style="font-size: 12px; color: #6b7280; margin: 0;">JPG, PNG, or PDF (Max 10MB)</p>
                            </label>
                        </div>
                        
                        <!-- Preview Area (hidden by default) -->
                        <div id="proofPreviewModal" style="display: none; margin-top: 12px;">
                            <div style="background: white; border-radius: 8px; padding: 16px; border: 2px solid #10b981; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <h5 style="font-size: 13px; font-weight: 600; color: #059669; display: flex; align-items: center; margin: 0;">
                                        <svg style="width: 16px; height: 16px; margin-right: 6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        File Uploaded Successfully
                                    </h5>
                                    <button type="button" onclick="removeProofPreviewModal()" style="color: #dc2626; background: none; border: none; cursor: pointer; padding: 4px;">
                                        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                                <div id="proofPreviewContentModal" style="margin-top: 12px;">
                                    <!-- Preview will be inserted here -->
                                </div>
                                <p id="proofFileNameModal" style="font-size: 11px; color: #6b7280; margin-top: 8px; text-align: center; margin-bottom: 0;"></p>
                            </div>
                        </div>
                        
                        <p style="font-size: 12px; color: #6b7280; margin: 8px 0 0 0;">Upload temperature reading, medical certificate, or other proof of illness</p>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 24px; border-top: 1px solid #e5e7eb; margin-top: 24px;">
                        <button type="button" onclick="closeRequestModal()" style="padding: 12px 24px; border: 1px solid #d1d5db; color: #374151; font-weight: 500; border-radius: 8px; background: white; cursor: pointer; transition: all 0.2s ease; font-size: 14px;" onmouseover="this.style.borderColor='#6b7280'; this.style.backgroundColor='#f9fafb';" onmouseout="this.style.borderColor='#d1d5db'; this.style.backgroundColor='white';">
                            Cancel
                        </button>
                        <button type="submit" style="padding: 12px 24px; background: #374151; color: white; font-weight: 500; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s ease; font-size: 14px; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.backgroundColor='#1f2937';" onmouseout="this.style.backgroundColor='#374151';">
                            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Resident and family members data (will be populated from PHP)
        const residentData = <?php 
            $residentData = ['resident' => null, 'familyMembers' => []];
            try {
                $residentRow = db()->prepare('SELECT id, first_name, middle_initial, last_name FROM residents WHERE user_id = ? LIMIT 1');
                $residentRow->execute([$user['id']]);
                $resident = $residentRow->fetch();
                if ($resident) {
                    $residentData['resident'] = $resident;
                    $stmt = db()->prepare('SELECT id, first_name, middle_initial, last_name, relationship, date_of_birth FROM family_members WHERE resident_id = ? ORDER BY first_name');
                    $stmt->execute([$resident['id']]);
                    $residentData['familyMembers'] = $stmt->fetchAll();
                }
            } catch (Throwable $e) {
                $residentData = ['resident' => null, 'familyMembers' => []];
            }
            echo json_encode($residentData);
        ?>;

        function openRequestModal(medicineId, medicineName, medicineImage) {
            const modal = document.getElementById('requestModal');
            const medicineNameElement = document.getElementById('modalMedicineName');
            const medicineIdInput = document.getElementById('modalMedicineId');
            const medicineImageElement = document.getElementById('modalMedicineImage');
            
            // Set medicine info
            medicineNameElement.textContent = medicineName;
            medicineIdInput.value = medicineId;
            
            // Set medicine image
            if (medicineImage && medicineImage.trim() !== '') {
                medicineImageElement.innerHTML = `<img src="${medicineImage}" alt="${medicineName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;" />`;
            } else {
                medicineImageElement.innerHTML = `<svg style="width: 24px; height: 24px; color: #9ca3af;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                </svg>`;
            }
            
            // Populate "Requested For" dropdown with resident and family member names
            const requestedForSelect = document.getElementById('reqFor');
            requestedForSelect.innerHTML = '';
            
            // Add resident (self) as first option
            if (residentData.resident) {
                const residentName = `${residentData.resident.first_name} ${residentData.resident.middle_initial ? residentData.resident.middle_initial + '. ' : ''}${residentData.resident.last_name}`;
                const residentOption = document.createElement('option');
                residentOption.value = 'self';
                residentOption.textContent = residentName;
                requestedForSelect.appendChild(residentOption);
            }
            
            // Add family members
            residentData.familyMembers.forEach(member => {
                const fullName = `${member.first_name} ${member.middle_initial ? member.middle_initial + '. ' : ''}${member.last_name}`;
                const option = document.createElement('option');
                option.value = `family_${member.id}`;
                option.textContent = fullName;
                requestedForSelect.appendChild(option);
            });
            
            // Populate family members dropdown (for the separate family member select)
            const familySelect = document.querySelector('#familyMemberSelect select');
            familySelect.innerHTML = '<option value="">Choose a family member</option>';
            
            residentData.familyMembers.forEach(member => {
                const fullName = `${member.first_name} ${member.middle_initial ? member.middle_initial + '. ' : ''}${member.last_name}`;
                const option = document.createElement('option');
                option.value = member.id;
                option.textContent = `${fullName} (${member.relationship}, DOB: ${member.date_of_birth})`;
                familySelect.appendChild(option);
            });
            
            // Reset proof preview when opening modal
            removeProofPreviewModal();
            
            // Show modal
            modal.style.display = 'flex';
        }
        
        // Setup proof preview for modal (called once on page load)
        document.addEventListener('DOMContentLoaded', function() {
            const proofFileInput = document.getElementById('proofFileModal');
            if (proofFileInput) {
                proofFileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        showProofPreviewModal(file);
                    }
                });
            }
        });
        
        function showProofPreviewModal(file) {
            const proofPreview = document.getElementById('proofPreviewModal');
            const proofPreviewContent = document.getElementById('proofPreviewContentModal');
            const proofFileName = document.getElementById('proofFileNameModal');
            const proofUploadArea = document.getElementById('proofUploadAreaModal');
            
            if (!file) return;
            
            // Show file name
            proofFileName.textContent = file.name;
            
            // Check if it's an image or PDF
            if (file.type.startsWith('image/')) {
                // Show image preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    proofPreviewContent.innerHTML = `
                        <div style="display: flex; justify-content: center;">
                            <img src="${e.target.result}" alt="Proof Preview" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 2px solid #e5e7eb; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: contain;">
                        </div>
                    `;
                    proofPreview.style.display = 'block';
                    proofUploadArea.style.display = 'none';
                };
                reader.readAsDataURL(file);
            } else if (file.type === 'application/pdf') {
                // Show PDF icon with file info
                proofPreviewContent.innerHTML = `
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px; background: #f9fafb; border-radius: 8px; border: 2px solid #e5e7eb;">
                        <svg style="width: 64px; height: 64px; color: #dc2626; margin-bottom: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        <p style="font-size: 14px; font-weight: 600; color: #374151; margin: 0;">PDF Document</p>
                        <p style="font-size: 12px; color: #6b7280; margin-top: 4px; margin-bottom: 0;">${(file.size / 1024).toFixed(2)} KB</p>
                    </div>
                `;
                proofPreview.style.display = 'block';
                proofUploadArea.style.display = 'none';
            }
        }
        
        function removeProofPreviewModal() {
            const proofPreview = document.getElementById('proofPreviewModal');
            const proofFileInput = document.getElementById('proofFileModal');
            const proofUploadArea = document.getElementById('proofUploadAreaModal');
            
            // Reset file input
            if (proofFileInput) {
                proofFileInput.value = '';
            }
            
            // Hide preview and show upload area
            if (proofPreview) {
                proofPreview.style.display = 'none';
            }
            
            if (proofUploadArea) {
                proofUploadArea.style.display = 'block';
            }
        }

        function closeRequestModal() {
            const modal = document.getElementById('requestModal');
            modal.style.display = 'none';
            
            // Reset form
            document.getElementById('requestForm').reset();
            document.getElementById('familyFields').style.display = 'none';
            document.getElementById('familyMemberSelect').style.display = 'none';
            
            // Reset proof preview
            removeProofPreviewModal();
        }

        function toggleFamilyFields() {
            const reqFor = document.getElementById('reqFor').value;
            const familyFields = document.getElementById('familyFields');
            const familyMemberSelect = document.getElementById('familyMemberSelect');
            
            if (reqFor.startsWith('family_')) {
                // Family member selected - show family fields with animation
                familyFields.style.display = 'flex';
                familyMemberSelect.style.display = 'flex';
                
                // Add animation class
                familyFields.style.animation = 'slideDown 0.3s ease-out';
                
                // Set the family member ID in the hidden field
                const familyMemberId = reqFor.replace('family_', '');
                const familySelect = document.querySelector('#familyMemberSelect select');
                familySelect.value = familyMemberId;
                
                // Auto-populate family member data
                populateFamilyData(familyMemberId);
            } else {
                // Self selected - hide family fields
                familyFields.style.display = 'none';
                familyMemberSelect.style.display = 'none';
                
                // Clear family data
                clearFamilyData();
            }
        }

        function populateFamilyData(familyMemberId) {
            const familyMember = residentData.familyMembers.find(member => member.id == familyMemberId);
            if (familyMember) {
                const fullName = `${familyMember.first_name} ${familyMember.middle_initial ? familyMember.middle_initial + '. ' : ''}${familyMember.last_name}`;
                
                // Populate the form fields
                document.querySelector('input[name="patient_name"]').value = fullName;
                document.querySelector('input[name="patient_date_of_birth"]').value = familyMember.date_of_birth;
                document.querySelector('input[name="relationship"]').value = familyMember.relationship;
                
                // Disable the fields since they're auto-populated
                document.querySelector('input[name="patient_name"]').readOnly = true;
                document.querySelector('input[name="patient_date_of_birth"]').readOnly = true;
                document.querySelector('input[name="relationship"]').readOnly = true;
                
                // Add visual feedback
                const inputs = document.querySelectorAll('#familyFields input');
                inputs.forEach(input => {
                    input.style.backgroundColor = '#fef3c7';
                    input.style.borderColor = '#f59e0b';
                });
            }
        }

        function clearFamilyData() {
            // Clear the form fields
            document.querySelector('input[name="patient_name"]').value = '';
            document.querySelector('input[name="patient_date_of_birth"]').value = '';
            document.querySelector('input[name="relationship"]').value = '';
            
            // Enable the fields
            document.querySelector('input[name="patient_name"]').readOnly = false;
            document.querySelector('input[name="patient_date_of_birth"]').readOnly = false;
            document.querySelector('input[name="relationship"]').readOnly = false;
            
            // Reset visual styling
            const inputs = document.querySelectorAll('#familyFields input');
            inputs.forEach(input => {
                input.style.backgroundColor = 'white';
                input.style.borderColor = '#fbbf24';
            });
        }

        // Enhanced file upload with drag and drop
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadArea = document.getElementById('proofUploadAreaModal');
            const fileInput = document.getElementById('proofFileModal');
            
            if (fileUploadArea && fileInput) {
                // Drag and drop functionality
                fileUploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    fileUploadArea.style.borderColor = '#3b82f6';
                    fileUploadArea.style.backgroundColor = '#f0f9ff';
                    fileUploadArea.style.transform = 'scale(1.02)';
                });
                
                fileUploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    fileUploadArea.style.borderColor = '#d1d5db';
                    fileUploadArea.style.backgroundColor = '#f9fafb';
                    fileUploadArea.style.transform = 'scale(1)';
                });
                
                fileUploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    fileUploadArea.style.borderColor = '#d1d5db';
                    fileUploadArea.style.backgroundColor = '#f9fafb';
                    fileUploadArea.style.transform = 'scale(1)';
                    
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        fileInput.files = files;
                        // Trigger change event to update preview
                        const event = new Event('change');
                        fileInput.dispatchEvent(event);
                    }
                });
            }
        });

        function updateFileDisplay(file) {
            const fileUploadArea = document.querySelector('#requestModal [style*="border: 3px dashed"]');
            if (fileUploadArea) {
                // Update the display to show selected file
                const label = fileUploadArea.querySelector('label');
                if (label) {
                    const icon = label.querySelector('div');
                    const title = label.querySelector('p:first-of-type');
                    const subtitle = label.querySelector('p:last-of-type');
                    
                    if (icon && title && subtitle) {
                        // Change icon to checkmark
                        icon.innerHTML = `
                            <svg style="width: 32px; height: 32px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        `;
                        
                        // Update text
                        title.textContent = `File selected: ${file.name}`;
                        subtitle.textContent = `Size: ${(file.size / 1024 / 1024).toFixed(2)} MB`;
                        
                        // Change colors to success
                        icon.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                        title.style.color = '#065f46';
                        subtitle.style.color = '#047857';
                    }
                }
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('requestModal');
            if (e.target === modal) {
                closeRequestModal();
            }
        });

        // Form validation
        function validateForm() {
            const form = document.getElementById('requestForm');
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            let firstErrorField = null;

            // Clear previous error states
            form.querySelectorAll('.error-state').forEach(el => {
                el.classList.remove('error-state');
            });

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    if (!firstErrorField) firstErrorField = field;
                    
                    // Add error styling
                    field.style.borderColor = '#ef4444';
                    field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    field.classList.add('error-state');
                    
                    // Add error message if not exists
                    if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
                        const errorMsg = document.createElement('p');
                        errorMsg.className = 'error-message';
                        errorMsg.style.color = '#ef4444';
                        errorMsg.style.fontSize = '12px';
                        errorMsg.style.margin = '4px 0 0 0';
                        errorMsg.textContent = 'This field is required';
                        field.parentNode.appendChild(errorMsg);
                    }
                } else {
                    // Remove error styling
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                    field.classList.remove('error-state');
                    
                    // Remove error message
                    const errorMsg = field.parentNode.querySelector('.error-message');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }
            });

            // Validate file upload
            const fileInput = document.getElementById('proofFileModal');
            if (fileInput && !fileInput.files.length) {
                isValid = false;
                const fileUploadArea = document.getElementById('proofUploadAreaModal');
                if (fileUploadArea) {
                    fileUploadArea.style.borderColor = '#ef4444';
                    fileUploadArea.style.backgroundColor = '#fef2f2';
                }
            }

            return { isValid, firstErrorField };
        }

        // Real-time validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('requestForm');
            if (form) {
                // Add validation on form submit
                form.addEventListener('submit', function(e) {
                    const validation = validateForm();
                    if (!validation.isValid) {
                        e.preventDefault();
                        
                        // Focus on first error field
                        if (validation.firstErrorField) {
                            validation.firstErrorField.focus();
                        }
                        
                        // Show error toast
                        showToast('Please fill in all required fields', 'error');
                        return false;
                    }
                });

                // Real-time validation on input
                form.querySelectorAll('input, textarea, select').forEach(field => {
                    field.addEventListener('blur', function() {
                        if (this.hasAttribute('required') && !this.value.trim()) {
                            this.style.borderColor = '#ef4444';
                            this.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                        } else {
                            this.style.borderColor = '';
                            this.style.boxShadow = '';
                        }
                    });
                    
                    field.addEventListener('input', function() {
                        if (this.style.borderColor === 'rgb(239, 68, 68)' && this.value.trim()) {
                            this.style.borderColor = '';
                            this.style.boxShadow = '';
                        }
                    });
                });
            }
        });

        // Toast notification function
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icons = {
                success: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
                error: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
                warning: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>',
                info: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            };
            
            const titles = {
                success: 'Success!',
                error: 'Error!',
                warning: 'Warning!',
                info: 'Info'
            };
            
            toast.innerHTML = `
                ${icons[type]}
                <div class="toast-content">
                    <div class="toast-title">${titles[type]}</div>
                    <div class="toast-message">${message}</div>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Show toast
            setTimeout(() => {
                toast.classList.add('show');
            }, 100);
            
            // Hide toast after 4 seconds
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 4000);
        }

        // Handle form submission
        document.getElementById('requestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Validate form first
            const validation = validateForm();
            if (!validation.isValid) {
                // Focus on first error field
                if (validation.firstErrorField) {
                    validation.firstErrorField.focus();
                }
                showToast('Please fill in all required fields', 'error');
                return;
            }
            
            console.log('Form submission started');
            
            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            
            // Fix the requested_for value for the server
            const requestedFor = formData.get('requested_for');
            console.log('Original requested_for value:', requestedFor);
            
            if (requestedFor && requestedFor.startsWith('family_')) {
                // Extract family member ID and set correct values
                const familyMemberId = requestedFor.replace('family_', '');
                formData.set('requested_for', 'family');
                formData.set('family_member_id', familyMemberId);
                console.log('Set requested_for to family, family_member_id to:', familyMemberId);
            } else {
                // Self request
                formData.set('requested_for', 'self');
                formData.delete('family_member_id');
                console.log('Set requested_for to self');
            }
            
            // Show loading state
            submitButton.innerHTML = '<svg style="width: 20px; height: 20px;" class="animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> Processing...';
            submitButton.disabled = true;
            
            console.log('Final form data:', Object.fromEntries(formData));
            
            fetch('<?php echo htmlspecialchars(base_url('resident/request_new.php')); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                return response.text();
            })
            .then(data => {
                console.log('Raw response data:', data);
                
                // Check if response contains success or error indicators
                if (data.includes('SUCCESS:') || data.trim() === 'SUCCESS: Request submitted successfully') {
                    // Close modal
                    closeRequestModal();
                    
                    // Show success toast
                    showToast('Medicine request submitted successfully!', 'success');
                    
                    // Reload page to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else if (data.includes('ERROR:') || data.includes('Fatal error') || data.includes('Exception')) {
                    // Show error toast
                    let errorMessage = 'Error submitting request. Please try again.';
                    if (data.includes('ERROR:')) {
                        errorMessage = data.replace('ERROR:', '').trim();
                    } else if (data.includes('Fatal error')) {
                        errorMessage = 'Server error occurred. Please try again.';
                    }
                    
                    showToast(errorMessage, 'error');
                    
                    // Reset button
                    submitButton.innerHTML = '<svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg> Submit Request';
                    submitButton.disabled = false;
                } else {
                    // If we get here without errors, assume success
                    console.log('Assuming success based on no error indicators');
                    
                    // Close modal
                    closeRequestModal();
                    
                    // Show success toast
                    showToast('Medicine request submitted successfully!', 'success');
                    
                    // Reload page to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error submitting request. Please try again.', 'error');
                
                // Reset button
                submitButton.innerHTML = '<svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg> Submit Request';
                submitButton.disabled = false;
            });
        });

        // Header Functions
        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update clock every second
        updateClock();
        setInterval(updateClock, 1000);

        // Night mode functionality
        function initNightMode() {
            const toggle = document.getElementById('night-mode-toggle');
            const body = document.body;
            
            if (!toggle) return;
            
            // Check for saved theme preference or default to light mode
            const currentTheme = localStorage.getItem('theme') || 'light';
            if (currentTheme === 'dark') {
                body.classList.add('dark');
                toggle.innerHTML = `
                    <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z"></path>
                    </svg>
                `;
            }
            
            toggle.addEventListener('click', function() {
                body.classList.toggle('dark');
                
                if (body.classList.contains('dark')) {
                    localStorage.setItem('theme', 'dark');
                    toggle.innerHTML = `
                        <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.758 17.303a.75.75 0 00-1.061-1.06l-1.591 1.59a.75.75 0 001.06 1.061l1.591-1.59zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.697 7.757a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591z"></path>
                        </svg>
                    `;
                } else {
                    localStorage.setItem('theme', 'light');
                    toggle.innerHTML = `
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                    `;
                }
            });
        }

        // Profile dropdown functionality
        function initProfileDropdown() {
            const toggle = document.getElementById('profile-toggle');
            const menu = document.getElementById('profile-menu');
            const arrow = document.getElementById('profile-arrow');
            
            if (!toggle || !menu || !arrow) return;
            
            toggle.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (menu.classList.contains('hidden')) {
                    menu.classList.remove('hidden');
                    arrow.classList.add('rotate-180');
                } else {
                    menu.classList.add('hidden');
                    arrow.classList.remove('rotate-180');
                }
            };
            
            // Close dropdown when clicking outside
            if (!window.browseProfileDropdownClickHandler) {
                window.browseProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.browseProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const menu = document.getElementById('profile-menu');
                    const arrow = document.getElementById('profile-arrow');
                    if (menu) menu.classList.add('hidden');
                    if (arrow) arrow.classList.remove('rotate-180');
                }
            });
        }

        // Initialize night mode and profile dropdown
        initNightMode();
        initProfileDropdown();
    </script>
    

</body>
</html>


