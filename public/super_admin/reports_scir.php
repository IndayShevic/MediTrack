<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';

$user = current_user();
$medicines = db()->query("SELECT id, name FROM medicines WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch report settings
$stmt = db()->query("SELECT * FROM report_settings LIMIT 1");
$settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];

$center_name = $settings['center_name'] ?? 'BASDASCU HEALTH CENTER';
$municipality = $settings['municipality'] ?? 'LOON';
$province = $settings['province'] ?? 'Bohol';
$rhu_cho = $settings['rhu_cho'] ?? 'RHU 1 - LOON';
$prepared_by = !empty($settings['bhw_name']) ? $settings['bhw_name'] : trim(($user['first_name'] ?? 'Super') . ' ' . ($user['last_name'] ?? 'Admin'));
$submitted_to = !empty($settings['rural_staff']) ? $settings['rural_staff'] : '______________________';
$approved_by = !empty($settings['municipal_staff']) ? $settings['municipal_staff'] : '______________________';

$report_data = null;
$dispensing_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['medicine_id'])) {
    $medicine_id = $_GET['medicine_id'];
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-t');
    
    // Get Medicine Details
    $medicine = db()->prepare("SELECT * FROM medicines WHERE id = ?");
    $medicine->execute([$medicine_id]);
    $med_info = $medicine->fetch(PDO::FETCH_ASSOC);
    
    if ($med_info) {
        // 1. Calculate SCIR Data
        
        // Received Stock (In Period)
        $stmt = db()->prepare("SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE medicine_id = ? AND DATE(received_at) BETWEEN ? AND ?");
        $stmt->execute([$medicine_id, $date_from, $date_to]);
        $received = (int)$stmt->fetchColumn();
        
        // Dispensed Stock (In Period)
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(rf.quantity), 0) 
            FROM request_fulfillments rf 
            JOIN requests r ON rf.request_id = r.id 
            WHERE r.medicine_id = ? 
            AND r.status IN ('claimed', 'approved') 
            AND DATE(rf.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$medicine_id, $date_from, $date_to]);
        $dispensed = (int)$stmt->fetchColumn();
        
        // Current Stock (Now)
        $stmt = db()->prepare("SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE medicine_id = ?");
        $stmt->execute([$medicine_id]);
        $current_stock = (int)$stmt->fetchColumn();
        
        // To get Beginning Balance, we need to work backwards from current stock or forwards from zero.
        // Let's try working forwards: Sum of ALL IN - Sum of ALL OUT before date_from
        
        // Total In before date_from
        $stmt = db()->prepare("SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE medicine_id = ? AND DATE(received_at) < ?");
        $stmt->execute([$medicine_id, $date_from]);
        $total_in_before = (int)$stmt->fetchColumn();
        
        // Total Out before date_from
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(rf.quantity), 0) 
            FROM request_fulfillments rf 
            JOIN requests r ON rf.request_id = r.id 
            WHERE r.medicine_id = ? 
            AND r.status IN ('claimed', 'approved') 
            AND DATE(rf.created_at) < ?
        ");
        $stmt->execute([$medicine_id, $date_from]);
        $total_out_before = (int)$stmt->fetchColumn();
        
        $beginning_balance = $total_in_before - $total_out_before;
        $total_stock = $beginning_balance + $received;
        $ending_balance = $total_stock - $dispensed;
        
        $report_data = [
            'medicine' => $med_info['name'],
            'unit' => $med_info['unit'] ?? 'pcs',
            'beginning' => $beginning_balance,
            'received' => $received,
            'total' => $total_stock,
            'consumed' => $dispensed,
            'ending' => $ending_balance
        ];
        
        // 2. Get Dispensing Log
        $stmt = db()->prepare("
            SELECT 
                r.id,
                CONCAT(res.first_name, ' ', res.last_name) as resident_name,
                rf.quantity as quantity_received,
                DATE_FORMAT(rf.created_at, '%M %d, %Y') as date_dispensed,
                CONCAT(u.first_name, ' ', u.last_name) as bhw_name,
                r.reason as remarks
            FROM request_fulfillments rf
            JOIN requests r ON rf.request_id = r.id
            JOIN residents res ON r.resident_id = res.id
            LEFT JOIN users u ON r.bhw_id = u.id
            WHERE r.medicine_id = ? 
            AND r.status IN ('claimed', 'approved')
            AND DATE(rf.created_at) BETWEEN ? AND ?
            ORDER BY rf.created_at ASC
        ");
        $stmt->execute([$medicine_id, $date_from, $date_to]);
        $dispensing_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCIR & Dispensing Log · MediTrack</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: A4; margin: 1cm; }
            body { background: white; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            .report-page { width: 100%; max-width: none; box-shadow: none; border: none; margin: 0; padding: 0; }
            header, nav, aside { display: none; }
        }
        .report-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
    
    <div class="no-print">
        <?php render_super_admin_sidebar(['current_page' => 'reports_scir.php', 'user_data' => $user]); ?>
    </div>

    <main class="ml-0 md:ml-64 transition-all duration-300">
        <!-- Form Section -->
        <div class="no-print p-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
                <h1 class="text-2xl font-bold mb-6">Generate SCIR & Dispensing Log</h1>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Medicine</label>
                        <select name="medicine_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Choose Medicine --</option>
                            <?php foreach ($medicines as $m): ?>
                                <option value="<?php echo $m['id']; ?>" <?php echo (isset($_GET['medicine_id']) && $_GET['medicine_id'] == $m['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                        <input type="date" name="date_from" value="<?php echo $_GET['date_from'] ?? date('Y-m-01'); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                        <input type="date" name="date_to" value="<?php echo $_GET['date_to'] ?? date('Y-m-t'); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="col-span-4 flex justify-end gap-3 mt-4">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium shadow-sm">
                            Generate Report
                        </button>
                        <?php if ($report_data): ?>
                            <button type="button" onclick="window.print()" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium shadow-sm">
                                Print Report
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report_data): ?>
        <!-- PAGE 1: SCIR -->
        <div class="report-page mb-8">
            <div class="text-center mb-8">
                <h4 class="text-sm font-bold uppercase">Republic of the Philippines</h4>
                <h3 class="text-lg font-bold uppercase"><?php echo htmlspecialchars($center_name); ?></h3>
                <p class="text-sm uppercase"><?php echo htmlspecialchars($rhu_cho . ', ' . $province); ?></p>
                <div class="border-b-2 border-black w-full my-4"></div>
                <h2 class="text-xl font-bold uppercase mb-2">Stock Consumption & Inventory Report (SCIR)</h2>
                <p class="text-sm">Period: <?php echo date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)); ?></p>
            </div>

            <div class="mb-8">
                <table class="w-full border-collapse border border-black">
                    <tr class="bg-gray-100">
                        <th class="border border-black p-2 text-left w-1/3">Item / Medicine</th>
                        <th class="border border-black p-2 text-left"><?php echo htmlspecialchars($report_data['medicine']); ?></th>
                    </tr>
                    <tr>
                        <td class="border border-black p-2">Unit of Measurement</td>
                        <td class="border border-black p-2"><?php echo htmlspecialchars($report_data['unit']); ?></td>
                    </tr>
                </table>
            </div>

            <div class="mb-8">
                <table class="w-full border-collapse border border-black text-center">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border border-black p-3">Beginning Stock</th>
                            <th class="border border-black p-3">Received</th>
                            <th class="border border-black p-3">Total Stock</th>
                            <th class="border border-black p-3">Consumed</th>
                            <th class="border border-black p-3">Ending Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="border border-black p-4 text-lg"><?php echo number_format($report_data['beginning']); ?></td>
                            <td class="border border-black p-4 text-lg"><?php echo number_format($report_data['received']); ?></td>
                            <td class="border border-black p-4 text-lg font-bold"><?php echo number_format($report_data['total']); ?></td>
                            <td class="border border-black p-4 text-lg"><?php echo number_format($report_data['consumed']); ?></td>
                            <td class="border border-black p-4 text-lg font-bold"><?php echo number_format($report_data['ending']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Signatories -->
            <div class="grid grid-cols-3 gap-8 mt-16">
                <div class="text-center">
                    <p class="text-sm font-bold mb-8">Prepared By:</p>
                    <div class="border-b border-black w-3/4 mx-auto mb-1"></div>
                    <p class="font-bold uppercase text-sm"><?php echo htmlspecialchars($prepared_by); ?></p>
                    <p class="text-xs">BHW / Midwife</p>
                </div>
                <div class="text-center">
                    <p class="text-sm font-bold mb-8">Submitted To:</p>
                    <div class="border-b border-black w-3/4 mx-auto mb-1"></div>
                    <p class="font-bold uppercase text-sm"><?php echo htmlspecialchars($submitted_to); ?></p>
                    <p class="text-xs">Rural Health Nurse / RHU Staff</p>
                </div>
                <div class="text-center">
                    <p class="text-sm font-bold mb-8">Approved By:</p>
                    <div class="border-b border-black w-3/4 mx-auto mb-1"></div>
                    <p class="font-bold uppercase text-sm"><?php echo htmlspecialchars($approved_by); ?></p>
                    <p class="text-xs">Municipal Health Officer</p>
                </div>
            </div>
        </div>

        <!-- PAGE 2: Dispensing Log -->
        <div class="report-page page-break">
            <div class="text-center mb-8">
                <h4 class="text-sm font-bold uppercase">Republic of the Philippines</h4>
                <h3 class="text-lg font-bold uppercase"><?php echo htmlspecialchars($center_name); ?></h3>
                <div class="border-b-2 border-black w-full my-4"></div>
                <h2 class="text-xl font-bold uppercase mb-2">Dispensing Log</h2>
                <p class="text-sm font-bold mb-1">Medicine: <?php echo htmlspecialchars($report_data['medicine']); ?></p>
                <p class="text-xs">Period: <?php echo date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)); ?></p>
            </div>

            <table class="w-full border-collapse border border-black text-sm">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-black p-2 text-left">Date Dispensed</th>
                        <th class="border border-black p-2 text-left">Resident Name</th>
                        <th class="border border-black p-2 text-center">Qty</th>
                        <th class="border border-black p-2 text-left">On-duty BHW</th>
                        <th class="border border-black p-2 text-left">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dispensing_log)): ?>
                        <tr>
                            <td colspan="5" class="border border-black p-4 text-center text-gray-500">No dispensing records found for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dispensing_log as $log): ?>
                        <tr>
                            <td class="border border-black p-2"><?php echo htmlspecialchars($log['date_dispensed']); ?></td>
                            <td class="border border-black p-2 font-medium"><?php echo htmlspecialchars($log['resident_name']); ?></td>
                            <td class="border border-black p-2 text-center"><?php echo htmlspecialchars($log['quantity_received']); ?></td>
                            <td class="border border-black p-2"><?php echo htmlspecialchars($log['bhw_name'] ?? 'N/A'); ?></td>
                            <td class="border border-black p-2 text-xs"><?php echo htmlspecialchars($log['remarks'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="mt-8 text-xs text-gray-500 text-right">
                Generated on: <?php echo date('F d, Y h:i A'); ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
