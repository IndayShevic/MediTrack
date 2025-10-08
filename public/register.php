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
    $errors = [];
    
    // Sanitize and validate input data
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $middle = trim($_POST['middle_initial'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    $purok_id = (int)($_POST['purok_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validation rules
    if (empty($first) || strlen($first) < 2) {
        $errors[] = 'First name must be at least 2 characters long.';
    } elseif (!preg_match('/^[a-zA-Z\s]+$/', $first)) {
        $errors[] = 'First name can only contain letters and spaces.';
    }
    
    if (empty($last) || strlen($last) < 2) {
        $errors[] = 'Last name must be at least 2 characters long.';
    } elseif (!preg_match('/^[a-zA-Z\s]+$/', $last)) {
        $errors[] = 'Last name can only contain letters and spaces.';
    }
    
    if (!empty($middle) && !preg_match('/^[a-zA-Z\s]+$/', $middle)) {
        $errors[] = 'Middle initial can only contain letters and spaces.';
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter, one lowercase letter, and one number.';
    }
    
    if (empty($dob)) {
        $errors[] = 'Please select your date of birth.';
    } else {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        if ($age < 0 || $age > 120) {
            $errors[] = 'Please enter a valid date of birth.';
        }
    }
    
    if ($purok_id <= 0) {
        $errors[] = 'Please select your purok.';
    }
    
    if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $checkEmail = db()->prepare('SELECT id FROM pending_residents WHERE email = ? UNION SELECT id FROM users WHERE email = ?');
            $checkEmail->execute([$email, $email]);
            if ($checkEmail->fetch()) {
                $errors[] = 'This email address is already registered.';
            }
        } catch (Throwable $e) {
            $errors[] = 'An error occurred while checking email availability.';
        }
    }
    
    // Family members data validation
    $family_members = [];
    if (isset($_POST['family_members']) && is_array($_POST['family_members'])) {
        foreach ($_POST['family_members'] as $index => $member) {
            $full_name = trim($member['full_name'] ?? '');
            $relationship = trim($member['relationship'] ?? '');
            $date_of_birth = $member['date_of_birth'] ?? '';
            
            // Only validate if at least one field is filled
            if (!empty($full_name) || !empty($relationship) || !empty($date_of_birth)) {
                if (empty($full_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Full name is required.";
                } elseif (!preg_match('/^[a-zA-Z\s]+$/', $full_name)) {
                    $errors[] = "Family member " . ($index + 1) . ": Full name can only contain letters and spaces.";
                }
                
                if (empty($relationship)) {
                    $errors[] = "Family member " . ($index + 1) . ": Relationship is required.";
                }
                
                if (empty($date_of_birth)) {
                    $errors[] = "Family member " . ($index + 1) . ": Date of birth is required.";
                } else {
                    $memberBirthDate = new DateTime($date_of_birth);
                    $memberAge = $today->diff($memberBirthDate)->y;
                    if ($memberAge < 0 || $memberAge > 120) {
                        $errors[] = "Family member " . ($index + 1) . ": Please enter a valid date of birth.";
                    }
                }
                
                if (empty($errors)) {
                    $family_members[] = [
                        'full_name' => $full_name,
                        'relationship' => $relationship,
                        'date_of_birth' => $date_of_birth
                    ];
                }
            }
        }
    }

    // If no validation errors, proceed with registration
    if (empty($errors)) {
        // Infer barangay from selected purok to avoid redundancy
        $barangay_id = 0;
        if ($purok_id > 0) {
            try {
                $q = db()->prepare('SELECT barangay_id FROM puroks WHERE id = ? LIMIT 1');
                $q->execute([$purok_id]);
                $row = $q->fetch();
                if ($row) { 
                    $barangay_id = (int)$row['barangay_id']; 
                } else {
                    $errors[] = 'Invalid purok selected.';
                }
            } catch (Throwable $e) { 
                $errors[] = 'An error occurred while validating purok selection.';
            }
        }
        
        if (empty($errors)) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $hash = password_hash($password, PASSWORD_BCRYPT);
                
                // Insert into pending_residents table
                $insPending = $pdo->prepare('INSERT INTO pending_residents(email, password_hash, first_name, last_name, middle_initial, date_of_birth, phone, address, barangay_id, purok_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $insPending->execute([$email, $hash, $first, $last, $middle, $dob, $phone, $address, $barangay_id, $purok_id]);
                $pendingId = (int)$pdo->lastInsertId();
                
                // Insert family members
                if (!empty($family_members)) {
                    $insFamily = $pdo->prepare('INSERT INTO pending_family_members(pending_resident_id, full_name, relationship, date_of_birth) VALUES(?,?,?,?)');
                    foreach ($family_members as $member) {
                        $insFamily->execute([$pendingId, $member['full_name'], $member['relationship'], $member['date_of_birth']]);
                    }
                }
                
                $pdo->commit();
                
                // Notify assigned BHW about new registration
                try {
                    $bhwStmt = db()->prepare('SELECT u.email, u.first_name, u.last_name, p.name as purok_name FROM users u JOIN puroks p ON p.id = u.purok_id WHERE u.role = "bhw" AND u.purok_id = ? LIMIT 1');
                    $bhwStmt->execute([$purok_id]);
                    $bhw = $bhwStmt->fetch();
                    
                    if ($bhw) {
                        $bhwName = format_full_name($bhw['first_name'] ?? '', $bhw['last_name'] ?? '', $bhw['middle_initial'] ?? null);
                        $residentName = format_full_name($first, $last, $middle);
                        $success = send_new_registration_notification_to_bhw($bhw['email'], $bhwName, $residentName, $bhw['purok_name']);
                        log_email_notification(0, 'new_registration', 'New Registration', 'New resident registration notification sent to BHW', $success);
                    }
                } catch (Throwable $e) {
                    // Log error silently
                    error_log('Email notification failed: ' . $e->getMessage());
                }
                
                set_flash('Registration submitted successfully! Your account is pending approval from your Barangay Health Worker. You will receive an email notification once your account is approved.','success');
                redirect_to('../index.php?registered=1');
            } catch (Throwable $e) {
                if (isset($pdo)) $pdo->rollBack();
                error_log('Registration failed: ' . $e->getMessage());
                set_flash('Registration failed due to a system error. Please try again later.','error');
                redirect_to('../index.php?modal=register');
            }
        }
    }
    
    // If there are validation errors, display them
    if (!empty($errors)) {
        $errorMessage = implode('<br>', $errors);
        set_flash($errorMessage, 'error');
        redirect_to('../index.php?modal=register');
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .form-input {
            @apply w-full border border-gray-300 rounded-lg px-4 py-3 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all duration-200;
        }
        .form-input.error {
            @apply border-red-500 focus:ring-red-500;
        }
        .form-label {
            @apply block text-sm font-medium text-gray-700 mb-2;
        }
        .error-message {
            @apply text-red-600 text-sm mt-1 hidden;
        }
        .success-message {
            @apply text-green-600 text-sm mt-1 hidden;
        }
        .loading {
            @apply opacity-50 pointer-events-none;
        }
        .btn-primary {
            @apply w-full bg-primary-600 text-white py-3 px-4 rounded-lg font-medium hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed;
        }
        .btn-secondary {
            @apply text-primary-600 hover:text-primary-700 text-sm font-medium transition-colors duration-200;
        }
        .section-card {
            @apply bg-white border border-gray-200 rounded-xl p-6 shadow-sm;
        }
        .step-indicator {
            @apply flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium;
        }
        .step-active {
            @apply bg-primary-600 text-white;
        }
        .step-completed {
            @apply bg-green-500 text-white;
        }
        .step-pending {
            @apply bg-gray-200 text-gray-600;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 font-sans">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-4xl">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Create Resident Account</h1>
                <p class="text-gray-600">Join MediTrack to manage your medicine requests</p>
            </div>

            <!-- Progress Steps -->
            <div class="flex items-center justify-center mb-8">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <div class="step-indicator step-active" id="step-1">1</div>
                        <span class="ml-2 text-sm font-medium text-gray-900">Personal Info</span>
                    </div>
                    <div class="w-8 h-0.5 bg-gray-300"></div>
                    <div class="flex items-center">
                        <div class="step-indicator step-pending" id="step-2">2</div>
                        <span class="ml-2 text-sm font-medium text-gray-500">Family Members</span>
                    </div>
                    <div class="w-8 h-0.5 bg-gray-300"></div>
                    <div class="flex items-center">
                        <div class="step-indicator step-pending" id="step-3">3</div>
                        <span class="ml-2 text-sm font-medium text-gray-500">Review</span>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php [$flash,$ft] = get_flash(); if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $ft==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?>">
                    <div class="flex items-center">
                        <?php if($ft==='success'): ?>
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        <?php else: ?>
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($flash); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" id="registration-form" class="space-y-8" novalidate>
                <!-- Step 1: Personal Information -->
                <div class="section-card" id="step-1-content">
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Personal Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="form-label" for="first_name">First Name <span class="text-red-500">*</span></label>
                            <input type="text" id="first_name" name="first_name" required class="form-input" placeholder="Enter your first name" />
                            <div class="error-message" id="first_name_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="last_name">Last Name <span class="text-red-500">*</span></label>
                            <input type="text" id="last_name" name="last_name" required class="form-input" placeholder="Enter your last name" />
                            <div class="error-message" id="last_name_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="middle_initial">Middle Initial</label>
                            <input type="text" id="middle_initial" name="middle_initial" class="form-input" placeholder="M.I." maxlength="10" />
                            <div class="error-message" id="middle_initial_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="email">Email Address <span class="text-red-500">*</span></label>
                            <input type="email" id="email" name="email" required class="form-input" placeholder="Enter your email address" />
                            <div class="error-message" id="email_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="password">Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" id="password" name="password" required class="form-input pr-10" placeholder="Create a strong password" />
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center" onclick="togglePassword('password')">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" id="password-eye">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                            <div class="error-message" id="password_error"></div>
                            <div class="mt-2">
                                <div class="text-xs text-gray-600">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 rounded-full bg-gray-300" id="length-check"></div>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 rounded-full bg-gray-300" id="uppercase-check"></div>
                                        <span>One uppercase letter</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 rounded-full bg-gray-300" id="lowercase-check"></div>
                                        <span>One lowercase letter</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 rounded-full bg-gray-300" id="number-check"></div>
                                        <span>One number</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="date_of_birth">Date of Birth <span class="text-red-500">*</span></label>
                            <input type="date" id="date_of_birth" name="date_of_birth" required class="form-input" />
                            <div class="error-message" id="date_of_birth_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-input" placeholder="Enter your phone number" />
                            <div class="error-message" id="phone_error"></div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="form-label" for="purok_id">Purok <span class="text-red-500">*</span></label>
                            <select id="purok_id" name="purok_id" required class="form-input">
                                <option value="">Select your purok</option>
                                <?php foreach ($puroks as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name'] . ' - ' . $p['barangay']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="error-message" id="purok_id_error"></div>
                        </div>
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="button" class="btn-primary max-w-xs" onclick="nextStep()">Next: Family Members</button>
                    </div>
                </div>
                
                <!-- Step 2: Family Members -->
                <div class="section-card hidden" id="step-2-content">
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Family Members (Optional)</h2>
                    <p class="text-gray-600 mb-6">Add family members who may also need medicine requests. You can skip this step if you don't have any family members to add.</p>
                    
                    <div id="family-members-container">
                        <div class="family-member bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-medium text-gray-700">Family Member 1</h3>
                                <button type="button" class="text-red-500 hover:text-red-700 text-sm hidden remove-family-member">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="family_members[0][full_name]" class="form-input" placeholder="e.g., Juan Dela Cruz" />
                                    <div class="error-message" id="family_member_0_full_name_error"></div>
                                </div>
                                <div>
                                    <label class="form-label">Relationship</label>
                                    <select name="family_members[0][relationship]" class="form-input">
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
                                    <div class="error-message" id="family_member_0_relationship_error"></div>
                                </div>
                                <div>
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" name="family_members[0][date_of_birth]" class="form-input" />
                                    <div class="error-message" id="family_member_0_date_of_birth_error"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-family-member" class="btn-secondary flex items-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        <span>Add Family Member</span>
                    </button>
                    
                    <div class="flex justify-between mt-8">
                        <button type="button" class="btn-secondary" onclick="prevStep()">Back</button>
                        <button type="button" class="btn-primary max-w-xs" onclick="nextStep()">Next: Review</button>
                    </div>
                </div>
                
                <!-- Step 3: Review -->
                <div class="section-card hidden" id="step-3-content">
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">Review Your Information</h2>
                    <p class="text-gray-600 mb-6">Please review your information before submitting for approval.</p>
                    
                    <div class="space-y-6">
                        <!-- Personal Information Review -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Personal Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-600">Name:</span>
                                    <span class="ml-2 font-medium" id="review-name"></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Email:</span>
                                    <span class="ml-2 font-medium" id="review-email"></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Date of Birth:</span>
                                    <span class="ml-2 font-medium" id="review-dob"></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Phone:</span>
                                    <span class="ml-2 font-medium" id="review-phone"></span>
                                </div>
                                <div class="md:col-span-2">
                                    <span class="text-gray-600">Purok:</span>
                                    <span class="ml-2 font-medium" id="review-purok"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Family Members Review -->
                        <div class="bg-gray-50 rounded-lg p-4" id="family-review-section" style="display: none;">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Family Members</h3>
                            <div id="family-review-content"></div>
                        </div>
                    </div>
                    
                    <div class="flex justify-between mt-8">
                        <button type="button" class="btn-secondary" onclick="prevStep()">Back</button>
                        <button type="submit" class="btn-primary max-w-xs" id="submit-btn">
                            <span id="submit-text">Submit for Approval</span>
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" id="submit-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </form>
            
            <div class="text-center mt-8">
                <p class="text-gray-600">Already have an account? 
                    <a class="text-primary-600 hover:text-primary-700 font-medium" href="<?php echo htmlspecialchars(base_url('../index.php')); ?>">Sign in</a>
                </p>
            </div>
        </div>
    </div>
    
    <script>
        let currentStep = 1;
        let familyMemberCount = 1;
        
        // Form validation rules
        const validationRules = {
            first_name: {
                required: true,
                minLength: 2,
                pattern: /^[a-zA-Z\s]+$/,
                message: 'First name must be at least 2 characters and contain only letters'
            },
            last_name: {
                required: true,
                minLength: 2,
                pattern: /^[a-zA-Z\s]+$/,
                message: 'Last name must be at least 2 characters and contain only letters'
            },
            middle_initial: {
                required: false,
                pattern: /^[a-zA-Z\s]*$/,
                message: 'Middle initial can only contain letters'
            },
            email: {
                required: true,
                pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                message: 'Please enter a valid email address'
            },
            password: {
                required: true,
                minLength: 8,
                pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/,
                message: 'Password must be at least 8 characters with uppercase, lowercase, and number'
            },
            date_of_birth: {
                required: true,
                message: 'Please select your date of birth'
            },
            phone: {
                required: false,
                pattern: /^[\+]?[0-9\s\-\(\)]+$/,
                message: 'Please enter a valid phone number'
            },
            purok_id: {
                required: true,
                message: 'Please select your purok'
            }
        };
        
        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            initializeForm();
            setupPasswordValidation();
            setupStepNavigation();
        });
        
        function initializeForm() {
            // Add real-time validation to all inputs
            Object.keys(validationRules).forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (field) {
                    field.addEventListener('blur', () => validateField(fieldName));
                    field.addEventListener('input', () => clearError(fieldName));
                }
            });
            
            // Setup family member management
            setupFamilyMemberManagement();
        }
        
        function setupPasswordValidation() {
            const passwordField = document.getElementById('password');
            if (passwordField) {
                passwordField.addEventListener('input', function() {
                    const password = this.value;
                    updatePasswordRequirements(password);
                });
            }
        }
        
        function updatePasswordRequirements(password) {
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /\d/.test(password)
            };
            
            Object.keys(checks).forEach(check => {
                const element = document.getElementById(`${check}-check`);
                if (element) {
                    element.className = `w-2 h-2 rounded-full ${checks[check] ? 'bg-green-500' : 'bg-gray-300'}`;
                }
            });
        }
        
        function validateField(fieldName) {
            const field = document.getElementById(fieldName);
            const rule = validationRules[fieldName];
            const value = field.value.trim();
            
            if (rule.required && !value) {
                showError(fieldName, `${getFieldLabel(fieldName)} is required`);
                return false;
            }
            
            if (value && rule.minLength && value.length < rule.minLength) {
                showError(fieldName, `${getFieldLabel(fieldName)} must be at least ${rule.minLength} characters`);
                return false;
            }
            
            if (value && rule.pattern && !rule.pattern.test(value)) {
                showError(fieldName, rule.message);
                return false;
            }
            
            if (fieldName === 'date_of_birth' && value) {
                const birthDate = new Date(value);
                const today = new Date();
                const age = today.getFullYear() - birthDate.getFullYear();
                
                if (age < 0 || age > 120) {
                    showError(fieldName, 'Please enter a valid date of birth');
                    return false;
                }
            }
            
            clearError(fieldName);
            return true;
        }
        
        function showError(fieldName, message) {
            const field = document.getElementById(fieldName);
            const errorElement = document.getElementById(`${fieldName}_error`);
            
            if (field) field.classList.add('error');
            if (errorElement) {
                errorElement.textContent = message;
                errorElement.classList.remove('hidden');
            }
        }
        
        function clearError(fieldName) {
            const field = document.getElementById(fieldName);
            const errorElement = document.getElementById(`${fieldName}_error`);
            
            if (field) field.classList.remove('error');
            if (errorElement) {
                errorElement.classList.add('hidden');
            }
        }
        
        function getFieldLabel(fieldName) {
            const labels = {
                first_name: 'First name',
                last_name: 'Last name',
                middle_initial: 'Middle initial',
                email: 'Email',
                password: 'Password',
                date_of_birth: 'Date of birth',
                phone: 'Phone number',
                purok_id: 'Purok'
            };
            return labels[fieldName] || fieldName;
        }
        
        function validateStep(step) {
            let isValid = true;
            
            if (step === 1) {
                const requiredFields = ['first_name', 'last_name', 'email', 'password', 'date_of_birth', 'purok_id'];
                requiredFields.forEach(field => {
                    if (!validateField(field)) {
                        isValid = false;
                    }
                });
            }
            
            return isValid;
        }
        
        function setupStepNavigation() {
            // Step navigation will be handled by nextStep() and prevStep() functions
        }
        
        function nextStep() {
            if (currentStep === 1) {
                if (!validateStep(1)) {
                    return;
                }
                currentStep = 2;
                showStep(2);
                updateStepIndicators();
            } else if (currentStep === 2) {
                currentStep = 3;
                showStep(3);
                updateStepIndicators();
                populateReviewData();
            }
        }
        
        function prevStep() {
            if (currentStep === 2) {
                currentStep = 1;
                showStep(1);
                updateStepIndicators();
            } else if (currentStep === 3) {
                currentStep = 2;
                showStep(2);
                updateStepIndicators();
            }
        }
        
        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('[id$="-content"]').forEach(el => {
                el.classList.add('hidden');
            });
            
            // Show current step
            const currentStepElement = document.getElementById(`step-${step}-content`);
            if (currentStepElement) {
                currentStepElement.classList.remove('hidden');
            }
        }
        
        function updateStepIndicators() {
            for (let i = 1; i <= 3; i++) {
                const indicator = document.getElementById(`step-${i}`);
                const label = indicator.nextElementSibling;
                
                indicator.className = 'step-indicator';
                label.className = 'ml-2 text-sm font-medium';
                
                if (i < currentStep) {
                    indicator.classList.add('step-completed');
                    label.classList.add('text-gray-900');
                } else if (i === currentStep) {
                    indicator.classList.add('step-active');
                    label.classList.add('text-gray-900');
                } else {
                    indicator.classList.add('step-pending');
                    label.classList.add('text-gray-500');
                }
            }
        }
        
        function populateReviewData() {
            // Personal information
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;
            const middleInitial = document.getElementById('middle_initial').value;
            const email = document.getElementById('email').value;
            const dob = document.getElementById('date_of_birth').value;
            const phone = document.getElementById('phone').value;
            const purokSelect = document.getElementById('purok_id');
            const purokText = purokSelect.options[purokSelect.selectedIndex].text;
            
            // Format name
            let fullName = `${firstName} ${lastName}`;
            if (middleInitial.trim()) {
                fullName = `${firstName} ${middleInitial} ${lastName}`;
            }
            
            document.getElementById('review-name').textContent = fullName;
            document.getElementById('review-email').textContent = email;
            document.getElementById('review-dob').textContent = formatDate(dob);
            document.getElementById('review-phone').textContent = phone || 'Not provided';
            document.getElementById('review-purok').textContent = purokText;
            
            // Family members
            populateFamilyReview();
        }
        
        function populateFamilyReview() {
            const familyMembers = document.querySelectorAll('.family-member');
            const familyReviewSection = document.getElementById('family-review-section');
            const familyReviewContent = document.getElementById('family-review-content');
            
            let hasFamilyMembers = false;
            let reviewHTML = '';
            
            familyMembers.forEach((member, index) => {
                const fullName = member.querySelector('input[name*="[full_name]"]').value.trim();
                const relationship = member.querySelector('select[name*="[relationship]"]').value;
                const dob = member.querySelector('input[name*="[date_of_birth]"]').value;
                
                if (fullName || relationship || dob) {
                    hasFamilyMembers = true;
                    reviewHTML += `
                        <div class="border-b border-gray-200 pb-3 mb-3 last:border-b-0 last:pb-0 last:mb-0">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-600">Name:</span>
                                    <span class="ml-2 font-medium">${fullName || 'Not provided'}</span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Relationship:</span>
                                    <span class="ml-2 font-medium">${relationship || 'Not provided'}</span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Date of Birth:</span>
                                    <span class="ml-2 font-medium">${dob ? formatDate(dob) : 'Not provided'}</span>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });
            
            if (hasFamilyMembers) {
                familyReviewContent.innerHTML = reviewHTML;
                familyReviewSection.style.display = 'block';
            } else {
                familyReviewSection.style.display = 'none';
            }
        }
        
        function formatDate(dateString) {
            if (!dateString) return 'Not provided';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
        
        function setupFamilyMemberManagement() {
            document.getElementById('add-family-member').addEventListener('click', function() {
                addFamilyMember();
            });
        }
        
        function addFamilyMember() {
            const container = document.getElementById('family-members-container');
            const newMember = document.createElement('div');
            newMember.className = 'family-member bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4';
            newMember.innerHTML = `
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-medium text-gray-700">Family Member ${familyMemberCount + 1}</h3>
                    <button type="button" class="text-red-500 hover:text-red-700 text-sm remove-family-member">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="form-label">Full Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][full_name]" class="form-input" placeholder="e.g., Juan Dela Cruz" />
                        <div class="error-message" id="family_member_${familyMemberCount}_full_name_error"></div>
                    </div>
                    <div>
                        <label class="form-label">Relationship</label>
                        <select name="family_members[${familyMemberCount}][relationship]" class="form-input">
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
                        <div class="error-message" id="family_member_${familyMemberCount}_relationship_error"></div>
                    </div>
                    <div>
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="family_members[${familyMemberCount}][date_of_birth]" class="form-input" />
                        <div class="error-message" id="family_member_${familyMemberCount}_date_of_birth_error"></div>
                    </div>
                </div>
            `;
            
            container.appendChild(newMember);
            familyMemberCount++;
            
            // Add remove functionality
            newMember.querySelector('.remove-family-member').addEventListener('click', function() {
                newMember.remove();
                updateRemoveButtons();
            });
            
            // Show remove button for first member if there are multiple
            updateRemoveButtons();
        }
        
        function updateRemoveButtons() {
            const familyMembers = document.querySelectorAll('.family-member');
            familyMembers.forEach((member, index) => {
                const removeBtn = member.querySelector('.remove-family-member');
                if (familyMembers.length > 1) {
                    removeBtn.classList.remove('hidden');
                } else {
                    removeBtn.classList.add('hidden');
                }
            });
        }
        
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const eye = document.getElementById(`${fieldId}-eye`);
            
            if (field.type === 'password') {
                field.type = 'text';
                eye.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                `;
            } else {
                field.type = 'password';
                eye.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                `;
            }
        }
        
        // Form submission
        document.getElementById('registration-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate all steps
            if (!validateStep(1)) {
                showStep(1);
                currentStep = 1;
                updateStepIndicators();
                return;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submit-btn');
            const submitText = document.getElementById('submit-text');
            const submitSpinner = document.getElementById('submit-spinner');
            
            submitBtn.disabled = true;
            submitText.textContent = 'Submitting...';
            submitSpinner.classList.remove('hidden');
            
            // Submit form
            this.submit();
        });
        
        // Initialize remove buttons
        updateRemoveButtons();
    </script>
</body>
</html>


