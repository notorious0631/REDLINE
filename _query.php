<?php
require 'config/db.php';

try {
    $conn->exec("ALTER TABLE orders ADD COLUMN seller_id INT DEFAULT NULL");
    echo "Added seller_id successfully.";
} catch (PDOException $e) {
    echo "Error adding seller_id: " . $e->getMessage() . " (Code: " . $e->getCode() . ")\n<br>";
}

try {
    $stmt = $conn->query("DESC orders");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}
?>
