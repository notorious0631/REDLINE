<?php include 'header.php';

$success = '';
$error = '';

// Helper to build clean links without duplicating action/id
function getCleanQuery($exclude = ['action', 'id']) {
    $params = $_GET;
    foreach ($exclude as $key) unset($params[$key]);
    return http_build_query($params);
}
$cleanQuery = getCleanQuery();

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $lid = intval($_GET['id']);
    $action = $_GET['action'];
    try {
        if ($action === 'delete') {
            // Check if listing has ever been ordered
            $stmt = $conn->prepare("SELECT COUNT(*) FROM order_items WHERE listing_id = ?");
            $stmt->execute([$lid]);
            $hasOrders = ($stmt->fetchColumn() > 0);

            if ($hasOrders) {
                // SOFT DELETE: It has history, just hide it
                $conn->prepare("UPDATE listings SET status = 'inactive' WHERE id = ?")->execute([$lid]);
                $success = "Listing #$lid has orders, so it was marked as 'Inactive' to preserve history.";
            } else {
                // HARD DELETE: No history, safe to remove
                $conn->beginTransaction();
                try {
                    // 1. Delete notifications
                    $conn->prepare("DELETE FROM notifications WHERE link LIKE ?")->execute(["%listing.php?id=$lid%"]);
                    
                    // 2. Clear from wishlists (if exists)
                    try { $conn->prepare("DELETE FROM wishlists WHERE listing_id = ?")->execute([$lid]); } catch (PDOException $e) {}
                    
                    // 3. Clear negotiations (if exists)
                    try { $conn->prepare("DELETE FROM negotiations WHERE listing_id = ?")->execute([$lid]); } catch (PDOException $e) {}
                    
                    // 4. Handle Chat Rooms and Messages
                    try {
                        $chatRooms = $conn->prepare("SELECT id FROM chat_rooms WHERE listing_id = ?");
                        $chatRooms->execute([$lid]);
                        $roomIds = $chatRooms->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($roomIds)) {
                            $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
                            $conn->prepare("DELETE FROM chat_messages WHERE chat_room_id IN ($placeholders)")->execute($roomIds);
                            $conn->prepare("DELETE FROM chat_rooms WHERE id IN ($placeholders)")->execute($roomIds);
                        }
                    } catch (PDOException $e) {}

                    // 5. Delete listing images
                    $stmt = $conn->prepare("SELECT image_path FROM listing_images WHERE listing_id = ?");
                    $stmt->execute([$lid]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $imgPath) {
                        $fullPath = '../' . $imgPath;
                        if (file_exists($fullPath)) @unlink($fullPath);
                    }
                    $conn->prepare("DELETE FROM listing_images WHERE listing_id = ?")->execute([$lid]);

                    // 6. Final: Delete the listing
                    $stmt = $conn->prepare("DELETE FROM listings WHERE id = ?");
                    $stmt->execute([$lid]);
                    
                    $conn->commit();
                    $success = "Listing #$lid deleted successfully.";
                } catch (PDOException $e2) {
                    $conn->rollBack();
                    $error = "Database Error: " . $e2->getMessage();
                }
            }
        } elseif (in_array($action, ['active', 'sold', 'draft'])) {
            $conn->prepare("UPDATE listings SET status = ? WHERE id = ?")->execute([$action, $lid]);
            $success = "Listing status updated to " . ucfirst($action) . ".";
        } elseif ($action === 'feature') {
            $conn->prepare("UPDATE listings SET is_featured = 1 WHERE id = ?")->execute([$lid]);
            $success = "Listing featured.";
        } elseif ($action === 'unfeature') {
            $conn->prepare("UPDATE listings SET is_featured = 0 WHERE id = ?")->execute([$lid]);
            $success = "Listing unfeatured.";
        }
    } catch (PDOException $e) {
        logError('admin_listings', 'Action failed', $e);
        $error = "Action failed: " . $e->getMessage();
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$catFilter = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];
if ($statusFilter) { $where[] = "l.status = ?"; $params[] = $statusFilter; }
if ($catFilter) { $where[] = "l.category_id = ?"; $params[] = intval($catFilter); }
if ($search) { $where[] = "l.title LIKE ?"; $params[] = "%$search%"; }

