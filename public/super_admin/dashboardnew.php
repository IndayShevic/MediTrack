<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';

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

$user = current_user();

// Get fresh user data for profile section
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$user_data = $stmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Fetch real dashboard data with error handling
try {
    $total_medicines = db()->query('SELECT COUNT(*) as count FROM medicines WHERE is_active = 1')->fetch()['count'];
} catch (Exception $e) {
    $total_medicines = 0;
}

try {
    // Total stock units (available, non-expired)
    $total_stock_result = db()->query('
        SELECT COALESCE(SUM(quantity_available), 0) as total 
        FROM medicine_batches 
        WHERE quantity_available > 0 AND expiry_date > CURDATE()
    ')->fetch();
    $total_stock_units = (int)($total_stock_result['total'] ?? 0);
} catch (Exception $e) {
    $total_stock_units = 0;
}

try {
    // Low stock medicines (below minimum_stock_level or 0 stock)
    $low_stock_result = db()->query('
        SELECT COUNT(DISTINCT m.id) as count 
        FROM medicines m 
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
        WHERE m.is_active = 1
        AND (mb.expiry_date IS NULL OR mb.expiry_date > CURDATE())
        GROUP BY m.id, m.minimum_stock_level
        HAVING COALESCE(SUM(mb.quantity_available), 0) < COALESCE(m.minimum_stock_level, 10) 
        OR COALESCE(SUM(mb.quantity_available), 0) = 0
    ')->fetchAll();
    $low_stock_medicines = count($low_stock_result);
} catch (Exception $e) {
    $low_stock_medicines = 0;
}

try {
    // Today's dispensed units
    $today_dispensed_result = db()->query('
        SELECT COALESCE(SUM(ABS(quantity)), 0) as total 
        FROM inventory_transactions 
        WHERE transaction_type = "OUT" 
        AND DATE(created_at) = CURDATE()
    ')->fetch();
    $today_dispensed = (int)($today_dispensed_result['total'] ?? 0);
} catch (Exception $e) {
    $today_dispensed = 0;
}

try {
    // Total requests (all time)
    $total_requests = db()->query('SELECT COUNT(*) as count FROM requests')->fetch()['count'];
} catch (Exception $e) {
    $total_requests = 0;
}

try {
    // Pending requests
    $pending_requests = db()->query('SELECT COUNT(*) as count FROM requests WHERE status = "submitted"')->fetch()['count'];
} catch (Exception $e) {
    $pending_requests = 0;
}

// Fetch recent pending requests for notifications
try {
    $recent_pending_requests = db()->query('
        SELECT r.id, r.status, r.created_at,
               CONCAT(IFNULL(u.first_name,"")," ",IFNULL(u.last_name,"")) as requester_name,
               DATE_FORMAT(r.created_at, "%b %d, %Y") as formatted_date
        FROM requests r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status = "submitted"
        ORDER BY r.created_at DESC
        LIMIT 5
    ')->fetchAll();
} catch (Exception $e) {
    $recent_pending_requests = [];
}

// Fetch inventory alerts for notifications
try {
    $inventory_alerts = db()->query('
        SELECT ia.id, ia.severity, ia.message, ia.created_at,
               m.name as medicine_name,
               DATE_FORMAT(ia.created_at, "%b %d, %Y") as formatted_date
        FROM inventory_alerts ia
        JOIN medicines m ON ia.medicine_id = m.id
        WHERE ia.is_acknowledged = FALSE
        ORDER BY 
            CASE ia.severity
                WHEN "critical" THEN 1
                WHEN "high" THEN 2
                WHEN "medium" THEN 3
                ELSE 4
            END,
            ia.created_at DESC
        LIMIT 5
    ')->fetchAll();
    $alerts_count = count($inventory_alerts);
} catch (Exception $e) {
    $inventory_alerts = [];
    $alerts_count = 0;
}

$total_notifications = $pending_requests + $alerts_count;

// Fetch recent activity data with error handling
try {
    $recent_medicines = db()->query('SELECT name, created_at FROM medicines ORDER BY created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_medicines = [];
}

try {
    $recent_users = db()->query('SELECT CONCAT(IFNULL(first_name,"")," ",IFNULL(last_name,"")) as name, role, created_at FROM users WHERE role = "bhw" ORDER BY created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_users = [];
}

try {
    $recent_requests = db()->query('SELECT r.id, CONCAT(IFNULL(res.first_name,"")," ",IFNULL(res.last_name,"")) as resident_name, r.status, r.created_at FROM requests r LEFT JOIN residents res ON r.resident_id = res.id ORDER BY r.created_at DESC LIMIT 3')->fetchAll();
} catch (Exception $e) {
    $recent_requests = [];
}

// Fetch comprehensive chart data with error handling
try {
    // Last 30 days: Requests and Dispensed (for combination chart)
    $request_dispensed_trends = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        
        // Get request count for this date
        $req_stmt = db()->prepare('SELECT COUNT(*) as count FROM requests WHERE DATE(created_at) = ?');
        $req_stmt->execute([$date]);
        $req_result = $req_stmt->fetch();
        $request_count = (int)($req_result['count'] ?? 0);
        
        // Get dispensed units for this date
        $disp_stmt = db()->prepare('
            SELECT COALESCE(SUM(ABS(quantity)), 0) as total 
            FROM inventory_transactions 
            WHERE transaction_type = "OUT" AND DATE(created_at) = ?
        ');
        $disp_stmt->execute([$date]);
        $disp_result = $disp_stmt->fetch();
        $dispensed_units = (int)($disp_result['total'] ?? 0);
        
        $request_dispensed_trends[] = [
            'date' => $date,
            'request_count' => $request_count,
            'dispensed_units' => $dispensed_units
        ];
    }
} catch (Exception $e) {
    $request_dispensed_trends = [];
}

try {
    // Top medicines by dispensed quantity (last 30 days)
    $top_dispensed_medicines = db()->query('
        SELECT 
            m.name,
            COALESCE(SUM(CASE WHEN it.transaction_type = "OUT" THEN ABS(it.quantity) ELSE 0 END), 0) as dispensed_units
        FROM medicines m
        LEFT JOIN inventory_transactions it ON m.id = it.medicine_id 
            AND it.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND it.transaction_type = "OUT"
        WHERE m.is_active = 1
        GROUP BY m.id, m.name
        HAVING dispensed_units > 0
        ORDER BY dispensed_units DESC
        LIMIT 10
    ')->fetchAll();
} catch (Exception $e) {
    $top_dispensed_medicines = [];
}

try {
    // Request status distribution
    $request_status_dist = db()->query('
        SELECT 
            status,
            COUNT(*) as count
        FROM requests
        GROUP BY status
    ')->fetchAll();
} catch (Exception $e) {
    $request_status_dist = [];
}

try {
    // Stock levels distribution (histogram data)
    $stock_distribution = db()->query('
        SELECT 
            CASE 
                WHEN COALESCE(SUM(mb.quantity_available), 0) = 0 THEN "Out of Stock"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 10 THEN "1-10 units"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 50 THEN "11-50 units"
                WHEN COALESCE(SUM(mb.quantity_available), 0) <= 100 THEN "51-100 units"
                ELSE "100+ units"
            END as stock_range,
            COUNT(DISTINCT m.id) as medicine_count
        FROM medicines m
        LEFT JOIN medicine_batches mb ON m.id = mb.medicine_id 
            AND mb.expiry_date > CURDATE()
        WHERE m.is_active = 1
        GROUP BY m.id
    ')->fetchAll();
} catch (Exception $e) {
    $stock_distribution = [];
}

try {
    // Monthly trends (last 6 months)
    $monthly_trends = db()->query('
        SELECT 
            DATE_FORMAT(created_at, "%Y-%m") as month,
            DATE_FORMAT(created_at, "%b %Y") as month_label,
            COUNT(*) as request_count,
            COALESCE((
                SELECT SUM(ABS(quantity)) 
                FROM inventory_transactions 
                WHERE transaction_type = "OUT" 
                AND DATE_FORMAT(created_at, "%Y-%m") = DATE_FORMAT(r.created_at, "%Y-%m")
            ), 0) as dispensed_units
        FROM requests r
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, "%Y-%m"), DATE_FORMAT(created_at, "%b %Y")
        ORDER BY month
    ')->fetchAll();
} catch (Exception $e) {
    $monthly_trends = [];
}

// Greeting
$greeting = 'Welcome back';

// Get current page for active state
// Determine current page for sidebar highlighting.
// When using the unified dashboard shell with ?target=..., prefer the target.
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
if (!empty($_GET['target'])) {
    $targetPath = parse_url((string)$_GET['target'], PHP_URL_PATH);
    if (is_string($targetPath) && $targetPath !== '') {
        $current_page = basename($targetPath);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MediTrack</title>
    <script src="https://cdn.tailwindcss.com" onerror="console.error('Tailwind CSS failed to load')"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onerror="console.error('Font Awesome failed to load')">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" onerror="console.error('Chart.js failed to load')"></script>
    <!-- FullCalendar for announcements calendar and similar views -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js" onerror="console.error('FullCalendar failed to load')"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .sidebar-link {
            transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
        }
        .sidebar-link:hover:not(.active) {
            background: linear-gradient(135deg, #e5e7eb 0%, #e5e7eb 100%);
            color: #4b5563;
        }
        .sidebar-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
        }
        /* Sidebar styles */
        .sidebar {
            width: 16rem; /* w-64 */
        }
        
        /* Prevent white screen - ensure content is always visible */
        body {
            min-height: 100vh;
            background-color: #f9fafb !important;
        }
        #app {
            min-height: 100vh;
            display: flex !important;
            height: auto;
            overflow: visible;
        }
        
        /* Ensure main content can scroll */
        #main-content-area {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            height: auto;
            min-height: calc(100vh - 4rem);
            max-height: none;
        }
        
        #dashboard-main-wrapper {
            height: auto;
            min-height: 100vh;
            overflow: visible;
        }
        
        /* On desktop, allow proper scrolling */
        @media (min-width: 1025px) {
            #app {
                height: 100vh;
                overflow: hidden;
            }
            
            #dashboard-main-wrapper {
                height: 100vh;
                overflow: hidden;
            }
            
            #main-content-area {
                overflow-y: auto;
                height: calc(100vh - 4rem);
                max-height: calc(100vh - 4rem);
            }
        }
        
        /* On mobile and tablet, allow full page scroll */
        @media (max-width: 1024px) {
            #app {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }
            
            #dashboard-main-wrapper {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }
            
            #main-content-area {
                overflow-y: visible;
                height: auto;
                min-height: calc(100vh - 4rem);
                max-height: none;
            }
            
            /* Ensure body can scroll on mobile */
            body {
                overflow-x: hidden;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
        }

        /* Dashboard shell layout: offset main content by sidebar width on desktop
           without affecting other pages that use the shared design system */
        .dashboard-shell #dashboard-main-wrapper {
            margin-left: 280px;
        }
        
        /* Mobile sidebar overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-overlay.show {
            display: block;
        }
        
        /* ============================================
           COMPREHENSIVE RESPONSIVE DESIGN SYSTEM
           Mobile-first approach with fluid layouts
           ============================================ */
        
        /* Base responsive utilities */
        * {
            box-sizing: border-box;
        }
        
        /* Container max-widths for readability */
        .container-responsive {
            width: 100%;
            max-width: 100%;
        }
        
        /* Inner content container for max-width on desktop */
        .content-container {
            width: 100%;
            margin: 0 auto;
        }
        
        /* Fix chart container to prevent jumping */
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Ensure chart containers don't resize */
        div[style*="position: relative"] {
            min-height: 250px;
        }
        
        /* Notification and Profile Dropdowns */
        #notificationDropdown,
        #profileDropdown {
            animation: fadeInDown 0.2s ease-out;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Ensure dropdowns are above other content */
        #notificationDropdown,
        #profileDropdown {
            z-index: 1000;
        }
        
        /* Mobile responsive dropdowns */
        @media (max-width: 640px) {
            #notificationDropdown {
                right: 0;
                left: auto;
                width: calc(100vw - 2rem);
                max-width: 20rem;
            }
            
            #profileDropdown {
                right: 0;
                left: auto;
                width: calc(100vw - 2rem);
                max-width: 16rem;
            }
        }
        
        /* ============================================
           MOBILE: max-width 640px
           - Hamburger sidebar
           - Vertical stacking
           - Full-width buttons/cards
           - Reduced padding, tap-friendly spacing
           ============================================ */
        @media (max-width: 640px) {
            /* Sidebar - Hidden by default, hamburger menu */
            #sidebar-aside {
                display: none !important;
                position: fixed !important;
                left: -16rem !important;
                z-index: 50 !important;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
                height: 100vh !important;
                top: 0 !important;
                will-change: left;
            }
            
            #sidebar-aside.show {
                left: 0 !important;
                display: flex !important;
                visibility: visible !important;
            }
            
            #sidebar-aside.show.hidden {
                display: flex !important;
                visibility: visible !important;
            }
            
            /* Ensure sidebar is visible when shown */
            #sidebar-aside.show,
            #sidebar-aside.show * {
                visibility: visible !important;
            }
            
            #sidebar {
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
                height: 100vh;
                width: 16rem;
                max-width: 85vw;
            }
            
            .sidebar-link {
                min-height: 48px; /* Tap-friendly */
                padding: 0.875rem 1rem;
                font-size: 0.9375rem;
            }
            
            /* Main content - full width, allow scrolling, visible when sidebar is open */
            main {
                margin-left: 0 !important;
                padding: 0.75rem !important; /* Reduced padding */
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                height: auto !important;
                min-height: 100vh !important;
                position: relative !important;
                z-index: 1 !important;
            }
            
            /* When sidebar is open, main content should still be visible */
            #sidebar-aside.show ~ #dashboard-main-wrapper main {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            /* Ensure app container allows scrolling */
            #app {
                height: auto !important;
                min-height: 100vh !important;
                overflow: visible !important;
            }
            
            #dashboard-main-wrapper {
                height: auto !important;
                min-height: 100vh !important;
                overflow: visible !important;
                position: relative !important;
                z-index: 1 !important;
            }
            
            /* Sidebar should slide over content, not push it */
            #sidebar-aside {
                position: fixed !important;
                z-index: 50 !important;
            }
            
            /* Overlay should be behind sidebar but above content */
            .sidebar-overlay {
                z-index: 40 !important;
            }
            
            .dashboard-shell #dashboard-main-wrapper {
                margin-left: 0;
            }
            
            /* Header - compact */
            header {
                padding: 0.625rem 0.75rem !important;
                height: auto !important;
                min-height: 3.5rem;
            }
            
            header h1 {
                font-size: 1rem !important;
            }
            
            header .flex {
                gap: 0.5rem;
            }
            
            /* Grids - single column */
            .grid,
            .grid.md\\:grid-cols-2,
            .grid.lg\\:grid-cols-2,
            .grid.lg\\:grid-cols-3,
            .grid.lg\\:grid-cols-4 {
                grid-template-columns: 1fr !important;
                gap: 0.75rem !important; /* Reduced gap */
            }
            
            /* Cards - full width, reduced padding */
            .bg-white.rounded-xl,
            .card {
                width: 100% !important;
                padding: 0.875rem !important; /* Reduced padding */
                margin-bottom: 0.75rem;
            }
            
            /* Buttons - full width, tap-friendly */
            button:not(.icon-only):not([class*="w-"]),
            .btn:not(.icon-only):not([class*="w-"]) {
                width: 100% !important;
                min-height: 44px; /* iOS tap target */
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
                margin-bottom: 0.5rem;
            }
            
            /* Forms - full width, tap-friendly */
            input,
            select,
            textarea {
                width: 100% !important;
                font-size: 16px !important; /* Prevents zoom on iOS */
                min-height: 44px;
                padding: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            /* Tables - horizontal scroll */
            .table-wrapper,
            .overflow-x-auto {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                -ms-overflow-style: -ms-autohiding-scrollbar;
                width: 100%;
            }
            
            .table-wrapper table,
            .overflow-x-auto table {
                display: table;
                min-width: 600px;
                font-size: 0.875rem;
            }
            
            .table-wrapper table thead,
            .overflow-x-auto table thead {
                display: table-header-group;
            }
            
            .table-wrapper table tbody,
            .overflow-x-auto table tbody {
                display: table-row-group;
            }
            
            .table-wrapper table tr,
            .overflow-x-auto table tr {
                display: table-row;
            }
            
            .table-wrapper table th,
            .table-wrapper table td,
            .overflow-x-auto table th,
            .overflow-x-auto table td {
                display: table-cell;
                padding: 0.5rem 0.375rem;
            }
            
            /* Typography - smaller but readable */
            h1 { font-size: 1.5rem !important; }
            h2 { font-size: 1.25rem !important; }
            h3 { font-size: 1.125rem !important; }
            
            /* Spacing - reduced but consistent */
            .mb-6, .mb-8 { margin-bottom: 1rem !important; }
            .p-4, .p-6, .p-8 { padding: 0.75rem !important; }
        }
        
        /* ============================================
           TABLET: 641px - 1024px
           - Partial sidebar or wider layout
           - 2-column grids
           - Appropriate text/icon scaling
           ============================================ */
        @media (min-width: 641px) and (max-width: 1024px) {
            /* Sidebar - hidden by default, hamburger menu */
            #sidebar-aside {
                display: none !important;
                position: fixed !important;
                left: -16rem !important;
                z-index: 50 !important;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
                height: 100vh !important;
                top: 0 !important;
                will-change: left;
            }
            
            #sidebar-aside.show {
                left: 0 !important;
                display: flex !important;
            }
            
            #sidebar-aside.show.hidden {
                display: flex !important;
            }
            
            #sidebar {
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
                height: 100vh;
                width: 18rem; /* Wider on tablet */
                max-width: 75vw;
            }
            
            .sidebar-link {
                min-height: 48px;
                padding: 1rem;
                font-size: 1rem;
            }
            
            /* Main content - full width */
            main {
                margin-left: 0 !important;
                padding: 1rem 1.25rem !important;
            }
            
            .dashboard-shell #dashboard-main-wrapper {
                margin-left: 0;
            }
            
            /* Header */
            header {
                padding: 0.875rem 1.25rem !important;
            }
            
            header h1 {
                font-size: 1.25rem !important;
            }
            
            /* Grids - 2 columns */
            .grid.md\\:grid-cols-2 {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            .grid.lg\\:grid-cols-3,
            .grid.lg\\:grid-cols-4 {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            /* Cards - 2 columns where appropriate */
            .bg-white.rounded-xl,
            .card {
                padding: 1.25rem !important;
            }
            
            /* Buttons - not full width, but larger */
            button:not(.icon-only):not([class*="w-"]),
            .btn:not(.icon-only):not([class*="w-"]) {
                min-height: 44px;
                padding: 0.875rem 1.5rem;
                font-size: 0.9375rem;
            }
            
            /* Forms */
            input,
            select,
            textarea {
                font-size: 16px !important;
                min-height: 44px;
                padding: 0.875rem;
            }
            
            /* Tables - better spacing */
            .table-wrapper table th,
            .table-wrapper table td,
            .overflow-x-auto table th,
            .overflow-x-auto table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.9375rem;
            }
            
            /* Typography */
            h1 { font-size: 1.875rem !important; }
            h2 { font-size: 1.5rem !important; }
            h3 { font-size: 1.25rem !important; }
            
            /* Spacing */
            .mb-6 { margin-bottom: 1.5rem !important; }
            .mb-8 { margin-bottom: 2rem !important; }
            .p-4 { padding: 1rem !important; }
            .p-6 { padding: 1.25rem !important; }
            .p-8 { padding: 1.5rem !important; }
        }
        
        /* ============================================
           DESKTOP: 1025px and above
           - Full sidebar and navigation
           - Multi-column grids (3-4 columns)
           - Max-width for readability
           ============================================ */
        @media (min-width: 1025px) {
            /* Sidebar - always visible */
            aside {
                position: relative !important;
                left: 0 !important;
                display: flex !important;
            }
            
            .sidebar-overlay {
                display: none !important;
            }
            
            /* Main content - offset by sidebar */
            main {
                margin-left: 0 !important;
                padding: 1.5rem 2rem !important;
                max-width: 100%;
            }
            
            .dashboard-shell #dashboard-main-wrapper {
                margin-left: 16rem; /* Sidebar width */
                max-width: calc(100% - 16rem);
            }
            
            /* Inner content container for max-width on desktop */
            .content-container {
                max-width: 1400px;
                margin: 0 auto;
            }
            
            /* Header */
            header {
                padding: 1rem 1.5rem !important;
            }
            
            header h1 {
                font-size: 1.5rem !important;
            }
            
            /* Grids - multi-column */
            .grid.md\\:grid-cols-2 {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1.5rem !important;
            }
            
            .grid.lg\\:grid-cols-2 {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1.5rem !important;
            }
            
            .grid.lg\\:grid-cols-3 {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 1.5rem !important;
            }
            
            .grid.lg\\:grid-cols-4 {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 1.5rem !important;
            }
            
            /* Cards - comfortable padding */
            .bg-white.rounded-xl,
            .card {
                padding: 1.5rem !important;
            }
            
            /* Buttons - standard size */
            button:not(.icon-only):not([class*="w-"]),
            .btn:not(.icon-only):not([class*="w-"]) {
                min-height: 44px;
                padding: 0.75rem 1.5rem;
                font-size: 0.9375rem;
            }
            
            /* Forms */
            input,
            select,
            textarea {
                font-size: 16px;
                min-height: 44px;
                padding: 0.75rem 1rem;
            }
            
            /* Tables - full table display */
            .table-wrapper table,
            .overflow-x-auto table {
                font-size: 0.9375rem;
            }
            
            .table-wrapper table th,
            .table-wrapper table td,
            .overflow-x-auto table th,
            .overflow-x-auto table td {
                padding: 1rem 0.75rem;
            }
            
            /* Typography */
            h1 { font-size: 2rem !important; }
            h2 { font-size: 1.75rem !important; }
            h3 { font-size: 1.5rem !important; }
            
            /* Spacing */
            .mb-6 { margin-bottom: 1.5rem !important; }
            .mb-8 { margin-bottom: 2rem !important; }
            .p-4 { padding: 1rem !important; }
            .p-6 { padding: 1.5rem !important; }
            .p-8 { padding: 2rem !important; }
        }
        
        /* ============================================
           LARGE DESKTOP: 1440px and above
           - Max-width containers for optimal readability
           ============================================ */
        @media (min-width: 1440px) {
            .content-container {
                max-width: 1600px;
            }
            
            main {
                padding: 2rem 2.5rem !important;
            }
            
            .dashboard-shell #dashboard-main-wrapper {
                max-width: calc(100% - 16rem);
            }
            }
        }
        
        @media (max-width: 640px) {
            main {
                padding: 1rem !important;
            }
            .gap-6 {
                gap: 1rem;
            }
            
            /* Smaller screens - stack everything */
            .flex.flex-row {
                flex-direction: column;
            }
            
            /* Mobile modals */
            .modal,
            [class*="modal"] {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }
            
            /* Mobile cards */
            .card,
            .bg-white.rounded-xl {
                padding: 1rem;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Prevent white screen - keep content visible -->
    <noscript>
        <style>
            body { display: block !important; }
            #app { display: flex !important; }
        </style>
    </noscript>
    <div id="app" class="flex min-h-screen dashboard-shell">
        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        
        <!-- Sidebar -->
        <?php render_super_admin_sidebar([
            'current_page' => $current_page,
            'user_data' => $user_data
        ]); ?>

        <!-- Main Content -->
        <div id="dashboard-main-wrapper" class="flex flex-col flex-1 min-h-screen">
            <!-- Top Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-30">
                <div class="flex items-center justify-between px-3 py-3 sm:px-4 sm:py-4 md:px-6 h-16">
                    <!-- Left Section: Menu + Logo/Title -->
                    <div class="flex items-center flex-1 min-w-0 h-full">
                        <button id="mobileMenuToggle" class="md:hidden text-gray-500 hover:text-gray-700 mr-2 sm:mr-3 flex-shrink-0 flex items-center justify-center w-10 h-10" aria-label="Toggle menu" type="button">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <!-- Mobile Logo + Title -->
                        <div class="md:hidden flex items-center min-w-0 flex-1 h-full">
                            <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                                <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg flex-shrink-0 mr-2" alt="Logo" />
                            <?php else: ?>
                                <i class="fas fa-heartbeat text-purple-600 text-2xl mr-2 flex-shrink-0"></i>
                            <?php endif; ?>
                            <h1 class="text-lg sm:text-xl font-bold text-gray-900 truncate leading-none"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
                        </div>
                        <!-- Desktop Title (hidden on mobile) -->
                        <div class="hidden md:flex items-center h-full">
                            <h1 class="text-xl font-bold text-gray-900 leading-none"><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></h1>
                        </div>
                    </div>
                    
                    <!-- Right Section: Notifications + Profile (aligned with hamburger and MediTrack) -->
                    <div class="flex items-center space-x-2 sm:space-x-3 flex-shrink-0 h-full">
                        <!-- Notifications Dropdown -->
                        <div class="relative">
                            <button id="notificationBtn" class="relative text-gray-500 hover:text-gray-700 flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors" aria-label="Notifications" type="button">
                            <i class="fas fa-bell text-xl"></i>
                                <?php if ($total_notifications > 0): ?>
                                    <span class="absolute top-1.5 right-1.5 block h-2 w-2 rounded-full bg-red-500"></span>
                                    <span class="absolute -top-1 -right-1 flex items-center justify-center w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full"><?php echo $total_notifications > 9 ? '9+' : $total_notifications; ?></span>
                            <?php endif; ?>
                        </button>
                            
                            <!-- Notifications Dropdown Menu -->
                            <div id="notificationDropdown" class="hidden absolute right-0 mt-3 w-80 sm:w-[28rem] bg-white rounded-2xl shadow-2xl ring-1 ring-black/5 z-50 overflow-hidden transform origin-top-right transition-all duration-200">
                                <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/80 backdrop-blur-sm flex items-center justify-between">
                                    <div>
                                        <h3 class="text-base font-bold text-gray-900">Notifications</h3>
                                        <p class="text-xs text-gray-500 mt-0.5">Stay updated with system activity</p>
                                    </div>
                                    <?php if ($total_notifications > 0): ?>
                                        <span class="px-2.5 py-1 bg-red-100 text-red-600 text-[10px] font-bold uppercase tracking-wider rounded-full"><?php echo $total_notifications; ?> NEW</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="overflow-y-auto max-h-[32rem] scrollbar-thin scrollbar-thumb-gray-200 scrollbar-track-transparent">
                                    <?php if ($total_notifications === 0): ?>
                                        <div class="flex flex-col items-center justify-center py-12 px-4 text-center">
                                            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                                <i class="fas fa-bell-slash text-2xl text-gray-300"></i>
                                            </div>
                                            <h4 class="text-sm font-semibold text-gray-900">No new notifications</h4>
                                            <p class="text-xs text-gray-500 mt-1 max-w-[200px]">You're all caught up! Check back later for updates.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php if ($pending_requests > 0): ?>
                                            <div class="divide-y divide-gray-50">
                                                <div class="px-5 py-2 bg-blue-50/50 border-b border-blue-100/50">
                                                    <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Pending Requests</p>
                                                </div>
                                                <?php foreach ($recent_pending_requests as $req): ?>
                                                    <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" 
                                                       class="group block px-5 py-4 hover:bg-blue-50/30 transition-all duration-200 relative">
                                                        <div class="flex items-start gap-4">
                                                            <div class="flex-shrink-0 mt-1">
                                                                <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                                    <i class="fas fa-user-clock text-xs"></i>
                                                                </div>
                                                            </div>
                                                            <div class="flex-1 min-w-0">
                                                                <p class="text-sm font-semibold text-gray-900 group-hover:text-blue-700 transition-colors mb-0.5">
                                                                    New request from <?php echo htmlspecialchars($req['requester_name'] ?: 'User'); ?>
                                                                </p>
                                                                <p class="text-xs text-gray-400 font-medium">
                                                                    <?php echo htmlspecialchars($req['formatted_date']); ?>
                                                                </p>
                                                            </div>
                                                            <div class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                                <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                                <?php if ($pending_requests > 5): ?>
                                                    <div class="p-2 bg-gray-50 border-t border-gray-100">
                                                        <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="block py-2 text-xs text-center text-blue-600 hover:text-blue-700 font-semibold hover:bg-blue-50 rounded-lg transition-colors">
                                                            View all <?php echo $pending_requests; ?> requests
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($alerts_count > 0): ?>
                                            <div class="divide-y divide-gray-50 border-t border-gray-100">
                                                <div class="px-5 py-2 bg-red-50/50 border-b border-red-100/50">
                                                    <p class="text-[10px] font-bold text-red-600 uppercase tracking-wider">Inventory Alerts</p>
                                                </div>
                                                <?php foreach ($inventory_alerts as $alert): ?>
                                                    <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>" 
                                                       class="group block px-5 py-4 hover:bg-red-50/30 transition-all duration-200 relative">
                                                        <div class="flex items-start gap-4">
                                                            <div class="flex-shrink-0 mt-1">
                                                                <?php if ($alert['severity'] === 'critical'): ?>
                                                                    <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-red-600 ring-4 ring-red-50">
                                                                        <i class="fas fa-exclamation text-xs"></i>
                                                                    </div>
                                                                <?php elseif ($alert['severity'] === 'high'): ?>
                                                                    <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 ring-4 ring-orange-50">
                                                                        <i class="fas fa-exclamation text-xs"></i>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center text-yellow-600 ring-4 ring-yellow-50">
                                                                        <i class="fas fa-info text-xs"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex-1 min-w-0">
                                                                <p class="text-sm font-semibold text-gray-900 group-hover:text-red-700 transition-colors mb-0.5">
                                                                    <?php echo htmlspecialchars($alert['medicine_name']); ?>
                                                                </p>
                                                                <p class="text-xs text-gray-600 line-clamp-2 mb-1"><?php echo htmlspecialchars($alert['message']); ?></p>
                                                                <p class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($alert['formatted_date']); ?></p>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                                <?php if ($alerts_count > 5): ?>
                                                    <div class="p-2 bg-gray-50 border-t border-gray-100">
                                                        <a href="<?php echo htmlspecialchars(base_url('super_admin/inventory.php')); ?>" class="block py-2 text-xs text-center text-red-600 hover:text-red-700 font-semibold hover:bg-red-50 rounded-lg transition-colors">
                                                            View all <?php echo $alerts_count; ?> alerts
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="p-3 border-t border-gray-100 bg-gray-50/80 backdrop-blur-sm">
                                    <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>" class="flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:text-gray-900 hover:border-gray-300 transition-all shadow-sm">
                                        <span>View All Activity</span>
                                        <i class="fas fa-arrow-right ml-2 text-xs opacity-70"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Profile Dropdown -->
                        <div class="relative">
                            <button id="profileBtn" class="flex items-center space-x-2 sm:space-x-3 h-full rounded-lg hover:bg-gray-100 transition-colors px-2" type="button">
                                <div class="text-right hidden sm:flex items-center h-full">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 leading-tight"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                                        <p class="text-xs text-gray-500 leading-tight">Super Administrator</p>
                                    </div>
                                </div>
                                <div class="w-10 h-10 rounded-full bg-white border-2 border-gray-300 flex items-center justify-center flex-shrink-0 cursor-pointer">
                                <?php if (!empty($user_data['profile_image'])): ?>
                                    <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                         alt="Profile" 
                                         class="w-10 h-10 rounded-full object-cover"
                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <?php endif; ?>
                                    <i class="fas fa-user text-gray-600 text-base <?php echo !empty($user_data['profile_image']) ? 'hidden' : ''; ?>" style="<?php echo !empty($user_data['profile_image']) ? 'display: none;' : ''; ?>"></i>
                                </div>
                            </button>
                            
                            <!-- Profile Dropdown Menu -->
                            <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                                <div class="p-4 border-b border-gray-200">
                                    <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(trim(($user_data['first_name'] ?? 'Super') . ' ' . ($user_data['last_name'] ?? 'Admin'))); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user_data['email'] ?? 'admin@meditrack.com'); ?></p>
                                </div>
                                <div class="py-2">
                                    <a href="<?php echo htmlspecialchars(base_url('super_admin/profile.php')); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                        <i class="fas fa-user-circle w-5 mr-3 text-gray-400"></i>
                                        <span>My Profile</span>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                        <i class="fas fa-cog w-5 mr-3 text-gray-400"></i>
                                        <span>Settings</span>
                                    </a>
                                    <div class="border-t border-gray-200 my-2"></div>
                                    <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                        <i class="fas fa-sign-out-alt w-5 mr-3"></i>
                                        <span>Logout</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main id="main-content-area" class="flex-1 overflow-y-auto container-responsive">
                <!-- Greeting Section -->
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Welcome back, Super Admin</h1>
                    <p class="text-gray-600">Here's an overview of your medicine inventory today.</p>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Stock Units</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-stock"><?php echo number_format($total_stock_units); ?></p>
                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fas fa-arrow-up"></i> Available units
                                </p>
                            </div>
                            <div class="bg-purple-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-boxes text-purple-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-yellow-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Low Stock Medicines</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-low"><?php echo number_format($low_stock_medicines); ?></p>
                                <p class="text-xs text-yellow-600 mt-2">
                                    <i class="fas fa-exclamation-triangle"></i> Needs restocking
                                </p>
                            </div>
                            <div class="bg-yellow-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Today's Dispensed</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-dispensed"><?php echo number_format($today_dispensed); ?></p>
                                <p class="text-xs text-green-600 mt-2">
                                    <i class="fas fa-arrow-up"></i> Units dispensed today
                                </p>
                            </div>
                            <div class="bg-green-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-pills text-green-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Requests</p>
                                <p class="text-3xl font-bold text-gray-900 mt-2" id="stat-requests"><?php echo number_format($total_requests); ?></p>
                                <p class="text-xs text-blue-600 mt-2">
                                    <i class="fas fa-clipboard-list"></i> <?php echo $pending_requests; ?> pending
                                </p>
                            </div>
                            <div class="bg-blue-100 w-14 h-14 rounded-full flex items-center justify-center">
                                <i class="fas fa-clipboard-list text-blue-600 text-2xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Combination Chart: Requests vs Dispensed -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Requests vs Dispensed</h3>
                            <p class="text-sm text-gray-600">Last 30 days trend</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="requestDispensedChart"></canvas>
                        </div>
                    </div>

                    <!-- Request Status Distribution -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Request Status</h3>
                            <p class="text-sm text-gray-600">Distribution by status</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <!-- Top Dispensed Medicines (Bar Chart) -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Top Dispensed Medicines</h3>
                            <p class="text-sm text-gray-600">Last 30 days</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="topMedicinesChart"></canvas>
                        </div>
                    </div>

                    <!-- Stock Distribution (Histogram) -->
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Stock Levels Distribution</h3>
                            <p class="text-sm text-gray-600">Current inventory levels</p>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="stockDistributionChart"></canvas>
                        </div>
                    </div>

                    <!-- Monthly Trends -->
                    <div class="bg-white rounded-xl shadow-md p-6 lg:col-span-2">
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Monthly Trends</h3>
                            <p class="text-sm text-gray-600">Last 6 months overview</p>
                        </div>
                        <div style="position: relative; height: 250px;">
                            <canvas id="monthlyTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Recent Activity</h3>
                            <p class="text-sm text-gray-600">Latest system updates</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <?php if (!empty($recent_medicines)): ?>
                            <?php foreach ($recent_medicines as $medicine): ?>
                                <div class="flex items-center space-x-4 p-4 bg-purple-50 rounded-lg">
                                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-pills text-green-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">New medicine added</p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($medicine['name']); ?> added to inventory</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($medicine['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($recent_users)): ?>
                            <?php foreach ($recent_users as $user_item): ?>
                                <div class="flex items-center space-x-4 p-4 bg-blue-50 rounded-lg">
                                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-user-plus text-blue-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">New BHW registered</p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($user_item['name']); ?> joined the system</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($user_item['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($recent_requests)): ?>
                            <?php foreach ($recent_requests as $request): ?>
                                <div class="flex items-center space-x-4 p-4 bg-green-50 rounded-lg">
                                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-clipboard-list text-purple-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900">Medicine request <?php echo htmlspecialchars($request['status']); ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($request['resident_name']); ?>'s request</p>
                                    </div>
                                    <span class="text-sm text-gray-500"><?php echo date('M j', strtotime($request['created_at'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (empty($recent_medicines) && empty($recent_users) && empty($recent_requests)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-500">No recent activity</p>
                                <p class="text-sm text-gray-400">Activity will appear here as users interact with the system</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Users Table -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Recent BHW Users</h2>
                        <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="text-sm text-purple-600 hover:text-purple-700">View all</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Join Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($recent_users)): ?>
                                    <?php foreach ($recent_users as $user_item): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <div class="h-10 w-10 rounded-full bg-purple-100 flex items-center justify-center">
                                                        <i class="fas fa-user text-purple-600"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user_item['name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars(ucfirst($user_item['role'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($user_item['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>" class="text-purple-600 hover:text-purple-900 mr-3"><i class="fas fa-edit"></i></a>
                                            <a href="#" class="text-red-600 hover:text-red-900"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            No recent users found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Global error handler to prevent white screen
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error, e.filename, e.lineno);
            e.preventDefault();
            return true;
        });

        // Prevent unhandled promise rejections from breaking the page
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
            e.preventDefault();
        });

        // Initialize charts
        function initCharts() {
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js is not loaded');
                return;
            }

            try {
                // 1. Combination Chart: Requests vs Dispensed (Line + Bar)
                const requestDispensedCtx = document.getElementById('requestDispensedChart');
                if (requestDispensedCtx) {
                    const ctx = requestDispensedCtx.getContext('2d');
                    const requestDispensedData = <?php echo json_encode($request_dispensed_trends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const labels = [];
                    const requestCounts = [];
                    const dispensedCounts = [];
                    
                    for (let i = 29; i >= 0; i--) {
                        const date = new Date();
                        date.setDate(date.getDate() - i);
                        const dateStr = date.toISOString().split('T')[0];
                        labels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                        
                        const dayData = requestDispensedData.find(item => item && item.date === dateStr);
                        requestCounts.push(dayData ? parseInt(dayData.request_count) || 0 : 0);
                        dispensedCounts.push(dayData ? parseInt(dayData.dispensed_units) || 0 : 0);
                    }
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Requests',
                                data: requestCounts,
                                type: 'line',
                                borderColor: 'rgb(139, 92, 246)',
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                tension: 0.4,
                                fill: false,
                                yAxisID: 'y'
                            }, {
                                label: 'Dispensed Units',
                                data: dispensedCounts,
                                backgroundColor: 'rgba(16, 185, 129, 0.6)',
                                borderColor: 'rgb(16, 185, 129)',
                                borderWidth: 1,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Requests'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Dispensed Units'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 2. Request Status Distribution (Doughnut Chart)
                const statusCtx = document.getElementById('statusChart');
                if (statusCtx) {
                    const ctx = statusCtx.getContext('2d');
                    const statusData = <?php echo json_encode($request_status_dist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const statusLabels = statusData.map(item => {
                        return item.status.charAt(0).toUpperCase() + item.status.slice(1).replace('_', ' ');
                    });
                    const statusCounts = statusData.map(item => parseInt(item.count) || 0);
                    
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: statusLabels,
                            datasets: [{
                                data: statusCounts,
                                backgroundColor: [
                                    'rgb(139, 92, 246)',
                                    'rgb(16, 185, 129)',
                                    'rgb(245, 158, 11)',
                                    'rgb(239, 68, 68)',
                                    'rgb(139, 69, 19)',
                                    'rgb(156, 163, 175)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }

                // 3. Top Dispensed Medicines (Horizontal Bar Chart)
                const topMedicinesCtx = document.getElementById('topMedicinesChart');
                if (topMedicinesCtx) {
                    const ctx = topMedicinesCtx.getContext('2d');
                    const topMedicinesData = <?php echo json_encode($top_dispensed_medicines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const medicineLabels = topMedicinesData.map(item => item.name.length > 20 ? item.name.substring(0, 20) + '...' : item.name);
                    const medicineUnits = topMedicinesData.map(item => parseInt(item.dispensed_units) || 0);
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: medicineLabels,
                            datasets: [{
                                label: 'Dispensed Units',
                                data: medicineUnits,
                                backgroundColor: 'rgba(139, 92, 246, 0.8)',
                                borderColor: 'rgb(139, 92, 246)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                y: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 4. Stock Distribution (Histogram/Bar Chart)
                const stockDistCtx = document.getElementById('stockDistributionChart');
                if (stockDistCtx) {
                    const ctx = stockDistCtx.getContext('2d');
                    const stockDistData = <?php echo json_encode($stock_distribution, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const stockRanges = ['Out of Stock', '1-10 units', '11-50 units', '51-100 units', '100+ units'];
                    const stockCounts = stockRanges.map(range => {
                        const found = stockDistData.find(item => item && item.stock_range === range);
                        return found ? parseInt(found.medicine_count) || 0 : 0;
                    });
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: stockRanges,
                            datasets: [{
                                label: 'Number of Medicines',
                                data: stockCounts,
                                backgroundColor: [
                                    'rgba(239, 68, 68, 0.8)',
                                    'rgba(245, 158, 11, 0.8)',
                                    'rgba(139, 92, 246, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(139, 69, 19, 0.8)'
                                ],
                                borderColor: [
                                    'rgb(239, 68, 68)',
                                    'rgb(245, 158, 11)',
                                    'rgb(139, 92, 246)',
                                    'rgb(16, 185, 129)',
                                    'rgb(139, 69, 19)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // 5. Monthly Trends (Combination Chart)
                const monthlyTrendsCtx = document.getElementById('monthlyTrendsChart');
                if (monthlyTrendsCtx) {
                    const ctx = monthlyTrendsCtx.getContext('2d');
                    const monthlyData = <?php echo json_encode($monthly_trends, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    
                    const monthLabels = monthlyData.map(item => item.month_label);
                    const monthRequests = monthlyData.map(item => parseInt(item.request_count) || 0);
                    const monthDispensed = monthlyData.map(item => parseInt(item.dispensed_units) || 0);
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: monthLabels,
                            datasets: [{
                                label: 'Requests',
                                data: monthRequests,
                                type: 'line',
                                borderColor: 'rgb(139, 69, 19)',
                                backgroundColor: 'rgba(139, 69, 19, 0.1)',
                                tension: 0.4,
                                fill: false,
                                yAxisID: 'y'
                            }, {
                                label: 'Dispensed Units',
                                data: monthDispensed,
                                backgroundColor: 'rgba(139, 92, 246, 0.6)',
                                borderColor: 'rgb(139, 92, 246)',
                                borderWidth: 1,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Requests'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Dispensed Units'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }

        // Animate stats on load
        function animateStats() {
            const stats = ['stat-stock', 'stat-low', 'stat-dispensed', 'stat-requests'];
            const values = [<?php echo (int)$total_stock_units; ?>, <?php echo (int)$low_stock_medicines; ?>, <?php echo (int)$today_dispensed; ?>, <?php echo (int)$total_requests; ?>];
            
            stats.forEach((statId, index) => {
                const element = document.getElementById(statId);
                if (!element) return;
                
                let current = 0;
                const target = values[index] || 0;
                if (target === 0) {
                    element.textContent = '0';
                    return;
                }
                const increment = Math.max(target / 50, 1);
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    element.textContent = Math.floor(current).toLocaleString();
                }, 30);
            });
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            animateStats();
            
            // Get mobile menu toggle and sidebar elements
            const mobileToggle = document.getElementById('mobileMenuToggle');
            const sidebarAside = document.getElementById('sidebar-aside');
            const overlay = document.getElementById('sidebarOverlay');
            const sidebarLinks = document.querySelectorAll('aside .sidebar-link');

            function setActiveSidebarLink(urlOrHref) {
                if (!urlOrHref) return;
                let targetPath;
                try {
                    const tmpUrl = new URL(urlOrHref, window.location.origin);
                    targetPath = tmpUrl.pathname;
                } catch (e) {
                    targetPath = urlOrHref;
                }

                sidebarLinks.forEach(link => {
                    const href = link.getAttribute('href') || '';
                    let linkPath = href;
                    try {
                        const tmp = new URL(href, window.location.origin);
                        linkPath = tmp.pathname;
                    } catch (e) {}

                    if (linkPath === targetPath) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            }
            
            // Preserve sidebar scroll position across full page reloads
            const sidebarScrollContainer = document.getElementById('super-admin-sidebar-scroll');
            const SIDEBAR_SCROLL_KEY = 'saSidebarScrollTop';

            if (sidebarScrollContainer) {
                // Restore previous scroll
                const savedScroll = parseInt(localStorage.getItem(SIDEBAR_SCROLL_KEY) || '0', 10);
                if (!Number.isNaN(savedScroll)) {
                    sidebarScrollContainer.scrollTop = savedScroll;
                }

                // Save on scroll
                sidebarScrollContainer.addEventListener('scroll', function () {
                    localStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebarScrollContainer.scrollTop));
                });
            }

            // Mobile sidebar helpers
                function openMobileSidebar() {
                if (sidebarAside) {
                    // Remove hidden class if present (from Tailwind)
                    sidebarAside.classList.remove('hidden');
                    sidebarAside.classList.add('show');
                    sidebarAside.style.left = '0';
                    sidebarAside.style.display = 'flex';
                    sidebarAside.style.zIndex = '50';
                    sidebarAside.style.position = 'fixed';
                    sidebarAside.style.top = '0';
                    sidebarAside.style.height = '100vh';
                    }
                    if (overlay) {
                        overlay.classList.add('show');
                    overlay.style.display = 'block';
                    overlay.style.zIndex = '40';
                }
                // Prevent body scroll ONLY when sidebar is open
                // Store current scroll position
                const scrollY = window.scrollY;
                document.body.style.position = 'fixed';
                document.body.style.top = `-${scrollY}px`;
                document.body.style.width = '100%';
                document.body.setAttribute('data-scroll-y', scrollY.toString());
                }
                
                function closeMobileSidebar() {
                if (sidebarAside) {
                    sidebarAside.classList.remove('show');
                    sidebarAside.style.left = '-16rem';
                    // On mobile, hide it completely after transition
                    if (window.innerWidth <= 1024) {
                        setTimeout(() => {
                            if (sidebarAside && !sidebarAside.classList.contains('show')) {
                                sidebarAside.style.display = 'none';
                            }
                        }, 300); // Wait for transition to complete
                    }
                    }
                    if (overlay) {
                        overlay.classList.remove('show');
                    overlay.style.display = 'none';
                }
                // Restore body scroll and position
                const scrollY = document.body.getAttribute('data-scroll-y');
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.width = '';
                document.body.removeAttribute('data-scroll-y');
                // Restore scroll position
                if (scrollY) {
                    window.scrollTo(0, parseInt(scrollY, 10));
                }
            }
            
            // Helper to check if sidebar is open
            function isMobileSidebarOpen() {
                return sidebarAside && sidebarAside.classList.contains('show');
            }

            // AJAX content loading function (used on desktop + mobile)
            function loadPageContent(url) {
                const mainContent = document.getElementById('main-content-area');
                if (!mainContent) return;

                // Show loading state
                mainContent.innerHTML = '<div class="flex items-center justify-center h-64"><div class="text-center"><div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600 mb-4"></div><p class="text-gray-600">Loading...</p></div></div>';

                // Add ajax parameter to URL
                const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1';

                // Fetch content
                fetch(ajaxUrl, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    // Replace content
                    mainContent.innerHTML = html;

                    // Execute any scripts in the loaded content
                    const scripts = mainContent.querySelectorAll('script');
                    scripts.forEach(oldScript => {
                        const newScript = document.createElement('script');
                        if (oldScript.src) {
                            newScript.src = oldScript.src;
                        } else {
                            newScript.textContent = oldScript.textContent;
                        }
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    // Update URL without reload and update active sidebar state
                    window.history.pushState({url: url}, '', url);
                    setActiveSidebarLink(url);
                })
                .catch(error => {
                    console.error('Error loading content:', error);
                    mainContent.innerHTML = '<div class="p-6 bg-red-50 border border-red-200 rounded-lg"><p class="text-red-700">Error loading content. Please <a href=\"' + url + '\" class=\"underline\">refresh the page</a>.</p></div>';
                });
            }

            // Mobile menu toggle wiring - ensure it works properly
            if (mobileToggle) {
                // Remove any existing handlers first
                mobileToggle.onclick = null;
                
                // Use direct onclick for maximum compatibility
                mobileToggle.onclick = function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    if (isMobileSidebarOpen()) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                };

                // Also add event listener as backup
                mobileToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    e.preventDefault();
                    if (isMobileSidebarOpen()) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                }, { passive: false });
                
                // Ensure button is visible and clickable
                mobileToggle.style.display = 'flex';
                mobileToggle.style.cursor = 'pointer';
                mobileToggle.style.pointerEvents = 'auto';
                
                // Close sidebar when clicking overlay
                if (overlay) {
                    overlay.onclick = function(e) {
                        e.stopPropagation();
                        closeMobileSidebar();
                    };
                    overlay.addEventListener('click', function(e) {
                        e.stopPropagation();
                        closeMobileSidebar();
                    });
                }
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 1024) {
                        if (isMobileSidebarOpen()) {
                            if (sidebarAside && !sidebarAside.contains(e.target) && 
                                mobileToggle && !mobileToggle.contains(e.target) &&
                                overlay && !overlay.contains(e.target)) {
                                closeMobileSidebar();
                            }
                        }
                    }
                });
            }
                
            // Handle sidebar link clicks (desktop + mobile)
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', function(e) {
                        // Handle AJAX navigation
                        const href = this.getAttribute('href');
                        if (href && !href.includes('#') && !this.hasAttribute('data-no-ajax')) {
                        // Skip dashboard shell link - let it perform a normal navigation
                            if (href.includes('dashboardnew.php')) {
                                if (window.innerWidth <= 1024) {
                                    closeMobileSidebar();
                                }
                                return;
                            }
                            
                            e.preventDefault();
                            
                        // Close mobile sidebar if needed
                            if (window.innerWidth <= 1024) {
                                closeMobileSidebar();
                            }
                            
                        // Immediately update active state based on the clicked link
                            sidebarLinks.forEach(l => l.classList.remove('active'));
                            this.classList.add('active');
                            
                            // Load content via AJAX
                            loadPageContent(href);
                        } else {
                            // Normal navigation for links with data-no-ajax or anchors
                            if (window.innerWidth <= 1024) {
                                closeMobileSidebar();
                            }
                        }
                    });
                });
                
                // Handle initial target load if coming from redirect shell
                const params = new URLSearchParams(window.location.search);
                const target = params.get('target');
                if (target) {
                    loadPageContent(target);
                }

                // Handle browser back/forward buttons
                window.addEventListener('popstate', function(e) {
                    if (e.state && e.state.url) {
                        loadPageContent(e.state.url);
                    } else {
                        // Reload dashboard
                        window.location.href = '<?php echo htmlspecialchars(base_url("super_admin/dashboardnew.php")); ?>';
                    }
                });
                
                // Handle form submissions - allow normal form posts
                document.addEventListener('submit', function(e) {
                    const form = e.target;
                    // If form has data-no-ajax attribute, allow normal submission
                    if (form.hasAttribute('data-no-ajax')) {
                        return;
                    }
                    // For forms in AJAX-loaded content, allow normal submission
                    // (they will redirect after POST)
                });
            
            // Notification Dropdown Handler
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            
            // Toggle notification dropdown
            if (notificationBtn && notificationDropdown) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = !notificationDropdown.classList.contains('hidden');
                    
                    // Close profile dropdown if open
                    if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                        profileDropdown.classList.add('hidden');
                    }
                    
                    // Toggle notification dropdown
                    if (isOpen) {
                        notificationDropdown.classList.add('hidden');
                    } else {
                        notificationDropdown.classList.remove('hidden');
                    }
                });
            }
            
            // Toggle profile dropdown
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = !profileDropdown.classList.contains('hidden');
                    
                    // Close notification dropdown if open
                    if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                        notificationDropdown.classList.add('hidden');
                    }
                    
                    // Toggle profile dropdown
                    if (isOpen) {
                        profileDropdown.classList.add('hidden');
                    } else {
                        profileDropdown.classList.remove('hidden');
                    }
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                // Close notification dropdown
                if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                    if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                        notificationDropdown.classList.add('hidden');
                    }
                }
                
                // Close profile dropdown
                if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                }
            });
            
            // Close dropdowns on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                        notificationDropdown.classList.add('hidden');
                    }
                    if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                        profileDropdown.classList.add('hidden');
                    }
                }
            });
        });
    </script>
</body>
</html>
