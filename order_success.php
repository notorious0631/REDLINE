<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$idsParam = $_GET['ids'] ?? '';
$idsArray = explode(',', $idsParam);
$validIds = array_filter(array_map('intval', $idsArray));
$isNew = isset($_GET['new']);

if (empty($validIds)) {
    header('Location: index.php');
    exit;
}

try {
    $inStr = implode(',', array_fill(0, count($validIds), '?'));
    $params = $validIds;
    $params[] = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id IN ($inStr) AND buyer_id = ?");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        header('Location: index.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT oi.*, l.title, l.image, c.name AS category_name
        FROM order_items oi
        JOIN listings l ON oi.listing_id = l.id
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE oi.order_id IN ($inStr)
    ");
    $stmt->execute($validIds);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $grandTotal = 0;
    foreach ($orders as $o) {
        $grandTotal += $o['total'];
    }
    
    $orderInfo = $orders[0];
} catch (PDOException $e) {
    header('Location: index.php');
    exit;
}

$payUrl = "pay_order.php?ids=" . implode(',', $validIds);

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/auth.css">
<style>
.success-page { min-height: 80vh; padding: 90px 0 60px; display: flex; align-items: center; justify-content: center; }
.success-card { max-width: 560px; width: 100%; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 48px 40px; text-align: center; position: relative; overflow: hidden; }

/* Animated checkmark */
.success-checkmark { width: 100px; height: 100px; margin: 0 auto 24px; position: relative; }
.checkmark-circle { width: 100px; height: 100px; border-radius: 50%; display: block; stroke-width: 3; stroke: #4caf50; stroke-miterlimit: 10; fill: none; animation: checkmark-stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards; }
.checkmark-check { transform-origin: 50% 50%; stroke-dasharray: 48; stroke-dashoffset: 48; animation: checkmark-stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards; }
.checkmark-svg { width: 100px; height: 100px; border-radius: 50%; display: block; stroke-width: 3; stroke: #4caf50; stroke-miterlimit: 10; filter: drop-shadow(0 0 20px rgba(76,175,80,0.3)); }

@keyframes checkmark-stroke {
    100% { stroke-dashoffset: 0; }
}

.success-card h1 { font-family: var(--font-display); font-size: 1.8rem; margin-bottom: 8px; animation: fadeInUp 0.5s 0.3s both; }
.success-card .order-id { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px; animation: fadeInUp 0.5s 0.5s both; }

.success-items { text-align: left; border-top: 1px solid var(--border-color); padding-top: 20px; margin-top: 20px; animation: fadeInUp 0.5s 0.6s both; }
.success-item { display: flex; gap: 12px; padding: 10px 0; align-items: center; }
.success-item img { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; }
.success-item-title { font-weight: 600; font-size: 0.85rem; }
.success-item-price { margin-left: auto; font-weight: 700; }
.success-total { display: flex; justify-content: space-between; padding-top: 16px; border-top: 1px solid var(--border-color); margin-top: 12px; font-weight: 800; font-size: 1.1rem; }

/* Redirect countdown */
.redirect-bar { animation: fadeInUp 0.5s 0.8s both; margin-top: 28px; }
.redirect-text { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 12px; }
.redirect-text span { color: var(--accent-red); font-weight: 700; font-size: 1rem; }
.progress-track { width: 100%; height: 4px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; }
.progress-fill { height: 100%; background: linear-gradient(90deg, var(--accent-red), #ff6f61); border-radius: 4px; animation: progressShrink 4s linear forwards; }

@keyframes progressShrink {
    from { width: 100%; }
    to { width: 0%; }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

.success-actions { display: flex; gap: 12px; margin-top: 20px; justify-content: center; animation: fadeInUp 0.5s 0.9s both; }
.btn-pay-now { 
    display: inline-flex; align-items: center; gap: 8px; 
    padding: 14px 32px; background: var(--accent-red); color: #fff; 
    border: none; border-radius: 12px; font-size: 1rem; font-weight: 700; 
    cursor: pointer; text-decoration: none; font-family: inherit; 
    transition: all 0.25s; box-shadow: 0 4px 20px rgba(229,57,53,0.3);
}
.btn-pay-now:hover { filter: brightness(1.15); transform: translateY(-2px); box-shadow: 0 6px 28px rgba(229,57,53,0.4); }

@media (max-width: 480px) { .success-card { padding: 28px 20px; } }
</style>

<div class="success-page">
    <div class="success-card" data-aos="zoom-in">
        <!-- Animated Checkmark -->
        <div class="success-checkmark">
            <svg class="checkmark-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                <circle class="checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                <path class="checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8" stroke="#4caf50" stroke-width="3"/>
            </svg>
        </div>

        <h1>Order Placed!</h1>
        <div style="font-size:0.9rem; color:var(--text-secondary); margin-bottom:16px; padding: 0 10px;">
            Your items have been reserved. Complete payment within <strong style="color:var(--accent-red);">20 minutes</strong> to confirm your purchase.
        </div>
        <p class="order-id">Order(s) #<?php echo implode(', #', array_column($orders, 'id')); ?> • <?php echo date('M d, Y', strtotime($orderInfo['created_at'])); ?></p>

        <div class="success-items">
            <?php foreach ($items as $item): ?>
            <div class="success-item">
                <?php if (!empty($item['image'])): ?>
                    <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="">
                <?php else: ?>
                    <div style="width:48px;height:48px;border-radius:6px;background:var(--bg-card);display:flex;align-items:center;justify-content:center;color:#333;"><i class="fas fa-car"></i></div>
                <?php endif; ?>
                <div>
                    <div class="success-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></div>
                </div>
                <div class="success-item-price">Rs.<?php echo number_format($item['price'], 0); ?></div>
            </div>
            <?php endforeach; ?>
            <div class="success-total">
                <span>Total Amount</span>
                <span>Rs.<?php echo number_format($grandTotal, 0); ?></span>
            </div>
        </div>

        <?php if ($isNew): ?>
        <!-- Auto-redirect countdown -->
        <div class="redirect-bar">
            <div class="redirect-text">Redirecting to payment in <span id="countdown">4</span>s...</div>
            <div class="progress-track">
                <div class="progress-fill"></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="success-actions" style="flex-direction: column; gap: 12px;">
            <a href="<?php echo $payUrl; ?>" class="btn-pay-now" id="payNowBtn">
                <i class="fas fa-wallet"></i> Pay Now — Rs.<?php echo number_format($grandTotal, 0); ?>
            </a>
            <a href="browse.php" style="color:var(--text-muted); font-size:0.82rem; text-decoration:none;">Continue Shopping</a>
        </div>
    </div>
</div>

<?php if ($isNew): ?>
<script>
(function() {
    let sec = 4;
    const el = document.getElementById('countdown');
    const payUrl = '<?php echo $payUrl; ?>';
    
    const timer = setInterval(() => {
        sec--;
        if (el) el.textContent = sec;
        if (sec <= 0) {
            clearInterval(timer);
            window.location.href = payUrl;
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
