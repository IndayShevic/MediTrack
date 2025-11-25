<?php
require_once '../../config/db.php';
require_once __DIR__ . '/includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ' . base_url('login.php'));
    exit;
}

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

$user = $_SESSION['user'];
$resident_id = $user['id'];

// Get updated user data with profile image
$userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$user['id']]);
$user_data = $userStmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
    $_SESSION['user'] = array_merge($_SESSION['user'], $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Get resident info for senior citizen check
$residentRow = db()->prepare('SELECT id, date_of_birth FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
$residentDbId = $resident ? (int)$resident['id'] : 0;

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
    $pendingStmt->execute([$residentDbId]);
    $pending_requests = (int)$pendingStmt->fetch()['count'];
} catch (Throwable $e) {
    $pending_requests = 0;
}

// Get recent requests for header notifications
$recent_requests = [];
try {
    $recentStmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name AS medicine_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id WHERE r.resident_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $recentStmt->execute([$residentDbId]);
    $recent_requests = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $recent_requests = [];
}

// Get medicine history with detailed information
$stmt = db()->prepare('
    SELECT 
        r.*,
        m.name as medicine_name,
        m.description as medicine_description,
        m.image_path as medicine_image,
        b.batch_code,
        b.expiry_date,
        b.quantity_available,
        rf.quantity as fulfillment_quantity,
        CONCAT(res.first_name, \' \', COALESCE(res.middle_initial, \'\'), CASE WHEN res.middle_initial IS NOT NULL THEN \' \' ELSE \'\' END, res.last_name) AS resident_full_name,
        CASE 
            WHEN r.status = "claimed" THEN "Successfully Claimed"
            WHEN r.status = "approved" THEN "Ready to Claim"
            WHEN r.status = "rejected" THEN "Request Rejected"
            WHEN r.status = "submitted" THEN "Under Review"
            ELSE r.status
        END as status_display,
        CASE 
            WHEN r.status = "claimed" THEN "success"
            WHEN r.status = "approved" THEN "info"
            WHEN r.status = "rejected" THEN "danger"
            WHEN r.status = "submitted" THEN "warning"
            ELSE "secondary"
        END as status_type
    FROM requests r
    JOIN medicines m ON r.medicine_id = m.id
    LEFT JOIN request_fulfillments rf ON r.id = rf.request_id
    LEFT JOIN medicine_batches b ON rf.batch_id = b.id
    LEFT JOIN residents res ON res.id = r.resident_id
    WHERE r.resident_id = ?
    ORDER BY r.created_at DESC
');
$stmt->execute([$resident_id]);
$requests = $stmt->fetchAll();

// Calculate statistics
$total_requests = count($requests);
$approved_requests = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
$claimed_requests = count(array_filter($requests, fn($r) => $r['status'] === 'claimed'));
$rejected_requests = count(array_filter($requests, fn($r) => $r['status'] === 'rejected'));
$success_rate = $total_requests > 0 ? round((($approved_requests + $claimed_requests) / $total_requests) * 100) : 0;

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';

// Filter requests based on parameters
$filtered_requests = $requests;
if ($status_filter !== 'all') {
    $filtered_requests = array_filter($filtered_requests, fn($r) => $r['status'] === $status_filter);
}
if ($date_filter !== 'all') {
    $now = new DateTime();
    $filtered_requests = array_filter($filtered_requests, function($r) use ($date_filter, $now) {
        $request_date = new DateTime($r['created_at']);
        switch ($date_filter) {
            case 'week':
                return $request_date >= $now->modify('-1 week');
            case 'month':
                return $request_date >= $now->modify('-1 month');
            case 'year':
                return $request_date >= $now->modify('-1 year');
            default:
                return true;
        }
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Medicine History · Resident</title>
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

        /* Timeline styles */
        .timeline-item {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        
        .timeline-item:last-child::before {
            display: none;
        }
        
        .timeline-dot {
            position: absolute;
            left: 0.25rem;
            top: 0.5rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            border: 2px solid white;
            z-index: 1;
        }
        
        .timeline-dot.success { background: #10b981; }
        .timeline-dot.info { background: #3b82f6; }
        .timeline-dot.warning { background: #f59e0b; }
        .timeline-dot.danger { background: #ef4444; }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Card hover effects */
        .history-card {
            transition: all 0.3s ease;
        }
        
        .history-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        /* Filter buttons */
        .filter-btn {
            transition: all 0.2s ease;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        /* Statistics cards */
        .stat-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.9), rgba(255,255,255,0.7));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/medicine_history.php')); ?>">
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
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 mb-1">Medicine History</h1>
                <p class="text-gray-600">Track your medicine request history and status</p>
            </div>

        <!-- Statistics Cards -->
        <div class="content-body">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Requests</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $total_requests; ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Approved</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $approved_requests; ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Claimed</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $claimed_requests; ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Rejected</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo $rejected_requests; ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                    <div class="flex flex-wrap gap-3">
                        <span class="text-sm font-medium text-gray-700">Filter by Status:</span>
                        <a href="?status=all&date=<?php echo $date_filter; ?>" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $status_filter === 'all' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            All (<?php echo $total_requests; ?>)
                        </a>
                        <a href="?status=claimed&date=<?php echo $date_filter; ?>" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $status_filter === 'claimed' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            Claimed (<?php echo $claimed_requests; ?>)
                        </a>
                        <a href="?status=approved&date=<?php echo $date_filter; ?>" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $status_filter === 'approved' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            Approved (<?php echo $approved_requests; ?>)
                        </a>
                        <a href="?status=rejected&date=<?php echo $date_filter; ?>" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $status_filter === 'rejected' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            Rejected (<?php echo $rejected_requests; ?>)
                        </a>
                    </div>
                    
                    <div class="flex flex-wrap gap-3">
                        <span class="text-sm font-medium text-gray-700">Filter by Date:</span>
                        <a href="?status=<?php echo $status_filter; ?>&date=all" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $date_filter === 'all' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            All Time
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&date=year" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $date_filter === 'year' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Year
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&date=month" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $date_filter === 'month' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Month
                        </a>
                        <a href="?status=<?php echo $status_filter; ?>&date=week" 
                           class="filter-btn px-4 py-2 rounded-lg text-sm font-medium <?php echo $date_filter === 'week' ? 'active' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Week
                        </a>
                    </div>
                </div>
            </div>

            <!-- Medicine History Timeline -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Request Timeline</h2>
                    <p class="text-gray-600 mt-1">Showing <?php echo count($filtered_requests); ?> request(s)</p>
                </div>
                
                <div class="p-6">
                    <?php if (empty($filtered_requests)): ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No requests found</h3>
                            <p class="text-gray-600">No medicine requests match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($filtered_requests as $request): ?>
                                <div class="timeline-item history-card bg-gray-50 rounded-lg p-6">
                                    <div class="timeline-dot <?php echo $request['status_type']; ?>"></div>
                                    
                                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-start space-x-4">
                                                <?php if ($request['medicine_image']): ?>
                                                    <img src="<?php echo htmlspecialchars(base_url($request['medicine_image'])); ?>" 
                                                         alt="<?php echo htmlspecialchars($request['medicine_name']); ?>" 
                                                         class="w-16 h-16 object-cover rounded-lg border border-gray-200">
                                                <?php else: ?>
                                                    <div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center">
                                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="flex-1">
                                                    <div class="flex items-center space-x-3 mb-2">
                                                        <h3 class="text-lg font-semibold text-gray-900">
                                                            <?php echo htmlspecialchars($request['medicine_name']); ?>
                                                        </h3>
                                                        <span class="status-badge status-<?php echo $request['status_type']; ?>">
                                                            <?php echo htmlspecialchars($request['status_display']); ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <p class="text-gray-600 mb-3">
                                                        <?php echo htmlspecialchars($request['medicine_description']); ?>
                                                    </p>
                                                    
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                                        <div>
                                                            <span class="font-medium text-gray-700">Requested:</span>
                                                            <span class="text-gray-600"><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></span>
                                                        </div>
                                                        <?php if ($request['batch_code']): ?>
                                                            <div>
                                                                <span class="font-medium text-gray-700">Batch:</span>
                                                                <span class="text-gray-600"><?php echo htmlspecialchars($request['batch_code']); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($request['expiry_date']): ?>
                                                            <div>
                                                                <span class="font-medium text-gray-700">Expires:</span>
                                                                <span class="text-gray-600"><?php echo date('M j, Y', strtotime($request['expiry_date'])); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($request['notes']): ?>
                                                        <div class="mt-3 p-3 bg-blue-50 rounded-lg">
                                                            <span class="font-medium text-blue-900">Notes:</span>
                                                            <p class="text-blue-800 text-sm mt-1"><?php echo htmlspecialchars($request['notes']); ?></p>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 lg:mt-0 lg:ml-6">
                                            <div class="text-right">
                                                <div class="text-sm text-gray-500 mb-1">Request ID</div>
                                                <div class="font-mono text-sm text-gray-900">#<?php echo str_pad($request['id'], 6, '0', STR_PAD_LEFT); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script>
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
        document.addEventListener('DOMContentLoaded', function() {
            initProfileDropdown();
        });
    </script>
</body>
</html>
