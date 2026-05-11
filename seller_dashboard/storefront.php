<?php
$pageTitle = 'Update Storefront';
include 'header.php';

$sellerId = $_SESSION['user_id'];
$sfError = '';
$sfSuccess = '';

// Self-migrating schema: add free_shipping_threshold column if missing
try {
    $conn->exec("ALTER TABLE users ADD COLUMN free_shipping_threshold DECIMAL(10,2) DEFAULT NULL AFTER bank_details");
} catch (PDOException $e) { /* Column already exists */ }

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_storefront'])) {
    $store_name = trim($_POST['store_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $store_location = trim($_POST['store_location'] ?? '');
    $social_instagram = trim($_POST['social_instagram'] ?? '');
    $social_facebook = trim($_POST['social_facebook'] ?? '');
    $social_twitter = trim($_POST['social_twitter'] ?? '');
    $free_shipping_threshold = $_POST['free_shipping_threshold'] ?? '';
    $free_shipping_threshold = ($free_shipping_threshold !== '' && is_numeric($free_shipping_threshold)) ? floatval($free_shipping_threshold) : null;


    try {
        $avatarPath = null;
        $bannerPath = null;

        // Handle avatar upload
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                if (!is_dir('../uploads/avatars')) mkdir('../uploads/avatars', 0755, true);
                $avatarPath = 'uploads/avatars/' . uniqid('av_') . '.' . $ext;
                move_uploaded_file($_FILES['avatar']['tmp_name'], '../' . $avatarPath);
            }
        }

        // Handle banner upload
        if (!empty($_FILES['banner']['tmp_name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                if (!is_dir('../uploads/banners')) mkdir('../uploads/banners', 0755, true);
                $bannerPath = 'uploads/banners/' . uniqid('bn_') . '.' . $ext;
                move_uploaded_file($_FILES['banner']['tmp_name'], '../' . $bannerPath);
            }
        }

        // Build dynamic UPDATE query
        $fields = [
            'store_name = ?', 'bio = ?', 'store_location = ?',
            'social_instagram = ?', 'social_facebook = ?', 'social_twitter = ?',
            'free_shipping_threshold = ?'
        ];
        $params = [$store_name, $bio, $store_location, $social_instagram, $social_facebook, $social_twitter, $free_shipping_threshold];

        if ($avatarPath) {
            $fields[] = 'avatar = ?';
            $params[] = $avatarPath;
        }
        if ($bannerPath) {
            $fields[] = 'banner = ?';
            $params[] = $bannerPath;
        }

        $params[] = $sellerId;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $sfSuccess = 'Storefront updated successfully!';

        // Refresh seller user data in header
        $stmt = $conn->prepare("SELECT name, avatar, upi_id, bank_details FROM users WHERE id = ?");
        $stmt->execute([$sellerId]);
        $sellerUser = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $sfError = 'Failed to update storefront. Please try again.';
    }
}

// Fetch current storefront data
try {
    $stmt = $conn->prepare("SELECT store_name, avatar, banner, bio, store_location, social_instagram, social_facebook, social_twitter, free_shipping_threshold FROM users WHERE id = ?");
    $stmt->execute([$sellerId]);
    $sf = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sf = [];
}

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
/* ── Storefront Editor Styles ── */
.sf-page-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
}
.sf-page-header h1 { font-size: 1.8rem; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 4px; }
.sf-page-header p { color: var(--text-secondary); font-size: 0.95rem; }
.sf-actions { display: flex; gap: 10px; flex-wrap: wrap; }

