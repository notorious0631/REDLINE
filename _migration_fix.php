<?php
require 'config/db.php';

$queries = [
    "ALTER TABLE `orders` ADD COLUMN `seller_id` INT DEFAULT NULL AFTER `buyer_id`",
    "ALTER TABLE `orders` ADD COLUMN `payment_status` ENUM('pending', 'verifying', 'confirmed', 'failed') DEFAULT 'pending'",
    "ALTER TABLE `orders` ADD COLUMN `transaction_id` VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE `orders` ADD COLUMN `payment_proof` VARCHAR(255) DEFAULT NULL"
];

foreach ($queries as $q) {
    try {
        $conn->exec($q);
        echo "OK: $q<br>\n";
    } catch (Exception $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "Skipped (Exists): $q<br>\n";
        } else {
            echo "ERR: " . $e->getMessage() . "<br>\n";
        }
    }
}
?>
