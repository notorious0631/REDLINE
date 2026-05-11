<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Accept both ?ids=X,Y (multi) and ?id=X (legacy single)
$idsParam = $_GET['ids'] ?? ($_GET['id'] ?? '');
if (isset($_GET['id']) && !isset($_GET['ids'])) {
    $idsParam = $_GET['id'];
}
$idsArray = array_filter(array_map('intval', explode(',', $idsParam)));

if (empty($idsArray)) {
    header('Location: order_view.php');
    exit;
}

$error = '';
$paymentSubmitted = false;

// Handle payment submission (for a single order at a time)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = intval($_POST['order_id'] ?? 0);
    $transactionId = trim($_POST['transaction_id'] ?? '');
    
    if (empty($transactionId)) {
        $error = "Please enter the Transaction ID (UTR).";
    } elseif (empty($_FILES['payment_proof']['tmp_name'])) {
        $error = "Please upload a payment screenshot.";
    } elseif ($orderId <= 0) {
        $error = "Invalid order.";
    } else {
        // Verify this order belongs to the user and is still pending
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND buyer_id = ? AND payment_status IN ('pending', 'failed')");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $error = "Order not found or already paid.";
        } else {
            // Check if deadline has passed
            if (!empty($order['payment_deadline']) && strtotime($order['payment_deadline']) < time()) {
                $error = "Payment deadline has expired for this order.";
            } else {
                $proofPath = null;
                if (!empty($_FILES['payment_proof']['tmp_name'])) {
                    $uploadDir = 'uploads/payments/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
                    $proofPath = $uploadDir . 'proof_' . $orderId . '_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['payment_proof']['tmp_name'], $proofPath);
                }
                
                try {
                    if ($proofPath) {
                        $stmt = $conn->prepare("UPDATE orders SET payment_status = 'verifying', transaction_id = ?, payment_proof = ? WHERE id = ?");
                        $stmt->execute([$transactionId, $proofPath, $orderId]);
                    } else {
                        $stmt = $conn->prepare("UPDATE orders SET payment_status = 'verifying', transaction_id = ? WHERE id = ?");
                        $stmt->execute([$transactionId, $orderId]);
                    }
                    
                    // Notify seller
                    $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'payment_submitted', ?, ?)")
                         ->execute([$order['seller_id'], "Payment details submitted for Order #$orderId by buyer. Please verify.", "seller_dashboard/orders.php"]);
                    
                    // Insert proof into chat if exists
                    if ($proofPath) {
                        try {
                            $itemStmt = $conn->prepare("SELECT listing_id FROM order_items WHERE order_id = ? LIMIT 1");
                            $itemStmt->execute([$orderId]);
                            $orderItem = $itemStmt->fetch(PDO::FETCH_ASSOC);
                            if ($orderItem) {
                                $convStmt = $conn->prepare("SELECT id FROM conversations WHERE listing_id = ? AND buyer_id = ? LIMIT 1");
                                $convStmt->execute([$orderItem['listing_id'], $userId]);
                                $conv = $convStmt->fetch(PDO::FETCH_ASSOC);
                                if ($conv) {
                                    $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, msg_type, image_path) VALUES (?, ?, ?, 'payment_proof', ?)")
                                         ->execute([$conv['id'], $userId, 'Payment proof uploaded — Rs.' . number_format($order['total'], 0), $proofPath]);
                                    $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conv['id']]);
                                }
                            }
                        } catch (PDOException $e) {}
                    }
                    
                    $paymentSubmitted = true;
                    
                } catch (PDOException $e) {
                    $error = "Failed to submit payment details.";
                }
            }
        }
    }
}

// Fetch all orders with seller details
$inStr = implode(',', array_fill(0, count($idsArray), '?'));
$params = $idsArray;
$params[] = $userId;

