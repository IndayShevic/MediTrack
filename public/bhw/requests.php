<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
require_once __DIR__ . '/../../config/mail.php';
$user = current_user();
$bhw_purok_id = $user['purok_id'] ?? 0;

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0) {
        if ($action === 'approve') {
            // allocate 1 unit FEFO
            $q = db()->prepare('SELECT medicine_id FROM requests WHERE id=? AND bhw_id=? AND status="submitted"');
            $q->execute([$id, $user['id']]);
            $row = $q->fetch();
            if ($row) {
                $allocated = fefoAllocate((int)$row['medicine_id'], 1, $id);
                if ($allocated >= 1) {
                    $u = db()->prepare('UPDATE requests SET status="approved" WHERE id=?');
                    $u->execute([$id]);
                    // notify resident
                    $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id WHERE rq.id=?");
                    $r->execute([$id]);
                    $rec = $r->fetch();
                    if ($rec && !empty($rec['email'])) {
                        $html = email_template(
                            'Request approved',
                            'Your medicine request was approved.',
                            '<p>Please proceed to your assigned barangay health center to claim.</p>',
                            'View My Requests',
                            base_url('resident/requests.php')
                        );
                        send_email($rec['email'], $rec['name'] ?? 'Resident', 'Request approved', $html);
                    }
                }
            }
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            error_log('BHW Medicine Rejection: Request ID: ' . $id . ', Reason: ' . $reason);
            file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Request ID: ' . $id . ', Reason: ' . $reason . "\n", FILE_APPEND);
            
            $u = db()->prepare('UPDATE requests SET status="rejected", rejection_reason=? WHERE id=? AND bhw_id=?');
            $u->execute([$reason, $id, $user['id']]);
            
            // notify resident
            $r = db()->prepare("SELECT u.email, CONCAT(IFNULL(u.first_name,''), ' ', IFNULL(u.last_name,'')) AS name FROM requests rq JOIN residents res ON res.id=rq.resident_id JOIN users u ON u.id=res.user_id WHERE rq.id=?");
            $r->execute([$id]);
            $rec = $r->fetch();
            if ($rec && !empty($rec['email'])) {
                error_log('BHW Medicine Rejection: Sending email to ' . $rec['email'] . ' with reason: ' . $reason);
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Sending email to ' . $rec['email'] . ' with reason: ' . $reason . "\n", FILE_APPEND);
                
                $html = email_template(
                    'Request rejected',
                    'Your medicine request was rejected.',
                    '<p>' . ($reason ? 'Reason: <b>' . htmlspecialchars($reason) . '</b>' : 'Please contact your BHW for details.') . '</p>',
                    'View My Requests',
                    base_url('resident/requests.php')
                );
                $success = send_email($rec['email'], $rec['name'] ?? 'Resident', 'Request rejected', $html);
                error_log('BHW Medicine Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No'));
                file_put_contents('bhw_debug.log', date('Y-m-d H:i:s') . ' - BHW Medicine Rejection: Email sent successfully: ' . ($success ? 'Yes' : 'No') . "\n", FILE_APPEND);
            }
        }
    }
    redirect_to('bhw/requests.php');
}

