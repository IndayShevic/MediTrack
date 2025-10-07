<?php
require_once '../config/db.php';

echo "<h2>Creating Missing Pending Tables</h2>";

try {
    $pdo = db();
    
    // Create pending_residents table
    echo "<h3>Creating pending_residents table...</h3>";
    $sql1 = "
    CREATE TABLE IF NOT EXISTS pending_residents (
      id INT AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(191) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      first_name VARCHAR(100) NOT NULL,
      last_name VARCHAR(100) NOT NULL,
      date_of_birth DATE NOT NULL,
      phone VARCHAR(50) NULL,
      address VARCHAR(255) NULL,
      barangay_id INT NOT NULL,
      purok_id INT NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      bhw_id INT NULL,
      rejection_reason TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_pending_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
      CONSTRAINT fk_pending_purok FOREIGN KEY (purok_id) REFERENCES puroks(id) ON DELETE CASCADE,
      CONSTRAINT fk_pending_bhw FOREIGN KEY (bhw_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB
    ";
    
    $pdo->exec($sql1);
    echo "✅ pending_residents table created successfully!<br>";
    
    // Create pending_family_members table
    echo "<h3>Creating pending_family_members table...</h3>";
    $sql2 = "
    CREATE TABLE IF NOT EXISTS pending_family_members (
      id INT AUTO_INCREMENT PRIMARY KEY,
      pending_resident_id INT NOT NULL,
      full_name VARCHAR(150) NOT NULL,
      relationship VARCHAR(100) NOT NULL,
      age INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_pending_family_resident FOREIGN KEY (pending_resident_id) REFERENCES pending_residents(id) ON DELETE CASCADE
    ) ENGINE=InnoDB
    ";
    
    $pdo->exec($sql2);
    echo "✅ pending_family_members table created successfully!<br>";
    
    echo "<h3>Tables Status:</h3>";
    
    // Check if tables exist
    $checkTables = $pdo->query("SHOW TABLES LIKE 'pending_%'")->fetchAll();
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Table Name</th><th>Status</th></tr>";
    
    foreach ($checkTables as $table) {
        $tableName = array_values($table)[0];
        echo "<tr><td>" . $tableName . "</td><td>✅ Created</td></tr>";
    }
    echo "</table>";
    
    echo "<br><strong>✅ All missing tables have been created successfully!</strong><br>";
    echo "<p>Now you can:</p>";
    echo "<ul>";
    echo "<li>Create new BHW accounts in the super admin panel</li>";
    echo "<li>Assign BHWs to specific puroks</li>";
    echo "<li>Register new residents and they will appear in the assigned BHW's pending registrations</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
