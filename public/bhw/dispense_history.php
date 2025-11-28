<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);

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
$notification_counts = get_bhw_notification_counts($bhw_purok_id, $user['id']);

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

// Fetch all dispensed medicines history
$dispense_history = [];
$total_dispensed = 0;
$total_quantity = 0;

try {
    $stmt = db()->prepare('
        SELECT 
            rd.id,
            rd.request_id,
            rd.dispensed_at,
            rd.quantity_released,
            rd.dispensing_notes,
            rd.batch_id,
            r.medicine_id,
            r.resident_id,
            r.requested_for,
            r.patient_name,
            r.relationship,
            m.name AS medicine_name,
            m.image_path AS medicine_image,
            mb.batch_code,
            mb.expiry_date,
            CONCAT(IFNULL(res.first_name, ""), " ", IFNULL(res.middle_initial, ""), " ", IFNULL(res.last_name, "")) AS resident_name,
            res.purok_id,
            p.name AS purok_name,
            CONCAT(IFNULL(bhw.first_name, ""), " ", IFNULL(bhw.middle_initial, ""), " ", IFNULL(bhw.last_name, "")) AS dispensed_by_name,
            CONCAT(IFNULL(approver.first_name, ""), " ", IFNULL(approver.middle_initial, ""), " ", IFNULL(approver.last_name, "")) AS approved_by_name,
            ra.approved_at
        FROM request_dispensings rd
        JOIN requests r ON r.id = rd.request_id
        JOIN medicines m ON m.id = r.medicine_id
        JOIN medicine_batches mb ON mb.id = rd.batch_id
        JOIN residents res ON res.id = r.resident_id
        LEFT JOIN puroks p ON p.id = res.purok_id
        JOIN users bhw ON bhw.id = rd.dispensed_by
        LEFT JOIN request_approvals ra ON ra.request_id = r.id AND ra.approval_status = "approved"
        LEFT JOIN users approver ON approver.id = ra.approved_by
        ORDER BY rd.dispensed_at DESC
    ');
    $stmt->execute();
    $dispense_history = $stmt->fetchAll();
    
    // Calculate totals
    $total_dispensed = count($dispense_history);
    foreach ($dispense_history as $record) {
        $total_quantity += (int)$record['quantity_released'];
    }
} catch (Throwable $e) {
    error_log('Error fetching dispense history: ' . $e->getMessage());
    $dispense_history = [];
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dispense History · BHW</title>
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
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 bhw-theme">
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
        <div class="content-header">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Dispense History</h1>
                <p class="text-gray-600 mt-1">View all records of dispensed medicines</p>
            </div>
        </div>

        <div class="content-body">
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Total Dispensed</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $total_dispensed; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Medicine records</p>
                        </div>
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-history text-2xl text-blue-600"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Total Quantity</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $total_quantity; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Units dispensed</p>
                        </div>
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-pills text-2xl text-green-600"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Today's Dispensed</p>
                            <?php
                            $today_count = 0;
                            $today_quantity = 0;
                            foreach ($dispense_history as $record) {
                                if (date('Y-m-d', strtotime($record['dispensed_at'])) === date('Y-m-d')) {
                                    $today_count++;
                                    $today_quantity += (int)$record['quantity_released'];
                                }
                            }
                            ?>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $today_count; ?></p>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $today_quantity; ?> units today</p>
                        </div>
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-calendar-day text-2xl text-purple-600"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History Table -->
            <?php if (empty($dispense_history)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-history text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">No Dispense History</h3>
                    <p class="text-gray-600 mb-6">No medicines have been dispensed yet.</p>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-purple-50">
                        <h2 class="text-xl font-bold text-gray-900">
                            <i class="fas fa-list mr-2 text-blue-600"></i>
                            All Dispensed Medicines
                        </h2>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Date & Time</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Medicine</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Patient</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Batch</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Quantity</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Approved By</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Dispensed By</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($dispense_history as $record): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo date('M d, Y', strtotime($record['dispensed_at'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('h:i A', strtotime($record['dispensed_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center space-x-3">
                                                <?php if (!empty($record['medicine_image'])): ?>
                                                    <img src="<?php echo htmlspecialchars(base_url($record['medicine_image'])); ?>" 
                                                         alt="<?php echo htmlspecialchars($record['medicine_name']); ?>" 
                                                         class="w-10 h-10 object-cover rounded-lg border border-gray-200"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold" style="display: none;">
                                                        <?php echo strtoupper(substr($record['medicine_name'], 0, 2)); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold">
                                                        <?php echo strtoupper(substr($record['medicine_name'], 0, 2)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900">
                                                        <?php echo htmlspecialchars($record['medicine_name']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        Request #<?php echo $record['request_id']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php 
                                                if ($record['requested_for'] === 'family') {
                                                    echo htmlspecialchars($record['patient_name'] ?? 'N/A');
                                                    if (!empty($record['relationship'])) {
                                                        echo ' <span class="text-gray-500">(' . htmlspecialchars($record['relationship']) . ')</span>';
                                                    }
                                                } else {
                                                    echo htmlspecialchars($record['resident_name'] ?? 'N/A');
                                                }
                                                ?>
                                            </div>
                                            <?php if ($record['purok_name']): ?>
                                                <div class="text-xs text-gray-500">
                                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                                    <?php echo htmlspecialchars($record['purok_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($record['batch_code']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                Expires: <?php echo date('M d, Y', strtotime($record['expiry_date'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                                                <i class="fas fa-box mr-1"></i>
                                                <?php echo $record['quantity_released']; ?> units
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($record['approved_by_name'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if (!empty($record['approved_at'])): ?>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('M d, Y', strtotime($record['approved_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($record['dispensed_by_name'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if (!empty($record['dispensing_notes'])): ?>
                                                <div class="text-sm text-gray-600 max-w-xs truncate" title="<?php echo htmlspecialchars($record['dispensing_notes']); ?>">
                                                    <i class="fas fa-sticky-note mr-1 text-gray-400"></i>
                                                    <?php echo htmlspecialchars($record['dispensing_notes']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">No notes</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

