<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);

$user = current_user();

// Get comprehensive inventory data
try {
    // Total medicines count
    $total_medicines = db()->query('SELECT COUNT(*) as count FROM medicines')->fetch()['count'];
} catch (Exception $e) {
    $total_medicines = 0;
}

try {
    // Low stock medicines (less than 10 total stock)
    $low_stock_count = db()->query('
        SELECT COUNT(*) as count 
        FROM (
            SELECT m.id 
            FROM medicines m 
            LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
            WHERE mb.quantity_available > 0
            GROUP BY m.id 
            HAVING COALESCE(SUM(mb.quantity_available), 0) < 10 AND COALESCE(SUM(mb.quantity_available), 0) > 0
        ) as low_stock_meds
    ')->fetch()['count'];
} catch (Exception $e) {
    $low_stock_count = 0;
}

try {
    // Expiring soon (within 30 days)
    $expiring_soon_count = db()->query('
        SELECT COUNT(DISTINCT m.id) as count 
        FROM medicines m 
        JOIN medicine_batches mb ON m.id = mb.medicine_id 
        WHERE mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND mb.expiry_date > CURDATE() 
        AND mb.quantity_available > 0
    ')->fetch()['count'];
} catch (Exception $e) {
    $expiring_soon_count = 0;
}

try {
    // Today's transactions (requests)
    $today_transactions = db()->query('
        SELECT COUNT(*) as count 
        FROM requests 
        WHERE DATE(created_at) = CURDATE()
    ')->fetch()['count'];
} catch (Exception $e) {
    $today_transactions = 0;
}

// Get detailed inventory summary with enhanced information
try {
    $inventory_summary = db()->query('
        SELECT 
            m.id,
            m.name,
            m.description,
            m.image_path,
            m.minimum_stock_level,
            COALESCE(SUM(mb.quantity_available), 0) as current_stock,
            COUNT(mb.id) as total_batches,
            COUNT(CASE WHEN mb.quantity_available > 0 THEN mb.id END) as active_batches,
            MIN(CASE WHEN mb.quantity_available > 0 THEN mb.expiry_date END) as earliest_expiry,
            MAX(CASE WHEN mb.quantity_available > 0 THEN mb.expiry_date END) as latest_expiry,
            COALESCE(SUM(mb.quantity_received), 0) as total_received,
            COALESCE(SUM(mb.quantity_received - mb.quantity_available), 0) as total_dispensed,
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "Out of Stock"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= COALESCE(m.minimum_stock_level, 10) THEN "Low Stock"
                WHEN MIN(mb.expiry_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "Expiring Soon"
                ELSE "In Stock"
            END as status,
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "text-red-600 bg-red-50"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= COALESCE(m.minimum_stock_level, 10) THEN "text-orange-600 bg-orange-50"
                WHEN MIN(mb.expiry_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "text-yellow-600 bg-yellow-50"
                ELSE "text-green-600 bg-green-50"
            END as status_class
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        GROUP BY m.id, m.name, m.description, m.image_path, m.minimum_stock_level
        ORDER BY 
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN 1
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= COALESCE(m.minimum_stock_level, 10) THEN 2
                WHEN MIN(mb.expiry_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 3
                ELSE 4
            END,
            current_stock ASC, 
            m.name ASC
    ')->fetchAll();
} catch (Exception $e) {
    $inventory_summary = [];
}

// Get recent transactions with more details
try {
    $recent_transactions = db()->query('
        SELECT 
            r.id,
            r.created_at,
            m.name as medicine_name,
            m.image_path as medicine_image,
            CONCAT(IFNULL(res.first_name, ""), " ", IFNULL(res.last_name, "")) as resident_name,
            res.purok_id,
            p.name as purok_name,
            r.status,
            COALESCE(SUM(rf.quantity), 0) as quantity,
            CASE 
                WHEN r.resident_id IS NULL THEN "Walk-in"
                ELSE "Registered"
            END as resident_type
        FROM requests r
        JOIN medicines m ON r.medicine_id = m.id
        LEFT JOIN residents res ON r.resident_id = res.id
        LEFT JOIN puroks p ON res.purok_id = p.id
        LEFT JOIN request_fulfillments rf ON r.id = rf.request_id
        GROUP BY r.id, r.created_at, m.name, m.image_path, res.first_name, res.last_name, res.purok_id, p.name, r.status, r.resident_id
        ORDER BY r.created_at DESC
        LIMIT 15
    ')->fetchAll();
} catch (Exception $e) {
    $recent_transactions = [];
}

// Get low stock medicines details
try {
    $low_stock_medicines = db()->query('
        SELECT 
            m.id,
            m.name,
            m.image_path,
            COALESCE(SUM(mb.quantity_available), 0) as current_stock,
            COUNT(CASE WHEN mb.quantity_available > 0 THEN mb.id END) as active_batches
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        WHERE mb.quantity_available > 0
        GROUP BY m.id, m.name, m.image_path
        HAVING COALESCE(SUM(mb.quantity_available), 0) < 10 AND COALESCE(SUM(mb.quantity_available), 0) > 0
        ORDER BY current_stock ASC
    ')->fetchAll();
} catch (Exception $e) {
    $low_stock_medicines = [];
}

// Get expiring medicines details
try {
    $expiring_medicines = db()->query('
        SELECT 
            m.id,
            m.name,
            m.image_path,
            mb.expiry_date,
            mb.quantity_available,
            DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry
        FROM medicines m
        JOIN medicine_batches mb ON m.id = mb.medicine_id
        WHERE mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND mb.expiry_date > CURDATE() 
        AND mb.quantity_available > 0
        ORDER BY mb.expiry_date ASC
    ')->fetchAll();
} catch (Exception $e) {
    $expiring_medicines = [];
}

// Create inventory_adjustments table if it doesn't exist
try {
    db()->exec('
        CREATE TABLE IF NOT EXISTS inventory_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            adjustment_type ENUM("add", "remove") NOT NULL,
            quantity INT NOT NULL,
            reason TEXT NOT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_medicine_id (medicine_id),
            INDEX idx_created_at (created_at),
            INDEX idx_adjustment_type (adjustment_type)
        )
    ');
} catch (Exception $e) {
    // Table might already exist, continue
}

// Handle inventory adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust') {
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $adjustment_type = $_POST['adjustment_type'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $expiry_date = $_POST['expiry_date'] ?? '';
    
    if ($medicine_id > 0 && $quantity > 0 && !empty($reason) && !empty($expiry_date)) {
        try {
            db()->beginTransaction();
            
            if ($adjustment_type === 'add') {
                // Create a new batch for adding stock
                $stmt = db()->prepare('
                    INSERT INTO medicine_batches (medicine_id, batch_number, quantity_received, quantity_available, expiry_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ');
                
                $batch_number = 'ADJ-' . date('YmdHis') . '-' . $medicine_id;
                $stmt->execute([$medicine_id, $batch_number, $quantity, $quantity, $expiry_date]);
                
                set_flash("Successfully added $quantity units to inventory!", 'success');
            } elseif ($adjustment_type === 'remove') {
                // Remove stock using FEFO (First Expiry, First Out)
                $available_batches = db()->prepare('
                    SELECT id, quantity_available, batch_number 
                    FROM medicine_batches 
                    WHERE medicine_id = ? AND quantity_available > 0 
                    ORDER BY expiry_date ASC
                ');
                $available_batches->execute([$medicine_id]);
                $batches = $available_batches->fetchAll();
                
                $remaining_to_remove = $quantity;
                $removed_batches = [];
                
                foreach ($batches as $batch) {
                    if ($remaining_to_remove <= 0) break;
                    
                    $remove_from_batch = min($remaining_to_remove, $batch['quantity_available']);
                    
                    $update_stmt = db()->prepare('
                        UPDATE medicine_batches 
                        SET quantity_available = quantity_available - ? 
                        WHERE id = ?
                    ');
                    $update_stmt->execute([$remove_from_batch, $batch['id']]);
                    
                    $removed_batches[] = [
                        'batch' => $batch['batch_number'],
                        'quantity' => $remove_from_batch
                    ];
                    
                    $remaining_to_remove -= $remove_from_batch;
                }
                
                if ($remaining_to_remove > 0) {
                    throw new Exception("Cannot remove $remaining_to_remove units - insufficient stock available.");
                }
                
                $batch_details = implode(', ', array_map(function($b) {
                    return $b['batch'] . ' (' . $b['quantity'] . ' units)';
                }, $removed_batches));
                
                set_flash("Successfully removed $quantity units from inventory! Batches: $batch_details", 'success');
            }
            
            // Log the adjustment
            $log_stmt = db()->prepare('
                INSERT INTO inventory_adjustments (medicine_id, adjustment_type, quantity, reason, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $log_stmt->execute([$medicine_id, $adjustment_type, $quantity, $reason, $user['id']]);
            
            db()->commit();
        } catch (Exception $e) {
            db()->rollBack();
            set_flash('Failed to adjust inventory: ' . $e->getMessage(), 'error');
        }
    } else {
        set_flash('Please fill in all required fields.', 'error');
    }
    
    redirect_to('super_admin/inventory.php');
}

// Get medicines for adjustment form
$medicines = db()->query('SELECT id, name FROM medicines ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Inventory Management - MediTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="<?php echo base_url('public/assets/css/design-system.css'); ?>">
    <style>
        .stat-glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        /* Fix main content positioning */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            min-height: 100vh !important;
            position: relative !important;
            z-index: 1 !important;
            background: #f8fafc !important;
        }
        
        .content-header {
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            padding: 2rem !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 2rem !important;
        }
        
        .stat-card {
            background: white !important;
            border: 1px solid #e5e7eb !important;
            transition: all 0.3s ease !important;
        }
        
        .stat-card:hover {
            transform: translateY(-4px) !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1) !important;
            border-color: #d1d5db !important;
        }
        
        .btn-gradient {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            color: white !important;
            border: none !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-gradient:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3) !important;
        }
        
        .ripple {
            position: absolute !important;
            border-radius: 50% !important;
            background: rgba(255, 255, 255, 0.6) !important;
            transform: scale(0) !important;
            animation: ripple-animation 0.6s linear !important;
            pointer-events: none !important;
        }
        
        @keyframes ripple-animation {
            to {
                transform: scale(4) !important;
                opacity: 0 !important;
            }
        }
        
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out !important;
        }
        
        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out !important;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0 !important;
                transform: translateY(30px) !important;
            }
            to {
                opacity: 1 !important;
                transform: translateY(0) !important;
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0 !important;
                transform: translateX(30px) !important;
            }
            to {
                opacity: 1 !important;
                transform: translateX(0) !important;
            }
        }
        
        .hover-lift {
            transition: all 0.3s ease !important;
        }
        
        .hover-lift:hover {
            transform: translateY(-8px) scale(1.02) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1) !important;
        }
        
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            z-index: 1000 !important;
            width: 280px !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            display: flex !important;
            flex-direction: column !important;
        }
        
        .sidebar-brand {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            padding: 1.5rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            color: white !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
            flex-shrink: 0 !important;
        }
        
        .sidebar-nav {
            flex: 1 !important;
            padding: 1rem !important;
            overflow-y: auto !important;
        }
        
        .sidebar-nav a {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            padding: 0.75rem 1rem !important;
            margin-bottom: 0.25rem !important;
            border-radius: 0.75rem !important;
            color: #374151 !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            transition: all 0.2s !important;
        }
        
        .sidebar-nav a:hover {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%) !important;
            color: #1d4ed8 !important;
            transform: translateX(4px) !important;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15) !important;
        }
        
        .sidebar-nav a.active {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%) !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2) !important;
            border-left: 3px solid #3b82f6 !important;
        }
        
        .sidebar-footer {
            padding: 1rem !important;
            border-top: 1px solid rgba(0, 0, 0, 0.05) !important;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
        }
        
        .sidebar-footer a {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.75rem !important;
            padding: 0.75rem 1rem !important;
            color: #6b7280 !important;
            text-decoration: none !important;
            border-radius: 0.75rem !important;
            transition: all 0.2s !important;
            font-weight: 500 !important;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%) !important;
            border: 1px solid rgba(0, 0, 0, 0.05) !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
        }
        
        .sidebar-footer a:hover {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%) !important;
            color: #dc2626 !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15) !important;
            border-color: rgba(220, 38, 38, 0.2) !important;
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
            <a href="<?php echo htmlspecialchars(base_url('super_admin/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                </svg>
                Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/categories.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                </svg>
                Categories
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Batches
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
                Inventory
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Users
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/analytics.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Analytics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Brand Settings
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/locations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Barangays & Puroks
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/email_logs.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Email Logs
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <!-- Logout removed - now accessible via profile dropdown -->
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                <div class="animate-fade-in-up mb-4 lg:mb-0">
                    <div class="flex items-center space-x-3 mb-2">
                        <h1 class="text-2xl lg:text-4xl font-bold bg-gradient-to-r from-gray-900 via-blue-800 to-purple-800 bg-clip-text text-transparent">
                            Inventory Management
                        </h1>
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-gray-600 text-base lg:text-lg">Track medicine stock levels, monitor expiring items, and manage inventory adjustments</p>
                    <div class="flex items-center space-x-2 mt-2">
                        <div class="w-1 h-1 bg-blue-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-purple-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-cyan-400 rounded-full"></div>
                        <span class="text-sm text-gray-500 ml-2">Live inventory dashboard</span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-2 sm:space-y-0 sm:space-x-4 animate-slide-in-right">
                    <div class="text-right stat-glass px-6 py-4 rounded-2xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide font-medium">Total medicines</div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $total_medicines; ?></div>
                        <div class="w-full bg-gray-200 rounded-full h-1 mt-2">
                            <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-1 rounded-full" style="width: <?php echo min(100, ($total_medicines / 50) * 100); ?>%"></div>
                        </div>
                    </div>
                    <div class="text-right stat-glass px-6 py-4 rounded-2xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide font-medium">Low stock items</div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $low_stock_count; ?></div>
                        <div class="w-full bg-gray-200 rounded-full h-1 mt-2">
                            <div class="bg-gradient-to-r from-red-500 to-orange-500 h-1 rounded-full" style="width: <?php echo min(100, ($low_stock_count / 10) * 100); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Container -->
        <div class="px-6 pb-6">
            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $_SESSION['flash_type'] === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'; ?>">
                    <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            <?php endif; ?>

                <!-- Dashboard Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Medicines -->
                    <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-gray-900"><?php echo $total_medicines; ?></p>
                                <p class="text-sm text-gray-500">Total Medicines</p>
                            </div>
                        </div>
                    </div>

                    <!-- Low Stock -->
                    <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-red-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-gray-900"><?php echo $low_stock_count; ?></p>
                                <p class="text-sm text-gray-500">Low Stock</p>
                            </div>
                        </div>
                    </div>

                    <!-- Expiring Soon -->
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
                                <p class="text-3xl font-bold text-gray-900"><?php echo $expiring_soon_count; ?></p>
                                <p class="text-sm text-gray-500">Expiring Soon</p>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Activity -->
                    <div class="stat-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-gray-900"><?php echo $today_transactions; ?></p>
                                <p class="text-sm text-gray-500">Today's Transactions</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Low Stock Alert -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-bold text-gray-900">Low Stock Alert</h2>
                            <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-medium">
                                <?php echo count($low_stock_medicines); ?> items
                            </span>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($low_stock_medicines)): ?>
                                <p class="text-gray-500 text-center py-4">No low stock medicines</p>
                            <?php else: ?>
                                <?php foreach (array_slice($low_stock_medicines, 0, 5) as $medicine): ?>
                                    <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <?php if (!empty($medicine['image_path'])): ?>
                                                <div class="w-8 h-8 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                                    <img src="<?php echo htmlspecialchars(base_url($medicine['image_path'])); ?>" 
                                                         alt="<?php echo htmlspecialchars($medicine['name']); ?>"
                                                         class="w-full h-full object-cover">
                                                </div>
                                            <?php else: ?>
                                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                    <?php echo strtoupper(substr($medicine['name'], 0, 2)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($medicine['name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo (int)$medicine['active_batches']; ?> batches</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-red-600"><?php echo (int)$medicine['current_stock']; ?></div>
                                            <div class="text-xs text-gray-500">units left</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Expiring Soon -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-bold text-gray-900">Expiring Soon</h2>
                            <span class="px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-sm font-medium">
                                <?php echo count($expiring_medicines); ?> batches
                            </span>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($expiring_medicines)): ?>
                                <p class="text-gray-500 text-center py-4">No expiring medicines</p>
                            <?php else: ?>
                                <?php foreach (array_slice($expiring_medicines, 0, 5) as $medicine): ?>
                                    <div class="flex items-center justify-between p-3 bg-orange-50 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <?php if (!empty($medicine['image_path'])): ?>
                                                <div class="w-8 h-8 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                                    <img src="<?php echo htmlspecialchars(base_url($medicine['image_path'])); ?>" 
                                                         alt="<?php echo htmlspecialchars($medicine['name']); ?>"
                                                         class="w-full h-full object-cover">
                                                </div>
                                            <?php else: ?>
                                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                    <?php echo strtoupper(substr($medicine['name'], 0, 2)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($medicine['name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo (int)$medicine['quantity_available']; ?> units</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-orange-600"><?php echo (int)$medicine['days_until_expiry']; ?></div>
                                            <div class="text-xs text-gray-500">days left</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Inventory Summary Table -->
                <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Inventory Summary</h2>
                        <button onclick="openAdjustmentModal()" class="btn-gradient ripple-effect inline-flex items-center px-4 py-2 text-white font-semibold rounded-xl shadow-lg">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Adjust Inventory
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-3 px-4 font-semibold text-gray-700">Medicine</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Current Stock</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Min Level</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Active Batches</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Earliest Expiry</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Total Dispensed</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Status</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory_summary as $medicine): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                                        <td class="py-4 px-4">
                                            <div class="flex items-center space-x-3">
                                                <?php if (!empty($medicine['image_path'])): ?>
                                                    <div class="w-10 h-10 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                                        <img src="<?php echo htmlspecialchars(base_url($medicine['image_path'])); ?>" 
                                                             alt="<?php echo htmlspecialchars($medicine['name']); ?>"
                                                             class="w-full h-full object-cover">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                        <?php echo strtoupper(substr($medicine['name'], 0, 2)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($medicine['name']); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo (int)$medicine['total_batches']; ?> total batches</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center py-4 px-4">
                                            <span class="font-semibold <?php echo $medicine['current_stock'] == 0 ? 'text-red-600' : ($medicine['current_stock'] <= ($medicine['minimum_stock_level'] ?? 10) ? 'text-orange-600' : 'text-green-600'); ?>">
                                                <?php echo (int)$medicine['current_stock']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center py-4 px-4 text-gray-600"><?php echo $medicine['minimum_stock_level'] ?? 'N/A'; ?></td>
                                        <td class="text-center py-4 px-4 text-gray-600"><?php echo (int)$medicine['active_batches']; ?></td>
                                        <td class="text-center py-4 px-4 text-gray-600">
                                            <?php echo $medicine['earliest_expiry'] ? date('M d, Y', strtotime($medicine['earliest_expiry'])) : '-'; ?>
                                        </td>
                                        <td class="text-center py-4 px-4">
                                            <span class="text-sm font-semibold text-blue-600">
                                                <?php echo (int)$medicine['total_dispensed']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center py-4 px-4">
                                            <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $medicine['status_class']; ?>">
                                                <?php echo htmlspecialchars($medicine['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center py-4 px-4">
                                            <button onclick="viewMedicineHistory(<?php echo (int)$medicine['id']; ?>)" class="text-blue-600 hover:text-blue-800 font-medium text-sm">View History</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="bg-white rounded-2xl shadow-lg p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Recent Transactions</h2>
                    <div class="space-y-4">
                        <?php foreach ($recent_transactions as $transaction): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-4">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $transaction['status'] === 'claimed' ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600'; ?>">
                                        <?php if ($transaction['status'] === 'claimed'): ?>
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        <?php else: ?>
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center space-x-3">
                                        <?php if (!empty($transaction['medicine_image'])): ?>
                                            <div class="w-8 h-8 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                                <img src="<?php echo htmlspecialchars(base_url($transaction['medicine_image'])); ?>" 
                                                     alt="<?php echo htmlspecialchars($transaction['medicine_name']); ?>"
                                                     class="w-full h-full object-cover">
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($transaction['medicine_name']); ?></div>
                                            <div class="text-sm text-gray-500">
                                                Request #<?php echo (int)$transaction['id']; ?> - 
                                                <?php echo $transaction['status'] === 'claimed' ? 'Dispensed' : ucfirst($transaction['status']); ?>
                                                <?php if ($transaction['quantity'] > 0): ?>
                                                    (<?php echo (int)$transaction['quantity']; ?> units)
                                                <?php endif; ?>
                                                - <?php echo htmlspecialchars($transaction['resident_type']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm text-gray-500"><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></div>
                                    <?php if (!empty($transaction['resident_name'])): ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($transaction['resident_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['purok_name'])): ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($transaction['purok_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                 </div>
             </div>
         </div>
     </main>

    <!-- Inventory Adjustment Modal -->
    <div id="adjustmentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-gray-900">Adjust Inventory</h3>
                <button onclick="closeAdjustmentModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="adjust">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Medicine</label>
                        <select name="medicine_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select a medicine</option>
                            <?php foreach ($medicines as $medicine): ?>
                                <option value="<?php echo (int)$medicine['id']; ?>"><?php echo htmlspecialchars($medicine['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Adjustment Type</label>
                        <select name="adjustment_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select type</option>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantity</label>
                        <input type="number" name="quantity" required min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Enter quantity">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Expiry Date</label>
                        <input type="date" name="expiry_date" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Reason</label>
                        <textarea name="reason" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Enter reason for adjustment"></textarea>
                    </div>
                </div>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="closeAdjustmentModal()" class="flex-1 px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        Adjust Inventory
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAdjustmentModal() {
            document.getElementById('adjustmentModal').classList.remove('hidden');
            document.getElementById('adjustmentModal').classList.add('flex');
        }

        function closeAdjustmentModal() {
            document.getElementById('adjustmentModal').classList.add('hidden');
            document.getElementById('adjustmentModal').classList.remove('flex');
        }

        function viewMedicineHistory(medicineId) {
            // Create a modal to show medicine history
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-6 w-full max-w-4xl mx-4 max-h-[80vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Medicine History</h3>
                        <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="text-center py-8">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                        <p class="text-gray-600">Loading medicine history...</p>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Simulate loading and show placeholder content
            setTimeout(() => {
                modal.querySelector('.text-center').innerHTML = `
                    <div class="text-gray-600">
                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        <h4 class="text-lg font-semibold mb-2">Medicine History Feature</h4>
                        <p class="mb-4">This feature will show detailed transaction history, batch information, and stock movements for Medicine ID: ${medicineId}</p>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <p class="text-blue-800 text-sm">
                                <strong>Coming Soon:</strong> Complete transaction history, batch tracking, expiry monitoring, and detailed analytics.
                            </p>
                        </div>
                    </div>
                `;
            }, 1000);
        }

        // Handle adjustment type change
        document.querySelector('select[name="adjustment_type"]').addEventListener('change', function() {
            const expiryField = document.querySelector('input[name="expiry_date"]');
            const expiryLabel = expiryField.previousElementSibling;
            
            if (this.value === 'add') {
                expiryField.required = true;
                expiryField.parentElement.style.display = 'block';
                expiryLabel.textContent = 'Expiry Date (Required for new stock)';
            } else if (this.value === 'remove') {
                expiryField.required = false;
                expiryField.parentElement.style.display = 'none';
            } else {
                expiryField.required = false;
                expiryField.parentElement.style.display = 'none';
            }
        });

        // Initialize expiry field as hidden
        document.querySelector('input[name="expiry_date"]').parentElement.style.display = 'none';

        // Add ripple effect to buttons
        document.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', function(e) {
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
    </script>
</body>
</html>
