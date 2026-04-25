<?php
require 'config/db.php';
try {
    $res = $conn->query('DESCRIBE order_disputes')->fetchAll(PDO::FETCH_ASSOC);
    echo "order_disputes:\n";
    print_r($res);
    $res = $conn->query('DESCRIBE dispute_messages')->fetchAll(PDO::FETCH_ASSOC);
    echo "\ndispute_messages:\n";
    print_r($res);
} catch(Exception $e) {
    echo $e->getMessage();
}
?>
