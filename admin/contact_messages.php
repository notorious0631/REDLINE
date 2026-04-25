<?php
include 'header.php';

// Handle Mark as Read
if (isset($_GET['mark_read'])) {
    $readId = intval($_GET['mark_read']);
    try {
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
        $stmt->execute([$readId]);
        
        // Add alert to history
        $stmt = $conn->prepare("INSERT INTO alert_history (type, message, related_id) VALUES ('system', 'Contact message marked as read', ?)");
        $stmt->execute([$readId]);
        
        echo "<script>window.location.href='contact_messages.php';</script>";
        exit;
    } catch (PDOException $e) {}
}

// Handle Delete
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    try {
        $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
        $stmt->execute([$delId]);
        echo "<script>window.location.href='contact_messages.php';</script>";
        exit;
    } catch (PDOException $e) {}
}

// Fetch Messages
$messages = [];
try {
    $stmt = $conn->query("SELECT * FROM contact_messages ORDER BY is_read ASC, created_at DESC");
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

?>

<div class="admin-header">
    <div class="admin-title">
        <h1>Contact Messages</h1>
        <p>Manage and review inquiries from users</p>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: 1fr;">
    <div class="admin-card">
        <h2 style="font-size: 1.2rem; margin-bottom: 24px; color: #fff;">Inbox</h2>
        
        <?php if (empty($messages)): ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-envelope-open" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.5;"></i>
                <p>No contact messages yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Sender</th>
                            <th style="width: 50%;">Message</th>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($messages as $msg): ?>
                            <tr style="<?php echo $msg['is_read'] == 0 ? 'background: rgba(229,57,53,0.05);' : ''; ?>">
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div style="width:36px; height:36px; border-radius:50%; background:var(--bg-layer-2); display:flex; align-items:center; justify-content:center; font-weight:bold; color:var(--text-primary); border:1px solid var(--border-color);">
                                            <?php echo strtoupper(substr($msg['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600; color:var(--text-primary);">
                                                <?php echo htmlspecialchars($msg['name']); ?>
                                                <?php if($msg['is_read'] == 0): ?>
                                                    <span style="background:var(--accent-red); color:#fff; font-size:0.5rem; padding:2px 4px; border-radius:4px; margin-left:6px; vertical-align:middle; text-transform:uppercase; font-weight:bold; letter-spacing:0.5px;">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:0.8rem; color:var(--text-secondary);">
                                                <a href="mailto:<?php echo htmlspecialchars($msg['email']); ?>" style="color:var(--text-secondary); text-decoration:none;">
                                                    <?php echo htmlspecialchars($msg['email']); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <p style="margin:0; font-size: 0.9rem; color:<?php echo $msg['is_read'] == 0 ? 'var(--text-primary)' : 'var(--text-secondary)'; ?>; max-width:500px; white-space:pre-wrap; line-height: 1.5;"><?php echo htmlspecialchars($msg['message']); ?></p>
                                </td>
                                <td style="font-size: 0.85rem; color:var(--text-muted);">
                                    <?php echo date('M d, Y h:i A', strtotime($msg['created_at'])); ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:8px;">
                                        <?php if($msg['is_read'] == 0): ?>
                                            <a href="contact_messages.php?mark_read=<?php echo $msg['id']; ?>" class="action-btn" title="Mark as Read" style="background:rgba(76,175,80,0.1); color:#81c784; border-color:rgba(76,175,80,0.3);">
                                                <i class="fas fa-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="mailto:<?php echo htmlspecialchars($msg['email']); ?>?subject=Reply from REDLINE Support" class="action-btn" title="Reply via Email" style="background:rgba(33,150,243,0.1); color:#64b5f6; border-color:rgba(33,150,243,0.3);">
                                            <i class="fas fa-reply"></i>
                                        </a>
                                        <a href="contact_messages.php?delete=<?php echo $msg['id']; ?>" onclick="return confirm('Are you sure you want to permanently delete this message?');" class="action-btn delete" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<?php include 'footer.php'; ?>
