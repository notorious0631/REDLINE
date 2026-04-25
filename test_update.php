<?php
require 'config/db.php';
try {
    $conn->prepare("UPDATE order_disputes SET status = 'resolved', resolution_notes = 'test', resolved_at = NOW() WHERE id = 1")->execute();
    echo 'Success updates';
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
