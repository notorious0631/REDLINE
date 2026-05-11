<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'config/db.php';
$conn->exec("ALTER TABLE listings ADD COLUMN is_mrp TINYINT(1) DEFAULT 0 AFTER price");
echo "Added is_mrp successfully.";
?>