try {
    $stmt = $conn->prepare("
        SELECT o.*, u.name AS seller_name, u.upi_id, u.bank_details, u.avatar AS seller_avatar,
               TIMESTAMPDIFF(SECOND, NOW(), o.payment_deadline) AS db_rem_secs
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        WHERE o.id IN ($inStr) AND o.buyer_id = ?
        ORDER BY o.id ASC
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $orders = [];
}

if (empty($orders)) {
    header('Location: order_view.php');
    exit;
}

// Get items for each order
$orderItems = [];
foreach ($orders as $order) {
    $stmt = $conn->prepare("
        SELECT oi.*, l.title, l.image, c.name AS category_name
        FROM order_items oi
        JOIN listings l ON oi.listing_id = l.id
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order['id']]);
    $orderItems[$order['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate earliest deadline (for the global timer)
$earliestDeadline = null;
$pendingOrders = [];
$completedOrders = [];
$expiredOrders = [];

foreach ($orders as $o) {
    if (in_array($o['payment_status'], ['pending', 'failed'])) {
        $rem = isset($o['db_rem_secs']) ? (int)$o['db_rem_secs'] : 0;
        if (!empty($o['payment_deadline']) && $rem <= 0) {
            $expiredOrders[] = $o;
        } else {
            $pendingOrders[] = $o;
            if ($earliestDeadline === null || $rem < $earliestDeadline) {
                $earliestDeadline = $rem;
            }
        }
    } else {
        $completedOrders[] = $o;
    }
}

$remainingSeconds = $earliestDeadline !== null ? max(0, $earliestDeadline) : 0;
$grandTotal = array_sum(array_column($orders, 'total'));

include 'includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════ */
/* ─── PAYMENT WINDOW ─── */
/* ══════════════════════════════════════════════════ */

.pay-window {
    min-height: 100vh;
    padding: 80px 20px 60px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.pay-window-inner {
    max-width: 640px;
    width: 100%;
}

/* ── Timer Section ── */
.timer-section {
    text-align: center;
    margin-bottom: 32px;
}

.timer-ring-container {
    position: relative;
    width: 160px;
    height: 160px;
    margin: 0 auto 16px;
}

.timer-ring-svg {
    width: 160px;
    height: 160px;
    transform: rotate(-90deg);
}

.timer-ring-bg {
    fill: none;
    stroke: rgba(255,255,255,0.05);
    stroke-width: 6;
}

.timer-ring-progress {
    fill: none;
    stroke: #4caf50;
    stroke-width: 6;
    stroke-linecap: round;
    stroke-dasharray: 439.82;
    stroke-dashoffset: 0;
    transition: stroke-dashoffset 1s linear, stroke 0.5s ease;
}

.timer-ring-progress.warning { stroke: #ffb74d; }
.timer-ring-progress.critical { stroke: #ef5350; }

.timer-display {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.timer-minutes {
    font-size: 2.5rem;
    font-weight: 900;
    letter-spacing: -1px;
    color: var(--text-primary);
    line-height: 1;
    font-family: 'Outfit', monospace;
}

.timer-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--text-muted);
    margin-top: 4px;
    font-weight: 700;
}

.timer-section h2 {
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 4px;
}

.timer-subtitle {
    font-size: 0.82rem;
    color: var(--text-muted);
}

.timer-section.warning .timer-minutes { color: #ffb74d; }
.timer-section.critical .timer-minutes { color: #ef5350; }
.timer-section.critical .timer-ring-container { animation: timerPulse 1s ease-in-out infinite; }

@keyframes timerPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.04); }
}

/* ── Steps Instructions ── */
.pay-steps {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 28px;
    padding: 16px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 14px;
}

.pay-step {
    text-align: center;
    padding: 10px 4px;
}

.pay-step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 800;
    color: var(--text-muted);
    margin: 0 auto 8px;
}

.pay-step.done .pay-step-num {
    background: rgba(76,175,80,0.15);
    border-color: rgba(76,175,80,0.3);
    color: #81c784;
}

.pay-step-text {
    font-size: 0.68rem;
    color: var(--text-muted);
    line-height: 1.3;
    font-weight: 600;
}

/* ── Order Progress ── */
.order-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
    font-size: 0.82rem;
    color: var(--text-muted);
}

.order-progress-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    border: 2px solid rgba(255,255,255,0.15);
    transition: all 0.3s;
}

.order-progress-dot.active {
    background: var(--accent-red);
    border-color: var(--accent-red);
    box-shadow: 0 0 12px rgba(229,57,53,0.4);
}

.order-progress-dot.done {
    background: #4caf50;
    border-color: #4caf50;
}

/* ── Order Payment Card ── */
.pay-card {
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.4);
    transition: all 0.3s;
}

