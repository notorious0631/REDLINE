<?php
session_start();
require_once 'config/db.php';

// Migrate old cart format (array of IDs) to new format (id => qty)
if (isset($_SESSION['cart']) && is_array($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $keys = array_keys($_SESSION['cart']);
    $isOldFormat = ($keys === range(0, count($_SESSION['cart']) - 1));
    if ($isOldFormat) {
        $newCart = [];
        foreach ($_SESSION['cart'] as $listingId) {
            if (is_numeric($listingId)) $newCart[intval($listingId)] = 1;
        }
        $_SESSION['cart'] = $newCart;
    }
}

// Handle quantity update
if (isset($_GET['update_qty']) && isset($_GET['qty'])) {
    $updateId = intval($_GET['update_qty']);
    $newQty = max(1, intval($_GET['qty']));
    if (isset($_SESSION['cart'][$updateId])) {
        $_SESSION['cart'][$updateId] = $newQty;
    }
    header('Location: cart.php');
    exit;
}

// Handle remove
if (isset($_GET['remove'])) {
    $removeId = intval($_GET['remove']);
    if (isset($_SESSION['cart'])) {
        unset($_SESSION['cart'][$removeId]);
    }
    header('Location: cart.php');
    exit;
}

// Handle clear
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}

$cartItems = [];
$total = 0;

if (!empty($_SESSION['cart'])) {
    $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    try {
        $stmt = $conn->query("
            SELECT l.*, c.name AS category_name, 
                   u.name AS seller_name, u.id AS seller_uid, 
                   u.avatar AS seller_avatar, u.is_verified AS seller_verified,
                   u.free_shipping_threshold,
                   u.shipping_type, u.standard_shipping_fee
            FROM listings l
            LEFT JOIN categories c ON l.category_id = c.id
            LEFT JOIN users u ON l.seller_id = u.id
            WHERE l.id IN ($ids) AND l.status = 'active'
        ");
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        
        // Attach quantity to each item and compute base subtotal
        foreach ($cartItems as &$item) {
            $item['cart_qty'] = intval($_SESSION['cart'][$item['id']] ?? 1);
            // Cap quantity at available stock
            $maxStock = intval($item['stock'] ?? 1);
            if ($item['cart_qty'] > $maxStock) {
                $item['cart_qty'] = $maxStock;
                $_SESSION['cart'][$item['id']] = $maxStock;
            }
            $item['base_subtotal'] = $item['price'] * $item['cart_qty'];
            // Per-item shipping (used only when seller's shipping_type is 'per_item')
            $item['item_shipping_total'] = floatval($item['shipping_fee'] ?? 0) * $item['cart_qty'];
        }


        unset($item);
        
        // Remove any sold/inactive items from session cart
        $activeIds = array_column($cartItems, 'id');
        foreach (array_keys($_SESSION['cart']) as $cartId) {
            if (!in_array($cartId, $activeIds)) {
                unset($_SESSION['cart'][$cartId]);
            }
        }
    } catch (PDOException $e) {}
}

// Group items by seller
$itemsBySeller = [];
foreach ($cartItems as $item) {
    $sid = $item['seller_id'];
    if (!isset($itemsBySeller[$sid])) {
        $itemsBySeller[$sid] = [
            'seller_name' => $item['seller_name'] ?? 'Seller',
            'seller_uid' => $item['seller_uid'] ?? $sid,
            'seller_avatar' => $item['seller_avatar'] ?? '',
            'seller_verified' => $item['seller_verified'] ?? 0,
            'free_shipping_threshold' => $item['free_shipping_threshold'],
            'shipping_type' => $item['shipping_type'] ?? 'per_item',
            'standard_shipping_fee' => floatval($item['standard_shipping_fee'] ?? 0),
            'items' => [],
            'base_subtotal' => 0,
            'shipping_total' => 0,
            'qty' => 0,
        ];
    }
    $itemsBySeller[$sid]['items'][] = $item;
    $itemsBySeller[$sid]['base_subtotal'] += $item['base_subtotal'];
    // Only accumulate per-item shipping if that's the seller's type
    if ($itemsBySeller[$sid]['shipping_type'] === 'per_item') {
        $itemsBySeller[$sid]['shipping_total'] += $item['item_shipping_total'];
    }
    $itemsBySeller[$sid]['qty'] += $item['cart_qty'];
}

// Fetch tiered shipping fees for sellers using tiered shipping
$tieredFees = [];
$tieredSellers = array_keys(array_filter($itemsBySeller, fn($g) => $g['shipping_type'] === 'tiered'));
if (!empty($tieredSellers)) {
    $tsIds = implode(',', $tieredSellers);
    try {
        $tStmt = $conn->query("SELECT * FROM shipping_tiers WHERE seller_id IN ($tsIds) ORDER BY min_items ASC");
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tieredFees[$t['seller_id']][] = $t;
        }
    } catch (PDOException $e) {}
}

