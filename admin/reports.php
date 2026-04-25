<?php include 'header.php';

// Revenue stats
$rev = ['today'=>0,'week'=>0,'month'=>0,'all'=>0];
try {
    $rev['today'] = $conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'")->fetchColumn();
    $rev['week'] = $conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND status!='cancelled'")->fetchColumn();
    $rev['month'] = $conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND status!='cancelled'")->fetchColumn();
    $rev['all'] = $conn->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status!='cancelled'")->fetchColumn();
} catch(PDOException $e){}

// Top sellers
$topSellers = [];
try {
    $topSellers = $conn->query("SELECT u.name, COUNT(l.id) as cnt, COALESCE(SUM(l.price),0) as total FROM listings l JOIN users u ON l.seller_id=u.id GROUP BY l.seller_id ORDER BY total DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){}

// Top categories
$topCats = [];
try {
    $topCats = $conn->query("SELECT c.name, COUNT(l.id) as cnt FROM listings l JOIN categories c ON l.category_id=c.id GROUP BY l.category_id ORDER BY cnt DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){}

// Recent signups
$recentUsers = [];
try {
    $recentUsers = $conn->query("SELECT name,email,role,is_verified,created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){}

// Avg order value
$avgOrder = 0;
try {
    $avgOrder = $conn->query("SELECT COALESCE(AVG(total),0) FROM orders WHERE status!='cancelled'")->fetchColumn();
} catch(PDOException $e){}
?>

<div class="admin-page-header"><div><h1>Reports</h1><p class="page-subtitle">Analytics & insights</p></div></div>

<!-- Revenue -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-icon green"><i class="fas fa-calendar-day"></i></div><div><div class="stat-value">Rs.<?php echo number_format($rev['today'],0); ?></div><div class="stat-label">Today</div></div></div>
    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-calendar-week"></i></div><div><div class="stat-value">Rs.<?php echo number_format($rev['week'],0); ?></div><div class="stat-label">Last 7 Days</div></div></div>
    <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-calendar-alt"></i></div><div><div class="stat-value">Rs.<?php echo number_format($rev['month'],0); ?></div><div class="stat-label">Last 30 Days</div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fas fa-rupee-sign"></i></div><div><div class="stat-value">Rs.<?php echo number_format($rev['all'],0); ?></div><div class="stat-label">All Time</div></div></div>
    <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-calculator"></i></div><div><div class="stat-value">Rs.<?php echo number_format($avgOrder,0); ?></div><div class="stat-label">Avg. Order Value</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- Top Sellers -->
    <div class="admin-card">
        <div class="admin-card-header">Top Sellers by Revenue</div>
        <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead><tr><th>#</th><th>Seller</th><th>Listings</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach($topSellers as $i=>$s): ?>
                <tr>
                    <td><?php echo $i+1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo $s['cnt']; ?></td>
                    <td style="font-weight:700;">Rs.<?php echo number_format($s['total'],0); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($topSellers)): ?><tr><td colspan="4" style="text-align:center;color:var(--admin-muted);">No data</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Top Categories -->
    <div class="admin-card">
        <div class="admin-card-header">Top Categories by Listings</div>
        <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead><tr><th>#</th><th>Category</th><th>Listings</th></tr></thead>
                <tbody>
                <?php foreach($topCats as $i=>$c): ?>
                <tr>
                    <td><?php echo $i+1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td><?php echo $c['cnt']; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($topCats)): ?><tr><td colspan="3" style="text-align:center;color:var(--admin-muted);">No data</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Signups -->
<div class="admin-card" style="margin-top:20px;">
    <div class="admin-card-header">Recent Signups</div>
    <div style="overflow-x:auto;">
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Verified</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach($recentUsers as $u): ?>
            <tr>
                <td style="font-weight:600;"><?php echo htmlspecialchars($u['name']); ?></td>
                <td style="font-size:0.8rem;"><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="badge-sm <?php echo $u['role']; ?>"><?php echo ucfirst($u['role']); ?></span></td>
                <td><span class="badge-sm <?php echo $u['is_verified']?'verified':'unverified'; ?>"><?php echo $u['is_verified']?'Yes':'No'; ?></span></td>
                <td style="font-size:0.78rem;color:var(--admin-muted);"><?php echo date('M d, Y',strtotime($u['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