.pay-card.submitted {
    border-color: rgba(76,175,80,0.3);
    opacity: 0.7;
}

.pay-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: rgba(255,255,255,0.02);
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.pay-card-seller {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pay-seller-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 0.85rem;
    overflow: hidden;
}

.pay-seller-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pay-seller-name {
    font-weight: 700;
    font-size: 0.9rem;
}

.pay-seller-order {
    font-size: 0.72rem;
    color: var(--text-muted);
}

.pay-amount-badge {
    font-size: 1.3rem;
    font-weight: 900;
    color: var(--accent-red);
}

/* ── Items Preview ── */
.pay-items-preview {
    padding: 12px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.pay-item-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 6px 6px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    font-size: 0.78rem;
}

.pay-item-chip img {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    object-fit: cover;
}

.pay-item-chip-noimg {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    background: rgba(255,255,255,0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6rem;
    color: var(--text-muted);
}

/* ── Seller Payment Details ── */
.pay-details {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.pay-detail-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 10px;
}

.upi-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: rgba(229,57,53,0.06);
    border: 1px solid rgba(229,57,53,0.15);
    border-radius: 12px;
    gap: 12px;
}

.upi-id-text {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--accent-red);
    word-break: break-all;
    font-family: 'Outfit', monospace;
}

.copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    background: rgba(255,255,255,0.04);
    color: var(--text-secondary);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
    white-space: nowrap;
}

.copy-btn:hover { border-color: rgba(255,255,255,0.25); color: #fff; background: rgba(255,255,255,0.08); }
.copy-btn.copied { border-color: rgba(16,185,129,0.4); color: #10b981; background: rgba(16,185,129,0.08); }

.bank-details-box {
    margin-top: 12px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px;
    font-size: 0.82rem;
    color: var(--text-secondary);
    white-space: pre-wrap;
    line-height: 1.6;
}

.bank-details-title {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 6px;
}

/* ── Payment Form ── */
.pay-form {
    padding: 20px;
}

.pay-input-group {
    margin-bottom: 16px;
}

.pay-input-label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-secondary);
    font-size: 0.82rem;
    font-weight: 600;
}

.pay-input-label .required { color: var(--accent-red); }

.pay-input {
    width: 100%;
    padding: 14px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    color: var(--text-primary);
    font-size: 0.95rem;
    font-family: inherit;
    transition: all 0.2s;
    box-sizing: border-box;
}

.pay-input:focus {
    outline: none;
    border-color: var(--accent-red);
    box-shadow: 0 0 0 3px rgba(229,57,53,0.1);
}

.pay-input::placeholder { color: rgba(255,255,255,0.2); }

.upload-zone {
    position: relative;
    border: 2px dashed rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s;
    overflow: hidden;
}

.upload-zone:hover {
    border-color: rgba(229,57,53,0.3);
    background: rgba(229,57,53,0.03);
}

.upload-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.upload-zone-icon {
    font-size: 1.5rem;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.upload-zone-text {
    font-size: 0.82rem;
    color: var(--text-muted);
}

.upload-zone-text span {
    color: var(--accent-red);
    font-weight: 600;
}

.btn-submit-payment {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, var(--accent-red), #c62828);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.25s;
    box-shadow: 0 6px 24px rgba(229,57,53,0.3);
    margin-top: 8px;
}

.btn-submit-payment:hover {
    filter: brightness(1.12);
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(229,57,53,0.4);
}

.btn-submit-payment:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-cancel-order {
    width: 100%;
    padding: 12px;
    background: transparent;
    color: var(--text-muted);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    margin-top: 12px;
}

.btn-cancel-order:hover {
    background: rgba(229,57,53,0.05);
    border-color: rgba(229,57,53,0.2);
    color: #ef5350;
}

/* ── Submitted State ── */
.pay-card-submitted {
    padding: 30px 20px;
    text-align: center;
}

.submitted-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(76,175,80,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    font-size: 1.5rem;
    color: #81c784;
}

/* ── Expired Overlay ── */
.expired-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(8px);
}