.sf-alert {
    padding: 14px 18px; border-radius: 10px;
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 20px; font-size: 0.88rem; font-weight: 500;
    animation: sfSlideIn 0.3s ease;
}
.sf-alert.success { background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #81c784; }
.sf-alert.error { background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e57373; }
@keyframes sfSlideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

.sf-card {
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: 16px; overflow: hidden; margin-bottom: 24px;
    transition: border-color 0.2s;
}
.sf-card:hover { border-color: var(--border-hover); }
.sf-card-header {
    padding: 18px 24px; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(255,255,255,0.015);
}
.sf-card-header h3 {
    font-size: 1rem; font-weight: 700; margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.sf-card-header h3 i { color: var(--accent-red); font-size: 0.9rem; }
.sf-card-header .sf-card-badge {
    font-size: 0.7rem; font-weight: 600; padding: 3px 10px; border-radius: 20px;
    background: rgba(229,57,53,0.1); color: var(--accent-red); text-transform: uppercase;
    letter-spacing: 0.05em;
}
.sf-card-body { padding: 24px; }

/* Form fields */
.sf-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.sf-field { display: flex; flex-direction: column; gap: 6px; }
.sf-field.full { grid-column: span 2; }
.sf-field label { font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); }
.sf-field input, .sf-field textarea, .sf-field select {
    background: rgba(255,255,255,0.04); border: 1px solid var(--border-color);
    border-radius: 10px; color: var(--text-primary); padding: 11px 14px;
    font-size: 0.9rem; font-family: var(--font-sans); transition: border-color 0.2s, box-shadow 0.2s; width: 100%;
}
.sf-field input:focus, .sf-field textarea:focus {
    outline: none; border-color: var(--accent-red); box-shadow: 0 0 0 3px rgba(229,57,53,0.1);
}
.sf-hint { font-size: 0.74rem; color: var(--text-muted); margin-top: 2px; }

/* Avatar section */
.sf-avatar-section { display: flex; align-items: center; gap: 24px; margin-bottom: 8px; }
.sf-avatar-preview {
    width: 88px; height: 88px; border-radius: 50%;
    background: var(--bg-hover); border: 3px solid var(--border-hover);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; font-weight: 800; color: #fff; flex-shrink: 0;
    background-size: cover; background-position: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    transition: transform 0.2s, box-shadow 0.2s;
}
.sf-avatar-preview:hover { transform: scale(1.05); box-shadow: 0 6px 28px rgba(229,57,53,0.2); }
.sf-upload-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
    border: 1px solid var(--border-color); color: var(--text-secondary);
    background: rgba(255,255,255,0.04); cursor: pointer; transition: 0.2s;
}
.sf-upload-btn:hover { border-color: rgba(255,255,255,0.2); color: var(--text-primary); background: rgba(255,255,255,0.08); }
.sf-upload-btn input { display: none; }

/* Banner section */
.sf-banner-preview {
    width: 100%; height: 120px; border-radius: 12px;
    background: var(--bg-hover); border: 2px dashed var(--border-color);
    display: flex; align-items: center; justify-content: center;
    gap: 12px; color: var(--text-muted); font-size: 0.9rem;
    background-size: cover; background-position: center;
    transition: border-color 0.2s;
    position: relative; overflow: hidden; cursor: pointer;
    margin-bottom: 8px;
}
.sf-banner-preview:hover { border-color: var(--accent-red); }
.sf-banner-preview.has-image { border-style: solid; }
.sf-banner-preview .sf-banner-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.5); display: flex; align-items: center;
    justify-content: center; opacity: 0; transition: opacity 0.2s;
    color: #fff; font-weight: 600; font-size: 0.85rem; gap: 8px;
}
.sf-banner-preview:hover .sf-banner-overlay { opacity: 1; }

