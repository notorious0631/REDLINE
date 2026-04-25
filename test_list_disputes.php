<?php
require 'config/db.php';
$ids = $conn->query("SELECT id FROM order_disputes limit 5")->fetchAll(PDO::FETCH_COLUMN);
print_r($ids);
?>
