<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

// Check if this is an AJAX request or should be loaded in dashboard shell
// Redirect logic moved to after POST handling

$user = current_user();

// Get fresh user data
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $bhw_id = (int)($_POST['bhw_id'] ?? 0);
        $duty_date = $_POST['duty_date'] ?? '';
        $shift_start = !empty($_POST['shift_start']) ? $_POST['shift_start'] : null;
        $shift_end = !empty($_POST['shift_end']) ? $_POST['shift_end'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $schedule_id = $action === 'update' ? (int)($_POST['schedule_id'] ?? 0) : 0;
        
        if ($bhw_id > 0 && !empty($duty_date)) {
            try {
                if ($action === 'create') {
                    $stmt = db()->prepare('
                        INSERT INTO bhw_duty_schedules (bhw_id, duty_date, shift_start, shift_end, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            shift_start = VALUES(shift_start),
                            shift_end = VALUES(shift_end),
                            is_active = VALUES(is_active),
                            updated_at = NOW()
                    ');
                    $stmt->execute([$bhw_id, $duty_date, $shift_start, $shift_end, $is_active, $user['id']]);
                    set_flash('Duty schedule created successfully!', 'success');
                } else {
                    $stmt = db()->prepare('
                        UPDATE bhw_duty_schedules 
                        SET bhw_id = ?, duty_date = ?, shift_start = ?, shift_end = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([$bhw_id, $duty_date, $shift_start, $shift_end, $is_active, $schedule_id]);
                    set_flash('Duty schedule updated successfully!', 'success');
                }
            } catch (Throwable $e) {
                error_log('Duty schedule error: ' . $e->getMessage());
                set_flash('Failed to save duty schedule: ' . $e->getMessage(), 'error');
            }
        } else {
            set_flash('Please fill in all required fields.', 'error');
        }
    } elseif ($action === 'delete') {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        if ($schedule_id > 0) {
            try {
                $stmt = db()->prepare('DELETE FROM bhw_duty_schedules WHERE id = ?');
                $stmt->execute([$schedule_id]);
                set_flash('Duty schedule deleted successfully!', 'success');
            } catch (Throwable $e) {
                error_log('Delete schedule error: ' . $e->getMessage());
                set_flash('Failed to delete duty schedule.', 'error');
            }
        }
    }
    
    redirect_to('super_admin/duty_schedules.php');
}

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

// Fetch all BHWs
$bhws = [];
try {
    $stmt = db()->prepare('
        SELECT u.id, u.first_name, u.last_name, u.middle_initial, u.email, p.name as purok_name
        FROM users u
        LEFT JOIN puroks p ON p.id = u.purok_id
        WHERE u.role = "bhw"
        ORDER BY u.last_name, u.first_name
    ');
    $stmt->execute();
    $bhws = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Error fetching BHWs: ' . $e->getMessage());
}

// Fetch duty schedules with BHW info
$schedules = [];
$table_exists = false;

// Check if table exists first - wrap in try-catch to prevent fatal errors
try {
    $tableCheck = db()->query("SHOW TABLES LIKE 'bhw_duty_schedules'")->fetch();
    $table_exists = !empty($tableCheck);
} catch (Throwable $e) {
    error_log('Error checking table: ' . $e->getMessage());
    $table_exists = false;
}

// Only fetch schedules if table exists
if ($table_exists) {
    try {
        $stmt = db()->prepare('
            SELECT 
                ds.id,
                ds.bhw_id,
                ds.duty_date,
                ds.shift_start,
                ds.shift_end,
                ds.is_active,
                ds.created_at,
                CONCAT(IFNULL(u.first_name, ""), " ", IFNULL(u.middle_initial, ""), " ", IFNULL(u.last_name, "")) AS bhw_name,
                u.email as bhw_email,
                p.name as purok_name
            FROM bhw_duty_schedules ds
            JOIN users u ON u.id = ds.bhw_id
            LEFT JOIN puroks p ON p.id = u.purok_id
            ORDER BY ds.duty_date DESC, ds.shift_start ASC
        ');
        $stmt->execute();
        $schedules = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Error fetching schedules: ' . $e->getMessage());
        $schedules = [];
    }
}

// Helper function to get upload URL (if not already defined)
if (!function_exists('upload_url')) {
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
}

// Fetch notification data (similar to dashboardnew.php)
try {
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "submitted"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
}

try {
    $inventory_alerts = db()->query('
        SELECT ia.id, ia.severity, ia.message, ia.created_at,
               m.name as medicine_name,
               DATE_FORMAT(ia.created_at, "%b %d, %Y") as formatted_date
        FROM inventory_alerts ia
        JOIN medicines m ON ia.medicine_id = m.id
        WHERE ia.is_acknowledged = FALSE
        ORDER BY 
            CASE ia.severity
                WHEN "critical" THEN 1
                WHEN "high" THEN 2
                WHEN "medium" THEN 3
                ELSE 4
            END,
            ia.created_at DESC
        LIMIT 5
    ')->fetchAll();
    $alerts_count = count($inventory_alerts);
} catch (Exception $e) {
    $inventory_alerts = [];
    $alerts_count = 0;
}

try {
    $recent_pending_requests = db()->query('
        SELECT r.id, r.status, r.created_at,
               CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) as requester_name,
               DATE_FORMAT(r.created_at, "%b %d, %Y") as formatted_date
        FROM requests r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status = "submitted"
        ORDER BY r.created_at DESC
        LIMIT 5
    ')->fetchAll();
} catch (Exception $e) {
    $recent_pending_requests = [];
}

$total_notifications = $pending_requests + $alerts_count;

// Get today's date for default
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

// Get current page for sidebar highlighting - normalize it
// When using the unified dashboard shell with ?target=..., prefer the target.
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
if (!empty($_GET['target'])) {
    $targetPath = parse_url((string)$_GET['target'], PHP_URL_PATH);
    if (is_string($targetPath) && $targetPath !== '') {
        $current_page = basename($targetPath);
    }
}
// Remove any query parameters if present
$current_page = strtok($current_page, '?');
// Ensure it's lowercase for consistent comparison
$current_page = strtolower(trim($current_page));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Duty Schedules · Super Admin</title>
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
        /* Force reset all styles to prevent conflicts from other pages */
        * {
            box-sizing: border-box;
        }
        
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            height: 100% !important;
            overflow-x: hidden !important;
        }
        
        body {
            font-family: 'Inter', system-ui, sans-serif !important;
            background: #f9fafb !important;
        }
        
        /* App container */
        #app.dashboard-shell {
            display: flex !important;
            min-height: 100vh !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            position: relative !important;
        }
        
        /* Sidebar positioning - must be fixed */
        #sidebar-aside {
            position: fixed !important;
            left: 0 !important;
            top: 0 !important;
            height: 100vh !important;
            z-index: 50 !important;
            width: 256px !important;
        }
        
        /* Dashboard main wrapper */
        #dashboard-main-wrapper {
            flex: 1 !important;
            display: flex !important;
            flex-direction: column !important;
            min-height: 100vh !important;
            margin-left: 256px !important;
            width: calc(100% - 256px) !important;
            position: relative !important;
        }
        
        /* Ensure header is properly sized and positioned */
        header {
            position: sticky !important;
            top: 0 !important;
            z-index: 30 !important;
            height: 80px !important;
            min-height: 80px !important;
            max-height: 80px !important;
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        header > div {
            height: 100% !important;
            min-height: 80px !important;
            display: flex !important;
            align-items: center !important;
        }
        
        /* Ensure main content area is properly positioned */
        #main-content-area {
            flex: 1 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            background: #f9fafb !important;
            padding: 1.5rem !important;
            margin: 0 !important;
            width: 100% !important;
            position: relative !important;
        }
        
        /* Mobile responsive */
        @media (max-width: 767px) {
            #sidebar-aside {
                left: -256px !important;
            }
            
            #dashboard-main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }
        
        .schedule-card {
            transition: all 0.2s ease;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        /* Sidebar overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-overlay.show {
            display: block;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
            <!-- Page Header -->
            <div class="content-header">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">BHW Duty Schedules</h1>
                    <p class="text-gray-600 mt-1">Manage duty schedules for Barangay Health Workers</p>
                </div>
            </div>
            
            <div class="content-body">
            <?php if (!$table_exists): ?>
                <div class="bg-yellow-50 border-2 border-yellow-200 rounded-xl p-8 mb-6">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-4xl text-yellow-600"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-yellow-900 mb-2">Database Table Not Found</h3>
                            <p class="text-yellow-800 mb-4">
                                The <code class="bg-yellow-100 px-2 py-1 rounded">bhw_duty_schedules</code> table doesn't exist yet. 
                                Please run the SQL migration file to create it.
                            </p>
                            <p class="text-sm text-yellow-700">
                                <strong>File to run:</strong> <code>database/create_approval_dispensing_logs.sql</code>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="max-w-7xl mx-auto">

                <!-- Flash Message -->
                <?php
                $flash = get_flash();
                if ($flash && !empty($flash[0])): 
                    list($flash_msg, $flash_type) = $flash;
                ?>
                    <div class="mb-6 p-4 rounded-lg <?php echo $flash_type === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : ($flash_type === 'warning' ? 'bg-yellow-100 text-yellow-700 border border-yellow-200' : 'bg-red-100 text-red-700 border border-red-200'); ?> flex items-center justify-between animate-fade-in-up">
                        <div class="flex items-center">
                            <?php if ($flash_type === 'success'): ?>
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <?php elseif ($flash_type === 'warning'): ?>
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($flash_msg); ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-sm font-semibold hover:underline">Dismiss</button>
                    </div>
                <?php endif; ?>

                <!-- Create Schedule Button -->
                <?php if ($table_exists): ?>
                <div class="mb-6">
                    <button onclick="openCreateModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i>
                        Create Duty Schedule
                    </button>
                </div>
                <?php endif; ?>

                <!-- Schedules List -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">All Duty Schedules</h2>
                    </div>
                    <div class="p-6">
                        <?php if (empty($schedules)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-calendar-times text-5xl text-gray-300 mb-4"></i>
                                <p class="text-gray-600">No duty schedules found. Create one to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($schedules as $schedule): ?>
                                    <div class="schedule-card bg-white border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-start justify-between mb-3">
                                            <div>
                                                <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($schedule['bhw_name']); ?></h3>
                                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($schedule['purok_name'] ?? 'No Purok'); ?></p>
                                            </div>
                                            <?php if ($schedule['is_active']): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">Active</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded-full">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="space-y-2 text-sm">
                                            <div>
                                                <span class="text-gray-600">Date:</span>
                                                <span class="font-medium text-gray-900">
                                                    <?php echo date('M d, Y', strtotime($schedule['duty_date'])); ?>
                                                </span>
                                            </div>
                                            <?php if ($schedule['shift_start'] || $schedule['shift_end']): ?>
                                                <div>
                                                    <span class="text-gray-600">Shift:</span>
                                                    <span class="font-medium text-gray-900">
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
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-4 flex gap-2">
                                            <button onclick="openEditModal(<?php echo $schedule['id']; ?>)" 
                                                    class="flex-1 px-3 py-2 text-sm bg-blue-50 text-blue-600 rounded hover:bg-blue-100 transition-colors"
                                                    data-schedule='<?php echo htmlspecialchars(json_encode($schedule, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                                <i class="fas fa-edit mr-1"></i>
                                                Edit
                                            </button>
                                            <button onclick="deleteSchedule(<?php echo $schedule['id']; ?>)" 
                                                    class="px-3 py-2 text-sm bg-red-50 text-red-600 rounded hover:bg-red-100 transition-colors">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

    <!-- Create/Edit Modal -->
    <div id="scheduleModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 id="modalTitle" class="text-xl font-bold text-gray-900">Create Duty Schedule</h2>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="scheduleForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="schedule_id" id="scheduleId">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">BHW <span class="text-red-500">*</span></label>
                        <select name="bhw_id" id="bhwId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select BHW</option>
                            <?php foreach ($bhws as $bhw): ?>
                                <option value="<?php echo $bhw['id']; ?>">
                                    <?php echo htmlspecialchars(trim(($bhw['first_name'] ?? '') . ' ' . ($bhw['middle_initial'] ?? '') . ' ' . ($bhw['last_name'] ?? ''))); ?>
                                    <?php if ($bhw['purok_name']): ?>
                                        - <?php echo htmlspecialchars($bhw['purok_name']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Duty Date <span class="text-red-500">*</span></label>
                        <input type="date" name="duty_date" id="dutyDate" required 
                               min="<?php echo $today; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Shift Start (Optional)</label>
                            <input type="time" name="shift_start" id="shiftStart" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Shift End (Optional)</label>
                            <input type="time" name="shift_end" id="shiftEnd" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_active" id="isActive" checked class="mr-2">
                            <span class="text-sm text-gray-700">Active</span>
                        </label>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        Save Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create Duty Schedule';
            document.getElementById('formAction').value = 'create';
            document.getElementById('scheduleId').value = '';
            document.getElementById('scheduleForm').reset();
            document.getElementById('dutyDate').value = '<?php echo $today; ?>';
            document.getElementById('isActive').checked = true;
            document.getElementById('scheduleModal').classList.remove('hidden');
        }
        
        function openEditModal(scheduleId) {
            const button = event.target.closest('button[data-schedule]');
            if (!button) return;
            const schedule = JSON.parse(button.getAttribute('data-schedule'));
            
            document.getElementById('modalTitle').textContent = 'Edit Duty Schedule';
            document.getElementById('formAction').value = 'update';
            document.getElementById('scheduleId').value = schedule.id;
            document.getElementById('bhwId').value = schedule.bhw_id;
            document.getElementById('dutyDate').value = schedule.duty_date;
            document.getElementById('shiftStart').value = schedule.shift_start || '';
            document.getElementById('shiftEnd').value = schedule.shift_end || '';
            document.getElementById('isActive').checked = schedule.is_active == 1;
            document.getElementById('scheduleModal').classList.remove('hidden');
        }
        
        function closeModal() {
            document.getElementById('scheduleModal').classList.add('hidden');
        }
        
        function deleteSchedule(id) {
            Swal.fire({
                title: 'Delete Schedule?',
                text: 'Are you sure you want to delete this duty schedule?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="schedule_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Close modal on outside click
        document.getElementById('scheduleModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>

