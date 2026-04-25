<?php include 'header.php';

// Fetch stats
$stats = [];
try {
    $stats['users'] = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['listings'] = $conn->query("SELECT COUNT(*) FROM listings")->fetchColumn();
    $stats['orders'] = $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $stats['revenue'] = $conn->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn();
    $stats['active_listings'] = $conn->query("SELECT COUNT(*) FROM listings WHERE status = 'active'")->fetchColumn();
    $stats['pending_orders'] = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $stats = ['users' => 0, 'listings' => 0, 'orders' => 0, 'revenue' => 0, 'active_listings' => 0, 'pending_orders' => 0];
}

// Revenue last 7 days
$revDays = []; $revAmounts = [];
try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $revDays[] = date('M d', strtotime($date));
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = ? AND status != 'cancelled'");
        $stmt->execute([$date]);
        $revAmounts[] = floatval($stmt->fetchColumn());
    }
} catch (PDOException $e) {
    $revDays = array_fill(0, 7, ''); $revAmounts = array_fill(0, 7, 0);
}

// Orders by status
$statusCounts = [];
try {
    $stmt = $conn->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$r['status']] = intval($r['cnt']);
    }
} catch (PDOException $e) {}

// Recent orders
$recentOrders = [];
try {
    $stmt = $conn->query("SELECT o.*, u.name AS buyer_name FROM orders o LEFT JOIN users u ON o.buyer_id = u.id ORDER BY o.created_at DESC LIMIT 5");
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Recent listings
$recentListings = [];
try {
    $stmt = $conn->query("SELECT l.*, u.name AS seller_name, c.name AS category_name FROM listings l LEFT JOIN users u ON l.seller_id = u.id LEFT JOIN categories c ON l.category_id = c.id ORDER BY l.created_at DESC LIMIT 5");
    $recentListings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<div class="admin-page-header">
    <div>
        <h1>Dashboard</h1>
        <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($adminName); ?>!</p>
    </div>
    <div style="font-size: 0.82rem; color: var(--admin-muted);">
        <i class="fas fa-clock"></i> <?php echo date('M d, Y — h:i A'); ?>
    </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div><div class="stat-value"><?php echo $stats['users']; ?></div><div class="stat-label">Total Users</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-box"></i></div>
        <div><div class="stat-value"><?php echo $stats['listings']; ?></div><div class="stat-label">Total Listings</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-receipt"></i></div>
        <div><div class="stat-value"><?php echo $stats['orders']; ?></div><div class="stat-label">Total Orders</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-rupee-sign"></i></div>
        <div><div class="stat-value">Rs.<?php echo number_format($stats['revenue'], 0); ?></div><div class="stat-label">Total Revenue</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-value"><?php echo $stats['active_listings']; ?></div><div class="stat-label">Active Listings</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="stat-value"><?php echo $stats['pending_orders']; ?></div><div class="stat-label">Pending Orders</div></div>
    </div>
</div>

<!-- Charts -->
<div class="chart-grid">
    <div class="admin-card chart-card">
        <div class="admin-card-header">Revenue (Last 7 Days)</div>
        <div class="admin-card-body"><canvas id="revenueChart"></canvas></div>
    </div>
    <div class="admin-card chart-card">
        <div class="admin-card-header">Orders by Status</div>
        <div class="admin-card-body"><canvas id="statusChart"></canvas></div>
    </div>
</div>

<!-- Recent Activity -->
<div class="chart-grid">
    <!-- Recent Orders -->
    <div class="admin-card">
        <div class="admin-card-header">Recent Orders <a href="orders.php" class="action-link" style="font-weight: 400;">View All →</a></div>
        <div style="overflow-x: auto;">
            <table class="admin-table">
                <thead><tr><th>#</th><th>Buyer</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentOrders as $o): ?>
                <tr>
                    <td><?php echo $o['id']; ?></td>
                    <td><?php echo htmlspecialchars($o['buyer_name'] ?? 'Unknown'); ?></td>
                    <td style="font-weight: 700;">Rs.<?php echo number_format($o['total'], 0); ?></td>
                    <td><span class="badge-sm <?php echo $o['status']; ?>"><?php echo ucfirst($o['status']); ?></span></td>
                    <td style="color: var(--admin-muted); font-size: 0.78rem;"><?php echo date('M d', strtotime($o['created_at'])); ?></td>
                    <td><a href="invoice.php?id=<?php echo $o['id']; ?>" class="action-link blue" title="Invoice"><i class="fas fa-file-invoice"></i></a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentOrders)): ?><tr><td colspan="6" style="text-align: center; color: var(--admin-muted);">No orders yet</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Recent Listings -->
    <div class="admin-card">
        <div class="admin-card-header">Recent Listings <a href="listings.php" class="action-link" style="font-weight: 400;">View All →</a></div>
        <div style="overflow-x: auto;">
            <table class="admin-table">
                <thead><tr><th>Title</th><th>Seller</th><th>Price</th></tr></thead>
                <tbody>
                <?php foreach ($recentListings as $l): ?>
                <tr>
                    <td style="font-weight: 600;"><?php echo htmlspecialchars($l['title']); ?></td>
                    <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo htmlspecialchars($l['seller_name'] ?? 'N/A'); ?></td>
                    <td style="font-weight: 700;">Rs.<?php echo number_format($l['price'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentListings)): ?><tr><td colspan="3" style="text-align: center; color: var(--admin-muted);">No listings yet</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Revenue Chart
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($revDays); ?>,
            datasets: [{
                label: 'Revenue (Rs.)',
                data: <?php echo json_encode($revAmounts); ?>,
                backgroundColor: 'rgba(229, 57, 53, 0.6)',
                borderColor: '#e53935',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#888' } },
                x: { grid: { display: false }, ticks: { color: '#888' } }
            }
        }
    });
    // Status Doughnut
    const statusData = <?php echo json_encode($statusCounts); ?>;
    const labels = Object.keys(statusData).map(s => s.charAt(0).toUpperCase() + s.slice(1));
    const colors = { pending: '#ffb74d', confirmed: '#64b5f6', shipped: '#ba68c8', delivered: '#81c784', cancelled: '#e57373' };
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: Object.values(statusData),
                backgroundColor: Object.keys(statusData).map(s => colors[s] || '#888'),
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            cutout: '65%',
            plugins: { legend: { position: 'bottom', labels: { color: '#888', padding: 12, font: { size: 11 } } } }
        }
    });
});
</script>

<?php include 'footer.php'; ?>