// Apply shipping logic per seller type and free shipping threshold
$total = 0;
foreach ($itemsBySeller as $sid => &$group) {
    $group['shipping_waived'] = false;

    // Calculate shipping based on seller's shipping_type
    if ($group['shipping_type'] === 'standard') {
        $group['shipping_total'] = $group['standard_shipping_fee'];
    } elseif ($group['shipping_type'] === 'tiered') {
        $tFee = 0;
        if (isset($tieredFees[$sid])) {
            foreach ($tieredFees[$sid] as $t) {
                if ($group['qty'] >= $t['min_items']) $tFee = floatval($t['shipping_fee']);
            }
        }
        $group['shipping_total'] = $tFee;
    }
    // 'per_item' was already accumulated above

    // Check free shipping threshold
    if (isset($group['free_shipping_threshold']) && $group['free_shipping_threshold'] !== null && $group['base_subtotal'] >= floatval($group['free_shipping_threshold'])) {
        $group['shipping_total'] = 0;
        $group['shipping_waived'] = true;
    }

    $group['group_total'] = $group['base_subtotal'] + $group['shipping_total'];
    $total += $group['group_total'];
}
unset($group);




$totalQty = 0;
foreach ($cartItems as $item) {
    $totalQty += $item['cart_qty'];
}
$sellerCount = count($itemsBySeller);

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/browse.css">
<style>
/* ===== Multi-Seller Cart ===== */
.cart-page { padding: 90px 0 60px; min-height: 80vh; }
.cart-stats-bar {
    display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 32px;
}
.cart-stat-chip {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
}
.cart-stat-chip i { color: var(--accent-red); font-size: 0.85rem; }
.cart-stat-chip strong { color: var(--text-primary); }

/* Seller Group Card */
.seller-cart-group {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg, 16px);
    margin-bottom: 24px;
    overflow: hidden;
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}
.seller-cart-group:hover {
    border-color: rgba(229,57,53,0.2);
    box-shadow: 0 4px 24px rgba(229,57,53,0.06);
}

