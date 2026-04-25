<?php
require_once 'config/db.php';

try {
    // Negotiations table — one per buyer×listing pair
    $conn->exec("CREATE TABLE IF NOT EXISTS `negotiations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `listing_id` INT NOT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `status` ENUM('active','accepted','rejected','expired') DEFAULT 'active',
        `offered_price` DECIMAL(10,2) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`listing_id`) REFERENCES `listings`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`buyer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`seller_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        UNIQUE KEY `unique_negotiation` (`listing_id`, `buyer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Negotiation messages table
    $conn->exec("CREATE TABLE IF NOT EXISTS `negotiation_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `negotiation_id` INT NOT NULL,
        `sender_id` INT NOT NULL,
        `message` TEXT DEFAULT NULL,
        `msg_type` ENUM('text','offer','accept','reject','counter') DEFAULT 'text',
        `offer_amount` DECIMAL(10,2) DEFAULT NULL,
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`negotiation_id`) REFERENCES `negotiations`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "<h2 style='color:green;'>✅ Chat tables created successfully!</h2>";
    echo "<p><strong>negotiations</strong> — stores buyer-seller conversation metadata</p>";
    echo "<p><strong>negotiation_messages</strong> — stores individual chat messages and offers</p>";
    echo "<p><a href='index.php'>← Back to Marketplace</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>❌ Error: " . $e->getMessage() . "</h2>";
}
?>
