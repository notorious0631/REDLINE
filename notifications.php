<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Initial Fetch
$notifications = [];
try {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$pageTitle = 'My Notifications';
include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/profile.css">
<style>
.notifications-page { min-height: 70vh; padding: 40px 0 80px; }
.notif-container { max-width: 800px; margin: 0 auto; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-lg); overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
.notif-page-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(255,255,255,0.015); }
.notif-page-title { font-size: 1.4rem; font-family: var(--font-display); font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px; }
.notif-page-title i { color: var(--accent-red); }

.notif-list { display: flex; flex-direction: column; }
.notif-row { display: flex; gap: 16px; padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s; text-decoration: none; color: var(--text-primary); }
.notif-row:last-child { border-bottom: none; }
.notif-row:hover { background: rgba(255,255,255,0.03); color: var(--text-primary); }

.notif-row.unread { background: rgba(229,57,53,0.04); position: relative; }
.notif-row.unread::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--accent-red); }
.notif-row.unread:hover { background: rgba(229,57,53,0.08); }

.notif-icon-wrap { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: var(--accent-red); flex-shrink: 0; }
.notif-row.unread .notif-icon-wrap { background: rgba(229,57,53,0.15); }

.notif-details { flex: 1; }
.notif-msg { font-size: 0.95rem; line-height: 1.5; margin-bottom: 6px; }
.notif-meta { font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }

.notif-empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.notif-empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.3; }
.notif-empty-state h3 { font-size: 1.2rem; font-weight: 600; margin-bottom: 8px; color: var(--text-primary); }
</style>

<div class="notifications-page container-rl">
    <div class="notif-container" data-aos="fade-up">
        <div class="notif-page-header">
            <h1 class="notif-page-title"><i class="fas fa-bell"></i> Notifications</h1>
            <?php 
                $hasUnread = false;
                foreach($notifications as $n) { if(!$n['is_read']) $hasUnread = true; }
            ?>
            <?php if($hasUnread): ?>
            <button class="btn-red" id="markAllBtn" onclick="markAllNotificationsPage()" style="padding: 8px 16px; font-size: 0.8rem; border-radius: 6px;">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
            <?php endif; ?>
        </div>
        
        <div class="notif-list" id="notifList">
            <?php if(empty($notifications)): ?>
                <div class="notif-empty-state">
                    <i class="far fa-bell-slash"></i>
                    <h3>No Notifications Yet</h3>
                    <p>When you get updates, they'll appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach($notifications as $notif): ?>
                    <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" class="notif-row <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                        <div class="notif-icon-wrap">
                            <?php 
                                $typeStr = strtolower($notif['type'] ?? '');
                                $icon = 'info-circle';
                                if(strpos($typeStr, 'order') !== false) $icon = 'box';
                                if(strpos($typeStr, 'payment') !== false) $icon = 'wallet';
                                if(strpos($typeStr, 'dispute') !== false) $icon = 'gavel';
                                if(strpos($typeStr, 'chat') !== false || strpos($typeStr, 'message') !== false) $icon = 'comment-alt';
                                if(strpos($typeStr, 'negotiation') !== false || strpos($typeStr, 'offer') !== false) $icon = 'handshake';
                            ?>
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                        </div>
                        <div class="notif-details">
                            <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <div class="notif-meta">
                                <i class="far fa-clock"></i>
                                <?php 
                                    $date = new DateTime($notif['created_at']);
                                    echo $date->format('M d, Y \a\t h:i A'); 
                                ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function markAllNotificationsPage() {
    fetch('api/notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=mark_read'
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            // Update UI on page
            document.querySelectorAll('.notif-row.unread').forEach(item => {
                item.classList.remove('unread');
            });
            const btn = document.getElementById('markAllBtn');
            if(btn) btn.style.display = 'none';
            
            // Also trigger the header's badge removal if it exists
            let badge = document.getElementById('notifBadgeCount');
            if(badge) badge.style.display = 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
