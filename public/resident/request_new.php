<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/email_notifications.php';
require_auth(['resident']);
$user = current_user();

$residentRow = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
$residentRow->execute([$user['id']]);
$resident = $residentRow->fetch();
if (!$resident) { echo 'Resident profile not found.'; exit; }
$residentId = (int)$resident['id'];

// Fetch family members for this resident
$familyMembers = [];
try {
    $stmt = db()->prepare('SELECT id, full_name, relationship, age FROM family_members WHERE resident_id = ? ORDER BY full_name');
    $stmt->execute([$residentId]);
    $familyMembers = $stmt->fetchAll();
} catch (Throwable $e) {
    $familyMembers = [];
}

$medicine_id = (int)($_GET['medicine_id'] ?? 0);
$m = null;
if ($medicine_id > 0) {
    $s = db()->prepare('SELECT id, name FROM medicines WHERE id=?');
    $s->execute([$medicine_id]);
    $m = $s->fetch();
}

// Ensure upload directory
$proofDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($proofDir)) { @mkdir($proofDir, 0777, true); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $requested_for = $_POST['requested_for'] ?? 'self';
    $family_member_id = ($_POST['family_member_id'] ?? null) ? (int)$_POST['family_member_id'] : null;
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_age = (int)($_POST['patient_age'] ?? 0);
    $relationship = trim($_POST['relationship'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $proof_path = null;
    
    // Handle proof upload
    if (!empty($_FILES['proof']['name'])) {
        if (($_FILES['proof']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','pdf'], true)) {
                $filename = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['proof']['tmp_name'], $proofDir . '/' . $filename)) {
                    $proof_path = 'uploads/proofs/' . $filename;
                }
            }
        }
    }

    // If family member is selected, get their details from the database
    if ($requested_for === 'family' && $family_member_id) {
        $familyMember = db()->prepare('SELECT full_name, relationship, age FROM family_members WHERE id = ? AND resident_id = ?');
        $familyMember->execute([$family_member_id, $residentId]);
        $member = $familyMember->fetch();
        
        if ($member) {
            $patient_name = $member['full_name'];
            $patient_age = $member['age'];
            $relationship = $member['relationship'];
        }
    }
    
    $bhwId = getAssignedBhwIdForResident($residentId);
    $stmt = db()->prepare('INSERT INTO requests (resident_id, medicine_id, requested_for, family_member_id, patient_name, patient_age, relationship, reason, proof_image_path, status, bhw_id) VALUES (?,?,?,?,?,?,?,?,?,"submitted",?)');
    $stmt->execute([$residentId, $medicine_id, $requested_for, $family_member_id, $patient_name, $patient_age, $relationship, $reason, $proof_path, $bhwId]);
    // Notify assigned BHW
    if ($bhwId) {
        $b = db()->prepare('SELECT email, CONCAT(IFNULL(first_name,\'\'),\' \',IFNULL(last_name,\'\')) AS name FROM users WHERE id=?');
        $b->execute([$bhwId]);
        $bhw = $b->fetch();
        if ($bhw && !empty($bhw['email'])) {
            $residentName = $user['name'] ?? 'Resident';
            $medicineName = $m['name'] ?? 'Unknown Medicine';
            $success = send_medicine_request_notification_to_bhw($bhw['email'], $bhw['name'] ?? 'BHW', $residentName, $medicineName);
            log_email_notification($bhwId, 'medicine_request', 'New Medicine Request', 'New medicine request notification sent to BHW', $success);
        }
    }
    set_flash('Request submitted.','success');
    redirect_to('resident/requests.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request Medicine · Resident</title>
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
            <a href="<?php echo htmlspecialchars(base_url('resident/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Browse Medicines
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                My Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
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
                    <h1 class="text-3xl font-bold text-gray-900">Request Medicine</h1>
                    <p class="text-gray-600 mt-1">Submit a request for medicine with proof of need.</p>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Browse
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content-body">
            <div class="max-w-2xl mx-auto">
                <form method="post" enctype="multipart/form-data" class="card animate-fade-in-up">
                    <div class="card-body">
                        <input type="hidden" name="medicine_id" value="<?php echo (int)($m['id'] ?? 0); ?>" />
                        
                        <!-- Medicine Info -->
                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                                <div>
                                    <h3 class="text-lg font-semibold text-blue-900"><?php echo htmlspecialchars($m['name'] ?? 'Medicine'); ?></h3>
                                    <p class="text-sm text-blue-700">Request this medicine</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Requested For</label>
                                <select name="requested_for" class="form-input" id="reqFor">
                                    <option value="self">Self</option>
                                    <option value="family">Family Member</option>
                                </select>
                            </div>
                            
                            <?php if (!empty($familyMembers)): ?>
                            <div id="familyMemberSelect" style="display:none">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select Family Member</label>
                                <select name="family_member_id" class="form-input">
                                    <option value="">Choose a family member</option>
                                    <?php foreach ($familyMembers as $member): ?>
                                        <option value="<?php echo (int)$member['id']; ?>"><?php echo htmlspecialchars($member['full_name'] . ' (' . $member['relationship'] . ', Age: ' . $member['age'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="md:col-span-2 mt-6" id="familyFields" style="display:none">
                            <h4 class="text-lg font-medium text-gray-900 mb-4">Patient Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Patient Name</label>
                                    <input name="patient_name" class="form-input" placeholder="Enter patient name" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Age</label>
                                    <input name="patient_age" type="number" min="0" max="120" class="form-input" placeholder="Age" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Relationship</label>
                                    <input name="relationship" class="form-input" placeholder="e.g., Father, Mother" />
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Request</label>
                            <textarea name="reason" class="form-input" rows="4" placeholder="Please explain why you need this medicine..."></textarea>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Proof of Need <span class="text-red-500">*</span>
                            </label>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors">
                                <input type="file" name="proof" accept="image/*,application/pdf" required class="hidden" id="proofFile" />
                                <label for="proofFile" class="cursor-pointer">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                    </svg>
                                    <p class="text-sm text-gray-600 mb-1">Click to upload or drag and drop</p>
                                    <p class="text-xs text-gray-500">JPG, PNG, or PDF (Max 10MB)</p>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Upload temperature reading, medical certificate, or other proof of illness</p>
                        </div>
                        
                        <div class="mt-8 flex justify-end space-x-4">
                            <a href="<?php echo htmlspecialchars(base_url('resident/browse.php')); ?>" class="btn btn-secondary">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Submit Request
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
<script>
document.getElementById('reqFor').addEventListener('change', function() {
  const familyFields = document.getElementById('familyFields');
  const familyMemberSelect = document.getElementById('familyMemberSelect');
  
  if (this.value === 'family') {
    if (familyMemberSelect) {
      familyMemberSelect.style.display = 'block';
    }
    familyFields.style.display = 'block';
  } else {
    if (familyMemberSelect) {
      familyMemberSelect.style.display = 'none';
    }
    familyFields.style.display = 'none';
    // Clear family member data when switching to self
    clearFamilyData();
  }
});

// Auto-populate family member data when selected
document.addEventListener('DOMContentLoaded', function() {
  const familyMemberSelect = document.getElementById('familyMemberSelect');
  if (familyMemberSelect) {
    const select = familyMemberSelect.querySelector('select[name="family_member_id"]');
    if (select) {
      select.addEventListener('change', function() {
        if (this.value) {
          // Parse the selected option text to extract family member details
          const optionText = this.options[this.selectedIndex].text;
          populateFamilyData(optionText);
        } else {
          clearFamilyData();
        }
      });
    }
  }
});

function populateFamilyData(optionText) {
  // Parse format: "Name (Relationship, Age: XX)"
  const match = optionText.match(/^(.+?)\s*\((.+?),\s*Age:\s*(\d+)\)$/);
  
  if (match) {
    const name = match[1].trim();
    const relationship = match[2].trim();
    const age = match[3].trim();
    
    // Populate the form fields
    document.querySelector('input[name="patient_name"]').value = name;
    document.querySelector('input[name="patient_age"]').value = age;
    document.querySelector('input[name="relationship"]').value = relationship;
    
    // Disable the fields since they're auto-populated
    document.querySelector('input[name="patient_name"]').readOnly = true;
    document.querySelector('input[name="patient_age"]').readOnly = true;
    document.querySelector('input[name="relationship"]').readOnly = true;
  }
}

function clearFamilyData() {
  // Clear the form fields
  document.querySelector('input[name="patient_name"]').value = '';
  document.querySelector('input[name="patient_age"]').value = '';
  document.querySelector('input[name="relationship"]').value = '';
  
  // Re-enable the fields
  document.querySelector('input[name="patient_name"]').readOnly = false;
  document.querySelector('input[name="patient_age"]').readOnly = false;
  document.querySelector('input[name="relationship"]').readOnly = false;
}
</script>
</body>
</html>


