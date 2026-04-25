<?php
/**
 * REDLINE — User & Engagement Metrics API
 * Returns JSON data for the admin engagement dashboard.
 * All metrics computed from live database.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

// ═══ Helpers ═══
function sf($conn, $sql, $p = []) {
    try { $s = $conn->prepare($sql); $s->execute($p); return floatval($s->fetchColumn()); } catch(PDOException $e) { return 0; }
}
function si($conn, $sql, $p = []) { return intval(sf($conn, $sql, $p)); }

switch ($action) {

    // ── Active Users (DAU / WAU / MAU by role) ──
    case 'active_users':
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $monthAgo = date('Y-m-d', strtotime('-30 days'));

        // Approximate "active" = placed order or sent message or logged in recently
        // Using orders + negotiations as proxy for activity
        $dauBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) = ?", [$today]);
        $dauSellers = si($conn, "SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) = ?", [$today]);
        $wauBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) >= ?", [$weekAgo]);
        $wauSellers = si($conn, "SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) >= ?", [$weekAgo]);
        $mauBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE DATE(created_at) >= ?", [$monthAgo]);
        $mauSellers = si($conn, "SELECT COUNT(DISTINCT seller_id) FROM orders WHERE DATE(created_at) >= ?", [$monthAgo]);

        // Also count negotiation-active users
        $dauNegBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM negotiations WHERE DATE(created_at) = ?", [$today]);
        $wauNegBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM negotiations WHERE DATE(created_at) >= ?", [$weekAgo]);
        $mauNegBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM negotiations WHERE DATE(created_at) >= ?", [$monthAgo]);

        echo json_encode([
            'dau' => ['buyers' => $dauBuyers + $dauNegBuyers, 'sellers' => $dauSellers],
            'wau' => ['buyers' => $wauBuyers + $wauNegBuyers, 'sellers' => $wauSellers],
            'mau' => ['buyers' => $mauBuyers + $mauNegBuyers, 'sellers' => $mauSellers],
        ]);
        break;

    // ── Signup Trend (last 30 days) ──
    case 'signup_trend':
        $labels = []; $buyers = []; $sellers = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('M d', strtotime($d));
            $buyers[] = si($conn, "SELECT COUNT(*) FROM users WHERE role = 'buyer' AND DATE(created_at) = ?", [$d]);
            $sellers[] = si($conn, "SELECT COUNT(*) FROM users WHERE role IN ('seller','admin') AND DATE(created_at) = ?", [$d]);
        }
        echo json_encode(['labels' => $labels, 'buyers' => $buyers, 'sellers' => $sellers]);
        break;

    // ── Retention Cohort Data ──
    case 'retention_cohorts':
        $cohorts = [];
        // Build month-over-month retention: for each signup month, what % placed orders in months 1,2,3,4,5,6
        for ($m = 5; $m >= 0; $m--) {
            $cohortStart = date('Y-m-01', strtotime("-$m months"));
            $cohortEnd = date('Y-m-t', strtotime("-$m months"));
            $cohortLabel = date('M Y', strtotime("-$m months"));

            $totalSignups = si($conn, "SELECT COUNT(*) FROM users WHERE DATE(created_at) BETWEEN ? AND ?", [$cohortStart, $cohortEnd]);
            if ($totalSignups === 0) {
                $cohorts[] = ['label' => $cohortLabel, 'signups' => 0, 'retention' => [0,0,0,0,0,0]];
                continue;
            }

            $retention = [];
            for ($r = 0; $r < 6; $r++) {
                if ($m - $r < 0) { $retention[] = null; continue; }
                $retStart = date('Y-m-01', strtotime("-" . ($m - $r) . " months"));
                $retEnd = date('Y-m-t', strtotime("-" . ($m - $r) . " months"));
                $activeInMonth = si($conn,
                    "SELECT COUNT(DISTINCT o.buyer_id) FROM orders o INNER JOIN users u ON o.buyer_id = u.id WHERE DATE(u.created_at) BETWEEN ? AND ? AND DATE(o.created_at) BETWEEN ? AND ?",
                    [$cohortStart, $cohortEnd, $retStart, $retEnd]
                );
                $retention[] = $totalSignups > 0 ? round(($activeInMonth / $totalSignups) * 100, 1) : 0;
            }

            $cohorts[] = ['label' => $cohortLabel, 'signups' => $totalSignups, 'retention' => $retention];
        }
        echo json_encode($cohorts);
        break;

    // ── Negotiation Engagement ──
    case 'negotiation_stats':
        $totalNegs = si($conn, "SELECT COUNT(*) FROM negotiations");
        $activeNegs = si($conn, "SELECT COUNT(*) FROM negotiations WHERE status = 'active'");
        $acceptedNegs = si($conn, "SELECT COUNT(*) FROM negotiations WHERE status = 'accepted'");
        $rejectedNegs = si($conn, "SELECT COUNT(*) FROM negotiations WHERE status = 'rejected'");
        $totalMessages = si($conn, "SELECT COUNT(*) FROM negotiation_messages");
        $avgMsgsPerNeg = $totalNegs > 0 ? round($totalMessages / $totalNegs, 1) : 0;
        $negToSaleRate = $totalNegs > 0 ? round(($acceptedNegs / $totalNegs) * 100, 1) : 0;

        // Seller response time (avg hours between buyer msg and seller reply)
        $avgResponseHrs = 0;
        try {
            $avgResponseHrs = round(sf($conn,
                "SELECT AVG(TIMESTAMPDIFF(HOUR, bm.created_at, sm.created_at))
                 FROM negotiation_messages bm
                 INNER JOIN negotiation_messages sm ON sm.negotiation_id = bm.negotiation_id AND sm.id > bm.id
                 INNER JOIN negotiations n ON bm.negotiation_id = n.id
                 WHERE bm.sender_id = n.buyer_id AND sm.sender_id = n.seller_id
                 LIMIT 1000"
            ), 1);
        } catch(PDOException $e) {}

        // Messages trend (last 14 days)
        $msgLabels = []; $msgCounts = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $msgLabels[] = date('M d', strtotime($d));
            $msgCounts[] = si($conn, "SELECT COUNT(*) FROM negotiation_messages WHERE DATE(created_at) = ?", [$d]);
        }

        echo json_encode([
            'total' => $totalNegs, 'active' => $activeNegs,
            'accepted' => $acceptedNegs, 'rejected' => $rejectedNegs,
            'total_messages' => $totalMessages,
            'avg_msgs_per_neg' => $avgMsgsPerNeg,
            'neg_to_sale_rate' => $negToSaleRate,
            'avg_response_hrs' => $avgResponseHrs,
            'msg_trend' => ['labels' => $msgLabels, 'counts' => $msgCounts]
        ]);
        break;

    // ── Order Size / AOV ──
    case 'order_metrics':
        $totalOrders = si($conn, "SELECT COUNT(*) FROM orders WHERE status != 'cancelled'");
        $totalRevenue = sf($conn, "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled'");
        $aov = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

        // Items per order
        $totalItems = si($conn, "SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON oi.order_id = o.id WHERE o.status != 'cancelled'");
        $itemsPerOrder = $totalOrders > 0 ? round($totalItems / $totalOrders, 1) : 0;

        // AOV by category
        $aovByCategory = [];
        try {
            $aovByCategory = $conn->query("
                SELECT c.name as category, ROUND(AVG(oi.price),0) as avg_price, COUNT(oi.id) as item_count
                FROM order_items oi
                INNER JOIN listings l ON oi.listing_id = l.id
                INNER JOIN categories c ON l.category_id = c.id
                INNER JOIN orders o ON oi.order_id = o.id
                WHERE o.status != 'cancelled'
                GROUP BY c.id
                ORDER BY avg_price DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {}

        // AOV by seller (top 5)
        $aovBySeller = [];
        try {
            $aovBySeller = $conn->query("
                SELECT u.name as seller, ROUND(AVG(o.total),0) as avg_order, COUNT(o.id) as order_count
                FROM orders o
                INNER JOIN users u ON o.seller_id = u.id
                WHERE o.status != 'cancelled'
                GROUP BY o.seller_id
                ORDER BY avg_order DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {}

        // AOV trend (last 12 months)
        $aovLabels = []; $aovValues = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $aovLabels[] = date('M Y', strtotime("-$i months"));
            $mOrders = si($conn, "SELECT COUNT(*) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'", [$month]);
            $mRev = sf($conn, "SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'", [$month]);
            $aovValues[] = $mOrders > 0 ? round($mRev / $mOrders, 0) : 0;
        }

        echo json_encode([
            'total_orders' => $totalOrders, 'total_revenue' => $totalRevenue,
            'aov' => $aov, 'items_per_order' => $itemsPerOrder,
            'aov_by_category' => $aovByCategory, 'aov_by_seller' => $aovBySeller,
            'aov_trend' => ['labels' => $aovLabels, 'values' => $aovValues]
        ]);
        break;

    // ── Conversion Funnel ──
    case 'conversion_funnel':
        $totalUsers = si($conn, "SELECT COUNT(*) FROM users");
        $totalListingViews = si($conn, "SELECT COALESCE(SUM(views), 0) FROM listings");
        $negsStarted = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM negotiations");
        $negAccepted = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM negotiations WHERE status = 'accepted'");
        $paidOrders = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE status != 'cancelled'");

        // Monthly acquisition
        $monthAcq = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $label = date('M Y', strtotime("-$i months"));
            $newBuyers = si($conn, "SELECT COUNT(*) FROM users WHERE role = 'buyer' AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
            $newSellers = si($conn, "SELECT COUNT(*) FROM users WHERE role IN ('seller','admin') AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
            $monthOrders = si($conn, "SELECT COUNT(*) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'", [$month]);
            $monthRev = sf($conn, "SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'", [$month]);
            $monthAcq[] = [
                'label' => $label,
                'new_buyers' => $newBuyers, 'new_sellers' => $newSellers,
                'orders' => $monthOrders, 'revenue' => $monthRev
            ];
        }

        // CLV estimate (avg revenue per buyer)
        $totalBuyers = si($conn, "SELECT COUNT(DISTINCT buyer_id) FROM orders WHERE status != 'cancelled'");
        $totalRev = sf($conn, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'cancelled'");
        $clv = $totalBuyers > 0 ? round($totalRev / $totalBuyers, 0) : 0;

        // Repeat buyer rate
        $repeatBuyers = si($conn, "SELECT COUNT(*) FROM (SELECT buyer_id FROM orders WHERE status != 'cancelled' GROUP BY buyer_id HAVING COUNT(*) > 1) t");
        $repeatRate = $totalBuyers > 0 ? round(($repeatBuyers / $totalBuyers) * 100, 1) : 0;

        echo json_encode([
            'total_users' => $totalUsers,
            'listing_views' => $totalListingViews,
            'negs_started' => $negsStarted,
            'neg_accepted' => $negAccepted,
            'paid_orders' => $paidOrders,
            'monthly_acquisition' => $monthAcq,
            'clv' => $clv,
            'repeat_buyer_rate' => $repeatRate,
            'total_buyers' => $totalBuyers,
            'repeat_buyers' => $repeatBuyers
        ]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
?>
