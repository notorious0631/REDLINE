<?php
require_once 'config/db.php';

try {
    // Add upi_id column to seller_applications table
    $conn->exec("ALTER TABLE `seller_applications` ADD COLUMN `upi_id` VARCHAR(100) DEFAULT NULL AFTER `selfie_with_aadhar_path`");
    echo "Migration successful: Added upi_id column to seller_applications table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column upi_id already exists. No changes made.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
