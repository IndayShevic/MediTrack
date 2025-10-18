<?php
/**
 * Run the announcements table migration
 * Execute this script to create the announcements table and insert sample data
 */

declare(strict_types=1);

// Database connection
$host = '127.0.0.1';
$dbname = 'meditrack';
$username = 'shev';
$password = 'shev';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Connected to database successfully.\n";
    
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/create_announcements_table.sql');
    
    if ($sql === false) {
        throw new Exception('Could not read SQL file');
    }
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 50) . "...\n";
        }
    }
    
    echo "\nAnnouncements table migration completed successfully!\n";
    echo "You can now access the announcements feature in your MediTrack system.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
