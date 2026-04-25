<?php
include 'header.php';

// ═══════════════════════════════════════════════════════════════════════
// DATA LAYER — All metrics pre-computed for server-side rendering
// ═══════════════════════════════════════════════════════════════════════

$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthAgo = date('Y-m-d', strtotime('-30 days'));

// ── 1. Active User Counts ──
$dau = ['buyers' => 0, 'sellers' => 0];
$wau = ['buyers' => 0, 'sellers' => 0];
$mau = ['buyers' => 0, 'sellers' => 0];
$totalUsers = 0; $totalBuyers = 0; $totalSellers = 0;
$newUsers30d = 0;
try {
    $totalUsers = intval($conn->query("SELECT COUNT(*) FROM users")->fetchColumn());
    $totalBuyers = intval($conn->query("SELECT COUNT(*) FROM users WHERE role = 'buyer'")->fetchColumn());
    $totalSellers = intval($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('seller','admin')")->fetchColumn());
    $newUsers30d = intval($conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn());

    // DAU
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) = ?");
    $stmt->execute([$today]); $dau['buyers'] = intval($stmt->fetchColumn());
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) = ?");
    $stmt->execute([$today]); $dau['sellers'] = intval($stmt->fetchColumn());

    // WAU
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) >= ?");
    $stmt->execute([$weekAgo]); $wau['buyers'] = intval($stmt->fetchColumn());
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) >= ?");
    $stmt->execute([$weekAgo]); $wau['sellers'] = intval($stmt->fetchColumn());

    // MAU
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) >= ?");
    $stmt->execute([$monthAgo]); $mau['buyers'] = intval($stmt->fetchColumn());
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) >= ?");
    $stmt->execute([$monthAgo]); $mau['sellers'] = intval($stmt->fetchColumn());
} catch (PDOException $e) {}

