<?php
// Migration: Add quantity column to order_items table
require_once 'config/db.php';

try {
    $cols = array_column($conn->query("SHOW COLUMNS FROM `order_items`")->fetchAll(), 'Field');
    if (!in_array('quantity', $cols)) {
        $conn->exec("ALTER TABLE `order_items` ADD COLUMN `quantity` INT NOT NULL DEFAULT 1 AFTER `price`");
        echo "✅ Migration successful! Added 'quantity' column to order_items table (default: 1).";
    } else {
        echo "✅ Column 'quantity' already exists. No migration needed.";
    }
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage();
}
