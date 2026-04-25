<?php
require_once 'config/db.php';

try {
    $sql = "ALTER TABLE `seller_applications` 
            MODIFY `aadhar_path` VARCHAR(255) NULL,
            MODIFY `pan_path` VARCHAR(255) NULL;";
    
    $conn->exec($sql);
    echo "Successfully updated seller_applications table schema!";
} catch (PDOException $e) {
    echo "Error updating table: " . $e->getMessage();
}
?>
