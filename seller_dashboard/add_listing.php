<?php
// seller_dashboard/add_listing.php
$pageTitle = 'Add New Listing';
include 'header.php';

$seller_id = $_SESSION['user_id'];
$message = '';
$msgType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $condition = $_POST['condition'] ?? 'new';
    $price = floatval($_POST['price'] ?? 0);
    $stock = max(1, intval($_POST['stock'] ?? 1));
    $scale = $_POST['scale'] ?? '';
    $description = trim($_POST['description'] ?? '');
    
    // Basic validation
    if (empty($title) || $category_id <= 0 || empty($scale) || $price <= 0) {
        $message = "Please fill in all required fields (Title, Category, Price, Scale).";
        $msgType = "danger";
    } else {
        $primaryImage = '';
        $uploadedImages = [];
        
        // Handle Multiple Image Upload
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $uploadDir = '../uploads/listings/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileCount = count($_FILES['images']['name']);
            $maxFiles = 8; // limit
            
            for ($i = 0; $i < min($fileCount, $maxFiles); $i++) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($fileInfo, $_FILES['images']['tmp_name'][$i]);
                finfo_close($fileInfo);
                
                if (!in_array($mimeType, $allowedTypes)) continue;
                
                $extension = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
                $uniqueName = uniqid('listing_') . '_' . time() . '_' . $i . '.' . $extension;
                $destination = $uploadDir . $uniqueName;
                
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $destination)) {
                    $relativePath = 'uploads/listings/' . $uniqueName;
                    $uploadedImages[] = $relativePath;
                    if ($i === 0) $primaryImage = $relativePath;
                }
            }
        }
        
        // If validation passed and no upload errors
        if ($msgType !== 'danger') {
            try {
                $conn->beginTransaction();
                
                // Insert listing with primary image
                $stmt = $conn->prepare("
                    INSERT INTO listings 
                    (seller_id, category_id, title, description, price, image, `condition`, stock, scale, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([
                    $seller_id, $category_id, $title, $description, $price, 
                    $primaryImage, $condition, $stock, $scale
                ]);
                $listingId = $conn->lastInsertId();
                
                // Insert all images into listing_images table
                if (!empty($uploadedImages)) {
                    $imgStmt = $conn->prepare("INSERT INTO listing_images (listing_id, image_path, sort_order) VALUES (?, ?, ?)");
                    foreach ($uploadedImages as $idx => $imgPath) {
                        $imgStmt->execute([$listingId, $imgPath, $idx]);
                    }
                }
                
                $conn->commit();
                
                // Notify followers
                try {
                    $sellerStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                    $sellerStmt->execute([$seller_id]);
                    $sellerName = $sellerStmt->fetchColumn();
                    
                    $fStmt = $conn->prepare("
                        SELECT u.id, u.name, u.email 
                        FROM user_follows uf 
                        JOIN users u ON uf.follower_id = u.id 
                        WHERE uf.seller_id = ?
                    ");
                    $fStmt->execute([$seller_id]);
                    $followers = $fStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($followers)) {
                        $notifStmt = $conn->prepare("
                            INSERT INTO notifications (user_id, type, message, link, is_read, created_at) 
                            VALUES (?, 'follower_update', ?, ?, 0, NOW())
                        ");
                        
                        $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF']));
                        $listingUrl = $baseUrl . "/listing.php?id=" . $listingId;
                        $notifMsg = "Your favorite seller " . $sellerName . " just listed: " . $title;
                        
                        $headers = "MIME-Version: 1.0\r\n";
                        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                        $headers .= "From: REDLINE Notifications <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
                        
                        foreach ($followers as $follower) {
                            $notifStmt->execute([$follower['id'], $notifMsg, "listing.php?id=" . $listingId]);
                            
                            $subject = "New Listing from " . $sellerName . " on REDLINE!";
                            $emailBody = "
                            <html>
                            <head><title>New Listing from " . $sellerName . "</title></head>
                            <body style='font-family: sans-serif; color: #333;'>
                                <div style='background: #111; padding: 20px; text-align: center;'>
                                    <h1 style='color: #e53935; margin: 0;'>REDLINE</h1>
                                </div>
                                <div style='padding: 20px; text-align: center;'>
                                    <h2>Hi " . htmlspecialchars($follower['name']) . ",</h2>
                                    <p>Your favorite seller <strong>" . htmlspecialchars($sellerName) . "</strong> has just posted a new item:</p>
                                    <h3 style='color: #e53935; border: 1px solid #ddd; padding: 10px; display: inline-block;'>" . htmlspecialchars($title) . " - Rs." . number_format($price) . "</h3>
                                    <br><br>
                                    <a href='" . htmlspecialchars($listingUrl) . "' style='background: #e53935; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;'>View Listing</a>
                                </div>
                            </body>
                            </html>
                            ";
                            @mail($follower['email'], $subject, $emailBody, $headers);
                        }
                    }
                } catch (PDOException $e) {}
                
                $message = "Listing created successfully! Your item is now live in your inventory.";
                $msgType = "success";
                
            } catch(PDOException $e) {
                $conn->rollBack();
                $message = "Database error: " . $e->getMessage();
                $msgType = "danger";
            }
        }
    }
}

// Fetch categories for the dropdown
$categories = [];
try {
    $stmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

?>

<div class="page-header">
    <div class="page-title">
        <h1>Add New Inventory</h1>
        <p>List a new diecast model to your storefront</p>
    </div>
</div>

<div class="panel" style="max-width: 800px; margin: 0 auto;">
    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msgType === 'success' ? 'rgba(16,185,129,0.1)' : 'rgba(229,57,53,0.1)'; ?>; border: 1px solid <?php echo $msgType === 'success' ? 'rgba(16,185,129,0.2)' : 'rgba(229,57,53,0.2)'; ?>; color: <?php echo $msgType === 'success' ? 'var(--accent-green)' : 'var(--accent-red)'; ?>; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
            <i class="fas <?php echo $msgType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($msgType === 'success'): ?>
        <div style="text-align:center; padding: 40px 0;">
            <i class="fas fa-box-open" style="font-size:3rem; color:var(--accent-green); margin-bottom:20px;"></i>
            <h3 style="margin-bottom:24px;">Item Added Successfully</h3>
            <a href="listings.php" class="btn-secondary">View Inventory</a>
            <a href="add_listing.php" class="btn-primary" style="margin-left:12px;">Add Another Item</a>
        </div>
    <?php else: ?>
    
    <form action="add_listing.php" method="POST" enctype="multipart/form-data">
        
        <style>
            .seller-form-group { margin-bottom: 24px; }
            .seller-form-label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500; }
            .seller-form-control { width: 100%; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); color: var(--text-primary); padding: 12px 16px; border-radius: 10px; font-size: 0.95rem; transition: all 0.2s; font-family:var(--font-sans); }
            .seller-form-control:focus { outline: none; border-color: var(--border-hover); background: rgba(255,255,255,0.04); }
            .seller-form-control::placeholder { color: var(--text-muted); }
            
            /* Multi-image upload area */
            .multi-upload-area {
                border: 2px dashed var(--border-color); border-radius: 14px; padding: 24px;
                background: rgba(255,255,255,0.01); transition: all 0.3s; cursor: pointer;
                text-align: center; position: relative;
            }
            .multi-upload-area:hover, .multi-upload-area.drag-over {
                border-color: var(--accent-red); background: rgba(229,57,53,0.03);
            }
            .multi-upload-area .upload-icon { font-size: 2.5rem; color: var(--text-muted); margin-bottom: 10px; transition: color 0.3s; }
            .multi-upload-area:hover .upload-icon { color: var(--accent-red); }
            .multi-upload-area .upload-text { font-size: 0.92rem; color: var(--text-secondary); margin-bottom: 4px; }
            .multi-upload-area .upload-hint { font-size: 0.78rem; color: var(--text-muted); }
            .multi-upload-area input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

            /* Preview Grid */
            .image-preview-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 10px; margin-top: 16px;
            }
            .preview-thumb {
                position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden;
                border: 2px solid var(--border-color); background: var(--bg-card); transition: border-color 0.2s;
            }
            .preview-thumb:first-child { border-color: var(--accent-red); }
            .preview-thumb img { width: 100%; height: 100%; object-fit: cover; }
            .preview-thumb .thumb-badge {
                position: absolute; top: 4px; left: 4px; font-size: 0.55rem; font-weight: 800;
                background: var(--accent-red); color: #fff; padding: 2px 6px; border-radius: 6px;
                text-transform: uppercase; letter-spacing: 0.04em;
            }
            .preview-thumb .thumb-remove {
                position: absolute; top: 4px; right: 4px; width: 20px; height: 20px;
                border-radius: 50%; background: rgba(0,0,0,0.7); color: #fff; border: none;
                font-size: 0.6rem; cursor: pointer; display: flex; align-items: center;
                justify-content: center; opacity: 0; transition: opacity 0.2s;
            }
            .preview-thumb:hover .thumb-remove { opacity: 1; }
            .preview-thumb:hover .thumb-remove { opacity: 1; }

            /* Grid Layouts */
            .seller-form-grid { display: grid; gap: 24px; }
            .grid-3-2-1-1 { grid-template-columns: 2fr 1fr 1fr; }
            .grid-3-1-1-1 { grid-template-columns: 1fr 1fr 1fr; }

            @media (max-width: 768px) {
                .seller-form-grid { grid-template-columns: 1fr !important; gap: 16px; }
                .seller-form-group { margin-bottom: 16px; }
            }
        </style>

        <!-- Multi-Image Upload -->
        <div class="seller-form-group">
            <label class="seller-form-label">Product Images * <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">(up to 8 images, first = cover)</span></label>
            <div class="multi-upload-area" id="uploadArea">
                <i class="fas fa-cloud-upload-alt upload-icon"></i>
                <div class="upload-text">Click or drag & drop images here</div>
                <div class="upload-hint">JPG, PNG, WebP up to 5MB each · First image becomes the cover</div>
                <input type="file" name="images[]" id="imageInput" accept="image/jpeg, image/png, image/webp" multiple required>
            </div>
            <div class="image-preview-grid" id="previewGrid"></div>
        </div>
        
        <div class="seller-form-grid grid-3-2-1-1">
            <div class="seller-form-group">
                <label class="seller-form-label">Listing Title *</label>
                <input type="text" name="title" class="seller-form-control" placeholder="e.g. Hot Wheels '67 Camaro Treasure Hunt" required>
            </div>
            
            <div class="seller-form-group">
                <label class="seller-form-label">Price (Rs.) *</label>
                <div style="position:relative;">
                    <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:600;">Rs.</span>
                    <input type="number" name="price" class="seller-form-control" style="padding-left:45px;" placeholder="0.00" step="0.01" min="1" required>
                </div>
            </div>

            <div class="seller-form-group">
                <label class="seller-form-label">Stock Qty *</label>
                <div style="position:relative;">
                    <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-muted);"><i class="fas fa-layer-group" style="font-size:0.85rem;"></i></span>
                    <input type="number" name="stock" class="seller-form-control" style="padding-left:42px;" placeholder="1" min="1" value="1" required>
                </div>
            </div>
        </div>

        <div class="seller-form-grid grid-3-1-1-1">
            <div class="seller-form-group">
                <label class="seller-form-label">Category / Line *</label>
                <div style="position:relative;">
                    <select name="category_id" class="seller-form-control" required style="appearance: none;">
                        <option value="" disabled selected>Select a product line</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>
            
            <div class="seller-form-group">
                <label class="seller-form-label">Size / Scale *</label>
                <div style="position:relative;">
                    <select name="scale" class="seller-form-control" required style="appearance: none;">
                        <option value="" disabled selected>Select a Product Size/Scale</option>
                        <option value="1:12">1:12 Scale</option>
                        <option value="1:18">1:18 Scale</option>
                        <option value="1:24">1:24 Scale</option>
                        <option value="1:32">1:32 Scale</option>
                        <option value="1:36">1:36 Scale</option>
                        <option value="1:43">1:43 Scale</option>
                        <option value="1:64">1:64 Scale (Hot Wheels size)</option>
                        <option value="1:72">1:72 Scale</option>
                        <option value="1:87">1:87 Scale (HO)</option>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>

            <div class="seller-form-group">
                <label class="seller-form-label">Condition *</label>
                <div style="position:relative;">
                    <select name="condition" class="seller-form-control" required style="appearance: none;">
                        <option value="new">New / Carded (Sealed)</option>
                        <option value="opened">Opened / Mint (Loose)</option>
                        <option value="used">Used / Played With</option>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>
        </div>

        <div class="seller-form-group">
            <label class="seller-form-label">Shipping Method *</label>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:rgba(255,255,255,0.03); border:1px solid var(--border-color); border-radius:10px; cursor:pointer;">
                    <input type="radio" name="shipping_method" value="self" checked style="accent-color:var(--accent-red); width:18px; height:18px;">
                    <div style="flex-grow:1;">
                        <span style="display:block; font-weight:600; font-size:0.95rem; color:var(--text-primary);">Ship by yourself</span>
                        <span style="display:block; font-size:0.8rem; color:var(--text-muted); margin-top:2px;">You will pack and ship the item directly to the buyer safely.</span>
                    </div>
                </label>
                
                <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:rgba(255,255,255,0.01); border:1px dashed rgba(255,255,255,0.1); border-radius:10px; opacity:0.5; cursor:not-allowed;">
                    <input type="radio" name="shipping_method" value="redline" disabled style="width:18px; height:18px;">
                    <div style="flex-grow:1;">
                        <span style="display:block; font-weight:600; font-size:0.95rem; color:var(--text-primary);">Ship by REDLINE <span style="background:var(--accent-red); color:#fff; font-size:0.6rem; padding:3px 6px; border-radius:4px; margin-left:8px; text-transform:uppercase; font-weight:800; letter-spacing:0.5px;">Coming Soon</span></span>
                        <span style="display:block; font-size:0.8rem; color:var(--text-muted); margin-top:2px;">We handle the pickup, packing, and secure delivery for you.</span>
                    </div>
                </label>
            </div>
        </div>

        <div class="seller-form-group">
            <label class="seller-form-label">Description & Comments</label>
            <textarea name="description" class="seller-form-control" rows="5" placeholder="Describe the item condition, any corner tear, protector box inclusion, etc."></textarea>
        </div>

        <div style="margin-top: 32px; display:flex; justify-content:flex-end; gap:12px;">
            <a href="listings.php" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary">
                Publish Listing <i class="fas fa-paper-plane" style="font-size:0.85rem;"></i>
            </button>
        </div>

    </form>
    <?php endif; ?>
