-- Inventory Transaction System for MediTrack
-- This creates a comprehensive inventory tracking system

-- 1. Inventory Transactions Table (Main tracking table)
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    batch_id INT NULL, -- NULL for adjustments, transfers, etc.
    transaction_type ENUM('IN', 'OUT', 'ADJUSTMENT', 'TRANSFER', 'EXPIRED', 'DAMAGED') NOT NULL,
    quantity INT NOT NULL, -- Positive for IN, negative for OUT
    reference_type ENUM('BATCH_RECEIVED', 'REQUEST_DISPENSED', 'WALKIN_DISPENSED', 'ADJUSTMENT', 'TRANSFER', 'EXPIRY', 'DAMAGE') NOT NULL,
    reference_id INT NULL, -- Links to requests, batches, etc.
    notes TEXT NULL,
    created_by INT NOT NULL, -- User who performed the transaction
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_inv_trans_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_trans_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_trans_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_medicine_date (medicine_id, created_at),
    INDEX idx_type_date (transaction_type, created_at),
    INDEX idx_reference (reference_type, reference_id)
) ENGINE=InnoDB;

-- 2. Inventory Adjustments Table (For manual corrections)
CREATE TABLE IF NOT EXISTS inventory_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    batch_id INT NULL,
    adjustment_type ENUM('CORRECTION', 'PHYSICAL_COUNT', 'SYSTEM_ERROR', 'THEFT', 'DAMAGE') NOT NULL,
    old_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    difference INT NOT NULL, -- Calculated: new_quantity - old_quantity
    reason TEXT NOT NULL,
    adjusted_by INT NOT NULL,
    adjusted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_adj_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    CONSTRAINT fk_adj_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_adj_user FOREIGN KEY (adjusted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Inventory Reports View (For easy reporting)
CREATE OR REPLACE VIEW inventory_summary AS
SELECT 
    m.id as medicine_id,
    m.name as medicine_name,
    m.description,
    m.image_path,
    
    -- Current Stock
    COALESCE(SUM(CASE 
        WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() 
        THEN mb.quantity_available 
        ELSE 0 
    END), 0) as current_stock,
    
    -- Total Received (All time)
    COALESCE(SUM(CASE 
        WHEN it.transaction_type = 'IN' 
        THEN it.quantity 
        ELSE 0 
    END), 0) as total_received,
    
    -- Total Dispensed (All time)
    COALESCE(SUM(CASE 
        WHEN it.transaction_type = 'OUT' 
        THEN ABS(it.quantity) 
        ELSE 0 
    END), 0) as total_dispensed,
    
    -- Expired Stock
    COALESCE(SUM(CASE 
        WHEN mb.expiry_date <= CURDATE() AND mb.quantity_available > 0
        THEN mb.quantity_available 
        ELSE 0 
    END), 0) as expired_stock,
    
    -- Expiring Soon (within 30 days)
    COALESCE(SUM(CASE 
        WHEN mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND mb.quantity_available > 0
        THEN mb.quantity_available 
        ELSE 0 
    END), 0) as expiring_soon,
    
    -- Low Stock (less than 10 units)
    CASE 
        WHEN COALESCE(SUM(CASE 
            WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() 
            THEN mb.quantity_available 
            ELSE 0 
        END), 0) < 10 THEN 1 
        ELSE 0 
    END as is_low_stock,
    
    -- Last Transaction Date
    MAX(it.created_at) as last_transaction_date,
    
    -- Number of Batches
    COUNT(DISTINCT mb.id) as total_batches,
    
    -- Active Batches (not expired, has stock)
    COUNT(DISTINCT CASE 
        WHEN mb.quantity_available > 0 AND mb.expiry_date > CURDATE() 
        THEN mb.id 
    END) as active_batches

FROM medicines m
LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
LEFT JOIN inventory_transactions it ON m.id = it.medicine_id
WHERE m.is_active = 1
GROUP BY m.id, m.name, m.description, m.image_path;
