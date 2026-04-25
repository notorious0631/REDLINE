<?php
// seller_dashboard/edit_listing.php
$pageTitle = 'Edit Listing';
include 'header.php';

$seller_id = $_SESSION['user_id'];
$message = '';
$msgType = '';

// Get listing ID from URL
$listing_id = intval($_GET['id'] ?? 0);
if ($listing_id <= 0) {
    header('Location: listings.php');
    exit;
}

// Fetch existing listing (verify ownership)
$listing = null;
try {
    $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ? AND seller_id = ?");
    $stmt->execute([$listing_id, $seller_id]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if (!$listing) {
    header('Location: listings.php');
    exit;
}

// Fetch current images
$currentImages = [];
try {
    $stmt = $conn->prepare("SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$listing_id]);
    $currentImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Handle image deletion via AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image_id'])) {
    $imgId = intval($_POST['delete_image_id']);
    try {
        $stmt = $conn->prepare("SELECT image_path FROM listing_images WHERE id = ? AND listing_id = ?");
        $stmt->execute([$imgId, $listing_id]);
        $imgRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($imgRow) {
            // Delete file
            $filePath = '../' . $imgRow['image_path'];
            if (file_exists($filePath)) @unlink($filePath);
            // Delete DB row
            $conn->prepare("DELETE FROM listing_images WHERE id = ?")->execute([$imgId]);
            // Update primary image in listings table
            $stmt = $conn->prepare("SELECT image_path FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC LIMIT 1");
            $stmt->execute([$listing_id]);
            $newPrimary = $stmt->fetchColumn() ?: '';
            $conn->prepare("UPDATE listings SET image = ? WHERE id = ?")->execute([$newPrimary, $listing_id]);
        }
    } catch (PDOException $e) {}
    header("Location: edit_listing.php?id=$listing_id&img_deleted=1");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = trim($_POST['title'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $condition = $_POST['condition'] ?? 'new';
    $price = floatval($_POST['price'] ?? 0);
    $stock = max(1, intval($_POST['stock'] ?? 1));
    $scale = $_POST['scale'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? $listing['status'];
    
    // Validate status
    $allowedStatuses = ['active', 'inactive'];
    if (!in_array($status, $allowedStatuses)) {
        $status = $listing['status'];
    }
    
    // Basic validation
    if (empty($title) || $category_id <= 0 || empty($scale) || $price <= 0) {
        $message = "Please fill in all required fields (Title, Category, Price, Scale).";
        $msgType = "danger";
    } else {
        // Handle new image uploads
        $newImages = [];
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $uploadDir = '../uploads/listings/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            // Count existing images
            $existingCount = count($currentImages);
            $maxNew = 8 - $existingCount;
            $fileCount = count($_FILES['images']['name']);
            
            for ($i = 0; $i < min($fileCount, $maxNew); $i++) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($fileInfo, $_FILES['images']['tmp_name'][$i]);
                finfo_close($fileInfo);
                if (!in_array($mimeType, $allowedTypes)) continue;
                
                $extension = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
                $uniqueName = uniqid('listing_') . '_' . time() . '_' . $i . '.' . $extension;
                $destination = $uploadDir . $uniqueName;
                
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $destination)) {
                    $newImages[] = 'uploads/listings/' . $uniqueName;
                }
            }
        }
        
        if ($msgType !== 'danger') {
            try {
                $conn->beginTransaction();
                
                // Insert new images
                if (!empty($newImages)) {
                    $maxSort = 0;
                    if (!empty($currentImages)) {
                        $maxSort = max(array_column($currentImages, 'sort_order')) + 1;
                    }
                    $imgStmt = $conn->prepare("INSERT INTO listing_images (listing_id, image_path, sort_order) VALUES (?, ?, ?)");
                    foreach ($newImages as $idx => $imgPath) {
                        $imgStmt->execute([$listing_id, $imgPath, $maxSort + $idx]);
                    }
                }
                
                // Get primary image (first by sort order)
                $stmt = $conn->prepare("SELECT image_path FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC LIMIT 1");
                $stmt->execute([$listing_id]);
                $primaryImage = $stmt->fetchColumn() ?: $listing['image'];
                
                // Update listing
                $stmt = $conn->prepare("
                    UPDATE listings 
                    SET category_id = ?, title = ?, description = ?, price = ?, image = ?, `condition` = ?, stock = ?, scale = ?, status = ?
                    WHERE id = ? AND seller_id = ?
                ");
                $stmt->execute([
                    $category_id, $title, $description, $price, 
                    $primaryImage, $condition, $stock, $scale, $status,
                    $listing_id, $seller_id
                ]);
                
                $conn->commit();
                
                // Back-in-stock notification: if stock was 0 (or status was sold) and now stock > 0
                $oldStock = intval($listing['stock'] ?? 0);
                $oldStatus = $listing['status'] ?? '';
                $wasOutOfStock = ($oldStock <= 0 || $oldStatus === 'sold');
                $isNowInStock = ($stock > 0 && $status === 'active');
                
                if ($wasOutOfStock && $isNowInStock) {
                    try {
                        // Find all users who wishlisted this item
                        $wlStmt = $conn->prepare("
                            SELECT u.id, u.name, u.email 
                            FROM wishlists w 
                            JOIN users u ON w.user_id = u.id 
                            WHERE w.listing_id = ?
                        ");
                        $wlStmt->execute([$listing_id]);
                        $waiters = $wlStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($waiters)) {
                            $notifStmt = $conn->prepare("
                                INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
                                VALUES (?, 'wishlist_restock', ?, ?, 0, NOW())
                            ");
                            
                            $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF']));
                            $listingUrl = $baseUrl . "/listing.php?id=" . $listing_id;
                            $notifMsg = "Great news! \"" . $title . "\" is back in stock! Grab it before it's gone.";
                            
                            $headers = "MIME-Version: 1.0\r\n";
                            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                            $headers .= "From: REDLINE Notifications <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
                            
                            foreach ($waiters as $waiter) {
                                $notifStmt->execute([$waiter['id'], $notifMsg, "listing.php?id=" . $listing_id]);
                                
                                $subject = "Back in Stock: " . $title . " — REDLINE";
                                $emailBody = "
                                <html>
                                <head><title>Back in Stock!</title></head>
                                <body style='font-family: sans-serif; color: #333;'>
                                    <div style='background: #111; padding: 20px; text-align: center;'>
                                        <h1 style='color: #e53935; margin: 0;'>REDLINE</h1>
                                    </div>
                                    <div style='padding: 20px; text-align: center;'>
                                        <h2>Hi " . htmlspecialchars($waiter['name']) . ",</h2>
                                        <p>An item on your wishlist is <strong style='color:#4caf50;'>back in stock</strong>!</p>
                                        <h3 style='color: #e53935; border: 1px solid #ddd; padding: 12px; display: inline-block; border-radius: 8px;'>" . htmlspecialchars($title) . " — Rs." . number_format($price) . "</h3>
                                        <br><br>
                                        <a href='" . htmlspecialchars($listingUrl) . "' style='background: #e53935; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Buy Now</a>
                                        <p style='margin-top:20px; font-size:0.85rem; color:#999;'>Don't wait too long — limited stock available!</p>
                                    </div>
                                </body>
                                </html>
                                ";
                                @mail($waiter['email'], $subject, $emailBody, $headers);
                            }
                            
                            // Clean up: remove wishlist entries since the item is now available
                            $conn->prepare("DELETE FROM wishlists WHERE listing_id = ?")->execute([$listing_id]);
                        }
                    } catch (PDOException $e) {}
                }
                
                $message = "Listing updated successfully!";
                $msgType = "success";
                
                // Refresh data
                $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ? AND seller_id = ?");
                $stmt->execute([$listing_id, $seller_id]);
                $listing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $conn->prepare("SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC");
                $stmt->execute([$listing_id]);
                $currentImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
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
        <h1>Edit Listing</h1>
        <p>Update your diecast listing details</p>
    </div>
    <a href="listings.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
</div>

<div class="panel" style="max-width: 800px; margin: 0 auto;">
    <?php if (!empty($message)): ?>
        <div style="background: <?php echo $msgType === 'success' ? 'rgba(16,185,129,0.1)' : 'rgba(229,57,53,0.1)'; ?>; border: 1px solid <?php echo $msgType === 'success' ? 'rgba(16,185,129,0.2)' : 'rgba(229,57,53,0.2)'; ?>; color: <?php echo $msgType === 'success' ? 'var(--accent-green)' : 'var(--accent-red)'; ?>; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; display:flex; gap:10px; align-items:center;">
            <i class="fas <?php echo $msgType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['img_deleted'])): ?>
        <div style="background:rgba(66,165,245,0.1); border:1px solid rgba(66,165,245,0.2); color:#64b5f6; padding:12px 20px; border-radius:12px; margin-bottom:24px; display:flex; gap:10px; align-items:center;">
            <i class="fas fa-trash-alt"></i> Image removed successfully.
        </div>
    <?php endif; ?>

    <form action="edit_listing.php?id=<?php echo $listing_id; ?>" method="POST" enctype="multipart/form-data">
        
        <style>
            .seller-form-group { margin-bottom: 24px; }
            .seller-form-label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500; }
            .seller-form-control { width: 100%; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); color: var(--text-primary); padding: 12px 16px; border-radius: 10px; font-size: 0.95rem; transition: all 0.2s; font-family:var(--font-sans); }
            .seller-form-control:focus { outline: none; border-color: var(--border-hover); background: rgba(255,255,255,0.04); }
            .seller-form-control::placeholder { color: var(--text-muted); }

            /* Existing Image Grid */
            .existing-images-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                gap: 10px; margin-bottom: 16px;
            }
            .existing-thumb {
                position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden;
                border: 2px solid var(--border-color); background: var(--bg-card); transition: border-color 0.2s;
            }
            .existing-thumb:first-child { border-color: var(--accent-red); }
            .existing-thumb img { width: 100%; height: 100%; object-fit: cover; }
            .existing-thumb .et-badge {
                position: absolute; top: 4px; left: 4px; font-size: 0.55rem; font-weight: 800;
                background: var(--accent-red); color: #fff; padding: 2px 6px; border-radius: 6px;
                text-transform: uppercase;
            }
            .existing-thumb .et-delete {
                position: absolute; bottom: 0; left: 0; right: 0; background: rgba(229,57,53,0.9);
                color: #fff; border: none; font-size: 0.7rem; padding: 6px 0; cursor: pointer;
                opacity: 0; transition: opacity 0.2s; text-align: center; font-weight: 600;
            }
            .existing-thumb:hover .et-delete { opacity: 1; }

            /* Add more zone */
            .add-more-zone {
                border: 2px dashed var(--border-color); border-radius: 14px; padding: 20px;
                background: rgba(255,255,255,0.01); transition: all 0.3s; cursor: pointer;
                text-align: center; position: relative;
            }
            .add-more-zone:hover { border-color: var(--accent-red); background: rgba(229,57,53,0.03); }
            .add-more-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

            /* New image previews */
            .new-preview-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 10px; margin-top: 12px;
            }
            .new-thumb {
                position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden;
                border: 2px solid var(--accent-green); background: var(--bg-card);
            }
            .new-thumb img { width: 100%; height: 100%; object-fit: cover; }
            .new-thumb .nt-badge {
                position: absolute; top: 4px; left: 4px; font-size: 0.55rem; font-weight: 800;
                background: var(--accent-green); color: #fff; padding: 2px 6px; border-radius: 6px;
            }

            /* Grid Layouts */
            .seller-form-grid { display: grid; gap: 24px; }
            .grid-3-2-1-1 { grid-template-columns: 2fr 1fr 1fr; }
            .grid-3-1-1-1 { grid-template-columns: 1fr 1fr 1fr; }

            @media (max-width: 768px) {
                .seller-form-grid { grid-template-columns: 1fr !important; gap: 16px; }
                .seller-form-group { margin-bottom: 16px; }
            }
        </style>

        <!-- Current Images -->
        <div class="seller-form-group">
            <label class="seller-form-label">
                Current Images 
                <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">
                    (<?php echo count($currentImages); ?>/8 · hover to delete)
                </span>
            </label>

            <?php if (!empty($currentImages)): ?>
            <div class="existing-images-grid">
                <?php foreach ($currentImages as $idx => $img): ?>
                <div class="existing-thumb">
                    <img src="../<?php echo htmlspecialchars($img['image_path']); ?>" alt="Image <?php echo $idx + 1; ?>">
                    <?php if ($idx === 0): ?><span class="et-badge">Cover</span><?php endif; ?>
                    <form method="POST" action="edit_listing.php?id=<?php echo $listing_id; ?>" style="margin:0;" onsubmit="return confirm('Remove this image?');">
                        <input type="hidden" name="delete_image_id" value="<?php echo $img['id']; ?>">
                        <button type="submit" class="et-delete"><i class="fas fa-trash-alt"></i> Remove</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding:20px; text-align:center; color:var(--text-muted); font-size:0.85rem; border:1px dashed var(--border-color); border-radius:12px; margin-bottom:12px;">
                <i class="fas fa-image" style="font-size:1.5rem; opacity:0.3; margin-bottom:8px; display:block;"></i>
                No images uploaded yet
            </div>
            <?php endif; ?>

            <!-- Add More Images -->
            <?php if (count($currentImages) < 8): ?>
            <div class="add-more-zone" id="addMoreZone">
                <i class="fas fa-plus-circle" style="font-size:1.3rem; color:var(--text-muted); margin-bottom:6px;"></i>
                <div style="font-size:0.85rem; color:var(--text-secondary);">Add more images</div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:3px;">Up to <?php echo 8 - count($currentImages); ?> more · JPG, PNG, WebP</div>
                <input type="file" name="images[]" id="newImageInput" accept="image/jpeg, image/png, image/webp" multiple>
            </div>
            <div class="new-preview-grid" id="newPreviewGrid"></div>
            <?php endif; ?>
        </div>
        
        <!-- Title & Price & Stock -->
        <div class="seller-form-grid grid-3-2-1-1">
            <div class="seller-form-group">
                <label class="seller-form-label">Listing Title *</label>
                <input type="text" name="title" class="seller-form-control" placeholder="e.g. Hot Wheels '67 Camaro Treasure Hunt" required value="<?php echo htmlspecialchars($listing['title']); ?>">
            </div>
            
            <div class="seller-form-group">
                <label class="seller-form-label">Price (Rs.) *</label>
                <div style="position:relative;">
                    <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:600;">Rs.</span>
                    <input type="number" name="price" class="seller-form-control" style="padding-left:45px;" placeholder="0.00" step="0.01" min="1" required value="<?php echo htmlspecialchars($listing['price']); ?>">
                </div>
            </div>

            <div class="seller-form-group">
                <label class="seller-form-label">Stock Qty *</label>
                <div style="position:relative;">
                    <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--text-muted);"><i class="fas fa-layer-group" style="font-size:0.85rem;"></i></span>
                    <input type="number" name="stock" class="seller-form-control" style="padding-left:42px;" placeholder="1" min="0" required value="<?php echo intval($listing['stock'] ?? 1); ?>">
                </div>
            </div>
        </div>

        <!-- Category & Condition -->
        <div class="seller-form-grid grid-3-1-1-1">
            <div class="seller-form-group">
                <label class="seller-form-label">Category / Line *</label>
                <div style="position:relative;">
                    <select name="category_id" class="seller-form-control" required style="appearance: none;">
                        <option value="" disabled>Select a product line</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($cat['id'] == $listing['category_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>
            
            <div class="seller-form-group">
                <label class="seller-form-label">Size / Scale *</label>
                <div style="position:relative;">
                    <select name="scale" class="seller-form-control" required style="appearance: none;">
                        <option value="" disabled <?php echo (empty($listing['scale'])) ? 'selected' : ''; ?>>Select a Product Size/Scale</option>
                        <option value="1:12" <?php echo ($listing['scale'] === '1:12') ? 'selected' : ''; ?>>1:12 Scale</option>
                        <option value="1:18" <?php echo ($listing['scale'] === '1:18') ? 'selected' : ''; ?>>1:18 Scale</option>
                        <option value="1:24" <?php echo ($listing['scale'] === '1:24') ? 'selected' : ''; ?>>1:24 Scale</option>
                        <option value="1:32" <?php echo ($listing['scale'] === '1:32') ? 'selected' : ''; ?>>1:32 Scale</option>
                        <option value="1:36" <?php echo ($listing['scale'] === '1:36') ? 'selected' : ''; ?>>1:36 Scale</option>
                        <option value="1:43" <?php echo ($listing['scale'] === '1:43') ? 'selected' : ''; ?>>1:43 Scale</option>
                        <option value="1:64" <?php echo ($listing['scale'] === '1:64') ? 'selected' : ''; ?>>1:64 Scale (Hot Wheels size)</option>
                        <option value="1:72" <?php echo ($listing['scale'] === '1:72') ? 'selected' : ''; ?>>1:72 Scale</option>
                        <option value="1:87" <?php echo ($listing['scale'] === '1:87') ? 'selected' : ''; ?>>1:87 Scale (HO)</option>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>

            <div class="seller-form-group">
                <label class="seller-form-label">Condition *</label>
                <div style="position:relative;">
                    <select name="condition" class="seller-form-control" required style="appearance: none;">
                        <option value="new" <?php echo ($listing['condition'] === 'new') ? 'selected' : ''; ?>>New / Carded (Sealed)</option>
                        <option value="opened" <?php echo ($listing['condition'] === 'opened') ? 'selected' : ''; ?>>Opened / Mint (Loose)</option>
                        <option value="used" <?php echo ($listing['condition'] === 'used') ? 'selected' : ''; ?>>Used / Played With</option>
                    </select>
                    <i class="fas fa-chevron-down" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:0.8rem;"></i>
                </div>
            </div>
        </div>

        <!-- Status -->
        <div class="seller-form-group">
            <label class="seller-form-label">Listing Status</label>
            <div style="display:flex; gap:12px;">
                <?php if($listing['status'] !== 'sold'): ?>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border-radius:10px; border:1px solid var(--border-color); transition:all 0.2s; <?php echo ($listing['status'] === 'active') ? 'border-color:var(--accent-green); background:rgba(16,185,129,0.06);' : ''; ?>">
                    <input type="radio" name="status" value="active" <?php echo ($listing['status'] === 'active') ? 'checked' : ''; ?> style="accent-color:var(--accent-green);">
                    <i class="fas fa-circle" style="font-size:0.5rem; color:var(--accent-green);"></i>
                    <span style="font-size:0.9rem; color:var(--text-primary);">Active</span>
                </label>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 18px; border-radius:10px; border:1px solid var(--border-color); transition:all 0.2s; <?php echo ($listing['status'] === 'inactive') ? 'border-color:var(--text-muted); background:rgba(255,255,255,0.03);' : ''; ?>">
                    <input type="radio" name="status" value="inactive" <?php echo ($listing['status'] === 'inactive') ? 'checked' : ''; ?> style="accent-color:var(--text-muted);">
                    <i class="fas fa-circle" style="font-size:0.5rem; color:var(--text-muted);"></i>
                    <span style="font-size:0.9rem; color:var(--text-primary);">Inactive</span>
                </label>
                <?php else: ?>
                <div style="display:flex; align-items:center; gap:8px; padding:10px 18px; border-radius:10px; border:1px solid rgba(229,57,53,0.2); background:rgba(229,57,53,0.06);">
                    <i class="fas fa-lock" style="font-size:0.75rem; color:var(--accent-red);"></i>
                    <span style="font-size:0.9rem; color:var(--accent-red); font-weight:500;">Sold</span>
                    <span style="font-size:0.75rem; color:var(--text-muted); margin-left:4px;">— Status locked after sale</span>
                </div>
                <input type="hidden" name="status" value="sold">
                <?php endif; ?>
            </div>
        </div>

        <!-- Description -->
        <div class="seller-form-group">
            <label class="seller-form-label">Description & Comments</label>
            <textarea name="description" class="seller-form-control" rows="5" placeholder="Describe the item condition, any corner tear, protector box inclusion, etc."><?php echo htmlspecialchars($listing['description']); ?></textarea>
        </div>

        <!-- Actions -->
        <div style="margin-top: 32px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:0.8rem; color:var(--text-muted);">
                <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                Last updated: <?php echo date('M j, Y \a\t g:i A', strtotime($listing['updated_at'] ?? $listing['created_at'])); ?>
            </div>
            <div style="display:flex; gap:12px;">
                <a href="listings.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">
                    Save Changes <i class="fas fa-save" style="font-size:0.85rem;"></i>
                </button>
            </div>
        </div>

    </form>
</div>

<!-- JS -->
<script>
(function() {
    // New image preview
    const newInput = document.getElementById('newImageInput');
    const newGrid = document.getElementById('newPreviewGrid');
    if (newInput && newGrid) {
        newInput.addEventListener('change', function() {
            newGrid.innerHTML = '';
            for (let i = 0; i < this.files.length; i++) {
                const thumb = document.createElement('div');
                thumb.className = 'new-thumb';
                const img = document.createElement('img');
                const reader = new FileReader();
                reader.onload = (e) => { img.src = e.target.result; };
                reader.readAsDataURL(this.files[i]);
                thumb.appendChild(img);
                const badge = document.createElement('span');
                badge.className = 'nt-badge';
                badge.textContent = 'New';
                thumb.appendChild(badge);
                newGrid.appendChild(thumb);
            }
        });
    }

    // Status radio visual toggle
    document.querySelectorAll('input[name="status"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('input[name="status"]').forEach(r => {
                r.closest('label').style.borderColor = 'var(--border-color)';
                r.closest('label').style.background = 'transparent';
            });
            if (this.value === 'active') {
                this.closest('label').style.borderColor = 'var(--accent-green)';
                this.closest('label').style.background = 'rgba(16,185,129,0.06)';
            } else {
                this.closest('label').style.borderColor = 'var(--text-muted)';
                this.closest('label').style.background = 'rgba(255,255,255,0.03)';
            }
        });
    });
})();
</script>

<?php include 'footer.php'; ?>
