<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting database update for separate name fields...\n";
    
    // Add new columns to family_members table
    echo "Adding columns to family_members table...\n";
    db()->exec("ALTER TABLE family_members 
                ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '',
                ADD COLUMN middle_initial VARCHAR(5) NOT NULL DEFAULT '',
                ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT ''");
    
    // Add new columns to resident_family_additions table
    echo "Adding columns to resident_family_additions table...\n";
    db()->exec("ALTER TABLE resident_family_additions 
                ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '',
                ADD COLUMN middle_initial VARCHAR(5) NOT NULL DEFAULT '',
                ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT ''");
    
    // Migrate existing full_name data to separate fields
    echo "Migrating data in family_members table...\n";
    $stmt = db()->prepare("SELECT id, full_name FROM family_members WHERE full_name IS NOT NULL AND full_name != ''");
    $stmt->execute();
    $family_members = $stmt->fetchAll();
    
    foreach ($family_members as $member) {
        $name_parts = explode(' ', trim($member['full_name']));
        $first_name = $name_parts[0] ?? '';
        $last_name = end($name_parts) ?? '';
        $middle_initial = '';
        
        if (count($name_parts) > 2) {
            $middle_initial = $name_parts[1] ?? '';
        }
        
        $update_stmt = db()->prepare("UPDATE family_members SET first_name = ?, middle_initial = ?, last_name = ? WHERE id = ?");
        $update_stmt->execute([$first_name, $middle_initial, $last_name, $member['id']]);
    }
    
    echo "Migrating data in resident_family_additions table...\n";
    $stmt = db()->prepare("SELECT id, full_name FROM resident_family_additions WHERE full_name IS NOT NULL AND full_name != ''");
    $stmt->execute();
    $pending_members = $stmt->fetchAll();
    
    foreach ($pending_members as $member) {
        $name_parts = explode(' ', trim($member['full_name']));
        $first_name = $name_parts[0] ?? '';
        $last_name = end($name_parts) ?? '';
        $middle_initial = '';
        
        if (count($name_parts) > 2) {
            $middle_initial = $name_parts[1] ?? '';
        }
        
        $update_stmt = db()->prepare("UPDATE resident_family_additions SET first_name = ?, middle_initial = ?, last_name = ? WHERE id = ?");
        $update_stmt->execute([$first_name, $middle_initial, $last_name, $member['id']]);
    }
    
    echo "Database update completed successfully!\n";
    echo "New columns added: first_name, middle_initial, last_name\n";
    echo "Existing full_name data has been migrated to separate fields\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
