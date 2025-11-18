<?php
/**
 * Debug Helper for Authentication System
 * This file helps diagnose issues with login, forgot password, and reset password flows
 * 
 * Usage: Access via browser at public/debug_auth.php
 * Remove this file in production!
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$checks = [
    'database_connection' => false,
    'users_table_exists' => false,
    'password_reset_tokens_table_exists' => false,
    'can_create_password_reset_table' => false,
    'sample_user_exists' => false,
];

$messages = [];
$errors = [];

try {
    // Check 1: Database connection
    $pdo = db();
    $checks['database_connection'] = true;
    $messages[] = '✓ Database connection successful';
    
    // Check 2: Users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        $checks['users_table_exists'] = true;
        $messages[] = '✓ Users table exists';
        
        // Check if there are any users
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $count = $stmt->fetch()['count'];
        if ($count > 0) {
            $checks['sample_user_exists'] = true;
            $messages[] = "✓ Found {$count} user(s) in database";
        } else {
            $messages[] = '⚠ No users found in database';
        }
    } else {
        $errors[] = '✗ Users table does not exist';
    }
    
    // Check 3: Password reset tokens table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
    if ($stmt->rowCount() > 0) {
        $checks['password_reset_tokens_table_exists'] = true;
        $messages[] = '✓ Password reset tokens table exists';
    } else {
        $messages[] = '⚠ Password reset tokens table does not exist';
        
        // Check 4: Can create password reset table
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(191) NOT NULL,
                    token VARCHAR(255) NOT NULL UNIQUE,
                    expires_at TIMESTAMP NOT NULL,
                    used_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email (email),
                    INDEX idx_token (token),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB
            ");
            $checks['can_create_password_reset_table'] = true;
            $checks['password_reset_tokens_table_exists'] = true;
            $messages[] = '✓ Successfully created password_reset_tokens table';
        } catch (Throwable $e) {
            $errors[] = '✗ Cannot create password_reset_tokens table: ' . $e->getMessage();
        }
    }
    
    // Check email configuration
    try {
        require_once __DIR__ . '/../config/mail.php';
        $messages[] = '✓ Email configuration loaded';
    } catch (Throwable $e) {
        $errors[] = '✗ Email configuration error: ' . $e->getMessage();
    }
    
} catch (Throwable $e) {
    $errors[] = '✗ Fatal error: ' . $e->getMessage();
    $errors[] = 'File: ' . $e->getFile() . ' | Line: ' . $e->getLine();
}

$allPassed = !in_array(false, $checks) && empty($errors);

echo json_encode([
    'success' => $allPassed,
    'checks' => $checks,
    'messages' => $messages,
    'errors' => $errors,
    'recommendations' => $allPassed ? [] : [
        'If password_reset_tokens table is missing, run: database/add_password_reset_table.sql',
        'Check PHP error logs for detailed error messages',
        'Verify database credentials in config/db.php',
        'Verify email configuration in config/mail.php'
    ]
], JSON_PRETTY_PRINT);