.seller-cart-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    background: rgba(255,255,255,0.015);
}
.seller-cart-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: var(--bg-card);
    border: 2px solid var(--border-color);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; flex-shrink: 0;
    font-weight: 700; color: var(--text-muted); font-size: 1rem;
}
.seller-cart-avatar img { width: 100%; height: 100%; object-fit: cover; }
.seller-cart-info { flex: 1; }
.seller-cart-name {
    font-weight: 700; font-size: 0.95rem; color: var(--text-primary);
    display: flex; align-items: center; gap: 6px;
}
.seller-cart-name .verified-icon { color: #008097; font-size: 0.8rem; }
.seller-cart-meta {
    font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;
}
.seller-cart-visit {
    font-size: 0.75rem; font-weight: 700; color: var(--accent-red);
    text-decoration: none; text-transform: uppercase; letter-spacing: 0.05em;
    padding: 6px 14px;
    border: 1px solid rgba(229,57,53,0.2);
    border-radius: 20px;
    transition: all 0.2s;
    white-space: nowrap;
}
.seller-cart-visit:hover {
    background: rgba(229,57,53,0.08);
    border-color: rgba(229,57,53,0.4);
    color: var(--accent-red);
}

/* Items inside seller card */
.seller-cart-items { padding: 0; }
.seller-cart-item {
    display: flex; gap: 14px; align-items: center;
    padding: 16px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    transition: background 0.2s;
}
.seller-cart-item:last-child { border-bottom: none; }
.seller-cart-item:hover { background: rgba(255,255,255,0.015); }

.sci-img {
    width: 64px; height: 64px; border-radius: 10px;
    object-fit: cover; background: var(--bg-card); flex-shrink: 0;
}
.sci-img-placeholder {
    width: 64px; height: 64px; border-radius: 10px;
    background: var(--bg-card);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted); font-size: 1.3rem; flex-shrink: 0;
}
.sci-details { flex: 1; min-width: 0; }
.sci-title {
    font-weight: 600; font-size: 0.9rem; color: var(--text-primary);
    text-decoration: none; display: block;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sci-title:hover { color: var(--accent-red); }
.sci-cat { font-size: 0.72rem; color: var(--text-muted); margin-top: 3px; }
.sci-price { font-weight: 700; font-size: 0.95rem; color: var(--text-primary); white-space: nowrap; min-width: 90px; text-align: right; }
.sci-actions { display: flex; align-items: center; gap: 12px; }

/* Qty selector */
.sci-qty-wrap { position: relative; display: inline-block; }
.sci-qty-select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    padding: 7px 28px 7px 12px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    color: var(--text-primary); font-size: 0.85rem; font-weight: 700;
    font-family: inherit; cursor: pointer; outline: none;
    transition: all 0.2s; min-width: 58px;
}
.sci-qty-select:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.2); }
.sci-qty-select:focus { border-color: var(--accent-red); box-shadow: 0 0 0 3px rgba(229,57,53,0.12); }
.sci-qty-select option { background: #1a1a2e; color: #fff; }
.sci-qty-wrap i {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    font-size: 0.6rem; color: var(--text-muted); pointer-events: none;
}

.sci-remove {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted); font-size: 0.8rem;
    background: transparent; border: 1px solid transparent;
    cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.sci-remove:hover {
    background: rgba(229,57,53,0.08); border-color: rgba(229,57,53,0.2);
    color: var(--accent-red);
}

/* Seller footer */
.seller-cart-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 16px 24px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
}
.scf-subtotal {
    display: flex; flex-direction: column;
}
.scf-subtotal-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
.scf-subtotal-amount { font-size: 1.15rem; font-weight: 800; color: var(--text-primary); }
.scf-checkout-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 24px;
    background: var(--accent-red); color: #fff; border: none;
    border-radius: 10px; font-size: 0.85rem; font-weight: 700;
    font-family: inherit; cursor: pointer; text-decoration: none;
    transition: all 0.25s;
    box-shadow: 0 3px 12px rgba(229,57,53,0.25);
}
.scf-checkout-btn:hover {
    filter: brightness(1.12); transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(229,57,53,0.35); color: #fff;
}

