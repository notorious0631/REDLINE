<?php
$conn = new PDO("mysql:host=localhost;dbname=redline", "root", "");
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check if tracking_id column exists
$stmt = $conn->query("SHOW COLUMNS FROM orders LIKE 'tracking_id'");
if ($stmt->rowCount() === 0) {
    $conn->exec("ALTER TABLE orders ADD COLUMN tracking_id VARCHAR(100) DEFAULT NULL AFTER estimated_delivery");
    echo "Column 'tracking_id' added successfully.";
} else {
    echo "Column 'tracking_id' already exists.";
}

// Show all columns
echo "\n\nAll columns:\n";
$stmt = $conn->query('DESCRIBE orders');
foreach($stmt as $r) {
    echo $r['Field'] . ' | ' . $r['Type'] . "\n";
}
