<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['bhw']);

$user = current_user();
$bhw_purok_id = $user['purok_id'] ?? 0;

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pending_id = (int)($_POST['pending_id'] ?? 0);
    
    if ($action === 'approve' && $pending_id > 0) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Get pending resident data
            $stmt = $pdo->prepare('SELECT * FROM pending_residents WHERE id = ? AND purok_id = ? AND status = "pending"');
            $stmt->execute([$pending_id, $bhw_purok_id]);
            $pending = $stmt->fetch();
            
            if ($pending) {
                // Create user account
                $insUser = $pdo->prepare('INSERT INTO users(email, password_hash, role, first_name, last_name, purok_id) VALUES(?,?,?,?,?,?)');
                $insUser->execute([$pending['email'], $pending['password_hash'], 'resident', $pending['first_name'], $pending['last_name'], $pending['purok_id']]);
                $userId = (int)$pdo->lastInsertId();
                
                // Create resident record
                $insRes = $pdo->prepare('INSERT INTO residents(user_id, barangay_id, purok_id, first_name, last_name, date_of_birth, email, phone, address) VALUES(?,?,?,?,?,?,?,?,?)');
                $insRes->execute([$userId, $pending['barangay_id'], $pending['purok_id'], $pending['first_name'], $pending['last_name'], $pending['date_of_birth'], $pending['email'], $pending['phone'], $pending['address']]);
                $residentId = (int)$pdo->lastInsertId();
                
                // Transfer family members
                $familyStmt = $pdo->prepare('SELECT * FROM pending_family_members WHERE pending_resident_id = ?');
                $familyStmt->execute([$pending_id]);
                $familyMembers = $familyStmt->fetchAll();
                
                foreach ($familyMembers as $member) {
                    $insFamily = $pdo->prepare('INSERT INTO family_members(resident_id, full_name, relationship, age) VALUES(?,?,?,?)');
                    $insFamily->execute([$residentId, $member['full_name'], $member['relationship'], $member['age']]);
                }
                
                // Update pending status
                $updateStmt = $pdo->prepare('UPDATE pending_residents SET status = "approved", bhw_id = ?, updated_at = NOW() WHERE id = ?');
                $updateStmt->execute([$user['id'], $pending_id]);
                
                // Send approval email to resident
                $success = send_registration_approval_email($pending['email'], $pending['first_name'] . ' ' . $pending['last_name']);
                log_email_notification($userId, 'registration_approval', 'Registration Approved', 'Resident registration approved', $success);
                
                $pdo->commit();
                set_flash('Resident registration approved successfully.', 'success');
            }
        } catch (Throwable $e) {
            if (isset($pdo)) $pdo->rollBack();
            set_flash('Failed to approve registration.', 'error');
        }
    } elseif ($action === 'reject' && $pending_id > 0) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            // Get pending resident data for email
            $stmt = db()->prepare('SELECT * FROM pending_residents WHERE id = ? AND purok_id = ?');
            $stmt->execute([$pending_id, $bhw_purok_id]);
            $pending = $stmt->fetch();
            
            if ($pending) {
                $stmt = db()->prepare('UPDATE pending_residents SET status = "rejected", bhw_id = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ? AND purok_id = ?');
                $stmt->execute([$user['id'], $reason, $pending_id, $bhw_purok_id]);
                
                // Send rejection email to resident
                $success = send_registration_rejection_email($pending['email'], $pending['first_name'] . ' ' . $pending['last_name'], $reason);
                log_email_notification(0, 'registration_rejection', 'Registration Rejected', 'Resident registration rejected: ' . $reason, $success);
                
                set_flash('Resident registration rejected.', 'success');
            }
        } catch (Throwable $e) {
            set_flash('Failed to reject registration.', 'error');
        }
    }
    
    redirect_to('bhw/pending_residents.php');
}

