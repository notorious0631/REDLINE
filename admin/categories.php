<?php include 'header.php';

$success = '';
$error = '';

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $badge = trim($_POST['badge_label'] ?? '');
    $badgeType = $_POST['badge_type'] ?? 'blue';
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if (empty($name) || empty($slug)) {
        $error = "Name and slug are required.";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO categories (name, slug, badge_label, badge_type, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $badge, $badgeType, $sortOrder]);
            $success = "Category '$name' added.";
        } catch (PDOException $e) {
            $error = "Failed to add category.";
        }
    }
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $id = intval($_POST['cat_id']);
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $badge = trim($_POST['badge_label'] ?? '');
    $badgeType = $_POST['badge_type'] ?? 'blue';
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    try {
        $conn->prepare("UPDATE categories SET name=?, slug=?, badge_label=?, badge_type=?, sort_order=? WHERE id=?")->execute([$name, $slug, $badge, $badgeType, $sortOrder, $id]);
        $success = "Category updated.";
    } catch (PDOException $e) {
        $error = "Update failed.";
    }
}

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $cid = intval($_GET['id']);
    try {
        if ($_GET['action'] === 'delete') {
            $conn->prepare("DELETE FROM categories WHERE id = ?")->execute([$cid]);
            $success = "Category deleted.";
        } elseif ($_GET['action'] === 'activate') {
            $conn->prepare("UPDATE categories SET status = 'active' WHERE id = ?")->execute([$cid]);
            $success = "Category activated.";
        } elseif ($_GET['action'] === 'deactivate') {
            $conn->prepare("UPDATE categories SET status = 'inactive' WHERE id = ?")->execute([$cid]);
            $success = "Category deactivated.";
        }
    } catch (PDOException $e) { $error = "Action failed."; }
}

// Fetch categories
$categories = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM listings WHERE category_id = c.id) AS listing_count FROM categories c ORDER BY c.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

$editCat = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    foreach ($categories as $c) { if ($c['id'] == $eid) { $editCat = $c; break; } }
}
?>

<div class="admin-page-header">
    <div><h1>Categories</h1><p class="page-subtitle"><?php echo count($categories); ?> categories</p></div>
</div>

<?php if ($success): ?><div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px;">
    <!-- Categories Table -->
    <div class="admin-card">
        <div class="admin-card-header">All Categories</div>
        <div style="overflow-x: auto;">
            <table class="admin-table">
                <thead><tr><th>Order</th><th>Name</th><th>Slug</th><th>Badge</th><th>Listings</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                <tr>
                    <td style="color: var(--admin-muted);"><?php echo $c['sort_order']; ?></td>
                    <td style="font-weight: 600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td style="font-size: 0.78rem; color: var(--admin-muted);"><?php echo htmlspecialchars($c['slug']); ?></td>
                    <td>
                        <?php if ($c['badge_label']): ?>
                            <span class="badge-sm" style="background: rgba(66,165,245,0.15); color: #64b5f6;"><?php echo htmlspecialchars($c['badge_label']); ?></span>
                        <?php else: ?>
                            <span style="color: var(--admin-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $c['listing_count']; ?></td>
                    <td><span class="badge-sm <?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td>
                        <a href="?edit=<?php echo $c['id']; ?>" class="action-link blue" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php if ($c['status'] === 'active'): ?>
                            <a href="?action=deactivate&id=<?php echo $c['id']; ?>" class="action-link" title="Deactivate"><i class="fas fa-eye-slash"></i></a>
                        <?php else: ?>
                            <a href="?action=activate&id=<?php echo $c['id']; ?>" class="action-link green" title="Activate"><i class="fas fa-eye"></i></a>
                        <?php endif; ?>
                        <a href="?action=delete&id=<?php echo $c['id']; ?>" class="action-link" title="Delete" onclick="return confirm('Delete this category?');"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Category Form -->
    <div class="admin-card" style="height: fit-content;">
        <div class="admin-card-header"><?php echo $editCat ? 'Edit Category' : 'Add Category'; ?></div>
        <div class="admin-card-body">
            <form method="POST">
                <?php if ($editCat): ?>
                    <input type="hidden" name="edit_category" value="1">
                    <input type="hidden" name="cat_id" value="<?php echo $editCat['id']; ?>">
                <?php else: ?>
                    <input type="hidden" name="add_category" value="1">
                <?php endif; ?>
                <div class="admin-form-group">
                    <label class="admin-form-label">Name</label>
                    <input type="text" name="name" class="admin-input" required value="<?php echo htmlspecialchars($editCat['name'] ?? ''); ?>">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Slug</label>
                    <input type="text" name="slug" class="admin-input" required value="<?php echo htmlspecialchars($editCat['slug'] ?? ''); ?>" placeholder="e.g. hw-mainline">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Badge Label</label>
                    <input type="text" name="badge_label" class="admin-input" value="<?php echo htmlspecialchars($editCat['badge_label'] ?? ''); ?>" placeholder="e.g. POPULAR">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Badge Type</label>
                    <select name="badge_type" class="admin-select">
                        <?php foreach (['blue','gold','red','green','orange','purple'] as $bt): ?>
                            <option value="<?php echo $bt; ?>" <?php echo ($editCat['badge_type'] ?? '') === $bt ? 'selected' : ''; ?>><?php echo ucfirst($bt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="admin-input" value="<?php echo $editCat['sort_order'] ?? 0; ?>" min="0">
                </div>
                <button type="submit" class="btn-admin red" style="width: 100%;">
                    <i class="fas fa-<?php echo $editCat ? 'save' : 'plus'; ?>"></i>
                    <?php echo $editCat ? 'Save Changes' : 'Add Category'; ?>
                </button>
                <?php if ($editCat): ?>
                    <a href="categories.php" class="btn-admin outline" style="width: 100%; margin-top: 8px; justify-content: center;">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
