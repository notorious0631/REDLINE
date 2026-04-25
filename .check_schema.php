<?php
require 'config/db.php';
$stmt = $conn->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES:\n" . implode("\n", $tables) . "\n\n";
?>
