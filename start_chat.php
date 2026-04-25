<?php
/**
 * Start Chat — Creates or resumes a conversation
 * Supports: listing negotiation OR direct seller chat
 */
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = intval($_SESSION['user_id']);
$sellerId = intval($_GET['seller_id'] ?? 0);
$listingId = intval($_GET['listing_id'] ?? 0);

// Direct chat with seller (from seller profile)
if ($sellerId > 0 && $sellerId !== $userId) {
    header("Location: negotiate.php?direct_seller_id={$sellerId}&tab=direct");
    exit;
}

// Negotiation on a listing
if ($listingId > 0) {
    header("Location: negotiate.php?listing_id={$listingId}&tab=buying");
    exit;
}

// Fallback
header('Location: negotiate.php');
exit;
?>
