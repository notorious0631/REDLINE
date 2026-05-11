<?php
/**
 * REDLINE — Trust & Quality Metrics Migration
 * Creates tables for: seller_reviews, order_disputes, csat_surveys, escrow_deposits
 * Also adds avg_rating/review_count caching columns to users table.
 * Run once via browser: /migrate_trust_metrics.php
 */
require 'config/db.php';

$queries = [
    // Seller Reviews table
    "CREATE TABLE IF NOT EXISTS `seller_reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `rating` TINYINT NOT NULL DEFAULT 5 COMMENT '1-5 stars',
        `review_text` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_order_review` (`order_id`, `buyer_id`),
        FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Order Disputes / Flags table
    "CREATE TABLE IF NOT EXISTS `order_disputes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `reporter_id` INT NOT NULL,
        `type` ENUM('not_received', 'wrong_item', 'damaged', 'scam', 'counterfeit', 'other') DEFAULT 'other',
        `description` TEXT DEFAULT NULL,
        `status` ENUM('open', 'investigating', 'resolved', 'dismissed') DEFAULT 'open',
        `resolution_notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `resolved_at` TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // CSAT / NPS Surveys table
    "CREATE TABLE IF NOT EXISTS `csat_surveys` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `order_id` INT DEFAULT NULL,
        `score` TINYINT NOT NULL COMMENT '1-10 scale, 9-10=promoter, 7-8=passive, 1-6=detractor',
        `feedback` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Escrow / Genuineness Deposits table
    "CREATE TABLE IF NOT EXISTS `escrow_deposits` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `status` ENUM('held', 'released', 'refunded', 'disputed') DEFAULT 'held',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `released_at` TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Add cached rating columns to users table for fast lookups
    "ALTER TABLE `users` ADD COLUMN `avg_rating` DECIMAL(3,2) DEFAULT 0.00 AFTER `is_verified`",
    "ALTER TABLE `users` ADD COLUMN `review_count` INT DEFAULT 0 AFTER `avg_rating`",
    "ALTER TABLE `seller_reviews` ADD COLUMN `review_image` VARCHAR(255) DEFAULT NULL AFTER `review_text`",
];

echo "<h2>REDLINE — Trust & Quality Metrics Migration</h2><pre>";
foreach ($queries as $sql) {
    try {
        $conn->exec($sql);
        echo "✅ Success: " . substr($sql, 0, 80) . "...\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⏭️ Skipped (already exists): " . substr($sql, 0, 80) . "...\n";
        } else {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }
}
echo "\n✅ Migration complete.</pre>";
?>
