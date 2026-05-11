<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$error = '';
$success = '';

if (!$orderId) {
    header('Location: order_view.php');
    exit;
}

// Verify user is part of the order
try {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $error = "Order not found.";
    } elseif ($order['buyer_id'] != $userId && $order['seller_id'] != $userId) {
        $error = "You do not have permission to view or dispute this order.";
    } else {
        // Find counterparty
        $counterpartyId = ($userId == $order['buyer_id']) ? $order['seller_id'] : $order['buyer_id'];
    }
} catch (PDOException $e) {
    $error = "Database error.";
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dispute']) && empty($error)) {
    $type = $_POST['type'] ?? 'other';
    $description = trim($_POST['description'] ?? '');

    if (empty($description)) {
        $error = "Please provide details about the issue.";
    } else {
        try {
            // Insert Dispute
            $stmt = $conn->prepare("INSERT INTO order_disputes (order_id, reporter_id, type, description, status) VALUES (?, ?, ?, ?, 'open')");
            $stmt->execute([$orderId, $userId, $type, $description]);
            $disputeId = $conn->lastInsertId();

            // Notify Counterparty
            $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_opened', ?, ?)")
                 ->execute([$counterpartyId, "A dispute (#$disputeId) has been opened regarding Order #$orderId.", "disputes.php"]);

            // Notify Admins
            $stmtAdmin = $conn->query("SELECT id FROM users WHERE role = 'admin'");
            $admins = $stmtAdmin->fetchAll(PDO::FETCH_COLUMN);
            $adminNotifyQuery = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_alert', ?, ?)");
            foreach($admins as $adminId) {
                $adminNotifyQuery->execute([$adminId, "New Dispute (#$disputeId) filed for Order #$orderId.", "admin/disputes.php"]);
            }

            $success = "Your dispute has been submitted successfully to administration. We will review it shortly.";
        } catch (PDOException $e) {
            $error = "Failed to submit dispute. Try again later.";
        }
    }
}

$pageTitle = 'Report Issue';
include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/sell.css">
<style>
    .dispute-container { max-width: 600px; margin: 60px auto; padding: 30px; background: var(--bg-surface); border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
    .dispute-header { text-align: center; margin-bottom: 24px; }
    .dispute-header h2 { font-size: 1.8rem; font-family: var(--font-display); color: var(--text-primary); margin-bottom: 8px; }
    .dispute-header p { color: var(--text-secondary); font-size: 0.95rem; }
</style>

<div class="container-rl" style="padding-top:40px; padding-bottom:60px;">
    <div class="dispute-container" data-aos="fade-up">
        <div class="dispute-header">
            <h2><i class="fas fa-exclamation-triangle" style="color:var(--accent-red); margin-right:10px;"></i> Report an Issue</h2>
            <p>Order #<?php echo $orderId; ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e57373; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <div style="text-align:center;">
                <a href="order_view.php" class="btn-outline-white">Go Back</a>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success" style="background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #81c784; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; text-align:center;">
                <i class="fas fa-check-circle" style="font-size:2rem; margin-bottom:10px; display:block;"></i> 
                <?php echo htmlspecialchars($success); ?>
            </div>
            <div style="text-align:center; margin-top:20px;">
                <a href="disputes.php" class="btn-red"><i class="fas fa-clipboard-list"></i> View My Tickets</a>
                <a href="order_view.php" class="btn-outline-white" style="margin-left:10px;">Back to Orders</a>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <div class="form-group pb-3">
                    <label class="form-label">Nature of Issue</label>
                    <select name="type" class="sell-form-control" required style="background:var(--bg-layer-2); cursor:pointer; color-scheme:dark; color:var(--text-primary);">
                        <option value="not_received">Item Not Received</option>
                        <option value="wrong_item">Received Wrong Item</option>
                        <option value="damaged">Item Damaged in Transit</option>
                        <option value="counterfeit">Suspected Counterfeit/Fake</option>
                        <option value="scam">Fraudulent Activity / Scam</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group pb-4">
                    <label class="form-label">Detailed Description</label>
                    <textarea name="description" class="sell-form-control" rows="5" required placeholder="Please provide as much context as possible. Admins will review this to assist..."></textarea>
                </div>
                
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <a href="order_view.php" style="color:var(--text-secondary); text-decoration:none;"><i class="fas fa-arrow-left"></i> Cancel</a>
                    <button type="submit" name="submit_dispute" class="btn-red"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
