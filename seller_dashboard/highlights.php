<?php
$pageTitle = 'Manage Highlights';
include 'header.php';

$sellerId = $_SESSION['user_id'];

// Fetch existing highlights
$highlights = [];
try {
    $stmt = $conn->prepare("
        SELECT sh.*, 
               (SELECT COUNT(*) FROM highlight_images WHERE highlight_id = sh.id) as image_count
        FROM seller_highlights sh
        WHERE sh.seller_id = ?
        ORDER BY sh.sort_order ASC, sh.created_at DESC
    ");
    $stmt->execute([$sellerId]);
    $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($highlights as &$h) {
        $imgStmt = $conn->prepare("SELECT id, image_path, sort_order FROM highlight_images WHERE highlight_id = ? ORDER BY sort_order ASC, id ASC");
        $imgStmt->execute([$h['id']]);
        $h['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($h);
} catch (PDOException $e) {}
?>

<style>
.hl-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
.hl-page-header h1 { font-size: 1.6rem; font-weight: 800; }
.hl-page-header p { color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; }

.btn-create-hl {
    background: linear-gradient(135deg, #e53935, #c62828);
    color: #fff; border: none; border-radius: 12px; padding: 12px 24px;
    font-weight: 700; font-size: 0.88rem; cursor: pointer; display: inline-flex;
    align-items: center; gap: 8px; font-family: inherit; transition: all 0.2s;
    box-shadow: 0 4px 16px rgba(229,57,53,0.2);
}
.btn-create-hl:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(229,57,53,0.3); }

/* Highlights Grid */
.hl-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}

.hl-card {
    background: var(--bg-card, rgba(255,255,255,0.03));
    border: 1px solid var(--border-color, #2a2a2a);
    border-radius: 16px; overflow: hidden; transition: all 0.2s;
}
.hl-card:hover { border-color: rgba(255,255,255,0.1); box-shadow: 0 8px 32px rgba(0,0,0,0.2); }

.hl-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 18px; border-bottom: 1px solid rgba(255,255,255,0.04);
}

.hl-card-title-area { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }

.hl-cover-preview {
    width: 50px; height: 50px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,255,255,0.05); border: 2px solid rgba(229,57,53,0.3);
    overflow: hidden; display: flex; align-items: center; justify-content: center;
}
.hl-cover-preview img { width: 100%; height: 100%; object-fit: cover; }
.hl-cover-preview i { color: var(--text-muted); font-size: 1rem; }

.hl-title-input {
    background: transparent; border: 1px solid transparent; border-radius: 8px;
    padding: 6px 10px; color: var(--text-primary, #fff); font-size: 0.95rem;
    font-weight: 700; font-family: inherit; width: 100%; outline: none;
    transition: border-color 0.2s;
}
.hl-title-input:hover { border-color: rgba(255,255,255,0.08); }
.hl-title-input:focus { border-color: rgba(229,57,53,0.4); background: rgba(255,255,255,0.03); }

.hl-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.hl-card-actions button {
    width: 34px; height: 34px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.02); color: var(--text-muted); font-size: 0.78rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all 0.2s; font-family: inherit;
}
.hl-card-actions button:hover { background: rgba(255,255,255,0.06); color: #fff; }
.hl-card-actions button.danger:hover { background: rgba(229,57,53,0.1); color: #e53935; border-color: rgba(229,57,53,0.3); }

/* Image grid inside card */
.hl-images-area { padding: 14px 18px; }
.hl-images-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px;
}

.hl-img-thumb {
    aspect-ratio: 1; border-radius: 10px; overflow: hidden; position: relative;
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);
    cursor: pointer; transition: all 0.15s;
}
.hl-img-thumb:hover { border-color: rgba(229,57,53,0.3); }
.hl-img-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hl-img-thumb .hl-img-overlay {
    position: absolute; inset: 0; background: rgba(0,0,0,0.6);
    display: none; align-items: center; justify-content: center; gap: 6px;
}
.hl-img-thumb:hover .hl-img-overlay { display: flex; }
.hl-img-overlay button {
    width: 28px; height: 28px; border-radius: 6px; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center; font-size: 0.7rem;
    transition: all 0.15s;
}
.hl-img-overlay .hl-set-cover { background: rgba(255,183,77,0.2); color: #ffb74d; }
.hl-img-overlay .hl-set-cover:hover { background: rgba(255,183,77,0.4); }
.hl-img-overlay .hl-del-img { background: rgba(229,57,53,0.2); color: #ef5350; }
.hl-img-overlay .hl-del-img:hover { background: rgba(229,57,53,0.4); }

.hl-img-thumb.is-cover { border-color: rgba(255,183,77,0.4); }
.hl-img-thumb.is-cover::after {
    content: '★'; position: absolute; top: 4px; right: 4px; font-size: 0.6rem;
    background: rgba(255,183,77,0.9); color: #000; width: 16px; height: 16px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

/* Upload zone */
.hl-upload-zone {
    aspect-ratio: 1; border-radius: 10px; border: 2px dashed rgba(255,255,255,0.08);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.2s; position: relative; overflow: hidden;
    color: var(--text-muted); font-size: 0.7rem; gap: 4px;
}
.hl-upload-zone:hover { border-color: rgba(229,57,53,0.3); background: rgba(229,57,53,0.03); color: #e53935; }
.hl-upload-zone i { font-size: 1rem; }
.hl-upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

.hl-count { font-size: 0.72rem; color: var(--text-muted); padding: 0 18px 14px; }

/* Empty state */
.hl-empty {
    text-align: center; padding: 80px 20px; color: var(--text-muted);
    background: var(--bg-card, rgba(255,255,255,0.02)); border-radius: 20px;
    border: 1px dashed rgba(255,255,255,0.06);
}
.hl-empty i { font-size: 3rem; opacity: 0.1; margin-bottom: 16px; display: block; }
.hl-empty h3 { font-size: 1.1rem; color: var(--text-secondary); margin-bottom: 6px; }
.hl-empty p { font-size: 0.85rem; }

@media (max-width: 600px) {
    .hl-grid { grid-template-columns: 1fr; }
    .hl-images-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>

<div class="hl-page-header">
    <div>
        <h1><i class="fas fa-circle-notch" style="color:var(--accent-red);"></i> Story Highlights</h1>
        <p>Showcase your best transactions, reviews, and collection to buyers</p>
    </div>
    <button class="btn-create-hl" onclick="createHighlight()"><i class="fas fa-plus"></i> New Highlight</button>
</div>

<?php if (empty($highlights)): ?>
    <div class="hl-empty">
        <i class="far fa-images"></i>
        <h3>No highlights yet</h3>
        <p>Create your first highlight to showcase past transactions, happy customers, or your collection!</p>
    </div>
<?php else: ?>
    <div class="hl-grid">
        <?php foreach ($highlights as $hl): ?>
        <div class="hl-card" id="hlCard<?php echo $hl['id']; ?>">
            <div class="hl-card-header">
                <div class="hl-card-title-area">
                    <div class="hl-cover-preview">
                        <?php if (!empty($hl['cover_image'])): ?>
                            <img src="../<?php echo htmlspecialchars($hl['cover_image']); ?>" alt="">
                        <?php else: ?>
                            <i class="far fa-image"></i>
                        <?php endif; ?>
                    </div>
                    <input type="text" class="hl-title-input" value="<?php echo htmlspecialchars($hl['title']); ?>"
                           onchange="updateTitle(<?php echo $hl['id']; ?>, this.value)"
                           placeholder="Highlight name...">
                </div>
                <div class="hl-card-actions">
                    <button class="danger" title="Delete highlight" onclick="deleteHighlight(<?php echo $hl['id']; ?>)">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
            <div class="hl-images-area">
                <div class="hl-images-grid" id="hlGrid<?php echo $hl['id']; ?>">
                    <?php foreach ($hl['images'] as $img): ?>
                    <div class="hl-img-thumb <?php echo ($img['image_path'] === $hl['cover_image']) ? 'is-cover' : ''; ?>" id="hlImg<?php echo $img['id']; ?>">
                        <img src="../<?php echo htmlspecialchars($img['image_path']); ?>" alt="">
                        <div class="hl-img-overlay">
                            <button class="hl-set-cover" title="Set as cover" onclick="setCover(<?php echo $hl['id']; ?>, '<?php echo htmlspecialchars($img['image_path'], ENT_QUOTES); ?>')">
                                <i class="fas fa-star"></i>
                            </button>
                            <button class="hl-del-img" title="Delete" onclick="deleteImage(<?php echo $img['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="hl-upload-zone" title="Add image">
                        <i class="fas fa-plus"></i>
                        <span>Add</span>
                        <input type="file" accept="image/*" onchange="uploadImage(<?php echo $hl['id']; ?>, this)">
                    </div>
                </div>
            </div>
            <div class="hl-count"><?php echo $hl['image_count']; ?> image<?php echo $hl['image_count'] !== 1 ? 's' : ''; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function createHighlight() {
    const title = prompt('Enter a name for this highlight:', 'Happy Customers');
    if (!title) return;

    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('title', title);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'Failed to create');
        })
        .catch(() => alert('Network error'));
}

function updateTitle(hlId, title) {
    const fd = new FormData();
    fd.append('action', 'update_title');
    fd.append('highlight_id', hlId);
    fd.append('title', title);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => {});
}

function uploadImage(hlId, input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Max 5MB per image'); input.value = ''; return; }

    const fd = new FormData();
    fd.append('action', 'upload_image');
    fd.append('highlight_id', hlId);
    fd.append('image', file);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'Upload failed');
        })
        .catch(() => alert('Network error'));

    input.value = '';
}

function setCover(hlId, imgPath) {
    const fd = new FormData();
    fd.append('action', 'set_cover');
    fd.append('highlight_id', hlId);
    fd.append('image_path', imgPath);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'Failed');
        })
        .catch(() => alert('Network error'));
}

function deleteImage(imgId) {
    if (!confirm('Delete this image?')) return;

    const fd = new FormData();
    fd.append('action', 'delete_image');
    fd.append('image_id', imgId);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const el = document.getElementById('hlImg' + imgId);
                if (el) el.remove();
            } else alert(data.error || 'Failed');
        })
        .catch(() => alert('Network error'));
}

function deleteHighlight(hlId) {
    if (!confirm('Delete this entire highlight and all its images?')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('highlight_id', hlId);

    fetch('../api/highlights.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const card = document.getElementById('hlCard' + hlId);
                if (card) card.remove();
            } else alert(data.error || 'Failed');
        })
        .catch(() => alert('Network error'));
}
</script>

<?php include 'footer.php'; ?>
