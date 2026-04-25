<?php
include 'header.php';

// ═══════════════════════════════════════════════════════════════════════
// TRUST & QUALITY METRICS — Data Layer
// ═══════════════════════════════════════════════════════════════════════

// 1. Verified Seller Ratio
$verifiedData = ['total' => 0, 'verified' => 0, 'ratio' => 0, 'pending_kyc' => 0, 'avg_verification_days' => 0];
try {
    $verifiedData['total'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('seller','admin')")->fetchColumn());
    $verifiedData['verified'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_verified = 1 AND role IN ('seller','admin')")->fetchColumn());
    $verifiedData['ratio'] = $verifiedData['total'] > 0 ? round(($verifiedData['verified'] / $verifiedData['total']) * 100, 1) : 0;
    $verifiedData['pending_kyc'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'")->fetchColumn());
    // Avg verification time (days between applied_at → updated_at for approved ones)
    $avgDays = $conn->query("SELECT COALESCE(AVG(DATEDIFF(updated_at, applied_at)), 0) FROM seller_applications WHERE status = 'approved'")->fetchColumn();
    $verifiedData['avg_verification_days'] = round(floatval($avgDays), 1);
} catch (PDOException $e) {}

// 2. Ratings & Reviews Aggregates
$ratingData = ['avg' => 0, 'total_reviews' => 0, 'positive_pct' => 0, 'negative_pct' => 0, 'sellers_below_3' => 0, 'new_reviews_30d' => 0];
try {
    $ratingData['avg'] = round(floatval($conn->query("SELECT COALESCE(AVG(rating), 0) FROM seller_reviews")->fetchColumn()), 2);
    $ratingData['total_reviews'] = intval($conn->query("SELECT COUNT(*) FROM seller_reviews")->fetchColumn());
    $ratingData['new_reviews_30d'] = intval($conn->query("SELECT COUNT(*) FROM seller_reviews WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn());
    $negativeCount = intval($conn->query("SELECT COUNT(*) FROM seller_reviews WHERE rating <= 2")->fetchColumn());
    $positiveCount = intval($conn->query("SELECT COUNT(*) FROM seller_reviews WHERE rating >= 4")->fetchColumn());
    $ratingData['positive_pct'] = $ratingData['total_reviews'] > 0 ? round(($positiveCount / $ratingData['total_reviews']) * 100, 1) : 0;
    $ratingData['negative_pct'] = $ratingData['total_reviews'] > 0 ? round(($negativeCount / $ratingData['total_reviews']) * 100, 1) : 0;
    $ratingData['sellers_below_3'] = intval($conn->query("SELECT COUNT(DISTINCT seller_id) FROM seller_reviews GROUP BY seller_id HAVING AVG(rating) < 3")->fetchColumn());
} catch (PDOException $e) {}

// 3. Dispute & Return Rates
$disputeData = ['total' => 0, 'open' => 0, 'resolved' => 0, 'dismissed' => 0, 'rate' => 0, 'cancelled_orders' => 0, 'return_rate' => 0];
try {
    $disputeData['total'] = intval($conn->query("SELECT COUNT(*) FROM order_disputes")->fetchColumn());
    $disputeData['open'] = intval($conn->query("SELECT COUNT(*) FROM order_disputes WHERE status IN ('open','investigating')")->fetchColumn());
    $disputeData['resolved'] = intval($conn->query("SELECT COUNT(*) FROM order_disputes WHERE status = 'resolved'")->fetchColumn());
    $disputeData['dismissed'] = intval($conn->query("SELECT COUNT(*) FROM order_disputes WHERE status = 'dismissed'")->fetchColumn());
    $totalOrders = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
    $disputeData['rate'] = $totalOrders > 0 ? round(($disputeData['total'] / $totalOrders) * 100, 1) : 0;
    $disputeData['cancelled_orders'] = intval($conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn());
    $disputeData['return_rate'] = $totalOrders > 0 ? round(($disputeData['cancelled_orders'] / $totalOrders) * 100, 1) : 0;
} catch (PDOException $e) {}

// 4. CSAT / NPS
$csatData = ['nps' => 0, 'total_responses' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'avg_score' => 0];
try {
    $csatData['total_responses'] = intval($conn->query("SELECT COUNT(*) FROM csat_surveys")->fetchColumn());
    $csatData['promoters'] = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score >= 9")->fetchColumn());
    $csatData['passives'] = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score BETWEEN 7 AND 8")->fetchColumn());
    $csatData['detractors'] = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score <= 6")->fetchColumn());
    $csatData['avg_score'] = round(floatval($conn->query("SELECT COALESCE(AVG(score), 0) FROM csat_surveys")->fetchColumn()), 1);
    $csatData['nps'] = $csatData['total_responses'] > 0
        ? round((($csatData['promoters'] - $csatData['detractors']) / $csatData['total_responses']) * 100)
        : 0;
} catch (PDOException $e) {}

// 5. Escrow / Deposit Usage
$escrowData = ['total_deposits' => 0, 'held' => 0, 'released' => 0, 'refunded' => 0, 'total_amount' => 0, 'usage_pct' => 0];
try {
    $escrowData['total_deposits'] = intval($conn->query("SELECT COUNT(*) FROM escrow_deposits")->fetchColumn());
    $escrowData['held'] = intval($conn->query("SELECT COUNT(*) FROM escrow_deposits WHERE status = 'held'")->fetchColumn());
    $escrowData['released'] = intval($conn->query("SELECT COUNT(*) FROM escrow_deposits WHERE status = 'released'")->fetchColumn());
    $escrowData['refunded'] = intval($conn->query("SELECT COUNT(*) FROM escrow_deposits WHERE status = 'refunded'")->fetchColumn());
    $escrowData['total_amount'] = floatval($conn->query("SELECT COALESCE(SUM(amount), 0) FROM escrow_deposits")->fetchColumn());
    $totalOrders = intval($conn->query("SELECT COUNT(*) FROM orders")->fetchColumn());
    $escrowData['usage_pct'] = $totalOrders > 0 ? round(($escrowData['total_deposits'] / $totalOrders) * 100, 1) : 0;
} catch (PDOException $e) {}

// 6. Recent Reviews
$recentReviews = [];
try {
    $recentReviews = $conn->query("
        SELECT sr.*, u.name as buyer_name, s.name as seller_name 
        FROM seller_reviews sr 
        LEFT JOIN users u ON sr.buyer_id = u.id 
        LEFT JOIN users s ON sr.seller_id = s.id 
        ORDER BY sr.created_at DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// 7. Recent Disputes
$recentDisputes = [];
try {
    $recentDisputes = $conn->query("
        SELECT d.*, u.name as reporter_name, o.total as order_total
        FROM order_disputes d 
        LEFT JOIN users u ON d.reporter_id = u.id 
        LEFT JOIN orders o ON d.order_id = o.id
        ORDER BY d.created_at DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// 8. KYC Applications Funnel
$kycFunnel = ['total_applied' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
try {
    $kycFunnel['total_applied'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM seller_applications")->fetchColumn());
    $kycFunnel['approved'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'approved'")->fetchColumn());
    $kycFunnel['pending'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'")->fetchColumn());
    $kycFunnel['rejected'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'rejected'")->fetchColumn());
} catch (PDOException $e) {}

// Rating distribution for the mini chart
$ratingDist = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
try {
    $rows = $conn->query("SELECT rating, COUNT(*) as cnt FROM seller_reviews GROUP BY rating")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $ratingDist[intval($r['rating'])] = intval($r['cnt']);
} catch (PDOException $e) {}

// Low-rated sellers
$lowRatedSellers = [];
try {
    $lowRatedSellers = $conn->query("
        SELECT u.id, u.name, u.email, u.avatar, u.is_verified,
               COALESCE(AVG(sr.rating), 0) as avg_rating,
               COUNT(sr.id) as review_count
        FROM users u
        LEFT JOIN seller_reviews sr ON u.id = sr.seller_id
        WHERE u.role IN ('seller','admin')
        GROUP BY u.id
        HAVING review_count >= 1
        ORDER BY avg_rating ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<!-- Trust Metrics Specific Styles -->
<style>
/* ═══ Trust Dashboard Layout ═══ */
.trust-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
.trust-header h1 { font-family: 'Cinzel', serif; font-size: 1.5rem; font-weight: 800; }
.trust-header .page-subtitle { font-size: 0.82rem; color: var(--admin-muted); margin-top: 2px; }
.trust-header .refresh-time { font-size: 0.75rem; color: var(--admin-muted); display: flex; align-items: center; gap: 6px; }

/* ═══ Metric Hero Cards (top row) ═══ */
.trust-hero-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}

.trust-hero-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 14px;
    padding: 22px 20px;
    position: relative;
    overflow: hidden;
    transition: border-color 0.2s, transform 0.2s;
}
.trust-hero-card:hover { border-color: rgba(255,255,255,0.12); transform: translateY(-2px); }

.trust-hero-card .thc-glow {
    position: absolute;
    top: -30px;
    right: -30px;
    width: 90px;
    height: 90px;
    border-radius: 50%;
    opacity: 0.08;
    pointer-events: none;
}

.trust-hero-card .thc-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; margin-bottom: 14px;
}

.trust-hero-card .thc-value {
    font-size: 1.9rem; font-weight: 800; line-height: 1; margin-bottom: 4px;
}
.trust-hero-card .thc-label { font-size: 0.72rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.08em; }
.trust-hero-card .thc-trend {
    margin-top: 10px; font-size: 0.7rem; font-weight: 600;
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 20px;
}
.thc-trend.positive { background: rgba(76,175,80,0.12); color: #81c784; }
.thc-trend.negative { background: rgba(229,57,53,0.12); color: #e57373; }
.thc-trend.neutral { background: rgba(255,183,77,0.12); color: #ffb74d; }

/* Color Variants */
.thc-green .thc-icon { background: rgba(76,175,80,0.15); color: #81c784; }
.thc-green .thc-glow { background: #4caf50; }
.thc-green .thc-value { color: #81c784; }

.thc-blue .thc-icon { background: rgba(66,165,245,0.15); color: #64b5f6; }
.thc-blue .thc-glow { background: #42a5f5; }
.thc-blue .thc-value { color: #64b5f6; }

.thc-red .thc-icon { background: rgba(229,57,53,0.15); color: #e57373; }
.thc-red .thc-glow { background: #e53935; }
.thc-red .thc-value { color: #e57373; }

.thc-purple .thc-icon { background: rgba(171,71,188,0.15); color: #ce93d8; }
.thc-purple .thc-glow { background: #ab47bc; }
.thc-purple .thc-value { color: #ce93d8; }

.thc-orange .thc-icon { background: rgba(255,183,77,0.15); color: #ffb74d; }
.thc-orange .thc-glow { background: #ffa726; }
.thc-orange .thc-value { color: #ffb74d; }

/* ═══ Section Panels ═══ */
.trust-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 28px;
}

.trust-section.three-col {
    grid-template-columns: 1fr 1fr 1fr;
}

.trust-panel {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 14px;
    overflow: hidden;
}

.trust-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--admin-border);
    font-weight: 700; font-size: 0.88rem;
}

.trust-panel-header .tph-badge {
    font-size: 0.6rem; font-weight: 800; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em;
}

.trust-panel-body { padding: 20px; }
.trust-panel-body canvas { max-height: 260px; }

/* ═══ Gauge / Ring Metric ═══ */
.gauge-container {
    display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0;
}
.gauge-ring {
    position: relative; width: 160px; height: 160px; margin-bottom: 12px;
}
.gauge-ring canvas { width: 100% !important; height: 100% !important; }
.gauge-center {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    text-align: center;
}
.gauge-center .gauge-val { font-size: 2rem; font-weight: 800; line-height: 1; }
.gauge-center .gauge-suffix { font-size: 0.8rem; color: var(--admin-muted); }
.gauge-label { font-size: 0.75rem; color: var(--admin-muted); text-align: center; text-transform: uppercase; letter-spacing: 0.08em; }

/* ═══ Mini Stat Row ═══ */
.mini-stat-row {
    display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px;
}
.mini-stat {
    flex: 1; min-width: 70px; text-align: center;
    padding: 10px 8px; border-radius: 8px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.04);
}
.mini-stat .ms-val { font-size: 1.1rem; font-weight: 800; }
.mini-stat .ms-label { font-size: 0.62rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

/* NPS gauge colors */
.nps-positive .gauge-val { color: #81c784; }
.nps-neutral .gauge-val { color: #ffb74d; }
.nps-negative .gauge-val { color: #e57373; }

/* ═══ Star Rating Display ═══ */
.star-display { display: inline-flex; align-items: center; gap: 2px; }
.star-display .star { color: #555; font-size: 0.75rem; }
.star-display .star.filled { color: #ffd700; }
.star-display .star.half { color: #ffd700; }

/* ═══ Review Card ═══ */
.review-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.review-item:last-child { border-bottom: none; }
.review-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--admin-accent); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 800;
}
.review-content { flex: 1; min-width: 0; }
.review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.review-buyer { font-weight: 600; font-size: 0.82rem; }
.review-seller-tag { font-size: 0.68rem; color: var(--admin-muted); }
.review-text { font-size: 0.8rem; color: var(--admin-muted); line-height: 1.4; margin-top: 4px; }
.review-date { font-size: 0.65rem; color: rgba(255,255,255,0.2); }

/* ═══ Dispute Item ═══ */
.dispute-item {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.dispute-item:last-child { border-bottom: none; }
.dispute-icon {
    width: 36px; height: 36px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 0.85rem;
}
.dispute-icon.open { background: rgba(255,183,77,0.12); color: #ffb74d; }
.dispute-icon.investigating { background: rgba(66,165,245,0.12); color: #64b5f6; }
.dispute-icon.resolved { background: rgba(76,175,80,0.12); color: #81c784; }
.dispute-icon.dismissed { background: rgba(136,136,136,0.12); color: #888; }
.dispute-info { flex: 1; min-width: 0; }
.dispute-type { font-weight: 600; font-size: 0.82rem; text-transform: capitalize; }
.dispute-meta { font-size: 0.72rem; color: var(--admin-muted); margin-top: 2px; }
.dispute-badge {
    font-size: 0.6rem; font-weight: 800; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em;
}

/* ═══ Rating Bar Visual ═══ */
.rating-bars { display: flex; flex-direction: column; gap: 8px; }
.rating-bar-row { display: flex; align-items: center; gap: 8px; }
.rb-star { font-size: 0.72rem; color: var(--admin-muted); min-width: 24px; text-align: right; font-weight: 600; }
.rb-bar-wrap { flex: 1; height: 8px; background: rgba(255,255,255,0.05); border-radius: 4px; overflow: hidden; }
.rb-bar-fill { height: 100%; border-radius: 4px; transition: width 0.6s ease; }
.rb-bar-fill.fill-5 { background: linear-gradient(90deg, #66bb6a, #81c784); }
.rb-bar-fill.fill-4 { background: linear-gradient(90deg, #aed581, #c5e1a5); }
.rb-bar-fill.fill-3 { background: linear-gradient(90deg, #ffd54f, #ffe082); }
.rb-bar-fill.fill-2 { background: linear-gradient(90deg, #ffb74d, #ffcc80); }
.rb-bar-fill.fill-1 { background: linear-gradient(90deg, #e57373, #ef9a9a); }
.rb-count { font-size: 0.7rem; color: var(--admin-muted); min-width: 28px; text-align: left; }

/* ═══ KYC Funnel ═══ */
.funnel-visual { display: flex; flex-direction: column; gap: 6px; }
.funnel-step {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; border-radius: 10px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.04);
    transition: background 0.2s;
}
.funnel-step:hover { background: rgba(255,255,255,0.04); }
.funnel-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.funnel-label { font-size: 0.82rem; font-weight: 500; flex: 1; }
.funnel-val { font-size: 0.95rem; font-weight: 800; }

/* ═══ Seller Trust Table ═══ */
.seller-trust-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.seller-trust-row:last-child { border-bottom: none; }
.str-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--admin-border); color: var(--admin-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 800;
}
.str-info { flex: 1; min-width: 0; }
.str-name { font-weight: 600; font-size: 0.82rem; display: flex; align-items: center; gap: 6px; }
.str-name .verified-icon { color: #64b5f6; font-size: 0.7rem; }
.str-meta { font-size: 0.68rem; color: var(--admin-muted); }
.str-rating { text-align: right; }
.str-rating-val { font-weight: 800; font-size: 0.95rem; }
.str-review-count { font-size: 0.65rem; color: var(--admin-muted); }

/* ═══ Escrow Stat Cards ═══ */
.escrow-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px; }
.es-card {
    text-align: center; padding: 14px 8px; border-radius: 10px;
    background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04);
}
.es-card .es-val { font-size: 1.2rem; font-weight: 800; }
.es-card .es-label { font-size: 0.6rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 3px; }

/* ═══ Alert Banner ═══ */
.trust-alert {
    padding: 14px 20px; border-radius: 10px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 12px;
    font-size: 0.85rem; font-weight: 500;
    animation: alertPulse 2s ease-in-out infinite;
}
.trust-alert.danger { background: rgba(229,57,53,0.08); border: 1px solid rgba(229,57,53,0.2); color: #e57373; }
.trust-alert.warning { background: rgba(255,183,77,0.08); border: 1px solid rgba(255,183,77,0.2); color: #ffb74d; }
.trust-alert i { font-size: 1.1rem; }

@keyframes alertPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.85; }
}

/* ═══ No Data State ═══ */
.trust-empty {
    text-align: center; padding: 40px 20px; color: var(--admin-muted);
}
.trust-empty i { font-size: 2rem; opacity: 0.2; margin-bottom: 10px; display: block; }
.trust-empty p { font-size: 0.82rem; }

/* ═══ Responsive ═══ */
@media (max-width: 1200px) {
    .trust-hero-grid { grid-template-columns: repeat(3, 1fr); }
    .trust-section.three-col { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 900px) {
    .trust-hero-grid { grid-template-columns: repeat(2, 1fr); }
    .trust-section { grid-template-columns: 1fr; }
    .trust-section.three-col { grid-template-columns: 1fr; }
    .escrow-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .trust-hero-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE HEADER -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="trust-header">
    <div>
        <h1><i class="fas fa-shield-alt" style="color: var(--admin-accent); margin-right: 8px;"></i>Trust & Quality Metrics</h1>
        <p class="page-subtitle">Monitor platform health, seller trust, buyer satisfaction & safety signals</p>
    </div>
    <div class="refresh-time">
        <i class="fas fa-clock"></i> Last updated: <?php echo date('M d, Y — h:i A'); ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ALERT BANNERS -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<?php if ($disputeData['open'] > 0): ?>
<div class="trust-alert danger">
    <i class="fas fa-exclamation-triangle"></i>
    <span><strong><?php echo $disputeData['open']; ?> open dispute<?php echo $disputeData['open'] > 1 ? 's' : ''; ?></strong> require attention — review and resolve flagged orders to maintain buyer trust.</span>
</div>
<?php endif; ?>

<?php if ($disputeData['rate'] > 5): ?>
<div class="trust-alert warning">
    <i class="fas fa-chart-line"></i>
    <span>Dispute rate is at <strong><?php echo $disputeData['rate']; ?>%</strong> — above the 5% threshold. Investigate root causes.</span>
</div>
<?php endif; ?>

<?php if ($verifiedData['pending_kyc'] >= 5): ?>
<div class="trust-alert warning">
    <i class="fas fa-id-card"></i>
    <span><strong><?php echo $verifiedData['pending_kyc']; ?> KYC applications</strong> awaiting review — process early to improve seller onboarding velocity.</span>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- HERO METRIC CARDS -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="trust-hero-grid">

    <!-- Verified Seller Ratio -->
    <div class="trust-hero-card thc-green">
        <div class="thc-glow"></div>
        <div class="thc-icon"><i class="fas fa-user-shield"></i></div>
        <div class="thc-value"><?php echo $verifiedData['ratio']; ?>%</div>
        <div class="thc-label">Verified Sellers</div>
        <span class="thc-trend <?php echo $verifiedData['ratio'] >= 70 ? 'positive' : ($verifiedData['ratio'] >= 40 ? 'neutral' : 'negative'); ?>">
            <i class="fas fa-<?php echo $verifiedData['ratio'] >= 50 ? 'arrow-up' : 'arrow-down'; ?>"></i>
            <?php echo $verifiedData['verified']; ?>/<?php echo $verifiedData['total']; ?> sellers
        </span>
    </div>

    <!-- Avg Rating -->
    <div class="trust-hero-card thc-blue">
        <div class="thc-glow"></div>
        <div class="thc-icon"><i class="fas fa-star"></i></div>
        <div class="thc-value"><?php echo $ratingData['avg'] ?: '—'; ?></div>
        <div class="thc-label">Avg Seller Rating</div>
        <span class="thc-trend <?php echo $ratingData['avg'] >= 4 ? 'positive' : ($ratingData['avg'] >= 3 ? 'neutral' : 'negative'); ?>">
            <i class="fas fa-comment-dots"></i> <?php echo $ratingData['total_reviews']; ?> reviews
        </span>
    </div>

    <!-- Dispute Rate -->
    <div class="trust-hero-card thc-red">
        <div class="thc-glow"></div>
        <div class="thc-icon"><i class="fas fa-flag"></i></div>
        <div class="thc-value"><?php echo $disputeData['rate']; ?>%</div>
        <div class="thc-label">Dispute Rate</div>
        <span class="thc-trend <?php echo $disputeData['rate'] <= 2 ? 'positive' : ($disputeData['rate'] <= 5 ? 'neutral' : 'negative'); ?>">
            <i class="fas fa-exclamation-circle"></i> <?php echo $disputeData['total']; ?> total
        </span>
    </div>

    <!-- NPS Score -->
    <div class="trust-hero-card thc-purple">
        <div class="thc-glow"></div>
        <div class="thc-icon"><i class="fas fa-heart"></i></div>
        <div class="thc-value"><?php echo $csatData['nps'] ?: '—'; ?></div>
        <div class="thc-label">NPS Score</div>
        <span class="thc-trend <?php echo $csatData['nps'] >= 50 ? 'positive' : ($csatData['nps'] >= 0 ? 'neutral' : 'negative'); ?>">
            <i class="fas fa-poll"></i> <?php echo $csatData['total_responses']; ?> responses
        </span>
    </div>

    <!-- Escrow Usage -->
    <div class="trust-hero-card thc-orange">
        <div class="thc-glow"></div>
        <div class="thc-icon"><i class="fas fa-lock"></i></div>
        <div class="thc-value"><?php echo $escrowData['usage_pct']; ?>%</div>
        <div class="thc-label">Escrow Usage</div>
        <span class="thc-trend <?php echo $escrowData['usage_pct'] >= 30 ? 'positive' : 'neutral'; ?>">
            <i class="fas fa-rupee-sign"></i> Rs.<?php echo number_format($escrowData['total_amount'], 0); ?>
        </span>
    </div>

</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 1: Verification Funnel + Rating Distribution + NPS Gauge -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="trust-section three-col">

    <!-- Verification Funnel -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-user-shield" style="margin-right:6px; color: var(--admin-green);"></i> Verification Funnel</span>
            <span class="tph-badge" style="background: rgba(76,175,80,0.12); color: #81c784;">SELLERS</span>
        </div>
        <div class="trust-panel-body">
            <div class="funnel-visual">
                <div class="funnel-step">
                    <div class="funnel-dot" style="background: #64b5f6;"></div>
                    <span class="funnel-label">Total Applied</span>
                    <span class="funnel-val" style="color: #64b5f6;"><?php echo $kycFunnel['total_applied']; ?></span>
                </div>
                <div class="funnel-step">
                    <div class="funnel-dot" style="background: #ffb74d;"></div>
                    <span class="funnel-label">Pending Review</span>
                    <span class="funnel-val" style="color: #ffb74d;"><?php echo $kycFunnel['pending']; ?></span>
                </div>
                <div class="funnel-step">
                    <div class="funnel-dot" style="background: #81c784;"></div>
                    <span class="funnel-label">Approved</span>
                    <span class="funnel-val" style="color: #81c784;"><?php echo $kycFunnel['approved']; ?></span>
                </div>
                <div class="funnel-step">
                    <div class="funnel-dot" style="background: #e57373;"></div>
                    <span class="funnel-label">Rejected</span>
                    <span class="funnel-val" style="color: #e57373;"><?php echo $kycFunnel['rejected']; ?></span>
                </div>
            </div>
            <div class="mini-stat-row">
                <div class="mini-stat">
                    <div class="ms-val" style="color:#81c784;"><?php echo $verifiedData['avg_verification_days']; ?>d</div>
                    <div class="ms-label">Avg Verify Time</div>
                </div>
                <div class="mini-stat">
                    <div class="ms-val" style="color:#64b5f6;"><?php echo $verifiedData['verified']; ?></div>
                    <div class="ms-label">Verified</div>
                </div>
                <div class="mini-stat">
                    <div class="ms-val" style="color:#e57373;"><?php echo $verifiedData['total'] - $verifiedData['verified']; ?></div>
                    <div class="ms-label">Unverified</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rating Distribution -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-star" style="margin-right:6px; color: #ffd700;"></i> Rating Distribution</span>
            <span class="tph-badge" style="background: rgba(255,215,0,0.12); color: #ffd700;"><?php echo $ratingData['total_reviews']; ?> REVIEWS</span>
        </div>
        <div class="trust-panel-body">
            <?php if ($ratingData['total_reviews'] > 0): ?>
                <div class="rating-bars">
                    <?php for ($i = 5; $i >= 1; $i--):
                        $pct = $ratingData['total_reviews'] > 0 ? round(($ratingDist[$i] / $ratingData['total_reviews']) * 100) : 0;
                    ?>
                    <div class="rating-bar-row">
                        <span class="rb-star"><?php echo $i; ?>★</span>
                        <div class="rb-bar-wrap">
                            <div class="rb-bar-fill fill-<?php echo $i; ?>" style="width: <?php echo $pct; ?>%;"></div>
                        </div>
                        <span class="rb-count"><?php echo $ratingDist[$i]; ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="mini-stat-row">
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#81c784;"><?php echo $ratingData['positive_pct']; ?>%</div>
                        <div class="ms-label">Positive (≥4★)</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#e57373;"><?php echo $ratingData['negative_pct']; ?>%</div>
                        <div class="ms-label">Negative (≤2★)</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#64b5f6;"><?php echo $ratingData['new_reviews_30d']; ?></div>
                        <div class="ms-label">Last 30 days</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-star"></i>
                    <p>No reviews yet. Reviews will appear as buyers rate sellers.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- NPS Gauge -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-heart" style="margin-right:6px; color: #ce93d8;"></i> NPS / Customer Satisfaction</span>
            <span class="tph-badge" style="background: rgba(171,71,188,0.12); color: #ce93d8;">CSAT</span>
        </div>
        <div class="trust-panel-body">
            <?php if ($csatData['total_responses'] > 0): ?>
                <div class="gauge-container <?php echo $csatData['nps'] >= 50 ? 'nps-positive' : ($csatData['nps'] >= 0 ? 'nps-neutral' : 'nps-negative'); ?>">
                    <div class="gauge-ring">
                        <canvas id="npsGaugeChart"></canvas>
                        <div class="gauge-center">
                            <div class="gauge-val"><?php echo $csatData['nps']; ?></div>
                            <div class="gauge-suffix">NPS</div>
                        </div>
                    </div>
                    <div class="gauge-label">
                        <?php
                        if ($csatData['nps'] >= 70) echo 'Excellent';
                        elseif ($csatData['nps'] >= 50) echo 'Great';
                        elseif ($csatData['nps'] >= 0) echo 'Needs Improvement';
                        else echo 'Critical';
                        ?>
                    </div>
                </div>
                <div class="mini-stat-row">
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#81c784;"><?php echo $csatData['promoters']; ?></div>
                        <div class="ms-label">Promoters</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#ffb74d;"><?php echo $csatData['passives']; ?></div>
                        <div class="ms-label">Passives</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#e57373;"><?php echo $csatData['detractors']; ?></div>
                        <div class="ms-label">Detractors</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-heart"></i>
                    <p>No survey responses yet. CSAT data will populate as customers provide feedback.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 2: Dispute Breakdown Chart + Dispute Trend -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="trust-section">

    <!-- Dispute Type Breakdown -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-flag" style="margin-right:6px; color: #e57373;"></i> Dispute Breakdown</span>
            <span class="tph-badge" style="background: rgba(229,57,53,0.12); color: #e57373;"><?php echo $disputeData['total']; ?> TOTAL</span>
        </div>
        <div class="trust-panel-body">
            <?php if ($disputeData['total'] > 0): ?>
                <canvas id="disputeBreakdownChart"></canvas>
                <div class="mini-stat-row">
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#ffb74d;"><?php echo $disputeData['open']; ?></div>
                        <div class="ms-label">Open</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#81c784;"><?php echo $disputeData['resolved']; ?></div>
                        <div class="ms-label">Resolved</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:var(--admin-muted);"><?php echo $disputeData['dismissed']; ?></div>
                        <div class="ms-label">Dismissed</div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-val" style="color:#e57373;"><?php echo $disputeData['return_rate']; ?>%</div>
                        <div class="ms-label">Cancel Rate</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-flag"></i>
                    <p>No disputes recorded yet. This is great!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Escrow / Deposit Panel -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-lock" style="margin-right:6px; color: #ffb74d;"></i> Escrow & Deposit Safety</span>
            <span class="tph-badge" style="background: rgba(255,183,77,0.12); color: #ffb74d;">SECURITY</span>
        </div>
        <div class="trust-panel-body">
            <?php if ($escrowData['total_deposits'] > 0): ?>
                <canvas id="escrowChart"></canvas>
                <div class="escrow-stats">
                    <div class="es-card">
                        <div class="es-val" style="color:#ffb74d;"><?php echo $escrowData['held']; ?></div>
                        <div class="es-label">Held</div>
                    </div>
                    <div class="es-card">
                        <div class="es-val" style="color:#81c784;"><?php echo $escrowData['released']; ?></div>
                        <div class="es-label">Released</div>
                    </div>
                    <div class="es-card">
                        <div class="es-val" style="color:#64b5f6;"><?php echo $escrowData['refunded']; ?></div>
                        <div class="es-label">Refunded</div>
                    </div>
                    <div class="es-card">
                        <div class="es-val" style="color:var(--admin-text);">Rs.<?php echo number_format($escrowData['total_amount'], 0); ?></div>
                        <div class="es-label">Total Value</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-lock"></i>
                    <p>No escrow deposits yet. As the deposit scheme is enabled, usage data will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ROW 3: Recent Reviews + Recent Disputes + Seller Trust Leaderboard -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="trust-section three-col">

    <!-- Recent Reviews -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-comment-alt" style="margin-right:6px; color: #64b5f6;"></i> Recent Reviews</span>
        </div>
        <div class="trust-panel-body" style="max-height: 380px; overflow-y: auto;">
            <?php if (!empty($recentReviews)): ?>
                <?php foreach ($recentReviews as $rev): ?>
                <div class="review-item">
                    <div class="review-avatar"><?php echo strtoupper(substr($rev['buyer_name'] ?? 'U', 0, 1)); ?></div>
                    <div class="review-content">
                        <div class="review-header">
                            <span class="review-buyer"><?php echo htmlspecialchars($rev['buyer_name'] ?? 'User'); ?></span>
                            <div class="star-display">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fas fa-star star <?php echo $s <= $rev['rating'] ? 'filled' : ''; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="review-seller-tag">→ <?php echo htmlspecialchars($rev['seller_name'] ?? 'Seller'); ?></div>
                        <?php if (!empty($rev['review_text'])): ?>
                            <div class="review-text"><?php echo htmlspecialchars(substr($rev['review_text'], 0, 120)); ?><?php echo strlen($rev['review_text']) > 120 ? '…' : ''; ?></div>
                        <?php endif; ?>
                        <div class="review-date"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-comment-alt"></i>
                    <p>No reviews yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Disputes -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-exclamation-triangle" style="margin-right:6px; color: #ffb74d;"></i> Recent Disputes</span>
        </div>
        <div class="trust-panel-body" style="max-height: 380px; overflow-y: auto;">
            <?php if (!empty($recentDisputes)): ?>
                <?php foreach ($recentDisputes as $d): ?>
                <div class="dispute-item">
                    <div class="dispute-icon <?php echo $d['status']; ?>">
                        <i class="fas fa-<?php
                            echo match($d['type']) {
                                'not_received' => 'box-open',
                                'wrong_item' => 'exchange-alt',
                                'damaged' => 'hammer',
                                'scam' => 'user-secret',
                                'counterfeit' => 'clone',
                                default => 'flag'
                            };
                        ?>"></i>
                    </div>
                    <div class="dispute-info">
                        <div class="dispute-type"><?php echo ucfirst(str_replace('_', ' ', $d['type'])); ?></div>
                        <div class="dispute-meta">
                            Order #<?php echo $d['order_id']; ?> · By <?php echo htmlspecialchars($d['reporter_name'] ?? 'User'); ?>
                            · <?php echo date('M d', strtotime($d['created_at'])); ?>
                        </div>
                    </div>
                    <span class="dispute-badge badge-sm <?php echo $d['status']; ?>"><?php echo ucfirst($d['status']); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-flag"></i>
                    <p>No disputes filed</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Seller Trust Leaderboard -->
    <div class="trust-panel">
        <div class="trust-panel-header">
            <span><i class="fas fa-trophy" style="margin-right:6px; color: #ffd700;"></i> Seller Trust Overview</span>
        </div>
        <div class="trust-panel-body" style="max-height: 380px; overflow-y: auto;">
            <?php if (!empty($lowRatedSellers)): ?>
                <?php foreach ($lowRatedSellers as $sl): ?>
                <div class="seller-trust-row">
                    <div class="str-avatar" <?php if(!empty($sl['avatar'])) echo 'style="background: url('.$sl['avatar'].') center/cover;"'; ?>>
                        <?php if(empty($sl['avatar'])) echo strtoupper(substr($sl['name'], 0, 1)); ?>
                    </div>
                    <div class="str-info">
                        <div class="str-name">
                            <?php echo htmlspecialchars($sl['name']); ?>
                            <?php if($sl['is_verified']): ?><i class="fas fa-check-circle verified-icon"></i><?php endif; ?>
                        </div>
                        <div class="str-meta"><?php echo $sl['review_count']; ?> review<?php echo $sl['review_count'] > 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="str-rating">
                        <div class="str-rating-val" style="color: <?php echo $sl['avg_rating'] >= 4 ? '#81c784' : ($sl['avg_rating'] >= 3 ? '#ffb74d' : '#e57373'); ?>;">
                            <?php echo number_format($sl['avg_rating'], 1); ?>★
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="trust-empty">
                    <i class="fas fa-trophy"></i>
                    <p>No reviewed sellers yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- CHARTS INITIALIZATION -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    const chartDefaults = {
        responsive: true,
        plugins: {
            legend: { labels: { color: '#888', padding: 12, font: { size: 11, family: "'Poppins', sans-serif" } } }
        }
    };

    // ─── NPS Gauge Doughnut ───
    <?php if ($csatData['total_responses'] > 0): ?>
    new Chart(document.getElementById('npsGaugeChart'), {
        type: 'doughnut',
        data: {
            labels: ['Promoters', 'Passives', 'Detractors'],
            datasets: [{
                data: [<?php echo $csatData['promoters']; ?>, <?php echo $csatData['passives']; ?>, <?php echo $csatData['detractors']; ?>],
                backgroundColor: ['rgba(129,199,132,0.8)', 'rgba(255,183,77,0.8)', 'rgba(229,115,115,0.8)'],
                borderWidth: 0,
                spacing: 2
            }]
        },
        options: {
            responsive: true,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1a22',
                    titleFont: { family: "'Poppins', sans-serif" },
                    bodyFont: { family: "'Poppins', sans-serif" },
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1
                }
            }
        }
    });
    <?php endif; ?>

    // ─── Dispute Breakdown Doughnut ───
    <?php if ($disputeData['total'] > 0): ?>
    fetch('../api/trust_metrics.php?action=dispute_breakdown')
        .then(r => r.json())
        .then(data => {
            if (data.types && data.types.length > 0) {
                const colors = ['#e57373', '#ffb74d', '#64b5f6', '#ce93d8', '#81c784', '#90a4ae'];
                new Chart(document.getElementById('disputeBreakdownChart'), {
                    type: 'doughnut',
                    data: {
                        labels: data.types,
                        datasets: [{
                            data: data.counts,
                            backgroundColor: colors.slice(0, data.types.length),
                            borderWidth: 0,
                            spacing: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#888', padding: 10, font: { size: 10, family: "'Poppins', sans-serif" } }
                            },
                            tooltip: {
                                backgroundColor: '#1a1a22',
                                borderColor: 'rgba(255,255,255,0.1)',
                                borderWidth: 1
                            }
                        }
                    }
                });
            }
        });
    <?php endif; ?>

    // ─── Escrow Status Chart ───
    <?php if ($escrowData['total_deposits'] > 0): ?>
    new Chart(document.getElementById('escrowChart'), {
        type: 'doughnut',
        data: {
            labels: ['Held', 'Released', 'Refunded', 'Disputed'],
            datasets: [{
                data: [
                    <?php echo $escrowData['held']; ?>,
                    <?php echo $escrowData['released']; ?>,
                    <?php echo $escrowData['refunded']; ?>,
                    <?php echo intval($conn->query("SELECT COUNT(*) FROM escrow_deposits WHERE status = 'disputed'")->fetchColumn()); ?>
                ],
                backgroundColor: ['rgba(255,183,77,0.8)', 'rgba(129,199,132,0.8)', 'rgba(100,181,246,0.8)', 'rgba(229,115,115,0.8)'],
                borderWidth: 0,
                spacing: 2
            }]
        },
        options: {
            responsive: true,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#888', padding: 10, font: { size: 10, family: "'Poppins', sans-serif" } }
                }
            }
        }
    });
    <?php endif; ?>

});
</script>

<?php include 'footer.php'; ?>