.expired-overlay.active { display: flex; }

.expired-card {
    max-width: 420px;
    width: 90%;
    background: var(--bg-surface, #161616);
    border: 1px solid rgba(229,57,53,0.2);
    border-radius: 24px;
    padding: 48px 36px;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,0.6);
    animation: expiredBounce 0.5s ease;
}

@keyframes expiredBounce {
    0% { transform: scale(0.8); opacity: 0; }
    70% { transform: scale(1.02); }
    100% { transform: scale(1); opacity: 1; }
}

.expired-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(229,57,53,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 2.5rem;
    color: #ef5350;
}

.expired-card h2 {
    font-size: 1.4rem;
    margin-bottom: 10px;
    color: #ef5350;
}

.expired-card p {
    font-size: 0.88rem;
    color: var(--text-secondary);
    margin-bottom: 24px;
    line-height: 1.5;
}

.btn-back-browse {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 32px;
    background: var(--accent-red);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
    transition: all 0.25s;
}

.btn-back-browse:hover { filter: brightness(1.15); transform: translateY(-2px); }

/* ── All Paid ── */
.all-paid-section {
    text-align: center;
    padding: 40px 20px;
    background: rgba(76,175,80,0.05);
    border: 1px solid rgba(76,175,80,0.15);
    border-radius: 20px;
    margin-top: 20px;
}

.all-paid-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(76,175,80,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 2rem;
    color: #81c784;
}

/* ── Responsive ── */
@media (max-width: 640px) {
    .pay-window { padding: 70px 12px 40px; }
    .pay-steps { grid-template-columns: repeat(2, 1fr); }
    .timer-ring-container { width: 130px; height: 130px; }
    .timer-ring-svg { width: 130px; height: 130px; }
    .timer-minutes { font-size: 2rem; }
    .upi-display { flex-direction: column; align-items: stretch; }
    .pay-amount-badge { font-size: 1.1rem; }
}
</style>

<div class="pay-window">
<div class="pay-window-inner">

<?php if (!empty($error)): ?>
    <div style="background:rgba(229,57,53,0.08); border:1px solid rgba(229,57,53,0.2); color:#e57373; padding:14px 20px; border-radius:12px; margin-bottom:20px; font-size:0.88rem; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (!empty($pendingOrders)): ?>
<!-- ═══ TIMER ═══ -->
<div class="timer-section" id="timerSection" data-aos="fade-down">
    <div class="timer-ring-container">
        <svg class="timer-ring-svg" viewBox="0 0 150 150">
            <circle class="timer-ring-bg" cx="75" cy="75" r="70" />
            <circle class="timer-ring-progress" id="timerRing" cx="75" cy="75" r="70" />
        </svg>
        <div class="timer-display">
            <div class="timer-minutes" id="timerText">--:--</div>
            <div class="timer-label">Remaining</div>
        </div>
    </div>
    <h2>Complete Payment</h2>
    <div class="timer-subtitle">Pay within the time limit to confirm your order<?php echo count($pendingOrders) > 1 ? 's' : ''; ?></div>
</div>

<!-- ═══ STEPS ═══ -->
<div class="pay-steps" data-aos="fade-up">
    <div class="pay-step">
        <div class="pay-step-num">1</div>
        <div class="pay-step-text">Copy seller's UPI ID</div>
    </div>
    <div class="pay-step">
        <div class="pay-step-num">2</div>
        <div class="pay-step-text">Open your UPI app</div>
    </div>
    <div class="pay-step">
        <div class="pay-step-num">3</div>
        <div class="pay-step-text">Send exact amount</div>
    </div>
    <div class="pay-step">
        <div class="pay-step-num">4</div>
        <div class="pay-step-text">Enter UTR below</div>
    </div>
</div>

