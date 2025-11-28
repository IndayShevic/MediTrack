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

// Get resident information
$residentRow = db()->prepare('SELECT id, date_of_birth FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
if (!$resident) { echo 'Resident profile not found.'; exit; }
$residentId = (int)$resident['id'];

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

// Get allocations for this resident
$allocations = [];
if ($is_senior) {
    $allocations = db()->prepare('
        SELECT 
            sa.id,
            sa.status,
            sa.must_claim_before,
            sa.released_at,
            ap.program_name,
            m.name as medicine_name,
            ap.quantity_per_senior,
            u.first_name as bhw_first_name,
            u.last_name as bhw_last_name
        FROM senior_allocations sa
        JOIN allocation_programs ap ON ap.id = sa.program_id
        JOIN medicines m ON m.id = ap.medicine_id
        JOIN users u ON u.id = sa.bhw_id
        WHERE sa.resident_id = ?
        ORDER BY sa.must_claim_before ASC
    ');
    $allocations->execute([$residentId]);
    $allocations = $allocations->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Allocations · Resident</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/resident-animations.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    </style>
    <style>
        /* CRITICAL: Override design-system.css sidebar styles - MUST be after design-system.css */
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
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        /* Remove hover effects */
        .sidebar-nav a:hover {
            background: transparent !important;
            color: inherit !important;
        }
        
        .sidebar-nav a {
            transition: none !important;
        }
        
        /* CRITICAL: Override mobile menu transforms */
        .sidebar.open {
            transform: none !important;
        }
        
        /* Ensure sidebar never transforms */
        .sidebar {
            transform: none !important;
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
        .sidebar-brand {
            position: relative !important;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            color: white !important;
            padding: 1.5rem !important;
            border-bottom: 1px solid #e5e7eb !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
        }
        
        .sidebar-nav {
            position: relative !important;
            padding: 1rem !important;
        }
        
        .sidebar-nav a {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            padding: 0.75rem 1rem !important;
            margin-bottom: 0.25rem !important;
            border-radius: 0.5rem !important;
            color: #374151 !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            transition: none !important;
        }
        
        /* Removed hover effects for sidebar navigation */
        
        .sidebar-nav a.active {
            background: #dbeafe !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
        }

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
        .allocation-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }
        .allocation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4);
        }
        .status-indicator {
            position: relative;
            overflow: hidden;
        }
        .status-indicator::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 2s infinite;
        }
        .progress-ring {
            transform: rotate(-90deg);
        }
        .progress-ring-circle {
            stroke-dasharray: 251.2;
            stroke-dashoffset: 251.2;
            transition: stroke-dashoffset 0.5s ease-in-out;
        }
        .senior-badge {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: white;
            box-shadow: 0 4px 14px 0 rgba(251, 191, 36, 0.39);
        }
        .eligibility-card {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1px solid rgba(59, 130, 246, 0.2);
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
            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>">
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
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
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 mb-1">My Allocations</h1>
                <p class="text-gray-600"><?php echo $is_senior ? 'Senior citizen medicine allocations and programs' : 'View your medicine allocations and benefits'; ?></p>
        </div>

        <!-- Content -->
        <div class="content-body">
            <?php if ($is_senior): ?>
                <!-- Senior Citizen Allocations -->
                <?php if (!empty($allocations)): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
                        <?php foreach ($allocations as $index => $allocation): 
                            $daysUntilExpiry = ceil((strtotime($allocation['must_claim_before']) - time()) / (60 * 60 * 24));
                            $isExpiringSoon = $daysUntilExpiry <= 7;
                            $progressPercentage = max(0, min(100, (30 - $daysUntilExpiry) / 30 * 100));
                        ?>
                            <div class="allocation-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                                 style="animation-delay: <?php echo $index * 0.1; ?>s">
                                
                                <!-- Allocation Header -->
                                <div class="flex items-center justify-between mb-6">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-mono text-sm text-gray-600">#<?php echo (int)$allocation['id']; ?></div>
                                            <div class="text-xs text-gray-500">Allocation</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Status Badge -->
                                    <div class="status-indicator">
                                        <?php if ($allocation['status'] === 'pending'): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Pending
                                            </span>
                                        <?php elseif ($allocation['status'] === 'released'): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Ready
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                                <?php echo ucfirst($allocation['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Program Info -->
                                <div class="mb-6">
                                    <h3 class="text-xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($allocation['program_name']); ?></h3>
                                    <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($allocation['medicine_name']); ?></p>
                                    
                                    <!-- Quantity Badge -->
                                    <div class="inline-flex items-center px-3 py-1 bg-blue-50 rounded-full">
                                        <svg class="w-4 h-4 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-blue-700"><?php echo (int)$allocation['quantity_per_senior']; ?> units</span>
                                    </div>
                                </div>

                                <!-- Progress Ring -->
                                <div class="mb-6 flex items-center justify-center">
                                    <div class="relative w-20 h-20">
                                        <svg class="w-20 h-20 progress-ring">
                                            <circle class="text-gray-200" stroke="currentColor" stroke-width="8" fill="transparent" r="40" cx="40" cy="40"/>
                                            <circle class="progress-ring-circle <?php echo $isExpiringSoon ? 'text-red-500' : 'text-blue-500'; ?>" 
                                                    stroke="currentColor" 
                                                    stroke-width="8" 
                                                    fill="transparent" 
                                                    r="40" 
                                                    cx="40" 
                                                    cy="40"
                                                    style="stroke-dashoffset: <?php echo 251.2 - ($progressPercentage / 100) * 251.2; ?>"/>
                                        </svg>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-xs font-bold <?php echo $isExpiringSoon ? 'text-red-600' : 'text-blue-600'; ?>">
                                                <?php echo $daysUntilExpiry; ?>d
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Allocation Details -->
                                <div class="space-y-3">
                                    <!-- BHW Assignment -->
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                            <span class="text-sm font-medium text-gray-700">Assigned BHW</span>
                                        </div>
                                        <span class="text-sm text-gray-600"><?php echo htmlspecialchars(trim($allocation['bhw_first_name'] . ' ' . $allocation['bhw_last_name'])); ?></span>
                                    </div>

                                    <!-- Claim Deadline -->
                                    <div class="flex items-center justify-between p-3 <?php echo $isExpiringSoon ? 'bg-red-50 border border-red-200' : 'bg-orange-50 border border-orange-200'; ?> rounded-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 <?php echo $isExpiringSoon ? 'text-red-500' : 'text-orange-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span class="text-sm font-medium <?php echo $isExpiringSoon ? 'text-red-700' : 'text-orange-700'; ?>">Claim Deadline</span>
                                        </div>
                                        <span class="text-sm <?php echo $isExpiringSoon ? 'text-red-600' : 'text-orange-600'; ?>">
                                            <?php echo date('M j, Y', strtotime($allocation['must_claim_before'])); ?>
                                        </span>
                                    </div>

                                    <?php if ($allocation['released_at']): ?>
                                    <!-- Release Info -->
                                    <div class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-sm font-medium text-green-700">Released</span>
                                        </div>
                                        <span class="text-sm text-green-600">
                                            <?php echo date('M j, Y g:i A', strtotime($allocation['released_at'])); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Button -->
                                <div class="mt-6 flex justify-end">
                                    <?php if ($allocation['status'] === 'released'): ?>
                                        <button class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white font-medium rounded-xl hover:from-green-700 hover:to-green-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Claim Medicine
                                        </button>
                                    <?php else: ?>
                                        <button class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-500 font-medium rounded-xl cursor-not-allowed opacity-60" disabled>
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Pending Release
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="allocation-card p-12 text-center rounded-2xl shadow-lg">
                        <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                            <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">No allocations available</h3>
                        <p class="text-gray-600">You don't have any senior citizen medicine allocations at this time.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Non-Senior Citizen View -->
                <div class="eligibility-card p-12 text-center rounded-2xl shadow-lg animate-fade-in-up">
                    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">Allocations Available for Senior Citizens</h3>
                    <p class="text-gray-600 mb-6 text-lg">Senior citizen medicine allocations are available for residents aged 60 and above.</p>
                    
                    <!-- Eligibility Requirements -->
                    <div class="bg-white border border-blue-200 rounded-xl p-6 max-w-lg mx-auto mb-6">
                        <div class="flex items-center justify-center mb-4">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-3">Eligibility Requirements</h4>
                        <div class="space-y-2 text-left">
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Must be 60 years or older</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Registered as a resident</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Valid identification required</span>
                            </div>
                        </div>
                    </div>

                    <!-- Benefits Information -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="bg-white border border-gray-200 rounded-lg p-4">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            </div>
                            <h5 class="font-semibold text-gray-900 mb-1">Free Medicines</h5>
                            <p class="text-xs text-gray-600">Essential medicines at no cost</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            <h5 class="font-semibold text-gray-900 mb-1">Priority Access</h5>
                            <p class="text-xs text-gray-600">First priority in medicine distribution</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-3">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h5 class="font-semibold text-gray-900 mb-1">Regular Programs</h5>
                            <p class="text-xs text-gray-600">Monthly allocation programs</p>
                        </div>
                    </div>

                    <!-- Call to Action -->
                    <div class="bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-xl p-6">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">Ready to become eligible?</h4>
                        <p class="text-sm text-gray-600 mb-4">Contact your local health center to learn more about senior citizen programs and benefits.</p>
                        <button class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            Contact Health Center
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
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

        // Add ripple effect to buttons (excluding sidebar links)
        document.querySelectorAll('a:not(.sidebar-nav a), button').forEach(element => {
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

            // Animate progress rings
            document.querySelectorAll('.progress-ring-circle').forEach(ring => {
                const offset = ring.style.strokeDashoffset;
                ring.style.strokeDashoffset = '251.2';
                
                setTimeout(() => {
                    ring.style.strokeDashoffset = offset;
                }, 500);
            });

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
                if (!window.residentProfileDropdownClickHandler) {
                    window.residentProfileDropdownClickHandler = function(e) {
                        const toggle = document.getElementById('profile-toggle');
                        const menu = document.getElementById('profile-menu');
                        if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.add('hidden');
                            const arrow = document.getElementById('profile-arrow');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                    };
                    document.addEventListener('click', window.residentProfileDropdownClickHandler);
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

            // Initialize profile dropdown when page loads
            initProfileDropdown();
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
    </style>
</body>
</html>


