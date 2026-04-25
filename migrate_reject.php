<?php
require 'config/db.php';
try {
    $conn->exec("ALTER TABLE orders ADD COLUMN payment_rejection_reason TEXT DEFAULT NULL");
    echo "Column payment_rejection_reason added.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false || $e->getCode() == '42S21') {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
