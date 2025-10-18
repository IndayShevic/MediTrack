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

$email = trim($_POST['email'] ?? '');

// Validate email format
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    // Check if email already exists in users table
    $userCheck = db()->prepare('SELECT id, email, role FROM users WHERE email = ? LIMIT 1');
    $userCheck->execute([$email]);
    $user = $userCheck->fetch();
    
    if ($user) {
        echo json_encode([
            'exists' => true,
            'message' => 'This email address is already registered as a ' . $user['role'] . '.',
            'details' => [
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    }
    
    // Check if email already exists in pending_residents table
    $pendingCheck = db()->prepare('SELECT id, email, status FROM pending_residents WHERE email = ? LIMIT 1');
    $pendingCheck->execute([$email]);
    $pending = $pendingCheck->fetch();
    
    if ($pending) {
        $statusMessage = '';
        switch ($pending['status']) {
            case 'pending':
                $statusMessage = 'This email address already has a registration pending approval.';
                break;
            case 'approved':
                $statusMessage = 'This email address has already been approved for registration.';
                break;
            case 'rejected':
                $statusMessage = 'This email address was previously rejected.';
                break;
        }
        
        echo json_encode([
            'exists' => true,
            'message' => $statusMessage,
            'details' => [
                'email' => $pending['email'],
                'status' => $pending['status']
            ]
        ]);
        exit;
    }
    
    // Email is available
    echo json_encode(['exists' => false]);
    
} catch (Throwable $e) {
    error_log('Error checking email existence: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while checking email availability']);
}
?>