$rows = db()->prepare('SELECT r.id, m.name AS medicine, r.status, r.created_at, res.first_name, res.last_name FROM requests r JOIN medicines m ON m.id=r.medicine_id JOIN residents res ON res.id=r.resident_id WHERE r.bhw_id=? ORDER BY r.id DESC');
$rows->execute([$user['id']]);
$reqs = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Requests · BHW</title>
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
        
        .sidebar-nav a {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, background-color;
        }
        
        .sidebar-nav a:active {
            transform: scale(0.98);
        }
        
        /* Optimize rendering */
        .sidebar {
            will-change: scroll-position;
        }
        
        /* Preload hover states */
        .sidebar-nav a:hover {
            transform: translateX(2px);
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span style="flex: 1;">Medicine Requests</span>
                <?php if ($notification_counts['pending_requests'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_requests']; ?></span>
                <?php endif; ?>
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
                            Medicine Requests
                        </h1>
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-gray-600 text-lg">Review and approve medicine requests from residents</p>
                    <div class="flex items-center space-x-2 mt-2">
                        <div class="w-1 h-1 bg-blue-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-purple-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-cyan-400 rounded-full"></div>
                        <span class="text-sm text-gray-500 ml-2">Live requests</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4 animate-slide-in-right">
                    <div class="text-right glass-effect px-4 py-2 rounded-xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Total requests</div>
                        <div class="text-sm font-semibold text-gray-900" id="request-count"><?php echo count($reqs); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content-body">
            <?php if (!empty($reqs)): ?>
                <!-- Search and Filter -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <div class="relative">
                                <input type="text" id="searchInput" placeholder="Search requests..." 
                                       class="w-full px-4 py-3 pl-12 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                                <svg class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button class="filter-chip active px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="all">
                                All
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="submitted">
                                Pending
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="approved">
                                Approved
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="rejected">
                                Rejected
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Requests Grid -->
                <div id="requestsGrid" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
                    <?php foreach ($reqs as $index => $r): ?>
                        <div class="request-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                             data-resident="<?php echo strtolower(htmlspecialchars($r['first_name'] . ' ' . $r['last_name'])); ?>"
                             data-medicine="<?php echo strtolower(htmlspecialchars($r['medicine'])); ?>"
                             data-status="<?php echo $r['status']; ?>"
                             style="animation-delay: <?php echo $index * 0.1; ?>s">
                            
                            <!-- Request Header -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="font-mono text-sm text-gray-600">#<?php echo (int)$r['id']; ?></div>
                                        <div class="text-xs text-gray-500">Request</div>
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
                                            <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                    Rejected
                                                </span>
                                            <?php endif; ?>
                                </div>
                            </div>

                            <!-- Request Details -->
                            <div class="space-y-3 mb-6">
                                <!-- Resident Info -->
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-gray-700">Resident</span>
                                    </div>
                                    <span class="text-sm text-gray-600"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></span>
                                </div>

                                <!-- Medicine Info -->
                                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-blue-700">Medicine</span>
                                    </div>
                                    <span class="text-sm text-blue-600"><?php echo htmlspecialchars($r['medicine']); ?></span>
                                </div>

                                <!-- Date Info -->
                                <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-purple-700">Requested</span>
                                    </div>
                                    <span class="text-sm text-purple-600"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></span>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex justify-end space-x-2">
                                            <?php if ($r['status'] === 'submitted'): ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
                                                        <input type="hidden" name="action" value="approve" />
                                        <button class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white font-medium rounded-xl hover:from-green-700 hover:to-green-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            Approve
                                                        </button>
                                                    </form>
                                    <button onclick="openRejectModal(<?php echo (int)$r['id']; ?>)" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white font-medium rounded-xl hover:from-red-700 hover:to-red-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                                </svg>
                                                                Reject
                                                            </button>
                                <?php else: ?>
                                    <span class="text-gray-500 text-sm py-2">No actions available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- No Results Message -->
                <div id="noResults" class="hidden text-center py-12">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                                                        </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No requests found</h3>
                    <p class="text-gray-600 mb-4">Try adjusting your search or filter criteria.</p>
                    <button onclick="clearFilters()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Clear Filters
                    </button>
                                                </div>
                                            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state-card hover-lift animate-fade-in-up p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">No Requests Found</h3>
                    <p class="text-gray-600 mb-6 text-lg">No medicine requests have been submitted by residents in your assigned area.</p>
                    
                    <div class="bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-xl p-6 max-w-2xl mx-auto">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">What you can do:</h4>
                        <div class="space-y-2 text-left">
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Check if residents are aware of the request system</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Verify your assigned area coverage</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Review resident registrations</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 flex items-center justify-center z-50 p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto animate-scale-in border border-gray-200">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Reject Request</h3>
                            <p class="text-gray-600">Provide a reason for rejection</p>
                        </div>
                    </div>
                    <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form method="post" class="space-y-4">
                    <input type="hidden" name="id" id="rejectRequestId" />
                    <input type="hidden" name="action" value="reject" />
                    
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Rejection Reason</label>
                        <textarea name="reason" rows="4" required
                                  class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-red-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm resize-none" 
                                  placeholder="Enter the reason for rejecting this request..."></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeRejectModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white font-medium rounded-xl hover:from-red-700 hover:to-red-800 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Reject Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
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
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterRequests();
            });
        }

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
                const resident = card.dataset.resident;
                const medicine = card.dataset.medicine;
                const status = card.dataset.status;
                
                let matchesSearch = true;
                let matchesFilter = true;
                
                // Check search match
                if (currentSearch) {
                    matchesSearch = resident.includes(currentSearch) || medicine.includes(currentSearch);
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
            if (requestCount) {
                requestCount.textContent = visibleCount;
            }
            
            // Show/hide no results message
            if (visibleCount === 0) {
                if (noResults) noResults.classList.remove('hidden');
                if (requestsGrid) requestsGrid.classList.add('hidden');
            } else {
                if (noResults) noResults.classList.add('hidden');
                if (requestsGrid) requestsGrid.classList.remove('hidden');
            }
        }

        // Clear filters function
        window.clearFilters = function() {
            if (searchInput) searchInput.value = '';
            currentSearch = '';
            currentFilter = 'all';
            
            filterChips.forEach(c => c.classList.remove('active'));
            filterChips[0].classList.add('active');
            
            filterRequests();
        };

        // Reject modal functions
        window.openRejectModal = function(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectModal').classList.remove('hidden');
            document.getElementById('rejectModal').classList.add('flex');
        };

        window.closeRejectModal = function() {
            document.getElementById('rejectModal').classList.add('hidden');
            document.getElementById('rejectModal').classList.remove('flex');
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
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterRequests();
                }
            });
        }

        // Close modal on outside click
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectModal();
            }
        });
    });
    </script>
</body>
</html>


