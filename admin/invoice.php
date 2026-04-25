<?php include 'header.php';

$orderId = intval($_GET['id'] ?? 0);

// If no order ID, show invoice listing
if ($orderId <= 0) {
    $allOrders = [];
    try {
        $allOrders = $conn->query("SELECT o.id, o.total, o.status, o.created_at, u.name AS buyer_name FROM orders o LEFT JOIN users u ON o.buyer_id = u.id ORDER BY o.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
?>

<div class="admin-page-header">
    <div><h1>Invoices</h1><p class="page-subtitle"><?php echo count($allOrders); ?> invoices generated</p></div>
</div>

<div class="admin-card">
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead><tr><th>Invoice #</th><th>Buyer</th><th>Total</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($allOrders as $o): ?>
            <tr>
                <td style="font-weight: 700;">INV-<?php echo str_pad($o['id'], 5, '0', STR_PAD_LEFT); ?></td>
                <td><?php echo htmlspecialchars($o['buyer_name'] ?? 'Unknown'); ?></td>
                <td style="font-weight: 700;">Rs.<?php echo number_format($o['total'], 0); ?></td>
                <td><span class="badge-sm <?php echo $o['status']; ?>"><?php echo ucfirst($o['status']); ?></span></td>
                <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                <td>
                    <a href="invoice.php?id=<?php echo $o['id']; ?>" class="btn-admin sm blue"><i class="fas fa-eye"></i> View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($allOrders)): ?><tr><td colspan="6" style="text-align:center;color:var(--admin-muted);padding:40px;">No orders yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; exit; } // end invoice listing

// Fetch order
try {
    $stmt = $conn->prepare("SELECT o.*, u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone FROM orders o LEFT JOIN users u ON o.buyer_id = u.id WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $order = null; }

if (!$order) {
    echo '<div class="admin-page-header"><div><h1>Invoice Not Found</h1><p class="page-subtitle"><a href="orders.php">← Back to Orders</a></p></div></div>';
    include 'footer.php'; exit;
}

// Fetch order items
$items = [];
try {
    $stmt = $conn->prepare("SELECT oi.*, l.title, l.image, c.name AS category_name FROM order_items oi LEFT JOIN listings l ON oi.listing_id = l.id LEFT JOIN categories c ON l.category_id = c.id WHERE oi.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch site settings for invoice header
$siteSettings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM admin_settings");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $siteSettings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {}

$invComp = $siteSettings['invoice_company_name'] ?? '';
$siteName = !empty($invComp) ? $invComp : ($siteSettings['site_name'] ?? 'REDLINE');
$invAddress = $siteSettings['invoice_address'] ?? '';
$invTaxId = $siteSettings['invoice_tax_id'] ?? '';
$invFooter = $siteSettings['invoice_footer_notes'] ?? 'Thank you for shopping at REDLINE!';

$contactEmail = $siteSettings['contact_email'] ?? '';
$contactPhone = $siteSettings['contact_phone'] ?? '';

$prefix = $siteSettings['invoice_prefix'] ?? 'INV-';
if(empty($prefix)) $prefix = 'INV-';
$invoiceNumber = $prefix . str_pad($order['id'], 5, '0', STR_PAD_LEFT);
$invoiceDate = date('d M Y', strtotime($order['created_at']));

$subtotal = 0;
foreach ($items as $item) { $subtotal += $item['price']; }
?>

<style>
/* Invoice Container */
.invoice-wrapper {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    color: #1a1a2e;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,0.3);
}

/* Invoice Header */
.invoice-top {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: #fff;
    padding: 36px 40px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.invoice-brand {
    display: flex;
    align-items: center;
    gap: 14px;
}

.invoice-brand img {
    width: 44px;
    height: 44px;
    border-radius: 8px;
}

.invoice-brand h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.6rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    margin: 0;
}

.invoice-brand small {
    display: block;
    font-size: 0.7rem;
    color: rgba(255,255,255,0.5);
    font-weight: 400;
    letter-spacing: 0.1em;
    margin-top: 2px;
}

.invoice-meta {
    text-align: right;
}

.invoice-meta .inv-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: rgba(255,255,255,0.5);
    margin-bottom: 4px;
}

.invoice-meta .inv-number {
    font-size: 1.3rem;
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: #e53935;
}

.invoice-meta .inv-date {
    font-size: 0.82rem;
    color: rgba(255,255,255,0.7);
    margin-top: 4px;
}

/* Invoice Body */
.invoice-body {
    padding: 36px 40px;
}

/* Info Grid (Bill To + Order Info) */
.invoice-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-bottom: 36px;
}

.invoice-info-block h3 {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #888;
    margin-bottom: 10px;
    font-weight: 700;
}

.invoice-info-block p {
    margin: 0 0 4px;
    font-size: 0.88rem;
    color: #333;
    line-height: 1.6;
}

.invoice-info-block p strong {
    color: #1a1a2e;
    font-weight: 700;
}

/* Items Table */
.invoice-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 32px;
}

.invoice-table thead th {
    text-align: left;
    padding: 10px 14px;
    font-size: 0.62rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #888;
    border-bottom: 2px solid #eee;
    font-weight: 700;
}

.invoice-table thead th:last-child {
    text-align: right;
}

.invoice-table tbody td {
    padding: 14px;
    font-size: 0.88rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    color: #333;
}

.invoice-table tbody td:last-child {
    text-align: right;
    font-weight: 700;
    color: #1a1a2e;
}

.item-row-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.item-thumb {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    object-fit: cover;
    background: #f5f5f5;
    flex-shrink: 0;
}

.item-thumb-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ccc;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.item-details .item-name {
    font-weight: 600;
    color: #1a1a2e;
}

.item-details .item-category {
    font-size: 0.72rem;
    color: #999;
}

/* Totals */
.invoice-totals {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 36px;
}

.invoice-totals table {
    width: 280px;
    border-collapse: collapse;
}

.invoice-totals td {
    padding: 8px 0;
    font-size: 0.9rem;
}

.invoice-totals td:first-child {
    color: #888;
}

.invoice-totals td:last-child {
    text-align: right;
    font-weight: 600;
    color: #333;
}

.invoice-totals .total-row td {
    padding-top: 12px;
    border-top: 2px solid #1a1a2e;
    font-size: 1.1rem;
    font-weight: 800;
    color: #1a1a2e;
}

/* Status Badge */
.invoice-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.invoice-status.pending { background: #fff3e0; color: #ef6c00; }
.invoice-status.confirmed { background: #e3f2fd; color: #1565c0; }
.invoice-status.shipped { background: #f3e5f5; color: #7b1fa2; }
.invoice-status.delivered { background: #e8f5e9; color: #2e7d32; }
.invoice-status.cancelled { background: #fbe9e7; color: #c62828; }

/* Footer */
.invoice-footer {
    text-align: center;
    padding: 24px 40px;
    background: #fafafa;
    border-top: 1px solid #eee;
}

.invoice-footer p {
    margin: 0;
    font-size: 0.78rem;
    color: #999;
}

.invoice-footer p strong {
    color: #666;
}

/* Actions Bar */
.invoice-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: center;
    margin-bottom: 28px;
}

/* Print Styles */
@media print {
    body.admin-body { background: #fff; }
    .admin-sidebar, .invoice-actions, .admin-page-header { display: none !important; }
    .admin-main { margin-left: 0 !important; padding: 0 !important; }
    .invoice-wrapper { box-shadow: none; border-radius: 0; max-width: 100%; }
    .invoice-top { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .invoice-status { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="admin-page-header">
    <div>
        <h1>Invoice</h1>
        <p class="page-subtitle"><?php echo $invoiceNumber; ?> — <?php echo $invoiceDate; ?></p>
    </div>
    <a href="orders.php" class="btn-admin outline"><i class="fas fa-arrow-left"></i> Back to Orders</a>
</div>

<!-- Action Buttons -->
<div class="invoice-actions">
    <button onclick="window.print()" class="btn-admin red"><i class="fas fa-print"></i> Print / Save PDF</button>
    <a href="orders.php" class="btn-admin outline"><i class="fas fa-list"></i> All Orders</a>
</div>

<!-- Invoice -->
<div class="invoice-wrapper">

    <!-- Header -->
    <div class="invoice-top">
        <div class="invoice-brand">
            <img src="../assets/images/logo.jpeg" alt="REDLINE">
            <div>
                <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <?php if (!empty($invAddress)): ?>
                    <small style="line-height:1.4; display:block; margin-top:4px;"><?php echo nl2br(htmlspecialchars($invAddress)); ?></small>
                <?php endif; ?>
                <?php if (!empty($invTaxId)): ?>
                    <small style="display:block; margin-top:4px; font-weight:700;">Tax ID: <?php echo htmlspecialchars($invTaxId); ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="invoice-meta">
            <div class="inv-label">Invoice</div>
            <div class="inv-number"><?php echo $invoiceNumber; ?></div>
            <div class="inv-date"><?php echo $invoiceDate; ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="invoice-body">

        <!-- Bill To / Order Info -->
        <div class="invoice-info-grid">
            <div class="invoice-info-block">
                <h3>Billed To</h3>
                <p><strong><?php echo htmlspecialchars($order['shipping_name'] ?? $order['buyer_name'] ?? 'N/A'); ?></strong></p>
                <p><?php echo htmlspecialchars($order['buyer_email'] ?? ''); ?></p>
                <?php if (!empty($order['shipping_phone'])): ?>
                    <p><?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                <?php endif; ?>
            </div>
            <div class="invoice-info-block">
                <h3>Ship To</h3>
                <p><strong><?php echo htmlspecialchars($order['shipping_name'] ?? 'N/A'); ?></strong></p>
                <?php if (!empty($order['shipping_address'])): ?>
                    <p><?php echo htmlspecialchars($order['shipping_address']); ?></p>
                <?php endif; ?>
                <p>
                    <?php echo htmlspecialchars($order['shipping_city'] ?? ''); ?>, 
                    <?php echo htmlspecialchars($order['shipping_state'] ?? ''); ?> 
                    <?php echo htmlspecialchars($order['shipping_pincode'] ?? ''); ?>
                </p>
            </div>
        </div>

        <!-- Order Meta -->
        <div class="invoice-info-grid" style="margin-bottom: 28px;">
            <div class="invoice-info-block">
                <h3>Order Details</h3>
                <p>Order ID: <strong>#<?php echo $order['id']; ?></strong></p>
                <p>Payment: <strong style="text-transform:uppercase;"><?php echo htmlspecialchars($order['payment_method'] ?? 'COD'); ?></strong></p>
            </div>
            <div class="invoice-info-block">
                <h3>Status</h3>
                <span class="invoice-status <?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span>
            </div>
        </div>

        <!-- Items Table -->
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td>
                        <div class="item-row-info">
                            <?php if (!empty($item['image'])): ?>
                                <img src="../<?php echo htmlspecialchars($item['image']); ?>" class="item-thumb" alt="">
                            <?php else: ?>
                                <div class="item-thumb-placeholder"><i class="fas fa-car"></i></div>
                            <?php endif; ?>
                            <div class="item-details">
                                <div class="item-name"><?php echo htmlspecialchars($item['title'] ?? 'Deleted Item'); ?></div>
                                <div class="item-category"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="color: #888; font-size: 0.82rem;"><?php echo htmlspecialchars($item['category_name'] ?? '—'); ?></td>
                    <td>1</td>
                    <td>Rs.<?php echo number_format($item['price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#999;padding:30px;">No items found for this order</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="invoice-totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td>Rs.<?php echo number_format($subtotal, 2); ?></td>
                </tr>
                <tr>
                    <td>Shipping</td>
                    <td>Free</td>
                </tr>
                <tr class="total-row">
                    <td>Total</td>
                    <td>Rs.<?php echo number_format($order['total'], 2); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Footer -->
    <div class="invoice-footer">
        <p style="margin-bottom:8px; font-weight:600; color:#333; line-height:1.5;"><?php echo nl2br(htmlspecialchars($invFooter)); ?></p>
        <p>
            <?php if ($contactEmail): ?>Email: <?php echo htmlspecialchars($contactEmail); ?><?php endif; ?>
            <?php if ($contactEmail && $contactPhone): ?> · <?php endif; ?>
            <?php if ($contactPhone): ?>Phone: <?php echo htmlspecialchars($contactPhone); ?><?php endif; ?>
        </p>
    </div>
</div>

<?php include 'footer.php'; ?>
