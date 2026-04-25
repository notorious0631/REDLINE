<?php
require 'config/db.php';
try {
    $stmt = $conn->prepare("SELECT * FROM order_disputes ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $dispus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($dispus);
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
