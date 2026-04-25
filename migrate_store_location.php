<?php
require 'config/db.php';
try {
    $conn->exec("ALTER TABLE users ADD COLUMN store_location VARCHAR(255) DEFAULT NULL");
    echo "Column added successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
