<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../index.php?modal=login');
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    redirect_to('../index.php?modal=login');
}

$stmt = db()->prepare('SELECT id, email, password_hash, role, first_name, last_name, middle_initial, purok_id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['flash'] = 'Invalid credentials';
    redirect_to('../index.php?modal=login');
}

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

if ($user['role'] === 'super_admin') {
    redirect_to('super_admin/dashboard.php');
}
if ($user['role'] === 'bhw') {
    redirect_to('bhw/dashboard.php');
}
redirect_to('resident/dashboard.php');


