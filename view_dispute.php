<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$disputeId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = '';
$success = '';

if (!$disputeId) {
    header('Location: disputes.php');
    exit;
}

// Fetch dispute and verify permission
try {
    $stmt = $conn->prepare("
        SELECT d.*, o.buyer_id, o.seller_id, o.id as order_id, o.total,
               u.name as reporter_name
        FROM order_disputes d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON d.reporter_id = u.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        header('Location: disputes.php');
        exit;
    }

    if ($dispute['buyer_id'] != $userId && $dispute['seller_id'] != $userId) {
        // User not authorized
        header('Location: disputes.php');
        exit;
    }

    $counterpartyId = ($userId == $dispute['buyer_id']) ? $dispute['seller_id'] : $dispute['buyer_id'];

} catch (PDOException $e) {
    $error = "Database Error.";
}

// Handle new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && empty($error)) {
    if (in_array($dispute['status'], ['resolved', 'dismissed'])) {
        $error = "This ticket is closed. You can no longer send messages.";
    } else {
        $message = trim($_POST['message'] ?? '');
        $imagePath = null;
        
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $uploadDir = 'uploads/disputes/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $imagePath = $uploadDir . 'disp_' . $disputeId . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['attachment']['tmp_name'], $imagePath);
        }
        
        if (empty($message) && !$imagePath) {
            $error = "Please enter a message or upload an attachment.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO dispute_messages (dispute_id, sender_id, message, image_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$disputeId, $userId, $message, $imagePath]);
                
                // Notify counterparty
                $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_message', ?, ?)")
                     ->execute([$counterpartyId, "New message on Dispute #$disputeId regarding Order #{$dispute['order_id']}.", "view_dispute.php?id=$disputeId"]);
                
                // Notify Admins
                $stmtAdmin = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                $admins = $stmtAdmin->fetchAll(PDO::FETCH_COLUMN);
                $adminNotify = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'dispute_message', ?, ?)");
                foreach($admins as $adminId) {
                    $adminNotify->execute([$adminId, "New activity on Dispute #$disputeId", "admin/view_dispute.php?id=$disputeId"]);
                }
                
                // Redirect to avoid form resubmission
                header("Location: view_dispute.php?id=$disputeId");
                exit;
            } catch (PDOException $e) {
                $error = "Failed to send message.";
            }
        }
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

