<?php
require 'config/db.php';
$stmt = $conn->query("DESCRIBE orders");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
?>
