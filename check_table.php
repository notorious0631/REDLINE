<?php
require 'config/db.php';
try {
    $stmt = $conn->query("SHOW CREATE TABLE negotiations");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'];
} catch(Exception $e) {
    echo $e->getMessage();
}
?>
