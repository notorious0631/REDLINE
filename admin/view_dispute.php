<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$adminId = $_SESSION['user_id'];
$disputeId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = '';
$success = '';

if (!$disputeId) {
    header('Location: disputes.php');
    exit;
}

// Fetch dispute with full details
try {
    $stmt = $conn->prepare("
        SELECT d.*, o.buyer_id, o.seller_id, o.id as order_id, o.total, o.status as order_status, 
               o.payment_status, o.payment_method, o.created_at as order_date, o.payment_proof, o.seller_statement,
               u.name as reporter_name, u.email as reporter_email,
               buyer.name as buyer_name, buyer.email as buyer_email, buyer.avatar as buyer_avatar, buyer.phone as buyer_phone,
               seller.name as seller_name, seller.email as seller_email, seller.avatar as seller_avatar, seller.phone as seller_phone
        FROM order_disputes d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON d.reporter_id = u.id
        JOIN users buyer ON o.buyer_id = buyer.id
        JOIN users seller ON o.seller_id = seller.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        header('Location: disputes.php');
        exit;
    }
} catch (PDOException $e) {
    $error = "Database Error.";
}

// Fetch order items
$orderItems = [];
try {
    $stmt2 = $conn->prepare("SELECT oi.*, l.title, l.price as listing_price, l.image_path FROM order_items oi JOIN listings l ON oi.listing_id = l.id WHERE oi.order_id = ?");
    $stmt2->execute([$dispute['order_id']]);
    $orderItems = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Change status to investigating on view if open
if ($dispute['status'] === 'open') {
    $conn->prepare("UPDATE order_disputes SET status = 'investigating' WHERE id = ?")->execute([$disputeId]);
    $dispute['status'] = 'investigating';
}

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && empty($error)) {
    if (in_array($dispute['status'], ['resolved', 'dismissed'])) {
        $error = "This ticket is closed.";
    } else {
        $message = trim($_POST['message'] ?? '');
        $imagePath = null;
        
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $uploadDir = '../uploads/disputes/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $imagePath = 'uploads/disputes/disp_' . $disputeId . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['attachment']['tmp_name'], '../' . $imagePath);
        }
        
        if (empty($message) && !$imagePath) {
            $error = "Please enter a message or upload an attachment.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO dispute_messages (dispute_id, sender_id, message, image_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$disputeId, $adminId, $message, $imagePath]);
                
                // Notify both buyer and seller
                $notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_message', ?, ?)");
                $notify->execute([$dispute['buyer_id'], "Admin replied to Dispute #$disputeId.", "view_dispute.php?id=$disputeId"]);
                $notify->execute([$dispute['seller_id'], "Admin replied to Dispute #$disputeId.", "view_dispute.php?id=$disputeId"]);
                
                header("Location: view_dispute.php?id=$disputeId");
                exit;
            } catch (PDOException $e) {
                $error = "Failed to send message.";
            }
        }
    }
}

// Handle closing ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket']) && empty($error)) {
    $action = $_POST['action_status'];
    $notes = trim($_POST['resolution_notes']);
    
    if (in_array($action, ['resolved', 'dismissed'])) {
        try {
            $conn->prepare("UPDATE order_disputes SET status = ?, resolution_notes = ?, resolved_at = NOW() WHERE id = ?")->execute([$action, $notes, $disputeId]);
            
            $msg = $action === 'resolved' ? "Dispute #$disputeId has been marked as Resolved by Admin." : "Dispute #$disputeId has been Dismissed by Admin.";
            $notify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_closed', ?, ?)");
            $notify->execute([$dispute['buyer_id'], $msg, "view_dispute.php?id=$disputeId"]);
            $notify->execute([$dispute['seller_id'], $msg, "view_dispute.php?id=$disputeId"]);
            
            header("Location: view_dispute.php?id=$disputeId");
            exit;
        } catch(PDOException $e) {
            logError('admin_view_dispute', 'Failed to close ticket', $e);
            $error = "Failed to close ticket. Please try again.";
        }
    } else {
        $error = "Invalid action.";
    }
}