$whereClause = implode(' AND ', $where);
$stmt = $conn->prepare("SELECT l.*, u.name AS seller_name, c.name AS category_name FROM listings l LEFT JOIN users u ON l.seller_id = u.id LEFT JOIN categories c ON l.category_id = c.id WHERE $whereClause ORDER BY l.created_at DESC");
$stmt->execute($params);
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categories for filter
$categories = $conn->query("SELECT id, name FROM categories ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-page-header">
    <div><h1>Listings</h1><p class="page-subtitle"><?php echo count($listings); ?> total listings</p></div>
</div>

<?php if ($success): ?><div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

<div class="admin-filters">
    <form style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="text" name="search" class="admin-input" placeholder="Search title..." value="<?php echo htmlspecialchars($search); ?>" style="width:200px;">
        <select name="status" class="admin-select" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
            <option value="sold" <?php echo $statusFilter==='sold'?'selected':''; ?>>Sold</option>
            <option value="draft" <?php echo $statusFilter==='draft'?'selected':''; ?>>Draft</option>
        </select>
        <select name="category" class="admin-select" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $catFilter==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-admin outline sm"><i class="fas fa-search"></i></button>
    </form>
</div>

<div class="admin-card">
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead><tr><th></th><th>Title</th><th>Seller</th><th>Category</th><th>Price</th><th>Status</th><th>Views</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($listings as $l): ?>
            <tr>
                <td>
                    <?php if (!empty($l['image'])): ?>
                        <img src="../<?php echo htmlspecialchars($l['image']); ?>" class="td-img">
                    <?php else: ?>
                        <div class="td-img" style="display:flex;align-items:center;justify-content:center;color:#333;background:var(--admin-bg);border-radius:6px;"><i class="fas fa-car"></i></div>
                    <?php endif; ?>
                </td>
                <td style="font-weight: 600; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($l['title']); ?></td>
                <td style="font-size: 0.8rem;"><?php echo htmlspecialchars($l['seller_name'] ?? 'N/A'); ?></td>
                <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo htmlspecialchars($l['category_name'] ?? '—'); ?></td>
                <td style="font-weight: 700;">Rs.<?php echo number_format($l['price'], 0); ?></td>
                <td><span class="badge-sm <?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                <td style="color: var(--admin-muted);"><?php echo $l['views']; ?></td>
                <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo date('M d', strtotime($l['created_at'])); ?></td>
                <td>
                    <?php if ($l['status'] !== 'active'): ?>
                        <a href="?<?php echo $cleanQuery; ?>&action=active&id=<?php echo $l['id']; ?>" class="action-link green" title="Activate"><i class="fas fa-check"></i></a>
                    <?php endif; ?>
                    <?php if ($l['status'] !== 'sold'): ?>
                        <a href="?<?php echo $cleanQuery; ?>&action=sold&id=<?php echo $l['id']; ?>" class="action-link blue" title="Mark Sold"><i class="fas fa-tag"></i></a>
                    <?php endif; ?>
                    <?php if ($l['status'] !== 'draft'): ?>
                        <a href="?<?php echo $cleanQuery; ?>&action=draft&id=<?php echo $l['id']; ?>" class="action-link" title="Draft"><i class="fas fa-eye-slash"></i></a>
                    <?php endif; ?>
                    <a href="?<?php echo $cleanQuery; ?>&action=delete&id=<?php echo $l['id']; ?>" class="action-link" title="Delete" onclick="return confirm('Delete this listing?');"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($listings)): ?><tr><td colspan="9" style="text-align:center;color:var(--admin-muted);padding:40px;">No listings found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
