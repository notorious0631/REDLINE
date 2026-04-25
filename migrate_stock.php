<?php
// Migration: Add stock column to listings table
require_once 'config/db.php';

try {
    $cols = $conn->query("SHOW COLUMNS FROM listings")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('stock', $cols)) {
        $conn->exec("ALTER TABLE `listings` ADD COLUMN `stock` INT NOT NULL DEFAULT 1 AFTER `views`");
        echo "✅ Migration successful! Added 'stock' column to listings table (default: 1).";
    } else {
        echo "✅ Column 'stock' already exists. No migration needed.";
    }
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage();
}
?>
