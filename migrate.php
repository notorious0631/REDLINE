<?php
require_once 'config/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS `seller_applications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `aadhar_path` VARCHAR(255) NOT NULL,
        `pan_path` VARCHAR(255) NOT NULL,
        `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        `admin_notes` TEXT DEFAULT NULL,
        `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $conn->exec($sql);
    echo "Successfully created seller_applications table!";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
