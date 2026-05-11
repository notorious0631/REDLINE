<?php
require_once __DIR__ . '/config/db.php';

try {
    // Add shipping_fee to listings table
    $sql = "ALTER TABLE `listings` ADD COLUMN `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`";
    
    $conn->exec($sql);
    echo "Migration successful: Added shipping_fee to listings table.\n";
} catch (PDOException $e) {
    // Check if duplicate column error (1060)
    if (strpos($e->getMessage(), 'Duplicate column name') !== false || $e->getCode() == '42S21') {
        echo "Migration already applied: Column exists.\n";
    } else {
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}
?>
