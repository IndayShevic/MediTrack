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

// Add minimum_stock_level column if it doesn't exist
try {
    db()->exec('ALTER TABLE medicines ADD COLUMN IF NOT EXISTS minimum_stock_level INT DEFAULT 10');
} catch (Exception $e) {
    // Column might already exist, continue
}

// Get detailed inventory summary with enhanced information
try {
    $inventory_summary = db()->query('
        SELECT 
            m.id,
            m.name,
            m.description,
            m.image_path,
            COALESCE(m.minimum_stock_level, 10) as minimum_stock_level,
            COALESCE(SUM(mb.quantity_available), 0) as current_stock,
            COUNT(mb.id) as total_batches,
            COUNT(CASE WHEN mb.quantity_available > 0 THEN mb.id END) as active_batches,
            MIN(CASE WHEN mb.quantity_available > 0 THEN mb.expiry_date END) as earliest_expiry,
            MAX(CASE WHEN mb.quantity_available > 0 THEN mb.expiry_date END) as latest_expiry,
            COALESCE(SUM(mb.quantity), 0) as total_received,
            COALESCE(SUM(mb.quantity - mb.quantity_available), 0) as total_dispensed,
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "Out of Stock"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 10 THEN "Low Stock"
                WHEN MIN(mb.expiry_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "Expiring Soon"
                ELSE "In Stock"
            END as status,
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "text-red-600 bg-red-50"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 10 THEN "text-orange-600 bg-orange-50"
                WHEN MIN(mb.expiry_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "text-yellow-600 bg-yellow-50"
                ELSE "text-green-600 bg-green-50"
            END as status_class
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        GROUP BY m.id, m.name, m.description, m.image_path
        ORDER BY 
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN 1
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 10 THEN 2
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

// Create inventory_transactions table if it doesn't exist
try {
    db()->exec('
        CREATE TABLE IF NOT EXISTS inventory_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            transaction_type ENUM("IN", "OUT", "ADJUSTMENT", "TRANSFER", "EXPIRED", "DAMAGED") NOT NULL,
            quantity INT NOT NULL,
            reference_type ENUM("BATCH_RECEIVED", "REQUEST_DISPENSED", "WALKIN_DISPENSED", "ADJUSTMENT", "TRANSFER", "EXPIRY", "DAMAGE") NOT NULL,
            reference_id INT NULL,
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_medicine_date (medicine_id, created_at),
            INDEX idx_type_date (transaction_type, created_at),
            INDEX idx_reference (reference_type, reference_id)
        ) ENGINE=InnoDB
    ');
} catch (Exception $e) {
    // Table might already exist, continue
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

// Create inventory_alerts table
try {
    db()->exec('
        CREATE TABLE IF NOT EXISTS inventory_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            alert_type ENUM("low_stock", "out_of_stock", "expiring_soon", "expired", "reorder_point") NOT NULL,
            severity ENUM("low", "medium", "high", "critical") NOT NULL,
            message TEXT NOT NULL,
            current_value INT NULL,
            threshold_value INT NULL,
            is_acknowledged BOOLEAN DEFAULT FALSE,
            acknowledged_by INT NULL,
            acknowledged_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE CASCADE,
            FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_alert_type (alert_type),
            INDEX idx_severity (severity),
            INDEX idx_acknowledged (is_acknowledged),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
    ');
} catch (Exception $e) {
    // Table might already exist, continue
}

// Generate automatic alerts
function generateInventoryAlerts() {
    try {
        // 1. Check for low stock (below minimum level)
        $low_stock = db()->query('
            SELECT 
                m.id as medicine_id,
                m.name,
                COALESCE(SUM(mb.quantity_available), 0) as current_stock,
                COALESCE(m.minimum_stock_level, 10) as min_level
            FROM medicines m
            LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id AND mb.quantity_available > 0
            GROUP BY m.id, m.name, m.minimum_stock_level
            HAVING current_stock > 0 AND current_stock <= min_level
        ')->fetchAll();
        
        foreach ($low_stock as $item) {
            // Check if alert already exists and is not acknowledged
            $exists = db()->prepare('
                SELECT id FROM inventory_alerts 
                WHERE medicine_id = ? AND alert_type = "low_stock" AND is_acknowledged = FALSE
            ');
            $exists->execute([$item['medicine_id']]);
            
            if (!$exists->fetch()) {
                $severity = $item['current_stock'] <= ($item['min_level'] / 2) ? 'high' : 'medium';
                $stmt = db()->prepare('
                    INSERT INTO inventory_alerts 
                    (medicine_id, alert_type, severity, message, current_value, threshold_value)
                    VALUES (?, "low_stock", ?, ?, ?, ?)
                ');
                $message = "{$item['name']} stock is low! Only {$item['current_stock']} units remaining (Minimum: {$item['min_level']})";
                $stmt->execute([$item['medicine_id'], $severity, $message, $item['current_stock'], $item['min_level']]);
            }
        }
        
        // 2. Check for out of stock
        $out_of_stock = db()->query('
            SELECT 
                m.id as medicine_id,
                m.name
            FROM medicines m
            LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id AND mb.quantity_available > 0
            GROUP BY m.id, m.name
            HAVING COALESCE(SUM(mb.quantity_available), 0) = 0
        ')->fetchAll();
        
        foreach ($out_of_stock as $item) {
            $exists = db()->prepare('
                SELECT id FROM inventory_alerts 
                WHERE medicine_id = ? AND alert_type = "out_of_stock" AND is_acknowledged = FALSE
            ');
            $exists->execute([$item['medicine_id']]);
            
            if (!$exists->fetch()) {
                $stmt = db()->prepare('
                    INSERT INTO inventory_alerts 
                    (medicine_id, alert_type, severity, message, current_value, threshold_value)
                    VALUES (?, "out_of_stock", "critical", ?, 0, 0)
                ');
                $message = "{$item['name']} is OUT OF STOCK! Immediate reorder required.";
                $stmt->execute([$item['medicine_id'], $message]);
            }
        }
        
        // 3. Check for expiring batches (within 30 days)
        $expiring = db()->query('
            SELECT 
                m.id as medicine_id,
                m.name,
                mb.id as batch_id,
                mb.batch_code,
                mb.quantity_available,
                mb.expiry_date,
                DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.id
            WHERE mb.quantity_available > 0 
            AND mb.expiry_date > CURDATE()
            AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ')->fetchAll();
        
        foreach ($expiring as $item) {
            $exists = db()->prepare('
                SELECT id FROM inventory_alerts 
                WHERE batch_id = ? AND alert_type = "expiring_soon" AND is_acknowledged = FALSE
            ');
            $exists->execute([$item['batch_id']]);
            
            if (!$exists->fetch()) {
                $severity = $item['days_until_expiry'] <= 7 ? 'high' : 
                           ($item['days_until_expiry'] <= 14 ? 'medium' : 'low');
                $stmt = db()->prepare('
                    INSERT INTO inventory_alerts 
                    (medicine_id, batch_id, alert_type, severity, message, current_value, threshold_value)
                    VALUES (?, ?, "expiring_soon", ?, ?, ?, 30)
                ');
                $message = "{$item['name']} batch {$item['batch_code']} expires in {$item['days_until_expiry']} days! ({$item['quantity_available']} units)";
                $stmt->execute([$item['medicine_id'], $item['batch_id'], $severity, $message, $item['days_until_expiry']]);
            }
        }
        
        // 4. Check for expired batches
        $expired = db()->query('
            SELECT 
                m.id as medicine_id,
                m.name,
                mb.id as batch_id,
                mb.batch_code,
                mb.quantity_available,
                mb.expiry_date
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.id
            WHERE mb.quantity_available > 0 AND mb.expiry_date <= CURDATE()
        ')->fetchAll();
        
        foreach ($expired as $item) {
            $exists = db()->prepare('
                SELECT id FROM inventory_alerts 
                WHERE batch_id = ? AND alert_type = "expired" AND is_acknowledged = FALSE
            ');
            $exists->execute([$item['batch_id']]);
            
            if (!$exists->fetch()) {
                $stmt = db()->prepare('
                    INSERT INTO inventory_alerts 
                    (medicine_id, batch_id, alert_type, severity, message, current_value)
                    VALUES (?, ?, "expired", "critical", ?, 0)
                ');
                $message = "{$item['name']} batch {$item['batch_code']} has EXPIRED! Remove {$item['quantity_available']} units from inventory.";
                $stmt->execute([$item['medicine_id'], $item['batch_id'], $message]);
            }
        }
        
    } catch (Exception $e) {
        error_log("Alert generation error: " . $e->getMessage());
    }
}

// Generate alerts on page load
generateInventoryAlerts();

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
                    INSERT INTO medicine_batches (medicine_id, batch_code, quantity, quantity_available, expiry_date, received_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ');
                
                $batch_code = 'ADJ-' . date('YmdHis') . '-' . $medicine_id;
                $stmt->execute([$medicine_id, $batch_code, $quantity, $quantity, $expiry_date]);
                
                $batch_id = db()->lastInsertId();
                
                // Log the transaction
                $trans_stmt = db()->prepare('
                    INSERT INTO inventory_transactions 
                    (medicine_id, batch_id, transaction_type, quantity, reference_type, reference_id, notes, created_by, created_at)
                    VALUES (?, ?, "IN", ?, "ADJUSTMENT", ?, ?, ?, NOW())
                ');
                $trans_stmt->execute([
                    $medicine_id, 
                    $batch_id, 
                    $quantity, 
                    $batch_id, 
                    "Manual adjustment: Add stock - $reason", 
                    $user['id']
                ]);
                
                set_flash("Successfully added $quantity units to inventory!", 'success');
            } elseif ($adjustment_type === 'remove') {
                // Remove stock using FEFO (First Expiry, First Out)
                $available_batches = db()->prepare('
                    SELECT id, quantity_available, batch_code 
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
                    
                    // Log each batch removal transaction
                    $trans_stmt = db()->prepare('
                        INSERT INTO inventory_transactions 
                        (medicine_id, batch_id, transaction_type, quantity, reference_type, reference_id, notes, created_by, created_at)
                        VALUES (?, ?, "OUT", ?, "ADJUSTMENT", ?, ?, ?, NOW())
                    ');
                    $trans_stmt->execute([
                        $medicine_id, 
                        $batch['id'], 
                        -$remove_from_batch, 
                        $batch['id'], 
                        "Manual adjustment: Remove stock - $reason", 
                        $user['id']
                    ]);
                    
                    $removed_batches[] = [
                        'batch' => $batch['batch_code'],
                        'quantity' => $remove_from_batch,
                        'batch_id' => $batch['id']
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

// Get additional inventory statistics
try {
    // Total stock units (all available medicines)
    $result = db()->query('
        SELECT COALESCE(SUM(mb.quantity_available), 0) as total_units
        FROM medicine_batches mb
        WHERE mb.quantity_available > 0 AND mb.expiry_date > CURDATE()
    ')->fetch();
    $total_stock_units = isset($result['total_units']) ? (int)$result['total_units'] : 0;
} catch (Exception $e) {
    $total_stock_units = 0;
}

// Get stock turnover rate (last 30 days)
try {
    $stock_turnover = db()->query('
        SELECT 
            COUNT(DISTINCT medicine_id) as active_medicines,
            SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) as total_dispensed_30d
        FROM inventory_transactions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ')->fetch();
} catch (Exception $e) {
    $stock_turnover = ['active_medicines' => 0, 'total_dispensed_30d' => 0];
}

// Get top moving medicines (last 30 days)
try {
    $top_moving_medicines = db()->query('
        SELECT 
            m.id,
            m.name,
            m.image_path,
            SUM(CASE WHEN it.transaction_type = "OUT" THEN ABS(it.quantity) ELSE 0 END) as units_dispensed
        FROM medicines m
        JOIN inventory_transactions it ON m.id = it.medicine_id
        WHERE it.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND it.transaction_type = "OUT"
        GROUP BY m.id, m.name, m.image_path
        ORDER BY units_dispensed DESC
        LIMIT 5
    ')->fetchAll();
} catch (Exception $e) {
    $top_moving_medicines = [];
}

// Get stock aging analysis
try {
    $stock_aging = db()->query('
        SELECT 
            CASE 
                WHEN mb.expiry_date <= CURDATE() THEN "Expired"
                WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "Expiring Soon"
                WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN "Near Expiry"
                ELSE "Fresh Stock"
            END as age_category,
            SUM(mb.quantity_available) as quantity
        FROM medicine_batches mb
        WHERE mb.quantity_available > 0
        GROUP BY age_category
    ')->fetchAll();
} catch (Exception $e) {
    $stock_aging = [];
}

// Get monthly stock movements
try {
    $monthly_movements = db()->query('
        SELECT 
            DATE_FORMAT(created_at, "%Y-%m") as month,
            SUM(CASE WHEN transaction_type = "IN" THEN quantity ELSE 0 END) as stock_in,
            SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) as stock_out
        FROM inventory_transactions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, "%Y-%m")
        ORDER BY month ASC
    ')->fetchAll();
} catch (Exception $e) {
    $monthly_movements = [];
}

// Get critical stock alerts with forecasting
try {
    $critical_alerts = db()->query('
        SELECT 
            m.id,
            m.name,
            m.image_path,
            COALESCE(SUM(mb.quantity_available), 0) as current_stock,
            COALESCE(m.minimum_stock_level, 10) as min_level,
            MIN(CASE WHEN mb.quantity_available > 0 THEN mb.expiry_date END) as next_expiry,
            (
                SELECT COALESCE(AVG(daily_out), 0)
                FROM (
                    SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                    FROM inventory_transactions
                    WHERE medicine_id = m.id 
                    AND transaction_type = "OUT"
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY DATE(created_at)
                ) as daily_usage
            ) as avg_daily_usage,
            CASE 
                WHEN (
                    SELECT COALESCE(AVG(daily_out), 0)
                    FROM (
                        SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                        FROM inventory_transactions
                        WHERE medicine_id = m.id 
                        AND transaction_type = "OUT"
                        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY DATE(created_at)
                    ) as daily_usage
                ) > 0 
                THEN FLOOR(COALESCE(SUM(mb.quantity_available), 0) / (
                    SELECT COALESCE(AVG(daily_out), 0.1)
                    FROM (
                        SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                        FROM inventory_transactions
                        WHERE medicine_id = m.id 
                        AND transaction_type = "OUT"
                        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY DATE(created_at)
                    ) as daily_usage
                ))
                ELSE 999
            END as days_until_stockout
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        GROUP BY m.id, m.name, m.image_path, m.minimum_stock_level
        HAVING current_stock <= min_level OR current_stock = 0
        ORDER BY days_until_stockout ASC, current_stock ASC
        LIMIT 10
    ')->fetchAll();
} catch (Exception $e) {
    $critical_alerts = [];
}

// Get stock forecasting data (predicted stockouts in next 30 days)
try {
    $stock_forecast = db()->query('
        SELECT 
            m.id,
            m.name,
            m.image_path,
            COALESCE(SUM(mb.quantity_available), 0) as current_stock,
            (
                SELECT COALESCE(AVG(daily_out), 0)
                FROM (
                    SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                    FROM inventory_transactions
                    WHERE medicine_id = m.id 
                    AND transaction_type = "OUT"
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY DATE(created_at)
                ) as daily_usage
            ) as avg_daily_usage,
            CASE 
                WHEN (
                    SELECT COALESCE(AVG(daily_out), 0)
                    FROM (
                        SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                        FROM inventory_transactions
                        WHERE medicine_id = m.id 
                        AND transaction_type = "OUT"
                        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY DATE(created_at)
                    ) as daily_usage
                ) > 0 
                THEN FLOOR(COALESCE(SUM(mb.quantity_available), 0) / (
                    SELECT COALESCE(AVG(daily_out), 0.1)
                    FROM (
                        SELECT DATE(created_at) as date, SUM(ABS(quantity)) as daily_out
                        FROM inventory_transactions
                        WHERE medicine_id = m.id 
                        AND transaction_type = "OUT"
                        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY DATE(created_at)
                    ) as daily_usage
                ))
                ELSE 999
            END as days_until_stockout
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
        WHERE mb.quantity_available > 0
        GROUP BY m.id, m.name, m.image_path
        HAVING days_until_stockout > 0 AND days_until_stockout <= 30 AND avg_daily_usage > 0
        ORDER BY days_until_stockout ASC
        LIMIT 5
    ')->fetchAll();
} catch (Exception $e) {
    $stock_forecast = [];
}

// Get batch expiry timeline
try {
    $expiry_timeline = db()->query('
        SELECT 
            m.name as medicine_name,
            m.image_path,
            mb.batch_code,
            mb.quantity_available,
            mb.expiry_date,
            DATEDIFF(mb.expiry_date, CURDATE()) as days_until_expiry,
            CASE 
                WHEN mb.expiry_date <= CURDATE() THEN "expired"
                WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN "critical"
                WHEN mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "warning"
                ELSE "good"
            END as expiry_status
        FROM medicine_batches mb
        JOIN medicines m ON mb.medicine_id = m.id
        WHERE mb.quantity_available > 0
        AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ORDER BY mb.expiry_date ASC
        LIMIT 20
    ')->fetchAll();
} catch (Exception $e) {
    $expiry_timeline = [];
}

// Get inventory turnover ratio (last 90 days)
try {
    $turnover_stats = db()->query('
        SELECT 
            COUNT(DISTINCT medicine_id) as active_items,
            SUM(CASE WHEN transaction_type = "IN" THEN quantity ELSE 0 END) as total_in,
            SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) as total_out,
            ROUND(
                SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) / 
                NULLIF(SUM(CASE WHEN transaction_type = "IN" THEN quantity ELSE 0 END), 0) * 100, 
                2
            ) as turnover_rate
        FROM inventory_transactions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    ')->fetch();
} catch (Exception $e) {
    $turnover_stats = ['active_items' => 0, 'total_in' => 0, 'total_out' => 0, 'turnover_rate' => 0];
}

try {
    // Total batches
    $total_batches = db()->query('SELECT COUNT(*) as count FROM medicine_batches')->fetch()['count'];
} catch (Exception $e) {
    $total_batches = 0;
}

try {
    // Active batches (not expired, has stock)
    $active_batches = db()->query('
        SELECT COUNT(*) as count 
        FROM medicine_batches 
        WHERE quantity_available > 0 AND expiry_date > CURDATE()
    ')->fetch()['count'];
} catch (Exception $e) {
    $active_batches = 0;
}

try {
    // Expired batches
    $expired_batches = db()->query('
        SELECT COUNT(*) as count 
        FROM medicine_batches 
        WHERE expiry_date <= CURDATE() AND quantity_available > 0
    ')->fetch()['count'];
} catch (Exception $e) {
    $expired_batches = 0;
}

try {
    // Total transactions today
    $today_transactions_count = db()->query('
        SELECT COUNT(*) as count 
        FROM inventory_transactions 
        WHERE DATE(created_at) = CURDATE()
    ')->fetch()['count'];
} catch (Exception $e) {
    $today_transactions_count = 0;
}

// Get active alerts (unacknowledged)
try {
    $active_alerts = db()->query('
        SELECT 
            ia.*,
            m.name as medicine_name,
            m.image_path,
            mb.batch_code,
            DATE_FORMAT(ia.created_at, "%b %d, %Y %H:%i") as formatted_date
        FROM inventory_alerts ia
        JOIN medicines m ON ia.medicine_id = m.id
        LEFT JOIN medicine_batches mb ON ia.batch_id = mb.id
        WHERE ia.is_acknowledged = FALSE
        ORDER BY 
            CASE ia.severity
                WHEN "critical" THEN 1
                WHEN "high" THEN 2
                WHEN "medium" THEN 3
                ELSE 4
            END,
            ia.created_at DESC
    ')->fetchAll();
    
    $alerts_count = count($active_alerts);
    $critical_alerts_count = count(array_filter($active_alerts, fn($a) => $a['severity'] === 'critical'));
    $high_alerts_count = count(array_filter($active_alerts, fn($a) => $a['severity'] === 'high'));
} catch (Exception $e) {
    $active_alerts = [];
    $alerts_count = 0;
    $critical_alerts_count = 0;
    $high_alerts_count = 0;
}

try {
    // Stock movements (last 7 days)
    $stock_movements = db()->query('
        SELECT 
            DATE(created_at) as date,
            SUM(CASE WHEN transaction_type = "IN" THEN quantity ELSE 0 END) as stock_in,
            SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) as stock_out
        FROM inventory_transactions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ')->fetchAll();
} catch (Exception $e) {
    $stock_movements = [];
}

// Transaction history is now loaded via AJAX modal (see get_medicine_history.php)
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        /* Smooth Scrolling */
        html {
            scroll-behavior: smooth;
        }
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
            position: sticky !important;
            top: 0 !important;
            z-index: 50 !important;
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            padding: 2rem !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 2rem !important;
        }
        
        .stat-card {
            background: white !important;
            border: 1px solid #e5e7eb !important;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            overflow: hidden !important;
        }
        
        .stat-card::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: -100% !important;
            width: 100% !important;
            height: 100% !important;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent) !important;
            transition: left 0.5s !important;
        }
        
        .stat-card:hover::before {
            left: 100% !important;
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12) !important;
            border-color: #93c5fd !important;
        }
        
        /* Pulse Animation for Icons */
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        
        .stat-icon {
            animation: pulse-slow 3s ease-in-out infinite !important;
        }
        
        /* Gradient Borders */
        .gradient-border {
            position: relative !important;
            background: white !important;
            border-radius: 1rem !important;
        }
        
        .gradient-border::before {
            content: '' !important;
            position: absolute !important;
            inset: -2px !important;
            border-radius: 1rem !important;
            padding: 2px !important;
            background: linear-gradient(45deg, #3b82f6, #8b5cf6, #ec4899, #f59e0b) !important;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0) !important;
            -webkit-mask-composite: xor !important;
            mask-composite: exclude !important;
            opacity: 0 !important;
            transition: opacity 0.3s !important;
        }
        
        .gradient-border:hover::before {
            opacity: 1 !important;
        }
        
        /* Skeleton Loading Animation */
        @keyframes skeleton-loading {
            0% { background-position: -200px 0; }
            100% { background-position: calc(200px + 100%) 0; }
        }
        
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 0px, #f8f8f8 40px, #f0f0f0 80px) !important;
            background-size: 200px 100% !important;
            animation: skeleton-loading 1.5s ease-in-out infinite !important;
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
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
            cursor: pointer !important;
        }
        
        .hover-lift:hover {
            transform: translateY(-8px) scale(1.02) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
        }
        
        .hover-lift:active {
            transform: translateY(-4px) scale(1.01) !important;
        }
        
        /* Glass Morphism Effect */
        .glass-effect {
            background: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(10px) saturate(180%) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
        }
        
        /* Tooltip Styles */
        .tooltip {
            position: relative !important;
        }
        
        .tooltip::after {
            content: attr(data-tooltip) !important;
            position: absolute !important;
            bottom: 100% !important;
            left: 50% !important;
            transform: translateX(-50%) translateY(-8px) !important;
            padding: 8px 12px !important;
            background: rgba(0, 0, 0, 0.9) !important;
            color: white !important;
            font-size: 12px !important;
            border-radius: 6px !important;
            white-space: nowrap !important;
            opacity: 0 !important;
            pointer-events: none !important;
            transition: opacity 0.3s, transform 0.3s !important;
            z-index: 1000 !important;
        }
        
        .tooltip:hover::after {
            opacity: 1 !important;
            transform: translateX(-50%) translateY(-4px) !important;
        }
        
        /* Progress Bar Animation */
        .progress-bar {
            position: relative !important;
            height: 4px !important;
            background: #e5e7eb !important;
            border-radius: 2px !important;
            overflow: hidden !important;
        }
        
        .progress-bar::after {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            height: 100% !important;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6) !important;
            border-radius: 2px !important;
            transition: width 0.5s ease !important;
        }
        
        /* Badge Styles */
        .badge {
            display: inline-flex !important;
            align-items: center !important;
            padding: 4px 12px !important;
            border-radius: 9999px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            transition: all 0.3s !important;
        }
        
        .badge:hover {
            transform: scale(1.05) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }
        
        /* Shimmer Effect */
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        
        .shimmer {
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent) !important;
            background-size: 1000px 100% !important;
            animation: shimmer 2s infinite !important;
        }
        
        /* Card Hover Glow */
        .card-glow {
            position: relative !important;
            transition: all 0.3s ease !important;
        }
        
        .card-glow::after {
            content: '' !important;
            position: absolute !important;
            inset: -2px !important;
            border-radius: inherit !important;
            background: linear-gradient(45deg, #3b82f6, #8b5cf6, #ec4899) !important;
            opacity: 0 !important;
            z-index: -1 !important;
            filter: blur(20px) !important;
            transition: opacity 0.3s !important;
        }
        
        .card-glow:hover::after {
            opacity: 0.6 !important;
        }
        
        /* Smooth Number Counter */
        .counter {
            font-variant-numeric: tabular-nums !important;
        }
        
        /* Focus States */
        input:focus, select:focus, textarea:focus {
            outline: none !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
            transition: all 0.3s ease !important;
        }
        
        /* Button Hover Effects */
        button {
            position: relative !important;
            overflow: hidden !important;
            transition: all 0.3s ease !important;
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-2px) !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15) !important;
        }
        
        button:active:not(:disabled) {
            transform: translateY(0) !important;
        }
        
        button:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
        }
        
        /* Table Row Hover */
        tbody tr {
            transition: all 0.2s ease !important;
        }
        
        tbody tr:hover {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.05), rgba(139, 92, 246, 0.05)) !important;
            transform: scale(1.01) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        }
        
        /* Scroll Animation */
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
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards !important;
        }
        
        /* Stagger Animation */
        .stagger-1 { animation-delay: 0.1s !important; }
        .stagger-2 { animation-delay: 0.2s !important; }
        .stagger-3 { animation-delay: 0.3s !important; }
        .stagger-4 { animation-delay: 0.4s !important; }
        .stagger-5 { animation-delay: 0.5s !important; }
        .stagger-6 { animation-delay: 0.6s !important; }
        
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
        
        /* Print Styles */
        @media print {
            .sidebar, 
            .sidebar-footer,
            button,
            #inventorySearch,
            #statusFilter,
            .no-print {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .content-header {
                position: static !important;
                border-bottom: 2px solid #000 !important;
            }
            
            body {
                background: white !important;
            }
            
            .stat-card,
            .bg-white {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            h1, h2, h3 {
                color: #000 !important;
            }
            
            .text-blue-600,
            .text-red-600,
            .text-green-600,
            .text-orange-600 {
                color: #000 !important;
            }
            
            @page {
                margin: 1cm;
            }
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
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
                <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Inventory Management</h1>
                    <p class="text-gray-600 mt-1">Track medicine stock levels and manage inventory adjustments</p>
                    </div>
                <div class="flex items-center space-x-3">
                    <!-- Alerts Button -->
                    <button onclick="openAlertsModal()" class="relative flex items-center px-4 py-2 <?php echo $alerts_count > 0 ? 'bg-red-600 hover:bg-red-700 animate-pulse' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white rounded-lg transition-colors shadow-md">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Alerts
                        <?php if ($alerts_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-yellow-400 text-red-900 text-xs font-bold rounded-full h-6 w-6 flex items-center justify-center border-2 border-white">
                                <?php echo $alerts_count; ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <!-- Export Button -->
                    <button onclick="exportInventoryReport()" class="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shadow-md">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Export Report
                    </button>
                    
                    <!-- Print Button -->
                    <button onclick="window.print()" class="flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors shadow-md">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Print
                    </button>
                    </div>
                </div>
                
                <!-- Filter and Search Bar -->
                <div class="mt-6 flex flex-col md:flex-row items-center gap-4">
                    <div class="flex-1 w-full relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" id="inventorySearch" placeholder="Search medicines by name..." 
                               class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all shadow-sm hover:border-gray-300"
                               onkeyup="filterInventory()">
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center">
                            <span id="searchCount" class="text-xs text-gray-500 font-medium"></span>
                    </div>
                        </div>
                    <div class="relative">
                        <select id="statusFilter" class="pl-10 pr-10 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all shadow-sm hover:border-gray-300 appearance-none bg-white" onchange="filterInventory()">
                            <option value="">All Status</option>
                            <option value="out-of-stock">Out of Stock</option>
                            <option value="low-stock">Low Stock</option>
                            <option value="expiring-soon">Expiring Soon</option>
                            <option value="in-stock">In Stock</option>
                        </select>
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                            </svg>
                    </div>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                    <button onclick="clearFilters()" class="px-6 py-3 bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 rounded-xl hover:from-gray-200 hover:to-gray-300 transition-all shadow-sm font-medium flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Clear
                    </button>
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
            
            <!-- Quick Stats Summary Bar -->
            <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-2xl shadow-lg p-6 mb-8 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Inventory Overview</h3>
                        <p class="text-blue-100 text-sm">Real-time stock monitoring and analytics</p>
                    </div>
                    <div class="grid grid-cols-5 gap-6">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-green-200"><?php echo number_format($total_stock_units); ?></div>
                            <div class="text-xs text-blue-100 mt-1">Total Units</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold"><?php echo $total_medicines; ?></div>
                            <div class="text-xs text-blue-100 mt-1">Medicines</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-200"><?php echo $low_stock_count; ?></div>
                            <div class="text-xs text-blue-100 mt-1">Low Stock</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-orange-200"><?php echo $expiring_soon_count; ?></div>
                            <div class="text-xs text-blue-100 mt-1">Expiring</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-yellow-200"><?php echo $today_transactions_count; ?></div>
                            <div class="text-xs text-blue-100 mt-1">Today's Moves</div>
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
                    <!-- Total Stock Units -->
                    <div class="stat-card card-glow hover-lift fade-in-up stagger-1 p-6 rounded-2xl shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="stat-icon w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="counter text-3xl font-bold text-gray-900"><?php echo number_format($total_stock_units); ?></p>
                                <p class="text-sm text-gray-500">Total Stock Units</p>
                                <span class="badge bg-green-100 text-green-800 mt-2">Available</span>
                            </div>
                        </div>
                    </div>

                    <!-- Total Medicines -->
                    <div class="stat-card card-glow hover-lift fade-in-up stagger-2 p-6 rounded-2xl shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="stat-icon w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="counter text-3xl font-bold text-gray-900"><?php echo $total_medicines; ?></p>
                                <p class="text-sm text-gray-500">Total Medicines</p>
                                <span class="badge bg-blue-100 text-blue-800 mt-2">Active</span>
                            </div>
                        </div>
                    </div>

                    <!-- Active Batches -->
                    <div class="stat-card card-glow hover-lift fade-in-up stagger-3 p-6 rounded-2xl shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="stat-icon w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-purple-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="counter text-3xl font-bold text-gray-900"><?php echo $active_batches; ?></p>
                                <p class="text-sm text-gray-500">Active Batches</p>
                                <span class="badge bg-purple-100 text-purple-800 mt-2">In Stock</span>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Transactions -->
                    <div class="stat-card card-glow hover-lift fade-in-up stagger-4 p-6 rounded-2xl shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div class="relative">
                                <div class="stat-icon w-16 h-16 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-indigo-400 rounded-full animate-pulse"></div>
                            </div>
                            <div class="text-right">
                                <p class="counter text-3xl font-bold text-gray-900"><?php echo $today_transactions_count; ?></p>
                                <p class="text-sm text-gray-500">Today's Movements</p>
                                <span class="badge bg-indigo-100 text-indigo-800 mt-2">Live</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Stats Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Low Stock Alert -->
                    <div class="stat-card hover-lift p-6 rounded-2xl shadow-lg bg-gradient-to-br from-red-50 to-red-100 border border-red-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-red-500 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-red-700"><?php echo $low_stock_count; ?></p>
                                    <p class="text-sm text-red-600">Low Stock Items</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expiring Soon -->
                    <div class="stat-card hover-lift p-6 rounded-2xl shadow-lg bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-orange-500 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-orange-700"><?php echo $expiring_soon_count; ?></p>
                                    <p class="text-sm text-orange-600">Expiring Soon</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expired Batches -->
                    <div class="stat-card hover-lift p-6 rounded-2xl shadow-lg bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gray-500 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-gray-700"><?php echo $expired_batches; ?></p>
                                    <p class="text-sm text-gray-600">Expired Batches</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Advanced Analytics Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Top Moving Medicines -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-gray-900">Top Moving Medicines (30 Days)</h2>
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-medium">
                                Most Dispensed
                            </span>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($top_moving_medicines)): ?>
                                <p class="text-gray-500 text-center py-4">No movement data available</p>
                            <?php else: ?>
                                <?php foreach ($top_moving_medicines as $index => $medicine): ?>
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg hover:shadow-md transition-all">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white font-bold">
                                                <?php echo $index + 1; ?>
                                            </div>
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
                                                <div class="text-sm text-gray-500"><?php echo (int)$medicine['units_dispensed']; ?> units dispensed</div>
                                            </div>
                            </div>
                            <div class="text-right">
                                            <div class="text-lg font-bold text-blue-600"><?php echo (int)$medicine['units_dispensed']; ?></div>
                                            <div class="text-xs text-gray-500">units</div>
                            </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stock Aging Analysis -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold text-gray-900">Stock Aging Analysis</h2>
                            <span class="px-3 py-1 bg-purple-100 text-purple-800 rounded-full text-sm font-medium">
                                Expiry Status
                            </span>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($stock_aging)): ?>
                                <p class="text-gray-500 text-center py-4">No stock data available</p>
                            <?php else: ?>
                                <?php foreach ($stock_aging as $aging): ?>
                                    <?php 
                                    $color_class = match($aging['age_category']) {
                                        'Expired' => 'from-red-500 to-red-600',
                                        'Expiring Soon' => 'from-orange-500 to-orange-600',
                                        'Near Expiry' => 'from-yellow-500 to-yellow-600',
                                        default => 'from-green-500 to-green-600'
                                    };
                                    $bg_class = match($aging['age_category']) {
                                        'Expired' => 'from-red-50 to-red-100',
                                        'Expiring Soon' => 'from-orange-50 to-orange-100',
                                        'Near Expiry' => 'from-yellow-50 to-yellow-100',
                                        default => 'from-green-50 to-green-100'
                                    };
                                    ?>
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r <?php echo $bg_class; ?> rounded-lg hover:shadow-md transition-all">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-gradient-to-br <?php echo $color_class; ?> rounded-lg flex items-center justify-center text-white font-bold text-sm">
                                                <?php 
                                                $icons = ['Expired' => '✕', 'Expiring Soon' => '⏰', 'Near Expiry' => '⚠', 'Fresh Stock' => '✓'];
                                                echo $icons[$aging['age_category']] ?? '•';
                                                ?>
                                            </div>
                                            <div>
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($aging['age_category']); ?></div>
                                                <div class="text-sm text-gray-600">Stock status</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-gray-900"><?php echo number_format((int)$aging['quantity']); ?></div>
                                            <div class="text-xs text-gray-500">units</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Critical Stock Alerts -->
                <?php if (!empty($critical_alerts)): ?>
                <div class="bg-gradient-to-r from-red-50 to-orange-50 border-2 border-red-200 rounded-2xl shadow-lg p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-red-500 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                            <div>
                                <h2 class="text-xl font-bold text-red-900">Critical Stock Alerts</h2>
                                <p class="text-sm text-red-700">Immediate attention required</p>
                            </div>
                        </div>
                        <span class="px-4 py-2 bg-red-500 text-white rounded-full text-sm font-bold">
                            <?php echo count($critical_alerts); ?> Items
                        </span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($critical_alerts as $alert): ?>
                            <div class="bg-white rounded-lg p-4 border-2 border-red-200 hover:border-red-400 transition-all">
                                <div class="flex items-center space-x-3">
                                    <?php if (!empty($alert['image_path'])): ?>
                                        <div class="w-12 h-12 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                            <img src="<?php echo htmlspecialchars(base_url($alert['image_path'])); ?>" 
                                                 alt="<?php echo htmlspecialchars($alert['name']); ?>"
                                                 class="w-full h-full object-cover">
                                        </div>
                                    <?php else: ?>
                                        <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-orange-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            <?php echo strtoupper(substr($alert['name'], 0, 2)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-1">
                                        <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($alert['name']); ?></div>
                                        <div class="text-sm text-gray-600">
                                            Current: <span class="font-bold text-red-600"><?php echo (int)$alert['current_stock']; ?></span> / 
                                            Min: <span class="text-gray-700"><?php echo (int)$alert['min_level']; ?></span>
                                        </div>
                                        <?php if (isset($alert['avg_daily_usage']) && $alert['avg_daily_usage'] > 0): ?>
                                            <div class="text-xs text-blue-600 mt-1">
                                                📊 Avg usage: <?php echo number_format($alert['avg_daily_usage'], 1); ?> units/day
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($alert['days_until_stockout']) && $alert['days_until_stockout'] < 999): ?>
                                            <div class="text-xs text-red-700 mt-1 font-semibold">
                                                ⚠️ Stockout in ~<?php echo (int)$alert['days_until_stockout']; ?> days
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($alert['next_expiry']): ?>
                                            <div class="text-xs text-orange-600 mt-1">
                                                📅 Expires: <?php echo date('M d, Y', strtotime($alert['next_expiry'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <button onclick="openAdjustmentModal(); document.querySelector('select[name=\"medicine_id\"]').value='<?php echo (int)$alert['id']; ?>';" 
                                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                                        Restock
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stock Movement Chart -->
                <?php if (!empty($stock_movements)): ?>
                <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Stock Movements (Last 7 Days)</h2>
                    <div class="h-64">
                        <canvas id="stockMovementChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stock Forecast & Expiry Timeline -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Stock Forecast (Predicted Stockouts) -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Stock Forecast</h2>
                                <p class="text-sm text-gray-500">Predicted stockouts in next 30 days</p>
                            </div>
                            <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm font-medium">
                                📈 Forecast
                            </span>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($stock_forecast)): ?>
                                <div class="text-center py-8">
                                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <p class="text-gray-600 font-medium">No predicted stockouts!</p>
                                    <p class="text-sm text-gray-500">All medicines have sufficient stock for the next 30 days</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($stock_forecast as $forecast): ?>
                                    <?php 
                                    $urgency_class = $forecast['days_until_stockout'] <= 7 ? 'from-red-50 to-red-100 border-red-200' : 
                                                    ($forecast['days_until_stockout'] <= 14 ? 'from-orange-50 to-orange-100 border-orange-200' : 
                                                    'from-yellow-50 to-yellow-100 border-yellow-200');
                                    ?>
                                    <div class="bg-gradient-to-r <?php echo $urgency_class; ?> border-2 rounded-lg p-4">
                                        <div class="flex items-center space-x-3">
                                            <?php if (!empty($forecast['image_path'])): ?>
                                                <div class="w-12 h-12 rounded-lg overflow-hidden bg-white shadow-sm flex-shrink-0">
                                                    <img src="<?php echo htmlspecialchars(base_url($forecast['image_path'])); ?>" 
                                                         alt="<?php echo htmlspecialchars($forecast['name']); ?>"
                                                         class="w-full h-full object-cover">
                                                </div>
                                            <?php else: ?>
                                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                                    <?php echo strtoupper(substr($forecast['name'], 0, 2)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="flex-1">
                                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($forecast['name']); ?></div>
                                                <div class="text-sm text-gray-600 mt-1">
                                                    Current: <span class="font-bold"><?php echo (int)$forecast['current_stock']; ?></span> units
                                                </div>
                                                <div class="text-xs text-gray-600 mt-1">
                                                    Daily usage: <?php echo number_format($forecast['avg_daily_usage'], 1); ?> units/day
                                                </div>
                            </div>
                            <div class="text-right">
                                                <div class="text-2xl font-bold <?php echo $forecast['days_until_stockout'] <= 7 ? 'text-red-600' : ($forecast['days_until_stockout'] <= 14 ? 'text-orange-600' : 'text-yellow-600'); ?>">
                                                    <?php echo (int)$forecast['days_until_stockout']; ?>
                            </div>
                                                <div class="text-xs text-gray-600">days left</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Batch Expiry Timeline -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Batch Expiry Timeline</h2>
                                <p class="text-sm text-gray-500">Next 90 days</p>
                            </div>
                            <span class="px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm font-medium">
                                📦 <?php echo count($expiry_timeline); ?> Batches
                            </span>
                        </div>
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            <?php if (empty($expiry_timeline)): ?>
                                <p class="text-gray-500 text-center py-4">No batches expiring in the next 90 days</p>
                            <?php else: ?>
                                <?php foreach ($expiry_timeline as $batch): ?>
                                    <?php 
                                    $status_class = match($batch['expiry_status']) {
                                        'expired' => 'bg-red-100 border-red-300 text-red-900',
                                        'critical' => 'bg-red-50 border-red-200 text-red-800',
                                        'warning' => 'bg-orange-50 border-orange-200 text-orange-800',
                                        default => 'bg-blue-50 border-blue-200 text-blue-800'
                                    };
                                    ?>
                                    <div class="<?php echo $status_class; ?> border rounded-lg p-3">
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1">
                                                <div class="font-medium text-sm"><?php echo htmlspecialchars($batch['medicine_name']); ?></div>
                                                <div class="text-xs mt-1">
                                                    Batch: <span class="font-mono"><?php echo htmlspecialchars($batch['batch_code']); ?></span> 
                                                    • <?php echo (int)$batch['quantity_available']; ?> units
                                                </div>
                                            </div>
                                            <div class="text-right ml-3">
                                                <div class="text-sm font-bold">
                                                    <?php if ($batch['days_until_expiry'] < 0): ?>
                                                        Expired
                                                    <?php else: ?>
                                                        <?php echo (int)$batch['days_until_expiry']; ?> days
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs">
                                                    <?php echo date('M d', strtotime($batch['expiry_date'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Inventory Performance Metrics -->
                <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg p-6 mb-8 text-white">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-2xl font-bold">Inventory Performance (Last 90 Days)</h2>
                            <p class="text-indigo-100 mt-1">Key metrics and turnover analysis</p>
                        </div>
                        <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20">
                            <div class="text-indigo-100 text-sm mb-2">Active Items</div>
                            <div class="text-3xl font-bold"><?php echo (int)($turnover_stats['active_items'] ?? 0); ?></div>
                            <div class="text-xs text-indigo-200 mt-2">Medicines in use</div>
                        </div>
                        
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20">
                            <div class="text-indigo-100 text-sm mb-2">Total Stock In</div>
                            <div class="text-3xl font-bold"><?php echo number_format((int)($turnover_stats['total_in'] ?? 0)); ?></div>
                            <div class="text-xs text-indigo-200 mt-2">Units received</div>
                        </div>
                        
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20">
                            <div class="text-indigo-100 text-sm mb-2">Total Stock Out</div>
                            <div class="text-3xl font-bold"><?php echo number_format((int)($turnover_stats['total_out'] ?? 0)); ?></div>
                            <div class="text-xs text-indigo-200 mt-2">Units dispensed</div>
                        </div>
                        
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 border border-white/20">
                            <div class="text-indigo-100 text-sm mb-2">Turnover Rate</div>
                            <div class="text-3xl font-bold"><?php echo number_format((float)($turnover_stats['turnover_rate'] ?? 0), 1); ?>%</div>
                            <div class="text-xs text-indigo-200 mt-2">Stock efficiency</div>
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
                                    <tr class="inventory-row border-b border-gray-100 hover:bg-gray-50" 
                                        data-medicine-name="<?php echo htmlspecialchars($medicine['name']); ?>"
                                        data-status="<?php echo strtolower(str_replace(' ', '-', $medicine['status'])); ?>">
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

    <!-- Scroll to Top Button -->
    <button id="scrollToTop" class="fixed bottom-8 right-8 w-14 h-14 bg-gradient-to-br from-blue-500 to-purple-600 text-white rounded-full shadow-2xl hover:shadow-3xl transition-all duration-300 flex items-center justify-center opacity-0 pointer-events-none z-50">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

    <!-- Inventory Adjustment Modal -->
    <div id="adjustmentModal" class="fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm hidden items-center justify-center z-50 transition-all duration-300">
        <div class="bg-white rounded-3xl p-8 w-full max-w-md mx-4 shadow-2xl transform transition-all scale-95 modal-content">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-2xl font-bold text-gray-900">Adjust Inventory</h3>
                    <p class="text-sm text-gray-500 mt-1">Add or remove stock quantities</p>
                </div>
                <button onclick="closeAdjustmentModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full p-2 transition-all">
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

    <!-- Alerts Modal -->
    <div id="alertsModal" class="fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm hidden items-center justify-center z-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl w-full max-w-4xl shadow-2xl transform transition-all scale-95 modal-content my-8">
            <div class="sticky top-0 bg-white rounded-t-3xl border-b border-gray-200 px-8 py-6 flex items-center justify-between z-10">
                <div>
                    <h3 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Inventory Alerts
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">
                        <?php if ($alerts_count > 0): ?>
                            <span class="text-red-600 font-semibold"><?php echo $alerts_count; ?> active alert<?php echo $alerts_count !== 1 ? 's' : ''; ?></span>
                            <?php if ($critical_alerts_count > 0): ?>
                                • <span class="text-red-700 font-bold"><?php echo $critical_alerts_count; ?> critical</span>
                            <?php endif; ?>
                            <?php if ($high_alerts_count > 0): ?>
                                • <span class="text-orange-600 font-semibold"><?php echo $high_alerts_count; ?> high</span>
                            <?php endif; ?>
                        <?php else: ?>
                            All clear! No active alerts.
                        <?php endif; ?>
                    </p>
                </div>
                <button onclick="closeAlertsModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full p-2 transition-all">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="px-8 py-6 max-h-[70vh] overflow-y-auto">
                <?php if (empty($active_alerts)): ?>
                    <div class="text-center py-12">
                        <svg class="w-24 h-24 mx-auto mb-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h4 class="text-xl font-bold text-gray-700 mb-2">All Clear!</h4>
                        <p class="text-gray-500">No active alerts at the moment. Your inventory is healthy.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($active_alerts as $alert): ?>
                            <?php
                                $severity_colors = [
                                    'critical' => 'border-red-500 bg-red-50',
                                    'high' => 'border-orange-500 bg-orange-50',
                                    'medium' => 'border-yellow-500 bg-yellow-50',
                                    'low' => 'border-blue-500 bg-blue-50'
                                ];
                                $severity_badges = [
                                    'critical' => 'bg-red-600 text-white',
                                    'high' => 'bg-orange-600 text-white',
                                    'medium' => 'bg-yellow-600 text-white',
                                    'low' => 'bg-blue-600 text-white'
                                ];
                                $severity_icons = [
                                    'critical' => '🚨',
                                    'high' => '⚠️',
                                    'medium' => '⚡',
                                    'low' => 'ℹ️'
                                ];
                                $alert_icons = [
                                    'low_stock' => '📉',
                                    'out_of_stock' => '🛑',
                                    'expiring_soon' => '⏰',
                                    'expired' => '❌',
                                    'reorder_point' => '🔄'
                                ];
                            ?>
                            <div class="border-l-4 <?php echo $severity_colors[$alert['severity']]; ?> rounded-lg p-4 hover:shadow-md transition-all">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 text-3xl">
                                        <?php echo $alert_icons[$alert['alert_type']] ?? '📢'; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $severity_badges[$alert['severity']]; ?>">
                                                <?php echo $severity_icons[$alert['severity']]; ?> <?php echo strtoupper($alert['severity']); ?>
                                            </span>
                                            <span class="px-2 py-1 bg-gray-200 text-gray-700 text-xs font-medium rounded">
                                                <?php echo str_replace('_', ' ', strtoupper($alert['alert_type'])); ?>
                                            </span>
                                        </div>
                                        <p class="text-gray-800 font-medium mb-2"><?php echo htmlspecialchars($alert['message']); ?></p>
                                        <div class="flex items-center gap-4 text-sm text-gray-600">
                                            <span class="flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                                </svg>
                                                <?php echo htmlspecialchars($alert['medicine_name']); ?>
                                            </span>
                                            <?php if ($alert['batch_code']): ?>
                                            <span class="flex items-center gap-1 font-mono text-xs">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                                </svg>
                                                <?php echo htmlspecialchars($alert['batch_code']); ?>
                                            </span>
                                            <?php endif; ?>
                                            <span class="flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <?php echo $alert['formatted_date']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <button onclick="acknowledgeAlert(<?php echo $alert['id']; ?>)" class="flex-shrink-0 px-3 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors">
                                        Acknowledge
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-6 flex gap-3">
                        <button onclick="acknowledgeAllAlerts()" class="flex-1 px-4 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                            Acknowledge All
                        </button>
                        <button onclick="closeAlertsModal()" class="px-4 py-3 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition-colors">
                            Close
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Close history modal function (define first)
        function closeHistoryModal() {
            const modal = document.querySelector('.history-modal');
            if (modal) {
                modal.style.opacity = '0';
                const content = modal.querySelector('.modal-content');
                if (content) {
                    content.style.transform = 'scale(0.95)';
                }
                setTimeout(() => {
                    modal.remove();
                    // Restore body scroll
                    document.body.style.overflow = '';
                }, 300);
            }
        }
        
        // View medicine history function
        async function viewMedicineHistory(medicineId) {
            console.log('Opening history for medicine ID:', medicineId);
            
            // Prevent multiple modals
            const existingModal = document.querySelector('.history-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Fetch and display medicine history
            const modal = document.createElement('div');
            modal.className = 'history-modal fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm flex items-center justify-center p-4 transition-all duration-300';
            modal.style.cssText = 'z-index: 9999; opacity: 0; overflow-y: auto;';
            modal.innerHTML = `
                <div class="bg-white rounded-3xl w-full max-w-6xl shadow-2xl transform transition-all modal-content my-8" style="transform: scale(0.95); max-height: calc(100vh - 4rem);">
                    <div class="sticky top-0 bg-white rounded-t-3xl border-b border-gray-200 px-8 py-6 flex items-center justify-between z-10">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">Medicine Transaction History</h3>
                            <p class="text-sm text-gray-500 mt-1" id="medicineName">Loading medicine details...</p>
                        </div>
                        <button onclick="closeHistoryModal()" type="button" class="flex-shrink-0 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full p-2 transition-all">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div id="historyContent" class="px-8 py-6 overflow-y-auto" style="max-height: calc(100vh - 12rem);">
                        <div class="text-center py-12">
                            <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600 mx-auto mb-4"></div>
                            <p class="text-gray-600 font-medium">Loading transaction history...</p>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';
            
            // Close on click outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeHistoryModal();
                }
            });
            
            // Animate modal in
            setTimeout(() => {
                modal.style.opacity = '1';
                const content = modal.querySelector('.modal-content');
                if (content) {
                    content.style.transform = 'scale(1)';
                }
            }, 10);
            
            try {
                // Fetch transaction history
                const response = await fetch(`get_medicine_history.php?medicine_id=${medicineId}`);
                const data = await response.json();
                
                console.log('API Response:', data);
                
                const historyContent = modal.querySelector('#historyContent');
                const medicineNameEl = modal.querySelector('#medicineName');
                
                if (!data.success) {
                    // Show error message
                    historyContent.innerHTML = `
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto mb-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <h4 class="text-lg font-semibold text-gray-700 mb-2">Error</h4>
                            <p class="text-gray-500">${data.message || 'Failed to load data'}</p>
                        </div>
                    `;
                    return;
                }
                
                if (data.success && data.transactions && data.transactions.length > 0) {
                    medicineNameEl.textContent = data.medicine_name || 'Transaction History';
                    
                    // Build table HTML
                    let tableHTML = `
                        <div class="mb-4 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-lg">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div>
                                    <h4 class="font-bold text-green-900">📋 Complete Transaction History</h4>
                                    <p class="text-sm text-green-700">Showing all movements: batch receipts, dispensing to patients, and manual adjustments</p>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-gray-200">
                            <table class="w-full border-collapse">
                                <thead class="bg-gradient-to-r from-blue-50 to-purple-50 sticky top-0">
                                    <tr>
                                        <th class="text-left py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Date & Time</th>
                                        <th class="text-left py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Type</th>
                                        <th class="text-center py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Quantity</th>
                                        <th class="text-left py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Batch</th>
                                        <th class="text-left py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Patient/Recipient</th>
                                        <th class="text-left py-4 px-4 font-semibold text-gray-700 border-b border-gray-200">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white">
                    `;
                    
                    data.transactions.forEach((transaction, index) => {
                        const statusClass = transaction.transaction_type === 'IN' ? 'text-green-700 bg-green-100 border-green-200' : 
                                          transaction.transaction_type === 'OUT' ? 'text-red-700 bg-red-100 border-red-200' : 
                                          'text-blue-700 bg-blue-100 border-blue-200';
                        const quantityClass = transaction.quantity > 0 ? 'text-green-600 font-bold' : 'text-red-600 font-bold';
                        const quantitySign = transaction.quantity > 0 ? '+' : '';
                        const rowBg = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
                        
                        tableHTML += `
                            <tr class="${rowBg} hover:bg-blue-50 transition-colors border-b border-gray-100">
                                <td class="py-4 px-4 text-sm text-gray-700">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        ${transaction.created_at}
                                    </div>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="px-3 py-1.5 rounded-full text-xs font-bold border ${statusClass}">
                                        ${transaction.transaction_type === 'IN' ? '📥 IN' : '📤 OUT'}
                                    </span>
                                </td>
                                <td class="text-center py-4 px-4">
                                    <span class="text-lg ${quantityClass}">
                                        ${quantitySign}${Math.abs(transaction.quantity)}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="font-mono text-sm font-semibold text-indigo-600 bg-indigo-50 px-2 py-1 rounded">
                                        ${transaction.batch_number || '-'}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-gray-900">
                                            ${transaction.performed_by || 'System'}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-sm text-gray-600 max-w-md">
                                    ${transaction.notes || '-'}
                                </td>
                            </tr>
                        `;
                    });
                    
                    tableHTML += `
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Transaction Summary -->
                        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-green-50 border-2 border-green-200 rounded-xl p-4">
                                <div class="flex items-center gap-3">
                                    <div class="bg-green-500 text-white rounded-full p-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-green-700">
                                            ${data.transactions.filter(t => t.transaction_type === 'IN').reduce((sum, t) => sum + Math.abs(t.quantity), 0)}
                                        </div>
                                        <div class="text-sm text-green-600">Total IN</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-red-50 border-2 border-red-200 rounded-xl p-4">
                                <div class="flex items-center gap-3">
                                    <div class="bg-red-500 text-white rounded-full p-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-red-700">
                                            ${data.transactions.filter(t => t.transaction_type === 'OUT').reduce((sum, t) => sum + Math.abs(t.quantity), 0)}
                                        </div>
                                        <div class="text-sm text-red-600">Total OUT</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4">
                                <div class="flex items-center gap-3">
                                    <div class="bg-blue-500 text-white rounded-full p-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-blue-700">
                                            ${data.transactions.length}
                                        </div>
                                        <div class="text-sm text-blue-600">Total Transactions</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    historyContent.innerHTML = tableHTML;
                } else {
                    medicineNameEl.textContent = data.medicine_name || 'Medicine History';
                    
                    console.log('No transactions found. Checking batches...', data.batches);
                    
                    // Check if there are batches to show
                    if (data.batches && data.batches.length > 0) {
                        console.log('Found batches:', data.batches.length);
                        let batchesHTML = `
                            <div class="mb-6">
                                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg">
                                    <div class="flex items-center">
                                        <svg class="w-6 h-6 text-blue-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <div>
                                            <h4 class="font-semibold text-blue-900">No Transaction History Yet</h4>
                                            <p class="text-sm text-blue-700">Transaction tracking started recently. Here are the existing batches for this medicine:</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                                    </svg>
                                    Detailed Batch Tracking
                                </h3>
                                
                                <div class="space-y-4">
                        `;
                        
                        data.batches.forEach((batch, index) => {
                            // Status badge styling
                            let statusBadge = '';
                            let borderClass = 'border-gray-200';
                            let bgClass = 'bg-white';
                            
                            if (batch.batch_status === 'expired') {
                                statusBadge = '<span class="px-2 py-1 bg-red-100 text-red-800 text-xs font-semibold rounded-full">❌ Expired</span>';
                                borderClass = 'border-red-300';
                                bgClass = 'bg-red-50';
                            } else if (batch.batch_status === 'expiring_soon') {
                                statusBadge = '<span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-semibold rounded-full">⚠️ Expiring Soon</span>';
                                borderClass = 'border-orange-300';
                                bgClass = 'bg-orange-50';
                            } else if (batch.batch_status === 'depleted') {
                                statusBadge = '<span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-semibold rounded-full">📭 Depleted</span>';
                                borderClass = 'border-gray-300';
                                bgClass = 'bg-gray-50';
                            } else {
                                statusBadge = '<span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">✅ Active</span>';
                                borderClass = 'border-green-300';
                                bgClass = 'bg-green-50';
                            }
                            
                            const usagePercentage = parseFloat(batch.usage_percentage || 0);
                            const progressColor = usagePercentage < 50 ? 'bg-green-500' : usagePercentage < 80 ? 'bg-yellow-500' : 'bg-red-500';
                            
                            batchesHTML += `
                                <div class="border-2 ${borderClass} rounded-xl p-4 ${bgClass} hover:shadow-lg transition-all">
                                    <!-- Header -->
                                    <div class="flex items-start justify-between mb-3">
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-mono text-lg font-bold text-gray-900">${batch.batch_code}</span>
                                                ${statusBadge}
                                            </div>
                                            <div class="text-sm text-gray-600">Batch #${index + 1}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-2xl font-bold ${batch.quantity_available > 0 ? 'text-green-600' : 'text-gray-400'}">
                                                ${batch.quantity_available}
                                            </div>
                                            <div class="text-xs text-gray-500">units left</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Progress Bar -->
                                    <div class="mb-3">
                                        <div class="flex items-center justify-between text-xs text-gray-600 mb-1">
                                            <span>Usage Progress</span>
                                            <span class="font-semibold">${usagePercentage}%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                                            <div class="${progressColor} h-2.5 rounded-full transition-all" style="width: ${usagePercentage}%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Stats Grid -->
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                        <div class="bg-white rounded-lg p-2 border border-gray-200">
                                            <div class="text-xs text-gray-500 mb-1">Initial Qty</div>
                                            <div class="text-sm font-bold text-gray-900">${batch.initial_quantity}</div>
                                        </div>
                                        <div class="bg-white rounded-lg p-2 border border-gray-200">
                                            <div class="text-xs text-gray-500 mb-1">Dispensed</div>
                                            <div class="text-sm font-bold text-blue-600">${batch.quantity_dispensed}</div>
                                        </div>
                                        <div class="bg-white rounded-lg p-2 border border-gray-200">
                                            <div class="text-xs text-gray-500 mb-1">Requests</div>
                                            <div class="text-sm font-bold text-purple-600">${batch.total_requests_fulfilled || 0}</div>
                                        </div>
                                        <div class="bg-white rounded-lg p-2 border border-gray-200">
                                            <div class="text-xs text-gray-500 mb-1">Days in Stock</div>
                                            <div class="text-sm font-bold text-indigo-600">${batch.days_in_stock || 0}</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Timeline -->
                                    <div class="border-t border-gray-200 pt-3 space-y-2">
                                        <div class="flex items-center text-sm">
                                            <svg class="w-4 h-4 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                            </svg>
                                            <span class="text-gray-600">Received:</span>
                                            <span class="font-medium text-gray-900 ml-2">${batch.received_at}</span>
                                        </div>
                                        
                                        ${batch.first_dispensed_date ? `
                                        <div class="flex items-center text-sm">
                                            <svg class="w-4 h-4 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                                            </svg>
                                            <span class="text-gray-600">First Dispensed:</span>
                                            <span class="font-medium text-gray-900 ml-2">${new Date(batch.first_dispensed_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'})}</span>
                                        </div>
                                        ` : ''}
                                        
                                        ${batch.last_dispensed_date && batch.quantity_dispensed > 0 ? `
                                        <div class="flex items-center text-sm">
                                            <svg class="w-4 h-4 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-gray-600">Last Dispensed:</span>
                                            <span class="font-medium text-gray-900 ml-2">${new Date(batch.last_dispensed_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'})}</span>
                                        </div>
                                        ` : ''}
                                        
                                        <div class="flex items-center text-sm">
                                            <svg class="w-4 h-4 ${batch.days_until_expiry < 0 ? 'text-red-600' : batch.days_until_expiry <= 30 ? 'text-orange-600' : 'text-gray-600'} mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span class="text-gray-600">Expiry Date:</span>
                                            <span class="font-medium ${batch.days_until_expiry < 0 ? 'text-red-600' : batch.days_until_expiry <= 30 ? 'text-orange-600' : 'text-gray-900'} ml-2">
                                                ${new Date(batch.expiry_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'})}
                                                ${batch.days_until_expiry < 0 ? `(Expired ${Math.abs(batch.days_until_expiry)} days ago)` : `(${batch.days_until_expiry} days left)`}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                        
                        batchesHTML += `
                                </div>
                                
                                <!-- Summary -->
                                <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="font-semibold text-gray-900 mb-1">📊 Batch Summary</h4>
                                            <p class="text-sm text-gray-600">Showing detailed tracking for ${data.batches.length} batch${data.batches.length !== 1 ? 'es' : ''}</p>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm text-gray-600 mb-1">Total Units</div>
                                            <div class="text-2xl font-bold text-blue-600">
                                                ${data.batches.reduce((sum, b) => sum + (b.quantity_available || 0), 0)}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 bg-gradient-to-r from-green-50 to-emerald-50 border-2 border-green-300 rounded-lg p-4 text-sm">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-green-900 mb-1">✅ Full Transaction Tracking Active</h4>
                                            <p class="text-green-800 leading-relaxed">
                                                All inventory adjustments are now automatically tracked with complete transaction history. 
                                                Every time you add or remove stock using the <strong>"Adjust Inventory"</strong> button, 
                                                the system will log:
                                            </p>
                                            <ul class="mt-2 space-y-1 text-green-800">
                                                <li class="flex items-center gap-2">
                                                    <span class="text-green-600">▸</span>
                                                    <strong>IN transactions</strong> - When stock is added (creates new batches)
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <span class="text-green-600">▸</span>
                                                    <strong>OUT transactions</strong> - When stock is removed (FEFO: First Expiry, First Out)
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <span class="text-green-600">▸</span>
                                                    <strong>Batch details</strong> - Which specific batches were affected
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <span class="text-green-600">▸</span>
                                                    <strong>User & timestamp</strong> - Who made the change and when
                                                </li>
                                                <li class="flex items-center gap-2">
                                                    <span class="text-green-600">▸</span>
                                                    <strong>Reason/Notes</strong> - Why the adjustment was made
                                                </li>
                                            </ul>
                                            <p class="mt-2 text-green-800 font-medium">
                                                🎯 Try it now: Click "Adjust Inventory", add or remove some stock, then check the history again!
                                            </p>
                                        </div>
                                    </div>
                        </div>
                    </div>
                `;
                        historyContent.innerHTML = batchesHTML;
                    } else {
                        historyContent.innerHTML = `
                            <div class="text-center py-12">
                                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <h4 class="text-lg font-semibold text-gray-700 mb-2">No Data Available</h4>
                                <p class="text-gray-500">No batches or transactions found for this medicine.</p>
                            </div>
                        `;
                    }
                }
            } catch (error) {
                console.error('Error loading history:', error);
                const historyContent = modal.querySelector('#historyContent');
                historyContent.innerHTML = `
                    <div class="text-center py-12">
                        <svg class="w-16 h-16 mx-auto mb-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h4 class="text-lg font-semibold text-gray-700 mb-2">Error Loading History</h4>
                        <p class="text-gray-500">Could not load transaction history. Please try again.</p>
                        <button onclick="closeHistoryModal(); viewMedicineHistory(${medicineId});" 
                                class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Retry
                        </button>
                    </div>
                `;
            }
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

        // Stock Movement Chart
        <?php if (!empty($stock_movements)): ?>
        const stockMovementData = <?php echo json_encode($stock_movements); ?>;
        
        const ctx = document.getElementById('stockMovementChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: stockMovementData.map(d => {
                        const date = new Date(d.date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [
                        {
                            label: 'Stock In',
                            data: stockMovementData.map(d => d.stock_in),
                            borderColor: 'rgb(34, 197, 94)',
                            backgroundColor: 'rgba(34, 197, 94, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Stock Out',
                            data: stockMovementData.map(d => d.stock_out),
                            borderColor: 'rgb(239, 68, 68)',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        // Filter inventory table with animations
        function filterInventory() {
            const searchTerm = document.getElementById('inventorySearch').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const tableRows = document.querySelectorAll('.inventory-row');
            const searchCountEl = document.getElementById('searchCount');
            
            let visibleCount = 0;
            
            tableRows.forEach((row, index) => {
                const medicineName = row.getAttribute('data-medicine-name').toLowerCase();
                const status = row.getAttribute('data-status').toLowerCase();
                
                const matchesSearch = medicineName.includes(searchTerm);
                const matchesStatus = !statusFilter || status.includes(statusFilter);
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    row.style.animation = `fadeInUp 0.4s ease-out ${index * 0.05}s forwards`;
                    row.style.opacity = '0';
                    setTimeout(() => { row.style.opacity = '1'; }, index * 50);
                    visibleCount++;
                } else {
                    row.style.opacity = '0';
                    setTimeout(() => { row.style.display = 'none'; }, 300);
                }
            });
            
            // Update search count
            if (searchCountEl) {
                if (searchTerm || statusFilter) {
                    searchCountEl.textContent = `${visibleCount} result${visibleCount !== 1 ? 's' : ''}`;
                } else {
                    searchCountEl.textContent = '';
                }
            }
        }
        
        // Clear all filters
        function clearFilters() {
            document.getElementById('inventorySearch').value = '';
            document.getElementById('statusFilter').value = '';
            filterInventory();
        }
        
        // Export inventory report to CSV
        function exportInventoryReport() {
            // Prepare CSV data
            let csv = 'Medicine Name,Current Stock,Minimum Level,Active Batches,Earliest Expiry,Total Dispensed,Status\n';
            
            const tableRows = document.querySelectorAll('.inventory-row');
            tableRows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cols = row.querySelectorAll('td');
                    const medicineName = row.getAttribute('data-medicine-name');
                    const currentStock = cols[1].textContent.trim();
                    const minLevel = cols[2].textContent.trim();
                    const activeBatches = cols[3].textContent.trim();
                    const earliestExpiry = cols[4].textContent.trim();
                    const totalDispensed = cols[5].textContent.trim();
                    const status = cols[6].textContent.trim();
                    
                    csv += `"${medicineName}","${currentStock}","${minLevel}","${activeBatches}","${earliestExpiry}","${totalDispensed}","${status}"\n`;
                }
            });
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            const date = new Date().toISOString().split('T')[0];
            
            link.setAttribute('href', url);
            link.setAttribute('download', `inventory_report_${date}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Scroll to Top Button
        const scrollToTopBtn = document.getElementById('scrollToTop');
        
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                scrollToTopBtn.style.opacity = '1';
                scrollToTopBtn.style.pointerEvents = 'auto';
                scrollToTopBtn.style.transform = 'scale(1)';
            } else {
                scrollToTopBtn.style.opacity = '0';
                scrollToTopBtn.style.pointerEvents = 'none';
                scrollToTopBtn.style.transform = 'scale(0.8)';
            }
        });
        
        scrollToTopBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Enhanced Modal Animations
        function openAdjustmentModal() {
            const modal = document.getElementById('adjustmentModal');
            const content = modal.querySelector('.modal-content');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.style.opacity = '1';
                content.style.transform = 'scale(1)';
            }, 10);
        }

        function closeAdjustmentModal() {
            const modal = document.getElementById('adjustmentModal');
            const content = modal.querySelector('.modal-content');
            modal.style.opacity = '0';
            content.style.transform = 'scale(0.95)';
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }

        // Alerts Modal Functions
        function openAlertsModal() {
            const modal = document.getElementById('alertsModal');
            const content = modal.querySelector('.modal-content');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.style.opacity = '1';
                content.style.transform = 'scale(1)';
            }, 10);
        }

        function closeAlertsModal() {
            const modal = document.getElementById('alertsModal');
            const content = modal.querySelector('.modal-content');
            modal.style.opacity = '0';
            content.style.transform = 'scale(0.95)';
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }

        // Acknowledge single alert
        async function acknowledgeAlert(alertId) {
            try {
                const response = await fetch('acknowledge_alert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ alert_id: alertId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reload page to update alerts
                    window.location.reload();
                } else {
                    alert('Failed to acknowledge alert: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to acknowledge alert');
            }
        }

        // Acknowledge all alerts
        async function acknowledgeAllAlerts() {
            if (!confirm('Are you sure you want to acknowledge all alerts?')) {
                return;
            }
            
            try {
                const response = await fetch('acknowledge_alert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ acknowledge_all: true })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reload page to update alerts
                    window.location.reload();
                } else {
                    alert('Failed to acknowledge alerts: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to acknowledge alerts');
            }
        }
        
        // Add keyboard support for modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('adjustmentModal');
                if (!modal.classList.contains('hidden')) {
                    closeAdjustmentModal();
                }
            }
        });
        
        // Add loading state to buttons
        const buttons = document.querySelectorAll('button[type="submit"]');
        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.form && this.form.checkValidity()) {
                    this.disabled = true;
                    this.innerHTML = '<svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                }
            });
        });
        
        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe all cards
        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeHistoryModal();
            }
        });

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Update time immediately and then every second
            updateTime();
            setInterval(updateTime, 1000);
            
            // Initialize night mode
            initNightMode();
            
            // Initialize profile dropdown
            initProfileDropdown();
            
            // Add smooth transitions to all stat cards
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
