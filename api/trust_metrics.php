<?php
/**
 * REDLINE — Trust & Quality Metrics API
 * Returns JSON data for the admin Trust Metrics dashboard.
 * Used by Chart.js and AJAX calls on admin/trust_metrics.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// Admin guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {

    // ─── Rating Distribution (1-5 stars) ───
    case 'rating_distribution':
        $dist = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
        try {
            $rows = $conn->query("SELECT rating, COUNT(*) as cnt FROM seller_reviews GROUP BY rating ORDER BY rating")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $dist[intval($r['rating'])] = intval($r['cnt']); }
        } catch(PDOException $e) {}
        echo json_encode($dist);
        break;

    // ─── Rating Trend (last 12 months) ───
    case 'rating_trend':
        $labels = []; $avgRatings = []; $reviewCounts = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M Y', strtotime("-$i months"));
            try {
                $stmt = $conn->prepare("SELECT COALESCE(AVG(rating),0) as avg_r, COUNT(*) as cnt FROM seller_reviews WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
                $stmt->execute([$month]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $avgRatings[] = round(floatval($row['avg_r']), 2);
                $reviewCounts[] = intval($row['cnt']);
            } catch(PDOException $e) {
                $avgRatings[] = 0;
                $reviewCounts[] = 0;
            }
        }
        echo json_encode(['labels' => $labels, 'avgRatings' => $avgRatings, 'reviewCounts' => $reviewCounts]);
        break;

    // ─── Dispute Breakdown ───
    case 'dispute_breakdown':
        $types = []; $counts = [];
        try {
            $rows = $conn->query("SELECT type, COUNT(*) as cnt FROM order_disputes GROUP BY type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $types[] = ucfirst(str_replace('_', ' ', $r['type']));
                $counts[] = intval($r['cnt']);
            }
        } catch(PDOException $e) {}
        echo json_encode(['types' => $types, 'counts' => $counts]);
        break;

    // ─── Dispute Trend (last 12 months) ───
    case 'dispute_trend':
        $labels = []; $openCounts = []; $resolvedCounts = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M Y', strtotime("-$i months"));
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM order_disputes WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
                $stmt->execute([$month]);
                $openCounts[] = intval($stmt->fetchColumn());
                $stmt = $conn->prepare("SELECT COUNT(*) FROM order_disputes WHERE DATE_FORMAT(resolved_at, '%Y-%m') = ? AND status = 'resolved'");
                $stmt->execute([$month]);
                $resolvedCounts[] = intval($stmt->fetchColumn());
            } catch(PDOException $e) {
                $openCounts[] = 0;
                $resolvedCounts[] = 0;
            }
        }
        echo json_encode(['labels' => $labels, 'opened' => $openCounts, 'resolved' => $resolvedCounts]);
        break;

    // ─── NPS Score ───
    case 'nps_data':
        $promoters = 0; $passives = 0; $detractors = 0; $total = 0;
        try {
            $promoters = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score >= 9")->fetchColumn());
            $passives = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score >= 7 AND score <= 8")->fetchColumn());
            $detractors = intval($conn->query("SELECT COUNT(*) FROM csat_surveys WHERE score <= 6")->fetchColumn());
            $total = $promoters + $passives + $detractors;
        } catch(PDOException $e) {}
        $nps = $total > 0 ? round(($promoters - $detractors) / $total * 100) : 0;
        echo json_encode([
            'nps' => $nps, 'total' => $total,
            'promoters' => $promoters, 'passives' => $passives, 'detractors' => $detractors
        ]);
        break;

    // ─── Escrow Usage Trend ───
    case 'escrow_trend':
        $labels = []; $usagePcts = []; $amounts = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M Y', strtotime("-$i months"));
            try {
                $totalOrders = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
                $totalOrders->execute([$month]);
                $totalCount = intval($totalOrders->fetchColumn());

                $escrowOrders = $conn->prepare("SELECT COUNT(*) FROM escrow_deposits WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
                $escrowOrders->execute([$month]);
                $escrowCount = intval($escrowOrders->fetchColumn());

                $escrowAmount = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM escrow_deposits WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
                $escrowAmount->execute([$month]);
                $amt = floatval($escrowAmount->fetchColumn());

                $usagePcts[] = $totalCount > 0 ? round(($escrowCount / $totalCount) * 100, 1) : 0;
                $amounts[] = $amt;
            } catch(PDOException $e) {
                $usagePcts[] = 0;
                $amounts[] = 0;
            }
        }
        echo json_encode(['labels' => $labels, 'usagePcts' => $usagePcts, 'amounts' => $amounts]);
        break;

    // ─── Verification Funnel ───
    case 'verification_funnel':
        $data = ['total_sellers' => 0, 'applied' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'verified_ratio' => 0];
        try {
            $data['total_sellers'] = intval($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('seller','admin')")->fetchColumn());
            $data['applied'] = intval($conn->query("SELECT COUNT(DISTINCT user_id) FROM seller_applications")->fetchColumn());
            $data['pending'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'")->fetchColumn());
            $data['approved'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'approved'")->fetchColumn());
            $data['rejected'] = intval($conn->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'rejected'")->fetchColumn());
            $totalUsers = intval($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('seller','admin')")->fetchColumn());
            $verifiedUsers = intval($conn->query("SELECT COUNT(*) FROM users WHERE is_verified = 1 AND role IN ('seller','admin')")->fetchColumn());
            $data['verified_ratio'] = $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100, 1) : 0;
            $data['verified_count'] = $verifiedUsers;
        } catch(PDOException $e) {}
        echo json_encode($data);
        break;

    // ─── Low-Rated Sellers Alert ───
    case 'low_rated_sellers':
        $sellers = [];
        try {
            $sellers = $conn->query("
                SELECT u.id, u.name, u.email, u.avatar, 
                       COALESCE(AVG(sr.rating), 0) as avg_rating,
                       COUNT(sr.id) as review_count,
                       COUNT(CASE WHEN sr.rating <= 2 THEN 1 END) as negative_count
                FROM users u
                LEFT JOIN seller_reviews sr ON u.id = sr.seller_id
                WHERE u.role IN ('seller','admin')
                GROUP BY u.id
                HAVING review_count >= 1
                ORDER BY avg_rating ASC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {}
        echo json_encode($sellers);
        break;

    // ─── Recent Disputes ───
    case 'recent_disputes':
        $disputes = [];
        try {
            $disputes = $conn->query("
                SELECT d.*, o.total as order_total, 
                       u.name as reporter_name,
                       s.name as seller_name
                FROM order_disputes d
                LEFT JOIN orders o ON d.order_id = o.id
                LEFT JOIN users u ON d.reporter_id = u.id
                LEFT JOIN orders o2 ON d.order_id = o2.id
                LEFT JOIN users s ON o2.seller_id = s.id
                ORDER BY d.created_at DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {}
        echo json_encode($disputes);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
?>
