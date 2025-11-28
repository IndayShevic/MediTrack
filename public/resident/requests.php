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

$rows = db()->prepare('
    SELECT 
        r.id, 
        m.name AS medicine, 
        m.image_path AS medicine_image_path,
        r.status, 
        r.requested_for,
        r.patient_name,
        r.patient_date_of_birth,
        r.relationship,
        r.reason,
        r.rejection_reason,
        r.created_at,
        r.updated_at,
        bhw.first_name AS bhw_first_name,
        bhw.last_name AS bhw_last_name,
        r.proof_image_path,
        CONCAT(res.first_name, \' \', COALESCE(res.middle_initial, \'\'), CASE WHEN res.middle_initial IS NOT NULL THEN \' \' ELSE \'\' END, res.last_name) AS resident_full_name
    FROM requests r 
    JOIN medicines m ON m.id = r.medicine_id 
    LEFT JOIN users bhw ON bhw.id = r.bhw_id
    LEFT JOIN residents res ON res.id = r.resident_id
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
        /* Remove hover effects for sidebar navigation */
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
            background: #FFFFFF !important;
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

        /* Premium Health-Tech Design System */
        .glass-effect {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        
        .premium-search {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06), 
                        inset 0 1px 0 rgba(255, 255, 255, 0.8),
                        inset 0 -1px 0 rgba(0, 0, 0, 0.02);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 9999px;
        }
        
        .premium-search:focus-within {
            box-shadow: 0 12px 40px rgba(34, 197, 94, 0.12), 
                        inset 0 1px 0 rgba(255, 255, 255, 0.9),
                        inset 0 -1px 0 rgba(0, 0, 0, 0.02);
            border-color: rgba(34, 197, 94, 0.25);
            transform: translateY(-1px);
        }
        
        .premium-search:hover {
            box-shadow: 0 10px 36px rgba(0, 0, 0, 0.08), 
                        inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        
        .filter-pill {
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            border-radius: 9999px;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        
        .filter-pill.active {
            background: linear-gradient(135deg, #22C55E 0%, #10B981 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.35), 
                        0 0 0 4px rgba(34, 197, 94, 0.12),
                        inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transform: scale(1.08);
            border: none;
        }
        
        .filter-pill:not(.active) {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1.5px solid rgba(229, 231, 235, 0.9);
            color: #6B7280;
        }
        
        .filter-pill:not(.active):hover {
            background: rgba(255, 255, 255, 1);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
            border-color: rgba(229, 231, 235, 1);
        }
        
        .filter-pill:active {
            transform: scale(0.95);
        }
        
        .request-card-premium {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid rgba(229, 231, 235, 0.7);
            border-radius: 22px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06),
                        0 1px 0 rgba(255, 255, 255, 0.8) inset;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .request-card-premium::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #22C55E 0%, #3B82F6 50%, #8B5CF6 100%);
            opacity: 0;
            transition: opacity 0.4s ease;
            border-radius: 22px 22px 0 0;
        }
        
        .request-card-premium:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1),
                        0 2px 0 rgba(255, 255, 255, 0.9) inset;
            border-color: rgba(34, 197, 94, 0.4);
        }
        
        .request-card-premium:hover::before {
            opacity: 1;
        }
        
        .status-badge-premium {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.1),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
            border: 1px solid rgba(255, 255, 255, 0.6);
            position: relative;
        }
        
        .status-pending-premium {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            color: #78350F;
            box-shadow: 0 3px 12px rgba(245, 158, 11, 0.2),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .status-approved-premium {
            background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
            color: #064E3B;
            box-shadow: 0 3px 12px rgba(16, 185, 129, 0.25),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .status-rejected-premium {
            background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
            color: #7F1D1D;
            box-shadow: 0 3px 12px rgba(239, 68, 68, 0.2),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .status-ready-premium {
            background: linear-gradient(135deg, #E9D5FF 0%, #DDD6FE 100%);
            color: #581C87;
            box-shadow: 0 3px 12px rgba(139, 92, 246, 0.25),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .status-claimed-premium {
            background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
            color: #1F2937;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08),
                        0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .view-details-btn {
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35),
                        0 0 0 1px rgba(255, 255, 255, 0.1) inset;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        
        .view-details-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 28px rgba(59, 130, 246, 0.45),
                        0 0 0 1px rgba(255, 255, 255, 0.15) inset;
            background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
        }
        
        .view-details-btn:active {
            transform: translateY(-1px);
        }
        
        .page-title-gradient {
            background: linear-gradient(135deg, #22C55E 0%, #3B82F6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .title-underline {
            height: 3px;
            background: linear-gradient(90deg, #22C55E 0%, #3B82F6 50%, #8B5CF6 100%);
            border-radius: 2px;
            margin-top: 8px;
            animation: fadeInUp 0.6s ease-out 0.2s both;
        }
        
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .bounce-in {
            animation: bounceIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
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
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
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
            <div class="mb-8 animate-fade-in-up">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm font-medium text-gray-500">MediTrack</span>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <span class="text-sm font-medium text-gray-700">My Requests</span>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold page-title-gradient mb-2">My Requests</h1>
                <div class="title-underline w-24"></div>
                <p class="text-gray-600 mt-4 text-sm">Track your medicine requests and their status</p>
            </div>

        <!-- Content -->
        <div class="content-body">
            <!-- Search and Filter Section -->
            <div class="mb-8 animate-fade-in-up" style="animation-delay: 0.1s">
                <!-- Premium Search Bar -->
                <div class="mb-6">
                    <div class="relative premium-search max-w-2xl mx-auto">
                        <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none z-10">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 12px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" id="searchInput" placeholder="Search requests by medicine, patient, or ID..." 
                               class="block w-full pr-6 py-5 bg-transparent border-0 rounded-full text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-0 text-sm font-medium" style="padding-left: 3.5rem;">
                    </div>
                </div>
                
                <!-- Filter Pills (iOS Segmented Control Style) -->
                <div class="flex flex-wrap gap-3 justify-center sm:justify-start">
                    <button class="filter-pill active px-5 py-2.5 rounded-full text-sm font-semibold transition-all duration-300" data-filter="all">
                        All Requests
                    </button>
                    <button class="filter-pill px-5 py-2.5 rounded-full text-sm font-semibold transition-all duration-300" data-filter="submitted">
                        Pending
                    </button>
                    <button class="filter-pill px-5 py-2.5 rounded-full text-sm font-semibold transition-all duration-300" data-filter="approved">
                        Ready to Claim
                    </button>
                    <button class="filter-pill px-5 py-2.5 rounded-full text-sm font-semibold transition-all duration-300" data-filter="dispensed">
                        Claimed
                    </button>
                    <button class="filter-pill px-5 py-2.5 rounded-full text-sm font-semibold transition-all duration-300" data-filter="rejected">
                        Rejected
                    </button>
                </div>
            </div>

            <!-- Requests Cards -->
            <div id="requestsContainer" class="space-y-4 animate-fade-in-up" style="animation-delay: 0.2s">
                <?php foreach ($requests as $index => $r): ?>
                    <div class="request-card-premium request-row p-6" 
                         data-medicine="<?php echo strtolower(htmlspecialchars($r['medicine'])); ?>"
                         data-status="<?php echo $r['status']; ?>"
                         data-patient="<?php echo strtolower(htmlspecialchars($r['patient_name'] ?? '')); ?>"
                         style="animation-delay: <?php echo ($index * 0.05); ?>s">
                        <div class="flex flex-col md:flex-row md:items-center gap-6">
                            <!-- Left Section: Request ID & Medicine -->
                            <div class="flex items-start gap-4 flex-1">
                                <!-- Request ID Icon -->
                                <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                                
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="font-mono text-sm font-bold text-gray-900">#<?php echo (int)$r['id']; ?></span>
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        $statusText = '';

                                        if ($r['status'] === 'submitted') {
                                            $statusClass = 'status-pending-premium';
                                            $statusIcon = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                                            $statusText = 'Pending';
                                        } elseif ($r['status'] === 'approved') {
                                            // Approved means Ready to Claim
                                            $statusClass = 'status-ready-premium';
                                            $statusIcon = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>';
                                            $statusText = 'Ready to Claim';
                                        } elseif ($r['status'] === 'rejected') {
                                            $statusClass = 'status-rejected-premium';
                                            $statusIcon = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
                                            $statusText = 'Rejected';
                                        } elseif ($r['status'] === 'dispensed') {
                                            // Dispensed means Claimed
                                            $statusClass = 'status-claimed-premium';
                                            $statusIcon = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                                            $statusText = 'Claimed';
                                        }
                                        ?>
                                        <span class="status-badge-premium <?php echo $statusClass; ?>">
                                            <?php echo $statusIcon; ?>
                                            <?php echo $statusText; ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Medicine Name -->
                                    <div class="flex items-center gap-2 mb-2">
                                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                        </svg>
                                        <span class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($r['medicine']); ?></span>
                                    </div>
                                    
                                    <?php if ($r['reason']): ?>
                                        <p class="text-sm text-gray-600 line-clamp-1"><?php echo htmlspecialchars($r['reason']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Middle Section: Requested For & Date -->
                            <div class="flex flex-col md:flex-row md:items-center gap-4 md:gap-6">
                                <!-- Requested For -->
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <?php if ($r['requested_for'] === 'self'): ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        <?php else: ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <?php endif; ?>
                                    </svg>
                                    <span class="text-sm text-gray-700">
                                        <?php 
                                        if ($r['requested_for'] === 'self') {
                                            echo htmlspecialchars($r['resident_full_name'] ?? 'Self');
                                        } else {
                                            echo htmlspecialchars($r['patient_name'] ?? 'Family Member');
                                        }
                                        ?>
                                    </span>
                                </div>
                                
                                <!-- Date -->
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    <div class="text-sm text-gray-600">
                                        <div><?php echo date('M j, Y', strtotime($r['created_at'])); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Section: View Details Button -->
                            <div class="flex-shrink-0">
                                <button onclick="showRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" 
                                        class="view-details-btn inline-flex items-center gap-2 px-5 py-2.5 text-white text-sm font-semibold rounded-xl">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    View Details
                                </button>
                            </div>
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
    <div id="requestDetailsModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50 p-4 backdrop-blur-md modal-overlay">
        <div class="bg-white rounded-[26px] max-w-[750px] w-full max-h-[90vh] overflow-hidden shadow-2xl border border-gray-100 modal-content" style="transform: translateY(20px); opacity: 0;">
            <!-- Top Section with Gradient Border -->
            <div class="bg-gradient-to-r from-green-500 via-emerald-500 to-green-500 h-1.5"></div>
            
            <!-- Header -->
            <div class="bg-white px-8 pt-7 pb-5 border-b border-gray-100">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h2 class="text-2xl font-bold text-gray-900 mb-5">Request Details</h2>
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="text-sm font-semibold text-gray-700">Request #<span id="modalRequestId" class="text-gray-900 font-bold"></span></span>
                            <span id="modalStatusBadge" class="inline-flex items-center"></span>
                        </div>
                    </div>
                    <button onclick="closeRequestDetails()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 p-2.5 rounded-full transition-all duration-200 flex-shrink-0 ml-4 group">
                        <svg class="w-5 h-5 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Content -->
            <div class="p-8 overflow-y-auto max-h-[calc(90vh-220px)]">
                <div id="requestDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
            
            <!-- Footer -->
            <div class="bg-gray-50 border-t border-gray-200 px-8 py-6">
                <div class="flex items-start gap-2 mb-4 justify-center">
                    <svg class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-sm text-gray-600 text-center">For concerns about this request, please contact your assigned BHW.</p>
                </div>
                <div class="flex justify-center">
                    <button onclick="closeRequestDetails()" class="px-6 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-200 shadow-sm hover:shadow-md">
                        Back to My Requests
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .modal-overlay {
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-content {
            animation: slideUpFadeIn 0.4s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUpFadeIn {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .info-box {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            padding: 18px 20px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .info-box:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
            border-color: #D1D5DB;
        }
        
        .info-label {
            font-size: 0.7rem;
            color: #6B7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }
        
        .info-value {
            font-size: 1.05rem;
            color: #111827;
            font-weight: 700;
            line-height: 1.4;
        }
        
        .medicine-image-card {
            width: 140px;
            height: 140px;
            border-radius: 18px;
            border: 2px solid #E5E7EB;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        
        .medicine-image-card:hover {
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
            transform: scale(1.02);
        }
        
        .medicine-image-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .status-approved {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-rejected {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        .status-ready {
            background: #DBEAFE;
            color: #1E40AF;
        }
        
        .status-claimed {
            background: #F3F4F6;
            color: #374151;
        }
    </style>

    <script>
        // Base URL for assets
        const BASE_URL = '<?php echo base_url(''); ?>';
        
        // Calculate age from birthdate
        function calculateAge(birthdate) {
            if (!birthdate) return '';
            const today = new Date();
            const birth = new Date(birthdate);
            let age = today.getFullYear() - birth.getFullYear();
            const monthDiff = today.getMonth() - birth.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                age--;
            }
            return age;
        }

        // Helper function for upload URL
        function upload_url(path) {
            if (!path) return '';
            // Ensure no double slashes if path already has uploads/
            const cleanPath = path.replace(/^uploads\//, ''); 
            return '<?php echo base_url('uploads/'); ?>' + cleanPath; 
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const filterChips = document.querySelectorAll('.filter-pill');
            const requestRows = document.querySelectorAll('.request-row');
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
                    
                    // Add bounce animation
                    this.classList.add('bounce-in');
                    setTimeout(() => this.classList.remove('bounce-in'), 500);
                    
                    currentFilter = this.dataset.filter;
                    filterRequests();
                });
            });

            function filterRequests() {
                const rows = document.querySelectorAll('.request-row');
                const container = document.getElementById('requestsContainer');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const medicine = row.dataset.medicine;
                    const status = row.dataset.status;
                    const patient = row.dataset.patient;
                    
                    let matchesSearch = true;
                    let matchesFilter = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = medicine.includes(currentSearch) || patient.includes(currentSearch) || 
                                      row.textContent.toLowerCase().includes(currentSearch);
                    }
                    
                    // Check filter match
                    if (currentFilter !== 'all') {
                        matchesFilter = status === currentFilter;
                    }
                    
                    if (matchesSearch && matchesFilter) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update count
                if (requestCount) {
                    requestCount.textContent = `${visibleCount} requests`;
                }
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
                    if (container) container.classList.add('hidden');
                } else {
                    noResults.classList.add('hidden');
                    if (container) container.classList.remove('hidden');
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

        window.showRequestDetails = function(request) {
            console.log('showRequestDetails called with:', request);
            const modal = document.getElementById('requestDetailsModal');
            const content = document.getElementById('requestDetailsContent');
            const requestIdEl = document.getElementById('modalRequestId');
            const statusBadgeEl = document.getElementById('modalStatusBadge');
            
            if (!modal) {
                console.error('Modal not found');
                return;
            }
            
            if (!content) {
                console.error('Modal content not found');
                return;
            }
            
            // Set request ID and status badge in header
            if (requestIdEl) {
                requestIdEl.textContent = request.id;
            }
            if (statusBadgeEl) {
                statusBadgeEl.innerHTML = getStatusBadge(request.status);
            }
            
            // Get medicine image URL
            let medicineImageUrl = null;
            let medicineImageExists = false;
            if (request.medicine_image_path) {
                if (request.medicine_image_path.startsWith('http')) {
                    medicineImageUrl = request.medicine_image_path;
                    medicineImageExists = true;
                } else {
                    // Remove leading slash if present to avoid double slashes
                    const imagePath = request.medicine_image_path.startsWith('/') ? 
                        request.medicine_image_path.substring(1) : request.medicine_image_path;
                    medicineImageUrl = BASE_URL + imagePath;
                    medicineImageExists = true;
                }
            }
            
            // Build medicine image HTML
            let medicineImageHtml = '';
            if (medicineImageExists) {
                medicineImageHtml = `
                    <div class="medicine-image-card">
                        <img src="${medicineImageUrl}" alt="${request.medicine}" onerror="this.parentElement.innerHTML='<div class=\\'w-full h-full flex items-center justify-center bg-gray-100 text-gray-400 text-xs font-medium\\'>${request.medicine.substring(0, 2).toUpperCase()}</div>'">
                    </div>
                `;
            } else {
                medicineImageHtml = `
                    <div class="medicine-image-card bg-gradient-to-br from-green-500 to-emerald-600 text-white flex items-center justify-center font-bold text-lg">
                        ${request.medicine ? request.medicine.substring(0, 2).toUpperCase() : 'MD'}
                    </div>
                `;
            }
            
            // Show the modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            
            // Reset animation
            const modalContent = modal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.transform = 'translateY(20px)';
                modalContent.style.opacity = '0';
                setTimeout(() => {
                    modalContent.style.transform = 'translateY(0)';
                    modalContent.style.opacity = '1';
                }, 10);
            }
            
            // Build the content with premium medical design
            let html = `
                <!-- Main Content Layout: Image Left, Info Right -->
                <div class="flex flex-col md:flex-row gap-8 mb-8">
                    <!-- Left: Medicine Image (110-140px) -->
                    <div class="flex-shrink-0 flex flex-col items-center md:items-start">
                        <div class="w-[140px] h-[140px] rounded-2xl overflow-hidden border-2 border-gray-200 shadow-xl bg-white flex items-center justify-center transition-transform duration-300 hover:scale-105">
                            ${medicineImageExists ? `
                                <img src="${medicineImageUrl}" alt="${request.medicine}" 
                                     class="w-full h-full object-cover"
                                     onerror="this.parentElement.innerHTML='<div class=\\'w-full h-full flex items-center justify-center bg-gradient-to-br from-green-500 to-emerald-600 text-white font-bold text-2xl\\'>${request.medicine ? request.medicine.substring(0, 2).toUpperCase() : 'MD'}</div>'">
                            ` : `
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-green-500 to-emerald-600 text-white font-bold text-2xl">
                                    ${request.medicine ? request.medicine.substring(0, 2).toUpperCase() : 'MD'}
                                </div>
                            `}
                        </div>
                        <p class="text-xs text-gray-500 text-center md:text-left mt-3 font-medium">Medicine Image</p>
                    </div>
                    
                    <!-- Right: Information Cards -->
                    <div class="flex-1 space-y-4">
                        <!-- Medicine Name -->
                        <div class="info-box">
                            <div class="info-label">Medicine Name</div>
                            <div class="info-value text-lg">${request.medicine || '-'}</div>
                        </div>
                        
                        <!-- Request ID & Created At -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="info-box">
                                <div class="info-label">Request ID</div>
                                <div class="info-value font-mono">#${request.id}</div>
                            </div>
                            
                            <div class="info-box">
                                <div class="info-label">Created At</div>
                                <div class="info-value">
                                    ${new Date(request.created_at).toLocaleDateString('en-US', { 
                                        year: 'numeric', 
                                        month: 'long', 
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    })}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Requested For & Assigned BHW -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="info-box">
                                <div class="info-label">Requested For</div>
                                <div class="info-value">
                                    ${request.requested_for === 'self' ? 
                                        (request.resident_full_name || 'Self') : 
                                        'Family Member'
                                    }
                                </div>
                            </div>
                            
                            <div class="info-box">
                                <div class="info-label">Assigned BHW</div>
                                <div class="info-value">
                                    ${request.bhw_first_name && request.bhw_last_name ? 
                                        `${request.bhw_first_name} ${request.bhw_last_name}` : 
                                        'Not assigned'
                                    }
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${request.requested_for === 'family' && request.patient_name ? `
                <!-- Patient Information -->
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Patient Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="info-box">
                            <div class="info-label">Name</div>
                            <div class="info-value">${request.patient_name || '-'}</div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Age</div>
                            <div class="info-value">${request.patient_date_of_birth ? calculateAge(request.patient_date_of_birth) + ' years' : '-'}</div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Relationship</div>
                            <div class="info-value">${request.relationship || '-'}</div>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                ${request.status === 'approved' && request.approver_first_name ? `
                <!-- Approval Information -->
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Approval Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="info-box">
                            <div class="info-label">Approved By</div>
                            <div class="info-value">${request.approver_first_name} ${request.approver_last_name}</div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Approved At</div>
                            <div class="info-value">
                                ${request.approved_at ? new Date(request.approved_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : '-'}
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                ${request.status === 'rejected' && request.rejector_first_name ? `
                <!-- Rejection Information -->
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Rejection Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="info-box">
                            <div class="info-label">Rejected By</div>
                            <div class="info-value">${request.rejector_first_name} ${request.rejector_last_name}</div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Rejected At</div>
                            <div class="info-value">
                                ${request.rejected_at ? new Date(request.rejected_at).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) : '-'}
                            </div>
                        </div>
                    </div>
                    ${request.rejection_reason ? `
                    <div class="info-box">
                        <div class="info-label">Rejection Reason</div>
                        <div class="info-value">${request.rejection_reason}</div>
                    </div>
                    ` : ''}
                </div>
                ` : ''}
                
                <!-- Reason Section -->
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Reason for Request</h4>
                    <div class="info-box">
                        <div class="info-value leading-relaxed">${request.reason || 'No reason provided'}</div>
                    </div>
                </div>

                <!-- Proof of Request Section -->
                <div class="mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Proof of Request</h4>
                    <div class="bg-gray-50 rounded-2xl p-6 border border-gray-200">
                        ${request.proof_image_path ? `
                            <div class="flex flex-col items-center">
                                <div class="relative group cursor-pointer overflow-hidden rounded-xl shadow-md hover:shadow-xl transition-all duration-300" onclick="window.open('${upload_url(request.proof_image_path)}', '_blank')">
                                    <img src="${upload_url(request.proof_image_path)}" 
                                         alt="Proof of Request" 
                                         class="max-w-full h-auto max-h-[300px] object-contain transform group-hover:scale-105 transition-transform duration-500">
                                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-all duration-300 flex items-center justify-center">
                                        <div class="opacity-0 group-hover:opacity-100 transform translate-y-4 group-hover:translate-y-0 transition-all duration-300 bg-white/90 backdrop-blur-sm px-4 py-2 rounded-full shadow-lg text-sm font-semibold text-gray-800 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                            </svg>
                                            Click to View
                                        </div>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-500 mt-3 flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Click image to view full size
                                </p>
                            </div>
                        ` : `
                            <div class="flex flex-col items-center justify-center py-8 text-gray-400">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium">No proof image provided</p>
                            </div>
                        `}
                    </div>
                </div>

            `;
            
            content.innerHTML = html;
        }

        window.closeRequestDetails = function() {
            const modal = document.getElementById('requestDetailsModal');
            const modalContent = modal.querySelector('.modal-content');
            
            // Animate out
            if (modalContent) {
                modalContent.style.transform = 'translateY(20px)';
                modalContent.style.opacity = '0';
            }
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = 'auto';
            }, 300);
        }

        function getStatusBadge(status) {
            const badges = {
                'submitted': '<span class="status-badge status-pending"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Pending</span>',
                'approved': '<span class="status-badge status-approved"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Approved</span>',
                'rejected': '<span class="status-badge status-rejected"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Rejected</span>',
                'ready_to_claim': '<span class="status-badge status-ready"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>Ready to Claim</span>',
                'claimed': '<span class="status-badge status-claimed"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Claimed</span>'
            };
            return badges[status] || '<span class="status-badge status-pending">' + status + '</span>';
        }

        // Close modal when clicking outside
        document.getElementById('requestDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRequestDetails();
            }
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
            if (!window.requestsProfileDropdownClickHandler) {
                window.requestsProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.requestsProfileDropdownClickHandler);
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

    <style>
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

    
    
</body>
</html>


