<?php
// seller_dashboard/negotiations.php
// Now redirects to the main messenger with selling tab
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

header('Location: ../negotiate.php?tab=selling');
exit;
?>
