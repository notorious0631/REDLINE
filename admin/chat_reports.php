<?php
$pageTitle = 'Chat Reports';
include 'header.php';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['report_id'])) {
    $reportId   = intval($_POST['report_id']);
    $newStatus  = $_POST['new_status'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    if (in_array($newStatus, ['reviewed', 'dismissed'])) {
        try {
            $conn->prepare("UPDATE chat_reports SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([$newStatus, $adminNotes, $reportId]);
        } catch (PDOException $e) {}
    }
    header("Location: chat_reports.php");
    exit;
}

// Fetch reports
$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['all', 'pending', 'reviewed', 'dismissed'];
if (!in_array($filter, $validFilters)) $filter = 'pending';

$where = $filter === 'all' ? '1=1' : "cr.status = '$filter'";

$reports = [];
try {
    $stmt = $conn->query("
        SELECT cr.*,
               reporter.name AS reporter_name, reporter.avatar AS reporter_avatar,
               reported.name AS reported_name, reported.avatar AS reported_avatar,
               c.type AS conv_type, c.listing_id,
               l.title AS listing_title
        FROM chat_reports cr
        JOIN users reporter ON cr.reporter_id = reporter.id
        JOIN users reported ON cr.reported_user_id = reported.id
        JOIN conversations c ON cr.conversation_id = c.id
        LEFT JOIN listings l ON c.listing_id = l.id
        WHERE $where
        ORDER BY cr.created_at DESC
    ");
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Count by status
$counts = ['pending' => 0, 'reviewed' => 0, 'dismissed' => 0];
try {
    $stmt = $conn->query("SELECT status, COUNT(*) as cnt FROM chat_reports GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['status']] = $row['cnt'];
    }
} catch (PDOException $e) {}
$counts['all'] = array_sum($counts);
?>

<style>
.cr-page { max-width: 1000px; }
.cr-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.cr-header h1 { font-size: 1.6rem; font-weight: 800; }
.cr-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.cr-filter {
    padding: 6px 14px; border-radius: 8px; font-size: 0.78rem; font-weight: 700;
    border: 1px solid rgba(255,255,255,0.1); background: transparent; color: #888;
    cursor: pointer; text-transform: uppercase; letter-spacing: 0.04em;
    text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 5px;
}
.cr-filter.active { background: var(--accent-red, #e53935); border-color: var(--accent-red, #e53935); color: #fff; }
.cr-filter:hover:not(.active) { border-color: rgba(255,255,255,0.2); color: #ccc; }
.cr-filter .cnt { font-size: 0.65rem; background: rgba(255,255,255,0.15); padding: 1px 6px; border-radius: 10px; }
.cr-filter.active .cnt { background: rgba(255,255,255,0.25); }

.cr-card {
    background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 14px; padding: 20px; margin-bottom: 16px;
    transition: border-color 0.2s;
}
.cr-card:hover { border-color: rgba(255,255,255,0.12); }

.cr-card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap:8px; }
.cr-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
.cr-badge.pending { background: rgba(255,183,77,0.12); color: #ffb74d; }
.cr-badge.reviewed { background: rgba(76,175,80,0.12); color: #81c784; }
.cr-badge.dismissed { background: rgba(255,255,255,0.06); color: #888; }

.cr-users { display: flex; gap: 20px; margin-bottom: 12px; flex-wrap: wrap; }
.cr-user { display: flex; align-items: center; gap: 10px; }
.cr-user-avatar {
    width: 36px; height: 36px; border-radius: 50%; overflow: hidden;
    background: rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: center;
    color: #888; font-weight: 700;
}
.cr-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.cr-user-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; color: #888; }
.cr-user-name { font-size: 0.88rem; font-weight: 600; }

.cr-reason { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 14px; font-size: 0.88rem; color: var(--text-secondary, #b0b0b0); line-height: 1.6; margin-bottom: 14px; }
.cr-reason-lbl { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; color: #888; margin-bottom: 6px; font-weight: 700; }

.cr-meta { font-size: 0.75rem; color: #666; display: flex; gap: 16px; flex-wrap: wrap; }

.cr-actions-form { display: flex; gap: 8px; margin-top: 14px; align-items: flex-end; flex-wrap: wrap; }
.cr-actions-form textarea { flex: 1; min-width: 200px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #fff; padding: 8px 12px; font-size: 0.82rem; resize: none; font-family: inherit; min-height: 38px; }
.cr-action-btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.78rem; cursor: pointer; transition: all 0.2s; font-family: inherit; }
.cr-btn-review { background: #4caf50; color: #fff; }
.cr-btn-review:hover { background: #43a047; }
.cr-btn-dismiss { background: rgba(255,255,255,0.06); color: #888; border: 1px solid rgba(255,255,255,0.1); }
.cr-btn-dismiss:hover { background: rgba(255,255,255,0.1); color: #ccc; }

.cr-empty { text-align: center; padding: 60px 20px; color: #666; }
.cr-empty i { font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: 0.15; }

.cr-admin-notes { margin-top: 10px; padding: 10px 14px; background: rgba(76,175,80,0.04); border: 1px solid rgba(76,175,80,0.15); border-radius: 8px; font-size: 0.82rem; color: #81c784; }
.cr-admin-notes .note-lbl { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(76,175,80,0.6); margin-bottom: 4px; font-weight: 700; }
</style>

<div class="cr-page">
    <div class="cr-header">
        <h1><i class="fas fa-flag" style="color:#ff9800;margin-right:10px;"></i> Chat Reports</h1>
        <div class="cr-filters">
            <?php foreach (['all', 'pending', 'reviewed', 'dismissed'] as $f): ?>
            <a href="?filter=<?php echo $f; ?>" class="cr-filter <?php echo $filter === $f ? 'active' : ''; ?>">
                <?php echo ucfirst($f); ?> <span class="cnt"><?php echo $counts[$f]; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($reports)): ?>
        <div class="cr-empty">
            <i class="fas fa-flag"></i>
            <h3>No <?php echo $filter !== 'all' ? $filter : ''; ?> reports</h3>
            <p>Chat reports from users will appear here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($reports as $r): ?>
        <div class="cr-card">
            <div class="cr-card-top">
                <span>Report #<?php echo $r['id']; ?></span>
                <span class="cr-badge <?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span>
            </div>

            <div class="cr-users">
                <div class="cr-user">
                    <div class="cr-user-avatar">
                        <?php if ($r['reporter_avatar']): ?>
                            <img src="../<?php echo htmlspecialchars($r['reporter_avatar']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($r['reporter_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="cr-user-label">Reporter</div>
                        <div class="cr-user-name"><?php echo htmlspecialchars($r['reporter_name']); ?></div>
                    </div>
                </div>
                <div class="cr-user">
                    <div class="cr-user-avatar" style="border: 2px solid rgba(229,57,53,0.3);">
                        <?php if ($r['reported_avatar']): ?>
                            <img src="../<?php echo htmlspecialchars($r['reported_avatar']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($r['reported_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="cr-user-label" style="color:#e57373;">Reported User</div>
                        <div class="cr-user-name" style="color:#ef5350;"><?php echo htmlspecialchars($r['reported_name']); ?></div>
                    </div>
                </div>
            </div>

            <div class="cr-reason-lbl"><i class="fas fa-quote-left" style="margin-right:4px;font-size:0.55rem;"></i> Reason</div>
            <div class="cr-reason"><?php echo nl2br(htmlspecialchars($r['reason'])); ?></div>

            <div class="cr-meta">
                <span><i class="fas fa-clock"></i> <?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></span>
                <span><i class="fas fa-<?php echo $r['conv_type'] === 'direct' ? 'user-friends' : ($r['conv_type'] === 'selling' ? 'store' : 'shopping-bag'); ?>"></i> <?php echo ucfirst($r['conv_type']); ?> chat</span>
                <?php if ($r['listing_title']): ?>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($r['listing_title']); ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($r['admin_notes'])): ?>
                <div class="cr-admin-notes">
                    <div class="note-lbl">Admin Notes</div>
                    <?php echo nl2br(htmlspecialchars($r['admin_notes'])); ?>
                </div>
            <?php endif; ?>

            <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" class="cr-actions-form">
                <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">
                <textarea name="admin_notes" placeholder="Add admin notes (optional)..." rows="1"></textarea>
                <button type="submit" name="new_status" value="reviewed" class="cr-action-btn cr-btn-review"><i class="fas fa-check"></i> Mark Reviewed</button>
                <button type="submit" name="new_status" value="dismissed" class="cr-action-btn cr-btn-dismiss"><i class="fas fa-times"></i> Dismiss</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
