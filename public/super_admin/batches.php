<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

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

$medicines = db()->query('SELECT id, name FROM medicines WHERE is_active=1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'delete') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id > 0) {
            try {
                $stmt = db()->prepare('DELETE FROM medicine_batches WHERE id = ?');
                $stmt->execute([$batch_id]);
                set_flash('Batch deleted successfully', 'success');
            } catch (Throwable $e) {
                set_flash('Failed to delete batch: ' . $e->getMessage(), 'error');
            }
        }
        redirect_to('super_admin/batches.php');
    }

    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $batch_code = trim($_POST['batch_code'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $expiry_date = $_POST['expiry_date'] ?? '';
    $received_at = $_POST['received_at'] ?? date('Y-m-d');
    $today = date('Y-m-d');

    if ($action === 'add' || $action === 'update') {
        if ($medicine_id <= 0) { set_flash('Select a medicine','error'); redirect_to('super_admin/batches.php'); }
        if ($batch_code === '') { set_flash('Batch code is required','error'); redirect_to('super_admin/batches.php'); }
        if ($quantity <= 0) { set_flash('Quantity must be greater than zero','error'); redirect_to('super_admin/batches.php'); }
        if ($expiry_date === '') { set_flash('Expiry date is required','error'); redirect_to('super_admin/batches.php'); }
    }

    if ($action === 'add') {
        $stmt = db()->prepare('INSERT INTO medicine_batches (medicine_id, batch_code, quantity, quantity_available, expiry_date, received_at) VALUES (?,?,?,?,?,?)');
        try { 
            $stmt->execute([$medicine_id, $batch_code, $quantity, $quantity, $expiry_date, $received_at]); 
            set_flash('Batch added successfully','success'); 
        } catch (Throwable $e) { 
            set_flash('Failed to add batch: ' . $e->getMessage(),'error'); 
        }
    } elseif ($action === 'update') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id > 0) {
            try {
                $old_batch = db()->prepare('SELECT quantity, quantity_available FROM medicine_batches WHERE id = ?');
                $old_batch->execute([$batch_id]);
                $current = $old_batch->fetch();
                
                if ($current) {
                    $quantity_diff = $quantity - $current['quantity'];
                    $new_available = $current['quantity_available'] + $quantity_diff;
                    
                    if ($new_available < 0) {
                        set_flash('Cannot reduce quantity below dispensed amount', 'error');
                    } else {
                        $stmt = db()->prepare('UPDATE medicine_batches SET medicine_id=?, batch_code=?, quantity=?, quantity_available=?, expiry_date=?, received_at=? WHERE id=?');
                        $stmt->execute([$medicine_id, $batch_code, $quantity, $new_available, $expiry_date, $received_at, $batch_id]);
                        set_flash('Batch updated successfully', 'success');
                    }
                }
            } catch (Throwable $e) {
                set_flash('Failed to update batch: ' . $e->getMessage(), 'error');
            }
        }
    }
    redirect_to('super_admin/batches.php');
}

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

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

$batches = db()->query('SELECT b.id, b.medicine_id, m.name AS medicine, m.image_path, b.batch_code, b.quantity, b.quantity_available, b.expiry_date, b.received_at FROM medicine_batches b JOIN medicines m ON m.id=b.medicine_id ORDER BY b.expiry_date ASC')->fetchAll();

// Calculate statistics
$total_batches = count($batches);
$expired_batches = 0;
$expiring_soon_batches = 0;
$low_stock_batches = 0;
$good_batches = 0;

