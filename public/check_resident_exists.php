<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$middle_initial = trim($_POST['middle_initial'] ?? '');
$date_of_birth = $_POST['date_of_birth'] ?? '';

// Normalize names for better matching
function normalizeName($name) {
    // Remove extra spaces, convert to lowercase, remove special characters
    $normalized = strtolower(trim(preg_replace('/[^a-zA-Z\s]/', '', $name)));
    // Remove multiple spaces
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return $normalized;
}

$first_name_normalized = normalizeName($first_name);
$last_name_normalized = normalizeName($last_name);
$middle_initial_normalized = normalizeName($middle_initial);

// Validate required fields
if (empty($first_name) || empty($last_name) || empty($date_of_birth)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    // Check if person exists as a resident (with flexible name matching)
    $resident_check = db()->prepare('
        SELECT 
            r.id,
            r.first_name,
            r.last_name,
            r.middle_initial,
            r.date_of_birth,
            r.email,
            u.email as user_email,
            "resident" as type,
            b.name as barangay_name,
            p.name as purok_name
        FROM residents r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN barangays b ON b.id = r.barangay_id
        LEFT JOIN puroks p ON p.id = r.purok_id
        WHERE (
            LOWER(TRIM(r.first_name)) = LOWER(TRIM(?))
            OR LOWER(TRIM(r.first_name)) LIKE LOWER(TRIM(?))
            OR LOWER(TRIM(?)) LIKE LOWER(TRIM(r.first_name))
        )
        AND (
            LOWER(TRIM(r.last_name)) = LOWER(TRIM(?))
            OR LOWER(TRIM(r.last_name)) LIKE LOWER(TRIM(?))
            OR LOWER(TRIM(?)) LIKE LOWER(TRIM(r.last_name))
        )
        AND (
            LOWER(TRIM(COALESCE(r.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
            OR LOWER(TRIM(COALESCE(r.middle_initial, ""))) LIKE LOWER(TRIM(COALESCE(?, "")))
            OR LOWER(TRIM(COALESCE(?, ""))) LIKE LOWER(TRIM(COALESCE(r.middle_initial, "")))
        )
        AND r.date_of_birth = ?
    ');
    
    $resident_check->execute([
        $first_name_normalized, $first_name_normalized, $first_name_normalized,
        $last_name_normalized, $last_name_normalized, $last_name_normalized,
        $middle_initial_normalized, $middle_initial_normalized, $middle_initial_normalized,
        $date_of_birth
    ]);
    $resident_result = $resident_check->fetch();
    
    if ($resident_result) {
        $full_name = format_full_name($resident_result['first_name'], $resident_result['last_name'], $resident_result['middle_initial']);
        echo json_encode([
            'exists' => true,
            'status' => 'active_resident',
            'message' => "This person is already registered as a resident.",
            'details' => [
                'name' => $full_name,
                'email' => $resident_result['user_email'] ?: $resident_result['email'],
                'location' => $resident_result['purok_name'] . ', ' . $resident_result['barangay_name'],
                'date_of_birth' => $resident_result['date_of_birth'],
                'type' => 'resident'
            ]
        ]);
        exit;
    }
    
    // Check if person exists as a family member (with flexible name matching)
    $family_check = db()->prepare('
        SELECT 
            fm.id,
            fm.first_name,
            fm.middle_initial,
            fm.last_name,
            fm.date_of_birth,
            fm.relationship,
            r.first_name as resident_first_name,
            r.last_name as resident_last_name,
            r.middle_initial as resident_middle_initial,
            "family_member" as type,
            b.name as barangay_name,
            p.name as purok_name
        FROM family_members fm
        JOIN residents r ON r.id = fm.resident_id
        LEFT JOIN barangays b ON b.id = r.barangay_id
        LEFT JOIN puroks p ON p.id = r.purok_id
        WHERE (
            LOWER(TRIM(fm.first_name)) = LOWER(TRIM(?))
            OR LOWER(TRIM(fm.first_name)) LIKE LOWER(TRIM(?))
            OR LOWER(TRIM(?)) LIKE LOWER(TRIM(fm.first_name))
        )
        AND (
            LOWER(TRIM(fm.last_name)) = LOWER(TRIM(?))
            OR LOWER(TRIM(fm.last_name)) LIKE LOWER(TRIM(?))
            OR LOWER(TRIM(?)) LIKE LOWER(TRIM(fm.last_name))
        )
        AND (
            LOWER(TRIM(COALESCE(fm.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
            OR LOWER(TRIM(COALESCE(fm.middle_initial, ""))) LIKE LOWER(TRIM(COALESCE(?, "")))
            OR LOWER(TRIM(COALESCE(?, ""))) LIKE LOWER(TRIM(COALESCE(fm.middle_initial, "")))
        )
        AND fm.date_of_birth = ?
    ');
    
    $family_check->execute([
        $first_name_normalized, $first_name_normalized, $first_name_normalized,
        $last_name_normalized, $last_name_normalized, $last_name_normalized,
        $middle_initial_normalized, $middle_initial_normalized, $middle_initial_normalized,
        $date_of_birth
    ]);
    $family_result = $family_check->fetch();
    
    if ($family_result) {
        $full_name = format_full_name($family_result['first_name'], $family_result['last_name'], $family_result['middle_initial']);
        $resident_name = format_full_name($family_result['resident_first_name'], $family_result['resident_last_name'], $family_result['resident_middle_initial']);
        echo json_encode([
            'exists' => true,
            'status' => 'active_family_member',
            'message' => "This person is already registered as a family member of {$resident_name}.",
            'details' => [
                'name' => $full_name,
                'relationship' => $family_result['relationship'],
                'resident_name' => $resident_name,
                'location' => $family_result['purok_name'] . ', ' . $family_result['barangay_name'],
                'date_of_birth' => $family_result['date_of_birth'],
                'type' => 'family_member'
            ]
        ]);
        exit;
    }
    
    // Check if resident already exists in pending residents table
    $pending_check = db()->prepare('
        SELECT 
            pr.id,
            pr.first_name,
            pr.last_name,
            pr.middle_initial,
            pr.date_of_birth,
            pr.email,
            pr.status,
            pr.created_at,
            b.name as barangay_name,
            p.name as purok_name
        FROM pending_residents pr
        LEFT JOIN barangays b ON b.id = pr.barangay_id
        LEFT JOIN puroks p ON p.id = pr.purok_id
        WHERE LOWER(TRIM(pr.first_name)) = LOWER(TRIM(?))
        AND LOWER(TRIM(pr.last_name)) = LOWER(TRIM(?))
        AND LOWER(TRIM(COALESCE(pr.middle_initial, ""))) = LOWER(TRIM(COALESCE(?, "")))
        AND pr.date_of_birth = ?
    ');
    
    $pending_check->execute([$first_name, $last_name, $middle_initial, $date_of_birth]);
    $pending_resident = $pending_check->fetch();
    
    if ($pending_resident) {
        $full_name = format_full_name($pending_resident['first_name'], $pending_resident['last_name'], $pending_resident['middle_initial']);
        $status_message = '';
        
        switch ($pending_resident['status']) {
            case 'pending':
                $status_message = "This resident already has a registration pending approval.";
                break;
            case 'approved':
                $status_message = "This resident's registration has already been approved.";
                break;
            case 'rejected':
                $status_message = "This resident's previous registration was rejected.";
                break;
        }
        
        echo json_encode([
            'exists' => true,
            'status' => $pending_resident['status'],
            'message' => $status_message,
            'details' => [
                'name' => $full_name,
                'email' => $pending_resident['email'],
                'location' => $pending_resident['purok_name'] . ', ' . $pending_resident['barangay_name'],
                'date_of_birth' => $pending_resident['date_of_birth'],
                'registration_date' => $pending_resident['created_at']
            ]
        ]);
        exit;
    }
    
    // No existing resident found
    echo json_encode(['exists' => false]);
    
} catch (Throwable $e) {
    error_log('Error checking resident existence: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while checking resident status']);
}
?>
