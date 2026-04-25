<?php
// seller_dashboard/orders.php
$pageTitle = 'Fulfill Orders';
include 'header.php';

$sellerId = $_SESSION['user_id'];

// Handle Payment Confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $orderId = intval($_POST['order_id']);
    try {
        $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $buyerId = $stmt->fetchColumn();

        $conn->prepare("UPDATE orders SET payment_status = 'confirmed' WHERE id = ? AND seller_id = ?")->execute([$orderId, $sellerId]);
        
        $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'payment_confirmed', ?, ?)")
             ->execute([$buyerId, "Your payment for Order #$orderId has been confirmed.", "order_view.php"]);

        $success = "Payment confirmed! Please dispatch the items to the buyer directly.";
    } catch (PDOException $e) {}
}

// Handle Reject Payment With Reason
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_payment_with_reason'])) {
    $orderId = intval($_POST['order_id']);
    $reason = trim($_POST['rejection_reason'] ?? '');
    
    // Process File Upload
    $statementPath = '';
    if (isset($_FILES['bank_statement']) && $_FILES['bank_statement']['error'] == UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/statements/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $ext = pathinfo($_FILES['bank_statement']['name'], PATHINFO_EXTENSION);
        $filename = 'statement_' . $orderId . '_' . time() . '.' . $ext;
        $dest = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['bank_statement']['tmp_name'], $dest)) {
            $statementPath = 'uploads/statements/' . $filename;
        }
    }
    
    if (empty($reason)) {
        $error = "Please provide a reason for rejecting the payment.";
    } elseif (empty($statementPath)) {
        $error = "A valid bank statement is mandatory to reject a payment.";
    } else {
        try {
            $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $buyerId = $stmt->fetchColumn();

            $conn->prepare("UPDATE orders SET payment_status = 'failed', payment_rejection_reason = ?, seller_statement = ? WHERE id = ? AND seller_id = ?")->execute([$reason, $statementPath, $orderId, $sellerId]);
            
            $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'payment_rejected', ?, ?)")
                 ->execute([$buyerId, "Your payment proof for Order #$orderId was rejected. Reason: $reason", "order_view.php"]);

            $success = "Payment rejected. Buyer has been notified and can raise a dispute if needed.";
        } catch (PDOException $e) {}
    }
}

// Handle Set ETA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_eta'])) {
    $orderId = intval($_POST['order_id']);
    $etaDate = $_POST['eta_date'] ?? null;
    if ($etaDate) {
        try {
            $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $buyerId = $stmt->fetchColumn();

            $conn->prepare("UPDATE orders SET estimated_delivery = ? WHERE id = ? AND seller_id = ?")->execute([$etaDate, $orderId, $sellerId]);
            
            $formattedEta = date('d M Y', strtotime($etaDate));
            $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'eta_updated', ?, ?)")
                 ->execute([$buyerId, "The estimated delivery for Order #$orderId has been set to $formattedEta.", "order_view.php"]);

            $success = "Estimated time of delivery has been set to $formattedEta and buyer notified.";
        } catch (PDOException $e) {}
    }
}

// Handle Set Tracking Link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_tracking'])) {
    $orderId = intval($_POST['order_id']);
    $trackingId = trim($_POST['tracking_id'] ?? '');
    if ($trackingId) {
        try {
            $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $buyerId = $stmt->fetchColumn();

            $conn->prepare("UPDATE orders SET tracking_id = ? WHERE id = ? AND seller_id = ?")->execute([$trackingId, $orderId, $sellerId]);
            
            $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'tracking_updated', ?, ?)")
                 ->execute([$buyerId, "Tracking Link for Order #$orderId has been updated: $trackingId", "order_view.php"]);

            $success = "Tracking Link for Order #$orderId has been set to: $trackingId";
        } catch (PDOException $e) {}
    }
}

// Handle Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_shipped'])) {
    $orderId = intval($_POST['order_id']);
    try { 
        $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $buyerId = $stmt->fetchColumn();

        $conn->prepare("UPDATE orders SET status = 'shipped' WHERE id = ? AND seller_id = ?")->execute([$orderId, $sellerId]); 

        $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'order_shipped', ?, ?)")
             ->execute([$buyerId, "Your Order #$orderId has been dispatched.", "order_view.php"]);

        $success = "Order marked as dispatched. Buyer will be notified."; 
    } catch(PDOException $e) {}
}

