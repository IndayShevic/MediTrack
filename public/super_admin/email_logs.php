<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/ajax_helpers.php';
require_once __DIR__ . '/includes/sidebar.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);
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

// Handle resend
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $row = db()->prepare('SELECT recipient, subject FROM email_logs WHERE id=?');
        $row->execute([$id]);
        if ($log = $row->fetch()) {
            $to = $log['recipient'];
            $subject = '[Resend] ' . ($log['subject'] ?? 'MediTrack Notification');
            $html = email_template(
                'Resent Email',
                'This is an automatic resend of a previous notification.',
                '<p>If you received the earlier message, you can ignore this one.</p>',
                'Open MediTrack',
                base_url('public/login.php')
            );
            if (!empty($to)) { send_email($to, $to, $subject, $html); }
        }
    }
    redirect_to('super_admin/email_logs.php');
}

// Filters & pagination
$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

$where = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(recipient LIKE ? OR subject LIKE ? OR error LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($from !== '') { $where[] = 'DATE(sent_at) >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'DATE(sent_at) <= ?'; $params[] = $to; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) AS c FROM email_logs $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetch()['c'];

// Bulk resend for filtered failed logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_resend') {
    $bw = [];
    $bp = [];
    // Always target failed only, but respect other filters
    $bw[] = 'status = "failed"';
    if ($q !== '') { $bw[] = '(recipient LIKE ? OR subject LIKE ? OR error LIKE ?)'; $bp[] = "%$q%"; $bp[] = "%$q%"; $bp[] = "%$q%"; }
    if ($from !== '') { $bw[] = 'DATE(sent_at) >= ?'; $bp[] = $from; }
    if ($to !== '') { $bw[] = 'DATE(sent_at) <= ?'; $bp[] = $to; }
    $bwSql = 'WHERE ' . implode(' AND ', $bw);
    $sel = db()->prepare("SELECT id, recipient, subject FROM email_logs $bwSql ORDER BY sent_at DESC");
    $sel->execute($bp);
    while ($r = $sel->fetch()) {
        if (!empty($r['recipient'])) {
            $html = email_template(
                'Resent Email',
                'This is an automatic bulk resend of a previous notification.',
                '<p>If you already received the original email, please ignore this copy.</p>',
                'Open MediTrack',
                base_url('public/login.php')
            );
            send_email($r['recipient'], $r['recipient'], '[Resend] ' . ($r['subject'] ?? 'MediTrack Notification'), $html);
        }
    }
    redirect_to('super_admin/email_logs.php?' . http_build_query($_GET));
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="email_logs.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Recipient','Subject','Status','Error','Sent At']);
    $stmt = db()->prepare("SELECT recipient, subject, status, error, sent_at FROM email_logs $whereSql ORDER BY sent_at DESC");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) { fputcsv($out, [$r['recipient'],$r['subject'],$r['status'],$r['error'],$r['sent_at']]); }
    fclose($out);
    exit;
}

