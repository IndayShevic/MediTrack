<?php
// Inventory Management Functions for MediTrack
// Add these functions to your config/db.php file

/**
 * Log an inventory transaction
 */
function logInventoryTransaction(
    int $medicineId,
    ?int $batchId,
    string $transactionType, // 'IN', 'OUT', 'ADJUSTMENT', 'TRANSFER', 'EXPIRED', 'DAMAGED'
    int $quantity,
    string $referenceType, // 'BATCH_RECEIVED', 'REQUEST_DISPENSED', 'WALKIN_DISPENSED', etc.
    ?int $referenceId = null,
    ?string $notes = null,
    ?int $createdBy = null
): int {
    $createdBy = $createdBy ?? current_user()['id'] ?? 1;
    
    $stmt = db()->prepare('
        INSERT INTO inventory_transactions 
        (medicine_id, batch_id, transaction_type, quantity, reference_type, reference_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $medicineId, $batchId, $transactionType, $quantity,
        $referenceType, $referenceId, $notes, $createdBy
    ]);
    
    return (int)db()->lastInsertId();
}

/**
 * Get inventory summary for all medicines
 */
function getInventorySummary(): array {
    $stmt = db()->query('SELECT * FROM inventory_summary ORDER BY medicine_name');
    return $stmt->fetchAll() ?: [];
}

/**
 * Get inventory transactions for a specific medicine
 */
