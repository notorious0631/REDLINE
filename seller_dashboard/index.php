<?php
$pageTitle = 'Seller Dashboard Overview';
include 'header.php';

$sellerId = $_SESSION['user_id'];

// Fetch Analytics
$stats = [
    'active_listings' => 0,
    'total_sales' => 0,
    'total_revenue' => 0,
    'pending_orders' => 0
];

try {
    // Active Listings
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'active'");
    $stmt->execute([$sellerId]);
    $stats['active_listings'] = $stmt->fetchColumn();
    
    // Total Sold Items
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'sold'");
    $stmt->execute([$sellerId]);
    $stats['total_sales'] = $stmt->fetchColumn();
    
    // Total Revenue (Sum of all order items sold by this seller where order might be completed/processing)
    // For simplicity, we sum the price of order_items linked to this seller's listings.
    $stmt = $conn->prepare("
        SELECT SUM(price) FROM order_items 
        WHERE listing_id IN (SELECT id FROM listings WHERE seller_id = ?)
    ");
    $stmt->execute([$sellerId]);
    $stats['total_revenue'] = $stmt->fetchColumn() ?: 0;
    
    // Pending Fulfillment 
    // Usually means orders that contain their items and haven't been marked shipped by them, 
    // but in our redline schema, orders are global. We'll just count how many times their items were ordered recently.
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE oi.listing_id IN (SELECT id FROM listings WHERE seller_id = ?)
        AND o.status IN ('pending', 'processing')
    ");
    $stmt->execute([$sellerId]);
    $stats['pending_orders'] = $stmt->fetchColumn();
    
} catch(PDOException $e) {}

// Fetch recent sales
$recentSales = [];
try {
    $stmt = $conn->prepare("
        SELECT o.id as order_id, o.created_at, l.title, oi.price
        FROM order_items oi
        JOIN listings l ON oi.listing_id = l.id
        JOIN orders o ON oi.order_id = o.id
        WHERE l.seller_id = ?
        ORDER BY o.created_at DESC LIMIT 5
    ");
    $stmt->execute([$sellerId]);
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

?>

<div class="page-header">
    <div class="page-title">
        <h1>Dashboard</h1>
        <p>Your business overview at a glance</p>
    </div>
    <a href="add_listing.php" class="btn-primary"><i class="fas fa-plus"></i> New Product</a>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(229,57,53,0.1); color: var(--accent-red);"><i class="fas fa-rupee-sign"></i></div>
        <div class="stat-info">
            <p>Total Revenue</p>
            <h3>Rs. <?php echo number_format($stats['total_revenue'], 0); ?></h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: var(--accent-green);"><i class="fas fa-box-open"></i></div>
        <div class="stat-info">
            <p>Items Sold</p>
            <h3><?php echo number_format($stats['total_sales']); ?></h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245,158,11,0.1); color: var(--accent-orange);"><i class="fas fa-tags"></i></div>
        <div class="stat-info">
            <p>Active Inventory</p>
            <h3><?php echo number_format($stats['active_listings']); ?></h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: var(--accent-blue);"><i class="fas fa-truck"></i></div>
        <div class="stat-info">
            <p>Pending Orders</p>
            <h3><?php echo number_format($stats['pending_orders']); ?></h3>
        </div>
    </div>
</div>

<div class="content-grid">
    
    <!-- Left Column: Recent Sales -->
    <div class="panel">
        <div class="panel-header">
            <h3 style="margin:0;">Recent Sales</h3>
            <a href="orders.php" style="color:var(--text-secondary); text-decoration:none; font-size:0.85rem; font-weight:500;">View All &rarr;</a>
        </div>
        
        <?php if(empty($recentSales)): ?>
            <div style="text-align:center; padding:40px 0; color:var(--text-muted);">
                <i class="fas fa-receipt" style="font-size:3rem; opacity:0.15; margin-bottom:15px; display:block;"></i>
                <p>No sales yet. Try adding more inventory!</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="seller-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Item</th>
                            <th>Date</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recentSales as $sale): ?>
                        <tr>
                            <td style="color:var(--text-muted);">#<?php echo $sale['order_id']; ?></td>
                            <td style="font-weight:500; color:#fff;"><?php echo htmlspecialchars($sale['title']); ?></td>
                            <td style="color:var(--text-secondary);"><?php echo date('M d', strtotime($sale['created_at'])); ?></td>
                            <td style="color:var(--accent-red); font-weight:600;">Rs.<?php echo number_format($sale['price'], 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Right Column: Action Center -->
    <div class="panel">
        <div class="panel-header">
            <h3 style="margin:0;">Action Center</h3>
        </div>
        <div style="display:flex; flex-direction:column; gap:16px;">
            <?php if($stats['pending_orders'] > 0): ?>
            <div style="padding:16px; background:rgba(245,158,11,0.05); border:1px solid rgba(245,158,11,0.2); border-radius:12px; display:flex; gap:12px; align-items:flex-start;">
                <i class="fas fa-exclamation-circle" style="color:var(--accent-orange); margin-top:2px;"></i>
                <div>
                    <h4 style="font-size:0.95rem; margin-bottom:4px;">Pending Shipments</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:8px;">You have <?php echo $stats['pending_orders']; ?> orders waiting to be fulfilled.</p>
                    <a href="orders.php" style="font-size:0.8rem; font-weight:600; color:var(--accent-orange); text-decoration:none;">View Orders</a>
                </div>
            </div>
            <?php else: ?>
            <div style="padding:16px; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); border-radius:12px; display:flex; gap:12px; align-items:flex-start;">
                <i class="fas fa-check-circle" style="color:var(--accent-green); margin-top:2px;"></i>
                <div>
                    <h4 style="font-size:0.95rem; margin-bottom:4px;">All caught up</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary);">You have no pending orders to fulfill.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="padding:16px; background:var(--bg-hover); border:1px solid var(--border-color); border-radius:12px; display:flex; gap:12px; align-items:flex-start;">
                <i class="fas fa-store" style="color:var(--accent-blue); margin-top:2px;"></i>
                <div>
                    <h4 style="font-size:0.95rem; margin-bottom:4px;">Store Branding</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:8px;">Custom banners increase buyer trust.</p>
                    <a href="storefront.php" style="font-size:0.8rem; font-weight:600; color:var(--accent-blue); text-decoration:none;">Update Storefront</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