$stmt = db()->prepare("SELECT id, recipient, subject, status, error, sent_at FROM email_logs $whereSql ORDER BY sent_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Email Logs · Super Admin</title>
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
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Email Logs</h1>
                    <p class="text-gray-600 mt-1">Search, filter, export, and resend failed notifications</p>
                </div>
                <div class="flex items-center space-x-6">
                    <!-- Current Time Display -->
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Current Time</div>
                        <div class="text-sm font-medium text-gray-900" id="current-time"><?php echo date('H:i:s'); ?></div>
                    </div>
                    
                    <!-- Night Mode Toggle -->
                    <button id="night-mode-toggle" class="p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200" title="Toggle Night Mode">
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                    </button>
                    
                    <!-- Notifications -->
                    <div class="relative">
                        <button class="p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200 relative" title="Notifications">
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM4.828 7l2.586 2.586a2 2 0 002.828 0L12 7H4.828zM4 5h16a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V7a2 2 0 012-2z"></path>
                            </svg>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">3</span>
                        </button>
                    </div>
                    
                    <!-- Profile Section -->
                    <div class="relative" id="profile-dropdown">
                        <button id="profile-toggle" class="flex items-center space-x-3 hover:bg-gray-50 rounded-lg p-2 transition-colors duration-200 cursor-pointer" type="button">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                                     alt="Profile Picture" 
                                     class="w-8 h-8 rounded-full object-cover border-2 border-purple-500"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500" style="display:none;">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm border-2 border-purple-500">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'S';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'A';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars(!empty($user['first_name']) ? $user['first_name'] : 'Super'); ?>
                                </div>
                                <div class="text-xs text-gray-500">Super Admin</div>
                            </div>
                            <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" id="profile-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <!-- Profile Dropdown Menu -->
                        <div id="profile-menu" class="absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border border-gray-200 py-2 z-50 hidden">
                            <!-- User Info Section -->
                            <div class="px-4 py-3 border-b border-gray-100">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'Super') . ' ' . ($user['last_name'] ?? 'Admin'))); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($user['email'] ?? 'admin@example.com'); ?>
                                </div>
                            </div>
                            
                            <!-- Menu Items -->
                            <div class="py-1">
                                <a href="<?php echo base_url('super_admin/profile.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Edit Profile
                                </a>
                                <a href="<?php echo base_url('super_admin/settings_brand.php'); ?>" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    Account Settings
                                </a>
                                <a href="#" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Support
                                </a>
                            </div>
                            
                            <!-- Separator -->
                            <div class="border-t border-gray-100 my-1"></div>
                            
                            <!-- Sign Out -->
                            <div class="py-1">
                                <a href="<?php echo base_url('logout.php'); ?>" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                                    <svg class="w-4 h-4 mr-3 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    Sign Out
                                </a>
                            </div>
                        </div>
                </div>
                    
                    <!-- Export Button -->
                    <a href="?export=1" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v8m0 0l-3-3m3 3l3-3M4 4h16v4H4z"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>
        </div>

        <div class="content-body">
            <!-- Filters -->
            <form method="get" class="card mb-6">
                <div class="card-body grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="">All</option>
                            <option value="sent" <?php echo $status==='sent'?'selected':''; ?>>Sent</option>
                            <option value="failed" <?php echo $status==='failed'?'selected':''; ?>>Failed</option>
                        </select>
                    </div>
                    <div class="form-group md:col-span-2">
                        <label class="form-label">Search</label>
                        <input name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-input" placeholder="Recipient, subject or error" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">From</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-input" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">To</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="form-input" />
                    </div>
                </div>
                <div class="card-body pt-0 flex justify-between">
                    <form method="post">
                        <input type="hidden" name="action" value="bulk_resend" />
                        <button class="btn btn-danger">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            Resend All Failed (filtered)
                        </button>
                    </form>
                    <button class="btn btn-primary">Apply Filters</button>
                </div>
            </form>

            <!-- Logs Table -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Error</th>
                                    <th>Sent At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr class="animate-fade-in">
                                    <td><?php echo htmlspecialchars($log['recipient']); ?></td>
                                    <td><?php echo htmlspecialchars($log['subject']); ?></td>
                                    <td>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $log['status']==='sent'?'bg-green-100 text-green-800':'bg-red-100 text-red-800'; ?>">
                                            <?php echo htmlspecialchars($log['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['error'])): ?>
                                            <details class="text-sm text-red-700"><summary class="cursor-pointer">View error</summary><?php echo htmlspecialchars($log['error']); ?></details>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-500">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="text-sm text-gray-600"><?php echo date('M j, Y H:i', strtotime($log['sent_at'])); ?></span></td>
                                    <td>
                                        <?php if ($log['status'] === 'failed'): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="action" value="resend" />
                                                <input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>" />
                                                <button class="btn btn-sm btn-primary">Resend</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <?php $pages = (int)ceil(max(1,$total)/$perPage); if ($pages > 1): ?>
                <div class="mt-6 flex justify-end space-x-2">
                    <?php for ($p=1; $p<=$pages; $p++): $qs = $_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
                        <a href="<?php echo htmlspecialchars($href); ?>" class="px-3 py-1 rounded border <?php echo $p===$page?'bg-blue-600 text-white border-blue-600':'bg-white text-gray-700 border-gray-200'; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Initialize functions when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Logout confirmation is now handled by logout-confirmation.js
    });

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
</script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>
