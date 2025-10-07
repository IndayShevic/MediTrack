<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['resident']);
$user = current_user();

$residentRow = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
if (!$resident) { echo 'Resident profile not found.'; exit; }
$residentId = (int)$resident['id'];

$rows = db()->prepare('
    SELECT 
        r.id, 
        m.name AS medicine, 
        r.status, 
        r.requested_for,
        r.patient_name,
        r.patient_age,
        r.relationship,
        r.reason,
        r.rejection_reason,
        r.created_at,
        r.approved_at,
        r.rejected_at,
        bhw.first_name AS bhw_first_name,
        bhw.last_name AS bhw_last_name,
        approver.first_name AS approver_first_name,
        approver.last_name AS approver_last_name,
        rejector.first_name AS rejector_first_name,
        rejector.last_name AS rejector_last_name
    FROM requests r 
    JOIN medicines m ON m.id = r.medicine_id 
    LEFT JOIN users bhw ON bhw.id = r.bhw_id
    LEFT JOIN users approver ON approver.id = r.approved_by
    LEFT JOIN users rejector ON rejector.id = r.rejected_by
    WHERE r.resident_id = ? 
    ORDER BY r.id DESC
');
$rows->execute([$residentId]);
$requests = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Requests · Resident</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
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
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25);
        }
        .request-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }
        .request-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4);
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                My Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="text-red-600 hover:text-red-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div class="animate-fade-in-up">
                    <div class="flex items-center space-x-3 mb-2">
                        <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 via-blue-800 to-purple-800 bg-clip-text text-transparent">
                            My Requests
                        </h1>
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-gray-600 text-lg">Track your medicine requests and their status</p>
                    <div class="flex items-center space-x-2 mt-2">
                        <div class="w-1 h-1 bg-blue-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-purple-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-cyan-400 rounded-full"></div>
                        <span class="text-sm text-gray-500 ml-2">Live tracking</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4 animate-slide-in-right">
                    <div class="text-right glass-effect px-4 py-2 rounded-xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Total</div>
                        <div class="text-sm font-semibold text-gray-900" id="request-count"><?php echo count($requests); ?> requests</div>
                    </div>
                </div>
            </div>
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
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="searchInput" placeholder="Search requests..." class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300">
                            </div>
                        </div>
                        
                        <!-- Filter Chips -->
                        <div class="flex flex-wrap gap-2">
                            <button class="filter-chip active px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50" data-filter="all">
                                All Requests
                            </button>
                            <button class="filter-chip px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50" data-filter="submitted">
                                Pending
                            </button>
                            <button class="filter-chip px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50" data-filter="approved">
                                Approved
                            </button>
                            <button class="filter-chip px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50" data-filter="rejected">
                                Rejected
                            </button>
                            <button class="filter-chip px-4 py-2 bg-white border border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50" data-filter="ready_to_claim">
                                Ready to Claim
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requests Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6" id="requestsGrid">
                <?php foreach ($requests as $index => $r): ?>
                    <div class="request-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                         data-medicine="<?php echo strtolower(htmlspecialchars($r['medicine'])); ?>"
                         data-status="<?php echo $r['status']; ?>"
                         data-patient="<?php echo strtolower(htmlspecialchars($r['patient_name'] ?? '')); ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s">
                        
                        <!-- Request Header -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-mono text-sm text-gray-600">#<?php echo (int)$r['id']; ?></div>
                                    <div class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                                </div>
                </div>
                            
                            <!-- Status Badge -->
                            <div class="status-indicator">
                                            <?php if ($r['status'] === 'submitted'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    Pending
                                                </span>
                                            <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    Approved
                                                </span>
                                <?php elseif ($r['status'] === 'rejected'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                    Rejected
                                                </span>
                                <?php elseif ($r['status'] === 'ready_to_claim'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                        Ready to Claim
                                    </span>
                                <?php elseif ($r['status'] === 'claimed'): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Claimed
                                    </span>
                                            <?php endif; ?>
                            </div>
                        </div>

                        <!-- Medicine Info -->
                        <div class="mb-4">
                            <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($r['medicine']); ?></h3>
                            
                            <!-- Requested For -->
                            <div class="flex items-center space-x-2 mb-3">
                                <?php if ($r['requested_for'] === 'self'): ?>
                                    <div class="flex items-center space-x-2 px-3 py-1 bg-blue-50 rounded-full">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-blue-700">Self</span>
                                    </div>
                                <?php else: ?>
                                    <div class="flex items-center space-x-2 px-3 py-1 bg-purple-50 rounded-full">
                                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-purple-700">Family</span>
                                    </div>
                                    <?php if ($r['patient_name']): ?>
                                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($r['patient_name']); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- BHW Assignment -->
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center space-x-2">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <span class="text-sm font-medium text-gray-700">Assigned BHW:</span>
                                <span class="text-sm text-gray-600">
                                    <?php if ($r['bhw_first_name'] && $r['bhw_last_name']): ?>
                                        <?php echo htmlspecialchars($r['bhw_first_name'] . ' ' . $r['bhw_last_name']); ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">Not assigned</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Approval/Rejection Info -->
                        <?php if ($r['status'] === 'approved' && $r['approver_first_name']): ?>
                            <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span class="text-sm font-medium text-green-700">Approved by</span>
                                </div>
                                <div class="text-sm text-green-800 font-medium">
                                    <?php echo htmlspecialchars($r['approver_first_name'] . ' ' . $r['approver_last_name']); ?>
                                </div>
                                <?php if ($r['approved_at']): ?>
                                    <div class="text-xs text-green-600">
                                        <?php echo date('M j, Y g:i A', strtotime($r['approved_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($r['status'] === 'rejected' && $r['rejector_first_name']): ?>
                            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    <span class="text-sm font-medium text-red-700">Rejected by</span>
                                </div>
                                <div class="text-sm text-red-800 font-medium">
                                    <?php echo htmlspecialchars($r['rejector_first_name'] . ' ' . $r['rejector_last_name']); ?>
                                </div>
                                <?php if ($r['rejected_at']): ?>
                                    <div class="text-xs text-red-600">
                                        <?php echo date('M j, Y g:i A', strtotime($r['rejected_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Action Button -->
                        <div class="flex justify-end">
                            <button onclick="showRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" 
                                    class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- No Results Message -->
            <div id="noResults" class="hidden">
                <div class="request-card p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No requests found</h3>
                    <p class="text-gray-600 mb-6">Try adjusting your search or filter criteria</p>
                    <button onclick="clearFilters()" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Clear Filters
                    </button>
                </div>
            </div>

            <?php if (empty($requests)): ?>
                <div class="request-card p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No requests yet</h3>
                    <p class="text-gray-600 mb-6">Start by browsing available medicines and submitting your first request.</p>
                    <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            Browse Medicines
                        </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Request Details Modal -->
    <div id="requestDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-semibold text-gray-800">Request Details</h2>
                    <button onclick="closeRequestDetails()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div id="requestDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const filterChips = document.querySelectorAll('.filter-chip');
            const requestCards = document.querySelectorAll('.request-card');
            const requestsGrid = document.getElementById('requestsGrid');
            const noResults = document.getElementById('noResults');
            const requestCount = document.getElementById('request-count');

            let currentFilter = 'all';
            let currentSearch = '';

            // Search functionality
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterRequests();
            });

            // Filter functionality
            filterChips.forEach(chip => {
                chip.addEventListener('click', function() {
                    // Update active state
                    filterChips.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    
                    currentFilter = this.dataset.filter;
                    filterRequests();
                });
            });

            function filterRequests() {
                let visibleCount = 0;
                
                requestCards.forEach(card => {
                    const medicine = card.dataset.medicine;
                    const status = card.dataset.status;
                    const patient = card.dataset.patient;
                    
                    let matchesSearch = true;
                    let matchesFilter = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = medicine.includes(currentSearch) || patient.includes(currentSearch);
                    }
                    
                    // Check filter match
                    if (currentFilter !== 'all') {
                        matchesFilter = status === currentFilter;
                    }
                    
                    if (matchesSearch && matchesFilter) {
                        card.style.display = 'block';
                        card.classList.add('animate-fade-in');
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                        card.classList.remove('animate-fade-in');
                    }
                });
                
                // Update count
                requestCount.textContent = `${visibleCount} requests`;
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
                    requestsGrid.classList.add('hidden');
                } else {
                    noResults.classList.add('hidden');
                    requestsGrid.classList.remove('hidden');
                }
            }

            // Clear filters function
            window.clearFilters = function() {
                searchInput.value = '';
                currentSearch = '';
                currentFilter = 'all';
                
                filterChips.forEach(c => c.classList.remove('active'));
                filterChips[0].classList.add('active');
                
                filterRequests();
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
                    this.style.transform = 'translateY(-4px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
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

            // Add keyboard navigation for search
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterRequests();
                }
            });
        });

        function showRequestDetails(request) {
            const modal = document.getElementById('requestDetailsModal');
            const content = document.getElementById('requestDetailsContent');
            
            // Build the content
            let html = `
                <div class="space-y-6">
                    <!-- Request Info -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Request ID</label>
                            <div class="text-lg font-mono text-gray-600">#${request.id}</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <div class="mt-1">
                                ${getStatusBadge(request.status)}
                            </div>
                        </div>
                    </div>

                    <!-- Medicine Info -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Medicine</label>
                        <div class="text-lg font-semibold text-gray-900">${request.medicine}</div>
                    </div>

                    <!-- Request Details -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Requested For</label>
                            <div class="text-gray-900">
                                ${request.requested_for === 'self' ? 
                                    '<span class="text-blue-600 font-medium">Self</span>' : 
                                    '<span class="text-purple-600 font-medium">Family Member</span>'
                                }
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Created</label>
                            <div class="text-gray-900">${new Date(request.created_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'short', 
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            })}</div>
                        </div>
                    </div>

                    ${request.requested_for === 'family' && request.patient_name ? `
                    <!-- Patient Info -->
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-purple-900 mb-3">Patient Information</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-purple-700 mb-1">Name</label>
                                <div class="text-purple-900">${request.patient_name || '-'}</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-purple-700 mb-1">Age</label>
                                <div class="text-purple-900">${request.patient_age || '-'}</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-purple-700 mb-1">Relationship</label>
                                <div class="text-purple-900">${request.relationship || '-'}</div>
                            </div>
                        </div>
                    </div>
                    ` : ''}

                    <!-- Reason -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason for Request</label>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <p class="text-gray-900">${request.reason || 'No reason provided'}</p>
                        </div>
                    </div>

                    <!-- BHW Assignment -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Assigned BHW</label>
                        <div class="text-gray-900">
                            ${request.bhw_first_name && request.bhw_last_name ? 
                                `${request.bhw_first_name} ${request.bhw_last_name}` : 
                                '<span class="text-gray-400">Not assigned</span>'
                            }
                        </div>
                    </div>

                    ${request.status === 'approved' && request.approver_first_name ? `
                    <!-- Approval Info -->
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-green-900 mb-3">Approval Information</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-green-700 mb-1">Approved By</label>
                                <div class="text-green-900 font-medium">${request.approver_first_name} ${request.approver_last_name}</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-green-700 mb-1">Approved At</label>
                                <div class="text-green-900">${request.approved_at ? new Date(request.approved_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'short', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : '-'}</div>
                            </div>
                        </div>
                    </div>
                    ` : ''}

                    ${request.status === 'rejected' && request.rejector_first_name ? `
                    <!-- Rejection Info -->
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-red-900 mb-3">Rejection Information</h3>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-red-700 mb-1">Rejected By</label>
                                    <div class="text-red-900 font-medium">${request.rejector_first_name} ${request.rejector_last_name}</div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-red-700 mb-1">Rejected At</label>
                                    <div class="text-red-900">${request.rejected_at ? new Date(request.rejected_at).toLocaleDateString('en-US', { 
                                        year: 'numeric', 
                                        month: 'short', 
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    }) : '-'}</div>
                                </div>
                            </div>
                            ${request.rejection_reason ? `
                            <div>
                                <label class="block text-sm font-medium text-red-700 mb-1">Rejection Reason</label>
                                <div class="bg-white border border-red-200 rounded-lg p-3">
                                    <p class="text-red-900">${request.rejection_reason}</p>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            content.innerHTML = html;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeRequestDetails() {
            const modal = document.getElementById('requestDetailsModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = 'auto';
        }

        function getStatusBadge(status) {
            const badges = {
                'submitted': '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Pending</span>',
                'approved': '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Approved</span>',
                'rejected': '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Rejected</span>',
                'ready_to_claim': '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>Ready to Claim</span>',
                'claimed': '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Claimed</span>'
            };
            return badges[status] || '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">' + status + '</span>';
        }

        // Close modal when clicking outside
        document.getElementById('requestDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRequestDetails();
            }
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


