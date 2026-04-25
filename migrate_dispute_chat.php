<?php
require 'config/db.php';

$sql = "
CREATE TABLE IF NOT EXISTS `dispute_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispute_id` INT NOT NULL,
    `sender_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `image_path` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`dispute_id`) REFERENCES `order_disputes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $conn->exec($sql);
    echo "Successfully created dispute_messages table.";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
