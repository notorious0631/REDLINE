<?php
require_once 'config/db.php';

echo "<h2>Fixing Notification Links</h2><pre>";

try {
    // 1. Fix links to orders.php -> seller_dashboard/orders.php
    $stmt1 = $conn->prepare("UPDATE notifications SET link = 'seller_dashboard/orders.php' WHERE link = 'orders.php'");
    $stmt1->execute();
    echo "✅ Updated " . $stmt1->rowCount() . " seller notification links.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n🎉 Fix complete!</pre>";
