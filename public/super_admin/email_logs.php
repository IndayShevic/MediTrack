<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/../../config/mail.php';

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
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
<div class="min-h-screen flex">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg" alt="Logo" />
            <?php endif; ?>
            <span><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo htmlspecialchars(base_url('super_admin/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"/></svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/medicines.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/batches.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                Batches
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/users.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
                Users
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/analytics.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Analytics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/settings_brand.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Brand Settings
            </a>
            <a href="<?php echo htmlspecialchars(base_url('super_admin/locations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Barangays & Puroks
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('super_admin/email_logs.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Email Logs
            </a>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="text-red-600 hover:text-red-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </nav>
    </aside>
    <main class="main-content">
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Email Logs</h1>
                    <p class="text-gray-600 mt-1">Search, filter, export, and resend failed notifications.</p>
                </div>
                <div class="flex items-center space-x-3">
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
</body>
</html>
