<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);

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

$bhw_purok_id = $user['purok_id'] ?? 0;

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Fetch pending requests for notifications
try {
    $stmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name as medicine_name, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id LEFT JOIN residents res ON r.resident_id = res.id WHERE r.status = "submitted" AND res.purok_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_requests_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_requests_list = [];
}

// Fetch pending registrations for notifications
try {
    $stmt = db()->prepare('SELECT id, first_name, last_name, created_at FROM pending_residents WHERE purok_id = ? AND status = "pending" ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_registrations_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_registrations_list = [];
}

// Fetch pending family additions for notifications
try {
    $stmt = db()->prepare('SELECT rfa.id, rfa.first_name, rfa.last_name, rfa.created_at FROM resident_family_additions rfa JOIN residents res ON res.id = rfa.resident_id WHERE res.purok_id = ? AND rfa.status = "pending" ORDER BY rfa.created_at DESC LIMIT 5');
    $stmt->execute([$bhw_purok_id]);
    $pending_family_additions_list = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_family_additions_list = [];
}

// Fetch this BHW's duty schedules
$my_schedules = [];
try {
    $stmt = db()->prepare('
        SELECT 
            id,
            duty_date,
            shift_start,
            shift_end,
            is_active,
            created_at
        FROM bhw_duty_schedules
        WHERE bhw_id = ?
        ORDER BY duty_date ASC
    ');
    $stmt->execute([$user['id']]);
    $my_schedules = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Error fetching schedules: ' . $e->getMessage());
}

// Check if today is a duty day
$is_duty_today = false;
$today_schedule = null;
$today = date('Y-m-d');
foreach ($my_schedules as $schedule) {
    if ($schedule['duty_date'] === $today && $schedule['is_active'] == 1) {
        $is_duty_today = true;
        $today_schedule = $schedule;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Schedule · BHW</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
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
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
    <!-- Sidebar -->
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php 
        require_once __DIR__ . '/includes/header.php';
        render_bhw_header([
            'user_data' => $user_data,
            'notification_counts' => $notification_counts,
            'pending_requests' => $pending_requests_list,
            'pending_registrations' => $pending_registrations_list,
            'pending_family_additions' => $pending_family_additions_list
        ]);
        ?>
        
        <div class="p-6">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">My Duty Schedule</h1>
            <p class="text-gray-600">View your assigned duty dates and shifts</p>
        </div>

        <!-- Content -->
        <div class="content-body">
            <!-- Today's Duty Status -->
            <?php if ($is_duty_today): ?>
                <div class="mb-6 p-6 bg-gradient-to-r from-green-50 to-emerald-50 border-2 border-green-200 rounded-xl shadow-lg">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-calendar-check text-white text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold text-green-900 mb-1">You are on duty today!</h2>
                            <p class="text-green-700">
                                <?php if ($today_schedule['shift_start'] || $today_schedule['shift_end']): ?>
                                    Shift: 
                                    <?php 
                                    if ($today_schedule['shift_start'] && $today_schedule['shift_end']) {
                                        echo date('h:i A', strtotime($today_schedule['shift_start'])) . ' - ' . date('h:i A', strtotime($today_schedule['shift_end']));
                                    } elseif ($today_schedule['shift_start']) {
                                        echo 'From ' . date('h:i A', strtotime($today_schedule['shift_start']));
                                    } elseif ($today_schedule['shift_end']) {
                                        echo 'Until ' . date('h:i A', strtotime($today_schedule['shift_end']));
                                    }
                                    ?>
                                <?php else: ?>
                                    Full day duty
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-6 p-6 bg-gray-50 border border-gray-200 rounded-xl">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0">
                            <div class="w-16 h-16 bg-gray-300 rounded-full flex items-center justify-center">
                                <i class="fas fa-calendar-times text-gray-600 text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-xl font-bold text-gray-900 mb-1">You are not on duty today</h2>
                            <p class="text-gray-600">Check your schedule below for upcoming duty dates.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Upcoming Schedules -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-purple-50">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas fa-calendar-alt mr-2 text-blue-600"></i>
                        My Duty Schedule
                    </h2>
                </div>
                
                <div class="p-6">
                    <?php if (empty($my_schedules)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-calendar-times text-5xl text-gray-300 mb-4"></i>
                            <p class="text-gray-600 text-lg mb-2">No duty schedule assigned</p>
                            <p class="text-gray-500">Contact the Super Admin to get your duty schedule assigned.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php 
                            $upcoming_count = 0;
                            $past_count = 0;
                            foreach ($my_schedules as $schedule): 
                                $schedule_date = strtotime($schedule['duty_date']);
                                $is_past = $schedule_date < strtotime($today);
                                $is_today = $schedule['duty_date'] === $today;
                                
                                if ($is_past) $past_count++;
                                else $upcoming_count++;
                            ?>
                                <div class="border border-gray-200 rounded-lg p-4 <?php echo $is_today ? 'bg-green-50 border-green-300' : ($is_past ? 'bg-gray-50 opacity-75' : 'bg-white'); ?>">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3 mb-2">
                                                <h3 class="text-lg font-semibold text-gray-900">
                                                    <?php echo date('l, F d, Y', $schedule_date); ?>
                                                </h3>
                                                <?php if ($is_today): ?>
                                                    <span class="px-3 py-1 bg-green-500 text-white text-sm font-semibold rounded-full">
                                                        Today
                                                    </span>
                                                <?php elseif ($is_past): ?>
                                                    <span class="px-3 py-1 bg-gray-400 text-white text-sm font-semibold rounded-full">
                                                        Past
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-3 py-1 bg-blue-500 text-white text-sm font-semibold rounded-full">
                                                        Upcoming
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($schedule['is_active']): ?>
                                                    <span class="px-3 py-1 bg-green-100 text-green-800 text-sm rounded-full">
                                                        Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-3 py-1 bg-gray-100 text-gray-800 text-sm rounded-full">
                                                        Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($schedule['shift_start'] || $schedule['shift_end']): ?>
                                                <div class="flex items-center gap-2 text-gray-700">
                                                    <i class="fas fa-clock text-blue-600"></i>
                                                    <span class="font-medium">
                                                        <?php 
                                                        if ($schedule['shift_start'] && $schedule['shift_end']) {
                                                            echo date('h:i A', strtotime($schedule['shift_start'])) . ' - ' . date('h:i A', strtotime($schedule['shift_end']));
                                                        } elseif ($schedule['shift_start']) {
                                                            echo 'From ' . date('h:i A', strtotime($schedule['shift_start']));
                                                        } elseif ($schedule['shift_end']) {
                                                            echo 'Until ' . date('h:i A', strtotime($schedule['shift_end']));
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-center gap-2 text-gray-700">
                                                    <i class="fas fa-clock text-blue-600"></i>
                                                    <span class="font-medium">Full day duty</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Summary -->
                        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="text-sm text-blue-600 font-medium mb-1">Total Schedules</div>
                                <div class="text-2xl font-bold text-blue-900"><?php echo count($my_schedules); ?></div>
                            </div>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <div class="text-sm text-green-600 font-medium mb-1">Upcoming</div>
                                <div class="text-2xl font-bold text-green-900"><?php echo $upcoming_count; ?></div>
                            </div>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <div class="text-sm text-gray-600 font-medium mb-1">Past</div>
                                <div class="text-2xl font-bold text-gray-900"><?php echo $past_count; ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
