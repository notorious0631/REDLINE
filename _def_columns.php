<?php
require 'config/db.php';
$stmt = $conn->query("SHOW COLUMNS FROM orders");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " | ";
}
?>
