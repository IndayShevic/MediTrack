<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';
require_once __DIR__ . '/../../config/mail.php';

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

// Ensure upload directory exists (under public/uploads/medicines)
$uploadRoot = __DIR__ . '/../uploads';
if (!is_dir($uploadRoot)) { @mkdir($uploadRoot, 0777, true); }
$medicineDir = $uploadRoot . '/medicines';
if (!is_dir($medicineDir)) { @mkdir($medicineDir, 0777, true); }

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

// Handle create / update / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('POST data received: ' . print_r($_POST, true));
    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    
    error_log("Action: $action, Name: $name, Description: $description, Category ID: $category_id");

    // Handle optional image upload
    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
                $filename = 'med_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destFs = $medicineDir . '/' . $filename;
                if (@move_uploaded_file($_FILES['image']['tmp_name'], $destFs)) {
                    $imagePath = 'uploads/medicines/' . $filename;
                } else {
                    set_flash('Failed to save uploaded image','error');
                }
            } else {
                set_flash('Unsupported image type. Use jpg, png, webp, or gif.','error');
            }
        } else {
            set_flash('Image upload error code: ' . (int)$_FILES['image']['error'],'error');
        }
    }

    if ($action === 'create' && $name !== '') {
        $stmt = db()->prepare('INSERT INTO medicines(name, description, image_path, category_id) VALUES(?,?,?,?)');
        try {
            $result = $stmt->execute([$name, $description, $imagePath, $category_id > 0 ? $category_id : null]);
            if ($result) {
                // Medicine saved successfully
                $emailSuccess = true;
                $emailError = '';
                
                // Try to email all BHW users (but don't fail if email fails)
                try {
                    $bhws = db()->query("SELECT email, CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')) AS name FROM users WHERE role='bhw'")->fetchAll();
                    $sent = 0; $attempts = 0;
                    
                    // Only try to send emails if there are BHW users
                    if (!empty($bhws)) {
                        foreach ($bhws as $b) {
                            if (!empty($b['email'])) {
                                $attempts++;
                                $html = email_template(
                                    'New medicine available',
                                    'A new medicine has been added to the inventory.',
                                    '<p>Medicine: <b>' . htmlspecialchars($name) . '</b></p><p>Please review batches and availability.</p>',
                                    'Open BHW Panel',
                                    base_url('bhw/dashboard.php')
                                );
                                if (send_email($b['email'], $b['name'] ?? 'BHW', 'New medicine added', $html)) { 
                                    $sent++; 
                                }
                            }
                        }
                        
                        if ($attempts > 0 && $sent === 0) {
                            $emailSuccess = false;
                            $emailError = 'Email sending failed. Check SMTP settings.';
                        } elseif ($attempts > 0 && $sent < $attempts) {
                            $emailSuccess = false;
                            $emailError = 'Some emails failed to send (' . $sent . '/' . $attempts . ' sent).';
                        }
                    } else {
                        // No BHW users to notify, so email is not needed
                        $emailSuccess = true;
                    }
                } catch (Exception $e) {
                    $emailSuccess = false;
                    $emailError = 'Email sending failed: ' . $e->getMessage();
                }
                
                // Show appropriate message based on email success
                if ($emailSuccess) {
                    set_flash('Medicine created successfully!', 'success');
                } else {
                    set_flash('Medicine saved, but ' . $emailError, 'error');
                }
            } else {
                set_flash('Failed to create medicine. Please try again.', 'error');
            }
        } catch (Throwable $e) {
            set_flash('Failed to create medicine: ' . $e->getMessage(), 'error');
        }
        redirect_to('super_admin/medicines.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $name !== '') {
            if ($imagePath) {
                $stmt = db()->prepare('UPDATE medicines SET name=?, description=?, image_path=?, category_id=? WHERE id=?');
                try { $stmt->execute([$name, $description, $imagePath, $category_id > 0 ? $category_id : null, $id]); } catch (Throwable $e) {}
            } else {
                $stmt = db()->prepare('UPDATE medicines SET name=?, description=?, category_id=? WHERE id=?');
                try { $stmt->execute([$name, $description, $category_id > 0 ? $category_id : null, $id]); } catch (Throwable $e) {}
            }
        }
        redirect_to('super_admin/medicines.php');
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $stmt = db()->prepare('DELETE FROM medicines WHERE id=?');
        try { $stmt->execute([$id]); } catch (Throwable $e) {}
    }
    redirect_to('super_admin/medicines.php');
}

