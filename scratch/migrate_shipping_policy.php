<?php
require_once 'config/db.php';

try {
    $conn->exec("ALTER TABLE users ADD COLUMN shipping_type VARCHAR(20) DEFAULT 'per_item'");
} catch (PDOException $e) {}

try {
    $conn->exec("ALTER TABLE users ADD COLUMN standard_shipping_fee DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}

try {
    $conn->exec("ALTER TABLE users ADD COLUMN transit_responsibility VARCHAR(20) DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS shipping_tiers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seller_id INT NOT NULL,
            min_items INT NOT NULL,
            shipping_fee DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {}

echo "Migrations completed.";
