<?php
require 'config/db.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['close_ticket'] = true;
$_POST['action_status'] = 'resolved';
$_POST['resolution_notes'] = 'simulate close';
$disputeId = 2;

try {
    $stmt = $conn->prepare("
        SELECT d.*, o.buyer_id, o.seller_id, o.id as order_id, o.total, o.status as order_status, o.payment_status
        FROM order_disputes d
        JOIN orders o ON d.order_id = o.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        die("No dispute found with ID $disputeId");
    }

    $action = $_POST['action_status'];
    $notes = trim($_POST['resolution_notes']);

    if (in_array($action, ['resolved', 'dismissed'])) {
        $conn->prepare("UPDATE order_disputes SET status = ?, resolution_notes = ?, resolved_at = NOW() WHERE id = ?")->execute([$action, $notes, $disputeId]);
        
        $msg = $action === 'resolved' ? "Dispute #$disputeId has been marked as Resolved by Admin." : "Dispute #$disputeId has been Dismissed by Admin.";
        $notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_closed', ?, ?)");
        $notify->execute([$dispute['buyer_id'], $msg, "view_dispute.php?id=$disputeId"]);
        $notify->execute([$dispute['seller_id'], $msg, "view_dispute.php?id=$disputeId"]);
        
        echo "SUCCESS!";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
