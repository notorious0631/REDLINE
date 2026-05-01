<?php
$host = "localhost";
$db_name = "redline";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection error: " . $e->getMessage();
}

function containsBlockedLinks($text) {
    if (empty($text)) return false;
    // Block WhatsApp Group and Telegram links
    $pattern = '/(chat\.whatsapp\.com|t\.me|telegram\.me|telegram\.dog)/i';
    return preg_match($pattern, $text) === 1;
}
?>