function getMedicineTransactions(int $medicineId, ?string $startDate = null, ?string $endDate = null): array {
    $sql = '
        SELECT 
            it.*,
            m.name as medicine_name,
            mb.batch_code,
            u.first_name as created_by_name,
            u.last_name as created_by_lastname
        FROM inventory_transactions it
        JOIN medicines m ON it.medicine_id = m.id
        LEFT JOIN medicine_batches mb ON it.batch_id = mb.id
        LEFT JOIN users u ON it.created_by = u.id
        WHERE it.medicine_id = ?
    ';
    
    $params = [$medicineId];
    
    if ($startDate) {
        $sql .= ' AND DATE(it.created_at) >= ?';
        $params[] = $startDate;
    }
    
    if ($endDate) {
        $sql .= ' AND DATE(it.created_at) <= ?';
        $params[] = $endDate;
    }
    
    $sql .= ' ORDER BY it.created_at DESC';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get inventory movement report
 */
function getInventoryMovementReport(?string $startDate = null, ?string $endDate = null): array {
    $sql = '
        SELECT 
            m.id as medicine_id,
            m.name as medicine_name,
            m.image_path,
            SUM(CASE WHEN it.transaction_type = "IN" THEN it.quantity ELSE 0 END) as total_in,
            SUM(CASE WHEN it.transaction_type = "OUT" THEN ABS(it.quantity) ELSE 0 END) as total_out,
            SUM(CASE WHEN it.transaction_type = "ADJUSTMENT" THEN it.quantity ELSE 0 END) as adjustments,
            COUNT(it.id) as transaction_count,
            MAX(it.created_at) as last_transaction
        FROM medicines m
        LEFT JOIN inventory_transactions it ON m.id = it.medicine_id
        WHERE m.is_active = 1
    ';
    
    $params = [];
    
    if ($startDate) {
        $sql .= ' AND DATE(it.created_at) >= ?';
        $params[] = $startDate;
    }
    
    if ($endDate) {
        $sql .= ' AND DATE(it.created_at) <= ?';
        $params[] = $endDate;
    }
    
    $sql .= ' GROUP BY m.id, m.name, m.image_path ORDER BY m.name';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get low stock medicines
 */
function getLowStockMedicines(int $threshold = 10): array {
    $stmt = db()->prepare('
        SELECT * FROM inventory_summary 
        WHERE current_stock < ? AND current_stock > 0
        ORDER BY current_stock ASC
    ');
    $stmt->execute([$threshold]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get expiring medicines
 */
function getExpiringMedicines(int $daysAhead = 30): array {
    $stmt = db()->prepare('
        SELECT * FROM inventory_summary 
        WHERE expiring_soon > 0
        ORDER BY expiring_soon DESC
    ');
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/**
 * Get expired medicines
 */
function getExpiredMedicines(): array {
    $stmt = db()->query('
        SELECT * FROM inventory_summary 
        WHERE expired_stock > 0
        ORDER BY expired_stock DESC
    ');
    return $stmt->fetchAll() ?: [];
}

/**
 * Create inventory adjustment
 */
function createInventoryAdjustment(
    int $medicineId,
    ?int $batchId,
    string $adjustmentType,
    int $oldQuantity,
    int $newQuantity,
    string $reason,
    ?int $adjustedBy = null
): int {
    $adjustedBy = $adjustedBy ?? current_user()['id'] ?? 1;
    $difference = $newQuantity - $oldQuantity;
    
    // Log the adjustment transaction
    logInventoryTransaction(
        $medicineId,
        $batchId,
        'ADJUSTMENT',
        $difference,
        'ADJUSTMENT',
        null,
        "Adjustment: {$reason} (Old: {$oldQuantity}, New: {$newQuantity})",
        $adjustedBy
    );
    
    // Insert adjustment record
    $stmt = db()->prepare('
        INSERT INTO inventory_adjustments 
        (medicine_id, batch_id, adjustment_type, old_quantity, new_quantity, difference, reason, adjusted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $medicineId, $batchId, $adjustmentType, $oldQuantity, 
        $newQuantity, $difference, $reason, $adjustedBy
    ]);
    
    return (int)db()->lastInsertId();
}

/**
 * Get inventory dashboard statistics
 */
function getInventoryDashboardStats(): array {
    $stats = [
        'total_medicines' => 0,
        'low_stock_count' => 0,
        'expiring_soon_count' => 0,
        'expired_count' => 0,
        'total_transactions_today' => 0,
        'total_dispensed_today' => 0,
        'total_received_today' => 0
    ];
    
    try {
        // Total medicines
        $stmt = db()->query('SELECT COUNT(*) FROM medicines WHERE is_active = 1');
        $stats['total_medicines'] = (int)$stmt->fetchColumn();
        
        // Low stock count
        $stmt = db()->query('SELECT COUNT(*) FROM inventory_summary WHERE is_low_stock = 1');
        $stats['low_stock_count'] = (int)$stmt->fetchColumn();
        
        // Expiring soon count
        $stmt = db()->query('SELECT COUNT(*) FROM inventory_summary WHERE expiring_soon > 0');
        $stats['expiring_soon_count'] = (int)$stmt->fetchColumn();
        
        // Expired count
        $stmt = db()->query('SELECT COUNT(*) FROM inventory_summary WHERE expired_stock > 0');
        $stats['expired_count'] = (int)$stmt->fetchColumn();
        
        // Today's transactions
        $stmt = db()->query('
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN transaction_type = "OUT" THEN ABS(quantity) ELSE 0 END) as total_dispensed,
                SUM(CASE WHEN transaction_type = "IN" THEN quantity ELSE 0 END) as total_received
            FROM inventory_transactions 
            WHERE DATE(created_at) = CURDATE()
        ');
        $todayStats = $stmt->fetch();
        if ($todayStats) {
            $stats['total_transactions_today'] = (int)$todayStats['total_transactions'];
            $stats['total_dispensed_today'] = (int)$todayStats['total_dispensed'];
            $stats['total_received_today'] = (int)$todayStats['total_received'];
        }
        
    } catch (Throwable $e) {
        error_log("Inventory dashboard stats error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Enhanced FEFO allocation with transaction logging
 */
function fefoAllocateWithLogging(int $medicineId, int $quantity, int $requestId = 0, string $referenceType = 'REQUEST_DISPENSED'): int {
    if ($quantity <= 0) return 0;
    
    $pdo = db();
    $pdo->beginTransaction();
    
    try {
        $allocated = 0;
        $q = $pdo->prepare('
            SELECT id, quantity_available 
            FROM medicine_batches 
            WHERE medicine_id = ? AND quantity_available > 0 AND expiry_date > CURDATE() 
            ORDER BY expiry_date ASC, id ASC 
            FOR UPDATE
        ');
        $q->execute([$medicineId]);
        
        while ($row = $q->fetch()) {
            if ($allocated >= $quantity) break;
            
            $take = min((int)$row['quantity_available'], $quantity - $allocated);
            if ($take <= 0) continue;
            
            // Update batch quantity
            $upd = $pdo->prepare('UPDATE medicine_batches SET quantity_available = quantity_available - ? WHERE id = ?');
            $upd->execute([$take, (int)$row['id']]);
            
            // Log the transaction
            logInventoryTransaction(
                $medicineId,
                (int)$row['id'],
                'OUT',
                -$take, // Negative for OUT
                $referenceType,
                $requestId,
                "Dispensed {$take} units from batch",
                current_user()['id'] ?? 1
            );
            
            // Create fulfillment record if request ID provided
            if ($requestId > 0) {
                $ins = $pdo->prepare('INSERT INTO request_fulfillments (request_id, batch_id, quantity) VALUES (?,?,?)');
                $ins->execute([$requestId, (int)$row['id'], $take]);
            }
            
            $allocated += $take;
        }
        
        $pdo->commit();
        return $allocated;
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("FEFO allocation error: " . $e->getMessage());
        return 0;
    }
}
?>
