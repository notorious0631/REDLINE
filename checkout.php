<?php
session_start();
require_once 'config/db.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$negoId = intval($_GET['nego_id'] ?? ($_POST['nego_id'] ?? 0));
$sellerFilter = intval($_GET['seller_id'] ?? ($_POST['seller_id'] ?? 0));
$error = '';
$cartItems = [];
$total = 0;
$checkoutSellerName = '';

if ($negoId > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT l.*, c.name AS category_name, conv.offered_price, COALESCE(conv.offered_quantity, 1) as offered_quantity
            FROM conversations conv
            JOIN listings l ON conv.listing_id = l.id
            LEFT JOIN categories c ON l.category_id = c.id
            WHERE conv.id = ? AND conv.buyer_id = ? AND conv.status = 'accepted' AND l.status = 'active'
        ");
        $stmt->execute([$negoId, $_SESSION['user_id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            $item['price'] = $item['offered_price'];
            $item['cart_qty'] = $item['offered_quantity'];
            $cartItems[] = $item;
            $total += $item['price'] * $item['cart_qty'];
        }
    } catch (PDOException $e) {}
} else {
    // Normal Cart checkout
    if (empty($_SESSION['cart'])) {
        header('Location: cart.php');
        exit;
    }
    
    $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $sellerCondition = '';
    if ($sellerFilter > 0) {
        $sellerCondition = ' AND l.seller_id = ' . $sellerFilter;
        // Fetch seller name for display
        try {
            $snStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
            $snStmt->execute([$sellerFilter]);
            $checkoutSellerName = $snStmt->fetchColumn() ?: '';
        } catch (PDOException $e) {}
    }
    try {
        $stmt = $conn->query("
            SELECT l.*, c.name AS category_name, u.name AS seller_name, u.free_shipping_threshold
            FROM listings l
            LEFT JOIN categories c ON l.category_id = c.id
            LEFT JOIN users u ON l.seller_id = u.id
            WHERE l.id IN ($ids) AND l.status = 'active'" . $sellerCondition . "
        ");

        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cartItems as &$ci) {
            $ci['cart_qty'] = intval($_SESSION['cart'][$ci['id']] ?? 1);
            $maxStock = intval($ci['stock'] ?? 1);
            if ($ci['cart_qty'] > $maxStock) $ci['cart_qty'] = $maxStock;
            $ci['shipping_total'] = floatval($ci['shipping_fee'] ?? 0) * $ci['cart_qty'];
            $ci['subtotal'] = ($ci['price'] * $ci['cart_qty']) + $ci['shipping_total'];
            $total += $ci['subtotal'];

        }
        unset($ci);
    } catch (PDOException $e) {}
}

if (empty($cartItems)) {
    if ($negoId > 0) {
        // Negotiation closed, invalid, or something else
        $_SESSION['error'] = "Invalid or expired negotiation. Proceed via regular checkout if still available.";
        header('Location: browse.php');
        exit;
    }
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}

// Handle order placement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['shipping_name'] ?? '');
    $address = trim($_POST['shipping_address'] ?? '');
    $city = trim($_POST['shipping_city'] ?? '');
    $state = trim($_POST['shipping_state'] ?? '');
    $pincode = trim($_POST['shipping_pincode'] ?? '');
    $phone = trim($_POST['shipping_phone'] ?? '');

    if (empty($name) || empty($address) || empty($city) || empty($state) || empty($pincode) || empty($phone)) {
        $error = 'Please fill in all shipping details.';
    } else {
        try {
            $conn->beginTransaction();

            // Group items by seller_id
            $itemsBySeller = [];
            foreach ($cartItems as $item) {
                $sellerId = $item['seller_id'];
                $qty = intval($item['cart_qty'] ?? 1);
                if (!isset($itemsBySeller[$sellerId])) {
                    $itemsBySeller[$sellerId] = [
                        'base_total' => 0,
                        'shipping_total' => 0,
                        'free_shipping_threshold' => $item['free_shipping_threshold'],
                        'items' => []
                    ];
                }
                $itemsBySeller[$sellerId]['base_total'] += $item['price'] * $qty;
                $itemsBySeller[$sellerId]['shipping_total'] += floatval($item['shipping_fee'] ?? 0) * $qty;
                $item['order_qty'] = $qty;
                $itemsBySeller[$sellerId]['items'][] = $item;
            }

            // Apply threshold per seller
            foreach ($itemsBySeller as &$group) {
                if (isset($group['free_shipping_threshold']) && $group['free_shipping_threshold'] !== null && $group['base_total'] >= floatval($group['free_shipping_threshold'])) {
                    $group['shipping_total'] = 0;
                }
                $group['final_total'] = $group['base_total'] + $group['shipping_total'];
            }
            unset($group);


            $orderIds = [];

            // Insert an order for each seller
            $stmtOrder = $conn->prepare("
                INSERT INTO orders (buyer_id, seller_id, total, shipping_name, shipping_address, shipping_city, shipping_state, shipping_pincode, shipping_phone, payment_status, payment_method, payment_deadline)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'upi', DATE_ADD(NOW(), INTERVAL 20 MINUTE))
            ");
            
            $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, listing_id, price, shipping_fee, quantity) VALUES (?, ?, ?, ?, ?)");

            $stmtUpdateStock = $conn->prepare("UPDATE listings SET stock = GREATEST(stock - ?, 0), status = CASE WHEN stock <= ? THEN 'sold' ELSE 'active' END WHERE id = ?");
            $stmtInsertNotif = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'new_order', ?, ?)");

            foreach ($itemsBySeller as $sellerId => $sellerGroup) {
                // Execute main order insertion
                $stmtOrder->execute([
                    $_SESSION['user_id'],
                    $sellerId,
                    $sellerGroup['final_total'],
                    $name, $address, $city, $state, $pincode, $phone
                ]);

                
                $orderId = $conn->lastInsertId();
                $orderIds[] = $orderId;

                // Add sub-items and update stock
                foreach ($sellerGroup['items'] as $item) {
                    $qty = intval($item['order_qty'] ?? 1);
                    $stmtItem->execute([$orderId, $item['id'], $item['price'], floatval($item['shipping_fee'] ?? 0), $qty]);

                    $stmtUpdateStock->execute([$qty, $qty, $item['id']]);
                }
                
                $stmtInsertNotif->execute([$sellerId, "You have a new order: #$orderId.", "seller_dashboard/orders.php"]);
            }

            $conn->commit();

            if ($negoId === 0) {
                if ($sellerFilter > 0) {
                    // Only remove this seller's items from cart
                    foreach ($cartItems as $ci) {
                        unset($_SESSION['cart'][$ci['id']]);
                    }
                } else {
                    $_SESSION['cart'] = [];
                }
            }
            $idsParam = implode(',', $orderIds);
            header("Location: order_success.php?ids=$idsParam&new=1");
            exit;

        } catch (PDOException $e) {
            logError('checkout', 'Order placement failed', $e);
            $error = 'Something went wrong placing your order. Please try again.';
        }
    }
}

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/sell.css">
<style>
.checkout-page { padding: 90px 0 60px; min-height: 80vh; }
.checkout-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 32px; max-width: 1000px; margin: 0 auto; }
.checkout-items { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 24px; }
.checkout-item { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--border-color); align-items: center; }
.checkout-item:last-child { border-bottom: none; }
.checkout-item-img { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; background: var(--bg-card); }
.checkout-item-title { font-weight: 600; font-size: 0.9rem; }
.checkout-item-price { font-weight: 700; margin-left: auto; white-space: nowrap; }
.checkout-total-row { display: flex; justify-content: space-between; padding-top: 16px; margin-top: 8px; border-top: 1px solid var(--border-color); font-size: 1.1rem; font-weight: 800; }
@media (max-width: 768px) {
    .checkout-page { padding: 70px 16px 40px; }
    .checkout-grid { grid-template-columns: 1fr; }
}
</style>

