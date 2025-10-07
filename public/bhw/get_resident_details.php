<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);

header('Content-Type: application/json');

$resident_id = (int)($_GET['id'] ?? 0);
$user = current_user();

if ($resident_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid resident ID']);
    exit;
}

try {
    // Get resident details
    $stmt = db()->prepare('
        SELECT r.*, p.name as purok_name, b.name as barangay_name
        FROM residents r
        JOIN puroks p ON p.id = r.purok_id
        JOIN barangays b ON b.id = r.barangay_id
        WHERE r.id = ? AND r.purok_id = (SELECT purok_id FROM users WHERE id = ?)
    ');
    $stmt->execute([$resident_id, $user['id']]);
    $resident = $stmt->fetch();
    
    if (!$resident) {
        echo json_encode(['success' => false, 'error' => 'Resident not found or not in your assigned area']);
        exit;
    }
    
    // Get family members
    $stmt = db()->prepare('
        SELECT full_name, relationship, date_of_birth
        FROM family_members
        WHERE resident_id = ?
        ORDER BY relationship, full_name
    ');
    $stmt->execute([$resident_id]);
    $family_members = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'resident' => $resident,
        'family_members' => $family_members
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