/* Social inputs */
.sf-social-row {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,0.02); padding: 10px 14px;
    border-radius: 10px; border: 1px solid var(--border-color);
    transition: border-color 0.2s;
}
.sf-social-row:focus-within { border-color: var(--accent-red); box-shadow: 0 0 0 3px rgba(229,57,53,0.08); }
.sf-social-row i { width: 24px; text-align: center; font-size: 1.1rem; flex-shrink: 0; }
.sf-social-row i.fa-instagram { color: #E4405F; }
.sf-social-row i.fa-facebook { color: #1877F2; }
.sf-social-row i.fa-twitter { color: #1DA1F2; }
.sf-social-row input {
    flex: 1; background: transparent !important; border: none !important;
    padding: 4px 0 !important; box-shadow: none !important; outline: none;
    color: var(--text-primary); font-size: 0.88rem;
}

/* Toggle switch */
.sf-toggle-wrap {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 0; gap: 16px;
}
.sf-toggle-info h4 { font-size: 0.95rem; font-weight: 600; margin: 0 0 4px; }
.sf-toggle-info p { font-size: 0.82rem; color: var(--text-muted); margin: 0; }
.sf-toggle {
    position: relative; width: 48px; height: 26px; flex-shrink: 0;
}
.sf-toggle input { opacity: 0; width: 0; height: 0; }
.sf-toggle-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: rgba(255,255,255,0.1); border-radius: 26px;
    transition: 0.3s;
}
.sf-toggle-slider::before {
    content: ''; position: absolute; height: 20px; width: 20px;
    left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
    transition: 0.3s;
}
.sf-toggle input:checked + .sf-toggle-slider { background: var(--accent-red); }
.sf-toggle input:checked + .sf-toggle-slider::before { transform: translateX(22px); }

/* Save bar */
.sf-save-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap; margin-top: 8px;
}

@media (max-width: 768px) {
    .sf-form-grid { grid-template-columns: 1fr; }
    .sf-field.full { grid-column: span 1; }
    .sf-avatar-section { flex-direction: column; text-align: center; }
    .sf-page-header { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="sf-page-header">
    <div>
        <h1><i class="fas fa-paint-brush" style="color:var(--accent-red);margin-right:8px;font-size:1.4rem;"></i>Update Storefront</h1>
        <p>Customize your public store profile — branding, bio, location & socials</p>
    </div>
    <div class="sf-actions">
        <button type="button" onclick="shareStorefrontDashboard()" class="btn-secondary"><i class="fas fa-share-alt"></i> Share Storefront</button>
        <a href="../seller.php?id=<?php echo $sellerId; ?>" target="_blank" class="btn-secondary"><i class="fas fa-external-link-alt"></i> View Storefront</a>
        <a href="index.php" class="btn-secondary"><i class="fas fa-chart-pie"></i> Dashboard</a>
    </div>
</div>

<?php if($sfSuccess): ?>
<div class="sf-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sfSuccess); ?></div>
<?php endif; ?>
<?php if($sfError): ?>
<div class="sf-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($sfError); ?></div>
<?php endif; ?>

<form method="POST" action="storefront.php" enctype="multipart/form-data" id="storefrontForm">
    <input type="hidden" name="update_storefront" value="1">

    <!-- ═══ SECTION: IDENTITY ═══ -->
    <div class="sf-card">
        <div class="sf-card-header">
            <h3><i class="fas fa-store"></i> Store Identity</h3>
            <span class="sf-card-badge">Public</span>
        </div>
        <div class="sf-card-body">
            <!-- Avatar -->
            <div class="sf-avatar-section">
                <div class="sf-avatar-preview" id="sfAvatarPreview"
                     <?php if(!empty($sf['avatar'])) echo 'style="background-image:url(../'.htmlspecialchars($sf['avatar']).');color:transparent;"'; ?>>
                    <?php if(empty($sf['avatar'])) echo strtoupper(substr($sellerUser['name'] ?? 'S', 0, 1)); ?>
                </div>
                <div>
                    <p style="margin:0 0 8px;font-size:0.85rem;color:var(--text-secondary);">Profile photo — square, 400×400px recommended</p>
                    <label class="sf-upload-btn">
                        <i class="fas fa-camera"></i> Change Photo
                        <input type="file" name="avatar" accept="image/*" onchange="previewSfAvatar(this)">
                    </label>
                </div>
            </div>

            <hr style="border:none;border-top:1px solid var(--border-color);margin:20px 0;">

            <!-- Banner -->
            <div class="sf-field full" style="margin-bottom:20px;">
                <label>Cover Banner</label>
                <label for="bannerInput">
                    <div class="sf-banner-preview <?php echo !empty($sf['banner']) ? 'has-image' : ''; ?>" id="sfBannerPreview"
                         <?php if(!empty($sf['banner'])) echo 'style="background-image:url(../'.htmlspecialchars($sf['banner']).');"'; ?>>
                        <?php if(empty($sf['banner'])): ?>
                            <i class="fas fa-image" style="font-size:1.5rem;"></i>
                            <span>Click to upload banner (1920×400px)</span>
                        <?php endif; ?>
                        <div class="sf-banner-overlay"><i class="fas fa-camera"></i> Change Banner</div>
                    </div>
                </label>
                <input type="file" name="banner" id="bannerInput" accept="image/*" style="display:none;" onchange="previewSfBanner(this)">
                <div class="sf-hint">Wide banner image displayed at the top of your storefront</div>
            </div>

            <div class="sf-form-grid">
                <div class="sf-field">
                    <label for="sf-store-name">Store Name</label>
                    <input type="text" id="sf-store-name" name="store_name" value="<?php echo htmlspecialchars($sf['store_name'] ?? ''); ?>" placeholder="e.g. JDM Garage Collectibles">
                    <div class="sf-hint">Displayed as the main heading on your storefront</div>
                </div>
                <div class="sf-field">
                    <label for="sf-location">Store Location</label>
                    <input type="text" id="sf-location" name="store_location" value="<?php echo htmlspecialchars($sf['store_location'] ?? ''); ?>" placeholder="https://www.google.com/maps/place/...">
                    <div class="sf-hint">Paste your Google Maps link — a "Store Location" button will appear on your storefront</div>
                </div>
                <div class="sf-field full">
                    <label for="sf-bio">Bio / Store Description</label>
                    <textarea id="sf-bio" name="bio" rows="4" placeholder="Tell buyers about your store, what you specialize in, your collection story..."><?php echo htmlspecialchars($sf['bio'] ?? ''); ?></textarea>
                    <div class="sf-hint">Shown below your store name on the public profile</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ SECTION: SOCIAL LINKS ═══ -->
    <div class="sf-card">
        <div class="sf-card-header">
            <h3><i class="fas fa-share-alt"></i> Social Links</h3>
        </div>
        <div class="sf-card-body">
            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:20px;">Add your social media links — they'll appear on your public storefront.</p>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div class="sf-social-row">
                    <i class="fab fa-instagram"></i>
                    <input type="text" name="social_instagram" value="<?php echo htmlspecialchars($sf['social_instagram'] ?? ''); ?>" placeholder="https://instagram.com/yourstore">
                </div>
                <div class="sf-social-row">
                    <i class="fab fa-facebook"></i>
                    <input type="text" name="social_facebook" value="<?php echo htmlspecialchars($sf['social_facebook'] ?? ''); ?>" placeholder="https://facebook.com/yourstore">
                </div>
                <div class="sf-social-row">
                    <i class="fab fa-twitter"></i>
                    <input type="text" name="social_twitter" value="<?php echo htmlspecialchars($sf['social_twitter'] ?? ''); ?>" placeholder="https://twitter.com/yourstore">
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ SECTION: SHIPPING POLICY ═══ -->
    <div class="sf-card">
        <div class="sf-card-header">
            <h3><i class="fas fa-truck"></i> Shipping Policy</h3>
            <span class="sf-card-badge">Storefront</span>
        </div>
        <div class="sf-card-body">
            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:20px;">Set a minimum cart value above which buyers get <strong style="color:var(--accent-green,#34d399);">free shipping</strong> from your store. Leave empty to disable.</p>
            <div class="sf-form-grid">
                <div class="sf-field">
                    <label for="sf-free-ship">Free Shipping Above (₹)</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-weight:700;font-size:0.95rem;">₹</span>
                        <input type="number" id="sf-free-ship" name="free_shipping_threshold" min="0" step="1" style="padding-left:34px;" value="<?php echo htmlspecialchars($sf['free_shipping_threshold'] ?? ''); ?>" placeholder="e.g. 499">
                    </div>
                    <div class="sf-hint">Buyers whose cart value from your store exceeds this amount will get free shipping. Set to 0 for always-free shipping.</div>
                </div>
                <div class="sf-field" style="display:flex;align-items:center;justify-content:center;">
                    <div style="text-align:center;padding:16px 20px;background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.15);border-radius:12px;width:100%;">
                        <?php if (!empty($sf['free_shipping_threshold']) || (isset($sf['free_shipping_threshold']) && $sf['free_shipping_threshold'] === '0')): ?>
                            <i class="fas fa-truck" style="font-size:1.5rem;color:#34d399;margin-bottom:6px;display:block;"></i>
                            <div style="font-size:0.82rem;color:#34d399;font-weight:700;">Free Shipping</div>
                            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">on orders above ₹<?php echo number_format(floatval($sf['free_shipping_threshold']), 0); ?></div>
                        <?php else: ?>
                            <i class="fas fa-truck" style="font-size:1.5rem;color:var(--text-muted);margin-bottom:6px;display:block;opacity:0.3;"></i>
                            <div style="font-size:0.82rem;color:var(--text-muted);font-weight:600;">Not Set</div>
                            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">Enter an amount to enable</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══ SAVE BAR ═══ -->
    <div class="sf-save-bar">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            <button type="reset" class="btn-secondary">Reset</button>
        </div>
        <a href="../seller.php?id=<?php echo $sellerId; ?>" target="_blank" class="btn-secondary" style="gap:8px;">
            <i class="fas fa-eye"></i> Preview Storefront
        </a>
    </div>
</form>

<!-- ═══ SECTION: STORY HIGHLIGHTS (outside main form, uses AJAX) ═══ -->
<div class="sf-card" style="margin-top:24px;">
    <div class="sf-card-header">
        <h3><i class="fas fa-circle-notch"></i> Story Highlights</h3>
        <button type="button" class="sf-upload-btn" onclick="createHighlight()" style="border-color:var(--accent-red); color:var(--accent-red);">
            <i class="fas fa-plus"></i> New Highlight
        </button>
    </div>
    <div class="sf-card-body">
        <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:20px;">
            Showcase your best transactions, reviews, and collection to buyers. These appear on your public storefront as story-style highlights.
        </p>

        <div id="hlContainer">
            <?php if (empty($highlights)): ?>
            <div class="hl-empty-state" id="hlEmptyState">
                <i class="far fa-images" style="font-size:2.5rem; opacity:0.1; margin-bottom:12px; display:block;"></i>
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">No highlights yet. Create your first one above!</p>
            </div>
            <?php else: ?>
            <div class="hl-sf-grid" id="hlGrid">
                <?php foreach ($highlights as $hl): ?>
                <div class="hl-sf-card" id="hlCard<?php echo $hl['id']; ?>">
                    <div class="hl-sf-card-header">
                        <div class="hl-sf-cover">
                            <?php if (!empty($hl['cover_image'])): ?>
                                <img src="../<?php echo htmlspecialchars($hl['cover_image']); ?>" alt="">
                            <?php else: ?>
                                <i class="far fa-image"></i>
                            <?php endif; ?>
                        </div>
                        <input type="text" class="hl-sf-title" value="<?php echo htmlspecialchars($hl['title']); ?>"
                               onchange="hlUpdateTitle(<?php echo $hl['id']; ?>, this.value)" placeholder="Highlight name...">
                        <button type="button" class="hl-sf-del" title="Delete highlight" onclick="hlDelete(<?php echo $hl['id']; ?>)">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                    <div class="hl-sf-images">
                        <?php foreach ($hl['images'] as $img): ?>
                        <div class="hl-sf-thumb <?php echo ($img['image_path'] === $hl['cover_image']) ? 'is-cover' : ''; ?>" id="hlImg<?php echo $img['id']; ?>">
                            <img src="../<?php echo htmlspecialchars($img['image_path']); ?>" alt="">
                            <div class="hl-sf-thumb-actions">
                                <button type="button" title="Set as cover" onclick="hlSetCover(<?php echo $hl['id']; ?>, '<?php echo htmlspecialchars($img['image_path'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-star"></i>
                                </button>
                                <button type="button" class="del" title="Delete" onclick="hlDelImage(<?php echo $img['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <label class="hl-sf-upload-zone">
                            <i class="fas fa-plus"></i>
                            <span>Add</span>
                            <input type="file" accept="image/*" onchange="hlUpload(<?php echo $hl['id']; ?>, this)" style="display:none;">
                        </label>
                    </div>
                    <div class="hl-sf-count"><?php echo $hl['image_count']; ?> image<?php echo $hl['image_count'] != 1 ? 's' : ''; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* ── Highlights in Storefront ── */
.hl-empty-state {
    text-align: center; padding: 48px 20px;
    background: rgba(255,255,255,0.02); border-radius: 12px;
    border: 1px dashed rgba(255,255,255,0.06);
}
.hl-sf-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;
}
.hl-sf-card {
    background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);
    border-radius: 14px; overflow: hidden; transition: border-color 0.2s;
}
.hl-sf-card:hover { border-color: rgba(255,255,255,0.1); }

.hl-sf-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.04);
}
.hl-sf-cover {
    width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,255,255,0.05); border: 2px solid rgba(229,57,53,0.3);
    overflow: hidden; display: flex; align-items: center; justify-content: center;
    color: var(--text-muted); font-size: 0.85rem;
}
.hl-sf-cover img { width: 100%; height: 100%; object-fit: cover; }

