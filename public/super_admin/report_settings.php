<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $center_name = trim($_POST['center_name'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $rhu_cho = trim($_POST['rhu_cho'] ?? '');
    $bhw_name = trim($_POST['bhw_name'] ?? '');
    // Use submitted_to_staff from form, map to rural_staff in DB
    $rural_staff = trim($_POST['submitted_to_staff'] ?? '');
    $municipal_staff = trim($_POST['municipal_staff'] ?? '');

    // Check if row exists
    $check = db()->query("SELECT id FROM report_settings LIMIT 1")->fetch();

    if ($check) {
        $sql = "UPDATE report_settings SET 
                center_name = ?, municipality = ?, province = ?, rhu_cho = ?, 
                bhw_name = ?, rural_staff = ?, municipal_staff = ? 
                WHERE id = ?";
        $stmt = db()->prepare($sql);
        $stmt->execute([$center_name, $municipality, $province, $rhu_cho, $bhw_name, $rural_staff, $municipal_staff, $check['id']]);
    } else {
        $sql = "INSERT INTO report_settings (center_name, municipality, province, rhu_cho, bhw_name, rural_staff, municipal_staff) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = db()->prepare($sql);
        $stmt->execute([$center_name, $municipality, $province, $rhu_cho, $bhw_name, $rural_staff, $municipal_staff]);
    }

    set_flash('Report settings updated successfully', 'success');
    redirect_to('super_admin/report_settings.php');
    exit;
}

require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

$user = current_user();

// Fetch current settings
$stmt = db()->query("SELECT * FROM report_settings LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    // Should have been created by setup script, but just in case
    $settings = [
        'center_name' => 'BASDASCU HEALTH CENTER',
        'municipality' => 'LOON',
        'province' => 'Bohol',
        'rhu_cho' => 'RHU 1 - LOON',
        'bhw_name' => '',
        'rural_staff' => '',
        'municipal_staff' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Report Settings · Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
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
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => 'report_settings.php',
        'user_data' => $user
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Unified Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-3 py-3 sm:px-4 sm:py-4 md:px-6 h-16">
                <!-- Left Section: Menu + Logo/Title -->
                <div class="flex items-center flex-1 min-w-0 h-full">
                    <button id="mobileMenuToggle" class="md:hidden text-gray-500 hover:text-gray-700 mr-2 sm:mr-3 flex-shrink-0 flex items-center justify-center w-10 h-10" aria-label="Toggle menu" type="button">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <!-- Mobile Logo + Title -->
                    <div class="md:hidden flex items-center min-w-0 flex-1 h-full">
                        <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                            <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg flex-shrink-0 mr-2" alt="Logo" />
                        <?php else: ?>
                            <i class="fas fa-heartbeat text-purple-600 text-2xl mr-2 flex-shrink-0"></i>
                        <?php endif; ?>
                        <h1 class="text-lg sm:text-xl font-bold text-gray-900 truncate leading-none"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
                    </div>
                    <!-- Desktop Title (hidden on mobile) -->
                    <div class="hidden md:flex items-center h-full">
                        <h1 class="text-xl font-bold text-gray-900 leading-none">Report Configuration</h1>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-body px-8 pb-8 pt-8">
            <?php [$flash, $ft] = get_flash(); if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $ft==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?> animate-fade-in">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?php if ($ft === 'success'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php endif; ?>
                        </svg>
                        <?php echo htmlspecialchars($flash); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8">
                <form method="POST" class="space-y-8">
                    <!-- Health Facility Information -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">🏥 Health Facility Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Health Center Name</label>
                                <input type="text" name="center_name" value="<?php echo htmlspecialchars($settings['center_name']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">RHU / CHO</label>
                                <input type="text" name="rhu_cho" value="<?php echo htmlspecialchars($settings['rhu_cho']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Municipality</label>
                                <input type="text" name="municipality" value="<?php echo htmlspecialchars($settings['municipality']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Province</label>
                                <input type="text" name="province" value="<?php echo htmlspecialchars($settings['province']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Personnel Information -->
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">👥 Personnel Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Prepared By (BHW / Midwife)</label>
                                <input type="text" name="bhw_name" value="<?php echo htmlspecialchars($settings['bhw_name']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Juan Dela Cruz">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Submitted To (RHU Staff)</label>
                                <input type="text" name="submitted_to_staff" value="<?php echo htmlspecialchars($settings['rural_staff'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Maria Santos">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Approved By (MHO)</label>
                                <input type="text" name="municipal_staff" value="<?php echo htmlspecialchars($settings['municipal_staff']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Dr. Jose Rizal">
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-6 border-t">
                        <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold flex items-center space-x-2 shadow-md transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                            <span>Save Settings</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>
