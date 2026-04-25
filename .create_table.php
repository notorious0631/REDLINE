<?php
require 'config/db.php';
$stmt = $conn->query("
CREATE TABLE IF NOT EXISTS user_follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    follower_id INT NOT NULL,
    seller_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY(follower_id, seller_id)
);
");
echo "Created user_follows table\n";
?>
