<?php
require 'config/db.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$q = "ALTER TABLE `orders` ADD COLUMN `seller_id` INT DEFAULT NULL AFTER `buyer_id`";
$conn->exec($q);
echo "DONE.";
?>
