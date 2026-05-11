<?php
session_start();
require_once 'config/db.php';

try {
    $conn->exec("ALTER TABLE `seller_reviews` ADD COLUMN `review_image` VARCHAR(255) DEFAULT NULL AFTER `review_text`");
} catch (PDOException $e) { /* Column probably exists */ }

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'buyer';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $orderId    = intval($_POST['order_id']);
    $rating     = intval($_POST['rating']);
    $reviewText = trim($_POST['review_text']);
    if ($rating >= 1 && $rating <= 5) {
        $stmt = $conn->prepare("SELECT seller_id, status FROM orders WHERE id = ? AND buyer_id = ?");
        $stmt->execute([$orderId, $userId]);
        $ord = $stmt->fetch();
        if ($ord && in_array($ord['status'], ['shipped', 'delivered'])) {
            try {
                $imagePath = null;
                if (!empty($_FILES['review_image']['name'])) {
                    $ext = pathinfo($_FILES['review_image']['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('rev_') . '.' . $ext;
                    $uploadDir = 'uploads/reviews/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $dest = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['review_image']['tmp_name'], $dest)) {
                        $imagePath = $dest;
                    }
                }

                $conn->beginTransaction();
                $conn->prepare("INSERT INTO seller_reviews (order_id, buyer_id, seller_id, rating, review_text, review_image) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), review_image = COALESCE(VALUES(review_image), review_image)")
                     ->execute([$orderId, $userId, $ord['seller_id'], $rating, $reviewText, $imagePath]);
                $conn->prepare("UPDATE users SET avg_rating = (SELECT AVG(rating) FROM seller_reviews WHERE seller_id = ?), review_count = (SELECT COUNT(*) FROM seller_reviews WHERE seller_id = ?) WHERE id = ?")
                     ->execute([$ord['seller_id'], $ord['seller_id'], $ord['seller_id']]);
                $conn->commit();
                $_SESSION['ov_success'] = "Review submitted successfully!";
                header("Location: order_view.php");
                exit;
            } catch (PDOException $e) { $conn->rollBack(); }
        }
    }
}