// Fetch messages
$messages = [];
try {
    $stmt = $conn->prepare("
        SELECT dm.*, u.name as sender_name, u.role as sender_role, u.avatar
        FROM dispute_messages dm
        JOIN users u ON dm.sender_id = u.id
        WHERE dm.dispute_id = ?
        ORDER BY dm.created_at ASC
    ");
    $stmt->execute([$disputeId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Determine status variables
$isClosed = in_array($dispute['status'], ['resolved', 'dismissed']);
$statusIcon = 'exclamation-circle';
if($dispute['status'] == 'investigating') $statusIcon = 'search';
if($dispute['status'] == 'resolved') $statusIcon = 'check-double';
if($dispute['status'] == 'dismissed') $statusIcon = 'times-circle';

// Age calculation
$created = new DateTime($dispute['created_at']);
$now = new DateTime();
$age = $created->diff($now);
if ($age->days === 0) $ageStr = 'Today';
elseif ($age->days === 1) $ageStr = '1 day ago';
else $ageStr = $age->days . ' days ago';

$pageTitle = 'Manage Dispute #' . $disputeId;
include 'header.php';
?>

<style>
/* ===== DISPUTE MANAGE PAGE :: PREMIUM REVAMP ===== */

.vd-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--admin-muted);
    font-size: 0.82rem;
    text-decoration: none;
    margin-bottom: 6px;
    transition: color 0.15s;
    font-weight: 500;
}
.vd-back:hover { color: var(--admin-text); }
.vd-back i { font-size: 0.75rem; }

/* Page Header Bar */
.vd-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.vd-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.vd-ticket-id {
    font-family: 'Cinzel', serif;
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--admin-text);
}

.vd-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 14px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.vd-type-badge.not_received { background: rgba(251,191,36,0.12); color: #fbbf24; border: 1px solid rgba(251,191,36,0.25); }
.vd-type-badge.scam { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
.vd-type-badge.damaged { background: rgba(251,146,60,0.12); color: #fb923c; border: 1px solid rgba(251,146,60,0.25); }
.vd-type-badge.wrong_item { background: rgba(168,85,247,0.12); color: #a855f7; border: 1px solid rgba(168,85,247,0.25); }
.vd-type-badge.other { background: rgba(148,163,184,0.12); color: #94a3b8; border: 1px solid rgba(148,163,184,0.25); }
.vd-type-badge.counterfeit { background: rgba(244,63,94,0.12); color: #fb7185; border: 1px solid rgba(244,63,94,0.25); }

.vd-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.vd-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.vd-status-pill.open { color: #fbbf24; background: rgba(251,191,36,0.12); }
.vd-status-pill.investigating { color: #60a5fa; background: rgba(96,165,250,0.12); }
.vd-status-pill.resolved { color: #34d399; background: rgba(52,211,153,0.12); }
.vd-status-pill.dismissed { color: #f87171; background: rgba(248,113,113,0.12); }

.vd-age { font-size: 0.78rem; color: var(--admin-muted); display: flex; align-items: center; gap: 5px; }

/* Main Layout */
.vd-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 20px;
    max-width: 1200px;
}

/* ===== CHAT PANEL ===== */
.vd-chat {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 200px);
    min-height: 500px;
    overflow: hidden;
}

.vd-chat-header {
    padding: 18px 24px;
    background: rgba(255,255,255,0.015);
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.vd-chat-title {
    font-size: 1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--admin-text);
}
.vd-chat-title i { color: var(--admin-accent); }
.vd-chat-meta { font-size: 0.72rem; color: var(--admin-muted); display: flex; align-items: center; gap: 6px; }
.vd-chat-meta .dot { width: 6px; height: 6px; border-radius: 50%; }
.vd-chat-meta .dot.live { background: #34d399; box-shadow: 0 0 6px rgba(52,211,153,0.4); }
.vd-chat-meta .dot.closed { background: #ef4444; }

/* Chat Body */
.vd-chat-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: var(--admin-bg);
}

.vd-chat-body::-webkit-scrollbar { width: 5px; }
.vd-chat-body::-webkit-scrollbar-track { background: transparent; }
.vd-chat-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 4px; }
.vd-chat-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.15); }

/* Statement banner */
.vd-statement {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 16px;
    position: relative;
}
.vd-statement::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--admin-accent);
    border-radius: 3px 0 0 3px;
}
.vd-statement-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--admin-muted);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.vd-statement-text {
    font-size: 0.88rem;
    color: var(--admin-text);
    line-height: 1.6;
    font-style: italic;
}

/* Date separator */
.vd-date-sep {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 0 8px;
}
.vd-date-sep::before, .vd-date-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,0.06);
}
.vd-date-sep span {
    font-size: 0.65rem;
    color: var(--admin-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 600;
    white-space: nowrap;
}

/* Messages */
.vd-msg {
    display: flex;
    gap: 10px;
    max-width: 75%;
    animation: vd-msg-in 0.25s ease;
}
@keyframes vd-msg-in {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.vd-msg.from-other { align-self: flex-start; }
.vd-msg.from-admin { align-self: flex-end; flex-direction: row-reverse; }
.vd-msg.from-system { align-self: center; max-width: 90%; }

.vd-msg-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    color: var(--admin-muted);
    flex-shrink: 0;
    overflow: hidden;
    margin-top: 18px;
}
.vd-msg-avatar img { width: 100%; height: 100%; object-fit: cover; }

.vd-msg-content { min-width: 0; }

.vd-msg-sender {
    font-size: 0.7rem;
    color: var(--admin-muted);
    margin-bottom: 4px;
    margin-left: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}
.from-admin .vd-msg-sender { text-align: right; margin-right: 2px; justify-content: flex-end; }

.vd-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 1px 7px;
    border-radius: 4px;
    font-size: 0.56rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.vd-role-badge.buyer { background: rgba(96,165,250,0.15); color: #60a5fa; }
.vd-role-badge.seller { background: rgba(251,146,60,0.15); color: #fb923c; }
.vd-role-badge.admin { background: rgba(229,57,53,0.15); color: #ef5350; }

.vd-bubble {
    padding: 12px 16px;
    border-radius: 14px;
    font-size: 0.85rem;
    line-height: 1.6;
    color: var(--admin-text);
    word-break: break-word;
}

.from-other .vd-bubble {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-bottom-left-radius: 4px;
}
.from-admin .vd-bubble {
    background: linear-gradient(135deg, rgba(229,57,53,0.2), rgba(229,57,53,0.08));
    border: 1px solid rgba(229,57,53,0.25);
    border-bottom-right-radius: 4px;
}
.from-system .vd-bubble {
    background: rgba(251,191,36,0.08);
    border: 1px solid rgba(251,191,36,0.2);
    color: #fbbf24;
    text-align: center;
    font-size: 0.82rem;
    border-radius: 10px;
}

.vd-msg-time {
    font-size: 0.65rem;
    color: rgba(255,255,255,0.25);
    margin-top: 3px;
    margin-left: 2px;
}
.from-admin .vd-msg-time { text-align: right; margin-right: 2px; }

.vd-attachment {
    max-width: 240px;
    border-radius: 10px;
    margin-top: 8px;
    border: 1px solid rgba(255,255,255,0.08);
    cursor: pointer;
    transition: transform 0.2s, border-color 0.2s;
    display: block;
}
.vd-attachment:hover { transform: scale(1.02); border-color: rgba(255,255,255,0.2); }

/* Chat Footer / Input */
.vd-chat-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--admin-border);
    background: rgba(255,255,255,0.015);
}

.vd-closed-banner {
    text-align: center;
    color: var(--admin-muted);
    padding: 6px;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.vd-closed-banner i { color: rgba(239,68,68,0.6); }

.vd-input-row {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.vd-attach-btn {
    width: 42px;
    height: 42px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    color: var(--admin-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    flex-shrink: 0;
    font-size: 0.9rem;
}
.vd-attach-btn:hover { background: rgba(255,255,255,0.08); color: var(--admin-text); border-color: rgba(255,255,255,0.15); }
.vd-attach-btn.attached { border-color: #34d399; color: #34d399; background: rgba(52,211,153,0.08); }

.vd-textarea {
    flex: 1;
    background: var(--admin-bg);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    padding: 10px 16px;
    color: var(--admin-text);
    font-family: 'Poppins', sans-serif;
    font-size: 0.85rem;
    resize: none;
    line-height: 1.5;
    transition: border-color 0.2s;
}
.vd-textarea:focus { outline: none; border-color: var(--admin-accent); }
.vd-textarea::placeholder { color: rgba(255,255,255,0.2); }

.vd-send-btn {
    width: 42px;
    height: 42px;
    background: var(--admin-accent);
    border: none;
    border-radius: 10px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, transform 0.1s;
    flex-shrink: 0;
    font-size: 0.85rem;
}
.vd-send-btn:hover { background: var(--admin-accent-hover); transform: scale(1.05); }

.vd-file-preview {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(52,211,153,0.06);
    border: 1px solid rgba(52,211,153,0.15);
    border-radius: 8px;
    margin-bottom: 10px;
    font-size: 0.78rem;
    color: #34d399;
}
.vd-file-preview.active { display: flex; }
.vd-file-preview .name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vd-file-preview .remove { cursor: pointer; color: var(--admin-muted); transition: color 0.15s; }
.vd-file-preview .remove:hover { color: #ef5350; }

/* ===== SIDEBAR ===== */
.vd-sidebar { display: flex; flex-direction: column; gap: 16px; }

.vd-panel {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 12px;
    overflow: hidden;
}

.vd-panel-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.vd-panel-title {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--admin-text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.vd-panel-title i { color: var(--admin-muted); font-size: 0.75rem; }
.vd-panel-link { font-size: 0.72rem; color: var(--admin-blue); text-decoration: none; font-weight: 600; transition: color 0.15s; }
.vd-panel-link:hover { color: #90caf9; }

.vd-panel-body { padding: 16px 18px; }

/* Party cards */
.vd-party {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.04);
    border-radius: 10px;
    margin-bottom: 10px;
    transition: border-color 0.15s;
}
.vd-party:last-child { margin-bottom: 0; }
.vd-party:hover { border-color: rgba(255,255,255,0.1); }

.vd-party-avatar {
    width: 40px;
    height: 40px;
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
.vd-party-avatar img { width: 100%; height: 100%; object-fit: cover; }

.vd-party-info { min-width: 0; flex: 1; }
.vd-party-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--admin-text);
    display: flex;
    align-items: center;
    gap: 6px;
}
.vd-party-email {
    font-size: 0.72rem;
    color: var(--admin-muted);
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.vd-party-email a { color: var(--admin-muted); text-decoration: none; }
.vd-party-email a:hover { color: var(--admin-blue); }

.vd-party-tag {
    font-size: 0.56rem;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.vd-party-tag.buyer { background: rgba(96,165,250,0.15); color: #60a5fa; }
.vd-party-tag.seller { background: rgba(251,146,60,0.15); color: #fb923c; }
.vd-party-tag.reporter { background: rgba(168,85,247,0.12); color: #a855f7; }

/* Order details */
.vd-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.vd-detail-row:last-child { border-bottom: none; }
.vd-detail-label { font-size: 0.78rem; color: var(--admin-muted); }
.vd-detail-value { font-size: 0.82rem; color: var(--admin-text); font-weight: 600; }

/* Items list */
.vd-item-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.vd-item-row:last-child { border-bottom: none; }

.vd-item-thumb {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: rgba(255,255,255,0.04);
    overflow: hidden;
    flex-shrink: 0;
}
.vd-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
.vd-item-name { font-size: 0.8rem; color: var(--admin-text); flex: 1; line-height: 1.3; }
.vd-item-price { font-size: 0.78rem; color: var(--admin-muted); font-weight: 600; white-space: nowrap; }

/* Close ticket panel */
.vd-close-panel {
    border-color: rgba(239,68,68,0.2);
}

.vd-close-label {
    display: block;
    font-size: 0.75rem;
    color: var(--admin-muted);
    margin-bottom: 6px;
    font-weight: 600;
}

.vd-close-select, .vd-close-textarea {
    width: 100%;
    background: var(--admin-bg);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    padding: 10px 12px;
    color: var(--admin-text);
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    margin-bottom: 12px;
    transition: border-color 0.2s;
}
.vd-close-select:focus, .vd-close-textarea:focus { outline: none; border-color: var(--admin-accent); }
.vd-close-textarea { resize: none; }

.vd-close-btn {
    width: 100%;
    background: var(--admin-accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 11px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.15s;
}
.vd-close-btn:hover { background: var(--admin-accent-hover); }

/* Resolution panel */
.vd-resolution-panel {
    border-color: rgba(52,211,153,0.2);
    background: rgba(52,211,153,0.02);
}
.vd-resolution-panel .vd-panel-header { border-bottom-color: rgba(52,211,153,0.1); }
.vd-resolution-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #34d399;
    margin-bottom: 8px;
}
.vd-resolution-text { font-size: 0.82rem; color: var(--admin-text); line-height: 1.6; }
.vd-resolution-date { font-size: 0.68rem; color: rgba(52,211,153,0.5); margin-top: 10px; display: flex; align-items: center; gap: 5px; }

/* Timeline mini */
.vd-timeline {
    display: flex;
    align-items: center;
    padding: 12px 18px;
    gap: 0;
    background: rgba(255,255,255,0.01);
    border-top: 1px solid rgba(255,255,255,0.03);
}
.vd-tl-step {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.62rem;
    color: var(--admin-muted);
    white-space: nowrap;
    font-weight: 600;
}
.vd-tl-step.done { color: #34d399; }
.vd-tl-step.active { color: var(--admin-text); }
.vd-tl-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--admin-border); flex-shrink: 0; }
.vd-tl-step.done .vd-tl-dot { background: #34d399; }
.vd-tl-step.active .vd-tl-dot { background: var(--admin-accent); box-shadow: 0 0 8px rgba(229,57,53,0.4); }
.vd-tl-line { width: 20px; height: 2px; background: var(--admin-border); margin: 0 3px; flex-shrink: 0; }

/* Responsive */
@media (max-width: 1000px) {
    .vd-layout { grid-template-columns: 1fr; }
    .vd-chat { height: 500px; }
}
</style>

<!-- Back link -->
<a href="disputes.php" class="vd-back"><i class="fas fa-arrow-left"></i> Back to Dispute Center</a>

<!-- Page Header -->
<div class="vd-header">
    <div class="vd-header-left">
        <span class="vd-ticket-id">Dispute #<?php echo $dispute['id']; ?></span>
        
        <?php $typeClass = strtolower($dispute['type']); ?>
        <span class="vd-type-badge <?php echo $typeClass; ?>">
            <i class="fas fa-<?php 
                echo match($dispute['type']) {
                    'not_received' => 'box-open',
                    'scam' => 'user-secret',
                    'damaged' => 'hammer',
                    'wrong_item' => 'exchange-alt',
                    'counterfeit' => 'clone',
                    default => 'question-circle'
                };
            ?>"></i>
            <?php echo ucwords(str_replace('_', ' ', $dispute['type'])); ?>
        </span>
        
        <span class="vd-age"><i class="far fa-clock"></i> <?php echo $ageStr; ?></span>
    </div>
    
    <div class="vd-header-right">
        <span class="vd-status-pill <?php echo $dispute['status']; ?>">
            <i class="fas fa-<?php echo $statusIcon; ?>"></i>
            <?php echo ucfirst($dispute['status']); ?>
        </span>
    </div>
</div>

<?php if ($error): ?>
    <div class="admin-alert error" style="margin-bottom:16px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Main Layout -->
<div class="vd-layout">
    
    <!-- ===== CHAT PANEL ===== -->
    <div class="vd-chat">
        <div class="vd-chat-header">
            <div class="vd-chat-title"><i class="fas fa-comments"></i> Resolution Thread</div>
            <div class="vd-chat-meta">
                <div class="dot <?php echo $isClosed ? 'closed' : 'live'; ?>"></div>
                <?php echo $isClosed ? 'Closed' : 'Active'; ?> · <?php echo count($messages); ?> messages
            </div>
        </div>
        
        <div class="vd-chat-body" id="chatBody">
            <!-- Original Statement -->
            <div class="vd-statement">
                <div class="vd-statement-label">
                    <i class="fas fa-quote-left"></i> 
                    Original Statement by <?php echo htmlspecialchars($dispute['reporter_name']); ?>
                    <?php if ($dispute['reporter_id'] == $dispute['buyer_id']): ?>
                        <span class="vd-role-badge buyer">Buyer</span>
                    <?php else: ?>
                        <span class="vd-role-badge seller">Seller</span>
                    <?php endif; ?>
                </div>
                <div class="vd-statement-text">"<?php echo nl2br(htmlspecialchars($dispute['description'])); ?>"</div>
            </div>

            <?php 
            $lastDate = '';
            foreach($messages as $msg): 
                $isAdmin = ($msg['sender_role'] === 'admin');
                $msgDate = date('M d, Y', strtotime($msg['created_at']));
                
                // Date separator
                if ($msgDate !== $lastDate):
                    $lastDate = $msgDate;
            ?>
                <div class="vd-date-sep"><span><?php echo $msgDate === date('M d, Y') ? 'Today' : $msgDate; ?></span></div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <!-- Admin message (right side) -->
                <div class="vd-msg from-admin">
                    <div class="vd-msg-content">
                        <div class="vd-msg-sender">
                            <span class="vd-role-badge admin"><i class="fas fa-shield-alt"></i> Admin</span>
                            You
                        </div>
                        
                        <?php if(!empty($msg['message'])): ?>
                        <div class="vd-bubble"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                        <?php endif; ?>
                        
                        <?php if(!empty($msg['image_path'])): ?>
                            <?php if (strtolower(pathinfo($msg['image_path'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                            <a href="../<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--admin-text); text-decoration:none; margin-top:8px;">
                                <i class="fas fa-file-pdf" style="color:#ef5350; font-size:1.5rem;"></i>
                                <span style="font-size:0.85rem; font-weight:600;">View PDF Document</span>
                            </a>
                            <?php else: ?>
                            <a href="../<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank">
                                <img src="../<?php echo htmlspecialchars($msg['image_path']); ?>" class="vd-attachment">
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="vd-msg-time"><?php echo date('g:i A', strtotime($msg['created_at'])); ?></div>
                    </div>
                    <div class="vd-msg-avatar" style="background: rgba(229,57,53,0.15); color: #ef5350; margin-top: 18px;">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                </div>
            
            <?php else: ?>
                <?php
                    $senderName = htmlspecialchars($msg['sender_name']);
                    $roleBadge = '';
                    if ($msg['sender_id'] == $dispute['buyer_id']) {
                        $roleBadge = '<span class="vd-role-badge buyer"><i class="fas fa-shopping-bag"></i> Buyer</span>';
                    } elseif ($msg['sender_id'] == $dispute['seller_id']) {
                        $roleBadge = '<span class="vd-role-badge seller"><i class="fas fa-store"></i> Seller</span>';
                    }
                ?>
                <!-- User message (left side) -->
                <div class="vd-msg from-other">
                    <div class="vd-msg-avatar">
                        <?php if(!empty($msg['avatar'])): ?>
                            <img src="../<?php echo htmlspecialchars($msg['avatar']); ?>">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="vd-msg-content">
                        <div class="vd-msg-sender">
                            <?php echo $senderName; ?>
                            <?php echo $roleBadge; ?>
                        </div>
                        
                        <?php if(!empty($msg['message'])): ?>
                        <div class="vd-bubble"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                        <?php endif; ?>
                        
                        <?php if(!empty($msg['image_path'])): ?>
                            <?php if (strtolower(pathinfo($msg['image_path'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                            <a href="../<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--admin-text); text-decoration:none; margin-top:8px;">
                                <i class="fas fa-file-pdf" style="color:#ef5350; font-size:1.5rem;"></i>
                                <span style="font-size:0.85rem; font-weight:600;">View PDF Document</span>
                            </a>
                            <?php else: ?>
                            <a href="../<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank">
                                <img src="../<?php echo htmlspecialchars($msg['image_path']); ?>" class="vd-attachment">
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="vd-msg-time"><?php echo date('g:i A', strtotime($msg['created_at'])); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php endforeach; ?>
            
            <?php if (empty($messages)): ?>
                <div style="text-align:center; color:var(--admin-muted); padding:40px 0; font-size:0.85rem;">
                    <i class="far fa-comment-dots" style="font-size:2rem; opacity:0.3; display:block; margin-bottom:12px;"></i>
                    No messages yet. Start the conversation below.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Chat Footer -->
        <div class="vd-chat-footer">
            <?php if ($isClosed): ?>
                <div class="vd-closed-banner">
                    <i class="fas fa-lock"></i> This dispute has been <?php echo $dispute['status']; ?>. The thread is locked.
                </div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data" id="msgForm">
                    <!-- File preview -->
                    <div class="vd-file-preview" id="filePreview">
                        <i class="fas fa-image"></i>
                        <span class="name" id="fileName"></span>
                        <span class="remove" onclick="clearAttachment()"><i class="fas fa-times"></i></span>
                    </div>
                    
                    <div class="vd-input-row">
                        <label class="vd-attach-btn" title="Attach Evidence" id="attachBtn">
                            <input type="file" name="attachment" accept="image/*,application/pdf" style="display:none;" onchange="handleAttachment(this)" id="fileInput">
                            <i class="fas fa-paperclip" id="clipIcon"></i>
                        </label>
                        
                        <textarea name="message" class="vd-textarea" rows="1" placeholder="Message buyer & seller..." id="msgInput" 
                                  onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); document.getElementById('msgForm').submit();}"
                                  oninput="autoResize(this)"></textarea>
                        
                        <button type="submit" name="send_message" class="vd-send-btn" title="Send Message">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ===== SIDEBAR ===== -->
    <div class="vd-sidebar">
        
        <!-- Parties -->
        <div class="vd-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title"><i class="fas fa-users"></i> Parties</div>
            </div>
            <div class="vd-panel-body">
                <!-- Buyer -->
                <div class="vd-party">
                    <div class="vd-party-avatar">
                        <?php if (!empty($dispute['buyer_avatar'])): ?>
                            <img src="../<?php echo htmlspecialchars($dispute['buyer_avatar']); ?>">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="vd-party-info">
                        <div class="vd-party-name">
                            <a href="users.php?search=<?php echo urlencode($dispute['buyer_name']); ?>" style="color:inherit; text-decoration:none;"><?php echo htmlspecialchars($dispute['buyer_name']); ?></a>
                            <span class="vd-party-tag buyer">Buyer</span>
                            <?php if ($dispute['reporter_id'] == $dispute['buyer_id']): ?>
                                <span class="vd-party-tag reporter">Reporter</span>
                            <?php endif; ?>
                        </div>
                        <div class="vd-party-email"><i class="fas fa-envelope"></i> <a href="mailto:<?php echo htmlspecialchars($dispute['buyer_email']); ?>"><?php echo htmlspecialchars($dispute['buyer_email']); ?></a></div>
                        <?php if(!empty($dispute['buyer_phone'])): ?>
                            <div class="vd-party-email" style="margin-top:4px;"><i class="fas fa-phone-alt"></i> <a href="tel:<?php echo htmlspecialchars($dispute['buyer_phone']); ?>"><?php echo htmlspecialchars($dispute['buyer_phone']); ?></a></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Seller -->
                <div class="vd-party">
                    <div class="vd-party-avatar">
                        <?php if (!empty($dispute['seller_avatar'])): ?>
                            <img src="../<?php echo htmlspecialchars($dispute['seller_avatar']); ?>">
                        <?php else: ?>
                            <i class="fas fa-store"></i>
                        <?php endif; ?>
                    </div>
                    <div class="vd-party-info">
                        <div class="vd-party-name">
                            <a href="users.php?search=<?php echo urlencode($dispute['seller_name']); ?>" style="color:inherit; text-decoration:none;"><?php echo htmlspecialchars($dispute['seller_name']); ?></a>
                            <span class="vd-party-tag seller">Seller</span>
                            <?php if ($dispute['reporter_id'] == $dispute['seller_id']): ?>
                                <span class="vd-party-tag reporter">Reporter</span>
                            <?php endif; ?>
                        </div>
                        <div class="vd-party-email"><i class="fas fa-envelope"></i> <a href="mailto:<?php echo htmlspecialchars($dispute['seller_email']); ?>"><?php echo htmlspecialchars($dispute['seller_email']); ?></a></div>
                        <?php if(!empty($dispute['seller_phone'])): ?>
                            <div class="vd-party-email" style="margin-top:4px;"><i class="fas fa-phone-alt"></i> <a href="tel:<?php echo htmlspecialchars($dispute['seller_phone']); ?>"><?php echo htmlspecialchars($dispute['seller_phone']); ?></a></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Mini timeline -->
            <div class="vd-timeline">
                <?php
                $steps = ['open', 'investigating', $dispute['status'] === 'dismissed' ? 'dismissed' : 'resolved'];
                $labels = ['Opened', 'Investigating', $dispute['status'] === 'dismissed' ? 'Dismissed' : 'Resolved'];
                $currentStep = array_search($dispute['status'], $steps);
                if ($currentStep === false) $currentStep = 0;
                
                foreach ($steps as $idx => $step):
                    $class = '';
                    if ($idx < $currentStep) $class = 'done';
                    elseif ($idx == $currentStep) $class = 'active';
                ?>
                    <?php if ($idx > 0): ?><div class="vd-tl-line"></div><?php endif; ?>
                    <div class="vd-tl-step <?php echo $class; ?>">
                        <div class="vd-tl-dot"></div>
                        <?php echo $labels[$idx]; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Order Details -->
        <div class="vd-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title"><i class="fas fa-receipt"></i> Order</div>
                <a href="orders.php?search=<?php echo $dispute['order_id']; ?>" class="vd-panel-link">View Order →</a>
            </div>
            <div class="vd-panel-body">
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Order ID</span>
                    <span class="vd-detail-value">#<?php echo $dispute['order_id']; ?></span>
                </div>
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Total</span>
                    <span class="vd-detail-value" style="color:var(--admin-accent);">₹<?php echo number_format($dispute['total']); ?></span>
                </div>
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Status</span>
                    <span class="vd-detail-value"><span class="badge-sm <?php echo $dispute['order_status']; ?>"><?php echo ucfirst($dispute['order_status']); ?></span></span>
                </div>
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Payment</span>
                    <span class="vd-detail-value" style="font-size:0.75rem; text-transform:uppercase;"><?php echo $dispute['payment_method'] ?? '—'; ?></span>
                </div>
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Payment Status</span>
                    <span class="vd-detail-value" style="font-size:0.75rem; text-transform:uppercase;"><?php echo ucfirst($dispute['payment_status']); ?></span>
                </div>
                <div class="vd-detail-row">
                    <span class="vd-detail-label">Ordered</span>
                    <span class="vd-detail-value" style="font-size:0.75rem;"><?php echo date('M d, Y', strtotime($dispute['order_date'])); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Evidence Documents -->
        <?php if (!empty($dispute['payment_proof']) || !empty($dispute['seller_statement'])): ?>
        <div class="vd-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title"><i class="fas fa-file-invoice-dollar"></i> Evidence</div>
            </div>
            <div class="vd-panel-body">
                <?php if (!empty($dispute['payment_proof'])): ?>
                <div style="margin-bottom:12px;">
                    <span class="vd-detail-label" style="display:block; margin-bottom:4px;">Buyer's Payment Proof</span>
                    <a href="../<?php echo htmlspecialchars($dispute['payment_proof']); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:6px; background:rgba(79,195,247,0.1); color:#4fc3f7; padding:6px 12px; border-radius:6px; font-size:0.8rem; text-decoration:none;">
                        <i class="fas fa-image"></i> View Receipt
                    </a>
                </div>
                <?php endif; ?>

                <?php if (!empty($dispute['seller_statement'])): ?>
                <div>
                    <span class="vd-detail-label" style="display:block; margin-bottom:4px;">Seller's Bank Statement</span>
                    <a href="../<?php echo htmlspecialchars($dispute['seller_statement']); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:6px; background:rgba(229,57,53,0.1); color:#ef5350; padding:6px 12px; border-radius:6px; font-size:0.8rem; text-decoration:none;">
                        <i class="fas fa-file-pdf"></i> View Statement
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Purchased Items -->
        <?php if (!empty($orderItems)): ?>
        <div class="vd-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title"><i class="fas fa-box-open"></i> Items (<?php echo count($orderItems); ?>)</div>
            </div>
            <div class="vd-panel-body">
                <?php foreach ($orderItems as $item): ?>
                <div class="vd-item-row">
                    <div class="vd-item-thumb">
                        <?php if (!empty($item['image_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($item['image_path']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="vd-item-name">
                        <?php echo htmlspecialchars($item['title']); ?>
                        <?php if (isset($item['quantity']) && $item['quantity'] > 1): ?>
                            <span style="color:var(--admin-muted); font-size:0.72rem;"> ×<?php echo $item['quantity']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="vd-item-price">₹<?php echo number_format($item['price']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Close Ticket / Resolution -->
        <?php if (!$isClosed): ?>
        <div class="vd-panel vd-close-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title" style="color:#ef5350;"><i class="fas fa-gavel" style="color:#ef5350;"></i> Close Ticket</div>
            </div>
            <div class="vd-panel-body">
                <form method="POST">
                    <input type="hidden" name="close_ticket" value="1">
                    
                    <label class="vd-close-label">Verdict</label>
                    <select name="action_status" required class="vd-close-select">
                        <option value="resolved">✅ Mark Resolved (Action Taken)</option>
                        <option value="dismissed">❌ Dismiss (No Action Needed)</option>
                    </select>
                    
                    <label class="vd-close-label">Resolution Note</label>
                    <textarea name="resolution_notes" required rows="3" class="vd-close-textarea" placeholder="Summarize the final outcome..."></textarea>
                    
                    <button type="submit" class="vd-close-btn" onclick="return confirm('Close this dispute permanently? This cannot be undone.');">
                        <i class="fas fa-lock"></i> Close Dispute
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="vd-panel vd-resolution-panel">
            <div class="vd-panel-header">
                <div class="vd-panel-title" style="color:#34d399;"><i class="fas fa-shield-alt" style="color:#34d399;"></i> Resolution</div>
            </div>
            <div class="vd-panel-body">
                <div class="vd-resolution-text"><?php echo nl2br(htmlspecialchars($dispute['resolution_notes'])); ?></div>
                <?php if ($dispute['resolved_at']): ?>
                    <div class="vd-resolution-date"><i class="far fa-calendar-check"></i> Closed on <?php echo date('M d, Y \a\t h:i A', strtotime($dispute['resolved_at'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Dispute Filed Info -->
        <div style="text-align:center; font-size:0.7rem; color:var(--admin-muted); padding:4px 0;">
            <i class="far fa-calendar-alt"></i> 
            Filed on <?php echo date('M d, Y \a\t h:i A', strtotime($dispute['created_at'])); ?>
        </div>
        
    </div>
</div>

<script>
    // Scroll chat to bottom
    const chatBody = document.getElementById('chatBody');
    if(chatBody) chatBody.scrollTop = chatBody.scrollHeight;
    
    // Auto-resize textarea
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    }
    
    // File attachment handling
    function handleAttachment(input) {
        const preview = document.getElementById('filePreview');
        const nameEl = document.getElementById('fileName');
        const btn = document.getElementById('attachBtn');
        
        if (input.files && input.files[0]) {
            preview.classList.add('active');
            nameEl.textContent = input.files[0].name;
            btn.classList.add('attached');
            const icon = preview.querySelector('i');
            if(input.files[0].type === 'application/pdf' || input.files[0].name.toLowerCase().endsWith('.pdf')) {
                icon.className = 'fas fa-file-pdf';
            } else {
                icon.className = 'fas fa-image';
            }
        }
    }
    
    function clearAttachment() {
        const input = document.getElementById('fileInput');
        const preview = document.getElementById('filePreview');
        const btn = document.getElementById('attachBtn');
        
        input.value = '';
        preview.classList.remove('active');
        btn.classList.remove('attached');
    }
</script>

<?php include 'footer.php'; ?>
