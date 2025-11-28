<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

$user = current_user();

// --- Helper Functions ---

function get_inventory_stats() {
    $pdo = db();
    return [
        'total_items' => $pdo->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn(),
        'low_stock' => $pdo->query("
            SELECT COUNT(*) FROM medicines m 
            LEFT JOIN (SELECT medicine_id, SUM(quantity_available) as total FROM medicine_batches GROUP BY medicine_id) s ON m.id = s.medicine_id 
            WHERE m.is_active = 1 AND COALESCE(s.total, 0) <= COALESCE(m.minimum_stock_level, 10) AND COALESCE(s.total, 0) > 0
        ")->fetchColumn(),
        'out_of_stock' => $pdo->query("
            SELECT COUNT(*) FROM medicines m 
            LEFT JOIN (SELECT medicine_id, SUM(quantity_available) as total FROM medicine_batches GROUP BY medicine_id) s ON m.id = s.medicine_id 
            WHERE m.is_active = 1 AND COALESCE(s.total, 0) = 0
        ")->fetchColumn(),
        'expired_batches' => $pdo->query("SELECT COUNT(*) FROM medicine_batches WHERE expiry_date < CURDATE() AND quantity_available > 0")->fetchColumn()
    ];
}

function get_inventory_items($search = '', $filter = 'all') {
    $pdo = db();
    $sql = "
        SELECT 
            m.id, m.name, m.image_path, m.category_id, c.name as category_name,
            COALESCE(m.minimum_stock_level, 10) as min_level,
            COALESCE(s.total_stock, 0) as current_stock,
            COALESCE(s.earliest_expiry, NULL) as next_expiry,
            COALESCE(s.batch_count, 0) as batch_count,
            COALESCE(last_trans.last_date, NULL) as last_transaction_date
        FROM medicines m
        LEFT JOIN categories c ON m.category_id = c.id
        LEFT JOIN (
            SELECT 
                medicine_id, 
                SUM(quantity_available) as total_stock, 
                MIN(CASE WHEN expiry_date > CURDATE() THEN expiry_date END) as earliest_expiry,
                COUNT(id) as batch_count
            FROM medicine_batches 
            WHERE quantity_available > 0
            GROUP BY medicine_id
        ) s ON m.id = s.medicine_id
        LEFT JOIN (
            SELECT medicine_id, MAX(created_at) as last_date 
            FROM inventory_transactions 
            GROUP BY medicine_id
        ) last_trans ON m.id = last_trans.medicine_id
        WHERE m.is_active = 1
    ";

    $params = [];
    if ($search) {
        $sql .= " AND (m.name LIKE ? OR c.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter === 'low_stock') {
        $sql .= " AND COALESCE(s.total_stock, 0) <= COALESCE(m.minimum_stock_level, 10) AND COALESCE(s.total_stock, 0) > 0";
    } elseif ($filter === 'out_of_stock') {
        $sql .= " AND COALESCE(s.total_stock, 0) = 0";
    } elseif ($filter === 'expiring_soon') {
        $sql .= " AND s.earliest_expiry <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
    }

    $sql .= " ORDER BY m.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Handle Actions ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'adjust_stock') {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            $medicine_id = $_POST['medicine_id'];
            $type = $_POST['adjustment_type']; // 'add' or 'remove'
            $quantity = (int)$_POST['quantity'];
            $reason = $_POST['reason'];
            $expiry_date = $_POST['expiry_date'] ?? null;
            
            if ($type === 'add') {
                // Create new batch
                $batch_code = 'ADJ-' . time();
                $stmt = $pdo->prepare("INSERT INTO medicine_batches (medicine_id, batch_code, quantity, quantity_available, expiry_date, received_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$medicine_id, $batch_code, $quantity, $quantity, $expiry_date]);
                $batch_id = $pdo->lastInsertId();
                
                // Log transaction
                $stmt = $pdo->prepare("INSERT INTO inventory_transactions (medicine_id, batch_id, transaction_type, quantity, reference_type, notes, created_by) VALUES (?, ?, 'IN', ?, 'ADJUSTMENT', ?, ?)");
                $stmt->execute([$medicine_id, $batch_id, $quantity, $reason, $user['id']]);
                
            } elseif ($type === 'remove') {
                // FIFO Removal
                $stmt = $pdo->prepare("SELECT id, quantity_available FROM medicine_batches WHERE medicine_id = ? AND quantity_available > 0 ORDER BY expiry_date ASC");
                $stmt->execute([$medicine_id]);
                $batches = $stmt->fetchAll();
                
                $remaining = $quantity;
                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;
                    
                    $take = min($remaining, $batch['quantity_available']);
                    $stmt = $pdo->prepare("UPDATE medicine_batches SET quantity_available = quantity_available - ? WHERE id = ?");
                    $stmt->execute([$take, $batch['id']]);
                    
                    // Log transaction
                    $stmt = $pdo->prepare("INSERT INTO inventory_transactions (medicine_id, batch_id, transaction_type, quantity, reference_type, notes, created_by) VALUES (?, ?, 'OUT', ?, 'ADJUSTMENT', ?, ?)");
                    $stmt->execute([$medicine_id, $batch['id'], -$take, $reason, $user['id']]);
                    
                    $remaining -= $take;
                }
            }
            
            $pdo->commit();
            set_flash('Stock adjusted successfully.', 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('Error adjusting stock: ' . $e->getMessage(), 'error');
        }
        redirect_to('super_admin/inventory.php');
    }
}

$stats = get_inventory_stats();
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$inventory = get_inventory_items($search, $filter);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management · MediTrack</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
<body class="bg-gray-50 text-gray-900 font-sans antialiased">
    
    <?php render_super_admin_sidebar(['current_page' => 'inventory.php', 'user_data' => $user]); ?>

    <main class="main-content">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <h1 class="text-2xl font-bold text-gray-900">Inventory Management</h1>
                </div>
                <div class="flex items-center gap-3">
                    <a href="reports_hub.php" class="flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium shadow-sm">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
                    </a>
                </div>
            </div>
        </header>

        <div class="p-8">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                        <i class="fas fa-boxes text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Items</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_items']); ?></p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-yellow-50 flex items-center justify-center text-yellow-600">
                        <i class="fas fa-exclamation-triangle text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Low Stock</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['low_stock']); ?></p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-red-50 flex items-center justify-center text-red-600">
                        <i class="fas fa-times-circle text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Out of Stock</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['out_of_stock']); ?></p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-orange-50 flex items-center justify-center text-orange-600">
                        <i class="fas fa-calendar-times text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500">Expired Batches</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['expired_batches']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-2 overflow-x-auto pb-2 sm:pb-0">
                        <a href="?filter=all" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $filter === 'all' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">All Items</a>
                        <a href="?filter=low_stock" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $filter === 'low_stock' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">Low Stock</a>
                        <a href="?filter=out_of_stock" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $filter === 'out_of_stock' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">Out of Stock</a>
                        <a href="?filter=expiring_soon" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $filter === 'expiring_soon' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">Expiring Soon</a>
                    </div>
                    <form class="relative w-full sm:w-72">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 z-10"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search inventory..." class="w-full pl-12 pr-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition shadow-sm" style="padding-left: 3rem;">
                    </form>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-semibold tracking-wider">
                                <th class="px-6 py-4">Medicine</th>
                                <th class="px-6 py-4">Category</th>
                                <th class="px-6 py-4 text-center">Batches</th>
                                <th class="px-6 py-4 text-center">Stock Level</th>
                                <th class="px-6 py-4 text-center">Status</th>
                                <th class="px-6 py-4">Next Expiry</th>
                                <th class="px-6 py-4">Last Updated</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($inventory)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                        <div class="flex flex-col items-center justify-center">
                                            <i class="fas fa-box-open text-4xl mb-3 text-gray-300"></i>
                                            <p>No inventory items found matching your criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory as $item): 
                                    $statusClass = 'bg-green-100 text-green-700';
                                    $statusText = 'In Stock';
                                    if ($item['current_stock'] == 0) {
                                        $statusClass = 'bg-red-100 text-red-700';
                                        $statusText = 'Out of Stock';
                                    } elseif ($item['current_stock'] <= $item['min_level']) {
                                        $statusClass = 'bg-yellow-100 text-yellow-700';
                                        $statusText = 'Low Stock';
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 transition group">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0 overflow-hidden border border-gray-200">
                                                <?php if ($item['image_path']): ?>
                                                    <img src="<?php echo htmlspecialchars(base_url($item['image_path'])); ?>" alt="" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <div class="w-full h-full flex items-center justify-center text-gray-400"><i class="fas fa-pills"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></p>
                                                <p class="text-xs text-gray-500">ID: #<?php echo $item['id']; ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td class="px-6 py-4 text-center text-sm text-gray-600">
                                        <span class="px-2 py-1 bg-gray-100 rounded-md text-xs font-medium"><?php echo number_format($item['batch_count']); ?> batches</span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="font-bold text-gray-900"><?php echo number_format((int)$item['current_stock']); ?></span>
                                        <span class="text-xs text-gray-400 block">Min: <?php echo $item['min_level']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?php 
                                        if ($item['next_expiry']) {
                                            $date = new DateTime($item['next_expiry']);
                                            $now = new DateTime();
                                            $diff = $now->diff($date);
                                            $color = 'text-gray-600';
                                            if ($date < $now) $color = 'text-red-600 font-bold';
                                            elseif ($diff->days < 90) $color = 'text-orange-600 font-medium';
                                            
                                            echo "<span class='$color'>" . $date->format('M d, Y') . "</span>";
                                        } else {
                                            echo '<span class="text-gray-400">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php echo $item['last_transaction_date'] ? date('M d, Y', strtotime($item['last_transaction_date'])) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2 transition-opacity">
                                            <button onclick="viewHistory(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="View History">
                                                <i class="fas fa-history"></i>
                                            </button>
                                            <button onclick="adjustStock(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')" class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg transition" title="Adjust Stock">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination (Simple for now) -->
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
                    <p class="text-sm text-gray-500">Showing <?php echo count($inventory); ?> items</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Adjustment Modal -->
    <div id="adjustmentModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 transform transition-all scale-100">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">Adjust Stock</h3>
                <button onclick="closeAdjustmentModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="medicine_id" id="adj_medicine_id">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Medicine</label>
                    <input type="text" id="adj_medicine_name" class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-500" readonly>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Action</label>
                        <select name="adjustment_type" id="adj_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" onchange="toggleExpiryField()">
                            <option value="add">Add Stock (+)</option>
                            <option value="remove">Remove Stock (-)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" name="quantity" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                
                <div id="expiryField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason / Notes</label>
                    <textarea name="reason" rows="2" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" placeholder="e.g. New delivery, Damaged, Expired..."></textarea>
                </div>
                
                <div class="pt-4">
                    <button type="submit" class="w-full py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium shadow-md transition">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl mx-4 transform transition-all scale-100 flex flex-col max-h-[90vh]">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-2xl">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Dispensing History</h3>
                    <p id="hist_medicine_name" class="text-sm text-gray-500">Loading...</p>
                </div>
                <button onclick="closeHistoryModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="overflow-auto flex-1 p-0">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Resident</th>
                            <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-center">Qty</th>
                            <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Dispensed By</th>
                            <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody" class="divide-y divide-gray-100">
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i> Loading history...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <div class="p-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl flex justify-end">
                <button onclick="closeHistoryModal()" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium shadow-sm transition">Close</button>
            </div>
        </div>
    </div>

    <script>
        function viewHistory(id, name) {
            document.getElementById('hist_medicine_name').textContent = name;
            document.getElementById('historyModal').classList.remove('hidden');
            document.getElementById('historyModal').classList.add('flex');
            
            const tbody = document.getElementById('historyTableBody');
            tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i> Loading history...</td></tr>';
            
            fetch(`get_medicine_history.php?id=${id}`)
                .then(response => response.text())
                .then(html => {
                    tbody.innerHTML = html;
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-4 text-center text-red-500">Failed to load history.</td></tr>';
                });
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').classList.add('hidden');
            document.getElementById('historyModal').classList.remove('flex');
        }

        function adjustStock(id, name) {
            document.getElementById('adj_medicine_id').value = id;
            document.getElementById('adj_medicine_name').value = name;
            document.getElementById('adjustmentModal').classList.remove('hidden');
            document.getElementById('adjustmentModal').classList.add('flex');
        }

        function closeAdjustmentModal() {
            document.getElementById('adjustmentModal').classList.add('hidden');
            document.getElementById('adjustmentModal').classList.remove('flex');
        }

        function toggleExpiryField() {
            const type = document.getElementById('adj_type').value;
            const field = document.getElementById('expiryField');
            if (type === 'add') {
                field.style.display = 'block';
                field.querySelector('input').required = true;
            } else {
                field.style.display = 'none';
                field.querySelector('input').required = false;
            }
        }
        
        // Initialize state
        toggleExpiryField();
        
        // Close modal on outside click
        document.getElementById('adjustmentModal').addEventListener('click', function(e) {
            if (e.target === this) closeAdjustmentModal();
        });
        
        document.getElementById('historyModal').addEventListener('click', function(e) {
            if (e.target === this) closeHistoryModal();
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>
