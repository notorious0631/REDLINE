<?php
require '../config/db.php';
$stmt = $conn->query("DESCRIBE listings");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "LISTINGS COLUMNS:\n";
foreach ($columns as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

$stmt = $conn->query("SELECT l.id, l.title, l.seller_id, u.name as seller_name FROM listings l JOIN users u ON l.seller_id = u.id WHERE l.title LIKE '%Nissan%' OR l.title LIKE '%bhbh%' OR u.name LIKE '%Bhaskar%' OR u.name LIKE '%Admin%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nMATCHING LISTINGS:\n";
foreach ($rows as $row) {
    echo "ID: " . $row['id'] . " | Title: " . $row['title'] . " | Seller ID: " . $row['seller_id'] . " | Seller: " . $row['seller_name'] . "\n";
}
?>