foreach ($batches as $batch) {
    $expiry_date = new DateTime($batch['expiry_date']);
    $today = new DateTime();
    $days_until_expiry = $today->diff($expiry_date)->days;
    $is_expired = $expiry_date < $today;
    $is_expiring_soon = $days_until_expiry <= 30 && !$is_expired;
    $is_low_stock = (int)$batch['quantity_available'] <= 10 && !$is_expired;
    
    if ($is_expired) {
        $expired_batches++;
    } elseif ($is_expiring_soon) {
        $expiring_soon_batches++;
    } elseif ($is_low_stock) {
        $low_stock_batches++;
    } else {
        $good_batches++;
    }
}
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Batches · Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
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
        
        /* Fix search bar overlapping */
        #searchInput {
            padding-left: 2.75rem !important;
            padding-right: 1rem !important;
        }
        
        .search-icon-wrapper {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure input text doesn't overlap */
        #searchInput::placeholder {
            padding-left: 0;
            padding-right: 0;
        }
    </style>
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
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <!-- Main Content -->
    <main class="main-content">
        <!-- Content -->
        <div class="content-body">
            <!-- Page Title -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Batches</h1>
                <p class="text-sm text-gray-500">Manage medicine batches and expiration</p>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Batches Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-total-batches">0</p>
                            <p class="text-sm text-gray-500">Total</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Medicine Batches</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-blue-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">All inventory batches</span>
                        </div>
                    </div>
                </div>

                <!-- Good Batches Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-good-batches">0</p>
                            <p class="text-sm text-gray-500">Good</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Healthy Batches</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">No issues detected</span>
                        </div>
                    </div>
                </div>

                <!-- Expiring Soon Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.3s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-orange-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-expiring-batches">0</p>
                            <p class="text-sm text-gray-500">Expiring</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Expiring Soon</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-orange-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Within 30 days</span>
                        </div>
                    </div>
                </div>

                <!-- Expired Batches Card -->
                <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.4s">
                    <div class="flex items-center justify-between mb-4">
                        <div class="relative">
                            <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-red-400 rounded-full animate-pulse"></div>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-gray-900" id="stat-expired-batches">0</p>
                            <p class="text-sm text-gray-500">Expired</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-gray-700">Expired Batches</p>
                        <div class="flex items-center space-x-2">
                            <div class="w-2 h-2 bg-red-400 rounded-full"></div>
                            <span class="text-xs text-gray-500">Requires attention</span>
                        </div>
                    </div>
                </div>
            </div>
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
            <!-- Add Batch Button -->
            <div class="flex justify-end mb-8">
                <button onclick="openAddBatchModal()" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 animate-fade-in-up">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Add Batch
                            </button>
                        </div>

            <!-- Search and Filter -->
            <div class="batch-controls-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg mb-8">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                    <!-- Search -->
                    <div class="flex-1 max-w-md">
                        <div class="relative">
                            <input type="text" id="searchInput" placeholder="Search batches..." 
                                   class="w-full py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                            <div class="search-icon-wrapper">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2">
                        <button class="filter-chip active px-4 py-2 rounded-full text-sm font-medium transition-all duration-200" data-filter="all">
                            All
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-full text-sm font-medium transition-all duration-200" data-filter="good">
                            Good
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-full text-sm font-medium transition-all duration-200" data-filter="expiring">
                            Expiring Soon
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-full text-sm font-medium transition-all duration-200" data-filter="expired">
                            Expired
                        </button>
                        <button class="filter-chip px-4 py-2 rounded-full text-sm font-medium transition-all duration-200" data-filter="low-stock">
                            Low Stock
                        </button>
                    </div>
                </div>
            </div>

            <!-- Batch Cards -->
            <div class="batch-catalog-card hover-lift animate-fade-in-up p-8 rounded-2xl shadow-lg">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900">Batch Inventory</h3>
                        <p class="text-gray-600">All medicine batches in your inventory</p>
                    </div>
                </div>

                <?php if (empty($batches)): ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <h4 class="text-xl font-semibold text-gray-900 mb-2">No batches yet</h4>
                        <p class="text-gray-600">Start by adding your first medicine batch to the inventory.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="batchesGrid">
                        <?php foreach ($batches as $index => $b): 
                                    $expiry_date = new DateTime($b['expiry_date']);
                                    $today = new DateTime();
                                    $days_until_expiry = $today->diff($expiry_date)->days;
                                    $is_expired = $expiry_date < $today;
                                    $is_expiring_soon = $days_until_expiry <= 30 && !$is_expired;
                            $is_low_stock = (int)$b['quantity_available'] <= 10 && !$is_expired;
                            $stock_percentage = (int)$b['quantity'] > 0 ? ((int)$b['quantity_available'] / (int)$b['quantity']) * 100 : 0;
                            
                            // Determine status
                            $status = 'good';
                            if ($is_expired) $status = 'expired';
                            elseif ($is_expiring_soon) $status = 'expiring';
                            elseif ($is_low_stock) $status = 'low-stock';
                        ?>
                            <div class="batch-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg border border-gray-100 hover:border-blue-200 transition-all duration-300" 
                                 data-medicine="<?php echo strtolower(htmlspecialchars($b['medicine'])); ?>"
                                 data-batch="<?php echo strtolower(htmlspecialchars($b['batch_code'])); ?>"
                                 data-status="<?php echo $status; ?>"
                                 data-batch-id="<?php echo $b['id']; ?>"
                                 data-medicine-id="<?php echo $b['medicine_id']; ?>"
                                 data-batch-code="<?php echo htmlspecialchars($b['batch_code']); ?>"
                                 data-quantity="<?php echo $b['quantity']; ?>"
                                 data-expiry-date="<?php echo $b['expiry_date']; ?>"
                                 data-received-at="<?php echo $b['received_at']; ?>"
                                 style="animation-delay: <?php echo $index * 0.1; ?>s">
                                
                                <!-- Batch Header -->
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <?php 
                                        $image_url = '';
                                        if (!empty($b['image_path'])) {
                                            $image_url = base_url($b['image_path']);
                                        }
                                        ?>
                                        <?php if (!empty($image_url)): ?>
                                            <!-- Simplified Image Display -->
                                            <div class="flex-shrink-0">
                                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                                     alt="<?php echo htmlspecialchars($b['medicine']); ?>"
                                                     class="w-10 h-10 rounded-xl border border-gray-200"
                                                     style="display: block; object-fit: cover;"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <!-- Fallback -->
                                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center text-white text-xs font-bold" style="display: none;">
                                                    <?php echo strtoupper(substr($b['medicine'], 0, 2)); ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                <?php echo strtoupper(substr($b['medicine'], 0, 2)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-mono text-sm text-gray-600"><?php echo htmlspecialchars($b['batch_code']); ?></div>
                                            <div class="text-xs text-gray-500">Batch Code</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Status Badge -->
                                    <div class="status-indicator">
                                        <?php if ($is_expired): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                                Expired
                                            </span>
                                        <?php elseif ($is_expiring_soon): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Expiring Soon
                                            </span>
                                        <?php elseif ($is_low_stock): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 border border-yellow-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                                </svg>
                                                Low Stock
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Good
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Medicine Info -->
                                <div class="mb-4">
                                    <h4 class="text-lg font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($b['medicine']); ?></h4>
                                    <div class="flex items-center space-x-2 text-sm text-gray-500">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span>Received <?php echo date('M j, Y', strtotime($b['received_at'])); ?></span>
                                    </div>
                                </div>

                                <!-- Stock Information -->
                                <div class="space-y-3 mb-4">
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-700">Stock Available</p>
                                                <p class="text-lg font-bold <?php echo (int)$b['quantity_available'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                                    <?php echo (int)$b['quantity_available']; ?> / <?php echo (int)$b['quantity']; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="w-12 h-12 relative">
                                                <svg class="w-12 h-12 transform -rotate-90" viewBox="0 0 36 36">
                                                    <path class="text-gray-200" stroke="currentColor" stroke-width="3" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                                                    <path class="<?php echo $stock_percentage > 50 ? 'text-green-500' : ($stock_percentage > 25 ? 'text-orange-500' : 'text-red-500'); ?>" stroke="currentColor" stroke-width="3" stroke-dasharray="<?php echo $stock_percentage; ?>, 100" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                                                </svg>
                                                <div class="absolute inset-0 flex items-center justify-center">
                                                    <span class="text-xs font-bold <?php echo $stock_percentage > 50 ? 'text-green-600' : ($stock_percentage > 25 ? 'text-orange-600' : 'text-red-600'); ?>"><?php echo round($stock_percentage); ?>%</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between p-3 <?php echo $is_expired ? 'bg-red-50 border border-red-200' : ($is_expiring_soon ? 'bg-orange-50 border border-orange-200' : 'bg-gray-50 border border-gray-200'); ?> rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 <?php echo $is_expired ? 'bg-red-500' : ($is_expiring_soon ? 'bg-orange-500' : 'bg-gray-500'); ?> rounded-lg flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium <?php echo $is_expired ? 'text-red-700' : ($is_expiring_soon ? 'text-orange-700' : 'text-gray-700'); ?>">Expires</p>
                                                <p class="text-lg font-bold <?php echo $is_expired ? 'text-red-600' : ($is_expiring_soon ? 'text-orange-600' : 'text-gray-600'); ?>">
                                                <?php echo date('M j, Y', strtotime($b['expiry_date'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <?php if ($is_expired): ?>
                                                <p class="text-sm font-medium text-red-600">Expired</p>
                                                <p class="text-xs text-red-500"><?php echo abs($days_until_expiry); ?> days ago</p>
                                            <?php else: ?>
                                                <p class="text-sm font-medium <?php echo $is_expiring_soon ? 'text-orange-600' : 'text-gray-600'; ?>"><?php echo $days_until_expiry; ?> days</p>
                                                <p class="text-xs <?php echo $is_expiring_soon ? 'text-orange-500' : 'text-gray-500'; ?>">remaining</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center space-x-2">
                                    <button onclick="editBatch(this)" class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-gradient-to-r from-blue-50 to-blue-100 text-blue-700 font-medium rounded-lg hover:from-blue-100 hover:to-blue-200 transition-all duration-200">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit
                                    </button>
                                    <button onclick="deleteBatch(<?php echo $b['id']; ?>)" class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-gradient-to-r from-red-50 to-red-100 text-red-700 font-medium rounded-lg hover:from-red-100 hover:to-red-200 transition-all duration-200">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                        Delete
                                    </button>
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
                        <h4 class="text-xl font-semibold text-gray-900 mb-2">No batches found</h4>
                        <p class="text-gray-600">Try adjusting your search or filter criteria.</p>
                        <button onclick="clearFilters()" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                            Clear Filters
                        </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add/Edit Batch Modal -->
    <div id="addBatchModal" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm opacity-0 invisible transition-all duration-300">
        <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl transform scale-95 transition-all duration-300 m-4" id="modalContent">
            <!-- Header -->
            <div class="px-8 py-6 border-b border-gray-100 flex items-center justify-between bg-gray-50/50 rounded-t-2xl">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/30">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900" id="modalTitle">Add New Batch</h3>
                        <p class="text-sm text-gray-500" id="modalSubtitle">Add a new medicine batch to your inventory</p>
                    </div>
                </div>
                <button onclick="closeAddBatchModal()" class="text-gray-400 hover:text-gray-600 transition-colors p-2 hover:bg-gray-100 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Body -->
            <div class="p-8">
                <form action="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>" method="post" class="space-y-6" id="batchForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="batch_id" id="formBatchId" value="">
                    
                    <!-- Medicine Select -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Medicine</label>
                        <select name="medicine_id" id="medicineId" required 
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-gray-50/50 hover:bg-white">
                            <option value="">Select Medicine</option>
                            <?php foreach ($medicines as $med): ?>
                                <option value="<?php echo (int)$med['id']; ?>"><?php echo htmlspecialchars($med['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Batch Code -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Batch Code</label>
                            <input name="batch_code" id="batchCode" required 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-gray-50/50 hover:bg-white" 
                                   placeholder="Enter batch code" />
                        </div>

                        <!-- Quantity -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Quantity</label>
                            <input name="quantity" id="quantity" type="number" min="1" required 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-gray-50/50 hover:bg-white" 
                                   placeholder="Enter quantity" />
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Expiry Date -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Expiry Date</label>
                            <input name="expiry_date" id="expiryDate" type="date" required 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-gray-50/50 hover:bg-white" />
                        </div>

                        <!-- Received Date -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Received Date</label>
                            <input name="received_at" id="receivedAt" type="date" value="<?php echo date('Y-m-d'); ?>" 
                                   class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-gray-50/50 hover:bg-white" />
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-6 border-t border-gray-100">
                        <button type="button" onclick="closeAddBatchModal()" 
                                class="px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-blue-500/30">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span id="submitButtonText">Save Batch</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddBatchModal() {
            // Reset form for adding
            document.getElementById('batchForm').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formBatchId').value = '';
            document.getElementById('modalTitle').textContent = 'Add New Batch';
            document.getElementById('modalSubtitle').textContent = 'Add a new medicine batch to your inventory';
            document.getElementById('submitButtonText').textContent = 'Add Batch';
            document.getElementById('receivedAt').value = '<?php echo date('Y-m-d'); ?>';
            
            const modal = document.getElementById('addBatchModal');
            const content = document.getElementById('modalContent');
            if (modal) {
                modal.classList.remove('invisible', 'opacity-0');
                content.classList.remove('scale-95');
                content.classList.add('scale-100');
            }
        }

        function editBatch(button) {
            const card = button.closest('.batch-card');
            if (!card) return;

            // Populate form
            document.getElementById('formAction').value = 'update';
            document.getElementById('formBatchId').value = card.dataset.batchId;
            document.getElementById('medicineId').value = card.dataset.medicineId;
            document.getElementById('batchCode').value = card.dataset.batchCode;
            document.getElementById('quantity').value = card.dataset.quantity;
            document.getElementById('expiryDate').value = card.dataset.expiryDate;
            document.getElementById('receivedAt').value = card.dataset.receivedAt;

            // Update modal UI
            document.getElementById('modalTitle').textContent = 'Edit Batch';
            document.getElementById('modalSubtitle').textContent = 'Update batch details';
            document.getElementById('submitButtonText').textContent = 'Update Batch';

            const modal = document.getElementById('addBatchModal');
            const content = document.getElementById('modalContent');
            if (modal) {
                modal.classList.remove('invisible', 'opacity-0');
                content.classList.remove('scale-95');
                content.classList.add('scale-100');
            }
        }

        function deleteBatch(id) {
            Swal.fire({
                title: 'Delete Batch?',
                text: "Are you sure you want to delete this batch? This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="batch_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function closeAddBatchModal() {
            const modal = document.getElementById('addBatchModal');
            const content = document.getElementById('modalContent');
            if (modal) {
                modal.classList.add('opacity-0');
                content.classList.remove('scale-100');
                content.classList.add('scale-95');
                
                // Wait for transition to finish before hiding
                setTimeout(() => {
                    modal.classList.add('invisible');
                }, 300);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
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

            // Add scale-in animation for modal
            document.querySelectorAll('.animate-scale-in').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'scale(0.9)';
                el.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                
                // Trigger animation after a small delay
                setTimeout(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'scale(1)';
                }, 50);
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

            // Animate statistics
            const stats = ['stat-total-batches', 'stat-good-batches', 'stat-expiring-batches', 'stat-expired-batches'];
            const values = [<?php echo $total_batches; ?>, <?php echo $good_batches; ?>, <?php echo $expiring_soon_batches; ?>, <?php echo $expired_batches; ?>];
            
            stats.forEach((statId, index) => {
                const element = document.getElementById(statId);
                if (element) {
                    let current = 0;
                    const target = values[index];
                    const increment = target / 30;
                    
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        element.textContent = Math.floor(current);
                    }, 50);
                }
            });

            // Animate batch count
            const countElement = document.getElementById('batch-count');
            if (countElement) {
                const targetCount = parseInt(countElement.textContent);
                let currentCount = 0;
                const increment = targetCount / 30;
                
                const timer = setInterval(() => {
                    currentCount += increment;
                    if (currentCount >= targetCount) {
                        currentCount = targetCount;
                        clearInterval(timer);
                    }
                    countElement.textContent = Math.floor(currentCount);
                }, 50);
            }

            // Search and filter functionality
            const searchInput = document.getElementById('searchInput');
            const filterChips = document.querySelectorAll('.filter-chip');
            const batchCards = document.querySelectorAll('.batch-card');
            const batchesGrid = document.getElementById('batchesGrid');
            const noResults = document.getElementById('noResults');

            let currentFilter = 'all';
            let currentSearch = '';

            // Search functionality
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    currentSearch = this.value.toLowerCase();
                    filterBatches();
                });
            }

            // Filter functionality
            filterChips.forEach(chip => {
                chip.addEventListener('click', function() {
                    // Update active state
                    filterChips.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    
                    currentFilter = this.dataset.filter;
                    filterBatches();
                });
            });

            function filterBatches() {
                let visibleCount = 0;
                
                batchCards.forEach(card => {
                    const medicine = card.dataset.medicine;
                    const batch = card.dataset.batch;
                    const status = card.dataset.status;
                    
                    let matchesSearch = true;
                    let matchesFilter = true;
                    
                    // Check search match
                    if (currentSearch) {
                        matchesSearch = medicine.includes(currentSearch) || batch.includes(currentSearch);
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
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (noResults) noResults.classList.remove('hidden');
                    if (batchesGrid) batchesGrid.classList.add('hidden');
                } else {
                    if (noResults) noResults.classList.add('hidden');
                    if (batchesGrid) batchesGrid.classList.remove('hidden');
                }
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
                if (!window.superAdminProfileDropdownClickHandler) {
                    window.superAdminProfileDropdownClickHandler = function(e) {
                        const toggle = document.getElementById('profile-toggle');
                        const menu = document.getElementById('profile-menu');
                        if (menu && toggle && !toggle.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.add('hidden');
                            const arrow = document.getElementById('profile-arrow');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                    };
                    document.addEventListener('click', window.superAdminProfileDropdownClickHandler);
                }
                
                // Close dropdown when pressing Escape
                if (!window.superAdminProfileDropdownKeyHandler) {
                    window.superAdminProfileDropdownKeyHandler = function(e) {
                        if (e.key === 'Escape') {
                            const menu = document.getElementById('profile-menu');
                            const arrow = document.getElementById('profile-arrow');
                            if (menu) menu.classList.add('hidden');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                    };
                    document.addEventListener('keydown', window.superAdminProfileDropdownKeyHandler);
                }
            }

            // Initialize profile dropdown when page loads
            document.addEventListener('DOMContentLoaded', function() {
                initProfileDropdown();
            });

            // Clear filters function
            window.clearFilters = function() {
                if (searchInput) searchInput.value = '';
                currentSearch = '';
                currentFilter = 'all';
                
                filterChips.forEach(c => c.classList.remove('active'));
                filterChips[0].classList.add('active');
                
                filterBatches();
            };

            // Add click outside to close modal
            document.addEventListener('click', function(e) {
                const modal = document.getElementById('addBatchModal');
                if (modal && modal.style.display === 'flex') {
                    const modalContent = modal.querySelector('div > div');
                    if (modalContent && !modalContent.contains(e.target) && e.target === modal) {
                        closeAddBatchModal();
                    }
                }
            });

            // Add escape key to close modal
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('addBatchModal');
                    if (modal && modal.style.display === 'flex') {
                        closeAddBatchModal();
                    }
                }
            });
        });
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>


