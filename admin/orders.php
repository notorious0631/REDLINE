<?php include 'header.php';

$success = '';
$error   = '';

// DEV ONLY — Delete order
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $oid = intval($_GET['id']);
    try {
        $conn->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$oid]);
        $conn->prepare("DELETE FROM orders WHERE id = ?")->execute([$oid]);
        $success = "Order #$oid has been deleted.";
    } catch (PDOException $e) {
        logError('admin_orders', 'Delete failed', $e);
        $error = "Delete failed. Please try again.";
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = "o.status = ?"; $params[] = $statusFilter; }
if ($search) { $where[] = "(u.name LIKE ? OR o.id = ?)"; $params[] = "%$search%"; $params[] = intval($search); }

$whereClause = implode(' AND ', $where);
$stmt = $conn->prepare("SELECT o.*, u.name AS buyer_name, u.email AS buyer_email FROM orders o LEFT JOIN users u ON o.buyer_id = u.id WHERE $whereClause ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);


?>

<div class="admin-page-header">
    <div><h1>Orders</h1><p class="page-subtitle"><?php echo count($orders); ?> total orders</p></div>
</div>

<?php if ($success): ?><div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="admin-alert danger" ><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>


<div class="admin-filters">
    <form style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="text" name="search" class="admin-input" placeholder="Search by ID or buyer..." value="<?php echo htmlspecialchars($search); ?>" style="width:200px;">
        <select name="status" class="admin-select" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
            <option value="confirmed" <?php echo $statusFilter==='confirmed'?'selected':''; ?>>Confirmed</option>
            <option value="shipped" <?php echo $statusFilter==='shipped'?'selected':''; ?>>Shipped</option>
            <option value="delivered" <?php echo $statusFilter==='delivered'?'selected':''; ?>>Delivered</option>
            <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Cancelled</option>
        </select>
        <button type="submit" class="btn-admin outline sm"><i class="fas fa-search"></i></button>
    </form>
</div>

<div class="admin-card">
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead><tr><th>Order #</th><th>Buyer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <?php
                    $stmt2 = $conn->prepare("SELECT oi.*, l.title FROM order_items oi JOIN listings l ON oi.listing_id = l.id WHERE oi.order_id = ?");
                    $stmt2->execute([$o['id']]);
                    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                ?>
            <tr>
                <td style="font-weight: 700;">#<?php echo $o['id']; ?></td>
                <td>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($o['buyer_name'] ?? 'Unknown'); ?></div>
                    <div style="font-size: 0.7rem; color: var(--admin-muted);"><?php echo htmlspecialchars($o['buyer_email'] ?? ''); ?></div>
                </td>
                <td>
                    <?php foreach ($items as $i): ?>
                        <div style="font-size: 0.78rem;"><?php echo htmlspecialchars($i['title']); ?> — Rs.<?php echo number_format($i['price'], 0); ?></div>
                    <?php endforeach; ?>
                </td>
                <td style="font-weight: 700;">Rs.<?php echo number_format($o['total'], 0); ?></td>
                <td style="font-size: 0.78rem; color: var(--admin-muted); text-transform: uppercase;"><?php echo $o['payment_method']; ?></td>
                <td><span class="badge-sm <?php echo $o['status']; ?>"><?php echo ucfirst($o['status']); ?></span></td>
                <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <a href="invoice.php?id=<?php echo $o['id']; ?>" class="btn-admin sm blue" title="View Invoice">
                        <i class="fas fa-file-invoice"></i> Invoice
                    </a>
                    <a href="?action=delete&id=<?php echo $o['id']; ?>&<?php echo http_build_query(array_diff_key($_GET, ['action'=>'','id'=>''])); ?>"
                       class="btn-admin sm" style="background:#dc3545;color:#fff;"
                       title="Delete Order"
                       onclick="return confirm('Delete Order #<?php echo $o['id']; ?>? This cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?><tr><td colspan="8" style="text-align:center;color:var(--admin-muted);padding:40px;">No orders found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Shipping Details Modal (expandable) -->
<style>
.shipping-toggle { cursor: pointer; color: var(--admin-accent); font-size: 0.78rem; }
</style>

<?php include 'footer.php'; ?>