/* Grand total sticky bar */
.cart-grand-total-bar {
    position: sticky; bottom: 0; left: 0; right: 0; z-index: 50;
    background: var(--bg-surface);
    border-top: 1px solid var(--border-color);
    border-radius: var(--radius-lg, 16px) var(--radius-lg, 16px) 0 0;
    padding: 20px 28px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    box-shadow: 0 -8px 30px rgba(0,0,0,0.4);
    flex-wrap: wrap;
}
.cgt-info {}
.cgt-label { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
.cgt-amount { font-size: 1.4rem; font-weight: 800; color: var(--text-primary); }
.cgt-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.cgt-checkout-all {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 13px 28px;
    background: linear-gradient(135deg, var(--accent-red), #ff5252);
    color: #fff; border: none;
    border-radius: 12px; font-size: 0.9rem; font-weight: 700;
    font-family: inherit; cursor: pointer; text-decoration: none;
    transition: all 0.25s;
    box-shadow: 0 4px 16px rgba(229,57,53,0.3);
    position: relative; overflow: hidden;
}
.cgt-checkout-all::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}
.cgt-checkout-all:hover {
    filter: brightness(1.12); transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(229,57,53,0.4); color: #fff;
}
.cgt-checkout-all .upcoming-pill {
    font-size: 0.6rem; font-weight: 800; letter-spacing: 0.08em;
    background: rgba(255,255,255,0.2); color: #fff;
    padding: 2px 8px; border-radius: 10px;
}

/* Empty cart */
.cart-empty { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.cart-empty i { font-size: 3rem; margin-bottom: 16px; opacity: 0.4; }

@media (max-width: 768px) {
    .cart-page { padding: 70px 16px 120px; }
    .seller-cart-header { padding: 14px 16px; gap: 10px; }
    .seller-cart-item { padding: 12px 16px; gap: 10px; }
    .seller-cart-footer { padding: 14px 16px; flex-direction: column; align-items: stretch; gap: 12px; }
    .scf-checkout-btn { justify-content: center; }
    .sci-img, .sci-img-placeholder { width: 52px; height: 52px; }
    .sci-price { min-width: auto; font-size: 0.85rem; }
    .sci-actions { gap: 8px; }
    .cart-grand-total-bar { 
        padding: 16px 16px; 
        border-radius: 16px 16px 0 0;
        margin-bottom: 60px; /* space for bottom nav */
    }
    .cgt-amount { font-size: 1.2rem; }
    .cgt-actions { width: 100%; }
    .cgt-checkout-all { width: 100%; justify-content: center; }
    .cart-stats-bar { gap: 8px; }
    .cart-stat-chip { padding: 6px 12px; font-size: 0.75rem; }
    .seller-cart-visit { display: none; }
}
</style>

<div class="cart-page container-rl">
    <div class="section-header" data-aos="fade-up">
        <div>
            <div class="section-label">YOUR</div>
            <h2 class="section-title">SHOPPING CART</h2>
        </div>
        <?php if (!empty($cartItems)): ?>
            <a href="cart.php?clear=1" style="color: var(--text-muted); font-size: 0.85rem;"><i class="fas fa-trash"></i> Clear Cart</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($itemsBySeller)): ?>

    <!-- Stats Chips -->
    <div class="cart-stats-bar" data-aos="fade-up">
        <div class="cart-stat-chip">
            <i class="fas fa-box"></i>
            <span><strong><?php echo $totalQty; ?></strong> item<?php echo $totalQty !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="cart-stat-chip">
            <i class="fas fa-store"></i>
            <span><strong><?php echo $sellerCount; ?></strong> seller<?php echo $sellerCount !== 1 ? 's' : ''; ?></span>
        </div>
        <div class="cart-stat-chip">
            <i class="fas fa-rupee-sign"></i>
            <span>Total: <strong>Rs.<?php echo number_format($total, 0); ?></strong></span>
        </div>
    </div>

    <!-- Seller Groups -->
    <?php $groupIndex = 0; foreach ($itemsBySeller as $sellerId => $group): $groupIndex++; ?>
    <div class="seller-cart-group" data-aos="fade-up" data-aos-delay="<?php echo $groupIndex * 50; ?>" id="seller-group-<?php echo $sellerId; ?>">
        
        <!-- Seller Header -->
        <div class="seller-cart-header">
            <div class="seller-cart-avatar">
                <?php if (!empty($group['seller_avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($group['seller_avatar']); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($group['seller_name'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="seller-cart-info">
                <div class="seller-cart-name">
                    <?php echo htmlspecialchars($group['seller_name']); ?>
                    <?php if ($group['seller_verified']): ?>
                        <i class="fas fa-check-circle verified-icon" title="Verified Seller"></i>
                    <?php endif; ?>
                </div>
                <div class="seller-cart-meta">
                    <?php echo $group['qty']; ?> item<?php echo $group['qty'] !== 1 ? 's' : ''; ?> • Rs.<?php echo number_format($group['group_total'], 0); ?>
                    <?php if($group['shipping_waived']): ?>
                        <span style="color:var(--accent-green);">(<i class="fas fa-gift"></i> Free Shipping Applied)</span>
                    <?php elseif($group['shipping_total'] > 0): ?>
                        <span style="color:var(--accent-green);">(Incl. Rs.<?php echo number_format($group['shipping_total'], 0); ?> shipping)</span>
                    <?php endif; ?>
                </div>


            </div>
            <a href="seller.php?id=<?php echo $group['seller_uid']; ?>" class="seller-cart-visit">
                <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i> Visit Store
            </a>
        </div>

        <!-- Items -->
        <div class="seller-cart-items">
            <?php foreach ($group['items'] as $item): ?>
            <div class="seller-cart-item">
                <?php if (!empty($item['image'])): ?>
                    <img src="<?php echo htmlspecialchars($item['image']); ?>" class="sci-img" alt="">
                <?php else: ?>
                    <div class="sci-img-placeholder"><i class="fas fa-car"></i></div>
                <?php endif; ?>
                
                <div class="sci-details">
                    <a href="listing.php?id=<?php echo $item['id']; ?>" class="sci-title"><?php echo htmlspecialchars($item['title']); ?></a>
                    <div class="sci-cat">
                        <?php echo htmlspecialchars($item['category_name'] ?? ''); ?> 
                        <?php 
                        $sellerShipType = $group['shipping_type'] ?? 'per_item';
                        if ($sellerShipType === 'per_item'): ?>
                            <?php if($item['shipping_fee'] > 0): ?>
                                • <span style="color:var(--accent-green);">Shipping: Rs.<?php echo number_format($item['shipping_fee'], 0); ?>/pc</span>
                            <?php else: ?>
                                • <span style="color:var(--text-muted);">Free Shipping</span>
                            <?php endif; ?>
                        <?php elseif ($sellerShipType === 'standard'): ?>
                            • <span style="color:var(--accent-green);">Flat Rate Shipping</span>
                        <?php elseif ($sellerShipType === 'tiered'): ?>
                            • <span style="color:var(--accent-green);">Tiered Shipping</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sci-actions">
                    <div class="sci-qty-wrap">
                        <select class="sci-qty-select" onchange="window.location.href='cart.php?update_qty=<?php echo $item['id']; ?>&qty='+this.value">
                            <?php 
                            $maxStock = intval($item['stock'] ?? 1);
                            for ($q = 1; $q <= $maxStock; $q++): ?>
                                <option value="<?php echo $q; ?>" <?php echo $q == $item['cart_qty'] ? 'selected' : ''; ?>><?php echo $q; ?></option>
                            <?php endfor; ?>
                        </select>
                        <i class="fas fa-chevron-down"></i>
                    </div>

                    <div class="sci-price">
                        <div>Rs.<?php echo number_format($item['base_subtotal'], 0); ?></div>
                        <?php if($sellerShipType === 'per_item'): ?>
                            <?php if($group['shipping_waived']): ?>
                                <div style="font-size:0.65rem; color:var(--accent-green); font-weight:400; margin-top:2px;"><del style="color:var(--text-muted);">+ Rs.<?php echo number_format($item['item_shipping_total'], 0); ?></del> Free</div>
                            <?php elseif($item['item_shipping_total'] > 0): ?>
                                <div style="font-size:0.65rem; color:var(--text-muted); font-weight:400; margin-top:2px;">+ Rs.<?php echo number_format($item['item_shipping_total'], 0); ?> ship</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>



                    <a href="cart.php?remove=<?php echo $item['id']; ?>" class="sci-remove" title="Remove">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Seller Footer -->
        <div class="seller-cart-footer">
            <div class="scf-subtotal">
                <span class="scf-subtotal-label">Subtotal (<?php echo $group['qty']; ?> item<?php echo $group['qty'] !== 1 ? 's' : ''; ?>)</span>
                <span class="scf-subtotal-amount">Rs.<?php echo number_format($group['group_total'], 0); ?></span>
            </div>
            <a href="checkout.php?seller_id=<?php echo $sellerId; ?>" class="scf-checkout-btn">
                Checkout with <?php echo htmlspecialchars($group['seller_name']); ?> <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Grand Total Sticky Bar -->
    <div class="cart-grand-total-bar" data-aos="fade-up">
        <div class="cgt-info">
            <div class="cgt-label"><?php echo $sellerCount; ?> Seller<?php echo $sellerCount > 1 ? 's' : ''; ?> • <?php echo $totalQty; ?> Item<?php echo $totalQty > 1 ? 's' : ''; ?></div>
            <div class="cgt-amount">Rs.<?php echo number_format($total, 0); ?></div>
        </div>
        <div class="cgt-actions">
            <?php if ($sellerCount === 1): ?>
                <?php $firstSellerId = array_key_first($itemsBySeller); ?>
                <a href="checkout.php?seller_id=<?php echo $firstSellerId; ?>" class="cgt-checkout-all">
                    <i class="fas fa-lock"></i> Proceed to Checkout
                </a>
            <?php else: ?>
                <button type="button" class="cgt-checkout-all" onclick="openComingSoonModal()">
                    <i class="fas fa-layer-group"></i> Checkout All
                    <span class="upcoming-pill">UPCOMING</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <div class="cart-empty" data-aos="fade-up">
        <i class="fas fa-shopping-cart"></i>
        <h3>Your cart is empty</h3>
        <p>Start browsing to find your next collectible.</p>
        <a href="browse.php" class="btn-red" style="margin-top: 20px;">Browse Listings</a>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