$pageTitle = 'View Dispute #' . $disputeId;
include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/profile.css">
<style>
    .dispute-container { max-width: 800px; margin: 40px auto; }
    
    .status-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;
    }
    .status-open { color: #fbbf24; background: rgba(251,191,36,0.15); }
    .status-investigating { color: #60a5fa; background: rgba(96,165,250,0.15); }
    .status-resolved { color: #34d399; background: rgba(52,211,153,0.15); }
    .status-dismissed { color: #ef4444; background: rgba(239,68,68,0.15); }

    .chat-box { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); overflow: hidden; margin-top: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
    .chat-header { background: rgba(255,255,255,0.02); padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center; }
    .chat-body { padding: 24px; max-height: 500px; overflow-y: auto; background: var(--bg-body); display: flex; flex-direction: column; gap: 20px; }
    .chat-footer { padding: 16px 24px; background: rgba(255,255,255,0.02); border-top: 1px solid rgba(255,255,255,0.05); }

    .message-wrapper { display: flex; flex-direction: column; width: 100%; }
    .message-row { display: flex; gap: 12px; margin-bottom: 4px; max-width: 80%; }
    
    .msg-buyer { align-self: flex-start; }
    .msg-seller { align-self: flex-start; } /* Both on left if opposing? Let's put current user on right */
    
    .msg-me { align-self: flex-end; }
    .msg-me .message-row { flex-direction: row-reverse; margin-left: auto; }
    
    .msg-admin { align-self: center; width: 100%; max-width: 100%; justify-content: center; margin: 10px 0; }
    .msg-admin .message-row { max-width: 90%; justify-content: center; }
    
    .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; color: var(--text-muted); flex-shrink: 0; overflow:hidden;}
    .avatar img { width: 100%; height: 100%; object-fit: cover; }
    
    .bubble { padding: 12px 16px; border-radius: 12px; font-size: 0.9rem; line-height: 1.5; color: var(--text-primary); }
    .msg-me .bubble { background: var(--accent-red); border-bottom-right-radius: 2px; }
    .msg-buyer .bubble, .msg-seller .bubble { background: var(--bg-surface); border: 1px solid var(--border-color); border-bottom-left-radius: 2px; }
    .msg-admin .bubble { background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.3); border-radius: 12px; color: #fbbf24; text-align: center; }

    .msg-meta { font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; }
    .msg-me .msg-meta { text-align: right; }
    .msg-admin .msg-meta { text-align: center; }
    
    .attachment-img { max-width: 200px; border-radius: 8px; margin-top: 8px; cursor: pointer; border: 1px solid rgba(255,255,255,0.1); }
    .msg-me .attachment-img { display: block; margin-left: auto; }

    .sender-name-wrap { position: relative; display: inline-flex; align-items: center; gap: 6px; cursor: default; }
    .role-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0; transform: translateY(4px); transition: opacity 0.2s ease, transform 0.2s ease; pointer-events: none; }
    .sender-name-wrap:hover .role-tag { opacity: 1; transform: translateY(0); }
    .role-tag.buyer { background: rgba(96,165,250,0.2); color: #60a5fa; border: 1px solid rgba(96,165,250,0.3); }
    .role-tag.seller { background: rgba(251,146,60,0.2); color: #fb923c; border: 1px solid rgba(251,146,60,0.3); }

    .upload-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 12px; border-radius: 8px; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
    .upload-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
    
    .input-row { display: flex; gap: 12px; align-items: flex-end; }
    .chat-textarea { flex: 1; background: var(--bg-body); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 12px 16px; color: var(--text-primary); font-family: inherit; resize: none; line-height: 1.5; }
    .chat-textarea:focus { outline: none; border-color: var(--accent-red); }
</style>

<div class="container-rl" style="padding-top:20px; padding-bottom:60px;">
    
    <a href="disputes.php" style="color:var(--text-muted); font-size:0.85rem; text-decoration:none; margin-bottom:20px; display:inline-block;"><i class="fas fa-arrow-left"></i> Back to My Tickets</a>

    <?php if ($error): ?>
        <div style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e57373; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="dispute-container">
        
        <!-- Ticket Info -->
        <div style="background:var(--bg-surface); padding:24px; border-radius:var(--radius-lg); border:1px solid var(--border-color); box-shadow:0 5px 20px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                <div>
                    <h2 style="font-size:1.4rem; font-family:var(--font-display); margin-bottom:4px;">Dispute #<?php echo $dispute['id']; ?> - Order #<?php echo $dispute['order_id']; ?></h2>
                    <div style="font-size:0.85rem; color:var(--text-muted);">Nature of issue: <strong style="color:var(--text-primary); text-transform:uppercase;"><?php echo str_replace('_', ' ', $dispute['type']); ?></strong></div>
                </div>
                <div>
                    <?php 
                        $statusClass = 'status-' . $dispute['status'];
                        $icon = 'exclamation-circle';
                        if($dispute['status'] == 'investigating') $icon = 'search';
                        if($dispute['status'] == 'resolved') $icon = 'check-double';
                        if($dispute['status'] == 'dismissed') $icon = 'times-circle';
                    ?>
                    <span class="status-badge <?php echo $statusClass; ?>"><i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $dispute['status']; ?></span>
                </div>
            </div>
            
            <div style="background:rgba(255,255,255,0.02); padding:16px; border-radius:8px; border:1px solid rgba(255,255,255,0.05);">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">Original Statement (<?php echo htmlspecialchars($dispute['reporter_name']); ?>):</div>
                <div style="font-size:0.95rem; color:var(--text-secondary); line-height:1.5;">
                    "<?php echo nl2br(htmlspecialchars($dispute['description'])); ?>"
                </div>
            </div>

            <?php if (!empty($dispute['resolution_notes'])): ?>
            <div style="margin-top:16px; background:rgba(52,211,153,0.05); padding:16px; border-radius:8px; border:1px solid rgba(52,211,153,0.2);">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#34d399; margin-bottom:6px;"><i class="fas fa-shield-alt"></i> Redline Resolution:</div>
                <div style="font-size:0.95rem; color:var(--text-primary); line-height:1.5;">
                    <?php echo nl2br(htmlspecialchars($dispute['resolution_notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Chat Box -->
        <div class="chat-box">
            <div class="chat-header">
                <div style="font-weight:700; font-size:1.1rem;"><i class="far fa-comments"></i> Resolution Center</div>
                <div style="font-size:0.8rem; color:var(--text-muted);">Administrators will intervene fully if required.</div>
            </div>
            
            <div class="chat-body" id="chatBody">
                <div style="text-align:center; color:var(--text-muted); font-size:0.8rem; margin-bottom:10px;">
                    <i class="fas fa-lock"></i> Chat started. All messages and evidence are visible to the buyer, seller, and admin.
                </div>

                <?php foreach($messages as $msg): 
                    $isMe = ($msg['sender_id'] == $userId);
                    $isAdmin = ($msg['sender_role'] === 'admin');
                    
                    $msgClass = 'msg-buyer';
                    if ($isMe) $msgClass = 'msg-me';
                    elseif ($isAdmin) $msgClass = 'msg-admin';
                    else $msgClass = 'msg-seller'; // Counterparty

                    $senderTitle = $isMe ? 'You' : ($isAdmin ? 'Redline Support' : htmlspecialchars($msg['sender_name']));
                    $roleTag = '';
                    if (!$isAdmin) {
                        if ($msg['sender_id'] == $dispute['buyer_id']) $roleTag = '<span class="role-tag buyer"><i class="fas fa-shopping-bag"></i> Buyer</span>';
                        elseif ($msg['sender_id'] == $dispute['seller_id']) $roleTag = '<span class="role-tag seller"><i class="fas fa-store"></i> Seller</span>';
                    }
                ?>
                <div class="message-wrapper <?php echo $msgClass; ?>">
                    <div class="message-row">
                        <?php if(!$isMe && !$isAdmin): ?>
                            <div class="avatar">
                                <?php if(!empty($msg['avatar'])): ?><img src="<?php echo htmlspecialchars($msg['avatar']); ?>"><?php else: ?><i class="fas fa-user"></i><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div>
                            <?php if(!$isMe && !$isAdmin): ?>
                            <div style="font-size:0.7rem; color:var(--text-muted); margin-bottom:4px; margin-left:4px;"><span class="sender-name-wrap"><?php echo $senderTitle; ?> <?php echo $roleTag; ?></span></div>
                            <?php endif; ?>
                            
                            <?php if(!empty($msg['message'])): ?>
                            <div class="bubble">
                                <?php if($isAdmin) echo '<strong>Admin:</strong> '; ?>
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if(!empty($msg['image_path'])): ?>
                                <?php if (strtolower(pathinfo($msg['image_path'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                                <a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:var(--text-primary); text-decoration:none; margin-top:8px;">
                                    <i class="fas fa-file-pdf" style="color:#ef4444; font-size:1.5rem;"></i>
                                    <span style="font-size:0.85rem; font-weight:600;">View PDF Document</span>
                                </a>
                                <?php else: ?>
                                <a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($msg['image_path']); ?>" class="attachment-img">
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div class="msg-meta">
                                <?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Message Input Form -->
            <div class="chat-footer">
                <?php if (in_array($dispute['status'], ['resolved', 'dismissed'])): ?>
                    <div style="text-align:center; color:var(--text-muted); padding:10px;">
                        <i class="fas fa-ban"></i> This dispute is closed. You can no longer send messages.
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="input-row">
                            <label class="upload-btn" title="Upload Evidence">
                                <input type="file" name="attachment" accept="image/*,application/pdf" style="display:none;" onchange="updateIcon(this)">
                                <i class="fas fa-paperclip" id="clipIcon"></i>
                            </label>
                            
                            <textarea name="message" class="chat-textarea" rows="2" placeholder="Reply with details or evidence..."></textarea>
                            
                            <button type="submit" name="send_message" class="btn-red" style="height: 100%; border-radius:8px; padding:0 24px;"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
</div>

<script>
    // Scroll to bottom of chat
    const chatBody = document.getElementById('chatBody');
    if(chatBody) {
        chatBody.scrollTop = chatBody.scrollHeight;
    }
    
    function updateIcon(input) {
        const icon = document.getElementById('clipIcon');
        if (input.files && input.files[0]) {
            icon.classList.remove('fa-paperclip');
            icon.classList.add('fa-check-circle');
            icon.style.color = '#81c784';
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
