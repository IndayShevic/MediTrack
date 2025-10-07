<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/email_notifications.php';

// If already logged in, send to their dashboard
$u = current_user();
if ($u) {
    if ($u['role'] === 'super_admin') redirect_to('super_admin/dashboard.php');
    if ($u['role'] === 'bhw') redirect_to('bhw/dashboard.php');
    redirect_to('resident/dashboard.php');
}

$barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
$puroks = db()->query('SELECT p.id, p.name, b.name AS barangay, p.barangay_id FROM puroks p JOIN barangays b ON b.id=p.barangay_id ORDER BY b.name, p.name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $barangay_id = (int)($_POST['barangay_id'] ?? 0);
    $purok_id = (int)($_POST['purok_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Family members data
    $family_members = [];
    if (isset($_POST['family_members']) && is_array($_POST['family_members'])) {
        foreach ($_POST['family_members'] as $member) {
            if (!empty($member['full_name']) && !empty($member['relationship']) && !empty($member['age'])) {
                $family_members[] = [
                    'full_name' => trim($member['full_name']),
                    'relationship' => trim($member['relationship']),
                    'age' => (int)$member['age']
                ];
            }
        }
    }

    if ($email && $password && $first && $last && $dob && $purok_id > 0 && $barangay_id > 0) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Insert into pending_residents table
            $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, date_of_birth, phone, address, barangay_id, purok_id) VALUES(?,?,?,?,?,?,?,?,?)');
            $insPending->execute([$email, $hash, $first, $last, $dob, $phone, $address, $barangay_id, $purok_id]);
            $pendingId = (int)$pdo->lastInsertId();
            
            // Insert family members
            if (!empty($family_members)) {
                $insFamily = $pdo->prepare('INSERT INTO pending_family_members(pending_resident_id, full_name, relationship, age) VALUES(?,?,?,?)');
                foreach ($family_members as $member) {
                    $insFamily->execute([$pendingId, $member['full_name'], $member['relationship'], $member['age']]);
                }
            }
            
            $pdo->commit();
            
            // Notify assigned BHW about new registration
            try {
                $bhwStmt = db()->prepare('SELECT u.email, u.first_name, u.last_name, p.name as purok_name FROM users u JOIN puroks p ON p.id = u.purok_id WHERE u.role = "bhw" AND u.purok_id = ? LIMIT 1');
                $bhwStmt->execute([$purok_id]);
                $bhw = $bhwStmt->fetch();
                
                if ($bhw) {
                    $bhwName = trim(($bhw['first_name'] ?? '') . ' ' . ($bhw['last_name'] ?? ''));
                    $residentName = $first . ' ' . $last;
                    $success = send_new_registration_notification_to_bhw($bhw['email'], $bhwName, $residentName, $bhw['purok_name']);
                    log_email_notification(0, 'new_registration', 'New Registration', 'New resident registration notification sent to BHW', $success);
                }
            } catch (Throwable $e) {
                // Log error silently
            }
            
            set_flash('Registration submitted for approval. You will receive an email once approved.','success');
            redirect_to('../index.php?registered=1');
        } catch (Throwable $e) {
            if (isset($pdo)) $pdo->rollBack();
            set_flash('Registration failed. Email may already exist.','error');
        }
    } else {
        set_flash('Please fill all required fields.','error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Register · MediTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-white shadow rounded-lg p-6">
        <h1 class="text-2xl font-semibold text-gray-800 mb-4 text-center">Create Resident Account</h1>
        <?php [$flash,$ft] = get_flash(); if ($flash): ?>
            <div class="mb-4 px-4 py-2 rounded <?php echo $ft==='success'?'bg-green-50 text-green-700':'bg-red-50 text-red-700'; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>
        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1">First Name</label>
                <input name="first_name" required class="w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Last Name</label>
                <input name="last_name" required class="w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Email</label>
                <input type="email" name="email" required class="w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Date of Birth</label>
                <input type="date" name="date_of_birth" required class="w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">Phone</label>
                <input name="phone" class="w-full border rounded px-3 py-2" />
            </div>
            <div class="md:col-span-1">
                <label class="block text-sm text-gray-700 mb-1">Barangay</label>
                <select name="barangay_id" id="barangay-select" required class="w-full border rounded px-3 py-2">
                    <option value="">Select Barangay First</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-1">
                <label class="block text-sm text-gray-700 mb-1">Purok</label>
                <select name="purok_id" id="purok-select" required class="w-full border rounded px-3 py-2" disabled>
                    <option value="">Select Barangay First</option>
                </select>
            </div>
            
            <!-- Family Members Section -->
            <div class="md:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Family Members (Optional)</h3>
                <div id="family-members-container">
                    <div class="family-member border rounded p-4 mb-3">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Full Name</label>
                                <input type="text" name="family_members[0][full_name]" class="w-full border rounded px-3 py-2" placeholder="e.g., Juan Dela Cruz" />
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Relationship</label>
                                <select name="family_members[0][relationship]" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Relationship</option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Son">Son</option>
                                    <option value="Daughter">Daughter</option>
                                    <option value="Brother">Brother</option>
                                    <option value="Sister">Sister</option>
                                    <option value="Grandfather">Grandfather</option>
                                    <option value="Grandmother">Grandmother</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Age</label>
                                <input type="number" name="family_members[0][age]" class="w-full border rounded px-3 py-2" placeholder="Age" min="0" max="120" />
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" id="add-family-member" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                    + Add Family Member
                </button>
            </div>
            
            <div class="md:col-span-2">
                <button class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">Submit for Approval</button>
            </div>
        </form>
        <p class="text-center text-sm text-gray-600 mt-4">Already have an account? <a class="text-blue-600 hover:underline" href="<?php echo htmlspecialchars(base_url('../index.php')); ?>">Sign in</a></p>
    </div>
    
    <script>
        // Barangay and Purok dynamic dropdown functionality
        document.getElementById('barangay-select').addEventListener('change', function() {
            const barangayId = this.value;
            const purokSelect = document.getElementById('purok-select');
            
            // Clear purok options
            purokSelect.innerHTML = '<option value="">Select Purok</option>';
            
            if (barangayId) {
                // Enable purok dropdown
                purokSelect.disabled = false;
                
                // Fetch puroks for selected barangay
                const puroks = <?php echo json_encode($puroks); ?>;
                const barangayPuroks = puroks.filter(p => p.barangay_id == barangayId);
                
                barangayPuroks.forEach(purok => {
                    const option = document.createElement('option');
                    option.value = purok.id;
                    option.textContent = purok.name;
                    purokSelect.appendChild(option);
                });
            } else {
                // Disable purok dropdown
                purokSelect.disabled = true;
                purokSelect.innerHTML = '<option value="">Select Barangay First</option>';
            }
        });
        
        let familyMemberCount = 1;
        
        document.getElementById('add-family-member').addEventListener('click', function() {
            const container = document.getElementById('family-members-container');
            const newMember = document.createElement('div');
            newMember.className = 'family-member border rounded p-4 mb-3';
            newMember.innerHTML = `
                <div class="flex justify-between items-center mb-2">
                    <h4 class="font-medium text-gray-700">Family Member ${familyMemberCount + 1}</h4>
                    <button type="button" class="remove-family-member text-red-600 hover:text-red-700 text-sm">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Full Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][full_name]" class="w-full border rounded px-3 py-2" placeholder="e.g., Juan Dela Cruz" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Relationship</label>
                        <select name="family_members[${familyMemberCount}][relationship]" class="w-full border rounded px-3 py-2">
                            <option value="">Select Relationship</option>
                            <option value="Father">Father</option>
                            <option value="Mother">Mother</option>
                            <option value="Son">Son</option>
                            <option value="Daughter">Daughter</option>
                            <option value="Brother">Brother</option>
                            <option value="Sister">Sister</option>
                            <option value="Grandfather">Grandfather</option>
                            <option value="Grandmother">Grandmother</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Age</label>
                        <input type="number" name="family_members[${familyMemberCount}][age]" class="w-full border rounded px-3 py-2" placeholder="Age" min="0" max="120" />
                    </div>
                </div>
            `;
            container.appendChild(newMember);
            familyMemberCount++;
            
            // Add remove functionality
            newMember.querySelector('.remove-family-member').addEventListener('click', function() {
                newMember.remove();
            });
        });
    </script>
</body>
</html>


