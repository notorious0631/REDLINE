<?php
require_once __DIR__ . '/config/db.php';

try {
    // Add shipping_fee to order_items table to store history
    $sql = "ALTER TABLE `order_items` ADD COLUMN `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`";
    
    $conn->exec($sql);
    echo "✅ Migration successful: Added shipping_fee to order_items table.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "ℹ️ Column already exists.";
    } else {
        echo "❌ Migration failed: " . $e->getMessage();
    }
}
