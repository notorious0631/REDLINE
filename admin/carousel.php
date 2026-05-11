<?php
require_once '../config/db.php';

// Ensure upload directory exists
$uploadDir = '../assets/images/carousel/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
// Fix permissions if needed (XAMPP compatibility)
if (!is_writable($uploadDir)) {
    @chmod($uploadDir, 0755);
}

$error = '';
$success = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        $stmt = $conn->prepare("SELECT media_path FROM carousel_slides WHERE id = ?");
        $stmt->execute([$id]);
        $slide = $stmt->fetch();
        if ($slide && !empty($slide['media_path']) && file_exists('../' . $slide['media_path'])) {
            unlink('../' . $slide['media_path']);
        }
        $conn->prepare("DELETE FROM carousel_slides WHERE id = ?")->execute([$id]);
        $success = "Slide deleted successfully.";
        $_SESSION['admin_success'] = $success;
        header("Location: carousel.php");
        exit;
    } catch (PDOException $e) {
        logError('admin_carousel', 'Failed to delete slide', $e);
        $error = "Failed to delete slide. Please try again.";
    }
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    $badge_text = trim($_POST['badge_text'] ?? '');
    $headline = trim($_POST['headline'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $button_text = trim($_POST['button_text'] ?? '');
    $button_link = trim($_POST['button_link'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    
    if ($action === 'add') {
        // Handle file upload
        if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please upload an image or video for the slide.";
        } else {
            $fileTmp = $_FILES['media']['tmp_name'];
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', basename($_FILES['media']['name']));
            $filePath = $uploadDir . $fileName;
            $dbPath = 'assets/images/carousel/' . $fileName;
            
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $videoExts = ['mp4', 'webm', 'ogg'];
            $mediaType = in_array($fileExt, $videoExts) ? 'video' : 'image';
            
            if (move_uploaded_file($fileTmp, $filePath) || copy($fileTmp, $filePath)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO carousel_slides (media_path, media_type, badge_text, headline, subtitle, button_text, button_link, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$dbPath, $mediaType, $badge_text, $headline, $subtitle, $button_text, $button_link, $sort_order, $status]);
                    $_SESSION['admin_success'] = "Slide added successfully.";
                    header("Location: carousel.php");
                    exit;
                } catch (PDOException $e) {
                    logError('admin_carousel', 'Database error', $e);
                    $error = "Database error. Please try again.";
                }
            } else {
                $error = "Failed to upload file. Check directory permissions on: $uploadDir";
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id']);
        
        $dbPathQuery = "";
        $paramsArray = [$badge_text, $headline, $subtitle, $button_text, $button_link, $sort_order, $status];
        
        if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
             // Delete old
             $stmt = $conn->prepare("SELECT media_path FROM carousel_slides WHERE id = ?");
             $stmt->execute([$id]);
             $old = $stmt->fetch();
             if ($old && !empty($old['media_path']) && file_exists('../' . $old['media_path'])) {
                 unlink('../' . $old['media_path']);
             }
             
             // Upload new
            $fileTmp = $_FILES['media']['tmp_name'];
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', basename($_FILES['media']['name']));
            $filePath = $uploadDir . $fileName;
            $dbPath = 'assets/images/carousel/' . $fileName;
            
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $videoExts = ['mp4', 'webm', 'ogg'];
            $mediaType = in_array($fileExt, $videoExts) ? 'video' : 'image';
            
            if (move_uploaded_file($fileTmp, $filePath) || copy($fileTmp, $filePath)) {
                $dbPathQuery = ", media_path = ?, media_type = ?";
                $paramsArray[] = $dbPath;
                $paramsArray[] = $mediaType;
            }
        }
        
        $paramsArray[] = $id;
        
        try {
            $stmt = $conn->prepare("UPDATE carousel_slides SET badge_text=?, headline=?, subtitle=?, button_text=?, button_link=?, sort_order=?, status=? $dbPathQuery WHERE id=?");
            $stmt->execute($paramsArray);
            $_SESSION['admin_success'] = "Slide updated successfully.";
            header("Location: carousel.php");
            exit;
        } catch (PDOException $e) {
            logError('admin_carousel', 'Database error', $e);
            $error = "Database error. Please try again.";
        }
    }
}

if (isset($_SESSION['admin_success'])) {
    $success = $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}

// Fetch all slides
$slides = [];
try {
    $stmt = $conn->query("SELECT * FROM carousel_slides ORDER BY sort_order ASC, created_at DESC");
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$editSlide = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    try {
        $stmt = $conn->prepare("SELECT * FROM carousel_slides WHERE id = ?");
        $stmt->execute([$editId]);
        $editSlide = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
include 'header.php';
?>

<div class="admin-page-header">
    <div>
        <h1>Homepage Carousel</h1>
        <p class="page-subtitle">Manage hero section slides for the homepage</p>
    </div>
    <?php if (!$editSlide): ?>
    <button id="addSlideBtn" class="btn-admin primary">
        <i class="fas fa-plus"></i> Add New Slide
    </button>
    <?php else: ?>
    <a href="carousel.php" class="btn-admin secondary">
        <i class="fas fa-times"></i> Cancel Edit
    </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="admin-alert error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="admin-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Slide Form -->
<div id="slideFormContainer" class="admin-card" style="<?php echo $editSlide ? 'display:block;' : 'display:none;'; ?>">
    <div class="admin-card-header">
        <i class="fas <?php echo $editSlide ? 'fa-edit' : 'fa-plus'; ?>"></i> 
        <?php echo $editSlide ? 'Edit Slide' : 'Add New Slide'; ?>
    </div>
    <div class="admin-card-body">
        <form method="POST" action="carousel.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editSlide ? 'edit' : 'add'; ?>">
            <?php if ($editSlide): ?>
                <input type="hidden" name="id" value="<?php echo $editSlide['id']; ?>">
            <?php endif; ?>

            <div class="carousel-grid-2">
                <!-- Left Column: Content -->
                <div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Badge Text (Optional)</label>
                        <input type="text" name="badge_text" class="admin-input" placeholder="e.g. INDIA'S DIECAST MARKETPLACE" value="<?php echo htmlspecialchars($editSlide['badge_text'] ?? ''); ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Headline (Supports HTML like &lt;span class="accent"&gt;)</label>
                        <textarea name="headline" class="admin-textarea" rows="4" placeholder="e.g. <span class='line'>COLLECT.</span> <span class='line accent'>TRADE.</span> <span class='line'>RACE.</span>"><?php echo htmlspecialchars($editSlide['headline'] ?? ''); ?></textarea>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Subtitle Text</label>
                        <textarea name="subtitle" class="admin-textarea" rows="3"><?php echo htmlspecialchars($editSlide['subtitle'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Right Column: Settings & Media -->
                <div>
<!-- Button fields removed as they are now permanently set on the homepage -->
                    
                    <div class="carousel-grid-2-sm">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="admin-input" value="<?php echo intval($editSlide['sort_order'] ?? 0); ?>">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Status</label>
                            <select name="status" class="admin-input">
                                <option value="active" <?php echo ($editSlide['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($editSlide['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="admin-form-group">
                        <label class="admin-form-label">Media (Image or MP4 Video) <?php echo $editSlide ? '<small class="carousel-meta">(Leave blank to keep current)</small>' : ''; ?></label>
                        <input type="file" name="media" class="admin-input" accept="image/*,video/mp4,video/webm" <?php echo $editSlide ? '' : 'required'; ?>>
                        <?php if ($editSlide && !empty($editSlide['media_path'])): ?>
                            <div class="carousel-media-preview">
                                <?php if ($editSlide['media_type'] === 'video'): ?>
                                    <video src="../<?php echo htmlspecialchars($editSlide['media_path']); ?>" muted playsinline></video>
                                <?php else: ?>
                                    <img src="../<?php echo htmlspecialchars($editSlide['media_path']); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="carousel-form-footer">
                <button type="submit" class="btn-admin primary">
                    <i class="fas fa-save"></i> <?php echo $editSlide ? 'Update Slide' : 'Save Slide'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Slides List -->
<div class="admin-card">
    <div class="admin-card-header">
        <i class="fas fa-layer-group"></i> Existing Slides
    </div>
    <div class="admin-card-body">
        <?php if (empty($slides)): ?>
            <div class="carousel-empty-message">
                <i class="fas fa-images"></i>
                <h3>No slides found</h3>
                <p>The homepage will use the static default hero section.</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Media</th>
                        <th>Headline / Badge</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slides as $slide): ?>
                    <tr>
                        <td>
                            <div class="carousel-table-img">
                                <?php if ($slide['media_type'] === 'video'): ?>
                                    <video src="../<?php echo htmlspecialchars($slide['media_path']); ?>" muted></video>
                                    <div class="carousel-table-img-play"><i class="fas fa-play"></i></div>
                                <?php else: ?>
                                    <img src="../<?php echo htmlspecialchars($slide['media_path']); ?>">
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="carousel-table-headline">
                                <?php echo !empty($slide['headline']) ? strip_tags($slide['headline']) : '(No Headline)'; ?>
                            </div>
                            <div class="carousel-table-meta">
                                <?php if ($slide['badge_text']): ?>
                                    <span class="carousel-badge-text"><?php echo htmlspecialchars($slide['badge_text']); ?></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($slide['media_type']); ?>
                            </div>
                        </td>
                        <td>
                            <span class="carousel-sort-order">
                                <?php echo $slide['sort_order']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($slide['status'] === 'active'): ?>
                                <span class="carousel-status-active"><i class="fas fa-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="carousel-status-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="carousel.php?edit=<?php echo $slide['id']; ?>" class="btn-admin secondary carousel-action-btn" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="carousel.php?delete=<?php echo $slide['id']; ?>" class="btn-admin secondary carousel-action-btn delete btn-delete-slide" title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>

<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    const addSlideBtn = document.getElementById('addSlideBtn');
    if (addSlideBtn) {
        addSlideBtn.addEventListener('click', function() {
            document.getElementById('slideFormContainer').style.display = 'block';
            window.scrollTo(0, 0);
        });
    }

    const deleteBtns = document.querySelectorAll('.btn-delete-slide');
    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Delete this slide? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
});
</script>
