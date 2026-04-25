<?php
/**
 * Migration: Create listing_images table for multi-image support.
 * The legacy listings.image column is kept for backward compatibility (stores first image).
 */
require_once 'config/db.php';

echo "<h2 style='font-family:sans-serif;'>📸 Multi-Image Migration</h2>";

$queries = [
    // 1. New table for multiple images per listing
    "CREATE TABLE IF NOT EXISTS `listing_images` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `listing_id` INT NOT NULL,
        `image_path` VARCHAR(500) NOT NULL,
        `sort_order` TINYINT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`) ON DELETE CASCADE,
        INDEX idx_listing_sort (`listing_id`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 2. Migrate existing single images into the new table
    "INSERT IGNORE INTO `listing_images` (`listing_id`, `image_path`, `sort_order`)
     SELECT `id`, `image`, 0 FROM `listings` WHERE `image` IS NOT NULL AND `image` != ''"
];

foreach ($queries as $i => $sql) {
    try {
        $conn->exec($sql);
        echo "<p style='color:green; font-family:sans-serif;'>✅ Query " . ($i + 1) . " executed successfully.</p>";
    } catch (PDOException $e) {
        echo "<p style='color:orange; font-family:sans-serif;'>⚠️ Query " . ($i + 1) . ": " . $e->getMessage() . "</p>";
    }
}

echo "<h3 style='color:green; font-family:sans-serif;'>✅ Migration complete.</h3>";
echo "<p style='font-family:sans-serif;'><a href='index.php'>← Back to site</a></p>";
?>
