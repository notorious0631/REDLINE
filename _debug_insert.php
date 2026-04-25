<?php
require 'config/db.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $conn->beginTransaction();

    $stmtOrder = $conn->prepare("
        INSERT INTO orders (buyer_id, seller_id, total, shipping_name, shipping_address, shipping_city, shipping_state, shipping_pincode, shipping_phone, payment_status, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'upi')
    ");

    $stmtOrder->execute([
        1,
        2,
        1500,
        "John Doe",
        "123 Street",
        "Mumbai",
        "MH",
        "400001",
        "9876543210"
    ]);

    echo "Inserted successfully, order ID: " . $conn->lastInsertId();
    $conn->rollBack();
} catch (PDOException $e) {
    echo "INSERT ERROR: " . $e->getMessage();
}
?>
