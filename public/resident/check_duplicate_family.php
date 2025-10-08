<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['resident']);

header('Content-Type: application/json');

$user = current_user();

// Get resident ID
$stmt = db()->prepare('SELECT id FROM residents WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$resident = $stmt->fetch();

if (!$resident) {
    echo json_encode(['error' => 'Resident not found']);
    exit;
}

$resident_id = (int)$resident['id'];
$first_name = trim($_POST['first_name'] ?? '');
$middle_initial = trim($_POST['middle_initial'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');

if (empty($first_name) || empty($last_name)) {
    echo json_encode(['duplicate' => false]);
    exit;
}

// Check for name-only duplicates in same account
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
        fm.date_of_birth,
        fm.relationship
    FROM family_members fm
    JOIN residents r ON r.id = fm.resident_id
    WHERE LOWER(TRIM(fm.first_name)) = LOWER(TRIM(?)) 
    AND LOWER(TRIM(fm.last_name)) = LOWER(TRIM(?))
    AND LOWER(TRIM(COALESCE(fm.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
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
        rfa.date_of_birth,
        rfa.relationship
    FROM resident_family_additions rfa
    JOIN residents r ON r.id = rfa.resident_id
    WHERE LOWER(TRIM(rfa.first_name)) = LOWER(TRIM(?)) 
    AND LOWER(TRIM(rfa.last_name)) = LOWER(TRIM(?))
    AND LOWER(TRIM(COALESCE(rfa.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
    AND rfa.resident_id = ?
    AND rfa.status = "pending"
');

$name_duplicate_check->execute([
    $first_name, $last_name, $middle_initial, $resident_id,  // approved_same_name
    $first_name, $last_name, $middle_initial, $resident_id   // pending_same_name
]);
$duplicate = $name_duplicate_check->fetch();

if ($duplicate) {
    $account_name = format_full_name($duplicate['first_name'], $duplicate['last_name'], $duplicate['middle_initial']);
    
    if ($duplicate['status'] === 'approved_same_name') {
        $existing_relationship = $duplicate['relationship'];
        $message = "This family member is already approved in your account as {$existing_relationship}.";
    } elseif ($duplicate['status'] === 'pending_same_name') {
        $existing_relationship = $duplicate['relationship'];
        $message = "This family member is already pending approval in your account as {$existing_relationship}.";
    }
    
    echo json_encode([
        'duplicate' => true,
        'message' => $message,
        'account_name' => $account_name,
        'status' => $duplicate['status']
    ]);
} else {
    echo json_encode(['duplicate' => false]);
}
?>
