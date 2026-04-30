<?php
require_once '../config/db.php';
include 'header.php';

// Ensure upload directory exists
$uploadDir = '../assets/images/carousel/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
// Fix permissions if needed (XAMPP compatibility)
if (!is_writable($uploadDir)) {
    @chmod($uploadDir, 0777);
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
        $error = "Failed to delete slide. " . $e->getMessage();
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
                    $error = "Database error: " . $e->getMessage();
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
            $error = "Database error: " . $e->getMessage();
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
?>

<div class="admin-page-header">
    <div>
        <h1>Homepage Carousel</h1>
        <p class="page-subtitle">Manage hero section slides for the homepage</p>
    </div>
    <?php if (!$editSlide): ?>
    <button onclick="document.getElementById('slideFormContainer').style.display='block'; window.scrollTo(0,0);" class="btn-admin primary">
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
<div id="slideFormContainer" class="admin-card" style="margin-bottom: 24px; <?php echo $editSlide ? 'display:block;' : 'display:none;'; ?>">
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

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
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
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="admin-form-group">
                            <label class="admin-form-label">Button Text</label>
                            <input type="text" name="button_text" class="admin-input" placeholder="e.g. BROWSE COLLECTION" value="<?php echo htmlspecialchars($editSlide['button_text'] ?? ''); ?>">
                        </div>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Button Link</label>
                            <input type="text" name="button_link" class="admin-input" placeholder="e.g. browse.php" value="<?php echo htmlspecialchars($editSlide['button_link'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
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
                        <label class="admin-form-label">Media (Image or MP4 Video) <?php echo $editSlide ? '<small style="color:var(--text-muted);">(Leave blank to keep current)</small>' : ''; ?></label>
                        <input type="file" name="media" class="admin-input" accept="image/*,video/mp4,video/webm" <?php echo $editSlide ? '' : 'required'; ?>>
                        <?php if ($editSlide && !empty($editSlide['media_path'])): ?>
                            <div style="margin-top: 10px; border-radius: 8px; overflow: hidden; max-height: 120px; background: #000; display: inline-block;">
                                <?php if ($editSlide['media_type'] === 'video'): ?>
                                    <video src="../<?php echo htmlspecialchars($editSlide['media_path']); ?>" style="height: 120px;" muted playsinline></video>
                                <?php else: ?>
                                    <img src="../<?php echo htmlspecialchars($editSlide['media_path']); ?>" style="height: 120px; object-fit: cover;">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="margin-top: 16px; text-align: right;">
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
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($slides)): ?>
            <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                <i class="fas fa-images" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.5;"></i>
                <h3>No slides found</h3>
                <p>The homepage will use the static default hero section.</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">Media</th>
                        <th>Headline / Badge</th>
                        <th style="width: 120px; text-align:center;">Order</th>
                        <th style="width: 100px; text-align:center;">Status</th>
                        <th style="width: 150px; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slides as $slide): ?>
                    <tr>
                        <td>
                            <div style="width: 80px; height: 50px; border-radius: 4px; overflow: hidden; background: #000; position: relative;">
                                <?php if ($slide['media_type'] === 'video'): ?>
                                    <video src="../<?php echo htmlspecialchars($slide['media_path']); ?>" style="width: 100%; height: 100%; object-fit: cover;" muted></video>
                                    <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4);"><i class="fas fa-play" style="color:#fff; font-size:0.8rem;"></i></div>
                                <?php else: ?>
                                    <img src="../<?php echo htmlspecialchars($slide['media_path']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; font-family:var(--font-brand); color:var(--text-primary); font-size:0.95rem; line-height:1.2;">
                                <?php echo !empty($slide['headline']) ? strip_tags($slide['headline']) : '(No Headline)'; ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                                <?php if ($slide['badge_text']): ?>
                                    <span style="background:rgba(255,255,255,0.1); padding:2px 6px; border-radius:4px; font-weight:700; font-size:0.65rem; color:var(--accent-red); margin-right:6px;"><?php echo htmlspecialchars($slide['badge_text']); ?></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($slide['media_type']); ?>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <span style="background: var(--bg-surface); border: 1px solid var(--border-color); padding: 4px 12px; border-radius: 12px; font-weight: 600; font-size: 0.8rem;">
                                <?php echo $slide['sort_order']; ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($slide['status'] === 'active'): ?>
                                <span style="background: rgba(76,175,80,0.1); color: #81c784; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700;"><i class="fas fa-circle" style="font-size:0.5rem; margin-right:4px;"></i> Active</span>
                            <?php else: ?>
                                <span style="background: rgba(255,255,255,0.05); color: var(--text-muted); padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <a href="carousel.php?edit=<?php echo $slide['id']; ?>" class="btn-admin secondary" style="padding: 6px 10px; margin-right: 6px;" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="carousel.php?delete=<?php echo $slide['id']; ?>" onclick="return confirm('Delete this slide? This action cannot be undone.');" class="btn-admin secondary" style="padding: 6px 10px; color: #e53935; border-color: rgba(229,57,53,0.3);" title="Delete">
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
