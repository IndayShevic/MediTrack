<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../index.php');
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    redirect_to('../index.php');
}

$stmt = db()->prepare('SELECT id, email, password_hash, role, first_name, last_name, middle_initial FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['flash'] = 'Invalid credentials';
    redirect_to('../index.php');
}

$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'email' => $user['email'],
    'role' => $user['role'],
    'name' => format_full_name($user['first_name'] ?? '', $user['last_name'] ?? '', $user['middle_initial'] ?? null),
];

if ($user['role'] === 'super_admin') {
    redirect_to('super_admin/dashboard.php');
}
if ($user['role'] === 'bhw') {
    redirect_to('bhw/dashboard.php');
}
redirect_to('resident/dashboard.php');


