<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$pageTitle = 'My Tickets';

// Fetch disputes involving the user (either reported by them or they are the counterparty)
$disputes = [];
try {
    $stmt = $conn->prepare("
        SELECT d.*, 
               o.buyer_id, o.seller_id,
               u.name as reporter_name
        FROM order_disputes d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON d.reporter_id = u.id
        WHERE d.reporter_id = ? OR o.buyer_id = ? OR o.seller_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Error handling
}

// Stats
$openTickets = 0;
$resolvedTickets = 0;
foreach($disputes as $d) {
    if(in_array($d['status'], ['open', 'investigating'])) $openTickets++;
    if(in_array($d['status'], ['resolved', 'dismissed'])) $resolvedTickets++;
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/profile.css">
<style>
    .ticket-card {
        background: var(--bg-surface);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        padding: 24px;
        margin-bottom: 20px;
        transition: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .ticket-card:hover {
        border-color: rgba(255,255,255,0.15);
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    .ticket-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        padding-bottom: 16px;
        margin-bottom: 16px;
    }
    .ticket-id { font-family: var(--font-display); font-size: 1.2rem; font-weight: 800; color: var(--text-primary); }
    .ticket-date { font-size: 0.8rem; color: var(--text-muted); }
    .ticket-type { font-size: 0.85rem; color: #fbbf24; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-top: 4px; display:inline-block; }
    .ticket-description { font-size: 0.95rem; color: var(--text-secondary); line-height: 1.6; }
    
    .resolution-box {
        margin-top: 20px;
        padding: 16px;
        background: rgba(16,185,129,0.05);
        border: 1px solid rgba(16,185,129,0.2);
        border-radius: 8px;
    }
    .resolution-box.dismissed {
        background: rgba(229,57,53,0.05);
        border-color: rgba(229,57,53,0.2);
    }
</style>

<div class="container-rl" style="padding-top:40px; padding-bottom:60px; min-height:80vh;">
    <div class="section-header" data-aos="fade-up">
        <div>
            <div class="section-label">SUPPORT</div>
            <h2 class="section-title">MY TICKETS</h2>
        </div>
    </div>
    
    <div class="row" style="margin-bottom:30px;" data-aos="fade-up">
        <div class="col-md-6">
            <div style="background:rgba(255,255,255,0.02); padding:20px; border-radius:12px; border:1px solid rgba(255,255,255,0.05); text-align:center;">
                <div style="font-size:2rem; font-weight:800; color:#fbbf24;"><?php echo $openTickets; ?></div>
                <div style="font-size:0.8rem; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Active / Investigating</div>
            </div>
        </div>
        <div class="col-md-6">
            <div style="background:rgba(255,255,255,0.02); padding:20px; border-radius:12px; border:1px solid rgba(255,255,255,0.05); text-align:center;">
                <div style="font-size:2rem; font-weight:800; color:#81c784;"><?php echo $resolvedTickets; ?></div>
                <div style="font-size:0.8rem; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Resolved / Closed</div>
            </div>
        </div>
    </div>

    <?php if (empty($disputes)): ?>
        <div style="text-align:center; padding: 60px 20px; background:var(--bg-surface); border-radius:16px; border:1px solid var(--border-color);" data-aos="fade-up">
            <i class="fas fa-check-circle" style="font-size:3rem; color:var(--text-muted); opacity:0.3; margin-bottom:16px;"></i>
            <h3>No Tickets Found</h3>
            <p style="color:var(--text-secondary);">You have no open or closed disputes. Smooth sailing!</p>
            <a href="order_view.php" class="btn-outline-white" style="margin-top:10px;">Return to Orders</a>
        </div>
    <?php else: ?>
        <div data-aos="fade-up">
            <?php foreach($disputes as $d): ?>
                <div class="ticket-card">
                    <div class="ticket-header">
                        <div>
                            <div class="ticket-id">Ticket #<?php echo $d['id']; ?> - Order #<?php echo $d['order_id']; ?></div>
                            <div class="ticket-type">Nature: <?php echo str_replace('_', ' ', $d['type']); ?></div>
                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:8px;">Reported by: <?php echo htmlspecialchars($d['reporter_name']); ?> <?php if($d['reporter_id']==$userId) echo '(You)'; else echo '(Counterparty)'; ?></div>
                        </div>
                        <div style="text-align:right;">
                            <?php 
                            $status = $d['status'];
                            $color = '#fff'; $bg = 'rgba(255,255,255,0.1)'; $icon = 'exclamation-circle';
                            if($status === 'open') { $color = '#fbbf24'; $bg = 'rgba(251,191,36,0.15)'; }
                            elseif($status === 'investigating') { $color = '#60a5fa'; $bg = 'rgba(96,165,250,0.15)'; $icon = 'search'; }
                            elseif($status === 'resolved') { $color = '#34d399'; $bg = 'rgba(52,211,153,0.15)'; $icon = 'check-double'; }
                            elseif($status === 'dismissed') { $color = '#ef4444'; $bg = 'rgba(239,68,68,0.15)'; $icon = 'times-circle'; }
                            ?>
                            <span style="display:inline-flex; align-items:center; gap:6px; color:<?php echo $color; ?>; background:<?php echo $bg; ?>; padding:8px 16px; border-radius:20px; font-size:0.8rem; font-weight:700; text-transform:uppercase;">
                                <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $status; ?>
                            </span>
                            <div class="ticket-date" style="margin-top:12px;">Submitted: <?php echo date('M d, Y', strtotime($d['created_at'])); ?></div>
                        </div>
                    </div>
                    
                    <div class="ticket-description">
                        <strong style="color:var(--text-primary); display:block; margin-bottom:8px; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;">User Statement:</strong>
                        "<?php echo nl2br(htmlspecialchars($d['description'])); ?>"
                    </div>
                    
                    
                    <?php if(!empty($d['resolution_notes'])): ?>
                    <div class="resolution-box <?php echo $status === 'dismissed' ? 'dismissed' : ''; ?>">
                        <strong style="color:<?php echo $status === 'dismissed' ? '#e57373' : '#81c784'; ?>; display:block; margin-bottom:8px; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-shield-alt"></i> Redline Administration:</strong>
                        <p style="color:var(--text-primary); margin:0; font-size:0.95rem; line-height:1.5;"><?php echo nl2br(htmlspecialchars($d['resolution_notes'])); ?></p>
                        <?php if($d['resolved_at']): ?>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:10px;">Resolved on <?php echo date('M d, Y g:i A', strtotime($d['resolved_at'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; text-align:right;">
                        <a href="view_dispute.php?id=<?php echo $d['id']; ?>" class="btn-primary" style="padding: 10px 20px; text-decoration:none;"><i class="far fa-comments"></i> Enter Resolution Chat</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
