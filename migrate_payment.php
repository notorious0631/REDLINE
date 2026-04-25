<?php
require_once __DIR__ . '/config/db.php';

try {
    // Add upi_id and bank_details to users table
    $sql = "ALTER TABLE `users` 
            ADD COLUMN `upi_id` VARCHAR(100) DEFAULT NULL, 
            ADD COLUMN `bank_details` TEXT DEFAULT NULL";
    
    $conn->exec($sql);
    echo "Migration successful: Added upi_id and bank_details to users table.\n";
} catch (PDOException $e) {
    // Check if duplicate column error (1060)
    if (strpos($e->getMessage(), 'Duplicate column name') !== false || $e->getCode() == '42S21') {
        echo "Migration already applied: Columns exist.\n";
    } else {
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}
?>
