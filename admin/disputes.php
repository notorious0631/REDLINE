<?php
require_once 'header.php';

$success = '';
$error = '';

// Handle Admin Action (Update Status / Add Notes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispute'])) {
    $disputeId = intval($_POST['dispute_id']);
    $newStatus = $_POST['status'];
    $notes = trim($_POST['resolution_notes']);
    
    try {
        $stmt = $conn->prepare("SELECT reporter_id, order_id, status FROM order_disputes WHERE id = ?");
        $stmt->execute([$disputeId]);
        $dispute = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($dispute) {
            $isClosing = in_array($newStatus, ['resolved', 'dismissed']) && !in_array($dispute['status'], ['resolved', 'dismissed']);
            
            if ($isClosing) {
                $conn->prepare("UPDATE order_disputes SET status = ?, resolution_notes = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?")
                     ->execute([$newStatus, $notes, $disputeId]);
                $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_closed', ?, ?)")
                     ->execute([$dispute['reporter_id'], "Your dispute (#$disputeId) regarding Order #{$dispute['order_id']} has been $newStatus.", "disputes.php"]);
            } else {
                $conn->prepare("UPDATE order_disputes SET status = ?, resolution_notes = ? WHERE id = ?")
                     ->execute([$newStatus, $notes, $disputeId]);
                if($newStatus !== $dispute['status']) {
                    $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_updated', ?, ?)")
                         ->execute([$dispute['reporter_id'], "The status of your dispute (#$disputeId) has been updated to '$newStatus'.", "disputes.php"]);
                }
            }
            $success = "Dispute #$disputeId updated successfully.";
        }
    } catch (PDOException $e) {
        $error = "Database Error: Failed to update dispute.";
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($statusFilter) {
    $where[] = "d.status = ?";
    $params[] = $statusFilter;
}
if ($typeFilter) {
    $where[] = "d.type = ?";
    $params[] = $typeFilter;
}
if ($search) {
    $where[] = "(buyer.name LIKE ? OR seller.name LIKE ? OR buyer.email LIKE ? OR seller.email LIKE ? OR d.id = ? OR o.id = ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = intval($search);
    $params[] = intval($search);
}

$whereClause = implode(' AND ', $where);

// Fetch all disputes with full buyer/seller/order details
$disputes = [];
try {
    $stmt = $conn->prepare("
        SELECT d.*, 
               o.buyer_id, o.seller_id, o.total as order_total, o.status as order_status, 
               o.payment_status, o.payment_method, o.created_at as order_date,
               u.name as reporter_name, u.email as reporter_email, u.role as reporter_role,
               buyer.name as buyer_name, buyer.email as buyer_email, buyer.avatar as buyer_avatar,
               seller.name as seller_name, seller.email as seller_email, seller.avatar as seller_avatar,
               (SELECT COUNT(*) FROM dispute_messages dm WHERE dm.dispute_id = d.id) as message_count
        FROM order_disputes d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON d.reporter_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        JOIN users seller ON o.seller_id = seller.id
        WHERE $whereClause
        ORDER BY CASE WHEN d.status = 'open' THEN 1 WHEN d.status = 'investigating' THEN 2 ELSE 3 END, d.created_at DESC
    ");
    $stmt->execute($params);
    $disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch order items for each dispute
$disputeItems = [];
foreach ($disputes as $d) {
    try {
        $stmt2 = $conn->prepare("SELECT oi.*, l.title, l.price as listing_price FROM order_items oi JOIN listings l ON oi.listing_id = l.id WHERE oi.order_id = ?");
        $stmt2->execute([$d['order_id']]);
        $disputeItems[$d['id']] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $disputeItems[$d['id']] = [];
    }
}

// Stats
$totalDisputes = count($disputes);
$openCount = 0; $investigatingCount = 0; $resolvedCount = 0; $dismissedCount = 0;
$totalDisputeValue = 0;
foreach ($disputes as $d) {
    if ($d['status'] === 'open') $openCount++;
    elseif ($d['status'] === 'investigating') $investigatingCount++;
    elseif ($d['status'] === 'resolved') $resolvedCount++;
    elseif ($d['status'] === 'dismissed') $dismissedCount++;
    $totalDisputeValue += $d['order_total'];
}

// Fetch all dispute types for filter dropdown
$allTypes = [];
try {
    $stmt = $conn->query("SELECT DISTINCT type FROM order_disputes ORDER BY type");
    $allTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

?>

<style>
/* ===== DISPUTES PAGE STYLES ===== */
.disputes-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.dstat-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform 0.2s, border-color 0.2s;
}
.dstat-card:hover {
    transform: translateY(-2px);
    border-color: rgba(255,255,255,0.1);
}

.dstat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.dstat-icon.red { background: rgba(229,57,53,0.15); color: #ef5350; }
.dstat-icon.amber { background: rgba(255,183,77,0.15); color: #ffb74d; }
.dstat-icon.blue { background: rgba(66,165,245,0.15); color: #42a5f5; }
.dstat-icon.green { background: rgba(76,175,80,0.15); color: #66bb6a; }
.dstat-icon.gray { background: rgba(158,158,158,0.15); color: #9e9e9e; }
.dstat-icon.purple { background: rgba(171,71,188,0.15); color: #ba68c8; }

.dstat-value { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.dstat-label { font-size: 0.72rem; color: var(--admin-muted); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.04em; }

/* Filters bar */
.disputes-filters {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    align-items: center;
}
.disputes-filters .filter-input {
    background: var(--admin-bg);
    border: 1px solid var(--admin-border);
    color: var(--admin-text);
    border-radius: 8px;
    padding: 9px 14px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    transition: border-color 0.2s;
}
.disputes-filters .filter-input:focus { border-color: var(--admin-accent); outline: none; }
.disputes-filters .filter-btn {
    background: var(--admin-accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}
.disputes-filters .filter-btn:hover { background: var(--admin-accent-hover); }
.disputes-filters .filter-clear {
    background: transparent;
    border: 1px solid var(--admin-border);
    color: var(--admin-muted);
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 0.82rem;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}
.disputes-filters .filter-clear:hover { border-color: var(--admin-text); color: var(--admin-text); }

/* Dispute cards */
.dispute-card {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.dispute-card:hover {
    border-color: rgba(255,255,255,0.08);
    box-shadow: 0 4px 24px rgba(0,0,0,0.2);
}
.dispute-card.status-open { border-left: 3px solid #fbbf24; }
.dispute-card.status-investigating { border-left: 3px solid #60a5fa; }
.dispute-card.status-resolved { border-left: 3px solid #34d399; }
.dispute-card.status-dismissed { border-left: 3px solid #ef4444; }
.dispute-card.closed { opacity: 0.65; }
.dispute-card.closed:hover { opacity: 1; }

.dispute-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    cursor: pointer;
    gap: 16px;
    flex-wrap: wrap;
}

.dc-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    min-width: 0;
}

.dc-id {
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--admin-text);
    white-space: nowrap;
}
.dc-id span {
    font-size: 0.72rem;
    color: var(--admin-muted);
    font-weight: 500;
    display: block;
    margin-top: 2px;
}

.dc-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}
.dc-type-badge.not_received { background: rgba(251,191,36,0.12); color: #fbbf24; border: 1px solid rgba(251,191,36,0.25); }
.dc-type-badge.scam { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
.dc-type-badge.damaged { background: rgba(251,146,60,0.12); color: #fb923c; border: 1px solid rgba(251,146,60,0.25); }
.dc-type-badge.wrong_item { background: rgba(168,85,247,0.12); color: #a855f7; border: 1px solid rgba(168,85,247,0.25); }
.dc-type-badge.other { background: rgba(148,163,184,0.12); color: #94a3b8; border: 1px solid rgba(148,163,184,0.25); }
.dc-type-badge.counterfeit { background: rgba(244,63,94,0.12); color: #fb7185; border: 1px solid rgba(244,63,94,0.25); }

.dc-desc {
    font-size: 0.82rem;
    color: var(--admin-muted);
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dc-right {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
}

.dc-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.dc-status.open { color: #fbbf24; background: rgba(251,191,36,0.12); }
.dc-status.investigating { color: #60a5fa; background: rgba(96,165,250,0.12); }
.dc-status.resolved { color: #34d399; background: rgba(52,211,153,0.12); }
.dc-status.dismissed { color: #f87171; background: rgba(248,113,113,0.12); }

.dc-msgs {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem;
    color: var(--admin-muted);
    background: rgba(255,255,255,0.03);
    padding: 5px 10px;
    border-radius: 6px;
}
.dc-msgs i { font-size: 0.7rem; }

.dc-amount {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--admin-accent);
    white-space: nowrap;
}

.dc-chevron {
    color: var(--admin-muted);
    transition: transform 0.3s;
    font-size: 0.8rem;
}
.dispute-card.expanded .dc-chevron { transform: rotate(180deg); }

.dc-date {
    font-size: 0.7rem;
    color: var(--admin-muted);
    white-space: nowrap;
}

/* Expandable body */
.dispute-card-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease;
    border-top: 0px solid transparent;
}
.dispute-card.expanded .dispute-card-body {
    max-height: 1200px;
    border-top: 1px solid var(--admin-border);
}

.dc-body-inner {
    padding: 24px;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 24px;
}

/* Parties column */
.dc-section-title {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--admin-muted);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.dc-section-title i { font-size: 0.7rem; }

.party-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.party-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    color: var(--admin-muted);
    flex-shrink: 0;
    overflow: hidden;
}
.party-avatar img { width: 100%; height: 100%; object-fit: cover; }

.party-info { min-width: 0; }
.party-name {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--admin-text);
    display: flex;
    align-items: center;
    gap: 6px;
}
.party-role-tag {
    font-size: 0.58rem;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.party-role-tag.buyer { background: rgba(96,165,250,0.15); color: #60a5fa; }
.party-role-tag.seller { background: rgba(251,146,60,0.15); color: #fb923c; }
.party-role-tag.reporter { background: rgba(168,85,247,0.15); color: #a855f7; }

.party-email {
    font-size: 0.75rem;
    color: var(--admin-muted);
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Order & Items column */
.dc-order-box {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 10px;
}

.dc-order-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.82rem;
    padding: 4px 0;
}
.dc-order-row .label { color: var(--admin-muted); font-size: 0.78rem; }
.dc-order-row .value { color: var(--admin-text); font-weight: 600; font-size: 0.82rem; }

.dc-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.dc-item:last-child { border-bottom: none; }
.dc-item-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--admin-accent);
    flex-shrink: 0;
}
.dc-item-name { font-size: 0.82rem; color: var(--admin-text); flex: 1; }
.dc-item-price { font-size: 0.8rem; color: var(--admin-muted); font-weight: 600; white-space: nowrap; }

/* Statement & Action column */
.dc-statement {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 10px;
    padding: 16px;
    font-size: 0.85rem;
    color: var(--admin-text);
    line-height: 1.6;
    margin-bottom: 12px;
    max-height: 120px;
    overflow-y: auto;
}

.dc-resolution {
    background: rgba(52,211,153,0.05);
    border: 1px solid rgba(52,211,153,0.15);
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 12px;
}
.dc-resolution-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #34d399;
    margin-bottom: 6px;
}
.dc-resolution-text { font-size: 0.82rem; color: var(--admin-muted); line-height: 1.5; }
.dc-resolution-date { font-size: 0.7rem; color: rgba(52,211,153,0.6); margin-top: 6px; }

.dc-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.dc-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all 0.15s;
    font-family: 'Poppins', sans-serif;
}
.dc-action-btn.primary {
    background: var(--admin-accent);
    color: #fff;
}
.dc-action-btn.primary:hover { background: var(--admin-accent-hover); }
.dc-action-btn.outline {
    background: transparent;
    color: var(--admin-muted);
    border: 1px solid var(--admin-border);
}
.dc-action-btn.outline:hover { border-color: var(--admin-text); color: var(--admin-text); }

/* Timeline */
.dc-timeline {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 16px;
}
.dc-tl-step {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.68rem;
    color: var(--admin-muted);
    white-space: nowrap;
}
.dc-tl-step.active { color: var(--admin-text); font-weight: 600; }
.dc-tl-step.done { color: #34d399; }
.dc-tl-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--admin-border);
    flex-shrink: 0;
}
.dc-tl-step.active .dc-tl-dot { background: var(--admin-accent); box-shadow: 0 0 8px rgba(229,57,53,0.4); }
.dc-tl-step.done .dc-tl-dot { background: #34d399; }
.dc-tl-line {
    width: 30px;
    height: 2px;
    background: var(--admin-border);
    margin: 0 4px;
}
.dc-tl-step.done + .dc-tl-line { background: #34d399; }

/* Empty state */
.disputes-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--admin-muted);
}
.disputes-empty i { font-size: 3rem; margin-bottom: 16px; opacity: 0.3; }
.disputes-empty p { font-size: 0.9rem; }

/* Responsive */
@media (max-width: 900px) {
    .dc-body-inner { grid-template-columns: 1fr; }
    .dc-desc { display: none; }
    .disputes-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- Page Header -->
<div class="admin-page-header">
    <div>
        <h1><i class="fas fa-life-ring" style="color:var(--admin-accent); margin-right:8px;"></i>Dispute Center</h1>
        <p class="page-subtitle">Monitor, investigate, and resolve platform disputes</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="admin-alert error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="disputes-stats">
    <div class="dstat-card">
        <div class="dstat-icon red"><i class="fas fa-gavel"></i></div>
        <div>
            <div class="dstat-value"><?php echo $totalDisputes; ?></div>
            <div class="dstat-label">Total Disputes</div>
        </div>
    </div>
    <div class="dstat-card">
        <div class="dstat-icon amber"><i class="fas fa-exclamation-circle"></i></div>
        <div>
            <div class="dstat-value"><?php echo $openCount; ?></div>
            <div class="dstat-label">Open / New</div>
        </div>
    </div>
    <div class="dstat-card">
        <div class="dstat-icon blue"><i class="fas fa-search"></i></div>
        <div>
            <div class="dstat-value"><?php echo $investigatingCount; ?></div>
            <div class="dstat-label">Investigating</div>
        </div>
    </div>
    <div class="dstat-card">
        <div class="dstat-icon green"><i class="fas fa-check-double"></i></div>
        <div>
            <div class="dstat-value"><?php echo $resolvedCount; ?></div>
            <div class="dstat-label">Resolved</div>
        </div>
    </div>
    <div class="dstat-card">
        <div class="dstat-icon gray"><i class="fas fa-times-circle"></i></div>
        <div>
            <div class="dstat-value"><?php echo $dismissedCount; ?></div>
            <div class="dstat-label">Dismissed</div>
        </div>
    </div>
    <div class="dstat-card">
        <div class="dstat-icon purple"><i class="fas fa-rupee-sign"></i></div>
        <div>
            <div class="dstat-value">₹<?php echo number_format($totalDisputeValue); ?></div>
            <div class="dstat-label">Value at Stake</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="disputes-filters">
    <form style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
        <input type="text" name="search" class="filter-input" placeholder="Search by name, email, ticket #, order #..." 
               value="<?php echo htmlspecialchars($search); ?>" style="min-width:260px; flex:1;">
        
        <select name="status" class="filter-input" style="min-width:150px;">
            <option value="">All Statuses</option>
            <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>🟡 Open</option>
            <option value="investigating" <?php echo $statusFilter === 'investigating' ? 'selected' : ''; ?>>🔵 Investigating</option>
            <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>🟢 Resolved</option>
            <option value="dismissed" <?php echo $statusFilter === 'dismissed' ? 'selected' : ''; ?>>🔴 Dismissed</option>
        </select>
        
        <select name="type" class="filter-input" style="min-width:150px;">
            <option value="">All Types</option>
            <?php foreach ($allTypes as $t): ?>
                <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $typeFilter === $t ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $t))); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Filter</button>
        
        <?php if ($search || $statusFilter || $typeFilter): ?>
            <a href="disputes.php" class="filter-clear"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Dispute Cards -->
<?php if (empty($disputes)): ?>
    <div class="admin-card">
        <div class="disputes-empty">
            <i class="fas fa-shield-alt"></i>
            <p>No disputes found matching your criteria.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($disputes as $d): 
        $isClosed = in_array($d['status'], ['resolved', 'dismissed']);
        $statusClass = $d['status'];
        $typeClass = strtolower($d['type']);
        $items = $disputeItems[$d['id']] ?? [];
        $isReporterBuyer = ($d['reporter_id'] == $d['buyer_id']);
        
        // Timeline step tracking
        $steps = ['open', 'investigating', 'resolved'];
        if ($d['status'] === 'dismissed') $steps[2] = 'dismissed';
        $currentStep = array_search($d['status'], $steps);
        if ($currentStep === false) $currentStep = 0;
        
        // Age calculation
        $created = new DateTime($d['created_at']);
        $now = new DateTime();
        $age = $created->diff($now);
        if ($age->days === 0) $ageStr = 'Today';
        elseif ($age->days === 1) $ageStr = '1 day ago';
        else $ageStr = $age->days . ' days ago';
        
        // Status icon
        $statusIcon = 'exclamation-circle';
        if ($d['status'] === 'investigating') $statusIcon = 'search';
        if ($d['status'] === 'resolved') $statusIcon = 'check-double';
        if ($d['status'] === 'dismissed') $statusIcon = 'times-circle';
    ?>
    <div class="dispute-card status-<?php echo $statusClass; ?> <?php echo $isClosed ? 'closed' : ''; ?>" id="dispute-<?php echo $d['id']; ?>">
        <!-- Clickable header -->
        <div class="dispute-card-header" onclick="toggleDispute(<?php echo $d['id']; ?>)">
            <div class="dc-left">
                <div class="dc-id">
                    #<?php echo $d['id']; ?>
                    <span>Order #<?php echo $d['order_id']; ?></span>
                </div>
                
                <span class="dc-type-badge <?php echo $typeClass; ?>">
                    <i class="fas fa-<?php 
                        echo match($d['type']) {
                            'not_received' => 'box-open',
                            'scam' => 'user-secret',
                            'damaged' => 'hammer',
                            'wrong_item' => 'exchange-alt',
                            'counterfeit' => 'clone',
                            default => 'question-circle'
                        };
                    ?>"></i>
                    <?php echo str_replace('_', ' ', $d['type']); ?>
                </span>
                
                <div class="dc-desc" title="<?php echo htmlspecialchars($d['description']); ?>">
                    <?php echo htmlspecialchars($d['description']); ?>
                </div>
            </div>
            
            <div class="dc-right">
                <div class="dc-date"><i class="far fa-clock"></i> <?php echo $ageStr; ?></div>
                
                <div class="dc-msgs" title="<?php echo $d['message_count']; ?> messages in thread">
                    <i class="fas fa-comments"></i> <?php echo $d['message_count']; ?>
                </div>
                
                <div class="dc-amount">₹<?php echo number_format($d['order_total']); ?></div>
                
                <span class="dc-status <?php echo $statusClass; ?>">
                    <i class="fas fa-<?php echo $statusIcon; ?>"></i>
                    <?php echo ucfirst($d['status']); ?>
                </span>
                
                <i class="fas fa-chevron-down dc-chevron"></i>
            </div>
        </div>
        
        <!-- Expandable body -->
        <div class="dispute-card-body">
            <div class="dc-body-inner">
                
                <!-- Column 1: Parties -->
                <div>
                    <div class="dc-section-title"><i class="fas fa-users"></i> Parties Involved</div>
                    
                    <!-- Buyer -->
                    <div class="party-card">
                        <div class="party-avatar">
                            <?php if (!empty($d['buyer_avatar'])): ?>
                                <img src="../<?php echo htmlspecialchars($d['buyer_avatar']); ?>">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="party-info">
                            <div class="party-name">
                                <?php echo htmlspecialchars($d['buyer_name']); ?>
                                <span class="party-role-tag buyer">Buyer</span>
                                <?php if ($d['reporter_id'] == $d['buyer_id']): ?>
                                    <span class="party-role-tag reporter">Reporter</span>
                                <?php endif; ?>
                            </div>
                            <div class="party-email"><i class="fas fa-envelope" style="margin-right:4px; font-size:0.65rem;"></i><?php echo htmlspecialchars($d['buyer_email']); ?></div>
                        </div>
                    </div>
                    
                    <!-- Seller -->
                    <div class="party-card">
                        <div class="party-avatar">
                            <?php if (!empty($d['seller_avatar'])): ?>
                                <img src="../<?php echo htmlspecialchars($d['seller_avatar']); ?>">
                            <?php else: ?>
                                <i class="fas fa-store"></i>
                            <?php endif; ?>
                        </div>
                        <div class="party-info">
                            <div class="party-name">
                                <?php echo htmlspecialchars($d['seller_name']); ?>
                                <span class="party-role-tag seller">Seller</span>
                                <?php if ($d['reporter_id'] == $d['seller_id']): ?>
                                    <span class="party-role-tag reporter">Reporter</span>
                                <?php endif; ?>
                            </div>
                            <div class="party-email"><i class="fas fa-envelope" style="margin-right:4px; font-size:0.65rem;"></i><?php echo htmlspecialchars($d['seller_email']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Column 2: Order & Items -->
                <div>
                    <div class="dc-section-title"><i class="fas fa-receipt"></i> Order Details</div>
                    
                    <div class="dc-order-box">
                        <div class="dc-order-row">
                            <span class="label">Order ID</span>
                            <span class="value"><a href="orders.php?search=<?php echo $d['order_id']; ?>" style="color:#42a5f5; text-decoration:none;">#<?php echo $d['order_id']; ?></a></span>
                        </div>
                        <div class="dc-order-row">
                            <span class="label">Total Amount</span>
                            <span class="value" style="color:var(--admin-accent);">₹<?php echo number_format($d['order_total']); ?></span>
                        </div>
                        <div class="dc-order-row">
                            <span class="label">Order Status</span>
                            <span class="value"><span class="badge-sm <?php echo $d['order_status']; ?>"><?php echo ucfirst($d['order_status']); ?></span></span>
                        </div>
                        <div class="dc-order-row">
                            <span class="label">Payment</span>
                            <span class="value" style="font-size:0.75rem; text-transform:uppercase;"><?php echo $d['payment_method'] ?? '—'; ?> · <?php echo ucfirst($d['payment_status']); ?></span>
                        </div>
                        <div class="dc-order-row">
                            <span class="label">Order Date</span>
                            <span class="value" style="font-size:0.78rem;"><?php echo date('M d, Y', strtotime($d['order_date'])); ?></span>
                        </div>
                    </div>
                    
                    <?php if (!empty($items)): ?>
                    <div class="dc-section-title" style="margin-top:16px;"><i class="fas fa-box"></i> Purchased Items (<?php echo count($items); ?>)</div>
                    <div class="dc-order-box">
                        <?php foreach ($items as $item): ?>
                        <div class="dc-item">
                            <div class="dc-item-dot"></div>
                            <div class="dc-item-name"><?php echo htmlspecialchars($item['title']); ?> <?php if (isset($item['quantity']) && $item['quantity'] > 1) echo '<span style="color:var(--admin-muted); font-size:0.75rem;">×'.$item['quantity'].'</span>'; ?></div>
                            <div class="dc-item-price">₹<?php echo number_format($item['price']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Column 3: Statement, Timeline & Actions -->
                <div>
                    <div class="dc-section-title"><i class="fas fa-clipboard-list"></i> User Statement</div>
                    <div class="dc-statement">
                        <?php echo nl2br(htmlspecialchars($d['description'])); ?>
                    </div>
                    
                    <!-- Timeline -->
                    <div class="dc-section-title" style="margin-top:16px;"><i class="fas fa-stream"></i> Progress</div>
                    <div class="dc-timeline">
                        <?php 
                        $timelineSteps = [
                            ['label' => 'Opened', 'key' => 'open'],
                            ['label' => 'Investigating', 'key' => 'investigating'],
                            ['label' => $d['status'] === 'dismissed' ? 'Dismissed' : 'Resolved', 'key' => $d['status'] === 'dismissed' ? 'dismissed' : 'resolved']
                        ];
                        
                        foreach ($timelineSteps as $idx => $step):
                            $stepIdx = $idx;
                            $class = '';
                            if ($stepIdx < $currentStep) $class = 'done';
                            elseif ($stepIdx == $currentStep) $class = 'active';
                        ?>
                            <?php if ($idx > 0): ?><div class="dc-tl-line"></div><?php endif; ?>
                            <div class="dc-tl-step <?php echo $class; ?>">
                                <div class="dc-tl-dot"></div>
                                <?php echo $step['label']; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($isClosed && !empty($d['resolution_notes'])): ?>
                        <div class="dc-resolution">
                            <div class="dc-resolution-label"><i class="fas fa-shield-alt"></i> Admin Resolution</div>
                            <div class="dc-resolution-text"><?php echo nl2br(htmlspecialchars($d['resolution_notes'])); ?></div>
                            <?php if ($d['resolved_at']): ?>
                                <div class="dc-resolution-date">Closed on <?php echo date('M d, Y \a\t h:i A', strtotime($d['resolved_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filed date -->
                    <div style="font-size:0.72rem; color:var(--admin-muted); margin-bottom:14px;">
                        <i class="far fa-calendar-alt"></i> Filed on <?php echo date('M d, Y \a\t h:i A', strtotime($d['created_at'])); ?>
                    </div>
                    
                    <div class="dc-actions">
                        <a href="view_dispute.php?id=<?php echo $d['id']; ?>" class="dc-action-btn primary">
                            <i class="fas fa-comments"></i> Open Chat
                        </a>
                        <a href="orders.php?search=<?php echo $d['order_id']; ?>" class="dc-action-btn outline">
                            <i class="fas fa-file-invoice"></i> View Order
                        </a>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
function toggleDispute(id) {
    const card = document.getElementById('dispute-' + id);
    card.classList.toggle('expanded');
}

// Auto-expand first open/investigating dispute
document.addEventListener('DOMContentLoaded', () => {
    const firstActive = document.querySelector('.dispute-card.status-open, .dispute-card.status-investigating');
    if (firstActive) firstActive.classList.add('expanded');
});
</script>

<?php require_once 'footer.php'; ?>