</div>

<!-- Multi-Image Preview JS -->
<script>
(function() {
    const input = document.getElementById('imageInput');
    const grid = document.getElementById('previewGrid');
    const area = document.getElementById('uploadArea');
    if (!input || !grid) return;

    let fileList = new DataTransfer();

    input.addEventListener('change', function() {
        // Merge new files into the DataTransfer list (max 8)
        for (let i = 0; i < this.files.length; i++) {
            if (fileList.items.length >= 8) break;
            fileList.items.add(this.files[i]);
        }
        this.files = fileList.files;
        renderPreviews();
    });

    // Drag & drop enhancement
    ['dragenter', 'dragover'].forEach(ev => {
        area.addEventListener(ev, e => { e.preventDefault(); area.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(ev => {
        area.addEventListener(ev, e => { e.preventDefault(); area.classList.remove('drag-over'); });
    });

    function renderPreviews() {
        grid.innerHTML = '';
        const files = fileList.files;
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const thumb = document.createElement('div');
            thumb.className = 'preview-thumb';
            
            const img = document.createElement('img');
            const reader = new FileReader();
            reader.onload = (e) => { img.src = e.target.result; };
            reader.readAsDataURL(file);
            thumb.appendChild(img);

            if (i === 0) {
                const badge = document.createElement('span');
                badge.className = 'thumb-badge';
                badge.textContent = 'Cover';
                thumb.appendChild(badge);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'thumb-remove';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.dataset.index = i;
            removeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const idx = parseInt(this.dataset.index);
                const newDT = new DataTransfer();
                for (let j = 0; j < fileList.files.length; j++) {
                    if (j !== idx) newDT.items.add(fileList.files[j]);
                }
                fileList = newDT;
                input.files = fileList.files;
                renderPreviews();
            });
            thumb.appendChild(removeBtn);

            grid.appendChild(thumb);
        }
    }
})();
</script>

<?php include 'footer.php'; ?>
