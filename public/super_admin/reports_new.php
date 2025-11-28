<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';

$user = current_user();

// Get report parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$export = $_GET['export'] ?? '';

// Get medicines with their inventory data
$medicines = [];
$report_data = [];

// Simplified approach - just show current inventory status
try {
    // Get all active medicines
    $sql = "SELECT 
        m.id,
        m.name as medicine_name,
        m.generic_name,
        m.dosage,
        m.form,
        m.unit,
        COALESCE(SUM(mb.quantity), 0) as total_received_ever,
        COALESCE(SUM(mb.quantity_available), 0) as current_stock
    FROM medicines m
    LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id
    WHERE m.is_active = 1
    GROUP BY m.id, m.name, m.generic_name, m.dosage, m.form, m.unit
    HAVING current_stock > 0 OR total_received_ever > 0
    ORDER BY m.name ASC";
    
    $stmt = db()->query($sql);
    $medicines = $stmt->fetchAll() ?: [];
    
    // For each medicine, calculate during the selected period
    foreach ($medicines as $med) {
        $med_id = $med['id'];
        
        // (B) Quantity Received during period
        $received_sql = "SELECT COALESCE(SUM(quantity), 0) as received
            FROM medicine_batches
            WHERE medicine_id = ?
            AND DATE(received_at) BETWEEN ? AND ?";
        $received_stmt = db()->prepare($received_sql);
        $received_stmt->execute([$med_id, $date_from, $date_to]);
        $quantity_received = (int)$received_stmt->fetch()['received'];
        
        // (D) Quantity Consumed during period
        $consumed_sql = "SELECT COALESCE(SUM(rf.quantity), 0) as consumed
            FROM request_fulfillments rf
            INNER JOIN requests r ON rf.request_id = r.id
            WHERE r.medicine_id = ?
            AND r.status IN ('claimed', 'approved')
            AND DATE(COALESCE(rf.created_at, r.updated_at)) BETWEEN ? AND ?";
        $consumed_stmt = db()->prepare($consumed_sql);
        $consumed_stmt->execute([$med_id, $date_from, $date_to]);
        $quantity_consumed = (int)$consumed_stmt->fetch()['consumed'];
        
        // (E) Ending Balance = current stock now
        $ending_balance = (int)$med['current_stock'];
        
        // (A) Beginning Balance = Ending - Received + Consumed  
        $beginning_balance = max(0, $ending_balance - $quantity_received + $quantity_consumed);
        
        // (C) Total Stock = A + B
        $total_stock = $beginning_balance + $quantity_received;
        
        // (F) Remarks - Get batch info
        $remarks_sql = "SELECT batch_code, expiry_date
            FROM medicine_batches
            WHERE medicine_id = ?
            AND quantity_available > 0
            ORDER BY expiry_date ASC
            LIMIT 1";
        $remarks_stmt = db()->prepare($remarks_sql);
        $remarks_stmt->execute([$med_id]);
        $batch_info = $remarks_stmt->fetch();
        
        $remarks = '';
        if ($batch_info) {
            $remarks = 'Batch: ' . $batch_info['batch_code'] . ', Exp: ' . date('m/Y', strtotime($batch_info['expiry_date']));
            if ($ending_balance == 0) {
                $remarks .= ' - OUT OF STOCK';
            } elseif ($ending_balance < 50) {
                $remarks .= ' - LOW STOCK';
            }
        } elseif ($ending_balance == 0) {
            $remarks = 'OUT OF STOCK - FOR RE-ORDER';
        } else {
            $remarks = 'No active batch';
        }
        
        // Add to report
        $report_data[] = [
            'id' => $med_id,
            'medicine_name' => $med['medicine_name'],
            'generic_name' => $med['generic_name'],
            'dosage' => $med['dosage'],
            'form' => $med['form'],
            'unit' => $med['unit'],
            'beginning_balance' => $beginning_balance,
            'quantity_received' => $quantity_received,
            'total_stock' => $total_stock,
            'quantity_consumed' => $quantity_consumed,
            'ending_balance' => $ending_balance,
            'remarks' => $remarks
        ];
    }
    
} catch (Throwable $e) {
    error_log("SCIR Report Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// Handle CSV Export
if ($export === 'csv' && !empty($report_data)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="SCIR_Report_' . date('Ymd', strtotime($date_from)) . '_to_' . date('Ymd', strtotime($date_to)) . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Header
    fputcsv($output, ['STOCK CONSUMPTION AND INVENTORY REPORT (SCIR)']);
    fputcsv($output, ['Name of Health Facility', 'BASDASCU HEALTH CENTER']);
    fputcsv($output, ['Rural Health Unit (RHU) / City Health Office (CHO)', 'RHU 1 - LOON']);
    fputcsv($output, ['City / Municipality', 'LOON']);
    fputcsv($output, ['Province', 'Bohol']);
    fputcsv($output, ['Reporting Period', date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to))]);
    fputcsv($output, []);
    
    // Table headers
    fputcsv($output, [
        '#',
        'Item Description (Generic Name, Strength, Form)',
        'Unit of Measure',
        '(A) Beginning Balance',
        '(B) Quantity Received',
        '(C) Total Stock (A+B)',
        '(D) Quantity Consumed/Dispensed',
        '(E) Ending Balance (C-D)',
        '(F) Remarks'
    ]);
    
    // Data rows
    $row_num = 1;
    foreach ($report_data as $row) {
        $item_desc = $row['generic_name'] ?: $row['medicine_name'];
        if ($row['dosage']) $item_desc .= ' ' . $row['dosage'];
        if ($row['form']) $item_desc .= ' ' . $row['form'];
        
        fputcsv($output, [
            $row_num++,
            $item_desc,
            $row['unit'] ?: 'Unit',
            $row['beginning_balance'],
            $row['quantity_received'],
            $row['total_stock'],
            $row['quantity_consumed'],
            $row['ending_balance'],
            $row['remarks']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Prepared By:', '', '', 'Submitted To:', '', '', 'Noted/Approved By:']);
    fputcsv($output, ['Name: _________________', '', '', 'Name: _________________', '', '', 'Name: _________________']);
    fputcsv($output, ['Designation: BHW/Midwife', '', '', 'Designation: RHU Staff', '', '', 'Designation: Municipal Health Officer']);
    fputcsv($output, ['Date: ' . date('F d, Y')]);
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCIR Report · Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .main-content { margin-left: 0 !important; }
            table { page-break-inside: avoid; }
            .official-header { display: block !important; }
            @page { margin: 0.5cm; }
        }
        
        .official-header {
            display: none;
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 3px solid #000;
            padding-bottom: 1rem;
        }
        
        .scir-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        
        .scir-table th,
        .scir-table td {
            border: 1px solid #000;
            padding: 0.5rem;
            text-align: center;
        }
        
        .scir-table th {
            background-color: #e5e7eb;
            font-weight: 700;
            font-size: 0.75rem;
        }
        
        .scir-table td:nth-child(2) {
            text-align: left;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php render_super_admin_sidebar(['current_page' => 'reports_scir.php', 'user_data' => $user]); ?>
    
    <main class="main-content ml-64">
        <div class="p-8">
            <!-- Page Header -->
            <div class="mb-8 no-print">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">📋 Stock Consumption & Inventory Report (SCIR)</h1>
                <p class="text-gray-600">Official DOH/LGU Monthly Medicine Report</p>
            </div>
            
            <!-- Filter Form -->
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 mb-8 no-print">
                <h2 class="text-xl font-bold text-gray-900 mb-6">Select Reporting Period</h2>
                
                <form method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date From *</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date To *</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                   class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between pt-6 border-t border-gray-200">
                        <button type="submit" class="px-8 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl hover:from-blue-700 hover:to-indigo-700 font-semibold flex items-center space-x-2 shadow-lg">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Generate Report</span>
                        </button>
                        
                        <?php if (!empty($report_data)): ?>
                        <div class="flex space-x-3">
                            <button type="button" onclick="window.print()" class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 font-semibold flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                </svg>
                                <span>Print</span>
                            </button>
                            
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                               class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 font-semibold flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <span>Export CSV</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($report_data)): ?>
            <!-- Report Content -->
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8">
                <!-- Official Header (visible on print) -->
                <div class="official-header">
                    <h1 style="font-size: 16px; font-weight: bold; margin-bottom: 8px;">REPUBLIC OF THE PHILIPPINES</h1>
                    <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">BASDASCU HEALTH CENTER</h2>
                    <h3 style="font-size: 14px; font-weight: 600;">RHU 1 - LOON, BOHOL</h3>
                </div>
                
                <!-- Report Title -->
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-900 uppercase mb-4">Stock Consumption and Inventory Report (SCIR)</h2>
                    <div class="grid grid-cols-2 gap-4 max-w-2xl mx-auto text-sm">
                        <div class="text-left"><strong>Name of Health Facility:</strong></div>
                        <div class="text-left">BASDASCU HEALTH CENTER</div>
                        
                        <div class="text-left"><strong>RHU / CHO:</strong></div>
                        <div class="text-left">RHU 1 - LOON</div>
                        
                        <div class="text-left"><strong>City / Municipality:</strong></div>
                        <div class="text-left">LOON</div>
                        
                        <div class="text-left"><strong>Province:</strong></div>
                        <div class="text-left">Bohol</div>
                        
                        <div class="text-left"><strong>Reporting Period:</strong></div>
                        <div class="text-left"><?php echo date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)); ?></div>
                    </div>
                </div>
                
                <!-- SCIR Table -->
                <div class="overflow-x-auto mb-8">
                    <table class="scir-table">
                        <thead>
                            <tr>
                                <th style="width: 3%;">#</th>
                                <th style="width: 25%;">ITEM DESCRIPTION<br>(Generic Name, Strength, Form)</th>
                                <th style="width: 8%;">UNIT OF<br>MEASURE</th>
                                <th style="width: 10%;">(A)<br>BEGINNING<br>BALANCE</th>
                                <th style="width: 10%;">(B)<br>QUANTITY<br>RECEIVED</th>
                                <th style="width: 10%;">(C)<br>TOTAL STOCK<br>(A+B)</th>
                                <th style="width: 10%;">(D)<br>QUANTITY<br>CONSUMED</th>
                                <th style="width: 10%;">(E)<br>ENDING<br>BALANCE<br>(C-D)</th>
                                <th style="width: 14%;">(F)<br>REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_num = 1;
                            foreach ($report_data as $row): 
                                $item_desc = $row['generic_name'] ?: $row['medicine_name'];
                                if ($row['dosage']) $item_desc .= ' ' . $row['dosage'];
                                if ($row['form']) $item_desc .= ' ' . $row['form'];
                            ?>
                            <tr>
                                <td><?php echo $row_num++; ?></td>
                                <td style="text-align: left; font-weight: 500;"><?php echo htmlspecialchars($item_desc); ?></td>
                                <td><?php echo htmlspecialchars($row['unit'] ?: 'Unit'); ?></td>
                                <td><?php echo number_format($row['beginning_balance']); ?></td>
                                <td><?php echo number_format($row['quantity_received']); ?></td>
                                <td><strong><?php echo number_format($row['total_stock']); ?></strong></td>
                                <td><?php echo number_format($row['quantity_consumed']); ?></td>
                                <td><strong><?php echo number_format($row['ending_balance']); ?></strong></td>
                                <td style="font-size: 0.75rem;"><?php echo htmlspecialchars($row['remarks']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Accountability Section -->
                <div class="mt-12 grid grid-cols-3 gap-8 text-sm">
                    <div class="text-center">
                        <p class="font-semibold mb-12">Prepared By:</p>
                        <div class="border-t-2 border-black pt-2 mb-2">
                            <p class="font-bold"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                        </div>
                        <p class="text-xs">BHW / Midwife</p>
                        <p class="text-xs mt-4">Date: <?php echo date('F d, Y'); ?></p>
                    </div>
                    
                    <div class="text-center">
                        <p class="font-semibold mb-12">Submitted To:</p>
                        <div class="border-t-2 border-black pt-2 mb-2">
                            <p class="font-bold">_____________________</p>
                        </div>
                        <p class="text-xs">Rural Health Nurse / RHU Staff</p>
                        <p class="text-xs mt-4">Date: _______________</p>
                    </div>
                    
                    <div class="text-center">
                        <p class="font-semibold mb-12">Noted / Approved By:</p>
                        <div class="border-t-2 border-black pt-2 mb-2">
                            <p class="font-bold">_____________________</p>
                        </div>
                        <p class="text-xs">Municipal Health Officer</p>
                        <p class="text-xs mt-4">Date: _______________</p>
                    </div>
                </div>
            </div>
            <?php elseif (isset($_GET['date_from'])): ?>
            <!-- No Data Message -->
            <div class="bg-yellow-50 border-2 border-yellow-200 rounded-2xl p-8 text-center">
                <svg class="w-16 h-16 text-yellow-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <h3 class="text-xl font-bold text-gray-900 mb-2">No Data Available</h3>
                <p class="text-gray-600">No medicine transactions found for the selected period.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
