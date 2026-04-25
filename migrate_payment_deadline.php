<?php
require_once __DIR__ . '/config/db.php';

$queries = [
    "ALTER TABLE `orders` ADD COLUMN `payment_deadline` DATETIME DEFAULT NULL AFTER `payment_proof`"
];

foreach ($queries as $sql) {
    try {
        $conn->exec($sql);
        echo "Success: $sql\n<br>";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "Skipped (already exists): $sql\n<br>";
        } else {
            echo "Error: " . $e->getMessage() . "\n<br>";
        }
    }
}
echo "Migration complete.";
?>