// Fetch buying orders
$buyingOrders = [];
try {
    $stmt = $conn->prepare("
        SELECT o.*,
               u.name AS seller_name, u.avatar AS seller_avatar, u.upi_id, u.bank_details,
               sr.rating AS review_rating, sr.review_text, sr.review_image,
               TIMESTAMPDIFF(SECOND, NOW(), o.payment_deadline) AS db_rem_secs
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        LEFT JOIN seller_reviews sr ON sr.order_id = o.id AND sr.buyer_id = ?
        WHERE o.buyer_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    $buyingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch selling orders
$sellingOrders = [];
if (in_array($userRole, ['seller', 'admin'])) {
    try {
        $stmt = $conn->prepare("
            SELECT o.*, u.name AS buyer_name, u.avatar AS buyer_avatar
            FROM orders o
            JOIN users u ON o.buyer_id = u.id
            WHERE o.seller_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$userId]);
        $sellingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

function getOrderItems($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT oi.*, l.title, l.image, l.seller_id, c.name AS category_name
        FROM order_items oi
        JOIN listings l ON oi.listing_id = l.id
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Stats
$totalSpent    = array_sum(array_map(fn($o) => $o['total'], $buyingOrders));
$deliveredBuying = count(array_filter($buyingOrders, fn($o) => $o['status'] === 'delivered'));
$pendingBuying   = count(array_filter($buyingOrders, fn($o) => $o['status'] === 'pending'));

include 'includes/header.php';
?>

<style>
:root {
    --ov-radius: 16px;
    --ov-card-bg: rgba(255,255,255,0.03);
    --ov-border: rgba(255,255,255,0.07);
    --ov-border-hover: rgba(255,255,255,0.14);
}

/* ── Page Layout ── */
.ov-page { padding: 100px 0 80px; min-height: 90vh; }
.ov-inner { max-width: 960px; margin: 0 auto; padding: 0 20px; }

/* ── Hero Header ── */
.ov-hero {
    display: flex; align-items: center; justify-content: space-between;
    gap: 20px; margin-bottom: 36px; flex-wrap: wrap;
}
.ov-hero-title { font-size: 2rem; font-weight: 900; letter-spacing: -0.5px; }
.ov-hero-title span { color: var(--accent-red); }
.ov-hero-sub { color: var(--text-muted); font-size: 0.88rem; margin-top: 4px; }

/* ── Stat Pills ── */
.ov-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.ov-stat {
    background: var(--ov-card-bg); border: 1px solid var(--ov-border);
    border-radius: 12px; padding: 10px 18px; text-align: center; min-width: 90px;
}
.ov-stat-val { font-size: 1.3rem; font-weight: 900; color: var(--text-primary); }
.ov-stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); margin-top: 2px; }

/* ── Alert ── */
.ov-alert-success {
    background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2);
    color: #34d399; padding: 14px 20px; border-radius: 12px;
    margin-bottom: 24px; display: flex; gap: 10px; align-items: center; font-size: 0.9rem;
}

/* ── Tabs ── */
.ov-tabs { display: flex; gap: 6px; margin-bottom: 24px; border-bottom: 1px solid var(--ov-border); padding-bottom: 0; }
.ov-tab {
    padding: 10px 20px; font-size: 0.82rem; font-weight: 700; cursor: pointer;
    border: none; background: transparent; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.6px; font-family: inherit;
    border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.2s;
}
.ov-tab.active { color: var(--accent-red); border-bottom-color: var(--accent-red); }
.ov-tab:not(.active):hover { color: var(--text-primary); }
.ov-tab-panel { display: none; }
.ov-tab-panel.active { display: block; }

/* ── Filter Bar ── */
.ov-filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    margin-bottom: 20px;
}
.ov-filter-chip {
    padding: 5px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;
    border: 1px solid var(--ov-border); background: transparent; color: var(--text-muted);
    cursor: pointer; font-family: inherit; text-transform: uppercase; letter-spacing: 0.4px;
    transition: all 0.2s;
}
.ov-filter-chip.active { background: var(--accent-red); border-color: var(--accent-red); color: #fff; }
.ov-filter-chip:not(.active):hover { border-color: var(--ov-border-hover); color: var(--text-primary); }

/* ── Order Card ── */
.ov-card {
    background: var(--ov-card-bg); border: 1px solid var(--ov-border);
    border-radius: var(--ov-radius); margin-bottom: 16px; overflow: hidden;
    transition: border-color 0.25s, transform 0.2s, box-shadow 0.2s;
}
.ov-card:hover {
    border-color: var(--ov-border-hover);
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    transform: translateY(-1px);
}

/* ── Card Top Bar ── */
.ov-card-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; background: rgba(255,255,255,0.02);
    border-bottom: 1px solid var(--ov-border); gap: 10px; flex-wrap: wrap;
}
.ov-order-num { font-size: 0.75rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; }
.ov-order-num strong { color: var(--text-primary); font-size: 0.85rem; }
.ov-topbar-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Badges ── */
.ov-badge {
    padding: 4px 11px; border-radius: 20px; font-size: 0.68rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.06em;
    display: inline-flex; align-items: center; gap: 5px;
}
.ov-badge.pending    { background: rgba(255,183,77,0.1);   color: #ffb74d; }
.ov-badge.verifying  { background: rgba(59,130,246,0.1);   color: #60a5fa; }
.ov-badge.confirmed  { background: rgba(16,185,129,0.1);   color: #34d399; }
.ov-badge.shipped    { background: rgba(129,199,132,0.1);  color: #81c784; }
.ov-badge.delivered  { background: rgba(76,175,80,0.13);   color: #66bb6a; }
.ov-badge.cancelled  { background: rgba(229,57,53,0.1);    color: #e57373; }
.ov-badge.pay-ok     { background: rgba(16,185,129,0.1);   color: #34d399; }
.ov-badge.pay-wait   { background: rgba(255,183,77,0.1);   color: #ffb74d; }
.ov-badge.pay-sub    { background: rgba(59,130,246,0.1);   color: #60a5fa; }

/* ── Progress Tracker ── */
.ov-tracker { padding: 18px 20px; display: flex; align-items: center; gap: 0; border-bottom: 1px solid var(--ov-border); }
.ov-step { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
.ov-step:not(:last-child)::after {
    content: ''; position: absolute; top: 14px; left: 50%; width: 100%;
    height: 2px; background: var(--ov-border); z-index: 0;
}
.ov-step.done:not(:last-child)::after { background: var(--accent-red); }
.ov-step-dot {
    width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--ov-border);
    background: var(--bg-surface, #111); display: flex; align-items: center; justify-content: center;
    font-size: 0.65rem; color: var(--text-muted); position: relative; z-index: 1;
    transition: all 0.3s;
}
.ov-step.done .ov-step-dot { background: var(--accent-red); border-color: var(--accent-red); color: #fff; }
.ov-step.current .ov-step-dot {
    background: transparent; border-color: var(--accent-red); color: var(--accent-red);
    box-shadow: 0 0 0 4px rgba(229,57,53,0.15);
}
.ov-step-lbl { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-top: 6px; font-weight: 700; text-align: center; }
.ov-step.done .ov-step-lbl, .ov-step.current .ov-step-lbl { color: var(--text-primary); }

/* ── Items List ── */
.ov-items { padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; border-bottom: 1px solid var(--ov-border); }
.ov-item-row { display: flex; align-items: center; gap: 14px; }
.ov-item-thumb {
    width: 52px; height: 52px; border-radius: 10px; flex-shrink: 0;
    overflow: hidden; background: rgba(255,255,255,0.04); border: 1px solid var(--ov-border);
    display: flex; align-items: center; justify-content: center; color: var(--text-muted);
}
.ov-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ov-item-details { flex: 1; min-width: 0; }
.ov-item-name { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ov-item-cat { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }
.ov-item-price { font-size: 0.9rem; font-weight: 800; color: var(--accent-red); flex-shrink: 0; }

/* ── Info Panels ── */
.ov-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--ov-border); }
.ov-panel { background: rgba(255,255,255,0.02); border: 1px solid var(--ov-border); border-radius: 10px; padding: 12px 14px; }
.ov-panel-lbl { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); font-weight: 700; margin-bottom: 6px; }
.ov-panel-val { font-size: 0.85rem; color: var(--text-primary); font-weight: 600; line-height: 1.4; }
.ov-panel-val.muted { color: var(--text-secondary); font-weight: 400; font-size: 0.82rem; }
a.ov-panel-link { cursor: pointer; transition: border-color 0.2s, background 0.2s; display: block; }
a.ov-panel-link:hover { border-color: rgba(229,57,53,0.35); background: rgba(229,57,53,0.04); }
a.ov-panel-link .ov-panel-val { color: var(--text-primary); }

/* ── Tracking Highlight ── */
.ov-tracking-banner {
    margin: 0 20px 0; padding: 12px 16px;
    background: rgba(171,71,188,0.06); border: 1px solid rgba(171,71,188,0.2);
    border-radius: 10px; display: flex; align-items: center; gap: 12px;
    margin-bottom: 0;
}
.ov-tracking-banner i { color: #ab47bc; font-size: 1.1rem; }
.ov-tracking-id { font-weight: 800; font-size: 0.95rem; color: var(--text-primary); font-family: monospace; letter-spacing: 1px; }
.ov-tracking-lbl { font-size: 0.72rem; color: var(--text-muted); margin-top: 1px; }

/* ── Payment Section ── */
.ov-pay-section {
    margin: 0 20px; padding: 14px 16px;
    background: rgba(255,255,255,0.025); border: 1px solid var(--ov-border);
    border-radius: 10px;
}
.ov-pay-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); margin-bottom: 10px; }
.ov-upi-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ov-upi-id { font-weight: 800; font-size: 1rem; color: var(--text-primary); word-break: break-all; }
.ov-copy-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; background: transparent;
    color: var(--text-secondary); font-size: 0.8rem; font-weight: 500; cursor: pointer;
    transition: all 0.2s; font-family: inherit; white-space: nowrap;
}
.ov-copy-btn:hover { border-color: rgba(255,255,255,0.25); color: #fff; }
.ov-copy-btn.copied { border-color: rgba(16,185,129,0.4); color: #10b981; }
.ov-bank-details { font-size: 0.82rem; color: var(--text-secondary); margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--ov-border); white-space: pre-wrap; }
.ov-pay-btn {
    display: inline-flex; align-items: center; gap: 8px; margin-top: 14px;
    padding: 10px 22px; background: var(--accent-red); color: #fff;
    border: none; border-radius: 10px; font-size: 0.88rem; font-weight: 700;
    cursor: pointer; transition: all 0.2s; font-family: inherit; text-decoration: none;
}
.ov-pay-btn:hover { filter: brightness(1.15); transform: translateY(-1px); }

/* ── Card Footer ── */
.ov-card-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 20px; flex-wrap: wrap; gap: 8px;
}
.ov-footer-meta { font-size: 0.75rem; color: var(--text-muted); }
.ov-footer-actions { display: flex; align-items: center; gap: 12px; }
.ov-footer-link {
    display: inline-flex; align-items: center; gap: 5px; font-size: 0.8rem;
    color: var(--text-secondary); text-decoration: none; font-weight: 500; transition: color 0.2s;
}
.ov-footer-link:hover { color: var(--text-primary); }
.ov-footer-link.danger:hover { color: #e57373; }

/* ── Total Row ── */
.ov-total-row {
    display: flex; justify-content: flex-end; align-items: center;
    gap: 8px; padding: 12px 20px;
}
.ov-total-lbl { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.ov-total-val { font-size: 1.15rem; font-weight: 900; color: var(--text-primary); }

/* ── Review Box ── */
.ov-review-box { margin: 0 20px; padding: 14px 16px; background: rgba(59,130,246,0.04); border: 1px solid rgba(59,130,246,0.15); border-radius: 10px; }
.ov-review-box.has-review { background: rgba(16,185,129,0.04); border-color: rgba(16,185,129,0.15); }
.stars-display { color: #fbbf24; font-size: 0.88rem; }
.stars-display .far { color: var(--ov-border); }
.btn-review { background: #3b82f6; color: #fff; border: none; padding: 7px 14px; border-radius: 8px; font-size: 0.78rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
.btn-review:hover { filter: brightness(1.1); }

/* ── Review Modal ── */
.review-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(6px); }
.review-modal-overlay.active { display: flex; }
.review-modal { background: var(--bg-surface, #161616); padding: 32px; border-radius: 20px; width: 100%; max-width: 460px; border: 1px solid var(--ov-border); box-shadow: 0 24px 60px rgba(0,0,0,0.5); position: relative; }
.rm-close { position: absolute; top: 16px; right: 16px; background: none; border: none; color: var(--text-muted); font-size: 1.2rem; cursor: pointer; }
.rm-close:hover { color: var(--accent-red); }
.star-rating-input { display: flex; gap: 8px; font-size: 2rem; color: #fbbf24; margin-bottom: 20px; flex-direction: row-reverse; justify-content: flex-end; }
.star-rating-input input { display: none; }
.star-rating-input label { cursor: pointer; color: var(--ov-border); transition: color 0.2s; }
.star-rating-input label:hover, .star-rating-input label:hover ~ label, .star-rating-input input:checked ~ label { color: #fbbf24; }

/* ── Empty ── */
.ov-empty { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.ov-empty i { font-size: 3.5rem; margin-bottom: 16px; opacity: 0.15; display: block; }
.ov-empty h3 { font-size: 1.1rem; color: var(--text-secondary); margin-bottom: 8px; }

/* ── Responsive ── */
@media (max-width: 680px) {
    .ov-hero { flex-direction: column; align-items: flex-start; gap: 16px; }
    .ov-panels { grid-template-columns: 1fr; }
    .ov-tracker { padding: 14px 12px; }
    .ov-step-lbl { font-size: 0.55rem; }
    .ov-card-topbar { flex-direction: column; align-items: flex-start; }
    .ov-items { padding: 12px 14px; }
    .ov-panel, .ov-pay-section, .ov-review-box, .ov-tracking-banner { margin-left: 14px; margin-right: 14px; }
}
</style>

<div class="ov-page">
<div class="ov-inner">

    <?php if (!empty($_SESSION['ov_success'])): ?>
        <div class="ov-alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['ov_success']); unset($_SESSION['ov_success']); ?></div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="ov-hero" data-aos="fade-up">
        <div>
            <div class="ov-hero-title">My <span>Orders</span></div>
            <div class="ov-hero-sub">Track purchases, manage deliveries, and leave reviews</div>
        </div>
        <div class="ov-stats">
            <div class="ov-stat">
                <div class="ov-stat-val"><?php echo count($buyingOrders); ?></div>
                <div class="ov-stat-lbl">Total</div>
            </div>
            <div class="ov-stat">
                <div class="ov-stat-val"><?php echo $deliveredBuying; ?></div>
                <div class="ov-stat-lbl">Delivered</div>
            </div>
            <div class="ov-stat">
                <div class="ov-stat-val" style="color:var(--accent-red);">Rs.<?php echo number_format($totalSpent, 0); ?></div>
                <div class="ov-stat-lbl">Spent</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="ov-tabs" data-aos="fade-up">
        <button class="ov-tab active" onclick="switchTab('buying', this)" id="tabBuying">
            <i class="fas fa-shopping-bag"></i> Buying (<?php echo count($buyingOrders); ?>)
        </button>
        <?php if (in_array($userRole, ['seller', 'admin'])): ?>
        <button class="ov-tab" onclick="switchTab('selling', this)" id="tabSelling">
            <i class="fas fa-store"></i> Selling (<?php echo count($sellingOrders); ?>)
        </button>
        <?php endif; ?>
    </div>

    <!-- ═══════════ BUYING TAB ═══════════ -->
    <div class="ov-tab-panel active" id="panel-buying">

        <!-- Filter chips -->
        <?php if (!empty($buyingOrders)): ?>
        <div class="ov-filter-bar" data-aos="fade-up">
            <button class="ov-filter-chip active" onclick="filterOrders('all', this, 'buying')">All</button>
            <button class="ov-filter-chip" onclick="filterOrders('pending', this, 'buying')">Pending</button>
            <button class="ov-filter-chip" onclick="filterOrders('shipped', this, 'buying')">Shipped</button>
            <button class="ov-filter-chip" onclick="filterOrders('delivered', this, 'buying')">Delivered</button>
            <button class="ov-filter-chip" onclick="filterOrders('cancelled', this, 'buying')">Cancelled</button>
        </div>
        <?php endif; ?>

        <?php if (empty($buyingOrders)): ?>
            <div class="ov-empty" data-aos="fade-up">
                <i class="fas fa-shopping-bag"></i>
                <h3>No purchases yet</h3>
                <p>Items you buy on REDLINER will appear here.</p>
                <a href="browse.php" class="btn-red" style="margin-top:20px; display:inline-flex; align-items:center; gap:8px;"><i class="fas fa-search"></i> Browse Listings</a>
            </div>
        <?php else: ?>
            <?php foreach ($buyingOrders as $order):
                $items     = getOrderItems($conn, $order['id']);
                $firstItem = $items[0] ?? null;
                $ps        = $order['payment_status'] ?? 'pending';
                $os        = $order['status']         ?? 'pending';

                // Progress steps
                $steps = ['pending' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3];
                $curStep = $os === 'cancelled' ? -1 : ($steps[$os] ?? 0);

                $statusLabel = ['pending'=>'Pending','confirmed'=>'Confirmed','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
                $payLabel    = ['pending'=>'Unpaid','verifying'=>'Verifying','confirmed'=>'Paid','failed'=>'Failed'];
            ?>
            <div class="ov-card" data-aos="fade-up" data-status="<?php echo $os; ?>">

                <!-- Top bar -->
                <div class="ov-card-topbar">
                    <div class="ov-order-num">
                        Order <strong>#<?php echo $order['id']; ?></strong>
                        &nbsp;·&nbsp; <?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?>
                    </div>
                    <div class="ov-topbar-right">
                        <!-- Payment badge -->
                        <?php if ($ps === 'verifying'): ?>
                            <span class="ov-badge pay-sub"><i class="fas fa-spinner fa-spin"></i> Verifying Payment</span>
                        <?php elseif ($ps === 'confirmed'): ?>
                            <span class="ov-badge pay-ok"><i class="fas fa-check-circle"></i> Paid</span>
                        <?php else: ?>
                            <span class="ov-badge pay-wait"><i class="fas fa-clock"></i> Awaiting Payment</span>
                        <?php endif; ?>
                        <!-- Order status badge -->
                        <?php if ($os === 'cancelled'): ?>
                            <span class="ov-badge cancelled"><i class="fas fa-times-circle"></i> Cancelled</span>
                        <?php else: ?>
                            <span class="ov-badge <?php echo $os; ?>">
                                <i class="fas fa-<?php echo $os==='shipped'?'truck':($os==='delivered'?'box-open':($os==='confirmed'?'check':'circle')); ?>"></i>
                                <?php echo $statusLabel[$os] ?? ucfirst($os); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Progress Tracker -->
                <?php if ($os !== 'cancelled'): ?>
                <div class="ov-tracker">
                    <?php
                    $trackSteps = [
                        ['icon'=>'fa-shopping-cart',  'lbl'=>'Ordered'],
                        ['icon'=>'fa-check-circle',   'lbl'=>'Confirmed'],
                        ['icon'=>'fa-truck',          'lbl'=>'Shipped'],
                        ['icon'=>'fa-box-open',       'lbl'=>'Delivered'],
                    ];
                    foreach ($trackSteps as $idx => $ts):
                        $cls = $idx < $curStep ? 'done' : ($idx === $curStep ? 'current' : '');
                    ?>
                    <div class="ov-step <?php echo $cls; ?>">
                        <div class="ov-step-dot"><i class="fas <?php echo $ts['icon']; ?>" style="font-size:0.7rem;"></i></div>
                        <div class="ov-step-lbl"><?php echo $ts['lbl']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Items -->
                <div class="ov-items">
                    <?php foreach ($items as $item): ?>
                    <div class="ov-item-row">
                        <div class="ov-item-thumb">
                            <?php if (!empty($item['image'])): ?>
                                <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="">
                            <?php else: ?>
                                <i class="fas fa-car-side"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ov-item-details">
                            <div class="ov-item-name"><?php echo htmlspecialchars($item['title']); ?></div>
                            <?php if (!empty($item['category_name'])): ?>
                            <div class="ov-item-cat"><i class="fas fa-tag" style="font-size:0.6rem;"></i> <?php echo htmlspecialchars($item['category_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="ov-item-price">
                            <div>Rs.<?php echo number_format($item['price'], 0); ?></div>
                            <?php if(floatval($item['shipping_fee'] ?? 0) > 0): ?>
                                <div style="font-size:0.65rem; color:var(--text-muted); font-weight:400; text-align:right;">+ Rs.<?php echo number_format($item['shipping_fee'] * ($item['quantity'] ?? 1), 0); ?> ship</div>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Info Panels: Seller + Shipping -->
                <div class="ov-panels">
                    <a href="seller.php?id=<?php echo $order['seller_id']; ?>" class="ov-panel ov-panel-link" style="text-decoration:none;">
                        <div class="ov-panel-lbl"><i class="fas fa-store"></i> Seller</div>
                        <div class="ov-panel-val"><?php echo htmlspecialchars($order['seller_name']); ?> <i class="fas fa-external-link-alt" style="font-size:0.6rem;opacity:0.4;margin-left:4px;"></i></div>
                    </a>
                    <?php if (!empty($order['estimated_delivery'])): ?>
                    <div class="ov-panel" style="border-color:rgba(79,195,247,0.2); background:rgba(79,195,247,0.04);">
                        <div class="ov-panel-lbl" style="color:#4fc3f7;"><i class="far fa-calendar-check"></i> Est. Delivery</div>
                        <div class="ov-panel-val" style="color:#4fc3f7;"><?php echo date('d M Y', strtotime($order['estimated_delivery'])); ?></div>
                    </div>
                    <?php else: ?>
                    <div class="ov-panel">
                        <div class="ov-panel-lbl"><i class="fas fa-credit-card"></i> Payment</div>
                        <div class="ov-panel-val muted"><?php echo strtoupper($order['payment_method'] ?? '—'); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="ov-panel" style="grid-column: span 2;">
                        <div class="ov-panel-lbl"><i class="fas fa-map-marker-alt"></i> Deliver To</div>
                        <div class="ov-panel-val muted">
                            <?php echo htmlspecialchars($order['shipping_name'] ?? ''); ?>&nbsp;·&nbsp;
                            <?php echo htmlspecialchars($order['shipping_address'] ?? ''); ?>,
                            <?php echo htmlspecialchars($order['shipping_city'] ?? ''); ?>&nbsp;
                            <?php echo htmlspecialchars($order['shipping_pincode'] ?? ''); ?>
                        </div>
                    </div>
                </div>

                <!-- Tracking Banner -->
                <?php if (!empty($order['tracking_id'])): ?>
                <div style="padding: 0 20px 14px;">
                    <div class="ov-tracking-banner">
                        <i class="fas fa-shipping-fast"></i>
                        <div>
                            <div class="ov-tracking-lbl"><?php echo !empty($order['courier']) ? htmlspecialchars($order['courier']) . ' Tracking' : 'Tracking Link'; ?></div>
                            <div class="ov-tracking-id"><?php echo htmlspecialchars($order['tracking_id']); ?></div>
                        </div>
                        <button class="ov-copy-btn" style="margin-left:auto;" onclick="copyText(this, '<?php echo htmlspecialchars($order['tracking_id'], ENT_QUOTES); ?>')">
                            <i class="far fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Section (for failed/rejected payments) -->
                <?php if ($ps === 'failed' && $os !== 'cancelled'): ?>
                <div style="padding: 0 20px 14px;">
                    <div style="background:rgba(229,57,53,0.06); border:1px solid rgba(229,57,53,0.15); border-radius:12px; padding:16px;">
                        <div style="color:#ef5350; font-family:var(--font-display); font-size:1.1rem; font-weight:700; margin-bottom:8px;">
                            <i class="fas fa-ban"></i> Payment Proof Rejected
                        </div>
                        <div style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:12px; line-height:1.5;">
                            The seller has rejected your payment submission for the following reason:<br>
                            <span style="color:var(--text-primary); font-weight:600; display:block; margin-top:4px; padding:8px; background:rgba(255,255,255,0.04); border-radius:6px;">
                                "<?php echo htmlspecialchars($order['payment_rejection_reason'] ?? 'Invalid payment details or amount mismatch.'); ?>"
                            </span>
                        </div>
                        <div style="display:flex; gap:10px; margin-top: 14px;">
                            <a href="dispute.php?order_id=<?php echo $order['id']; ?>" class="btn-primary" style="background:#e53935; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:0.85rem; text-decoration:none; font-weight:700;">
                                <i class="fas fa-exclamation-triangle"></i> Raise Dispute
                            </a>
                            <a href="pay_order.php?ids=<?php echo $order['id']; ?>" class="btn-secondary" style="background:rgba(229,57,53,0.1); color:#e53935; border:1px solid rgba(229,57,53,0.3); border-radius:8px; padding:9px 18px; font-size:0.85rem; text-decoration:none; font-weight:700;">
                                <i class="fas fa-redo"></i> Try Again
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Section (for unpaid orders) -->
                <?php if ($ps === 'pending' && $os !== 'cancelled'): ?>
                <?php
                    $deadlineStr = $order['payment_deadline'] ?? null;
                    $rem = isset($order['db_rem_secs']) ? (int)$order['db_rem_secs'] : 0;
                    $deadlineExpired = $deadlineStr && $rem <= 0;
                    $deadlineRemaining = $deadlineStr ? max(0, $rem) : 0;
                    $deadlineMins = floor($deadlineRemaining / 60);
                    $deadlineSecs = $deadlineRemaining % 60;
                ?>
                <div style="padding: 0 20px 14px;">
                    <div class="ov-pay-section">
                        <?php if ($deadlineExpired): ?>
                            <div class="ov-pay-label" style="color:#ef5350;"><i class="fas fa-hourglass-end"></i> Payment Expired</div>
                            <div style="font-size:0.82rem; color:var(--text-muted); margin-bottom:8px;">
                                The payment window has expired. This order will be cancelled.
                            </div>
                        <?php else: ?>
                            <div class="ov-pay-label"><i class="fas fa-wallet"></i> Complete Your Payment</div>
                            <?php if ($deadlineStr): ?>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; padding:10px 14px; background:rgba(255,183,77,0.06); border:1px solid rgba(255,183,77,0.15); border-radius:10px;">
                                <i class="fas fa-clock" style="color:#ffb74d;"></i>
                                <span style="font-size:0.82rem; color:#ffb74d; font-weight:600;">
                                    <?php echo $deadlineMins; ?>m <?php echo $deadlineSecs; ?>s remaining to pay
                                </span>
                            </div>
                            <?php endif; ?>
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <a href="pay_order.php?ids=<?php echo $order['id']; ?>" class="ov-pay-btn">
                                    <i class="fas fa-wallet"></i> Open Payment Window
                                </a>
                                <button type="button" class="ov-copy-btn" style="height:42px; margin-top:14px; color:#ef5350; border-color:rgba(229,57,53,0.2);" onclick="cancelOrder(<?php echo $order['id']; ?>, this)">
                                    <i class="fas fa-times"></i> Cancel Order
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Review Section -->
                <?php if ($os === 'shipped' || $os === 'delivered'): ?>
                <div style="padding: 0 20px 14px;">
                    <div class="ov-review-box <?php echo !empty($order['review_rating']) ? 'has-review' : ''; ?>">
                        <?php if (!empty($order['review_rating'])): ?>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                <div>
                                    <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:4px;">Your Review</div>
                                    <div class="stars-display">
                                        <?php for($i=1;$i<=5;$i++) echo $i<=$order['review_rating']?'<i class="fas fa-star"></i>':'<i class="far fa-star"></i>'; ?>
                                        <span style="color:var(--text-primary); font-weight:700; margin-left:6px;"><?php echo $order['review_rating']; ?>.0</span>
                                    </div>
                                    <?php if(!empty($order['review_text'])): ?>
                                        <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:8px; line-height:1.5;"><?php echo nl2br(htmlspecialchars($order['review_text'])); ?></p>
                                    <?php endif; ?>
                                    <?php if(!empty($order['review_image'])): ?>
                                        <div style="margin-top:12px;">
                                            <img src="<?php echo htmlspecialchars($order['review_image']); ?>" style="max-height: 80px; border-radius: 8px; border: 1px solid var(--ov-border); cursor: pointer; object-fit: cover;" onclick="window.open(this.src, '_blank')" alt="Review Image">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn-review" style="background:transparent; border:1px solid var(--ov-border); color:var(--text-secondary); flex-shrink:0;" onclick="openReviewModal(<?php echo $order['id']; ?>, <?php echo $order['review_rating']; ?>, '<?php echo htmlspecialchars($order['review_text'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-edit"></i> Edit</button>
                            </div>
                        <?php else: ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                                <div>
                                    <div style="font-size:0.92rem; color:var(--text-primary); font-weight:600; margin-bottom:3px;"><i class="fas fa-star" style="color:#fbbf24;"></i> Rate your experience</div>
                                    <div style="font-size:0.78rem; color:var(--text-muted);">Help others by reviewing this seller.</div>
                                </div>
                                <button type="button" class="btn-review" onclick="openReviewModal(<?php echo $order['id']; ?>, 5, '')">Leave Review</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Total + Footer -->
                <div class="ov-total-row" style="border-top: 1px solid var(--ov-border);">
                    <span class="ov-total-lbl">Order Total</span>
                    <span style="width:12px;height:1px;background:var(--ov-border);display:inline-block;margin:0 8px;"></span>
                    <span class="ov-total-val">Rs.<?php echo number_format($order['total'], 0); ?></span>
                </div>
                <div class="ov-card-footer">
                    <div class="ov-footer-meta">
                        <i class="fas fa-hashtag" style="font-size:0.65rem;"></i> <?php echo $order['id']; ?>
                        &nbsp;·&nbsp; <?php echo date('d M Y', strtotime($order['created_at'])); ?>
                        &nbsp;·&nbsp; <?php echo strtoupper($order['payment_method'] ?? ''); ?>
                    </div>
                    <div class="ov-footer-actions">
                        <?php if(!in_array($os, ['pending', 'cancelled'])): ?>
                            <a href="dispute.php?order_id=<?php echo $order['id']; ?>" class="ov-footer-link danger"><i class="fas fa-exclamation-triangle"></i> Report Issue</a>
                        <?php endif; ?>
                        <?php if($firstItem): ?>
                            <a href="negotiate.php?listing_id=<?php echo $firstItem['listing_id'] ?? 0; ?>" class="ov-footer-link"><i class="far fa-comment-dots"></i> Chat with Seller</a>
                        <?php endif; ?>
                        <a href="invoice.php?id=<?php echo $order['id']; ?>" class="ov-footer-link"><i class="fas fa-file-invoice"></i> Invoice</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ═══════════ SELLING TAB ═══════════ -->
    <?php if (in_array($userRole, ['seller', 'admin'])): ?>
    <div class="ov-tab-panel" id="panel-selling">

        <?php if (!empty($sellingOrders)): ?>
        <div class="ov-filter-bar" data-aos="fade-up">
            <button class="ov-filter-chip active" onclick="filterOrders('all', this, 'selling')">All</button>
            <button class="ov-filter-chip" onclick="filterOrders('pending', this, 'selling')">Pending</button>
            <button class="ov-filter-chip" onclick="filterOrders('shipped', this, 'selling')">Shipped</button>
            <button class="ov-filter-chip" onclick="filterOrders('delivered', this, 'selling')">Delivered</button>
        </div>
        <?php endif; ?>

        <?php if (empty($sellingOrders)): ?>
            <div class="ov-empty" data-aos="fade-up">
                <i class="fas fa-store"></i>
                <h3>No sales yet</h3>
                <p>Orders from buyers will show up here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sellingOrders as $order):
                $items     = getOrderItems($conn, $order['id']);
                $firstItem = $items[0] ?? null;
                $ps        = $order['payment_status'] ?? 'pending';
                $os        = $order['status']         ?? 'pending';
                $steps     = ['pending'=>0,'confirmed'=>1,'shipped'=>2,'delivered'=>3];
                $curStep   = $os === 'cancelled' ? -1 : ($steps[$os] ?? 0);
                $statusLabel = ['pending'=>'Pending','confirmed'=>'Confirmed','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
            ?>
            <div class="ov-card" data-aos="fade-up" data-status="<?php echo $os; ?>">
                <div class="ov-card-topbar">
                    <div class="ov-order-num">
                        Order <strong>#<?php echo $order['id']; ?></strong>
                        &nbsp;·&nbsp; <?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?>
                    </div>
                    <div class="ov-topbar-right">
                        <?php if ($ps === 'verifying'): ?>
                            <span class="ov-badge pay-sub"><i class="fas fa-spinner fa-spin"></i> Verify Payment</span>
                        <?php elseif ($ps === 'confirmed'): ?>
                            <span class="ov-badge pay-ok"><i class="fas fa-check-circle"></i> Paid</span>
                        <?php else: ?>
                            <span class="ov-badge pay-wait"><i class="fas fa-clock"></i> Unpaid</span>
                        <?php endif; ?>
                        <span class="ov-badge <?php echo $os; ?>">
                            <i class="fas fa-<?php echo $os==='shipped'?'truck':($os==='delivered'?'box-open':($os==='cancelled'?'times-circle':'circle')); ?>"></i>
                            <?php echo $statusLabel[$os] ?? ucfirst($os); ?>
                        </span>
                    </div>
                </div>

                <?php if ($os !== 'cancelled'): ?>
                <div class="ov-tracker">
                    <?php $trackSteps = [['icon'=>'fa-shopping-cart','lbl'=>'Ordered'],['icon'=>'fa-check-circle','lbl'=>'Confirmed'],['icon'=>'fa-truck','lbl'=>'Shipped'],['icon'=>'fa-box-open','lbl'=>'Delivered']];
                    foreach ($trackSteps as $idx => $ts): $cls = $idx < $curStep ? 'done' : ($idx === $curStep ? 'current' : ''); ?>
                    <div class="ov-step <?php echo $cls; ?>">
                        <div class="ov-step-dot"><i class="fas <?php echo $ts['icon']; ?>" style="font-size:0.7rem;"></i></div>
                        <div class="ov-step-lbl"><?php echo $ts['lbl']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="ov-items">
                    <?php foreach ($items as $item): ?>
                    <div class="ov-item-row">
                        <div class="ov-item-thumb">
                            <?php if (!empty($item['image'])): ?><img src="<?php echo htmlspecialchars($item['image']); ?>" alt=""><?php else: ?><i class="fas fa-car-side"></i><?php endif; ?>
                        </div>
                        <div class="ov-item-details">
                            <div class="ov-item-name"><?php echo htmlspecialchars($item['title']); ?></div>
                            <?php if (!empty($item['category_name'])): ?><div class="ov-item-cat"><i class="fas fa-tag" style="font-size:0.6rem;"></i> <?php echo htmlspecialchars($item['category_name']); ?></div><?php endif; ?>
                        </div>
                        <div class="ov-item-price">
                            <div>Rs.<?php echo number_format($item['price'], 0); ?></div>
                            <?php if(floatval($item['shipping_fee'] ?? 0) > 0): ?>
                                <div style="font-size:0.65rem; color:var(--text-muted); font-weight:400; text-align:right;">+ Rs.<?php echo number_format($item['shipping_fee'] * ($item['quantity'] ?? 1), 0); ?> ship</div>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Buyer + Shipping -->
                <div class="ov-panels">
                    <div class="ov-panel">
                        <div class="ov-panel-lbl"><i class="fas fa-user"></i> Buyer</div>
                        <div class="ov-panel-val"><?php echo htmlspecialchars($order['buyer_name']); ?></div>
                    </div>
                    <?php if (!empty($order['tracking_id'])): ?>
                    <div class="ov-panel" style="border-color:rgba(171,71,188,0.2); background:rgba(171,71,188,0.04);">
                        <div class="ov-panel-lbl" style="color:#ab47bc;"><i class="fas fa-shipping-fast"></i> <?php echo !empty($order['courier']) ? htmlspecialchars($order['courier']) . ' Tracking' : 'Tracking Link'; ?></div>
                        <div class="ov-panel-val" style="color:#ab47bc; font-family:monospace;"><?php echo htmlspecialchars($order['tracking_id']); ?></div>
                    </div>
                    <?php elseif (!empty($order['estimated_delivery'])): ?>
                    <div class="ov-panel" style="border-color:rgba(79,195,247,0.2); background:rgba(79,195,247,0.04);">
                        <div class="ov-panel-lbl" style="color:#4fc3f7;"><i class="far fa-calendar-check"></i> ETA</div>
                        <div class="ov-panel-val" style="color:#4fc3f7;"><?php echo date('d M Y', strtotime($order['estimated_delivery'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['shipping_name'])): ?>
                    <div class="ov-panel" style="grid-column: span 2;">
                        <div class="ov-panel-lbl"><i class="fas fa-map-marker-alt"></i> Ship To</div>
                        <div class="ov-panel-val muted">
                            <?php echo htmlspecialchars($order['shipping_name']); ?> · <?php echo htmlspecialchars($order['shipping_address']); ?>,
                            <?php echo htmlspecialchars($order['shipping_city']); ?> <?php echo htmlspecialchars($order['shipping_pincode']); ?>
                            &nbsp;📞 <?php echo htmlspecialchars($order['shipping_phone']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ov-total-row" style="border-top: 1px solid var(--ov-border);">
                    <span class="ov-total-lbl">Order Total</span>
                    <span style="width:12px;height:1px;background:var(--ov-border);display:inline-block;margin:0 8px;"></span>
                    <span class="ov-total-val">Rs.<?php echo number_format($order['total'], 0); ?></span>
                </div>
                <div class="ov-card-footer">
                    <div class="ov-footer-meta">#<?php echo $order['id']; ?> · <?php echo date('d M Y', strtotime($order['created_at'])); ?></div>
                    <div class="ov-footer-actions">
                        <?php if(!in_array($os, ['pending', 'cancelled'])): ?>
                            <a href="dispute.php?order_id=<?php echo $order['id']; ?>" class="ov-footer-link danger"><i class="fas fa-exclamation-triangle"></i> Report</a>
                        <?php endif; ?>
                        <?php if($firstItem): ?>
                            <a href="negotiate.php?listing_id=<?php echo $firstItem['listing_id'] ?? 0; ?>" class="ov-footer-link"><i class="far fa-comment-dots"></i> Chat with Buyer</a>
                        <?php endif; ?>
                        <a href="seller_dashboard/orders.php" class="ov-footer-link" style="color:#81c784;"><i class="fas fa-cogs"></i> Manage</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.ov-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.ov-tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
}

function filterOrders(status, btn, panel) {
    btn.closest('.ov-filter-bar').querySelectorAll('.ov-filter-chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#panel-' + panel + ' .ov-card').forEach(card => {
        card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
    });
}

function copyText(btn, text) {
    navigator.clipboard?.writeText(text).then(() => showCopied(btn)).catch(() => fallbackCopy(btn, text));
}

function fallbackCopy(btn, text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta); showCopied(btn);
}

function showCopied(btn) {
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fas fa-check"></i> Copied';
    setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<i class="far fa-copy"></i> Copy'; }, 2000);
}

function openReviewModal(orderId, rating, text) {
    document.getElementById('review_order_id').value = orderId;
    document.getElementById('review_text').value = text;
    const star = document.getElementById('star' + rating);
    if (star) star.checked = true;
    document.getElementById('reviewModal').classList.add('active');
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('active');
}

function cancelOrder(orderId, btn) {
    if (!confirm('Are you sure you want to cancel this order? This will release the items back to the marketplace.')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';

    fetch('api/payment_timeout.php?action=cancel&ids=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
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
}
</script>

<!-- Review Modal -->
<div class="review-modal-overlay" id="reviewModal" onclick="if(event.target===this)closeReviewModal()">
    <div class="review-modal">
        <button type="button" class="rm-close" onclick="closeReviewModal()"><i class="fas fa-times"></i></button>
        <h3 style="margin-bottom:6px; font-size:1.2rem;">Rate Your Experience</h3>
        <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:22px;">Share your honest feedback about the seller's packaging, speed, and communication.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="submit_review" value="1">
            <input type="hidden" name="order_id" id="review_order_id" value="">
            <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); margin-bottom:8px;">Your Rating</div>
            <div class="star-rating-input">
                <input type="radio" id="star5" name="rating" value="5" checked><label for="star5"><i class="fas fa-star"></i></label>
                <input type="radio" id="star4" name="rating" value="4"><label for="star4"><i class="fas fa-star"></i></label>
                <input type="radio" id="star3" name="rating" value="3"><label for="star3"><i class="fas fa-star"></i></label>
                <input type="radio" id="star2" name="rating" value="2"><label for="star2"><i class="fas fa-star"></i></label>
                <input type="radio" id="star1" name="rating" value="1"><label for="star1"><i class="fas fa-star"></i></label>
            </div>
            <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); margin-bottom:8px;">Review (Optional)</div>
            <textarea name="review_text" id="review_text" rows="4" placeholder="Describe your experience — packaging, speed, communication..." style="width:100%; padding:12px; border-radius:10px; border:1px solid var(--ov-border); background:rgba(255,255,255,0.03); color:#fff; font-size:0.88rem; margin-bottom:14px; font-family:inherit; resize:vertical; box-sizing:border-box;"></textarea>
            
            <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); margin-bottom:8px;">Add Photo (Optional)</div>
            <input type="file" name="review_image" accept="image/*" style="width:100%; padding:10px; border-radius:10px; border:1px solid var(--ov-border); background:rgba(255,255,255,0.03); color:#fff; font-size:0.88rem; margin-bottom:20px; font-family:inherit; box-sizing:border-box; cursor: pointer;">

            <button type="submit" class="btn-red" style="width:100%; padding:12px; font-size:0.95rem;"><i class="fas fa-paper-plane"></i> Submit Review</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
