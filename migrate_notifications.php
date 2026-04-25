<?php
require_once 'config/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `type` varchar(50) NOT NULL,
        `message` text NOT NULL,
        `link` varchar(255) DEFAULT NULL,
        `is_read` tinyint(1) DEFAULT 0,
        `created_at` datetime DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($sql);
    echo "Notifications table created successfully.\n";
} catch(PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>
