<?php
require 'config/db.php';
try {
    $conn->exec("ALTER TABLE `orders` ADD COLUMN `estimated_delivery` DATE DEFAULT NULL AFTER `status`");
    echo "Added estimated_delivery column.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
