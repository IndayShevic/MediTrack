<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);

$user = current_user();
$bhw_purok_id = $user['purok_id'] ?? 0;

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id > 0) {
        // Verify this family addition belongs to a resident in BHW's purok
        $verify = db()->prepare('
            SELECT rfa.*, res.first_name, res.last_name, res.user_id
            FROM resident_family_additions rfa
            JOIN residents res ON res.id = rfa.resident_id
            WHERE rfa.id = ? AND res.purok_id = ? AND rfa.status = "pending"
        ');
        $verify->execute([$id, $bhw_purok_id]);
        $addition = $verify->fetch();
        
        if ($addition) {
            if ($action === 'approve') {
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    
                    // Move to approved family_members table
                    $insert = $pdo->prepare('
                        INSERT INTO family_members (resident_id, full_name, relationship, date_of_birth)
                        SELECT resident_id, full_name, relationship, date_of_birth
                        FROM resident_family_additions
                        WHERE id = ?
                    ');
                    $insert->execute([$id]);
                    
                    // Update status
                    $update = $pdo->prepare('
                        UPDATE resident_family_additions 
                        SET status = "approved", bhw_id = ?, approved_at = NOW()
                        WHERE id = ?
                    ');
                    $update->execute([$user['id'], $id]);
                    
                    $pdo->commit();
                    $_SESSION['flash'] = 'Family member approved successfully!';
                    $_SESSION['flash_type'] = 'success';
                    
                    // Clear cache
                    unset($_SESSION['bhw_notification_counts_' . $bhw_purok_id]);
                    
                } catch (Throwable $e) {
                    if (isset($pdo)) $pdo->rollBack();
                    $_SESSION['flash'] = 'Failed to approve. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                }
            } elseif ($action === 'reject') {
                $reason = trim($_POST['reason'] ?? 'Not verified');
                try {
                    $stmt = db()->prepare('
                        UPDATE resident_family_additions 
                        SET status = "rejected", bhw_id = ?, rejection_reason = ?, rejected_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([$user['id'], $reason, $id]);
                    
                    $_SESSION['flash'] = 'Family member addition rejected.';
                    $_SESSION['flash_type'] = 'success';
                    
                    // Clear cache
                    unset($_SESSION['bhw_notification_counts_' . $bhw_purok_id]);
                    
                } catch (Throwable $e) {
                    $_SESSION['flash'] = 'Failed to reject. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
    }
    
    redirect_to('bhw/pending_family_additions.php');
}

// Get pending family additions
$pending = db()->prepare('
    SELECT rfa.*, 
           res.first_name as resident_first, 
           res.last_name as resident_last,
           res.phone,
           p.name as purok_name,
           (SELECT COUNT(*) FROM family_members WHERE resident_id = rfa.resident_id) as existing_family_count
    FROM resident_family_additions rfa
    JOIN residents res ON res.id = rfa.resident_id
    JOIN puroks p ON p.id = res.purok_id
    WHERE res.purok_id = ? AND rfa.status = "pending"
    ORDER BY rfa.created_at ASC
');
$pending->execute([$bhw_purok_id]);
$pending_additions = $pending->fetchAll();

function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pending Family Additions · BHW Dashboard</title>
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
        
        .sidebar {
            will-change: scroll-position;
        }
        
        .sidebar-nav a:hover {
            transform: translateX(2px);
        }

        /* Enhanced UI/UX Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: calc(200px + 100%) 0; }
        }

        @keyframes ripple {
            0% {
                transform: scale(0);
                opacity: 1;
            }
            100% {
                transform: scale(4);
                opacity: 0;
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out forwards;
        }

        .animate-scale-in {
            animation: scaleIn 0.5s ease-out forwards;
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, box-shadow;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, background-color;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .badge-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .content-header {
            animation: slideInLeft 0.8s ease-out forwards;
        }

        .grid > div {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .grid > div:nth-child(1) { animation-delay: 0.1s; }
        .grid > div:nth-child(2) { animation-delay: 0.2s; }
        .grid > div:nth-child(3) { animation-delay: 0.3s; }
        .grid > div:nth-child(4) { animation-delay: 0.4s; }
        .grid > div:nth-child(5) { animation-delay: 0.5s; }

        .ripple-effect {
            position: relative;
            overflow: hidden;
        }

        .ripple-effect::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .ripple-effect:active::before {
            width: 300px;
            height: 300px;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }

        .modal-backdrop {
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.3);
        }

        .modal-content {
            animation: scaleIn 0.3s ease-out forwards;
        }

        .status-indicator {
            position: relative;
            display: inline-block;
        }

        .status-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: -10px;
            width: 6px;
            height: 6px;
            background: #f59e0b;
            border-radius: 50%;
            transform: translateY(-50%);
            animation: pulse 2s ease-in-out infinite;
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/pending_family_additions.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <span style="flex: 1;">Pending Family Additions</span>
                <?php if ($notification_counts['pending_family_additions'] > 0): ?>
                    <span class="notification-badge"><?php echo $notification_counts['pending_family_additions']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Statistics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/announcements.php')); ?>">
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
        <div class="content-header">
            <div>
                <h1 class="text-4xl font-bold gradient-text animate-slide-in-left">
                    Pending Family Additions
                </h1>
                <p class="text-gray-600 text-lg mt-2 animate-fade-in-up" style="animation-delay: 0.2s;">Review and approve family member additions from residents</p>
                <div class="flex items-center justify-between mt-4 animate-fade-in-up" style="animation-delay: 0.4s;">
                    <div class="flex items-center space-x-2">
                        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium bg-gradient-to-r from-blue-100 to-indigo-100 text-blue-800 border border-blue-200 shadow-sm">
                            <svg class="w-4 h-4 mr-2 animate-float" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                            </svg>
                            <?php 
                            $purok = db()->prepare('SELECT name FROM puroks WHERE id = ?');
                            $purok->execute([$bhw_purok_id]);
                            echo htmlspecialchars($purok->fetch()['name'] ?? 'Unknown');
                            ?>
                        </span>
                        <span class="text-blue-600 font-bold">•</span>
                        <span class="text-sm text-gray-600 font-medium">
                            <span class="status-indicator"><?php echo count($pending_additions); ?> pending</span>
                        </span>
                    </div>
                    
                    <?php if (count($pending_additions) > 5): ?>
                    <div class="flex items-center space-x-2">
                        <span class="text-xs text-gray-500">Quick Actions:</span>
                        <button onclick="approveAll()" class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full hover:bg-green-200 transition-colors">
                            Approve All
                        </button>
                        <button onclick="showBulkRejectModal()" class="text-xs bg-red-100 text-red-700 px-3 py-1 rounded-full hover:bg-red-200 transition-colors">
                            Bulk Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> animate-fade-in-up">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?php echo $_SESSION['flash']; unset($_SESSION['flash'], $_SESSION['flash_type']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (empty($pending_additions)): ?>
            <div class="card animate-scale-in">
                <div class="card-body text-center py-16">
                    <div class="animate-float">
                        <svg class="w-32 h-32 text-gray-300 mx-auto mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-3xl font-bold gradient-text mb-3">All Caught Up!</h3>
                    <p class="text-gray-600 text-lg">No pending family member additions to review</p>
                    <div class="mt-6">
                        <div class="inline-flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            Great job! All requests processed
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="grid gap-3">
                <?php foreach ($pending_additions as $index => $addition): ?>
                    <div class="card animate-fade-in-up" style="animation-delay: <?php echo ($index * 0.05) + 0.6; ?>s;">
                        <div class="card-body p-4">
                            <div class="flex items-center justify-between">
                                <!-- Left: Avatar and Info -->
                                <div class="flex items-center space-x-4 flex-1">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                        <?php echo strtoupper(substr($addition['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($addition['full_name']); ?></h3>
                                            <span class="badge-warning text-xs px-2 py-1">Pending</span>
                                        </div>
                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                            <span><strong><?php echo htmlspecialchars($addition['resident_first'] . ' ' . $addition['resident_last']); ?></strong></span>
                                            <span>•</span>
                                            <span><?php echo htmlspecialchars($addition['relationship']); ?></span>
                                            <span>•</span>
                                            <span><?php echo calculateAge($addition['date_of_birth']); ?> years</span>
                                            <span>•</span>
                                            <span><?php echo $addition['existing_family_count']; ?> family members</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Date and Actions -->
                                <div class="flex items-center space-x-4">
                                    <div class="text-right text-sm text-gray-500">
                                        <div><?php echo date('M d, Y', strtotime($addition['created_at'])); ?></div>
                                        <div class="text-xs"><?php echo date('g:i A', strtotime($addition['created_at'])); ?></div>
                                    </div>
                                    
                                    <div class="flex space-x-2">
                                        <form method="POST" onsubmit="return confirm('Approve this family member addition?')">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?php echo $addition['id']; ?>">
                                            <button type="submit" class="btn btn-success ripple-effect px-4 py-2 text-sm font-medium">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Approve
                                            </button>
                                        </form>
                                        <button onclick="showRejectModal(<?php echo $addition['id']; ?>, '<?php echo htmlspecialchars(addslashes($addition['full_name'])); ?>')" 
                                                class="btn btn-danger ripple-effect px-4 py-2 text-sm font-medium">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="modal-content bg-white rounded-2xl max-w-md w-full shadow-2xl">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Reject Family Member Addition</h3>
                        <p class="text-sm text-gray-600 mt-1" id="rejectName"></p>
                    </div>
                </div>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                
                <label class="block text-sm font-semibold text-gray-700 mb-3">Reason for Rejection</label>
                <textarea name="reason" required rows="4" 
                          class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-red-100 focus:border-red-500 transition-all duration-200 resize-none"
                          placeholder="Please provide a specific reason for rejecting this family member addition..."></textarea>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="document.getElementById('rejectModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary py-3 font-semibold">Cancel</button>
                    <button type="submit" class="flex-1 btn btn-danger py-3 font-semibold">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Reject Modal -->
    <div id="bulkRejectModal" class="fixed inset-0 modal-backdrop hidden items-center justify-center z-50 p-4">
        <div class="modal-content bg-white rounded-2xl max-w-md w-full shadow-2xl">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Bulk Reject All</h3>
                        <p class="text-sm text-gray-600 mt-1">This will reject ALL pending family additions</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <label class="block text-sm font-semibold text-gray-700 mb-3">Reason for Bulk Rejection</label>
                <textarea id="bulkRejectReason" required rows="4" 
                          class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-4 focus:ring-red-100 focus:border-red-500 transition-all duration-200 resize-none"
                          placeholder="Please provide a specific reason for rejecting all family member additions..."></textarea>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="document.getElementById('bulkRejectModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary py-3 font-semibold">Cancel</button>
                    <button onclick="bulkReject()" class="flex-1 btn btn-danger py-3 font-semibold">Reject All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showRejectModal(id, name) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectName').textContent = 'Rejecting: ' + name;
            const modal = document.getElementById('rejectModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Add entrance animation
            setTimeout(() => {
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
            }, 10);
        }

        // Close modal on click outside
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        function closeModal() {
            const modal = document.getElementById('rejectModal');
            const content = modal.querySelector('.modal-content');
            
            // Add exit animation
            content.style.transform = 'scale(0.9)';
            content.style.opacity = '0';
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                content.style.transform = 'scale(1)';
                content.style.opacity = '1';
            }, 200);
        }

        // Add ripple effect to buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('ripple-effect')) {
                const button = e.target;
                const ripple = document.createElement('span');
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                button.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            }
        });

        // Bulk actions
        function approveAll() {
            if (confirm('Are you sure you want to approve ALL pending family additions? This action cannot be undone.')) {
                const forms = document.querySelectorAll('form[action*="approve"]');
                forms.forEach((form, index) => {
                    setTimeout(() => {
                        form.submit();
                    }, index * 100); // Stagger submissions to avoid conflicts
                });
            }
        }

        function showBulkRejectModal() {
            const modal = document.getElementById('bulkRejectModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            setTimeout(() => {
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
            }, 10);
        }

        function bulkReject() {
            const reason = document.getElementById('bulkRejectReason').value.trim();
            if (!reason) {
                alert('Please provide a reason for bulk rejection.');
                return;
            }
            
            if (confirm('Are you sure you want to reject ALL pending family additions? This action cannot be undone.')) {
                const forms = document.querySelectorAll('form');
                forms.forEach((form, index) => {
                    if (form.querySelector('input[name="action"][value="reject"]')) {
                        const reasonInput = form.querySelector('textarea[name="reason"]');
                        if (reasonInput) {
                            reasonInput.value = reason;
                        }
                        setTimeout(() => {
                            form.submit();
                        }, index * 100);
                    }
                });
            }
        }

        // Add CSS for ripple effect
        const style = document.createElement('style');
        style.textContent = `
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
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
        `;
        document.head.appendChild(style);
        
        // Function to update notification badges
        function updateNotificationBadges() {
            fetch('<?php echo base_url('bhw/get_notification_counts.php'); ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const counts = data.counts;
                        
                        // Update Pending Family Additions badge
                        const pendingFamilyBadge = document.querySelector('a[href*="pending_family_additions.php"] .notification-badge');
                        if (counts.pending_family_additions > 0) {
                            if (pendingFamilyBadge) {
                                pendingFamilyBadge.textContent = counts.pending_family_additions;
                            } else {
                                // Create badge if it doesn't exist
                                const pendingFamilyLink = document.querySelector('a[href*="pending_family_additions.php"]');
                                if (pendingFamilyLink) {
                                    const badge = document.createElement('span');
                                    badge.className = 'notification-badge';
                                    badge.textContent = counts.pending_family_additions;
                                    pendingFamilyLink.appendChild(badge);
                                }
                            }
                        } else {
                            // Remove badge if count is 0
                            if (pendingFamilyBadge) {
                                pendingFamilyBadge.remove();
                            }
                        }
                        
                        // Add visual feedback for badge updates
                        const updatedBadges = document.querySelectorAll('.notification-badge');
                        updatedBadges.forEach(badge => {
                            badge.style.transform = 'scale(1.2)';
                            badge.style.transition = 'transform 0.3s ease';
                            setTimeout(() => {
                                badge.style.transform = 'scale(1)';
                            }, 300);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error updating notification badges:', error);
                });
        }
        
        // Update badges after approve/reject actions
        document.addEventListener('DOMContentLoaded', function() {
            const approveButtons = document.querySelectorAll('button[onclick*="approveFamilyAddition"]');
            const rejectButtons = document.querySelectorAll('button[onclick*="showRejectModal"]');
            
            approveButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(updateNotificationBadges, 1000);
                });
            });
            
            rejectButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(updateNotificationBadges, 1000);
                });
            });
        });
    </script>
</body>
</html>

