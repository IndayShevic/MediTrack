<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['resident']);
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../../config/mail.php';

// Helper function to get upload URL (uploads are at project root, not in public/)
function upload_url(string $path): string {
    // Remove leading slash if present
    $clean_path = ltrim($path, '/');
    
    // Get base path without /public/
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

// Get updated user data with profile image (same approach as profile.php)
$userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$user['id']]);
$user_data = $userStmt->fetch() ?: [];
if (!empty($user_data)) {
    // Merge all user data including profile_image
    $user = array_merge($user, $user_data);
    // Also update session for consistency
    if (isset($user_data['profile_image'])) {
        $_SESSION['user']['profile_image'] = $user_data['profile_image'];
    }
} else {
    // If fetch failed, initialize empty array to prevent errors
    $user_data = [];
}

// Ensure user_data always has profile_image key for consistency
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Get resident ID
$stmt = db()->prepare('SELECT id, purok_id FROM residents WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$resident = $stmt->fetch();

if (!$resident) {
    redirect_to('index.php');
}

$resident_id = (int)$resident['id'];
$purok_id = (int)$resident['purok_id'];

// Get resident info for senior citizen check
$is_senior = false;
if ($resident) {
    $dobStmt = db()->prepare('SELECT date_of_birth FROM residents WHERE id = ? LIMIT 1');
    $dobStmt->execute([$resident_id]);
    $dobRow = $dobStmt->fetch();
    if ($dobRow && !empty($dobRow['date_of_birth'])) {
        $birth_date = new DateTime($dobRow['date_of_birth']);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        $is_senior = $age >= 60;
    }
}

// Get pending requests count for notifications
$pending_requests = 0;
try {
    $pendingStmt = db()->prepare('SELECT COUNT(*) as count FROM requests WHERE resident_id = ? AND status = "submitted"');
    $pendingStmt->execute([$resident_id]);
    $pending_requests = (int)$pendingStmt->fetch()['count'];
} catch (Throwable $e) {
    $pending_requests = 0;
}

// Get recent requests for header notifications
$recent_requests = [];
try {
    $recentStmt = db()->prepare('SELECT r.id, r.status, r.created_at, m.name AS medicine_name FROM requests r LEFT JOIN medicines m ON r.medicine_id = m.id WHERE r.resident_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $recentStmt->execute([$resident_id]);
    $recent_requests = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $recent_requests = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_family_member') {
        // Sanitize input data - remove banned characters and prevent HTML/script injection
        function sanitizeInputBackend($value, $pattern = null) {
            if (empty($value)) return '';
            
            // Remove script tags and HTML tags (prevent XSS)
            $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', (string)$value);
            $sanitized = preg_replace('/<[^>]+>/', '', $sanitized);
            
            // Remove banned characters: !@#$%^&*()={}[]:;"<>?/\|~`_
            $banned = '/[!@#$%^&*()={}\[\]:;"<>?\/\\\|~`_]/';
            $sanitized = preg_replace($banned, '', $sanitized);
            
            // Remove control characters and emojis
            $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $sanitized);
            $sanitized = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $sanitized);
            
            // Trim leading/trailing spaces
            $sanitized = trim($sanitized);
            
            // Apply pattern if provided
            if ($pattern && $sanitized) {
                $sanitized = preg_replace('/[^' . $pattern . ']/', '', $sanitized);
            }
            
            return $sanitized;
        }
        
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $relationship_other = trim($_POST['relationship_other'] ?? '');
        $dob = $_POST['date_of_birth'] ?? '';
        
        // If "Other" is selected, use the custom relationship text
        if ($relationship === 'Other' && !empty($relationship_other)) {
            $relationship = sanitizeInputBackend($relationship_other, 'A-Za-zÀ-ÿ\' -');
        }
        
        $errors = [];
        
        // Validate first name (letters only, no digits)
        if (empty($first_name) || strlen($first_name) < 2) {
            $errors[] = 'First name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $first_name)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $first_name)) {
            $errors[] = 'First name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $first_name = sanitizeInputBackend($first_name, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Validate last name (letters only, no digits)
        if (empty($last_name) || strlen($last_name) < 2) {
            $errors[] = 'Last name must be at least 2 characters long.';
        } elseif (preg_match('/\d/', $last_name)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } elseif (!preg_match('/^[A-Za-zÀ-ÿ\' -]+$/', $last_name)) {
            $errors[] = 'Last name: Only letters, spaces, hyphens, and apostrophes are allowed.';
        } else {
            $last_name = sanitizeInputBackend($last_name, 'A-Za-zÀ-ÿ\' -');
        }
        
        // Middle initial validation (only 1 character, only letters)
        if (!empty($middle_initial)) {
            $middle_initial = sanitizeInputBackend($middle_initial, 'A-Za-zÀ-ÿ');
            if (strlen($middle_initial) > 1) {
                $errors[] = 'Middle initial can only be 1 character.';
            } elseif (!preg_match('/^[A-Za-zÀ-ÿ]+$/', $middle_initial)) {
                $errors[] = 'Middle initial can only contain letters.';
        }
        }
        
        // Suffix validation (only allowed values)
        if (!empty($suffix)) {
            $allowed_suffixes = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
            if (!in_array($suffix, $allowed_suffixes)) {
                $errors[] = 'Invalid suffix selected.';
        }
        }
        
        // Relationship validation
        if (empty($relationship)) {
            $errors[] = 'Please select a relationship.';
        } elseif ($relationship === 'Other' && empty($relationship_other)) {
            $errors[] = 'Please specify the relationship when "Other" is selected.';
        }
        
        // Date of birth validation
        if (empty($dob)) {
            $errors[] = 'Please provide date of birth.';
        } else {
            $birthDate = new DateTime($dob);
            $today = new DateTime();
            
            if ($birthDate > $today) {
                $errors[] = 'Date of birth cannot be in the future.';
            } else {
            $age = $today->diff($birthDate)->y;
            if ($age < 0 || $age > 120) {
                $errors[] = 'Please enter a valid date of birth.';
                }
            }
        }
        
        if (empty($errors)) {
            // Define full_name here for the queries
            $full_name = format_full_name($first_name, $last_name, $middle_initial, $suffix ?: null);

            // First check for exact duplicates (same name AND date of birth)
            $exact_duplicate_check = db()->prepare('
                -- Check approved family members in same account (exact match)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_same" as status,
                    fm.full_name,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.full_name)) = LOWER(TRIM(?)) 
                AND fm.date_of_birth = ?
                AND fm.resident_id = ?
                
                UNION ALL
                
                -- Check pending family members in same account (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_same" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id = ?
                AND rfa.status = "pending"
                
                UNION ALL
                
                -- Check approved family members in different accounts (exact match)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_different" as status,
                    fm.full_name,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.full_name)) = LOWER(TRIM(?)) 
                AND fm.date_of_birth = ?
                AND fm.resident_id != ?
                
                UNION ALL
                
                -- Check pending family members in different accounts (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_different" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id != ?
                AND rfa.status = "pending"
                
                UNION ALL
                
                -- Check approved pending family members in different accounts (exact match)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_pending_different" as status,
                    rfa.full_name,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.full_name)) = LOWER(TRIM(?)) 
                AND rfa.date_of_birth = ?
                AND rfa.resident_id != ?
                AND rfa.status = "approved"
            ');
            $exact_duplicate_check->execute([
                $full_name, $dob, $resident_id,  // approved_same
                $full_name, $dob, $resident_id,  // pending_same
                $full_name, $dob, $resident_id,  // approved_different
                $full_name, $dob, $resident_id,  // pending_different
                $full_name, $dob, $resident_id   // approved_pending_different
            ]);
            $exact_duplicate = $exact_duplicate_check->fetch();
            
            // Check for name-only duplicates in same account (same person, different relationship)
            $name_duplicate_check = db()->prepare('
                -- Check approved family members in same account (name only)
                SELECT 
                    fm.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "approved_same_name" as status,
                    fm.first_name as fm_first_name,
                    fm.middle_initial as fm_middle_initial,
                    fm.last_name as fm_last_name,
                    fm.suffix as fm_suffix,
                    fm.date_of_birth,
                    fm.relationship
                FROM family_members fm
                JOIN residents r ON r.id = fm.resident_id
                WHERE LOWER(TRIM(fm.first_name)) = LOWER(TRIM(?)) 
                AND LOWER(TRIM(fm.last_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(COALESCE(fm.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND LOWER(TRIM(COALESCE(fm.suffix, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND fm.resident_id = ?
                
                UNION ALL
                
                -- Check pending family members in same account (name only)
                SELECT 
                    rfa.resident_id,
                    r.first_name,
                    r.last_name,
                    r.middle_initial,
                    "pending_same_name" as status,
                    rfa.first_name as fm_first_name,
                    rfa.middle_initial as fm_middle_initial,
                    rfa.last_name as fm_last_name,
                    rfa.suffix as fm_suffix,
                    rfa.date_of_birth,
                    rfa.relationship
                FROM resident_family_additions rfa
                JOIN residents r ON r.id = rfa.resident_id
                WHERE LOWER(TRIM(rfa.first_name)) = LOWER(TRIM(?)) 
                AND LOWER(TRIM(rfa.last_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(COALESCE(rfa.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND LOWER(TRIM(COALESCE(rfa.suffix, ""))) = LOWER(TRIM(COALESCE(?, "")))
                AND rfa.resident_id = ?
                AND rfa.status = "pending"
            ');
            $name_duplicate_check->execute([
                $first_name, $last_name, $middle_initial, $suffix, $resident_id,  // approved_same_name
                $first_name, $last_name, $middle_initial, $suffix, $resident_id   // pending_same_name
            ]);
            $name_duplicate = $name_duplicate_check->fetch();
            
            // Prioritize exact duplicate if found, otherwise use name duplicate
            $duplicate = $exact_duplicate ?: $name_duplicate;
            
            if ($duplicate) {
                $account_name = format_full_name($duplicate['first_name'], $duplicate['last_name'], $duplicate['middle_initial'], $duplicate['suffix'] ?? null);
                $full_name = format_full_name($first_name, $last_name, $middle_initial, $suffix ?: null);
                
                if ($duplicate['status'] === 'approved_same') {
                    $errors[] = "❌ <strong>{$full_name}</strong> is already approved in your account.";
                } elseif ($duplicate['status'] === 'pending_same') {
                    $errors[] = "⏳ <strong>{$full_name}</strong> is already pending approval in your account.";
                } elseif ($duplicate['status'] === 'approved_different') {
                    $errors[] = "❌ <strong>{$full_name}</strong> is already registered under <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'pending_different') {
                    $errors[] = "⏳ <strong>{$full_name}</strong> is currently pending approval under <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'approved_pending_different') {
                    $errors[] = "✅ <strong>{$full_name}</strong> was recently approved and added to <strong>{$account_name}</strong>'s account.";
                } elseif ($duplicate['status'] === 'approved_same_name') {
                    $existing_relationship = $duplicate['relationship'];
                    $errors[] = "❌ <strong>{$full_name}</strong> is already approved in your account as <strong>{$existing_relationship}</strong>.";
                } elseif ($duplicate['status'] === 'pending_same_name') {
                    $existing_relationship = $duplicate['relationship'];
                    $errors[] = "⏳ <strong>{$full_name}</strong> is already pending approval in your account as <strong>{$existing_relationship}</strong>.";
                }
                
                $errors[] = "<br><small class='text-gray-600'>💡 <strong>Tip:</strong> Each family member can only be registered once. Please verify the name and date of birth, or contact the BHW if you believe this is an error.</small>";
            }
        }
        
        // Handle profile image upload if provided
        $profile_image_path = null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $errors[] = 'Please upload a valid image file (JPEG, PNG, GIF, or WebP).';
            } elseif ($file['size'] > $max_size) {
                $errors[] = 'Image file is too large. Maximum size is 5MB.';
            } else {
                try {
                    // Create profiles directory if it doesn't exist
                    $upload_dir = __DIR__ . '/../../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'family_' . $resident_id . '_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        $profile_image_path = 'uploads/profiles/' . $filename;
                    } else {
                        $errors[] = 'Failed to upload image. Please try again.';
                    }
                } catch (Throwable $e) {
                    error_log('Family member profile image upload error: ' . $e->getMessage());
                    $errors[] = 'Failed to upload image. Please try again.';
                }
            }
        }
        
        if (empty($errors)) {
            try {
                // Insert as pending - try with profile_image first, fallback if column doesn't exist
                try {
                $stmt = db()->prepare('
                    INSERT INTO resident_family_additions 
                    (resident_id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth, profile_image, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")
                ');
                $stmt->execute([$resident_id, $first_name, $middle_initial, $last_name, $suffix ?: null, $relationship, $dob, $profile_image_path]);
                } catch (PDOException $e) {
                    // If profile_image column doesn't exist, insert without it
                    if (strpos($e->getMessage(), 'profile_image') !== false) {
                        $stmt = db()->prepare('
                            INSERT INTO resident_family_additions 
                            (resident_id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, "pending")
                ');
                        $stmt->execute([$resident_id, $first_name, $middle_initial, $last_name, $suffix ?: null, $relationship, $dob]);
                    } else {
                        throw $e;
                    }
                }
                
                $_SESSION['flash'] = 'Family member added! Awaiting BHW verification.';
                $_SESSION['flash_type'] = 'success';

                // Notify BHW via email
                try {
                    $bhwStmt = db()->prepare('SELECT email, first_name, last_name FROM users WHERE role = "bhw" AND purok_id = ? LIMIT 1');
                    $bhwStmt->execute([$purok_id]);
                    $bhw = $bhwStmt->fetch();

                    if ($bhw && !empty($bhw['email'])) {
                        $subject = 'New Family Member Request - ' . $full_name;
                        $residentName = $user['first_name'] . ' ' . $user['last_name'];
                        $html = email_template(
                            'New Family Member Request',
                            'A resident has requested to add a family member.',
                            "<p><strong>Resident:</strong> {$residentName}</p>
                             <p><strong>Family Member:</strong> {$full_name}</p>
                             <p><strong>Relationship:</strong> {$relationship}</p>
                             <p>Please log in to your dashboard to review this request.</p>",
                            'Review Request',
                            base_url('bhw/pending_family_additions.php')
                        );
                        send_email($bhw['email'], $bhw['first_name'] . ' ' . $bhw['last_name'], $subject, $html);
                    }
                } catch (Throwable $e) {
                    error_log('Failed to send BHW notification email: ' . $e->getMessage());
                }

                redirect_to('resident/family_members.php');
            } catch (Throwable $e) {
                $_SESSION['flash'] = 'Failed to add family member. Please try again.';
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            $_SESSION['flash'] = implode('<br>', $errors);
            $_SESSION['flash_type'] = 'error';
        }
    }
    
    if ($action === 'delete_pending') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // Get profile image path before deleting (if column exists)
            $member = null;
            try {
            $getImage = db()->prepare('SELECT profile_image FROM resident_family_additions WHERE id = ? AND resident_id = ?');
            $getImage->execute([$id, $resident_id]);
            $member = $getImage->fetch();
            } catch (PDOException $e) {
                // If profile_image column doesn't exist, just proceed without it
                if (strpos($e->getMessage(), 'profile_image') === false) {
                    throw $e;
                }
            }
            
            // Can only delete own pending members
            $stmt = db()->prepare('
                DELETE FROM resident_family_additions 
                WHERE id = ? AND resident_id = ? AND status = "pending"
            ');
            $stmt->execute([$id, $resident_id]);
            
            // Delete profile image if exists
            if ($member && !empty($member['profile_image'])) {
                $image_path = __DIR__ . '/../../uploads/profiles/' . basename($member['profile_image']);
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            $_SESSION['flash'] = 'Pending family member removed.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Failed to remove family member.';
            $_SESSION['flash_type'] = 'error';
        }
        redirect_to('resident/family_members.php');
    }
    
    if ($action === 'upload_family_profile') {
        $family_member_id = (int)($_POST['family_member_id'] ?? 0);
        
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $_SESSION['flash'] = 'Please upload a valid image file (JPEG, PNG, GIF, or WebP).';
                $_SESSION['flash_type'] = 'error';
            } elseif ($file['size'] > $max_size) {
                $_SESSION['flash'] = 'Image file is too large. Maximum size is 5MB.';
                $_SESSION['flash_type'] = 'error';
            } else {
                try {
                    // Verify family member belongs to this resident
                    $check = db()->prepare('SELECT id, profile_image FROM family_members WHERE id = ? AND resident_id = ?');
                    $check->execute([$family_member_id, $resident_id]);
                    $member = $check->fetch();
                    
                    if (!$member) {
                        $_SESSION['flash'] = 'Family member not found.';
                        $_SESSION['flash_type'] = 'error';
                    } else {
                        // Create profiles directory if it doesn't exist
                        $upload_dir = __DIR__ . '/../../uploads/profiles/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Generate unique filename
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'family_' . $resident_id . '_' . $family_member_id . '_' . time() . '.' . $extension;
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $filepath)) {
                            // Delete old profile image if exists
                            if ($member['profile_image']) {
                                $old_file = __DIR__ . '/../../uploads/profiles/' . basename($member['profile_image']);
                                if (file_exists($old_file)) {
                                    unlink($old_file);
                                }
                            }
                            
                            // Update database
                            $relative_path = 'uploads/profiles/' . $filename;
                            $stmt = db()->prepare('UPDATE family_members SET profile_image = ? WHERE id = ? AND resident_id = ?');
                            $stmt->execute([$relative_path, $family_member_id, $resident_id]);
                            
                            $_SESSION['flash'] = 'Profile image updated successfully!';
                            $_SESSION['flash_type'] = 'success';
                        } else {
                            $_SESSION['flash'] = 'Failed to upload image. Please try again.';
                            $_SESSION['flash_type'] = 'error';
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Family member profile image upload error: ' . $e->getMessage());
                    $_SESSION['flash'] = 'Failed to upload image. Please try again.';
                    $_SESSION['flash_type'] = 'error';
                }
            }
        } else {
            $_SESSION['flash'] = 'Please select a valid image file.';
            $_SESSION['flash_type'] = 'error';
        }
        
        redirect_to('resident/family_members.php');
    }
}

// Get approved family members
$approved_family = db()->prepare('
    SELECT id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth, profile_image, created_at
    FROM family_members 
    WHERE resident_id = ?
    ORDER BY last_name, first_name
');
$approved_family->execute([$resident_id]);
$approved_members = $approved_family->fetchAll();

// Get pending family additions (only truly pending ones, not approved or rejected)
// Check if profile_image column exists first
try {
$pending_family = db()->prepare('
    SELECT id, first_name, middle_initial, last_name, suffix, relationship, date_of_birth, profile_image, status, 
           rejection_reason, created_at, updated_at
    FROM resident_family_additions 
    WHERE resident_id = ? AND status = "pending"
    ORDER BY created_at DESC
');
$pending_family->execute([$resident_id]);
$pending_members = $pending_family->fetchAll();
} catch (PDOException $e) {
    // If profile_image column doesn't exist, select without it
    if (strpos($e->getMessage(), 'profile_image') !== false) {
        $pending_family = db()->prepare('
            SELECT id, first_name, middle_initial, last_name, relationship, date_of_birth, status, 
                   rejection_reason, created_at, updated_at
            FROM resident_family_additions 
            WHERE resident_id = ? AND status = "pending"
            ORDER BY created_at DESC
        ');
        $pending_family->execute([$resident_id]);
        $pending_members = $pending_family->fetchAll();
        // Add null profile_image to each member for consistency
        foreach ($pending_members as &$member) {
            $member['profile_image'] = null;
        }
        unset($member);
    } else {
        throw $e;
    }
}

function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Family Members · MediTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/resident-animations.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <style>
        /* CRITICAL: Override mobile menu CSS that's breaking sidebar */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 1000 !important;
            transform: none !important;
        }
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override mobile media queries */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                transform: none !important;
                width: 280px !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
    </style>
    <style>
        /* CRITICAL: Override design-system.css sidebar styles - MUST be after design-system.css */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 9999 !important;
            overflow-y: auto !important;
            transform: none !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1) !important;
            transition: none !important;
        }
        
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
            .main-content {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }
        }
        
        /* Remove hover effects */
        .sidebar-nav a:hover {
            background: transparent !important;
            color: inherit !important;
        }
        
        .sidebar-nav a {
            transition: none !important;
        }
        
        /* CRITICAL: Override mobile menu transforms */
        .sidebar.open {
            transform: none !important;
        }
        
        /* Ensure sidebar never transforms */
        .sidebar {
            transform: none !important;
        }
    </style>
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
<body class="bg-gradient-to-br from-gray-50 to-blue-50 resident-theme">
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
            <a href="<?php echo htmlspecialchars(base_url('resident/medicine_history.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Medicine History
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/announcements.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                Announcements
            </a>
            <a href="<?php echo htmlspecialchars(base_url('resident/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                Allocations
            </a>
            <a class="active" href="<?php echo htmlspecialchars(base_url('resident/family_members.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Family Members
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="flex items-center mb-3">
                <div class="flex-shrink-0">
                            <?php if (!empty($user_data['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars(upload_url($user_data['profile_image'])); ?>" 
                             alt="Profile" 
                             class="w-10 h-10 rounded-full object-cover border-2 border-green-500"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-green-500 hidden">
                                <?php 
                                $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'R';
                                $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'E';
                                echo strtoupper($firstInitial . $lastInitial); 
                                ?>
                            </div>
                            <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-semibold text-sm border-2 border-green-500">
                                    <?php 
                                    $firstInitial = !empty($user['first_name']) ? substr($user['first_name'], 0, 1) : 'R';
                                    $lastInitial = !empty($user['last_name']) ? substr($user['last_name'], 0, 1) : 'E';
                                    echo strtoupper($firstInitial . $lastInitial); 
                                    ?>
                                </div>
                            <?php endif; ?>
                                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">
                                    <?php echo htmlspecialchars(trim(($user['first_name'] ?? 'Resident') . ' ' . ($user['last_name'] ?? 'User'))); ?>
                    </p>
                    <p class="text-xs text-gray-600 truncate">
                                    <?php echo htmlspecialchars($user['email'] ?? 'resident@example.com'); ?>
                    </p>
                                        </div>
                                    </div>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                            </svg>
                Logout
                                </a>
                            </div>
    </aside>
                            
    <!-- Main Content -->
    <main class="main-content">
        <?php render_resident_header([
            'user_data' => $user_data,
            'is_senior' => $is_senior,
            'pending_requests' => $pending_requests,
            'recent_requests' => $recent_requests
        ]); ?>
        
        <!-- Page Title -->
        <div class="p-4 sm:p-6 lg:p-8">

        <div class="content-body">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">My Family Members</h1>
                <p class="text-gray-600 mt-1">Manage your family members for medicine requests</p>
            </div>
            <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                    class="btn btn-primary inline-flex items-center space-x-2 shadow-lg hover:shadow-xl transition-shadow duration-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                <span>Add Family Member</span>
            </button>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> animate-fade-in-up">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?php echo $_SESSION['flash']; unset($_SESSION['flash'], $_SESSION['flash_type']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Pending Family Members -->
        <?php if (!empty($pending_members)): ?>
        <div class="card animate-fade-in-up mb-6 border-l-4 border-l-yellow-500">
            <div class="card-header bg-gradient-to-r from-yellow-50 to-orange-50">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold flex items-center space-x-2 text-gray-800">
                    <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                            <span>Pending Verification</span>
                            <span class="bg-yellow-500 text-white text-xs font-bold px-2 py-1 rounded-full"><?php echo count($pending_members); ?></span>
                </h2>
                        <p class="text-sm text-gray-600 mt-1">These family members are awaiting approval from your assigned BHW</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>Name</th>
                                <th>Relationship</th>
                                <th>Age</th>
                                <th>Status</th>
                                <th>Submitted On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_members as $member): ?>
                            <tr>
                                <td>
                                    <div class="flex items-center">
                                        <?php if (!empty($member['profile_image'])): ?>
                                            <img src="<?php echo htmlspecialchars(upload_url($member['profile_image'])); ?>" 
                                                 alt="<?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'], $member['suffix'] ?? null)); ?>" 
                                                 class="w-10 h-10 rounded-full object-cover border-2 border-gray-200"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center text-white font-semibold text-sm border-2 border-gray-200" style="display:none;">
                                                <?php 
                                                $firstInitial = !empty($member['first_name']) ? substr($member['first_name'], 0, 1) : '';
                                                $lastInitial = !empty($member['last_name']) ? substr($member['last_name'], 0, 1) : '';
                                                echo strtoupper($firstInitial . $lastInitial); 
                                                ?>
                                            </div>
                                    <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center text-white font-semibold text-sm border-2 border-gray-200">
                                                <?php 
                                                $firstInitial = !empty($member['first_name']) ? substr($member['first_name'], 0, 1) : '';
                                                $lastInitial = !empty($member['last_name']) ? substr($member['last_name'], 0, 1) : '';
                                                echo strtoupper($firstInitial . $lastInitial); 
                                                ?>
                                            </div>
                                    <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'], $member['suffix'] ?? null)); ?></div>
                                </td>
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                        <?php echo htmlspecialchars($member['relationship']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-gray-700 font-medium"><?php echo calculateAge($member['date_of_birth']); ?> years</span>
                                </td>
                                <td>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 bg-yellow-500 rounded-full animate-pulse"></div>
                                        <span class="text-sm text-gray-600">Awaiting approval</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-gray-600 text-sm"><?php echo date('M d, Y', strtotime($member['created_at'])); ?></span>
                                </td>
                                <td>
                                    <form method="POST" class="inline" onsubmit="return confirmDeletePending(event, this);">
                                            <input type="hidden" name="action" value="delete_pending">
                                            <input type="hidden" name="id" value="<?php echo $member['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium transition-colors duration-150 flex items-center space-x-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                            <span>Remove</span>
                                        </button>
                                        </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Empty State for Pending -->
        <div class="card animate-fade-in-up mb-6 border-2 border-dashed border-gray-300">
            <div class="card-body text-center py-12">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">No Pending Verifications</h3>
                <p class="text-gray-500 text-sm">All your family member requests have been processed.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approved Family Members -->
        <div class="card animate-fade-in-up border-l-4 border-l-green-500">
            <div class="card-header bg-gradient-to-r from-green-50 to-emerald-50">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold flex items-center space-x-2 text-gray-800">
                    <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                            <span>Approved Family Members</span>
                            <span class="bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-full"><?php echo count($approved_members); ?></span>
                </h2>
                        <p class="text-sm text-gray-600 mt-1">These family members can be used when requesting medicines</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($approved_members)): ?>
                    <div class="text-center py-12">
                        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Family Members Yet</h3>
                        <p class="text-gray-600">Add family members to request medicine on their behalf</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Profile</th>
                                    <th>Name</th>
                                    <th>Relationship</th>
                                    <th>Age</th>
                                    <th>Added On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approved_members as $member): ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center">
                                            <?php if (!empty($member['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars(upload_url($member['profile_image'])); ?>" 
                                                     alt="<?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'], $member['suffix'] ?? null)); ?>" 
                                                     class="w-10 h-10 rounded-full object-cover border-2 border-gray-200"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center text-white font-semibold text-sm border-2 border-gray-200" style="display:none;">
                                                    <?php 
                                                    $firstInitial = !empty($member['first_name']) ? substr($member['first_name'], 0, 1) : '';
                                                    $lastInitial = !empty($member['last_name']) ? substr($member['last_name'], 0, 1) : '';
                                                    echo strtoupper($firstInitial . $lastInitial); 
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center text-white font-semibold text-sm border-2 border-gray-200">
                                                    <?php 
                                                    $firstInitial = !empty($member['first_name']) ? substr($member['first_name'], 0, 1) : '';
                                                    $lastInitial = !empty($member['last_name']) ? substr($member['last_name'], 0, 1) : '';
                                                    echo strtoupper($firstInitial . $lastInitial); 
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars(format_full_name($member['first_name'], $member['last_name'], $member['middle_initial'], $member['suffix'] ?? null)); ?></div>
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo htmlspecialchars($member['relationship']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-gray-700 font-medium"><?php echo calculateAge($member['date_of_birth']); ?> years</span>
                                    </td>
                                    <td>
                                        <span class="text-gray-600 text-sm"><?php echo date('M d, Y', strtotime($member['created_at'])); ?></span>
                                    </td>
                                    <td>
                                        <button onclick="openProfileUploadModal(<?php echo $member['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors duration-150 flex items-center space-x-1 hover:underline">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span><?php echo !empty($member['profile_image']) ? 'Change Photo' : 'Add Photo'; ?></span>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Family Member Modal -->
    <div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[99999] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-3xl w-full shadow-2xl transform transition-all relative z-[100000] border border-gray-200">
            <div class="p-6 border-b">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-semibold">Add Family Member</h3>
                    <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-gray-600 mt-1">BHW will verify before approval</p>
            </div>
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add_family_member">
                
                <!-- Profile Image Upload -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Profile Picture (Optional)</label>
                    <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0">
                            <img id="profile-preview" src="" alt="Preview" class="w-20 h-20 rounded-full object-cover border-2 border-gray-300 hidden">
                            <div id="profile-placeholder" class="w-20 h-20 rounded-full bg-gradient-to-br from-gray-300 to-gray-400 flex items-center justify-center text-white font-semibold text-sm border-2 border-gray-300">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex-1">
                            <input type="file" name="profile_image" id="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" 
                                   class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                   onchange="previewProfileImage(this)">
                            <p class="text-xs text-gray-500 mt-1">JPEG, PNG, GIF, or WebP (max 5MB)</p>
                        </div>
                    </div>
                </div>
                
                <!-- Row 1: First Name and Middle Initial -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                        <input type="text" name="first_name" required 
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Juan">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">M.I.</label>
                        <input type="text" name="middle_initial" maxlength="1"
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="D">
                    </div>
                </div>
                
                <!-- Row 2: Last Name and Suffix -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" required 
                               class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Dela Cruz">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Suffix</label>
                        <select name="suffix" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" style="padding-right: 3.5rem !important;">
                            <option value="">Suffix (optional)</option>
                            <option value="Jr.">Jr. (Junior)</option>
                            <option value="Sr.">Sr. (Senior)</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                            <option value="IV">IV</option>
                            <option value="V">V</option>
                        </select>
                        <div class="relative -mt-8 pointer-events-none flex items-center justify-end pr-3">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                    <select name="relationship" id="relationship_select" required 
                            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="handleRelationshipChangeResident(this)">
                        <?php echo get_relationship_options(null, true); ?>
                    </select>
                    <input type="text" 
                           name="relationship_other" 
                           id="relationship_other_resident"
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 mt-2 hidden" 
                           placeholder="Specify relationship (e.g., Stepfather, Godmother, etc.)"
                           maxlength="50" />
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth" required 
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary">Cancel</button>
                    <button type="submit" class="flex-1 btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* FORCE SIDEBAR TO STAY FIXED - OVERRIDE ALL OTHER STYLES */
        * {
            box-sizing: border-box !important;
        }
        
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden !important;
            height: 100% !important;
        }
        
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            z-index: 9999 !important;
            overflow-y: auto !important;
            transform: none !important;
            background: white !important;
            border-right: 1px solid #e5e7eb !important;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1) !important;
            transition: none !important;
        }
        
        /* Override all media queries */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar {
                position: fixed !important;
                width: 280px !important;
                transform: none !important;
            }
        }

        /* Ensure main content has proper margin and doesn't affect sidebar */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            position: relative !important;
            min-height: 100vh !important;
            background: #f9fafb !important;
        }

        /* Prevent any container from affecting sidebar position */
        .container, .wrapper, .page-wrapper {
            position: relative !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure sidebar brand and nav stay in place */
        .sidebar-brand {
            position: relative !important;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            color: white !important;
            padding: 1.5rem !important;
            border-bottom: 1px solid #e5e7eb !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
        }
        
        .sidebar-nav {
            position: relative !important;
            padding: 1rem !important;
        }
        
        .sidebar-nav a {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            padding: 0.75rem 1rem !important;
            margin-bottom: 0.25rem !important;
            border-radius: 0.5rem !important;
            color: #374151 !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            transition: none !important;
        }
        
        /* Removed hover effects for sidebar navigation */
        
        .sidebar-nav a.active {
            background: #dbeafe !important;
            color: #1d4ed8 !important;
            font-weight: 600 !important;
        }

        /* Force disabled button styling */
        button[type="submit"][disabled] {
            background-color: #ffffff !important;
            color: #6b7280 !important;
            border: 1px solid #d1d5db !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }
        
        button[type="submit"][disabled]:hover,
        button[type="submit"][disabled]:focus,
        button[type="submit"][disabled]:active {
            background-color: #ffffff !important;
            color: #6b7280 !important;
            border: 1px solid #d1d5db !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }
    </style>
    <script>
        // Modal click outside to close
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
            }
        });

        // Real-time duplicate validation
        let validationTimeout;
        let isDuplicateDetected = false;
        const firstNameInput = document.querySelector('input[name="first_name"]');
        const middleInitialInput = document.querySelector('input[name="middle_initial"]');
        const lastNameInput = document.querySelector('input[name="last_name"]');
        const dobInput = document.querySelector('input[name="date_of_birth"]');
        const validationDiv = document.createElement('div');
        validationDiv.id = 'validation-message';
        validationDiv.className = 'mt-2 text-sm';
        
        // Insert validation div after the date of birth input
        dobInput.parentNode.insertAdjacentElement('afterend', validationDiv);

        function checkDuplicate() {
            const firstName = firstNameInput.value.trim();
            const middleInitial = middleInitialInput.value.trim();
            const lastName = lastNameInput.value.trim();
            
            if (firstName.length >= 2 && lastName.length >= 2) {
                // Show loading state
                validationDiv.innerHTML = '<div class="flex items-center text-blue-600"><svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Checking for duplicates...</div>';
                
                fetch('<?php echo htmlspecialchars(base_url('resident/check_duplicate_family.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `first_name=${encodeURIComponent(firstName)}&middle_initial=${encodeURIComponent(middleInitial)}&last_name=${encodeURIComponent(lastName)}`
                })
                .then(response => response.json())
                .then(data => {
                    // Get the current submit button (could be the original or a recreated one)
                    let submitBtn = window.currentSubmitBtn || document.querySelector('button[type="submit"]');
                    
                    if (data.duplicate) {
                        isDuplicateDetected = true;
                        validationDiv.innerHTML = `<div class="text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">
                            <div class="flex items-start">
                                <svg class="w-5 h-5 text-red-500 mt-0.5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="font-medium">❌ Duplicate Found!</div>
                                    <div class="text-sm mt-1">${data.message}</div>
                                    <div class="text-xs mt-2 text-red-500 font-medium">⚠️ Cannot add duplicate family member</div>
                                </div>
                            </div>
                        </div>`;
                        
                        // Find the button container and remove ALL buttons first
                        const buttonContainer = document.querySelector('.flex.space-x-3.pt-4');
                        if (buttonContainer) {
                            // Remove ALL existing buttons and divs
                            const allButtons = buttonContainer.querySelectorAll('button, div[style*="Cannot Add"]');
                            allButtons.forEach(btn => btn.remove());
                            
                            // Keep only the Cancel button
                            const cancelBtn = buttonContainer.querySelector('button[type="button"]');
                            
                            // Clear the container and add back only Cancel button
                            buttonContainer.innerHTML = '';
                            if (cancelBtn) {
                                buttonContainer.appendChild(cancelBtn);
                            }
                            
                            // Create completely unclickable div
                            const disabledDiv = document.createElement('div');
                            disabledDiv.className = 'flex-1';
                            disabledDiv.style.backgroundColor = '#ffffff';
                            disabledDiv.style.color = '#6b7280';
                            disabledDiv.style.border = '1px solid #d1d5db';
                            disabledDiv.style.cursor = 'not-allowed';
                            disabledDiv.style.pointerEvents = 'none';
                            disabledDiv.style.textAlign = 'center';
                            disabledDiv.style.padding = '0.5rem 1rem';
                            disabledDiv.style.borderRadius = '0.5rem';
                            disabledDiv.style.fontSize = '0.875rem';
                            disabledDiv.style.fontWeight = '500';
                            disabledDiv.innerHTML = '❌ Cannot Add (Duplicate)';
                            
                            // Add it to the button container
                            buttonContainer.appendChild(disabledDiv);
                            window.currentSubmitBtn = disabledDiv;
                        }
                        
                        // Completely disable the form
                        const form = document.querySelector('form');
                        form.style.pointerEvents = 'none';
                        form.style.opacity = '0.7';
                        
                        // Add overlay to block all interactions
                        const overlay = document.createElement('div');
                        overlay.style.position = 'absolute';
                        overlay.style.top = '0';
                        overlay.style.left = '0';
                        overlay.style.right = '0';
                        overlay.style.bottom = '0';
                        overlay.style.backgroundColor = 'transparent';
                        overlay.style.zIndex = '9999';
                        overlay.style.pointerEvents = 'auto';
                        overlay.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            alert('Cannot add duplicate family member. Please check the validation message above.');
                            return false;
                        });
                        form.style.position = 'relative';
                        form.appendChild(overlay);
                        
                    } else {
                        isDuplicateDetected = false;
                        validationDiv.innerHTML = `<div class="text-green-600 bg-green-50 border border-green-200 rounded-lg p-3">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="font-medium">✅ Name available!</span>
                            </div>
                        </div>`;
                        
                        // Recreate the submit button
                        const buttonContainer = document.querySelector('.flex.space-x-3.pt-4');
                        if (buttonContainer) {
                            // Remove ALL existing buttons and divs
                            const allButtons = buttonContainer.querySelectorAll('button, div[style*="Cannot Add"]');
                            allButtons.forEach(btn => btn.remove());
                            
                            // Keep only the Cancel button
                            const cancelBtn = buttonContainer.querySelector('button[type="button"]');
                            
                            // Clear the container and add back only Cancel button
                            buttonContainer.innerHTML = '';
                            if (cancelBtn) {
                                buttonContainer.appendChild(cancelBtn);
                            }
                            
                            // Create new submit button
                            const newSubmitBtn = document.createElement('button');
                            newSubmitBtn.type = 'submit';
                            newSubmitBtn.className = 'flex-1 btn btn-primary';
                            newSubmitBtn.innerHTML = 'Add Member';
                            
                            // Add it to the button container
                            buttonContainer.appendChild(newSubmitBtn);
                            window.currentSubmitBtn = newSubmitBtn;
                        }
                        
                        // Re-enable the form
                        const form = document.querySelector('form');
                        form.style.pointerEvents = 'auto';
                        form.style.opacity = '1';
                        
                        // Remove any overlays
                        const overlays = form.querySelectorAll('div[style*="z-index: 9999"]');
                        overlays.forEach(overlay => overlay.remove());
                    }
                })
                .catch(error => {
                    validationDiv.innerHTML = '<div class="text-yellow-600">Unable to check for duplicates. Please try again.</div>';
                });
            } else {
                validationDiv.innerHTML = '';
            }
        }

        // Real-time input filtering to prevent invalid characters
        function filterInput(input, pattern, maxLength = null) {
            const originalValue = input.value;
            // Remove invalid characters based on pattern
            let filtered = originalValue.replace(new RegExp('[^' + pattern + ']', 'g'), '');
            
            // Apply max length if specified
            if (maxLength && filtered.length > maxLength) {
                filtered = filtered.substring(0, maxLength);
            }
            
            // Update value if it changed
            if (filtered !== originalValue) {
                const cursorPos = input.selectionStart;
                input.value = filtered;
                // Restore cursor position (adjust for removed characters)
                const newPos = Math.min(cursorPos - (originalValue.length - filtered.length), filtered.length);
                input.setSelectionRange(newPos, newPos);
            }
        }
        
        // First Name: Only letters, spaces, hyphens, apostrophes
        firstNameInput.addEventListener('input', function(e) {
            filterInput(this, 'A-Za-zÀ-ÿ\\s\\-\'');
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });
        
        firstNameInput.addEventListener('keypress', function(e) {
            // Allow: letters, space, hyphen, apostrophe, backspace, delete, tab, arrow keys
            const char = String.fromCharCode(e.which || e.keyCode);
            if (!/[A-Za-zÀ-ÿ\s\-\']/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                e.preventDefault();
            }
        });
        
        // Last Name: Only letters, spaces, hyphens, apostrophes
        lastNameInput.addEventListener('input', function(e) {
            filterInput(this, 'A-Za-zÀ-ÿ\\s\\-\'');
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });
        
        lastNameInput.addEventListener('keypress', function(e) {
            // Allow: letters, space, hyphen, apostrophe, backspace, delete, tab, arrow keys
            const char = String.fromCharCode(e.which || e.keyCode);
            if (!/[A-Za-zÀ-ÿ\s\-\']/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                e.preventDefault();
            }
        });
        
        // Middle Initial: Only letters, max 1 character
        middleInitialInput.addEventListener('input', function(e) {
            filterInput(this, 'A-Za-zÀ-ÿ', 1);
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(checkDuplicate, 300);
        });
        
        middleInitialInput.addEventListener('keypress', function(e) {
            // Allow: letters only, backspace, delete, tab, arrow keys
            const char = String.fromCharCode(e.which || e.keyCode);
            if (!/[A-Za-zÀ-ÿ]/.test(char) && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                e.preventDefault();
            }
            // Prevent typing if already 1 character
            if (this.value.length >= 1 && !/[8|46|9|27|13|37|38|39|40]/.test(e.keyCode)) {
                e.preventDefault();
            }
        });
        
        // Date of Birth: Prevent future dates
        dobInput.addEventListener('change', function(e) {
            const selectedDate = new Date(this.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate > today) {
                alert('Date of birth cannot be in the future.');
                this.value = '';
                this.focus();
            }
        });
        
        // Handle paste events to filter invalid characters
        firstNameInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const filtered = pastedText.replace(/[^A-Za-zÀ-ÿ\s\-\']/g, '');
            const cursorPos = this.selectionStart;
            const textBefore = this.value.substring(0, cursorPos);
            const textAfter = this.value.substring(this.selectionEnd);
            this.value = textBefore + filtered + textAfter;
            const newPos = cursorPos + filtered.length;
            this.setSelectionRange(newPos, newPos);
            // Trigger input event for duplicate check
            this.dispatchEvent(new Event('input'));
        });
        
        lastNameInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const filtered = pastedText.replace(/[^A-Za-zÀ-ÿ\s\-\']/g, '');
            const cursorPos = this.selectionStart;
            const textBefore = this.value.substring(0, cursorPos);
            const textAfter = this.value.substring(this.selectionEnd);
            this.value = textBefore + filtered + textAfter;
            const newPos = cursorPos + filtered.length;
            this.setSelectionRange(newPos, newPos);
            // Trigger input event for duplicate check
            this.dispatchEvent(new Event('input'));
        });
        
        middleInitialInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const filtered = pastedText.replace(/[^A-Za-zÀ-ÿ]/g, '').substring(0, 1);
            const cursorPos = this.selectionStart;
            const textBefore = this.value.substring(0, cursorPos);
            const textAfter = this.value.substring(this.selectionEnd);
            this.value = textBefore + filtered + textAfter;
            // Limit to 1 character total
            if (this.value.length > 1) {
                this.value = this.value.substring(0, 1);
            }
            this.setSelectionRange(1, 1);
            // Trigger input event for duplicate check
            this.dispatchEvent(new Event('input'));
        });

        // Clear validation on modal close
        document.getElementById('addModal').addEventListener('hidden.bs.modal', function() {
            validationDiv.innerHTML = '';
            firstNameInput.value = '';
            middleInitialInput.value = '';
            lastNameInput.value = '';
            dobInput.value = '';
            isDuplicateDetected = false;
        });

        // Prevent form submission if duplicate is detected
        document.querySelector('form').addEventListener('submit', function(e) {
            if (isDuplicateDetected) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
        });
        
        // Prevent form submission when duplicate is detected
        document.querySelector('form').addEventListener('submit', function(e) {
            if (isDuplicateDetected) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
            
            // Also check if the current element is a div (disabled state)
            const currentElement = window.currentSubmitBtn;
            if (currentElement && currentElement.tagName === 'DIV') {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
            
            // Check if form is disabled
            const form = document.querySelector('form');
            if (form.style.pointerEvents === 'none') {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                alert('Form is disabled due to duplicate family member. Please check the validation message.');
                return false;
            }
        }, true);
        
        // Additional prevention - block all form events
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            // Only allow submission if no duplicate and form is enabled
            if (!isDuplicateDetected && document.querySelector('form').style.pointerEvents !== 'none') {
                // Re-enable the form temporarily for submission
                const form = document.querySelector('form');
                const originalPointerEvents = form.style.pointerEvents;
                const originalOpacity = form.style.opacity;
                
                form.style.pointerEvents = 'auto';
                form.style.opacity = '1';
                
                // Remove overlays temporarily
                const overlays = form.querySelectorAll('div[style*="z-index: 9999"]');
                overlays.forEach(overlay => overlay.style.display = 'none');
                
                // Submit the form
                setTimeout(() => {
                    form.submit();
                }, 10);
                
                return true;
            } else {
                alert('Cannot submit form with duplicate family member. Please check the validation message.');
                return false;
            }
        }, false);
        
        // Handle relationship change to show/hide custom relationship input
        function handleRelationshipChangeResident(selectElement) {
            const otherInput = document.getElementById('relationship_other_resident');
            if (otherInput) {
                if (selectElement.value === 'Other') {
                    otherInput.classList.remove('hidden');
                    otherInput.required = true;
                } else {
                    otherInput.classList.add('hidden');
                    otherInput.value = '';
                    otherInput.required = false;
                }
            }
        }
        
        // Preview profile image before upload
        function previewProfileImage(input) {
            const preview = document.getElementById('profile-preview');
            const placeholder = document.getElementById('profile-placeholder');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.add('hidden');
                placeholder.classList.remove('hidden');
            }
        }
        
        // Open profile upload modal for existing family member
        function openProfileUploadModal(familyMemberId) {
            document.getElementById('uploadFamilyMemberId').value = familyMemberId;
            document.getElementById('uploadProfileModal').classList.remove('hidden');
        }
        
        // Preview image in upload modal
        function previewUploadImage(input) {
            const preview = document.getElementById('upload-preview');
            const placeholder = document.getElementById('upload-placeholder');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.add('hidden');
                placeholder.classList.remove('hidden');
            }
        }
    </script>
    
    <!-- Upload Profile Picture Modal -->
    <div id="uploadProfileModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[99999] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl transform transition-all relative z-[100000] border border-gray-200">
            <div class="p-6 border-b">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-semibold">Upload Profile Picture</h3>
                    <button onclick="document.getElementById('uploadProfileModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" name="action" value="upload_family_profile">
                <input type="hidden" name="family_member_id" id="uploadFamilyMemberId" value="">
                
                <div class="flex items-center justify-center">
                    <div class="relative">
                        <img id="upload-preview" src="" alt="Preview" class="w-32 h-32 rounded-full object-cover border-4 border-gray-300 hidden">
                        <div id="upload-placeholder" class="w-32 h-32 rounded-full bg-gradient-to-br from-gray-300 to-gray-400 flex items-center justify-center text-white font-semibold text-sm border-4 border-gray-300">
                            <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div>
                    <input type="file" name="profile_image" id="upload_profile_image" accept="image/jpeg,image/png,image/gif,image/webp" 
                           class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                           onchange="previewUploadImage(this)" required>
                    <p class="text-xs text-gray-500 mt-1">JPEG, PNG, GIF, or WebP (max 5MB)</p>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="document.getElementById('uploadProfileModal').classList.add('hidden')" 
                            class="flex-1 btn btn-secondary">Cancel</button>
                    <button type="submit" class="flex-1 btn btn-primary">Upload Photo</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            if (!window.residentProfileDropdownClickHandler) {
                window.residentProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.residentProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const menu = document.getElementById('profile-menu');
                    const arrow = document.getElementById('profile-arrow');
                    if (menu) menu.classList.add('hidden');
                    if (arrow) arrow.classList.remove('rotate-180');
                }
            });
        }

        // Initialize night mode and profile dropdown
        initNightMode();
        initProfileDropdown();

        function confirmDeletePending(event, form) {
            event.preventDefault();
            
            Swal.fire({
                title: 'Remove Pending Member?',
                text: "Are you sure you want to remove this pending family member? This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, remove it',
                cancelButtonText: 'Cancel',
                customClass: {
                    popup: 'rounded-2xl',
                    confirmButton: 'px-4 py-2 rounded-lg font-medium',
                    cancelButton: 'px-4 py-2 rounded-lg font-medium'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            
            return false;
        }
        
        // Initialize profile dropdown when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initProfileDropdown();
        });
    </script>
</body>
</html>