// Handle Mark Delivered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_delivered'])) {
    $orderId = intval($_POST['order_id']);
    try {
        $stmt = $conn->prepare("SELECT buyer_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $buyerId = $stmt->fetchColumn();

        $conn->prepare("UPDATE orders SET status = 'delivered' WHERE id = ? AND seller_id = ?")->execute([$orderId, $sellerId]);

        $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'order_delivered', ?, ?)")
             ->execute([$buyerId, "Your Order #$orderId has been delivered! You can now leave a review.", "order_view.php"]);

        $success = "Order marked as delivered. Buyer has been notified and can now leave a review.";
    } catch(PDOException $e) {}
}

// Fetch Orders directly corresponding to the Seller
$orders = [];
try {
    $stmt = $conn->prepare("
        SELECT o.*, 
               u.name as buyer_name, u.email as buyer_email
        FROM orders o
        JOIN users u ON o.buyer_id = u.id
        WHERE o.seller_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$sellerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

?>

<div class="page-header">
    <div class="page-title">
        <h1>Order Fulfillment</h1>
        <p>Track your sold items and prepare them for logistics</p>
    </div>
</div>

<?php if (isset($success)): ?>
    <div style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); color: var(--accent-green); padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.2); color: #e53935; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="panel">
    <div style="background:rgba(79,195,247,0.05); padding:16px 20px; border-radius:12px; border:1px solid rgba(79,195,247,0.1); display:flex; gap:12px; align-items:flex-start; margin-bottom: 24px;">
        <i class="fas fa-info-circle" style="color:#4fc3f7; margin-top:2px;"></i>
        <p style="font-size:0.9rem; color:var(--text-secondary); line-height:1.5;">REDLINE uses a Direct P2P Payment model. <strong style="color:var(--text-primary);">Wait for the buyer</strong> to upload their payment proof. Once verified, dispatch the items directly to the buyer's address.</p>
    </div>

    <div class="table-container">
        <table class="seller-table">
            <thead>
                <tr>
                    <th>Sale Date</th>
                    <th>Product</th>
                    <th>Sold Price</th>
                    <th>Global Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($orders)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 60px; color:var(--text-muted);">
                        <i class="fas fa-truck-loading" style="font-size:3rem; opacity:0.15; margin-bottom:16px;"></i><br>
                        No sales to fulfill at the moment.
                    </td></tr>
                <?php else: foreach($orders as $o): 
                    // Fetch items for this order
                    $stmt = $conn->prepare("SELECT oi.*, l.title, l.image, l.id as listing_id FROM order_items oi JOIN listings l ON oi.listing_id = l.id WHERE oi.order_id = ?");
                    $stmt->execute([$o['id']]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                    <tr>
                        <td>
                            <strong style="color:var(--text-primary); display:block; font-size:0.95rem;">#<?php echo $o['id']; ?></strong>
                            <span style="font-size:0.8rem; color:var(--text-muted);"><?php echo date('M d, H:i', strtotime($o['created_at'])); ?></span><br>
                            <span style="font-size:0.75rem; color:var(--text-secondary);">Buyer: <?php echo htmlspecialchars($o['buyer_name']); ?></span>
                        </td>
                        <td>
                            <?php foreach($items as $item): ?>
                            <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
                                <?php if(!empty($item['image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($item['image']); ?>" style="width:40px; height:40px; object-fit:cover; border-radius:6px;">
                                <?php else: ?>
                                    <div style="width:40px; height:40px; background:rgba(255,255,255,0.03); border-radius:6px; display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-car-side"></i></div>
                                <?php endif; ?>
                                <div>
                                    <strong style="color:var(--text-primary); display:block; font-size:0.85rem; margin-bottom:2px;"><?php echo htmlspecialchars($item['title']); ?></strong>
                                    <span style="color:var(--accent-red); font-size:0.8rem; font-weight:700;">Rs. <?php echo number_format($item['price'], 0); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </td>
                        <td style="color:var(--accent-red); font-weight:700; align-items:center;">
                            <div style="font-size: 1.1rem;">Rs. <?php echo number_format($o['total'], 0); ?></div>
                        </td>
                        <td>
                            <?php if($o['payment_status'] === 'pending'): ?>
                                <span class="badge" style="background:rgba(255,255,255,0.1); color:#fff;"><i class="fas fa-clock"></i> Unpaid</span>
                            <?php elseif($o['payment_status'] === 'verifying'): ?>
                                <div style="background:rgba(255,183,77,0.1); padding:8px; border-radius:8px; border:1px solid rgba(255,183,77,0.3);">
                                    <div style="color:#ffb74d; font-size:0.8rem; font-weight:700; margin-bottom:4px;"><i class="fas fa-search-dollar"></i> VERIFY PAYMENT</div>
                                    <div style="font-size:0.75rem; color:var(--text-secondary); word-break:break-all;">UTR: <?php echo htmlspecialchars($o['transaction_id']); ?></div>
                                    <?php if(!empty($o['payment_proof'])): ?>
                                        <a href="../<?php echo htmlspecialchars($o['payment_proof']); ?>" target="_blank" style="font-size:0.75rem; color:#4fc3f7; text-decoration:none; display:inline-block; margin-top:4px;"><i class="fas fa-image"></i> View Receipt</a>
                                    <?php endif; ?>
                                </div>
                            <?php elseif($o['payment_status'] === 'confirmed'): ?>
                                <?php if($o['status'] === 'shipped'): ?>
                                    <span class="badge" style="background:rgba(129,199,132,0.15); color:#81c784;"><i class="fas fa-truck"></i> Dispatched</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.15); color:var(--accent-green);"><i class="fas fa-check-double"></i> Paid - Ready to Ship</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if(!empty($o['estimated_delivery']) || !empty($o['tracking_id'])): ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                                <?php if(!empty($o['estimated_delivery'])): ?>
                                <div style="font-size:0.75rem; color:#4fc3f7; display:inline-block; border:1px solid rgba(79,195,247,0.3); padding:4px 8px; border-radius:6px; background:rgba(79,195,247,0.05);">
                                    <i class="far fa-calendar-check"></i> ETA: <?php echo date('d M Y', strtotime($o['estimated_delivery'])); ?>
                                </div>
                                <?php endif; ?>
                                <?php if(!empty($o['tracking_id'])): ?>
                                <div style="font-size:0.75rem; color:#ab47bc; display:inline-block; border:1px solid rgba(171,71,188,0.3); padding:4px 8px; border-radius:6px; background:rgba(171,71,188,0.05);">
                                    <i class="fas fa-shipping-fast"></i> Tracking Link: <?php echo htmlspecialchars($o['tracking_id']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline-flex; gap:8px;">
                                <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                <?php if($o['payment_status'] === 'verifying'): ?>
                                    <button type="submit" name="confirm_payment" class="btn-primary" style="padding:6px 14px; font-size:0.8rem; background:#4fc3f7; color:#0d1117;" onclick="return confirm('Please double-check your bank app that the exact amount was received. Confirm?');"><i class="fas fa-check"></i> Confirm Payment</button>
                                    <button type="button" class="btn-secondary" style="padding:6px 14px; font-size:0.8rem; background:rgba(229,57,53,0.1); color:#e53935; border:1px solid rgba(229,57,53,0.3);" onclick="openRejectModal('<?php echo $o['id']; ?>');"><i class="fas fa-times"></i> Reject</button>
                                <?php elseif($o['payment_status'] === 'failed'): ?>
                                    <button type="button" class="btn-secondary" style="padding:6px 14px; font-size:0.8rem; background:rgba(229,57,53,0.1); color:#e53935; border:1px solid rgba(229,57,53,0.3); opacity:0.8; cursor:not-allowed;" disabled><i class="fas fa-ban"></i> Payment Rejected</button>
                                <?php elseif($o['payment_status'] === 'confirmed' && $o['status'] !== 'shipped' && $o['status'] !== 'delivered'): ?>
                                    <button type="submit" name="mark_shipped" class="btn-primary" style="padding:6px 14px; font-size:0.8rem;">Mark Dispatched</button>
                                <?php elseif($o['payment_status'] === 'confirmed' && $o['status'] === 'shipped'): ?>
                                    <button type="submit" name="mark_delivered" class="btn-primary" style="padding:6px 14px; font-size:0.8rem; background:#10b981; border-color:#10b981;" onclick="return confirm('Confirm that this order has been delivered to the buyer?');"><i class="fas fa-check-double"></i> Mark Delivered</button>
                                <?php elseif($o['payment_status'] === 'pending'): ?>
                                    <button type="button" class="btn-secondary" style="padding:6px 14px; font-size:0.8rem; opacity:0.5; cursor:not-allowed;" disabled>Awaiting Payment</button>
                                <?php else: ?>
                                    <button type="button" class="btn-secondary" style="padding:6px 14px; font-size:0.8rem; opacity:0.5; cursor:not-allowed;" disabled><i class="fas fa-check-circle"></i> Delivered</button>
                                <?php endif; ?>
                            </form>
                            
                            <?php if($o['payment_status'] === 'confirmed'): ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                                <form method="POST" style="display:inline-flex; gap:8px; align-items:center; background:rgba(255,255,255,0.03); padding:8px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <div style="font-size:0.75rem; color:var(--text-secondary);"><i class="far fa-calendar-alt"></i> ETA:</div>
                                    <input type="date" name="eta_date" required class="form-input" style="padding:4px 8px; font-size:0.8rem; border-radius:6px; background:var(--bg-surface); min-height:0; border:1px solid rgba(255,255,255,0.1);" value="<?php echo !empty($o['estimated_delivery']) ? $o['estimated_delivery'] : ''; ?>">
                                    <button type="submit" name="set_eta" class="btn-secondary" style="padding:4px 10px; font-size:0.75rem; border-radius:6px;">Set</button>
                                </form>
                                <form method="POST" style="display:inline-flex; gap:8px; align-items:center; background:rgba(171,71,188,0.05); padding:8px 12px; border-radius:8px; border:1px solid rgba(171,71,188,0.15);">
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <div style="font-size:0.75rem; color:#ab47bc;"><i class="fas fa-shipping-fast"></i> Tracking Link:</div>
                                    <input type="text" name="tracking_id" required placeholder="Enter tracking link" class="form-input" style="padding:4px 8px; font-size:0.8rem; border-radius:6px; background:var(--bg-surface); min-height:0; border:1px solid rgba(171,71,188,0.2); width:160px; color:var(--text-primary);" value="<?php echo htmlspecialchars($o['tracking_id'] ?? ''); ?>">
                                    <button type="submit" name="set_tracking" class="btn-secondary" style="padding:4px 10px; font-size:0.75rem; border-radius:6px; border-color:rgba(171,71,188,0.3); color:#ab47bc;">Set</button>
                                </form>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!in_array($o['status'], ['pending', 'cancelled'])): ?>
                                <div style="margin-top:12px; text-align:right; display:flex; gap:8px; justify-content:flex-end;">
                                    <?php if(!empty($items[0]['listing_id'])): ?>
                                        <a href="../negotiate.php?listing_id=<?php echo $items[0]['listing_id']; ?>" class="btn-secondary" style="font-size:0.75rem; padding:6px 12px; opacity:0.9; text-decoration:none; border-color:rgba(59,130,246,0.2); color:#60a5fa;"><i class="far fa-comment-dots"></i> Chat with Buyer</a>
                                    <?php endif; ?>
                                    <a href="../dispute.php?order_id=<?php echo $o['id']; ?>" class="btn-secondary" style="font-size:0.75rem; padding:6px 12px; opacity:0.9; text-decoration:none;"><i class="fas fa-exclamation-triangle"></i> Report Issue</a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if($o['payment_status'] === 'confirmed' && $o['status'] !== 'shipped'): ?>
                            <div style="text-align:left; font-size:0.7rem; color:var(--text-muted); margin-top:8px; background:rgba(255,255,255,0.02); padding:6px; border-radius:6px;">
                                <strong>Ship to:</strong> <?php echo htmlspecialchars($o['shipping_name']); ?><br>
                                <?php echo htmlspecialchars($o['shipping_address']); ?>, <?php echo htmlspecialchars($o['shipping_city']); ?>, <?php echo htmlspecialchars($o['shipping_state']); ?> <?php echo htmlspecialchars($o['shipping_pincode']); ?><br>
                                Ph: <?php echo htmlspecialchars($o['shipping_phone']); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject Payment Modal -->
<div id="rejectModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); backdrop-filter:blur(5px); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card); border:1px solid rgba(229,57,53,0.3); border-radius:16px; width:100%; max-width:400px; padding:24px; box-shadow:0 10px 40px rgba(0,0,0,0.5);">
        <h3 style="margin-bottom:8px; font-size:1.2rem; display:flex; align-items:center; gap:8px;"><i class="fas fa-exclamation-triangle" style="color:#e53935;"></i> Reject Payment</h3>
        <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:20px; line-height:1.5;">Please provide a reason for rejecting the payment proof. The buyer will be notified and can raise a dispute.</p>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="order_id" id="rejectOrderId">
            
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Upload Bank Statement (Mandatory)</label>
                <input type="file" name="bank_statement" required accept="image/*,.pdf" style="width:100%; padding:10px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--text-primary); font-size:0.85rem;">
                <p style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Attach a screenshot of your bank statement as proof that no payment was received.</p>
            </div>

            <div style="margin-bottom:20px;">
                <textarea name="rejection_reason" required rows="3" placeholder="e.g., Amount mismatched, UTR invalid..." style="width:100%; padding:12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--text-primary); font-family:inherit; resize:vertical; font-size:0.9rem;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn-secondary" onclick="document.getElementById('rejectModal').style.display='none';" style="padding:10px 16px;">Cancel</button>
                <button type="submit" name="reject_payment_with_reason" class="btn-primary" style="padding:10px 16px; background:#e53935; border:none;">Reject Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(orderId) {
    document.getElementById('rejectOrderId').value = orderId;
    document.getElementById('rejectModal').style.display = 'flex';
}
</script>

<?php include 'footer.php'; ?>
