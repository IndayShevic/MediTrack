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

// Check if this BHW is on duty today
$is_duty_today = false;
$today = date('Y-m-d');
try {
    $dutyCheck = db()->prepare('
        SELECT id, shift_start, shift_end 
        FROM bhw_duty_schedules 
        WHERE bhw_id = ? AND duty_date = ? AND is_active = 1
    ');
    $dutyCheck->execute([$user['id'], $today]);
    $duty_schedule = $dutyCheck->fetch();
    $is_duty_today = !empty($duty_schedule);
} catch (Throwable $e) {
    error_log('Error checking duty schedule: ' . $e->getMessage());
}

// Get notification counts for sidebar
require_once __DIR__ . '/includes/sidebar_counts.php';
$notification_counts = get_bhw_notification_counts($bhw_purok_id);

// Handle dispensing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    $quantity_released = (int)($_POST['quantity_released'] ?? 1);
    $dispensing_notes = trim($_POST['dispensing_notes'] ?? '');
    
    if ($request_id > 0 && $batch_id > 0 && $quantity_released > 0) {
        $pdo = db();
        $pdo->beginTransaction();
        
        try {
            // Verify request is approved and ready to dispense
            $reqCheck = $pdo->prepare('
                SELECT r.id, r.medicine_id, r.status, r.is_ready_to_dispense, res.purok_id
                FROM requests r
                JOIN residents res ON res.id = r.resident_id
                WHERE r.id = ? AND r.status = "approved" AND r.is_ready_to_dispense = 1
            ');
            $reqCheck->execute([$request_id]);
            $request = $reqCheck->fetch();
            
            if (!$request) {
                throw new Exception('Request not found or not ready for dispensing.');
            }
            
            // Verify batch belongs to the medicine and has enough quantity
            $batchCheck = $pdo->prepare('
                SELECT mb.id, mb.medicine_id, mb.quantity_available, mb.batch_code, mb.expiry_date
                FROM medicine_batches mb
                WHERE mb.id = ? AND mb.medicine_id = ? AND mb.quantity_available >= ? AND mb.expiry_date > CURDATE()
            ');
            $batchCheck->execute([$batch_id, $request['medicine_id'], $quantity_released]);
            $batch = $batchCheck->fetch();
            
            if (!$batch) {
                throw new Exception('Invalid batch or insufficient quantity available.');
            }
            
            // Get BHW full name for dispensing log
            $bhwNameStmt = $pdo->prepare('SELECT CONCAT(IFNULL(first_name, ""), " ", IFNULL(middle_initial, ""), " ", IFNULL(last_name, "")) AS full_name FROM users WHERE id = ?');
            $bhwNameStmt->execute([$user['id']]);
            $bhwNameData = $bhwNameStmt->fetch();
            $bhwFullName = $bhwNameData['full_name'] ?? ($user['first_name'] . ' ' . $user['last_name']);
            
            // Update batch quantity
            $updateBatch = $pdo->prepare('UPDATE medicine_batches SET quantity_available = quantity_available - ? WHERE id = ?');
            $updateBatch->execute([$quantity_released, $batch_id]);
            
            // Create fulfillment record
            $fulfillStmt = $pdo->prepare('INSERT INTO request_fulfillments (request_id, batch_id, quantity) VALUES (?, ?, ?)');
            $fulfillStmt->execute([$request_id, $batch_id, $quantity_released]);
            
            // Create dispensing log
            $dispensingStmt = $pdo->prepare('
                INSERT INTO request_dispensings (request_id, dispensed_by, batch_id, quantity_released, dispensing_notes)
                VALUES (?, ?, ?, ?, ?)
            ');
            $dispensingStmt->execute([
                $request_id,
                $user['id'],
                $batch_id,
                $quantity_released,
                $dispensing_notes ?: null
            ]);
            
            // Update request status to dispensed
            $updateRequest = $pdo->prepare('UPDATE requests SET status = "dispensed", is_ready_to_dispense = 0 WHERE id = ?');
            $updateRequest->execute([$request_id]);
            
            // Log inventory transaction
            require_once __DIR__ . '/../../config/inventory_functions.php';
            if (function_exists('logInventoryTransaction')) {
                logInventoryTransaction(
                    $request['medicine_id'],
                    $batch_id,
                    'OUT',
                    -$quantity_released,
                    'REQUEST_DISPENSED',
                    $request_id,
                    "Dispensed {$quantity_released} units from batch {$batch['batch_code']}",
                    $user['id']
                );
            }
            
            $pdo->commit();
            
            set_flash("Medicine dispensed successfully! Batch: {$batch['batch_code']}, Quantity: {$quantity_released}", 'success');
            
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Dispensing error: ' . $e->getMessage());
            set_flash('Failed to dispense medicine: ' . $e->getMessage(), 'error');
        }
    } else {
        set_flash('Invalid dispensing data provided.', 'error');
    }
    
    redirect_to('bhw/dispense_medicines.php');
}

// Fetch approved requests ready for dispensing
// Only show if this BHW is on duty today
$approvedRequests = [];
if ($is_duty_today) {
    try {
        // Duty BHW can dispense ALL approved requests from ANY purok
        // This allows centralized dispensing by the duty BHW
        $stmt = db()->prepare('
            SELECT 
                r.id,
                r.medicine_id,
                r.resident_id,
                r.requested_for,
                r.patient_name,
                r.patient_date_of_birth,
                r.relationship,
                r.reason,
                r.created_at,
                m.name AS medicine_name,
                m.image_path AS medicine_image_path,
                CONCAT(IFNULL(res.first_name, ""), " ", IFNULL(res.middle_initial, ""), " ", IFNULL(res.last_name, "")) AS resident_name,
                res.purok_id,
                p.name AS purok_name,
                CONCAT(IFNULL(apb.first_name, ""), " ", IFNULL(apb.middle_initial, ""), " ", IFNULL(apb.last_name, "")) AS approved_by_name,
                ra.approved_at,
                ra.approval_remarks
            FROM requests r
            JOIN medicines m ON m.id = r.medicine_id
            JOIN residents res ON res.id = r.resident_id
            LEFT JOIN puroks p ON p.id = res.purok_id
            LEFT JOIN request_approvals ra ON ra.request_id = r.id AND ra.approval_status = "approved"
            LEFT JOIN users apb ON apb.id = ra.approved_by
            WHERE r.status = "approved" AND r.is_ready_to_dispense = 1
            ORDER BY r.created_at ASC
        ');
        $stmt->execute();
        $approvedRequests = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Error fetching approved requests: ' . $e->getMessage());
        $approvedRequests = [];
    }
}

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dispense Medicines · BHW</title>
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
        .batch-card {
            transition: all 0.2s ease;
        }
        .batch-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .batch-card.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
    </style>
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
                <h1 class="text-3xl font-bold text-gray-900">Dispense Medicines</h1>
                <p class="text-gray-600 mt-1">Dispense approved medicine requests using FEFO (First Expiry, First Out)</p>
            </div>
        </div>

        <div class="content-body">
            <?php if (!$is_duty_today): ?>
                <!-- Not on duty message -->
                <div class="bg-yellow-50 border-2 border-yellow-200 rounded-xl p-8 text-center">
                    <div class="w-24 h-24 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-calendar-times text-5xl text-yellow-600"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-yellow-900 mb-4">You are not on duty today</h3>
                    <p class="text-gray-700 mb-4 text-lg">
                        Only BHWs who are scheduled for duty today can dispense medicines.
                    </p>
                    <p class="text-gray-600 mb-6">
                        Please check your <a href="<?php echo htmlspecialchars(base_url('bhw/my_schedule.php')); ?>" class="text-blue-600 hover:text-blue-800 font-semibold underline">duty schedule</a> to see when you're assigned.
                    </p>
                    <div class="bg-white rounded-lg p-4 border border-yellow-200">
                        <p class="text-sm text-gray-600">
                            <strong>Today's Date:</strong> <?php echo date('F d, Y'); ?>
                        </p>
                    </div>
                </div>
            <?php elseif (empty($approvedRequests)): ?>
                <div class="empty-state-card p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-check-circle text-5xl text-gray-400"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">No Approved Requests</h3>
                    <p class="text-gray-600 mb-6 text-lg">There are no approved requests ready for dispensing at this time.</p>
                    <?php if ($duty_schedule['shift_start'] || $duty_schedule['shift_end']): ?>
                        <div class="bg-blue-50 rounded-lg p-4 max-w-md mx-auto">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-clock mr-2"></i>
                                <strong>Your Shift Today:</strong>
                                <?php 
                                if ($duty_schedule['shift_start'] && $duty_schedule['shift_end']) {
                                    echo date('h:i A', strtotime($duty_schedule['shift_start'])) . ' - ' . date('h:i A', strtotime($duty_schedule['shift_end']));
                                } elseif ($duty_schedule['shift_start']) {
                                    echo 'From ' . date('h:i A', strtotime($duty_schedule['shift_start']));
                                } elseif ($duty_schedule['shift_end']) {
                                    echo 'Until ' . date('h:i A', strtotime($duty_schedule['shift_end']));
                                }
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Duty Status Banner -->
                <div class="mb-6 p-4 bg-gradient-to-r from-green-50 to-emerald-50 border-2 border-green-200 rounded-xl">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-calendar-check text-white text-xl"></i>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-green-900">You are on duty today!</h3>
                            <p class="text-green-700 text-sm">
                                <?php if ($duty_schedule['shift_start'] || $duty_schedule['shift_end']): ?>
                                    Shift: 
                                    <?php 
                                    if ($duty_schedule['shift_start'] && $duty_schedule['shift_end']) {
                                        echo date('h:i A', strtotime($duty_schedule['shift_start'])) . ' - ' . date('h:i A', strtotime($duty_schedule['shift_end']));
                                    } elseif ($duty_schedule['shift_start']) {
                                        echo 'From ' . date('h:i A', strtotime($duty_schedule['shift_start']));
                                    } elseif ($duty_schedule['shift_end']) {
                                        echo 'Until ' . date('h:i A', strtotime($duty_schedule['shift_end']));
                                    }
                                    ?>
                                <?php else: ?>
                                    Full day duty
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="space-y-6">
                    <?php foreach ($approvedRequests as $req): ?>
                        <?php
                        // Get available batches for this medicine (FEFO - oldest expiry first)
                        $batchesStmt = db()->prepare('
                            SELECT id, batch_code, quantity_available, expiry_date, received_at
                            FROM medicine_batches
                            WHERE medicine_id = ? AND quantity_available > 0 AND expiry_date > CURDATE()
                            ORDER BY expiry_date ASC, id ASC
                        ');
                        $batchesStmt->execute([$req['medicine_id']]);
                        $batches = $batchesStmt->fetchAll();
                        ?>
                        
                        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-start gap-4">
                                    <?php if ($req['medicine_image_path']): ?>
                                        <img src="<?php echo htmlspecialchars(base_url($req['medicine_image_path'])); ?>" 
                                             alt="<?php echo htmlspecialchars($req['medicine_name']); ?>" 
                                             class="w-20 h-20 object-cover rounded-lg">
                                    <?php else: ?>
                                        <div class="w-20 h-20 bg-gradient-to-br from-blue-100 to-purple-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-pills text-3xl text-blue-600"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($req['medicine_name']); ?></h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            Request ID: #<?php echo $req['id']; ?> · 
                                            Requested by: <?php echo htmlspecialchars($req['resident_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <?php if ($req['requested_for'] === 'family'): ?>
                                                For: <?php echo htmlspecialchars($req['patient_name']); ?> 
                                                (<?php echo htmlspecialchars($req['relationship']); ?>)
                                            <?php else: ?>
                                                For: Self
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            Approved by: <?php echo htmlspecialchars($req['approved_by_name'] ?? 'N/A'); ?> 
                                            on <?php echo date('M d, Y h:i A', strtotime($req['approved_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                                    Approved
                                </span>
                            </div>
                            
                            <?php if (empty($batches)): ?>
                                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                    <p class="text-red-800 font-semibold">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        No available batches for this medicine
                                    </p>
                                </div>
                            <?php else: ?>
                                <form method="POST" class="mt-4" onsubmit="return confirmDispense(this);">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    
                                    <div class="mb-4">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                                            Select Batch (FEFO - Oldest Expiry First) <span class="text-red-500">*</span>
                                        </label>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                            <?php foreach ($batches as $batch): ?>
                                                <label class="batch-card cursor-pointer border-2 border-gray-200 rounded-lg p-4 bg-white">
                                                    <input type="radio" 
                                                           name="batch_id" 
                                                           value="<?php echo $batch['id']; ?>" 
                                                           required
                                                           class="batch-radio sr-only"
                                                           data-batch-code="<?php echo htmlspecialchars($batch['batch_code']); ?>"
                                                           data-quantity="<?php echo $batch['quantity_available']; ?>">
                                                    <div class="flex items-start justify-between">
                                                        <div>
                                                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($batch['batch_code']); ?></p>
                                                            <p class="text-sm text-gray-600 mt-1">
                                                                Available: <span class="font-semibold"><?php echo $batch['quantity_available']; ?></span>
                                                            </p>
                                                            <p class="text-xs text-gray-500 mt-1">
                                                                Expiry: <?php echo date('M d, Y', strtotime($batch['expiry_date'])); ?>
                                                            </p>
                                                        </div>
                                                        <i class="fas fa-circle text-gray-300 mt-1 batch-icon"></i>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <label for="quantity_<?php echo $req['id']; ?>" class="block text-sm font-semibold text-gray-700 mb-2">
                                                Quantity to Release <span class="text-red-500">*</span>
                                            </label>
                                            <input type="number" 
                                                   id="quantity_<?php echo $req['id']; ?>" 
                                                   name="quantity_released" 
                                                   min="1" 
                                                   value="1" 
                                                   required
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        </div>
                                        
                                        <div>
                                            <label for="notes_<?php echo $req['id']; ?>" class="block text-sm font-semibold text-gray-700 mb-2">
                                                Dispensing Notes (Optional)
                                            </label>
                                            <input type="text" 
                                                   id="notes_<?php echo $req['id']; ?>" 
                                                   name="dispensing_notes" 
                                                   placeholder="Additional notes..."
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" 
                                            class="w-full md:w-auto px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors shadow-lg hover:shadow-xl">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        Dispense Medicine
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Handle batch selection
        document.querySelectorAll('.batch-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                const form = this.closest('form');
                
                // Reset all cards in this form
                form.querySelectorAll('.batch-card').forEach(card => {
                    card.classList.remove('selected');
                    const icon = card.querySelector('.batch-icon');
                    icon.classList.remove('fa-check-circle', 'text-blue-600');
                    icon.classList.add('fa-circle', 'text-gray-300');
                });
                
                // Update selected card
                if (this.checked) {
                    const card = this.closest('.batch-card');
                    card.classList.add('selected');
                    
                    const icon = card.querySelector('.batch-icon');
                    icon.classList.remove('fa-circle', 'text-gray-300');
                    icon.classList.add('fa-check-circle', 'text-blue-600');
                    
                    const quantity = parseInt(this.dataset.quantity);
                    const quantityInput = form.querySelector('input[name="quantity_released"]');
                    if (quantityInput) {
                        quantityInput.max = quantity;
                        if (parseInt(quantityInput.value) > quantity) {
                            quantityInput.value = quantity;
                        }
                    }
                }
            });
        });
        
        function confirmDispense(form) {
            const batchRadio = form.querySelector('input[name="batch_id"]:checked');
            const quantity = form.querySelector('input[name="quantity_released"]').value;
            
            if (!batchRadio) {
                Swal.fire({
                    icon: 'error',
                    title: 'No Batch Selected',
                    text: 'Please select a batch to dispense from.'
                });
                return false;
            }
            
            const batchCode = batchRadio.dataset.batchCode;
            const maxQuantity = parseInt(batchRadio.dataset.quantity);
            
            if (parseInt(quantity) > maxQuantity) {
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Quantity',
                    text: `Only ${maxQuantity} units available in this batch.`
                });
                return false;
            }
            
            return Swal.fire({
                title: 'Confirm Dispensing',
                html: `Dispense <strong>${quantity}</strong> unit(s) from batch <strong>${batchCode}</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Dispense',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                return result.isConfirmed;
            });
        }
    </script>
</body>
</html>

