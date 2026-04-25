<?php
require 'config/db.php';
session_start();

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user = $conn->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    $listing = $conn->query("SELECT * FROM listings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$listing) {
        die("No user or listing found.");
    }

    $cartItems = [$listing];
    $name = 'Test Name';
    $address = 'TEST ADDR';
    $city = 'City';
    $state = 'State';
    $pincode = '123456';
    $phone = '1234567890';

    $conn->beginTransaction();

    $itemsBySeller = [];
    foreach ($cartItems as $item) {
        $sellerId = $item['seller_id'];
        if (!isset($itemsBySeller[$sellerId])) {
            $itemsBySeller[$sellerId] = [
                'total' => 0,
                'items' => []
            ];
        }
        $itemsBySeller[$sellerId]['total'] += $item['price'];
        $itemsBySeller[$sellerId]['items'][] = $item;
    }

    $orderIds = [];

    $stmtOrder = $conn->prepare("
        INSERT INTO orders (buyer_id, seller_id, total, shipping_name, shipping_address, shipping_city, shipping_state, shipping_pincode, shipping_phone, payment_status, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'upi')
    ");
    
    $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, listing_id, price) VALUES (?, ?, ?)");
    $stmtUpdateStatus = $conn->prepare("UPDATE listings SET status = 'sold' WHERE id = ?");

    foreach ($itemsBySeller as $sellerId => $sellerGroup) {
        $stmtOrder->execute([
            $user,
            $sellerId,
            $sellerGroup['total'],
            $name, $address, $city, $state, $pincode, $phone
        ]);
        
        $orderId = $conn->lastInsertId();
        $orderIds[] = $orderId;

        foreach ($sellerGroup['items'] as $item) {
            $stmtItem->execute([$orderId, $item['id'], $item['price']]);
            $stmtUpdateStatus->execute([$item['id']]);
        }
    }

    $conn->commit();
    echo "SUCCESS: " . implode(',', $orderIds);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "DEBUG INFO: User=" . $user . ", Seller=" . $sellerId . ", Total=" . $sellerGroup['total'];
}
?>
