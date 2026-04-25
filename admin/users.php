<?php include 'header.php';

$success = '';
$error = '';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $uid = intval($_GET['id']);
    $action = $_GET['action'];

    try {
        if ($action === 'delete' && $uid != $_SESSION['user_id']) {
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $success = "User deleted.";
        } elseif ($action === 'make_admin') {
            $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$uid]);
            $success = "User promoted to admin.";
        } elseif ($action === 'make_seller') {
            $conn->prepare("UPDATE users SET role = 'seller' WHERE id = ?")->execute([$uid]);
            $success = "User set to seller.";
        } elseif ($action === 'make_buyer') {
            $conn->prepare("UPDATE users SET role = 'buyer' WHERE id = ?")->execute([$uid]);
            $success = "User set to buyer.";
        } elseif ($action === 'verify') {
            $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$uid]);
            $success = "User verified.";
        } elseif ($action === 'unverify') {
            $conn->prepare("UPDATE users SET is_verified = 0 WHERE id = ?")->execute([$uid]);
            $success = "User unverified.";
        }
    } catch (PDOException $e) {
        $error = "Action failed.";
    }
}

// Filters
$roleFilter = $_GET['role'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];
if ($roleFilter) { $where[] = "role = ?"; $params[] = $roleFilter; }
if ($search) { $where[] = "(name LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereClause = implode(' AND ', $where);
$stmt = $conn->prepare("SELECT * FROM users WHERE $whereClause ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-page-header">
    <div><h1>Users</h1><p class="page-subtitle"><?php echo count($users); ?> total users</p></div>
</div>

<?php if ($success): ?><div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

<div class="admin-filters">
    <form style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="text" name="search" class="admin-input" placeholder="Search name or email..." value="<?php echo htmlspecialchars($search); ?>" style="width:220px;">
        <select name="role" class="admin-select" onchange="this.form.submit()">
            <option value="">All Roles</option>
            <option value="buyer" <?php echo $roleFilter==='buyer'?'selected':''; ?>>Buyers</option>
            <option value="seller" <?php echo $roleFilter==='seller'?'selected':''; ?>>Sellers</option>
            <option value="admin" <?php echo $roleFilter==='admin'?'selected':''; ?>>Admins</option>
        </select>
        <button type="submit" class="btn-admin outline sm"><i class="fas fa-search"></i> Search</button>
    </form>
</div>

<div class="admin-card">
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Verified</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td style="font-weight: 600;"><?php echo htmlspecialchars($u['name']); ?></td>
                <td style="font-size: 0.8rem;"><?php echo htmlspecialchars($u['email']); ?></td>
                <td style="font-size: 0.8rem; color: var(--admin-muted);"><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                <td><span class="badge-sm <?php echo $u['role']; ?>"><?php echo ucfirst($u['role']); ?></span></td>
                <td>
                    <?php if ($u['is_verified']): ?>
                        <span class="badge-sm verified">Yes</span>
                    <?php else: ?>
                        <span class="badge-sm unverified">No</span>
                    <?php endif; ?>
                </td>
                <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td>
                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <?php if ($u['role'] !== 'admin'): ?>
                            <a href="?action=make_admin&id=<?php echo $u['id']; ?>" class="action-link blue" title="Make Admin"><i class="fas fa-crown"></i></a>
                        <?php endif; ?>
                        <?php if ($u['role'] !== 'seller'): ?>
                            <a href="?action=make_seller&id=<?php echo $u['id']; ?>" class="action-link green" title="Make Seller"><i class="fas fa-store"></i></a>
                        <?php elseif ($u['role'] === 'seller'): ?>
                            <a href="?action=make_buyer&id=<?php echo $u['id']; ?>" class="action-link blue" title="Make Buyer (Revoke Seller)"><i class="fas fa-user-minus"></i></a>
                        <?php endif; ?>
                        <?php if (!$u['is_verified']): ?>
                            <a href="?action=verify&id=<?php echo $u['id']; ?>" class="action-link green" title="Verify"><i class="fas fa-check"></i></a>
                        <?php else: ?>
                            <a href="?action=unverify&id=<?php echo $u['id']; ?>" class="action-link" title="Unverify"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                        <a href="?action=delete&id=<?php echo $u['id']; ?>" class="action-link" title="Delete" onclick="return confirm('Delete this user?');"><i class="fas fa-trash"></i></a>
                    <?php else: ?>
                        <span style="font-size: 0.75rem; color: var(--admin-muted);">You</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
