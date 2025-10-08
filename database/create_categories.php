<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db();
    
    // Create categories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "Categories table created successfully!\n";
    
    // Add category_id column to medicines table if it doesn't exist
    $columns = $pdo->query("SHOW COLUMNS FROM medicines LIKE 'category_id'")->fetch();
    if (!$columns) {
        $pdo->exec("ALTER TABLE medicines ADD COLUMN category_id INT NULL");
        echo "Added category_id column to medicines table!\n";
    } else {
        echo "category_id column already exists in medicines table!\n";
    }
    
    // Insert default categories
    $defaultCategories = [
        ['Pain Relief', 'Medicines for pain management and relief'],
        ['Antibiotics', 'Antibacterial medications'],
        ['Vitamins', 'Vitamin supplements and nutritional aids'],
        ['First Aid', 'Basic first aid supplies and medications'],
        ['Chronic Care', 'Medicines for chronic conditions'],
        ['Emergency', 'Emergency medications and supplies']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = name");
    foreach ($defaultCategories as $category) {
        $stmt->execute($category);
    }
    echo "Default categories added!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
