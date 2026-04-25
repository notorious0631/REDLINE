<?php
require_once 'config/db.php';
try {
    $conn->exec("ALTER TABLE orders ADD COLUMN seller_statement VARCHAR(255) DEFAULT NULL");
    echo "SUCCESS";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) echo "ALREADY_EXISTS";
    else echo "ERROR: " . $e->getMessage();
}
?>
