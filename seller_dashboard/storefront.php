<?php
$pageTitle = 'Update Storefront';
include 'header.php';

$sellerId = $_SESSION['user_id'];
$sfError = '';
$sfSuccess = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_storefront'])) {
    $store_name = trim($_POST['store_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $store_location = trim($_POST['store_location'] ?? '');
    $social_instagram = trim($_POST['social_instagram'] ?? '');
    $social_facebook = trim($_POST['social_facebook'] ?? '');
    $social_twitter = trim($_POST['social_twitter'] ?? '');
    $show_reviews = isset($_POST['show_reviews']) ? 1 : 0;

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
            'show_reviews = ?'
        ];
        $params = [$store_name, $bio, $store_location, $social_instagram, $social_facebook, $social_twitter, $show_reviews];

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
    $stmt = $conn->prepare("SELECT store_name, avatar, banner, bio, store_location, social_instagram, social_facebook, social_twitter, show_reviews FROM users WHERE id = ?");
    $stmt->execute([$sellerId]);
    $sf = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sf = [];
}

// Fetch review count for info display
$reviewCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM seller_reviews WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $reviewCount = $stmt->fetchColumn();
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
                    <input type="text" id="sf-location" name="store_location" value="<?php echo htmlspecialchars($sf['store_location'] ?? ''); ?>" placeholder="e.g. Mumbai, Maharashtra">
                    <div class="sf-hint">Helps buyers find local sellers</div>
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

    <!-- ═══ SECTION: CUSTOMER FEEDBACK ═══ -->
    <div class="sf-card">
        <div class="sf-card-header">
            <h3><i class="fas fa-star-half-alt"></i> Customer Feedback</h3>
        </div>
        <div class="sf-card-body">
            <div class="sf-toggle-wrap">
                <div class="sf-toggle-info">
                    <h4>Show Reviews on Storefront</h4>
                    <p>When enabled, customer reviews and ratings will be visible on your public profile. You have <?php echo $reviewCount; ?> review<?php echo $reviewCount !== 1 ? 's' : ''; ?>.</p>
                </div>
                <label class="sf-toggle">
                    <input type="checkbox" name="show_reviews" value="1" <?php echo (!isset($sf['show_reviews']) || $sf['show_reviews']) ? 'checked' : ''; ?>>
                    <span class="sf-toggle-slider"></span>
                </label>
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

<?php include 'footer.php'; ?>