<!-- ═══ ORDER PROGRESS (multi-order) ═══ -->
<?php if (count($pendingOrders) > 1): ?>
<div class="order-progress" data-aos="fade-up">
    <span>Paying</span>
    <?php foreach ($pendingOrders as $idx => $o): ?>
        <div class="order-progress-dot <?php echo $idx === 0 ? 'active' : ''; ?>" data-order-id="<?php echo $o['id']; ?>"></div>
    <?php endforeach; ?>
    <span style="font-weight:700;"><span id="currentOrderNum">1</span> of <?php echo count($pendingOrders); ?></span>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ PAYMENT CARDS ═══ -->
<?php foreach ($orders as $idx => $order): 
    $items = $orderItems[$order['id']] ?? [];
    $isPending = in_array($order['payment_status'], ['pending', 'failed']);
    $isVerifying = $order['payment_status'] === 'verifying';
    $isExpired = $isPending && !empty($order['payment_deadline']) && (isset($order['db_rem_secs']) ? (int)$order['db_rem_secs'] <= 0 : false);
    $isSubmitted = ($paymentSubmitted && intval($_POST['order_id'] ?? 0) === $order['id']) || $isVerifying;
?>
<div class="pay-card <?php echo $isSubmitted ? 'submitted' : ''; ?>" data-aos="fade-up" data-order-id="<?php echo $order['id']; ?>" id="payCard-<?php echo $order['id']; ?>">
    
    <!-- Header -->
    <div class="pay-card-header">
        <div class="pay-card-seller">
            <div class="pay-seller-avatar">
                <?php if (!empty($order['seller_avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($order['seller_avatar']); ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-store"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="pay-seller-name"><?php echo htmlspecialchars($order['seller_name']); ?></div>
                <div class="pay-seller-order">Order #<?php echo $order['id']; ?></div>
            </div>
        </div>
        <div class="pay-amount-badge">Rs.<?php echo number_format($order['total'], 0); ?></div>
    </div>

    <!-- Items Preview -->
    <div class="pay-items-preview">
        <?php foreach ($items as $item): ?>
        <div class="pay-item-chip">
            <?php if (!empty($item['image'])): ?>
                <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="">
            <?php else: ?>
                <div class="pay-item-chip-noimg"><i class="fas fa-car"></i></div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($item['title']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isSubmitted): ?>
    <!-- ── Already Submitted ── -->
    <div class="pay-card-submitted">
        <div class="submitted-icon"><i class="fas fa-check"></i></div>
        <div style="font-weight:700; font-size:1rem; margin-bottom:6px;">Payment Submitted</div>
        <div style="font-size:0.82rem; color:var(--text-muted);">
            UTR: <strong><?php echo htmlspecialchars($order['transaction_id'] ?? $_POST['transaction_id'] ?? ''); ?></strong><br>
            The seller will verify and confirm your payment.
        </div>
    </div>

    <?php elseif ($isExpired): ?>
    <!-- ── Expired ── -->
    <div class="pay-card-submitted" style="background:rgba(229,57,53,0.03);">
        <div class="submitted-icon" style="background:rgba(229,57,53,0.12); color:#ef5350;"><i class="fas fa-clock"></i></div>
        <div style="font-weight:700; font-size:1rem; margin-bottom:6px; color:#ef5350;">Payment Expired</div>
        <div style="font-size:0.82rem; color:var(--text-muted);">This order has been cancelled due to payment timeout.</div>
    </div>

    <?php else: ?>
    
    <?php if ($order['payment_status'] === 'failed' && !empty($order['payment_rejection_reason'])): ?>
    <div style="background:rgba(229,57,53,0.06); border:1px solid rgba(229,57,53,0.2); border-radius:12px; padding:16px; margin:20px 20px 0;">
        <div style="font-weight:700; color:#ef5350; font-size:0.95rem; margin-bottom:6px;"><i class="fas fa-ban"></i> Payment Proof Rejected</div>
        <div style="color:var(--text-secondary); font-size:0.85rem; line-height:1.4;">
            <?php echo nl2br(htmlspecialchars($order['payment_rejection_reason'])); ?>
        </div>
        <div style="margin-top:8px; font-size:0.8rem; font-weight:600; color:var(--text-primary);">Please verify and upload the correct screenshot below.</div>
    </div>
    <?php endif; ?>

    <!-- ── Seller Payment Details ── -->
    <div class="pay-details">
        <div class="pay-detail-label"><i class="fas fa-wallet" style="margin-right:4px;"></i> Pay to <?php echo htmlspecialchars($order['seller_name']); ?></div>
        
        <?php if (!empty($order['upi_id'])): ?>
        <div class="upi-display">
            <div class="upi-id-text"><?php echo htmlspecialchars($order['upi_id']); ?></div>
            <button class="copy-btn" onclick="copyText(this, '<?php echo htmlspecialchars($order['upi_id'], ENT_QUOTES); ?>')">
                <i class="far fa-copy"></i> Copy UPI
            </button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($order['bank_details'])): ?>
        <div class="bank-details-box">
            <div class="bank-details-title">Bank Transfer Details</div>
            <?php echo nl2br(htmlspecialchars($order['bank_details'])); ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Payment Form ── -->
    <form method="POST" enctype="multipart/form-data" class="pay-form" id="payForm-<?php echo $order['id']; ?>">
        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
        
        <div class="pay-input-group">
            <label class="pay-input-label">Transaction ID / UTR Number <span class="required">*</span></label>
            <input type="text" name="transaction_id" class="pay-input" placeholder="e.g. 412306789012" required>
        </div>

        <div class="pay-input-group">
            <label class="pay-input-label">Payment Screenshot <span class="required">*</span></label>
            <div class="upload-zone" id="uploadZone-<?php echo $order['id']; ?>">
                <div class="upload-zone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <div class="upload-zone-text" id="uploadText-<?php echo $order['id']; ?>"><span>Click to upload</span> receipt screenshot</div>
                <input type="file" name="payment_proof" accept="image/*" required onchange="updateUploadText(this, <?php echo $order['id']; ?>)">
            </div>
        </div>

        <button type="submit" class="btn-submit-payment" id="submitBtn-<?php echo $order['id']; ?>">
            <i class="fas fa-check-circle"></i> Submit Payment — Rs.<?php echo number_format($order['total'], 0); ?>
        </button>
        <button type="button" class="btn-cancel-order" onclick="cancelOrder(<?php echo $order['id']; ?>)">
            <i class="fas fa-times"></i> Cancel Order
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- ═══ ALL PAID CHECK ═══ -->
<?php 
$allPaid = empty($pendingOrders) && !empty($completedOrders);
if ($allPaid): 
?>
<div class="all-paid-section" data-aos="zoom-in">
    <div class="all-paid-icon"><i class="fas fa-check-double"></i></div>
    <h3 style="font-size:1.2rem; margin-bottom:8px;">All Payments Submitted!</h3>
    <p style="font-size:0.88rem; color:var(--text-secondary); margin-bottom:20px;">
        Your sellers will verify the payments and dispatch your items.
    </p>
    <a href="order_view.php" class="btn-back-browse" style="background:#4caf50;">
        <i class="fas fa-box"></i> View My Orders
    </a>
</div>
<?php endif; ?>

<!-- ═══ BOTTOM LINKS ═══ -->
<div style="text-align:center; margin-top:24px; padding-bottom:20px;">
    <a href="order_view.php" style="color:var(--text-muted); font-size:0.82rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
        <i class="fas fa-arrow-left"></i> Back to My Orders
    </a>
</div>

</div>
</div>

<!-- ═══ EXPIRED OVERLAY ═══ -->
<div class="expired-overlay" id="expiredOverlay">
    <div class="expired-card">
        <div class="expired-icon"><i class="fas fa-hourglass-end"></i></div>
        <h2>Time's Up!</h2>
        <p>The 20-minute payment window has expired. Your order has been cancelled and the items have been released back to the seller.</p>
        <a href="browse.php" class="btn-back-browse">
            <i class="fas fa-search"></i> Browse Listings
        </a>
        <div style="margin-top:14px;">
            <a href="order_view.php" style="color:var(--text-muted); font-size:0.82rem; text-decoration:none;">View My Orders</a>
        </div>
    </div>
</div>

<script>
(function() {
    const TOTAL_SECONDS = 20 * 60; // 20 minutes
    let remainingSeconds = <?php echo $remainingSeconds; ?>;
    const orderIds = '<?php echo implode(',', $idsArray); ?>';
    const hasPending = <?php echo !empty($pendingOrders) ? 'true' : 'false'; ?>;
    
    if (!hasPending) return;

    const timerText = document.getElementById('timerText');
    const timerRing = document.getElementById('timerRing');
    const timerSection = document.getElementById('timerSection');
    const circumference = 2 * Math.PI * 70; // r=70
    
    if (timerRing) {
        timerRing.style.strokeDasharray = circumference;
    }

    function updateTimer() {
        if (remainingSeconds <= 0) {
            timerExpired();
            return;
        }

        const min = Math.floor(remainingSeconds / 60);
        const sec = remainingSeconds % 60;
        if (timerText) {
            timerText.textContent = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
        }

        // Update ring progress
        if (timerRing) {
            const progress = remainingSeconds / TOTAL_SECONDS;
            const offset = circumference * (1 - progress);
            timerRing.style.strokeDashoffset = offset;

            // Color transitions
            timerRing.classList.remove('warning', 'critical');
            if (timerSection) timerSection.classList.remove('warning', 'critical');
            
            if (remainingSeconds <= 120) {
                timerRing.classList.add('critical');
                if (timerSection) timerSection.classList.add('critical');
            } else if (remainingSeconds <= 300) {
                timerRing.classList.add('warning');
                if (timerSection) timerSection.classList.add('warning');
            }
        }

        remainingSeconds--;
        setTimeout(updateTimer, 1000);
    }

    function timerExpired() {
        if (timerText) timerText.textContent = '00:00';
        
        // Call the API to cancel expired orders
        fetch('api/payment_timeout.php?action=check&ids=' + orderIds)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.cancelled && data.cancelled.length > 0) {
                    document.getElementById('expiredOverlay').classList.add('active');
                }
            })
            .catch(() => {
                document.getElementById('expiredOverlay').classList.add('active');
            });
    }

    // Check for already-expired orders on load
    fetch('api/payment_timeout.php?action=check&ids=' + orderIds)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.cancelled && data.cancelled.length > 0) {
                // Some orders just got cancelled — reload to show updated state
                if (data.cancelled.length >= <?php echo count($pendingOrders); ?>) {
                    document.getElementById('expiredOverlay').classList.add('active');
                } else {
                    location.reload();
                }
            }
        })
        .catch(() => {});

    updateTimer();

    // Utility functions
    window.copyText = function(btn, text) {
        navigator.clipboard?.writeText(text)
            .then(() => showCopied(btn))
            .catch(() => fallbackCopy(btn, text));
    };

    function fallbackCopy(btn, text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showCopied(btn);
    }

    function showCopied(btn) {
        btn.classList.add('copied');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<i class="far fa-copy"></i> Copy UPI';
        }, 2500);
    }

    window.updateUploadText = function(input, orderId) {
        const textEl = document.getElementById('uploadText-' + orderId);
        if (textEl && input.files && input.files[0]) {
            textEl.innerHTML = '<i class="fas fa-check" style="color:#81c784;"></i> ' + input.files[0].name;
        }
    };

    window.cancelOrder = function(orderId) {
        if (!confirm('Are you sure you want to cancel this order? This will release the items back to the marketplace.')) return;
        
        const btn = event.currentTarget;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';

        fetch('api/payment_timeout.php?action=cancel&ids=' + orderId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.href = 'order_view.php';
                } else {
                    alert('Error: ' + (data.error || 'Failed to cancel order'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-times"></i> Cancel Order';
                }
            })
            .catch(() => {
                alert('Connection error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-times"></i> Cancel Order';
            });
    };
})();
</script>

<?php include 'includes/footer.php'; ?>