<div class="checkout-page container-rl">
    <div class="section-header" data-aos="fade-up">
        <div>
            <div class="section-label">SECURE</div>
            <h2 class="section-title">CHECKOUT<?php if ($checkoutSellerName): ?> <span style="font-size:0.6em; color:var(--text-muted); font-weight:400;">— <?php echo htmlspecialchars($checkoutSellerName); ?></span><?php endif; ?></h2>
        </div>
        <?php if ($sellerFilter > 0): ?>
        <a href="cart.php" style="color:var(--text-muted); font-size:0.85rem; text-decoration:none; display:flex; align-items:center; gap:6px;"><i class="fas fa-arrow-left"></i> Back to Cart</a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="max-width: 1000px; margin: 0 auto 24px; background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e57373; padding: 14px 20px; border-radius: 8px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="checkout.php">
        <?php if ($negoId > 0): ?>
            <input type="hidden" name="nego_id" value="<?php echo $negoId; ?>">
        <?php endif; ?>
        <?php if ($sellerFilter > 0): ?>
            <input type="hidden" name="seller_id" value="<?php echo $sellerFilter; ?>">
        <?php endif; ?>
        <div class="checkout-grid" data-aos="fade-up">
            <!-- Shipping Form -->
            <div class="sell-form-container" style="box-shadow: none;">
                <div class="sell-form-header">
                    <h1 style="font-size: 1.2rem;">Shipping Details</h1>
                </div>
                <div class="sell-form-body" style="padding: 28px;">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="shipping_name" class="sell-form-control" placeholder="John Doe" required value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address <span class="required">*</span></label>
                        <textarea name="shipping_address" class="sell-form-control" rows="3" placeholder="House/Flat, Street, Locality" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">City <span class="required">*</span></label>
                                <input type="text" name="shipping_city" class="sell-form-control" placeholder="Mumbai" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">State <span class="required">*</span></label>
                                <input type="text" name="shipping_state" class="sell-form-control" placeholder="Maharashtra" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Pincode <span class="required">*</span></label>
                                <input type="text" name="shipping_pincode" class="sell-form-control" placeholder="400001" maxlength="6" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Phone <span class="required">*</span></label>
                                <input type="tel" name="shipping_phone" class="sell-form-control" placeholder="+91 9876543210" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select class="sell-form-control">
                            <option selected>Direct UPI / Bank Transfer (P2P)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="checkout-items">
                <h3 style="margin: 0 0 16px; font-size: 1rem; font-weight: 700;">Order Summary (<?php
                    $totalQty = 0;
                    foreach ($cartItems as $ci) $totalQty += intval($ci['cart_qty'] ?? 1);
                    echo $totalQty;
                ?> item<?php echo $totalQty !== 1 ? 's' : ''; ?>)</h3>
                <?php foreach ($cartItems as $item): ?>
                <div class="checkout-item">
                    <?php if (!empty($item['image'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image']); ?>" class="checkout-item-img" alt="">
                    <?php else: ?>
                        <div class="checkout-item-img" style="display:flex;align-items:center;justify-content:center;color:#333;"><i class="fas fa-car"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="checkout-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?> <?php $q = intval($item['cart_qty'] ?? 1); if ($q > 1) echo '× ' . $q; ?></div>
                    </div>
                    <div class="checkout-item-price">
                        <div>Rs.<?php echo number_format($item['price'] * intval($item['cart_qty'] ?? 1), 0); ?></div>
                        <?php 
                            // Check if this seller qualifies for free shipping
                            $sid = $item['seller_id'];
                            $sellerItemsTotal = 0;
                            $sellerThreshold = $item['free_shipping_threshold'] ?? null;
                            foreach($cartItems as $ci) if($ci['seller_id'] == $sid) $sellerItemsTotal += $ci['price'] * intval($ci['cart_qty'] ?? 1);
                            
                            $isWaived = (isset($sellerThreshold) && $sellerThreshold !== null && $sellerItemsTotal >= floatval($sellerThreshold));
                            
                            if(floatval($item['shipping_fee'] ?? 0) > 0): 
                        ?>
                            <div style="font-size:0.7rem; color:var(--text-muted); font-weight:400; text-align:right;">
                                <?php if($isWaived): ?>
                                    <del>+ Rs.<?php echo number_format(floatval($item['shipping_fee']) * intval($item['cart_qty'] ?? 1), 0); ?></del> Free
                                <?php else: ?>
                                    + Rs.<?php echo number_format(floatval($item['shipping_fee']) * intval($item['cart_qty'] ?? 1), 0); ?> ship
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>


                </div>
                <?php endforeach; ?>
                <div class="checkout-total-row">
                    <span>Total</span>
                    <span>Rs.<?php echo number_format($total, 0); ?></span>
                </div>
                <button type="submit" class="btn-red" style="width: 100%; margin-top: 24px; display: flex; justify-content: center;">
                    Place Order <i class="fas fa-check"></i>
                </button>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