$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editingId > 0) {
    $s = db()->prepare('SELECT * FROM medicines WHERE id=?');
    $s->execute([$editingId]);
    $editing = $s->fetch();
}

// Enhanced query with stock, expiry, and status data
$meds = db()->query('
    SELECT 
        m.id,
        m.name,
        m.image_path,
        m.created_at,
        m.is_active,
        c.name as category_name,
        COALESCE(SUM(CASE WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() THEN mb.quantity_available ELSE 0 END), 0) as current_stock,
        MIN(CASE WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() THEN mb.expiry_date END) as earliest_expiry,
        COALESCE(m.minimum_stock_level, 10) as minimum_stock_level
    FROM medicines m
    LEFT JOIN categories c ON m.category_id = c.id
    LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
    GROUP BY m.id, m.name, m.image_path, m.created_at, m.is_active, c.name, m.minimum_stock_level
    ORDER BY m.name ASC
')->fetchAll();
$categories = db()->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

// Setup AJAX capture and redirect if needed
$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Medicines · Super Admin</title>
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
    <main class="main-content">
        <!-- Content -->
        <div class="content-body">
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

            <!-- Toolbar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <!-- Search and Filters -->
                    <div class="flex flex-col sm:flex-row gap-3 flex-1">
                        <!-- Search Bar -->
                        <div class="relative flex-1 max-w-md">
                            <input type="text" id="searchInput" placeholder="Search medicines..." 
                                   class="w-full py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            <div class="search-icon-wrapper">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Category Filter -->
                        <select id="filterCategory" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars(strtolower($cat['name'])); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- Stock Status Filter -->
                        <select id="filterStock" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white">
                            <option value="">All Stock Status</option>
                            <option value="in_stock">In Stock</option>
                            <option value="low_stock">Low Stock</option>
                            <option value="out_of_stock">Out of Stock</option>
                            <option value="expiring_soon">Expiring Soon</option>
                        </select>
                        
                        <!-- Sort By -->
                        <select id="sortBy" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white">
                            <option value="name">Sort by Name</option>
                            <option value="stock">Sort by Stock</option>
                            <option value="expiry">Sort by Expiry</option>
                            <option value="date">Sort by Date Added</option>
                        </select>
                    </div>
                    
                    <!-- Add Medicine Button -->
                    <button onclick="openAddModal()" class="inline-flex items-center px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-all duration-200 shadow-sm hover:shadow-md whitespace-nowrap">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        + Add Medicine
                    </button>
                </div>
            </div>

            <!-- Medicine Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <?php if (empty($meds)): ?>
                    <div class="text-center py-16">
                        <div class="w-20 h-20 bg-gray-100 rounded-xl flex items-center justify-center mx-auto mb-4">
                            <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                            </svg>
                        </div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">No medicines found</h4>
                        <p class="text-gray-600 mb-4">Start by adding your first medicine to the catalog.</p>
                        <button onclick="openAddModal()" class="inline-flex items-center px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-all duration-200">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add Medicine
                        </button>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Medicine</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Category</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Stock</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Expiry Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Added Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="medicinesTableBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($meds as $m): 
                                    $current_stock = (int)($m['current_stock'] ?? 0);
                                    $min_stock = (int)($m['minimum_stock_level'] ?? 10);
                                    $is_low_stock = $current_stock > 0 && $current_stock <= $min_stock;
                                    $is_out_of_stock = $current_stock === 0;
                                    
                                    $earliest_expiry = $m['earliest_expiry'] ?? null;
                                    $is_expiring_soon = false;
                                    if ($earliest_expiry) {
                                        $expiry_date = new DateTime($earliest_expiry);
                                        $today = new DateTime();
                                        $days_until_expiry = $today->diff($expiry_date)->days;
                                        $is_expiring_soon = $days_until_expiry <= 30 && $days_until_expiry > 0;
                                    }
                                    
                                    $stock_status = 'in_stock';
                                    if ($is_out_of_stock) {
                                        $stock_status = 'out_of_stock';
                                    } elseif ($is_low_stock) {
                                        $stock_status = 'low_stock';
                                    } elseif ($is_expiring_soon) {
                                        $stock_status = 'expiring_soon';
                                    }
                                ?>
                                    <tr class="medicine-row hover:bg-gray-50 transition-colors duration-150" 
                                        data-name="<?php echo htmlspecialchars(strtolower($m['name'])); ?>"
                                        data-category="<?php echo htmlspecialchars(strtolower($m['category_name'] ?? '')); ?>"
                                        data-stock="<?php echo $stock_status; ?>"
                                        data-stock-value="<?php echo $current_stock; ?>"
                                        data-expiry="<?php echo $earliest_expiry ? date('Y-m-d', strtotime($earliest_expiry)) : ''; ?>"
                                        data-date="<?php echo date('Y-m-d', strtotime($m['created_at'])); ?>">
                                        <!-- Medicine (Thumbnail + Name) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center space-x-3">
                                                <?php 
                                                $image_url = '';
                                                $image_exists = false;
                                                if (!empty($m['image_path'])) {
                                                    $image_url = base_url($m['image_path']);
                                                    $full_path = __DIR__ . '/../' . $m['image_path'];
                                                    $image_exists = file_exists($full_path);
                                                }
                                                ?>
                                                <?php if (!empty($m['image_path']) && $image_exists): ?>
                                                    <div class="w-10 h-10 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0 border border-gray-200">
                                                        <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                                             alt="<?php echo htmlspecialchars($m['name']); ?>"
                                                             class="w-full h-full object-cover">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                        <?php echo strtoupper(substr($m['name'], 0, 2)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($m['name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Category -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (!empty($m['category_name'])): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                                                    <?php echo htmlspecialchars($m['category_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Stock -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm font-medium text-gray-900"><?php echo number_format($current_stock); ?> units</span>
                                                <?php if ($is_low_stock): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                        ⚠️ Low Stock
                                                    </span>
                                                <?php elseif ($is_out_of_stock): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 border border-red-200">
                                                        Out of Stock
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <!-- Expiry Date -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($earliest_expiry): ?>
                                                <div class="flex items-center space-x-2">
                                                    <span class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($earliest_expiry)); ?></span>
                                                    <?php if ($is_expiring_soon): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                                                            ⚠️ Soon
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Added Date -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            <?php echo date('M j, Y', strtotime($m['created_at'])); ?>
                                        </td>
                                        
                                        <!-- Status -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($m['is_active']): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">
                                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full mr-1.5"></span>
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex items-center justify-end space-x-2">
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php?edit=' . (int)$m['id'])); ?>" 
                                                   class="inline-flex items-center px-3 py-1.5 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors duration-150"
                                                   title="Edit">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php?delete=' . (int)$m['id'])); ?>" 
                                                   onclick="return confirm('Delete this medicine?');" 
                                                   class="inline-flex items-center px-3 py-1.5 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors duration-150"
                                                   title="Delete">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- No Results Message (Hidden by default) -->
                    <div id="noResults" class="hidden text-center py-12">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-1">No medicines found</h3>
                        <p class="text-sm text-gray-600">Try adjusting your search or filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add Medicine Modal -->
            <div id="addModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); z-index: 99999; align-items: center; justify-content: center; padding: 24px; backdrop-filter: blur(4px);">
                <div style="background: white; border-radius: 24px; box-shadow: 0 32px 64px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1); max-width: 700px; width: 100%; max-height: 95vh; overflow-y: auto; border: 1px solid rgba(229, 231, 235, 0.8);">
                    <div style="padding: 40px;">
                        <!-- Enhanced Header -->
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 40px; padding-bottom: 24px; border-bottom: 1px solid #f3f4f6;">
                            <div style="display: flex; align-items: center; gap: 20px;">
                                <div style="width: 56px; height: 56px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border-radius: 16px; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);">
                                    <svg style="width: 28px; height: 28px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 style="font-size: 28px; font-weight: 700; color: #111827; margin: 0 0 8px 0; letter-spacing: -0.025em;">Add New Medicine</h3>
                                    <p style="color: #6b7280; margin: 0; font-size: 16px; line-height: 1.5;">Add a new medicine to your inventory catalog</p>
                                </div>
                            </div>
                            <button onclick="closeAddModal()" style="color: #9ca3af; background: #f9fafb; border: none; cursor: pointer; padding: 12px; border-radius: 12px; transition: all 0.2s ease;" onmouseover="this.style.background='#f3f4f6'; this.style.color='#6b7280';" onmouseout="this.style.background='#f9fafb'; this.style.color='#9ca3af';">
                                <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <form method="post" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 32px;">
                            <input type="hidden" name="action" value="create" />
                            
                            <!-- First Row - Medicine Name and Category -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                                <div style="display: flex; flex-direction: column; gap: 12px;">
                                    <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Medicine Name</label>
                                    <input name="name" required 
                                           style="width: 100%; padding: 16px 20px; border: 2px solid #e5e7eb; border-radius: 16px; font-size: 16px; transition: all 0.2s ease; background: #fafafa;" 
                                           placeholder="Enter medicine name"
                                           onfocus="this.style.borderColor='#3b82f6'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)';"
                                           onblur="this.style.borderColor='#e5e7eb'; this.style.background='#fafafa'; this.style.boxShadow='none';" />
                                </div>
                                
                                <div style="display: flex; flex-direction: column; gap: 12px;">
                                    <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Category</label>
                                    <select name="category_id" 
                                            style="width: 100%; padding: 16px 20px; border: 2px solid #e5e7eb; border-radius: 16px; font-size: 16px; transition: all 0.2s ease; background: #fafafa; cursor: pointer;"
                                            onfocus="this.style.borderColor='#3b82f6'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)';"
                                            onblur="this.style.borderColor='#e5e7eb'; this.style.background='#fafafa'; this.style.boxShadow='none';">
                                        <option value="">Select Category (Optional)</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Medicine Image Field -->
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Medicine Image</label>
                                <input type="file" name="image" accept="image/*" 
                                       style="width: 100%; padding: 16px 20px; border: 2px solid #e5e7eb; border-radius: 16px; font-size: 16px; transition: all 0.2s ease; background: #fafafa; cursor: pointer;"
                                       onfocus="this.style.borderColor='#3b82f6'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)';"
                                       onblur="this.style.borderColor='#e5e7eb'; this.style.background='#fafafa'; this.style.boxShadow='none';" />
                            </div>
                            
                            <!-- Description Field -->
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">Description</label>
                                <textarea name="description" rows="5"
                                          style="width: 100%; padding: 16px 20px; border: 2px solid #e5e7eb; border-radius: 16px; font-size: 16px; transition: all 0.2s ease; background: #fafafa; resize: none; min-height: 120px;" 
                                          placeholder="Enter medicine description"
                                          onfocus="this.style.borderColor='#3b82f6'; this.style.background='white'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)';"
                                          onblur="this.style.borderColor='#e5e7eb'; this.style.background='#fafafa'; this.style.boxShadow='none';"></textarea>
                            </div>
                            
                            <!-- Enhanced Action Buttons -->
                            <div style="display: flex; justify-content: flex-end; gap: 16px; padding-top: 24px; border-top: 1px solid #f3f4f6; margin-top: 8px;">
                                <button type="button" onclick="closeAddModal()" 
                                        style="padding: 16px 32px; border: 2px solid #e5e7eb; color: #6b7280; font-weight: 600; border-radius: 16px; background: white; cursor: pointer; transition: all 0.2s ease; font-size: 16px;"
                                        onmouseover="this.style.borderColor='#d1d5db'; this.style.backgroundColor='#f9fafb'; this.style.color='#374151';"
                                        onmouseout="this.style.borderColor='#e5e7eb'; this.style.backgroundColor='white'; this.style.color='#6b7280';">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        style="padding: 16px 32px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; font-weight: 600; border-radius: 16px; border: none; cursor: pointer; transition: all 0.2s ease; font-size: 16px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); display: flex; align-items: center; gap: 8px;"
                                        onmouseover="this.style.background='linear-gradient(135deg, #2563eb, #1e40af)'; this.style.boxShadow='0 6px 16px rgba(59, 130, 246, 0.4)'; this.style.transform='translateY(-1px)';"
                                        onmouseout="this.style.background='linear-gradient(135deg, #3b82f6, #1d4ed8)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.3)'; this.style.transform='translateY(0)';">
                                    <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    Add Medicine
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($editing): ?>
            <!-- Edit Medicine Modal -->
            <div class="fixed inset-0 z-[99999] flex items-center justify-center p-4" style="background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px);">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[95vh] overflow-y-auto border border-gray-200" style="box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.05);">
                    <div class="p-10">
                        <!-- Enhanced Header -->
                        <div class="flex items-start justify-between mb-10 pb-6 border-b border-gray-100">
                            <div class="flex items-center gap-4">
                                <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg" style="box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);">
                                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-3xl font-bold text-gray-900 mb-2" style="letter-spacing: -0.025em;">Edit Medicine</h3>
                                    <p class="text-gray-600 text-base">Update medicine information</p>
                                </div>
                            </div>
                            <button onclick="closeEditModal()" class="p-2.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition-all duration-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <form method="post" enctype="multipart/form-data" class="space-y-8">
                            <input type="hidden" name="action" value="update" />
                            <input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>" />
                            
                            <!-- First Row - Medicine Name and Category -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-3">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Medicine Name <span class="text-red-500">*</span></label>
                                    <input name="name" value="<?php echo htmlspecialchars($editing['name']); ?>" required 
                                           class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white text-gray-900 placeholder-gray-400" 
                                           placeholder="Enter medicine name"
                                           style="font-size: 16px;" />
                                </div>
                                
                                <div class="space-y-3">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Category</label>
                                    <select name="category_id" class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white text-gray-900 cursor-pointer"
                                            style="font-size: 16px;">
                                        <option value="">Select Category (Optional)</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo (int)$category['id']; ?>" <?php echo ($editing['category_id'] == $category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Image Upload Field - Full Width for Better Spacing -->
                            <div class="space-y-3">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Replace Image</label>
                                <div class="relative">
                                    <input type="file" name="image" accept="image/*" id="editImageInput"
                                           class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white text-gray-900 cursor-pointer file:mr-4 file:py-2.5 file:px-5 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 file:cursor-pointer"
                                           style="font-size: 16px;" />
                                    <p class="mt-2 text-xs text-gray-500">Recommended: JPG, PNG (Max 5MB)</p>
                                </div>
                            </div>
                            
                            <!-- Description Field -->
                            <div class="space-y-3">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" rows="5"
                                          class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 bg-white text-gray-900 placeholder-gray-400 resize-none" 
                                          placeholder="Enter medicine description (optional)"
                                          style="font-size: 16px; min-height: 140px;"><?php echo htmlspecialchars($editing['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex justify-end gap-4 pt-6 border-t border-gray-100">
                                <button type="button" onclick="closeEditModal()" 
                                        class="px-8 py-3.5 border-2 border-gray-300 text-gray-700 font-semibold rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-200"
                                        style="font-size: 16px;">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        class="inline-flex items-center px-8 py-3.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:scale-[1.02]"
                                        style="font-size: 16px;">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <!-- Scripts -->
    <script>
        // Filter and Search Functionality
        function filterMedicines() {
            const searchInput = document.getElementById('searchInput');
            const filterCategory = document.getElementById('filterCategory');
            const filterStock = document.getElementById('filterStock');
            const sortBy = document.getElementById('sortBy');
            const rows = document.querySelectorAll('.medicine-row');
            const noResults = document.getElementById('noResults');
            const tableBody = document.getElementById('medicinesTableBody');
            
            if (!rows.length) return;
            
            const searchTerm = (searchInput?.value || '').toLowerCase();
            const categoryFilter = (filterCategory?.value || '').toLowerCase();
            const stockFilter = (filterStock?.value || '');
            const sortValue = (sortBy?.value || 'name');
            
            let visibleCount = 0;
            const visibleRows = [];
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const category = row.getAttribute('data-category') || '';
                const stock = row.getAttribute('data-stock') || '';
                
                const matchesSearch = !searchTerm || name.includes(searchTerm);
                const matchesCategory = !categoryFilter || category === categoryFilter;
                const matchesStock = !stockFilter || stock === stockFilter;
                
                if (matchesSearch && matchesCategory && matchesStock) {
                    row.style.display = '';
                    visibleCount++;
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            if (visibleCount === 0 && noResults && tableBody) {
                tableBody.style.display = 'none';
                noResults.classList.remove('hidden');
            } else {
                if (noResults) noResults.classList.add('hidden');
                if (tableBody) tableBody.style.display = '';
            }
            
            // Sort visible rows
            if (visibleRows.length > 0) {
                visibleRows.sort((a, b) => {
                    let aVal, bVal;
                    
                    switch(sortValue) {
                        case 'name':
                            aVal = a.getAttribute('data-name') || '';
                            bVal = b.getAttribute('data-name') || '';
                            return aVal.localeCompare(bVal);
                        case 'stock':
                            aVal = parseInt(a.getAttribute('data-stock-value') || '0');
                            bVal = parseInt(b.getAttribute('data-stock-value') || '0');
                            return bVal - aVal; // Descending
                        case 'expiry':
                            aVal = a.getAttribute('data-expiry') || '9999-12-31';
                            bVal = b.getAttribute('data-expiry') || '9999-12-31';
                            return aVal.localeCompare(bVal);
                        case 'date':
                            aVal = a.getAttribute('data-date') || '';
                            bVal = b.getAttribute('data-date') || '';
                            return bVal.localeCompare(aVal); // Descending (newest first)
                        default:
                            return 0;
                    }
                });
                
                // Reorder rows in DOM
                const tbody = visibleRows[0].parentElement;
                visibleRows.forEach(row => {
                    tbody.appendChild(row);
                });
            }
        }
        
        // Initialize filtering
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const filterCategory = document.getElementById('filterCategory');
            const filterStock = document.getElementById('filterStock');
            const sortBy = document.getElementById('sortBy');
            
            if (searchInput) {
                searchInput.addEventListener('input', filterMedicines);
            }
            if (filterCategory) {
                filterCategory.addEventListener('change', filterMedicines);
            }
            if (filterStock) {
                filterStock.addEventListener('change', filterMedicines);
            }
            if (sortBy) {
                sortBy.addEventListener('change', filterMedicines);
            }
            
            // Initial filter
            filterMedicines();
            
            // Initialize profile dropdown and time update
            if (typeof initProfileDropdown === 'function') {
                setTimeout(initProfileDropdown, 100);
            }
            if (typeof updateTime === 'function') {
                updateTime();
                setInterval(updateTime, 1000);
            }
            if (typeof initNightMode === 'function') {
                initNightMode();
            }
        });
        
        function openAddModal() {
            console.log('Opening add modal...');
            const modal = document.getElementById('addModal');
            if (modal) {
                modal.style.display = 'flex';
                console.log('Modal opened successfully');
            } else {
                console.error('Modal element not found!');
            }
        }

        function closeAddModal() {
            const modal = document.getElementById('addModal');
            if (modal) {
                modal.style.display = 'none';
                console.log('Modal closed');
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('addModal');
            if (e.target === modal) {
                closeAddModal();
            }
        });

        function closeEditModal() {
            window.location.href = '<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>';
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

            // Animate medicine count
            const countElement = document.getElementById('medicine-count');
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

            // Add click outside to close modals
            document.addEventListener('click', function(e) {
                const addModal = document.getElementById('addModal');
                const editModal = document.querySelector('.fixed.inset-0:not(#addModal)');
                
                if (addModal && addModal.classList.contains('flex')) {
                    const modalContent = addModal.querySelector('div');
                    if (modalContent && !modalContent.contains(e.target)) {
                        closeAddModal();
                    }
                }
                
                if (editModal) {
                    const modalContent = editModal.querySelector('div');
                    if (modalContent && !modalContent.contains(e.target)) {
                        closeEditModal();
                    }
                }
            });

            // Add escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const addModal = document.getElementById('addModal');
                    const editModal = document.querySelector('.fixed.inset-0:not(#addModal)');
                    
                    if (addModal && addModal.classList.contains('flex')) {
                        closeAddModal();
                    } else if (editModal) {
                        closeEditModal();
                    }
                }
            });
        });
        
        // Time update functionality
        function updateTime() {
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
            
            // Check if elements exist
            if (!toggle || !menu || !arrow) {
                return;
            }
            
            // Remove any existing event listeners
            toggle.onclick = null;
            
            // Simple click handler
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
            
            // Close dropdown when clicking outside (use a single event listener)
            if (!window.profileDropdownClickHandler) {
                window.profileDropdownClickHandler = function(e) {
                    const allToggles = document.querySelectorAll('#profile-toggle');
                    const allMenus = document.querySelectorAll('#profile-menu');
                    
                    allToggles.forEach((toggle, index) => {
                        const menu = allMenus[index];
                        if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.add('hidden');
                            const arrow = toggle.querySelector('#profile-arrow');
                            if (arrow) arrow.classList.remove('rotate-180');
                        }
                    });
                };
                document.addEventListener('click', window.profileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            if (!window.profileDropdownKeyHandler) {
                window.profileDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const allMenus = document.querySelectorAll('#profile-menu');
                        const allArrows = document.querySelectorAll('#profile-arrow');
                        allMenus.forEach(menu => menu.classList.add('hidden'));
                        allArrows.forEach(arrow => arrow.classList.remove('rotate-180'));
                    }
                };
                document.addEventListener('keydown', window.profileDropdownKeyHandler);
            }
        }

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Update time immediately and then every second
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize night mode
            initNightMode();
            
            // Initialize profile dropdown
            initProfileDropdown();
        });
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize profile dropdown
            initProfileDropdown();
            // Logout confirmation is now handled by logout-confirmation.js
        });
    </script>
    </main>
</body>
</html>
<?php
deliver_dashboard_ajax_content($isAjax);