// ── 2. Negotiation Engagement ──
$negData = ['total' => 0, 'active' => 0, 'accepted' => 0, 'rejected' => 0, 'msgs' => 0, 'avg_msgs' => 0, 'conv_rate' => 0, 'resp_hrs' => 0];
try {
    $negData['total'] = intval($conn->query("SELECT COUNT(*) FROM negotiations")->fetchColumn());
    $negData['active'] = intval($conn->query("SELECT COUNT(*) FROM negotiations WHERE status = 'active'")->fetchColumn());
    $negData['accepted'] = intval($conn->query("SELECT COUNT(*) FROM negotiations WHERE status = 'accepted'")->fetchColumn());
    $negData['rejected'] = intval($conn->query("SELECT COUNT(*) FROM negotiations WHERE status = 'rejected'")->fetchColumn());
    $negData['msgs'] = intval($conn->query("SELECT COUNT(*) FROM negotiation_messages")->fetchColumn());
    $negData['avg_msgs'] = $negData['total'] > 0 ? round($negData['msgs'] / $negData['total'], 1) : 0;
    $negData['conv_rate'] = $negData['total'] > 0 ? round(($negData['accepted'] / $negData['total']) * 100, 1) : 0;

    // Avg seller response time (hours)
    try {
        $rt = $conn->query("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, bm.created_at, sm.created_at)) / 60
            FROM negotiation_messages bm
            INNER JOIN (
                SELECT negotiation_id, sender_id, created_at,
                       ROW_NUMBER() OVER(PARTITION BY negotiation_id, sender_id ORDER BY id) as rn
                FROM negotiation_messages
            ) sm ON sm.negotiation_id = bm.negotiation_id AND sm.created_at > bm.created_at
            INNER JOIN negotiations n ON bm.negotiation_id = n.id
            WHERE bm.sender_id = n.buyer_id AND sm.sender_id = n.seller_id AND sm.rn = 1
        ")->fetchColumn();
        $negData['resp_hrs'] = round(floatval($rt), 1);
    } catch (PDOException $e) {
        // Simpler fallback
        $negData['resp_hrs'] = 0;
    }
} catch (PDOException $e) {}

// ── 3. Order Metrics (AOV, Items per Order) ──
$orderData = ['total' => 0, 'revenue' => 0, 'aov' => 0, 'items_per_order' => 0];
try {
    $orderData['total'] = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status != 'cancelled'")->fetchColumn());
    $orderData['revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn());
    $orderData['aov'] = $orderData['total'] > 0 ? round($orderData['revenue'] / $orderData['total'], 0) : 0;
    $totalItems = intval($conn->query("SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON oi.order_id = o.id WHERE o.status != 'cancelled'")->fetchColumn());
    $orderData['items_per_order'] = $orderData['total'] > 0 ? round($totalItems / $orderData['total'], 1) : 0;
} catch (PDOException $e) {}

// AOV by category
$aovByCategory = [];
try {
    $aovByCategory = $conn->query("
        SELECT c.name as category, ROUND(AVG(oi.price),0) as avg_price, COUNT(oi.id) as items
        FROM order_items oi
        INNER JOIN listings l ON oi.listing_id = l.id
        INNER JOIN categories c ON l.category_id = c.id
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE o.status != 'cancelled'
        GROUP BY c.id ORDER BY avg_price DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// AOV by seller (top 5)
$aovBySeller = [];
try {
    $aovBySeller = $conn->query("
        SELECT u.name as seller, ROUND(AVG(o.total),0) as avg_order, COUNT(o.id) as orders
        FROM orders o INNER JOIN users u ON o.seller_id = u.id
        WHERE o.status != 'cancelled'
        GROUP BY o.seller_id ORDER BY avg_order DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ── 4. Conversion & Funnel ──
$funnelData = ['views' => 0, 'negs' => 0, 'accepted' => 0, 'orders' => 0, 'conv_rate' => 0];
try {
    $funnelData['views'] = intval($conn->query("SELECT COALESCE(SUM(views), 0) FROM listings")->fetchColumn());
    $funnelData['negs'] = intval($conn->query("SELECT COUNT(DISTINCT buyer_id) FROM negotiations")->fetchColumn());
    $funnelData['accepted'] = intval($conn->query("SELECT COUNT(DISTINCT buyer_id) FROM negotiations WHERE status = 'accepted'")->fetchColumn());
    $funnelData['orders'] = intval($conn->query("SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE status != 'cancelled'")->fetchColumn());
    $funnelData['conv_rate'] = $funnelData['views'] > 0 ? round(($funnelData['orders'] / $funnelData['views']) * 100, 2) : 0;
} catch (PDOException $e) {}

// ── 5. CLV & Repeat Buyers ──
$clvData = ['clv' => 0, 'repeat_buyers' => 0, 'repeat_rate' => 0, 'total_unique_buyers' => 0];
try {
    $clvData['total_unique_buyers'] = intval($conn->query("SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE status != 'cancelled'")->fetchColumn());
    $clvData['clv'] = $clvData['total_unique_buyers'] > 0 ? round($orderData['revenue'] / $clvData['total_unique_buyers'], 0) : 0;
    $clvData['repeat_buyers'] = intval($conn->query("SELECT COUNT(*) FROM (SELECT buyer_id FROM orders WHERE status != 'cancelled' GROUP BY buyer_id HAVING COUNT(*) > 1) t")->fetchColumn());
    $clvData['repeat_rate'] = $clvData['total_unique_buyers'] > 0 ? round(($clvData['repeat_buyers'] / $clvData['total_unique_buyers']) * 100, 1) : 0;
} catch (PDOException $e) {}

// ── 6. Signup Trend (30 days for chart) ──
$signupLabels = []; $signupBuyers = []; $signupSellers = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $signupLabels[] = date('M d', strtotime($d));
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'buyer' AND DATE(created_at) = ?");
        $stmt->execute([$d]); $signupBuyers[] = intval($stmt->fetchColumn());
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role IN ('seller','admin') AND DATE(created_at) = ?");
        $stmt->execute([$d]); $signupSellers[] = intval($stmt->fetchColumn());
    } catch (PDOException $e) {
        $signupBuyers[] = 0; $signupSellers[] = 0;
    }
}

// ── 7. Retention Cohort (6 months) ──
$cohorts = [];
for ($m = 5; $m >= 0; $m--) {
    $cs = date('Y-m-01', strtotime("-$m months"));
    $ce = date('Y-m-t', strtotime("-$m months"));
    $cl = date('M Y', strtotime("-$m months"));
    $ts = 0;
    try { $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) BETWEEN ? AND ?"); $stmt->execute([$cs, $ce]); $ts = intval($stmt->fetchColumn()); } catch (PDOException $e) {}
    $ret = [];
    for ($r = 0; $r < 6; $r++) {
        if ($m - $r < 0) { $ret[] = null; continue; }
        $rs = date('Y-m-01', strtotime("-" . ($m - $r) . " months"));
        $re = date('Y-m-t', strtotime("-" . ($m - $r) . " months"));
        try {
            $stmt = $conn->prepare("SELECT COUNT(DISTINCT o.buyer_id) FROM orders o INNER JOIN users u ON o.buyer_id = u.id WHERE DATE(u.created_at) BETWEEN ? AND ? AND DATE(o.created_at) BETWEEN ? AND ?");
            $stmt->execute([$cs, $ce, $rs, $re]);
            $act = intval($stmt->fetchColumn());
        } catch (PDOException $e) { $act = 0; }
        $ret[] = $ts > 0 ? round(($act / $ts) * 100, 1) : 0;
    }
    $cohorts[] = ['label' => $cl, 'signups' => $ts, 'retention' => $ret];
}

// ── 8. Message Trend (14 days) ──
$msgLabels = []; $msgCounts = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $msgLabels[] = date('M d', strtotime($d));
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM negotiation_messages WHERE DATE(created_at) = ?");
        $stmt->execute([$d]); $msgCounts[] = intval($stmt->fetchColumn());
    } catch (PDOException $e) { $msgCounts[] = 0; }
}

// ── 9. AOV Trend (8 months) ──
$aovLabels = []; $aovValues = [];
for ($i = 7; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $aovLabels[] = date('M', strtotime("-$i months"));
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'");
        $stmt->execute([$month]); $mOrd = intval($stmt->fetchColumn());
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'");
        $stmt->execute([$month]); $mRev = floatval($stmt->fetchColumn());
        $aovValues[] = $mOrd > 0 ? round($mRev / $mOrd, 0) : 0;
    } catch (PDOException $e) { $aovValues[] = 0; }
}
?>

<style>
/* ═══ Engagement Page Styles ═══ */
.eng-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
.eng-header h1 { font-family: 'Cinzel', serif; font-size: 1.5rem; font-weight: 800; }
.eng-header .subtitle { font-size: 0.82rem; color: var(--admin-muted); margin-top: 2px; }
.eng-header .ts { font-size: 0.75rem; color: var(--admin-muted); display: flex; align-items: center; gap: 6px; }

/* ═══ Hero Cards Grid ═══ */
.eng-heroes {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 28px;
}
.eng-hero {
    background: var(--admin-card-bg); border: 1px solid var(--admin-border); border-radius: 12px;
    padding: 18px 16px; position: relative; overflow: hidden; transition: border-color 0.2s, transform 0.2s;
}
.eng-hero:hover { border-color: rgba(255,255,255,0.1); transform: translateY(-2px); }
.eng-hero .eh-glow { position: absolute; top: -25px; right: -25px; width: 70px; height: 70px; border-radius: 50%; opacity: 0.07; pointer-events: none; }
.eng-hero .eh-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; margin-bottom: 12px; }
.eng-hero .eh-val { font-size: 1.65rem; font-weight: 800; line-height: 1; margin-bottom: 3px; }
.eng-hero .eh-label { font-size: 0.65rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.07em; }
.eng-hero .eh-sub { margin-top: 8px; font-size: 0.68rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 16px; }
.eh-sub.green { background: rgba(76,175,80,0.1); color: #81c784; }
.eh-sub.blue { background: rgba(66,165,245,0.1); color: #64b5f6; }
.eh-sub.orange { background: rgba(255,183,77,0.1); color: #ffb74d; }
.eh-sub.red { background: rgba(229,57,53,0.1); color: #e57373; }

/* Color variants */
.eh-cyan .eh-icon { background: rgba(0,188,212,0.15); color: #4dd0e1; }
.eh-cyan .eh-glow { background: #00bcd4; } .eh-cyan .eh-val { color: #4dd0e1; }
.eh-blue .eh-icon { background: rgba(66,165,245,0.15); color: #64b5f6; }
.eh-blue .eh-glow { background: #42a5f5; } .eh-blue .eh-val { color: #64b5f6; }
.eh-green .eh-icon { background: rgba(76,175,80,0.15); color: #81c784; }
.eh-green .eh-glow { background: #4caf50; } .eh-green .eh-val { color: #81c784; }
.eh-purple .eh-icon { background: rgba(171,71,188,0.15); color: #ce93d8; }
.eh-purple .eh-glow { background: #ab47bc; } .eh-purple .eh-val { color: #ce93d8; }
.eh-orange .eh-icon { background: rgba(255,183,77,0.15); color: #ffb74d; }
.eh-orange .eh-glow { background: #ffa726; } .eh-orange .eh-val { color: #ffb74d; }
.eh-red .eh-icon { background: rgba(229,57,53,0.15); color: #e57373; }
.eh-red .eh-glow { background: #e53935; } .eh-red .eh-val { color: #e57373; }

/* ═══ Section Grids ═══ */
.eng-section { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.eng-section.three-col { grid-template-columns: 1fr 1fr 1fr; }

/* ═══ Panels ═══ */
.eng-panel { background: var(--admin-card-bg); border: 1px solid var(--admin-border); border-radius: 14px; overflow: hidden; }
.eng-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--admin-border); font-weight: 700; font-size: 0.86rem; }
.eng-panel-body { padding: 20px; }
.eng-panel-body canvas { max-height: 240px; }

.eng-panel-header .badge-sm {
    font-size: 0.58rem; font-weight: 800; padding: 3px 10px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: 0.06em;
}

/* ═══ DAU/WAU/MAU Tabs ═══ */
.active-user-tabs { display: flex; gap: 10px; margin-bottom: 16px; }
.aut-tab {
    flex: 1; text-align: center; padding: 14px 8px; border-radius: 10px;
    background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); transition: all 0.2s;
}
.aut-tab:hover { border-color: rgba(255,255,255,0.08); }
.aut-tab .aut-val { font-size: 1.4rem; font-weight: 800; }
.aut-tab .aut-label { font-size: 0.6rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 4px; }
.aut-tab .aut-split { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 6px; font-size: 0.65rem; }
.aut-split .buyers { color: #64b5f6; } .aut-split .sellers { color: #81c784; }

/* ═══ Retention Cohort Heatmap ═══ */
.cohort-table { width: 100%; border-collapse: collapse; }
.cohort-table th { font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--admin-muted); padding: 8px; text-align: center; }
.cohort-table td { padding: 8px; text-align: center; font-size: 0.76rem; font-weight: 600; border-radius: 4px; }
.cohort-table .cohort-month { text-align: left; font-weight: 700; font-size: 0.78rem; white-space: nowrap; }
.cohort-table .cohort-count { color: var(--admin-muted); font-weight: 400; font-size: 0.72rem; }
.cohort-cell { transition: all 0.2s; cursor: default; }

/* ═══ Funnel Visual ═══ */
.funnel-visual { display: flex; flex-direction: column; gap: 0; }
.funnel-bar {
    display: flex; align-items: center; gap: 14px; padding: 12px 16px; position: relative;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.funnel-bar-fill {
    position: absolute; left: 0; top: 0; bottom: 0; z-index: 0; border-radius: 0 6px 6px 0; opacity: 0.08;
    transition: width 0.8s ease;
}
.funnel-bar-content { position: relative; z-index: 1; display: flex; align-items: center; gap: 14px; width: 100%; }
.fb-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; }
.fb-label { font-size: 0.82rem; font-weight: 500; flex: 1; }
.fb-value { font-size: 1.1rem; font-weight: 800; }
.fb-pct { font-size: 0.68rem; color: var(--admin-muted); min-width: 50px; text-align: right; }

/* ═══ Neg Status Dots ═══ */
.neg-status-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.ns-card { padding: 12px; border-radius: 10px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); text-align: center; }
.ns-card .ns-val { font-size: 1.3rem; font-weight: 800; }
.ns-card .ns-label { font-size: 0.6rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 3px; }

/* AOV Category List */
.aov-list { display: flex; flex-direction: column; gap: 6px; }
.aov-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); }
.aov-item .aov-rank { font-size: 0.7rem; font-weight: 800; color: var(--admin-muted); min-width: 20px; }
.aov-item .aov-name { flex: 1; font-size: 0.82rem; font-weight: 500; }
.aov-item .aov-val { font-size: 0.85rem; font-weight: 800; }
.aov-item .aov-count { font-size: 0.65rem; color: var(--admin-muted); margin-left: 6px; }

/* ═══ Mini Stats Row ═══ */
.mini-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.mini-stat { flex: 1; min-width: 60px; text-align: center; padding: 10px 6px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04); }
.mini-stat .mv { font-size: 1.05rem; font-weight: 800; }
.mini-stat .ml { font-size: 0.58rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

/* Empty State */
.eng-empty { text-align: center; padding: 40px 20px; color: var(--admin-muted); }
.eng-empty i { font-size: 2rem; opacity: 0.15; margin-bottom: 10px; display: block; }

/* ═══ Responsive ═══ */
@media (max-width: 1200px) {
    .eng-heroes { grid-template-columns: repeat(3, 1fr); }
    .eng-section.three-col { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 900px) {
    .eng-heroes { grid-template-columns: repeat(2, 1fr); }
    .eng-section { grid-template-columns: 1fr; }
    .eng-section.three-col { grid-template-columns: 1fr; }
}
@media (max-width: 600px) { .eng-heroes { grid-template-columns: 1fr; } }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE HEADER -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-header">
    <div>
        <h1><i class="fas fa-users" style="color: #4dd0e1; margin-right: 8px;"></i>User & Engagement</h1>
        <p class="subtitle">Active users, negotiation engagement, order metrics, conversion funnel & retention</p>
    </div>
    <div class="ts"><i class="fas fa-clock"></i> <?php echo date('M d, Y — h:i A'); ?></div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- HERO CARDS -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-heroes">
    <div class="eng-hero eh-cyan">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-users"></i></div>
        <div class="eh-val"><?php echo number_format($totalUsers); ?></div>
        <div class="eh-label">Total Users</div>
        <span class="eh-sub green"><i class="fas fa-user-plus"></i> +<?php echo $newUsers30d; ?> (30d)</span>
    </div>
    <div class="eng-hero eh-blue">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-comments"></i></div>
        <div class="eh-val"><?php echo number_format($negData['total']); ?></div>
        <div class="eh-label">Negotiations</div>
        <span class="eh-sub blue"><i class="fas fa-check"></i> <?php echo $negData['conv_rate']; ?>% convert</span>
    </div>
    <div class="eng-hero eh-green">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-exchange-alt"></i></div>
        <div class="eh-val"><?php echo number_format($negData['msgs']); ?></div>
        <div class="eh-label">Messages Exchanged</div>
        <span class="eh-sub green"><i class="fas fa-chart-line"></i> ~<?php echo $negData['avg_msgs']; ?>/chat</span>
    </div>
    <div class="eng-hero eh-purple">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-shopping-bag"></i></div>
        <div class="eh-val">Rs.<?php echo number_format($orderData['aov']); ?></div>
        <div class="eh-label">Avg Order Value</div>
        <span class="eh-sub blue"><i class="fas fa-box"></i> <?php echo $orderData['items_per_order']; ?> items/order</span>
    </div>
    <div class="eng-hero eh-orange">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-redo-alt"></i></div>
        <div class="eh-val"><?php echo $clvData['repeat_rate']; ?>%</div>
        <div class="eh-label">Repeat Buyer Rate</div>
        <span class="eh-sub <?php echo $clvData['repeat_rate'] >= 20 ? 'green' : 'orange'; ?>"><i class="fas fa-user-check"></i> <?php echo $clvData['repeat_buyers']; ?>/<?php echo $clvData['total_unique_buyers']; ?></span>
    </div>
    <div class="eng-hero eh-red">
        <div class="eh-glow"></div>
        <div class="eh-icon"><i class="fas fa-gem"></i></div>
        <div class="eh-val">Rs.<?php echo number_format($clvData['clv']); ?></div>
        <div class="eh-label">Customer Lifetime Value</div>
        <span class="eh-sub blue"><i class="fas fa-chart-bar"></i> per buyer avg</span>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 1: Active Users + Signup Trend -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-section">

    <!-- Active Users DAU/WAU/MAU -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-signal" style="margin-right:6px; color:#4dd0e1;"></i> Active Users</span>
            <span class="badge-sm" style="background:rgba(0,188,212,0.12); color:#4dd0e1;">LIVE</span>
        </div>
        <div class="eng-panel-body">
            <div class="active-user-tabs">
                <div class="aut-tab">
                    <div class="aut-val" style="color:#4dd0e1;"><?php echo $dau['buyers'] + $dau['sellers']; ?></div>
                    <div class="aut-label">DAU (Today)</div>
                    <div class="aut-split">
                        <span class="buyers"><i class="fas fa-shopping-bag"></i> <?php echo $dau['buyers']; ?></span>
                        <span class="sellers"><i class="fas fa-store"></i> <?php echo $dau['sellers']; ?></span>
                    </div>
                </div>
                <div class="aut-tab">
                    <div class="aut-val" style="color:#64b5f6;"><?php echo $wau['buyers'] + $wau['sellers']; ?></div>
                    <div class="aut-label">WAU (7 Days)</div>
                    <div class="aut-split">
                        <span class="buyers"><i class="fas fa-shopping-bag"></i> <?php echo $wau['buyers']; ?></span>
                        <span class="sellers"><i class="fas fa-store"></i> <?php echo $wau['sellers']; ?></span>
                    </div>
                </div>
                <div class="aut-tab">
                    <div class="aut-val" style="color:#ce93d8;"><?php echo $mau['buyers'] + $mau['sellers']; ?></div>
                    <div class="aut-label">MAU (30 Days)</div>
                    <div class="aut-split">
                        <span class="buyers"><i class="fas fa-shopping-bag"></i> <?php echo $mau['buyers']; ?></span>
                        <span class="sellers"><i class="fas fa-store"></i> <?php echo $mau['sellers']; ?></span>
                    </div>
                </div>
            </div>
            <div class="mini-row">
                <div class="mini-stat"><div class="mv" style="color:#64b5f6;"><?php echo $totalBuyers; ?></div><div class="ml">Total Buyers</div></div>
                <div class="mini-stat"><div class="mv" style="color:#81c784;"><?php echo $totalSellers; ?></div><div class="ml">Total Sellers</div></div>
                <div class="mini-stat"><div class="mv" style="color:#ffb74d;"><?php echo $totalBuyers > 0 ? round(($totalSellers / $totalBuyers) * 100, 0) : 0; ?>%</div><div class="ml">Seller Ratio</div></div>
            </div>
        </div>
    </div>

    <!-- Signup Trend Chart -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-user-plus" style="margin-right:6px; color:#81c784;"></i> Signup Trend (30 Days)</span>
        </div>
        <div class="eng-panel-body">
            <canvas id="signupTrendChart"></canvas>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 2: Negotiation Engagement + Message Trend -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-section">

    <!-- Negotiation Stats -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-handshake" style="margin-right:6px; color:#ffb74d;"></i> Negotiation Engagement</span>
            <span class="badge-sm" style="background:rgba(255,183,77,0.12); color:#ffb74d;"><?php echo $negData['total']; ?> TOTAL</span>
        </div>
        <div class="eng-panel-body">
            <div class="neg-status-grid">
                <div class="ns-card"><div class="ns-val" style="color:#81c784;"><?php echo $negData['accepted']; ?></div><div class="ns-label">Accepted</div></div>
                <div class="ns-card"><div class="ns-val" style="color:#ffb74d;"><?php echo $negData['active']; ?></div><div class="ns-label">Active</div></div>
                <div class="ns-card"><div class="ns-val" style="color:#e57373;"><?php echo $negData['rejected']; ?></div><div class="ns-label">Rejected</div></div>
                <div class="ns-card"><div class="ns-val" style="color:#4dd0e1;"><?php echo $negData['conv_rate']; ?>%</div><div class="ns-label">Neg → Sale</div></div>
            </div>
            <div class="mini-row">
                <div class="mini-stat"><div class="mv" style="color:#64b5f6;"><?php echo $negData['avg_msgs']; ?></div><div class="ml">Avg Msgs/Chat</div></div>
                <div class="mini-stat"><div class="mv" style="color:#ffb74d;"><?php echo $negData['resp_hrs']; ?>h</div><div class="ml">Seller Resp Time</div></div>
                <div class="mini-stat"><div class="mv" style="color:#ce93d8;"><?php echo number_format($negData['msgs']); ?></div><div class="ml">Total Messages</div></div>
            </div>
        </div>
    </div>

    <!-- Messages Trend -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-chart-area" style="margin-right:6px; color:#ce93d8;"></i> Message Activity (14 Days)</span>
        </div>
        <div class="eng-panel-body">
            <canvas id="messageTrendChart"></canvas>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 3: Conversion Funnel + AOV Trends -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-section">

    <!-- Conversion Funnel -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-filter" style="margin-right:6px; color:#e57373;"></i> Conversion Funnel</span>
            <span class="badge-sm" style="background:rgba(229,57,53,0.12); color:#e57373;"><?php echo $funnelData['conv_rate']; ?>% CONV</span>
        </div>
        <div class="eng-panel-body" style="padding: 0;">
            <?php
            $fSteps = [
                ['icon' => 'eye', 'label' => 'Listing Views', 'val' => $funnelData['views'], 'color' => '#64b5f6', 'pct' => 100],
                ['icon' => 'comments', 'label' => 'Buyers Negotiated', 'val' => $funnelData['negs'], 'color' => '#ffb74d',
                 'pct' => $funnelData['views'] > 0 ? round(($funnelData['negs'] / $funnelData['views']) * 100, 1) : 0],
                ['icon' => 'handshake', 'label' => 'Negotiations Accepted', 'val' => $funnelData['accepted'], 'color' => '#81c784',
                 'pct' => $funnelData['views'] > 0 ? round(($funnelData['accepted'] / $funnelData['views']) * 100, 1) : 0],
                ['icon' => 'shopping-cart', 'label' => 'Completed Orders', 'val' => $funnelData['orders'], 'color' => '#e57373',
                 'pct' => $funnelData['views'] > 0 ? round(($funnelData['orders'] / $funnelData['views']) * 100, 1) : 0],
            ];
            foreach ($fSteps as $idx => $fs):
            ?>
            <div class="funnel-bar">
                <div class="funnel-bar-fill" style="width: <?php echo $fs['pct']; ?>%; background: <?php echo $fs['color']; ?>;"></div>
                <div class="funnel-bar-content">
                    <div class="fb-icon" style="background: <?php echo $fs['color']; ?>22; color: <?php echo $fs['color']; ?>;">
                        <i class="fas fa-<?php echo $fs['icon']; ?>"></i>
                    </div>
                    <span class="fb-label"><?php echo $fs['label']; ?></span>
                    <span class="fb-value" style="color: <?php echo $fs['color']; ?>;"><?php echo number_format($fs['val']); ?></span>
                    <span class="fb-pct"><?php echo $idx === 0 ? '' : $fs['pct'] . '%'; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- AOV Trend Chart -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-rupee-sign" style="margin-right:6px; color:#81c784;"></i> AOV Trend</span>
            <span class="badge-sm" style="background:rgba(76,175,80,0.12); color:#81c784;">Rs.<?php echo number_format($orderData['aov']); ?> AVG</span>
        </div>
        <div class="eng-panel-body">
            <canvas id="aovTrendChart"></canvas>
            <div class="mini-row">
                <div class="mini-stat"><div class="mv" style="color:#81c784;"><?php echo number_format($orderData['total']); ?></div><div class="ml">Total Orders</div></div>
                <div class="mini-stat"><div class="mv" style="color:#64b5f6;">Rs.<?php echo number_format($orderData['revenue'], 0); ?></div><div class="ml">Total Revenue</div></div>
                <div class="mini-stat"><div class="mv" style="color:#ffb74d;"><?php echo $orderData['items_per_order']; ?></div><div class="ml">Items/Order</div></div>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 4: AOV by Category + AOV by Seller + Retention Cohort -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-section three-col">

    <!-- AOV by Category -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-tags" style="margin-right:6px; color:#64b5f6;"></i> AOV by Category</span>
        </div>
        <div class="eng-panel-body">
            <?php if (!empty($aovByCategory)): ?>
            <div class="aov-list">
                <?php foreach ($aovByCategory as $i => $cat): ?>
                <div class="aov-item">
                    <span class="aov-rank">#<?php echo $i + 1; ?></span>
                    <span class="aov-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                    <span class="aov-val" style="color:#64b5f6;">Rs.<?php echo number_format($cat['avg_price']); ?></span>
                    <span class="aov-count"><?php echo $cat['items']; ?> items</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="eng-empty"><i class="fas fa-tags"></i><p>No category data yet</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- AOV by Seller -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-store" style="margin-right:6px; color:#81c784;"></i> Top Sellers by AOV</span>
        </div>
        <div class="eng-panel-body">
            <?php if (!empty($aovBySeller)): ?>
            <div class="aov-list">
                <?php foreach ($aovBySeller as $i => $sel): ?>
                <div class="aov-item">
                    <span class="aov-rank">#<?php echo $i + 1; ?></span>
                    <span class="aov-name"><?php echo htmlspecialchars($sel['seller']); ?></span>
                    <span class="aov-val" style="color:#81c784;">Rs.<?php echo number_format($sel['avg_order']); ?></span>
                    <span class="aov-count"><?php echo $sel['orders']; ?> orders</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="eng-empty"><i class="fas fa-store"></i><p>No seller data yet</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Repeat Buyers & CLV -->
    <div class="eng-panel">
        <div class="eng-panel-header">
            <span><i class="fas fa-gem" style="margin-right:6px; color:#ce93d8;"></i> Customer Value</span>
        </div>
        <div class="eng-panel-body">
            <div style="text-align:center; padding: 10px 0;">
                <div style="font-size:2.2rem; font-weight:800; color:#ce93d8;">Rs.<?php echo number_format($clvData['clv']); ?></div>
                <div style="font-size:0.7rem; color:var(--admin-muted); text-transform:uppercase; letter-spacing:0.08em; margin-top:4px;">Customer Lifetime Value</div>
            </div>
            <div class="mini-row" style="margin-top:12px;">
                <div class="mini-stat"><div class="mv" style="color:#81c784;"><?php echo $clvData['total_unique_buyers']; ?></div><div class="ml">Unique Buyers</div></div>
                <div class="mini-stat"><div class="mv" style="color:#ffb74d;"><?php echo $clvData['repeat_buyers']; ?></div><div class="ml">Repeat Buyers</div></div>
            </div>
            <div style="margin-top: 12px; text-align:center;">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 16px; border-radius:20px; font-size:0.78rem; font-weight:700;
                    background: <?php echo $clvData['repeat_rate'] >= 20 ? 'rgba(76,175,80,0.1)' : 'rgba(255,183,77,0.1)'; ?>;
                    color: <?php echo $clvData['repeat_rate'] >= 20 ? '#81c784' : '#ffb74d'; ?>;">
                    <i class="fas fa-redo-alt"></i> <?php echo $clvData['repeat_rate']; ?>% Repeat Rate
                </span>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 5: Retention Cohort Heatmap (full width) -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="eng-panel" style="margin-bottom: 28px;">
    <div class="eng-panel-header">
        <span><i class="fas fa-th" style="margin-right:6px; color:#4dd0e1;"></i> Retention Cohort Analysis</span>
        <span class="badge-sm" style="background:rgba(0,188,212,0.12); color:#4dd0e1;">6-MONTH</span>
    </div>
    <div class="eng-panel-body" style="overflow-x:auto;">
        <table class="cohort-table">
            <thead>
                <tr>
                    <th style="text-align:left;">Cohort</th>
                    <th>Signups</th>
                    <th>Month 0</th>
                    <th>Month 1</th>
                    <th>Month 2</th>
                    <th>Month 3</th>
                    <th>Month 4</th>
                    <th>Month 5</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cohorts as $co): ?>
                <tr>
                    <td class="cohort-month"><?php echo $co['label']; ?></td>
                    <td class="cohort-count"><?php echo $co['signups']; ?></td>
                    <?php foreach ($co['retention'] as $rIdx => $rVal): ?>
                    <td class="cohort-cell" style="<?php
                        if ($rVal === null) {
                            echo 'color:var(--admin-muted); opacity: 0.3;';
                        } else {
                            // Color scale: green (high) → yellow → red (low)
                            $intensity = min(100, max(0, $rVal));
                            if ($intensity >= 30) {
                                $bg = 'rgba(76,175,80,' . ($intensity / 200) . ')';
                                $col = '#81c784';
                            } elseif ($intensity >= 10) {
                                $bg = 'rgba(255,183,77,' . ($intensity / 200) . ')';
                                $col = '#ffb74d';
                            } else {
                                $bg = 'rgba(229,57,53,' . max(0.05, $intensity / 200) . ')';
                                $col = '#e57373';
                            }
                            echo "background: $bg; color: $col;";
                        }
                    ?>"><?php echo $rVal !== null ? $rVal . '%' : '—'; ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:12px; font-size:0.72rem; color:var(--admin-muted); text-align:center;">
            <i class="fas fa-info-circle"></i> Each cell shows the % of users from that cohort who placed an order in the given month. Higher = better retention.
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- CHARTS INITIALIZATION -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    const chartFont = { family: "'Poppins', sans-serif", size: 10 };
    const gridColor = 'rgba(255,255,255,0.04)';

    // ── Signup Trend Chart ──
    new Chart(document.getElementById('signupTrendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(fn($l) => substr($l, 4), $signupLabels)); ?>,
            datasets: [
                {
                    label: 'Buyers',
                    data: <?php echo json_encode($signupBuyers); ?>,
                    borderColor: '#64b5f6',
                    backgroundColor: 'rgba(100,181,246,0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4
                },
                {
                    label: 'Sellers',
                    data: <?php echo json_encode($signupSellers); ?>,
                    borderColor: '#81c784',
                    backgroundColor: 'rgba(129,199,132,0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#888', font: chartFont, padding: 12 } },
                tooltip: { backgroundColor: '#1a1a22', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1 }
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont, maxTicksLimit: 10 } },
                y: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont }, beginAtZero: true }
            }
        }
    });

    // ── Message Trend Chart ──
    new Chart(document.getElementById('messageTrendChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(fn($l) => substr($l, 4), $msgLabels)); ?>,
            datasets: [{
                label: 'Messages',
                data: <?php echo json_encode($msgCounts); ?>,
                backgroundColor: 'rgba(206,147,216,0.3)',
                borderColor: '#ce93d8',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#1a1a22', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1 }
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont } },
                y: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont }, beginAtZero: true }
            }
        }
    });

    // ── AOV Trend Chart ──
    new Chart(document.getElementById('aovTrendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($aovLabels); ?>,
            datasets: [{
                label: 'Avg Order Value (Rs.)',
                data: <?php echo json_encode($aovValues); ?>,
                borderColor: '#81c784',
                backgroundColor: 'rgba(129,199,132,0.1)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#81c784',
                pointBorderColor: '#1a1a22',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#1a1a22', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1 }
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont } },
                y: { grid: { color: gridColor }, ticks: { color: '#666', font: chartFont, callback: v => 'Rs.' + v.toLocaleString() }, beginAtZero: true }
            }
        }
    });

});
</script>

<?php include 'footer.php'; ?>