.hl-sf-title {
    flex: 1; min-width: 0;
    background: transparent; border: 1px solid transparent; border-radius: 6px;
    padding: 5px 8px; color: var(--text-primary); font-size: 0.9rem;
    font-weight: 600; font-family: inherit; outline: none; transition: 0.2s;
}
.hl-sf-title:hover { border-color: rgba(255,255,255,0.08); }
.hl-sf-title:focus { border-color: rgba(229,57,53,0.4); background: rgba(255,255,255,0.03); }

.hl-sf-del {
    width: 30px; height: 30px; border-radius: 6px; border: none; flex-shrink: 0;
    background: transparent; color: var(--text-muted); font-size: 0.72rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: 0.2s;
}
.hl-sf-del:hover { background: rgba(229,57,53,0.1); color: #e53935; }

.hl-sf-images {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: 6px; padding: 12px 14px;
}

.hl-sf-thumb {
    aspect-ratio: 1; border-radius: 8px; overflow: hidden; position: relative;
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);
    cursor: pointer; transition: 0.15s;
}
.hl-sf-thumb:hover { border-color: rgba(229,57,53,0.3); }
.hl-sf-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hl-sf-thumb.is-cover { border-color: rgba(255,183,77,0.4); }
.hl-sf-thumb.is-cover::after {
    content: '\2605'; position: absolute; top: 3px; right: 3px; font-size: 0.55rem;
    background: rgba(255,183,77,0.9); color: #000; width: 14px; height: 14px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}

.hl-sf-thumb-actions {
    position: absolute; inset: 0; background: rgba(0,0,0,0.6);
    display: none; align-items: center; justify-content: center; gap: 4px;
}
.hl-sf-thumb:hover .hl-sf-thumb-actions { display: flex; }
.hl-sf-thumb-actions button {
    width: 24px; height: 24px; border-radius: 5px; border: none;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 0.6rem; transition: 0.15s;
    background: rgba(255,183,77,0.2); color: #ffb74d;
}
.hl-sf-thumb-actions button:hover { background: rgba(255,183,77,0.4); }
.hl-sf-thumb-actions button.del { background: rgba(229,57,53,0.2); color: #ef5350; }
.hl-sf-thumb-actions button.del:hover { background: rgba(229,57,53,0.4); }

.hl-sf-upload-zone {
    aspect-ratio: 1; border-radius: 8px; border: 2px dashed rgba(255,255,255,0.08);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    cursor: pointer; transition: 0.2s; color: var(--text-muted);
    font-size: 0.65rem; gap: 2px;
}
.hl-sf-upload-zone:hover { border-color: rgba(229,57,53,0.3); background: rgba(229,57,53,0.03); color: #e53935; }
.hl-sf-upload-zone i { font-size: 0.9rem; }

.hl-sf-count {
    font-size: 0.7rem; color: var(--text-muted); padding: 0 14px 10px;
}

@media (max-width: 600px) {
    .hl-sf-grid { grid-template-columns: 1fr; }
}
</style>

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

function hlUpdateTitle(hlId, title) {
    const fd = new FormData();
    fd.append('action', 'update_title');
    fd.append('highlight_id', hlId);
    fd.append('title', title);
    fetch('../api/highlights.php', { method: 'POST', body: fd }).catch(() => {});
}

function hlUpload(hlId, input) {
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

function hlSetCover(hlId, imgPath) {
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

function hlDelImage(imgId) {
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

function hlDelete(hlId) {
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

<script>
function previewSfAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const prev = document.getElementById('sfAvatarPreview');
            prev.style.backgroundImage = 'url(' + e.target.result + ')';
            prev.style.color = 'transparent';
            prev.textContent = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewSfBanner(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const prev = document.getElementById('sfBannerPreview');
            prev.style.backgroundImage = 'url(' + e.target.result + ')';
            prev.innerHTML = '<div class="sf-banner-overlay"><i class="fas fa-camera"></i> Change Banner</div>';
            prev.classList.add('has-image');
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<script>
function shareStorefrontDashboard() {
    const url = window.location.origin + '/seller.php?id=<?php echo $sellerId; ?>';
    if (navigator.share) {
        navigator.share({
            title: 'My Store on REDLINER',
            text: 'Check out my store on REDLINER!',
            url: url
        }).catch(err => {
            console.log('Error sharing', err);
        });
    } else {
        navigator.clipboard.writeText(url).then(() => {
            alert('Storefront link copied to clipboard!');
        }).catch(err => {
            alert('Failed to copy link.');
        });
    }
}
</script>

<?php include 'footer.php'; ?>
