<?php
require 'config/db.php';
try {
    $stmt = $conn->query("SHOW COLUMNS FROM order_items");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . " | ";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
