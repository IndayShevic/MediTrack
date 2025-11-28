<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Step 1: Validate fields are not empty
if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required']);
    exit;
}

// Step 2: Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

try {
    $pdo = db();
    
    // Step 3: Check if email exists in users table
    $stmt = $pdo->prepare('SELECT id, email, password_hash, role, first_name, last_name, middle_initial, purok_id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Email not registered']);
        exit;
    }
    
    // Step 4: Check if password matches
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Wrong credentials']);
        exit;
    }
    
    // Step 5: Login successful - create session
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'middle_initial' => $user['middle_initial'] ?? null,
        'name' => format_full_name($user['first_name'] ?? '', $user['last_name'] ?? '', $user['middle_initial'] ?? null),
        'purok_id' => $user['purok_id'] ? (int)$user['purok_id'] : null,
    ];
    
    // Determine redirect URL based on role
    $redirectUrl = 'resident/dashboard.php';
    if ($user['role'] === 'super_admin') {
        $redirectUrl = 'super_admin/dashboardnew.php';
    } elseif ($user['role'] === 'bhw') {
        $redirectUrl = 'bhw/dashboard.php';
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => $redirectUrl
    ]);
    
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
    error_log('Login database error: ' . $errorMsg . ' | Code: ' . $e->getCode() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    error_log('Login error: ' . $errorMsg . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: ' . $errorTrace);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
}