// Fetch pending residents for this BHW's purok
$pending_residents = [];
try {
    $stmt = db()->prepare('
        SELECT pr.*, b.name as barangay_name, p.name as purok_name,
               (SELECT COUNT(*) FROM pending_family_members WHERE pending_resident_id = pr.id) as family_count
        FROM pending_residents pr
        JOIN barangays b ON b.id = pr.barangay_id
        JOIN puroks p ON p.id = pr.purok_id
        WHERE pr.purok_id = ? AND pr.status = "pending"
        ORDER BY pr.created_at DESC
    ');
    $stmt->execute([$bhw_purok_id]);
    $pending_residents = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending_residents = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pending Residents - BHW Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
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
            <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                Medicine Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                </svg>
                Residents & Family
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Pending Registrations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Statistics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="text-red-600 hover:text-red-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Pending Resident Registrations</h1>
                    <p class="text-gray-600 mt-1">Review and approve resident registration requests for your purok.</p>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php [$flash, $flashType] = get_flash(); if ($flash): ?>
            <div class="mb-6 px-4 py-3 rounded-lg <?php echo $flashType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                <?php echo htmlspecialchars($flash); ?>
            </div>
        <?php endif; ?>

        <!-- Pending Residents List -->
        <div class="content-body">
            <?php if (empty($pending_residents)): ?>
                <div class="card">
                    <div class="card-body text-center py-12">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Pending Registrations</h3>
                        <p class="text-gray-600">There are no pending resident registration requests for your purok.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid gap-6">
                    <?php foreach ($pending_residents as $resident): ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-4 mb-4">
                                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></h3>
                                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($resident['email']); ?></p>
                                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($resident['barangay_name'] . ' - ' . $resident['purok_name']); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <div>
                                                <p class="text-sm text-gray-600"><strong>Phone:</strong> <?php echo htmlspecialchars($resident['phone'] ?: 'Not provided'); ?></p>
                                                <p class="text-sm text-gray-600"><strong>Date of Birth:</strong> <?php echo date('M j, Y', strtotime($resident['date_of_birth'])); ?></p>
                                            </div>
                                            <div>
                                                <p class="text-sm text-gray-600"><strong>Address:</strong> <?php echo htmlspecialchars($resident['address'] ?: 'Not provided'); ?></p>
                                                <p class="text-sm text-gray-600"><strong>Family Members:</strong> <?php echo (int)$resident['family_count']; ?></p>
                                            </div>
                                        </div>
                                        
                                        <?php if ($resident['family_count'] > 0): ?>
                                            <div class="mb-4">
                                                <h4 class="text-sm font-medium text-gray-900 mb-2">Family Members:</h4>
                                                <?php
                                                $familyStmt = db()->prepare('SELECT * FROM pending_family_members WHERE pending_resident_id = ?');
                                                $familyStmt->execute([$resident['id']]);
                                                $familyMembers = $familyStmt->fetchAll();
                                                ?>
                                                <div class="space-y-2">
                                                    <?php foreach ($familyMembers as $member): ?>
                                                        <div class="flex items-center space-x-4 text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">
                                                            <span class="font-medium"><?php echo htmlspecialchars($member['full_name']); ?></span>
                                                            <span class="text-gray-500"><?php echo htmlspecialchars($member['relationship']); ?></span>
                                                            <span class="text-gray-500">Age: <?php echo (int)$member['age']; ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <p class="text-xs text-gray-500">Submitted: <?php echo date('M j, Y g:i A', strtotime($resident['created_at'])); ?></p>
                                    </div>
                                    
                                    <div class="flex flex-col space-y-2 ml-4">
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="approve" />
                                            <input type="hidden" name="pending_id" value="<?php echo (int)$resident['id']; ?>" />
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Approve this registration?')">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Approve
                                            </button>
                                        </form>
                                        
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="showRejectModal(<?php echo (int)$resident['id']; ?>)">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Reject Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Reject Registration</h3>
            <form method="post" id="rejectForm">
                <input type="hidden" name="action" value="reject" />
                <input type="hidden" name="pending_id" id="rejectPendingId" />
                <div class="mb-4">
                    <label class="block text-sm text-gray-700 mb-2">Reason for rejection:</label>
                    <textarea name="rejection_reason" class="w-full border rounded px-3 py-2" rows="3" placeholder="Please provide a reason for rejection..." required></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Registration</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showRejectModal(pendingId) {
            document.getElementById('rejectPendingId').value = pendingId;
            document.getElementById('rejectModal').classList.remove('hidden');
            document.getElementById('rejectModal').classList.add('flex');
        }
        
        function hideRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
            document.getElementById('rejectModal').classList.remove('flex');
        }
        
        // Close modal when clicking outside
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideRejectModal();
            }
        });
    </script>
</body>
</html>
