<?php
// Migration: Add aadhar_back_path and selfie_with_aadhar_path columns to seller_applications
require_once 'config/db.php';

try {
    // Check if columns already exist
    $cols = $conn->query("SHOW COLUMNS FROM seller_applications")->fetchAll(PDO::FETCH_COLUMN);

    $alterParts = [];

    if (!in_array('aadhar_back_path', $cols)) {
        $alterParts[] = "ADD COLUMN `aadhar_back_path` VARCHAR(255) DEFAULT NULL AFTER `aadhar_path`";
    }

    if (!in_array('selfie_with_aadhar_path', $cols)) {
        $alterParts[] = "ADD COLUMN `selfie_with_aadhar_path` VARCHAR(255) DEFAULT NULL AFTER `pan_path`";
    }

    if (!empty($alterParts)) {
        $sql = "ALTER TABLE `seller_applications` " . implode(', ', $alterParts);
        $conn->exec($sql);
        echo "✅ Migration successful! Added new KYC columns to seller_applications.<br>";
        echo "Columns added: " . implode(', ', array_map(function ($p) {
            return explode('`', $p)[1]; }, $alterParts));
    } else {
        echo "✅ All columns already exist. No migration needed.";
    }
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage();
}
?>