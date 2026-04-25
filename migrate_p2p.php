<?php
require 'config/db.php';

$queries = [
    "ALTER TABLE `orders` ADD COLUMN `seller_id` INT DEFAULT NULL AFTER `buyer_id`",
    "ALTER TABLE `orders` ADD COLUMN `payment_status` ENUM('pending', 'verifying', 'confirmed', 'failed') DEFAULT 'pending'",
    "ALTER TABLE `orders` ADD COLUMN `transaction_id` VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE `orders` ADD COLUMN `payment_proof` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `orders` MODIFY COLUMN `payment_method` VARCHAR(50) DEFAULT 'upi'"
];

foreach ($queries as $sql) {
    try {
        $conn->exec($sql);
        echo "Success: $sql\n<br>";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "Skipped (already exists): $sql\n<br>";
        } else {
            echo "Error executing $sql: " . $e->getMessage() . "\n<br>";
        }
    }
}
echo "Migration complete.";
?>
