<?php
session_start();
require_once 'config/db.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: browse.php');
    exit;
}

// Handle Add to Cart (must be before any output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
    
    // Migrate old format (simple array of IDs) to new format (id => qty)
    $migratedCart = [];
    foreach ($_SESSION['cart'] as $k => $v) {
        if (is_int($k) && is_int($v)) {
            // Old format: numeric index => listing_id
            $migratedCart[$v] = 1;
        } else {
            // New format: listing_id => qty
            $migratedCart[$k] = $v;
        }
    }
    $_SESSION['cart'] = $migratedCart;
    
    $selectedQty = max(1, intval($_POST['quantity'] ?? 1));
    $_SESSION['cart'][$id] = $selectedQty;
    
    if (isset($_POST['buy_now'])) {
        header("Location: cart.php");
        exit;
    } else {
        header("Location: listing.php?id=$id&added=1");
        exit;
    }
}

include 'includes/header.php';

// Fetch listing
try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name, c.slug AS category_slug, 
               u.name AS seller_name, u.id AS seller_uid, u.avatar AS seller_avatar, u.store_location, u.is_verified, u.created_at AS seller_joined,
               u.avg_rating, u.review_count
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN users u ON l.seller_id = u.id
        WHERE l.id = ?
    ");
    $stmt->execute([$id]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $listing = null;
}

if (!$listing) {
    echo '<div class="container-rl" style="padding: 120px 0; text-align: center;"><h2>Listing not found</h2><p><a href="browse.php">Browse listings</a></p></div>';
    include 'includes/footer.php';
    exit;
}

// Increment views
try {
    $conn->prepare("UPDATE listings SET views = views + 1 WHERE id = ?")->execute([$id]);
} catch (PDOException $e) {}

// Fetch all images for this listing
$listingImages = [];
try {
    $stmt = $conn->prepare("SELECT image_path FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $listingImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
// Fallback to single image if listing_images table is empty
if (empty($listingImages) && !empty($listing['image'])) {
    $listingImages = [$listing['image']];
}

// Fetch related listings (including sold-out ones)
$related = [];
try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name, u.name AS seller_name 
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN users u ON l.seller_id = u.id
        WHERE l.category_id = ? AND l.id != ? AND l.status IN ('active','sold')
        ORDER BY RAND() LIMIT 4
    ");
    $stmt->execute([$listing['category_id'], $id]);
    $related = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Pre-fetch user's wishlisted listing IDs
$userWishlist = [];
if (isset($_SESSION['user_id'])) {
    try {
        $wlStmt = $conn->prepare("SELECT listing_id FROM wishlists WHERE user_id = ?");
        $wlStmt->execute([$_SESSION['user_id']]);
        $userWishlist = $wlStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

// Fetch Seller Stats specifically for the trust card
$sellerStats = ['active' => 0, 'sold' => 0];
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'active'");
    $stmt->execute([$listing['seller_uid']]);
    $sellerStats['active'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'sold'");
    $stmt->execute([$listing['seller_uid']]);
    $sellerStats['sold'] = $stmt->fetchColumn();
} catch (PDOException $e) {}

// Fetch recent reviews for the seller to show on the listing page
$sellerReviews = [];
try {
    $stmt = $conn->prepare("
        SELECT sr.*, u.name as buyer_name, u.avatar as buyer_avatar
        FROM seller_reviews sr
        JOIN users u ON sr.buyer_id = u.id
        WHERE sr.seller_id = ?
        ORDER BY sr.created_at DESC LIMIT 4
    ");
    $stmt->execute([$listing['seller_uid']]);
    $sellerReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$conditionLabels = ['new' => 'New / Sealed', 'opened' => 'Opened / Mint', 'used' => 'Used'];
$cartAdded = isset($_GET['added']);
$inCart = isset($_SESSION['cart']) && array_key_exists($id, $_SESSION['cart']);
$listingStock = max(0, intval($listing['stock'] ?? 1));
?>

<link rel="stylesheet" href="assets/css/browse.css?v=<?php echo time(); ?>"> <!-- For related grid -->
<link rel="stylesheet" href="assets/css/listing.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="assets/css/chat.css?v=<?php echo time(); ?>">

<div class="ecom-listing-page container-rl">
    <?php if ($cartAdded): ?>
        <div class="ecom-alert-success" data-aos="fade-down">
            <i class="fas fa-check-circle"></i> Item successfully added to your cart! 
            <a href="cart.php" class="ecom-alert-link">View Cart & Checkout &rarr;</a>
        </div>
    <?php endif; ?>

    <!-- Breadcrumbs -->
    <nav style="display:flex; gap:8px; font-size:0.75rem; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:var(--text-muted); margin-bottom:32px; align-items:center;">
        <a href="browse.php" style="color:var(--text-muted); text-decoration:none;">Marketplace</a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
        <a href="browse.php?category=<?php echo htmlspecialchars($listing['category_slug'] ?? ''); ?>" style="color:var(--text-muted); text-decoration:none;"><?php echo htmlspecialchars($listing['category_name'] ?? 'Category'); ?></a>
        <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
        <span style="color:var(--text-primary);"><?php echo htmlspecialchars($listing['title']); ?></span>
    </nav>

    <!-- Main Dual Column Layout -->
    <div class="ecom-main">
        
        <!-- Left Column: Visuals & Details -->
        <div class="ecom-gallery-col" data-aos="fade-up">
            <!-- Showcase Image Carousel -->
            <div class="ecom-image-box" id="listingCarousel">
                <?php if (!empty($listingImages)): ?>
                    <div class="carousel-main">
                        <?php foreach ($listingImages as $idx => $imgPath): ?>
                        <div class="carousel-slide <?php echo $idx === 0 ? 'active' : ''; ?>" data-index="<?php echo $idx; ?>">
                            <img src="<?php echo htmlspecialchars($imgPath); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?> — Image <?php echo $idx + 1; ?>" loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>">
                        </div>
                        <?php endforeach; ?>

                        <?php if (count($listingImages) > 1): ?>
                        <button class="carousel-arrow carousel-prev" type="button" onclick="carouselNav(-1)" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
                        <button class="carousel-arrow carousel-next" type="button" onclick="carouselNav(1)" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
                        <span class="carousel-counter"><span id="carouselCurrent">1</span> / <?php echo count($listingImages); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (count($listingImages) > 1): ?>
                    <div class="carousel-thumbs">
                        <?php foreach ($listingImages as $idx => $imgPath): ?>
                        <div class="carousel-thumb <?php echo $idx === 0 ? 'active' : ''; ?>" onclick="carouselGoTo(<?php echo $idx; ?>)">
                            <img src="<?php echo htmlspecialchars($imgPath); ?>" alt="Thumb <?php echo $idx + 1; ?>" loading="lazy">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="carousel-main" style="background:var(--bg-elevated); display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-car-side" style="font-size:4rem; color:var(--text-muted);"></i>
                    </div>
                <?php endif; ?>
                
                <!-- Condition Overlay -->
                <?php if($listing['condition'] === 'new'): ?>
                    <span class="ecom-cond-badge badge-new" style="background:var(--tertiary, #008097); color:#fff;">MINT CONDITION</span>
                <?php elseif($listing['condition'] === 'opened'): ?>
                    <span class="ecom-cond-badge badge-mint" style="background:#546067; color:#fff;">OPENED MINT</span>
                <?php else: ?>
                    <span class="ecom-cond-badge badge-used" style="background:#5b403d; color:#fff;">USED</span>
                <?php endif; ?>
            </div>
            
            <!-- Description Box (Grid Style) -->
            <div class="ecom-desc-box" data-aos="fade-up" data-aos-delay="50">
                <h2 style="font-family:'Manrope',sans-serif; font-size:1.8rem; font-weight:800; margin-bottom:24px; color:var(--text-primary);">Product Details</h2>
                
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:32px 24px; margin-bottom:32px;">
                    <div>
                        <p style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Scale</p>
                        <p style="color:var(--text-primary); font-weight:600; margin:0;"><?php echo htmlspecialchars($listing['scale'] ?? 'Not Specified'); ?></p>
                    </div>
                    <div>
                        <p style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Manufacturer</p>
                        <p style="color:var(--text-primary); font-weight:600; margin:0;"><?php echo htmlspecialchars($listing['manufacturer'] ?? 'See Description'); ?></p>
                    </div>
                    <div>
                        <p style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Category</p>
                        <p style="color:var(--text-primary); font-weight:600; margin:0;"><?php echo htmlspecialchars($listing['category_name'] ?? 'Diecast'); ?></p>
                    </div>
                    <div>
                        <p style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:4px;">Condition</p>
                        <p style="color:var(--text-primary); font-weight:600; margin:0;"><?php echo htmlspecialchars($conditionLabels[$listing['condition']] ?? 'Used'); ?></p>
                    </div>
                </div>

                <div class="ecom-desc-content" style="color:var(--text-secondary); line-height:1.8; max-width:800px;">
                    <?php if (!empty($listing['description'])): ?>
                        <?php echo nl2br(htmlspecialchars($listing['description'])); ?>
                    <?php else: ?>
                        <p style="font-style:italic;">No description provided by the seller.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Sticky Purchase Sidebar -->
        <div class="ecom-sidebar-col" data-aos="fade-up" data-aos-delay="100">
            
            <!-- Core Purchase Card Header -->
            <div style="margin-bottom:24px;">
                <h1 style="font-family:'Manrope',sans-serif; font-size:2.5rem; font-weight:800; line-height:1.1; margin-bottom:8px; color:var(--text-primary);"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <p style="color:var(--text-muted); font-weight:500; font-size:1.1rem; margin:0;"><?php echo htmlspecialchars($listing['category_name'] ?? 'Diecast'); ?> Model by <?php echo htmlspecialchars($listing['seller_name'] ?? 'Seller'); ?></p>
            </div>

            <!-- Price Block -->
            <div style="display:flex; align-items:baseline; gap:16px; margin-bottom:24px;">
                <span style="font-size:2.5rem; font-weight:800; color:var(--accent-red); letter-spacing:-0.05em;">Rs.<?php echo number_format($listing['price'], 0); ?></span>
                <!-- Optional Subtitle/Discount visual -->
            </div>

            <!-- The Curator Box (Purchase Card) -->
            <div class="ecom-curator-box">
                <!-- Action Buttons -->
                <div class="epc-actions" style="margin-top:0;">
                    <?php if ($listing['status'] === 'active' && $listingStock > 0): ?>
                        <?php
                        // Check if logged-in user is the seller of this listing
                        $isOwnListing = isset($_SESSION['user_id']) && $_SESSION['user_id'] == $listing['seller_uid'];
                        ?>
                        <?php if ($isOwnListing): ?>
                            <!-- Seller viewing their own listing -->
                            <div class="epc-stock-info" style="margin-bottom: 16px; <?php echo $listingStock <= 5 ? 'background: rgba(251, 191, 36, 0.1); border-color: rgba(251, 191, 36, 0.3); color: #fbbf24;' : ''; ?>">
                                <?php if ($listingStock <= 5): ?>
                                    <i class="fas fa-clock"></i> LOW STOCK: Only <?php echo $listingStock; ?> unit<?php echo $listingStock > 1 ? 's' : ''; ?> left!
                                <?php else: ?>
                                    <i class="fas fa-check-circle"></i> In Stock (<?php echo $listingStock; ?> units available)
                                <?php endif; ?>
                            </div>
                            <div style="padding:16px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:12px; text-align:center;">
                                <p style="color:var(--text-muted); font-size:0.9rem; margin:0 0 12px;"><i class="fas fa-info-circle"></i> This is your listing</p>
                                <a href="seller_dashboard/edit_listing.php?id=<?php echo $id; ?>" class="btn-curator-secondary hover-scale" style="text-align:center; display:block;">
                                    <i class="fas fa-pen"></i> Edit Listing
                                </a>
                            </div>
                        <?php elseif ($inCart): ?>
                            <a href="cart.php" class="btn-curator-secondary hover-scale" style="text-align:center;"><i class="fas fa-shopping-cart"></i> Proceed to Checkout</a>
                        <?php else: ?>
                            <form method="POST" style="display:flex; flex-direction:column; gap:16px;">
                                <input type="hidden" name="add_to_cart" value="1">
                                
                                <!-- Stock Availability Badge -->
                                <div class="epc-stock-info" style="margin-bottom: 16px; <?php echo $listingStock <= 5 ? 'background: rgba(251, 191, 36, 0.1); border-color: rgba(251, 191, 36, 0.3); color: #fbbf24;' : ''; ?>">
                                    <?php if ($listingStock <= 5): ?>
                                        <i class="fas fa-clock"></i> LOW STOCK: Only <?php echo $listingStock; ?> unit<?php echo $listingStock > 1 ? 's' : ''; ?> left!
                                    <?php else: ?>
                                        <i class="fas fa-check-circle"></i> In Stock (<?php echo $listingStock; ?> units available)
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Volume Button Quantity -->
                                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:var(--bg-elevated); border-radius:12px;">
                                    <label for="qtyInput" style="font-weight:700; color:var(--text-secondary); font-size:0.9rem;">Quantity</label>
                                    <div style="display:flex; align-items:center; background:var(--bg-card); border:1px solid rgba(255,255,255,0.1); border-radius:24px; overflow:hidden; padding:2px;">
                                        <button type="button" onclick="updateQty(-1)" style="background:transparent; border:none; color:var(--text-primary); width:32px; height:32px; display:flex; justify-content:center; align-items:center; cursor:pointer; outline:none; border-radius:50%; transition:0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'"><i class="fas fa-minus" style="font-size:0.75rem;"></i></button>
                                        <input type="number" name="quantity" id="qtyInput" value="1" min="1" max="<?php echo $listingStock; ?>" readonly style="width:40px; text-align:center; background:transparent; border:none; color:var(--text-primary); font-weight:700; font-size:0.95rem; outline:none; -moz-appearance:textfield;" onkeydown="return false;">
                                        <button type="button" onclick="updateQty(1)" style="background:transparent; border:none; color:var(--text-primary); width:32px; height:32px; display:flex; justify-content:center; align-items:center; cursor:pointer; outline:none; border-radius:50%; transition:0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'"><i class="fas fa-plus" style="font-size:0.75rem;"></i></button>
                                    </div>
                                </div>
                                
                                <!-- Primary Action -->
                                <button type="submit" class="btn-curator-primary hover-scale">
                                    <i class="fas fa-shopping-bag" style="font-size:1.2rem;"></i>
                                    ADD TO CURATOR BOX
                                </button>
                                
                                <!-- Secondary Action -->
                                <div style="display:grid; grid-template-columns:1fr; gap:12px;">
                                    <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $listing['seller_uid'] && !empty($listing['negotiable'])): ?>
                                        <a href="negotiate.php?listing_id=<?php echo $id; ?>" class="btn-curator-secondary hover-scale" style="text-align:center;">Make an Offer</a>
                                    <?php elseif (!isset($_SESSION['user_id'])  && !empty($listing['negotiable'])): ?>
                                        <a href="login.php" class="btn-curator-secondary hover-scale" style="text-align:center;">Make an Offer</a>
                                    <?php else: ?>
                                        <button type="submit" name="buy_now" value="1" class="btn-curator-secondary hover-scale">Buy it Now</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="btn-ecom-disabled">SOLD OUT</div>
                        <?php
                        // Check if user has this in their wishlist
                        $isWishlisted = false;
                        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $listing['seller_uid']) {
                            try {
                                $wlStmt = $conn->prepare("SELECT id FROM wishlists WHERE user_id = ? AND listing_id = ?");
                                $wlStmt->execute([$_SESSION['user_id'], $id]);
                                $isWishlisted = (bool)$wlStmt->fetchColumn();
                            } catch (PDOException $e) {}
                        }
                        ?>
                        <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $listing['seller_uid']): ?>
                        <button id="wishlistBtn" onclick="toggleWishlist(<?php echo $id; ?>)" style="
                            width:100%; margin-top:12px; padding:14px 20px;
                            background:<?php echo $isWishlisted ? 'rgba(229,57,53,0.12)' : 'rgba(255,255,255,0.04)'; ?>;
                            color:<?php echo $isWishlisted ? '#e53935' : 'var(--text-secondary)'; ?>;
                            border:1px solid <?php echo $isWishlisted ? 'rgba(229,57,53,0.3)' : 'rgba(255,255,255,0.08)'; ?>;
                            border-radius:12px; font-size:0.9rem; font-weight:700;
                            cursor:pointer; font-family:inherit; display:flex;
                            align-items:center; justify-content:center; gap:10px;
                            transition:all 0.25s;
                        ">
                            <i class="<?php echo $isWishlisted ? 'fas' : 'far'; ?> fa-heart" style="font-size:1.1rem;"></i>
                            <span><?php echo $isWishlisted ? 'Saved to Wishlist' : 'Add to Wishlist — Notify Me'; ?></span>
                        </button>
                        <script>
                        function toggleWishlist(listingId) {
                            const btn = document.getElementById('wishlistBtn');
                            btn.style.opacity = '0.6';
                            btn.style.pointerEvents = 'none';
                            const fd = new FormData();
                            fd.append('action', 'toggle');
                            fd.append('listing_id', listingId);
                            fetch('api/wishlist.php', { method: 'POST', body: fd })
                                .then(r => r.json())
                                .then(data => {
                                    btn.style.opacity = '1';
                                    btn.style.pointerEvents = 'auto';
                                    if (data.success) {
                                        if (data.wishlisted) {
                                            btn.style.background = 'rgba(229,57,53,0.12)';
                                            btn.style.color = '#e53935';
                                            btn.style.borderColor = 'rgba(229,57,53,0.3)';
                                            btn.innerHTML = '<i class="fas fa-heart" style="font-size:1.1rem;"></i> <span>Saved to Wishlist</span>';
                                        } else {
                                            btn.style.background = 'rgba(255,255,255,0.04)';
                                            btn.style.color = 'var(--text-secondary)';
                                            btn.style.borderColor = 'rgba(255,255,255,0.08)';
                                            btn.innerHTML = '<i class="far fa-heart" style="font-size:1.1rem;"></i> <span>Add to Wishlist — Notify Me</span>';
                                        }
                                    } else {
                                        alert(data.message || 'Error');
                                    }
                                })
                                .catch(() => {
                                    btn.style.opacity = '1';
                                    btn.style.pointerEvents = 'auto';
                                    alert('Network error');
                                });
                        }
                        </script>
                        <?php elseif (!isset($_SESSION['user_id'])): ?>
                        <a href="login.php" style="
                            display:flex; width:100%; margin-top:12px; padding:14px 20px;
                            background:rgba(255,255,255,0.04); color:var(--text-secondary);
                            border:1px solid rgba(255,255,255,0.08); border-radius:12px;
                            font-size:0.9rem; font-weight:700; text-decoration:none;
                            align-items:center; justify-content:center; gap:10px;
                            transition:all 0.25s; box-sizing:border-box;
                        ">
                            <i class="far fa-heart" style="font-size:1.1rem;"></i>
                            <span>Login to add to Wishlist</span>
                        </a>
                        <?php endif; ?>
                <?php endif; ?>
                </div>
                
                <!-- Trust Signals Block -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:24px;">
                    <div style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:var(--bg-elevated); border-radius:12px;">
                        <i class="fas fa-shield-alt" style="color:var(--accent-red); font-size:1.2rem;"></i>
                        <div>
                            <p style="font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-primary); margin-bottom:2px;">Authentic</p>
                            <p style="font-size:0.65rem; color:var(--text-muted); margin:0;">REDLINE Guarantee</p>
                        </div>
                    </div>
                    <div style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:var(--bg-elevated); border-radius:12px;">
                        <i class="fas fa-truck" style="color:var(--accent-red); font-size:1.2rem;"></i>
                        <div>
                            <p style="font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-primary); margin-bottom:2px;">Insured</p>
                            <p style="font-size:0.65rem; color:var(--text-muted); margin:0;">Secure Delivery</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Floating Seller Info -->
            <div style="margin-top:24px; display:flex; flex-direction:column; gap:16px;">
                <div style="display:flex; align-items:center; gap:16px; cursor:pointer;" onclick="window.location.href='seller.php?id=<?php echo $listing['seller_uid']; ?>'">
                    <div style="width:48px; height:48px; border-radius:50%; background:var(--bg-card); display:flex; align-items:center; justify-content:center; overflow:hidden; border:2px solid var(--border-color);">
                        <?php if($listing['seller_avatar']): ?>
                            <img src="<?php echo htmlspecialchars($listing['seller_avatar']); ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <span style="font-weight:700; color:var(--text-muted);"><?php echo strtoupper(substr($listing['seller_name'] ?? 'S', 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <p style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:2px;">Seller
                        <?php if(!empty($listing['store_location'])): ?>
                            &bull; <i class="fas fa-map-marker-alt" style="margin-left:4px;"></i> <?php echo htmlspecialchars($listing['store_location']); ?>
                        <?php endif; ?>
                        </p>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:700; color:var(--text-primary);"><?php echo htmlspecialchars($listing['seller_name']); ?></span>
                            <?php if($listing['is_verified']): ?>
                                <i class="fas fa-check-circle" style="color:var(--tertiary, #008097); font-size:0.85rem;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span style="color:var(--accent-red); font-size:0.75rem; font-weight:700; text-transform:uppercase; text-decoration:underline;">Visit</span>
                </div>
                
                <div style="padding:16px; background:var(--bg-elevated); border-radius:12px; display:flex; align-items:center; justify-content:space-between;">
                    <div style="display:flex; gap:24px;">
                        <div style="text-align:center;">
                            <p style="font-weight:700; color:var(--text-primary); margin:0;"><?php echo number_format($sellerStats['sold']); ?></p>
                            <p style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase; margin:0;">Sales</p>
                        </div>
                        <div style="width:1px; background:var(--border-color);"></div>
                        <div style="text-align:center;">
                            <p style="font-weight:700; color:var(--text-primary); margin:0;"><?php echo $sellerStats['active']; ?></p>
                            <p style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase; margin:0;">Active</p>
                        </div>
                    </div>
                    <button type="button" class="btn-share-listing" id="btnShareListing" onclick="shareListingLink()" style="width:auto; padding:8px 16px; background:var(--text-primary); color:var(--bg-surface); border-radius:20px; font-weight:700; text-transform:uppercase; font-size:0.75rem; border:none;">
                        <i class="fas fa-share-alt"></i> Share
                    </button>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Seller Customer Reviews Section -->
    <div class="ecom-related-section" data-aos="fade-up" style="margin-top:40px; margin-bottom:20px;">
        <h2 style="font-size:1.35rem; color:var(--text-primary); margin-bottom:20px; font-weight:700; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-star" style="color:#fbbf24;"></i> Customer Reviews
        </h2>
        
        <?php if (!empty($sellerReviews)): ?>
            <div style="background:var(--bg-surface); padding:30px; border-radius:16px; border:1px solid var(--border-color); box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                    <?php foreach($sellerReviews as $rev): ?>
                    <div style="padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.05); background:rgba(255,255,255,0.02);">
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                            <div style="width:40px; height:40px; border-radius:50%; background:var(--bg-card); display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                <?php if($rev['buyer_avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($rev['buyer_avatar']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?>
                                    <i class="fas fa-user" style="color:var(--text-muted);"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div style="font-weight:600; font-size:0.9rem; color:var(--text-primary);"><?php echo htmlspecialchars($rev['buyer_name']); ?></div>
                                <div style="color:#fbbf24; font-size:0.8rem; margin-top:2px;">
                                    <?php for($i=1; $i<=5; $i++) echo $i <= $rev['rating'] ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'; ?>
                                    <span style="color:var(--text-muted); margin-left:6px;"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if(!empty($rev['review_text'])): ?>
                            <p style="font-size:0.9rem; color:var(--text-secondary); line-height:1.5; margin:0;">"<?php echo nl2br(htmlspecialchars($rev['review_text'])); ?>"</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align:center; margin-top:20px;">
                    <a href="seller.php?id=<?php echo $listing['seller_uid']; ?>" class="btn-ecom-outline" style="font-size:0.85rem; padding:8px 20px;">View all seller reviews</a>
                </div>
            </div>
        <?php else: ?>
            <div style="background:var(--bg-surface); padding:60px 20px; border-radius:16px; border:1px solid var(--border-color); text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                <div style="width:54px; height:54px; background:rgba(255,255,255,0.04); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                    <i class="far fa-comment-dots" style="font-size:1.6rem; color:#d97757;"></i>
                </div>
                <h3 style="font-size:1.15rem; color:var(--text-primary); margin-bottom:8px; font-weight:700;">No Reviews Yet</h3>
                <p style="color:var(--text-muted); font-size:0.9rem; max-width:300px; margin:0 auto;">Be the first to share your experience with this product!</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Related Listings Grid -->
    <?php if (!empty($related)): ?>
    <div class="ecom-related-section" data-aos="fade-up">
        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:48px;">
            <div>
                <p style="font-size:0.75rem; font-weight:700; color:var(--accent-red); text-transform:uppercase; letter-spacing:0.2em; margin-bottom:8px;">The Collection</p>
                <h2 style="font-family:'Manrope',sans-serif; font-size:2rem; font-weight:800; letter-spacing:-0.02em; color:var(--text-primary); margin:0;">More from <?php echo htmlspecialchars($listing['category_name'] ?? 'This Line'); ?></h2>
            </div>
            <a href="browse.php?category=<?php echo htmlspecialchars($listing['category_slug'] ?? ''); ?>" style="color:var(--text-primary); font-weight:700; border-bottom:2px solid var(--text-primary); padding-bottom:4px; text-decoration:none; transition:all 0.2s;">View Full Gallery</a>
        </div>
        <div class="listings-grid">
            <?php foreach($related as $r):
                $rSoldOut = ($r['status'] === 'sold' || intval($r['stock'] ?? 1) <= 0);
                $rWishlisted = in_array($r['id'], $userWishlist);
            ?>
            <a href="listing.php?id=<?php echo $r['id']; ?>" class="listing-card <?php echo $rSoldOut ? 'listing-card--soldout' : ''; ?>">
                <div class="listing-img">
                    <?php if(!empty($r['image'])): ?>
                        <img src="<?php echo htmlspecialchars($r['image']); ?>" alt="<?php echo htmlspecialchars($r['title']); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="no-img"><i class="fas fa-car-side"></i></div>
                    <?php endif; ?>

                    <?php if($rSoldOut): ?>
                        <div class="soldout-overlay">
                            <span class="soldout-badge">SOLD OUT</span>
                        </div>
                        <button type="button" class="card-wishlist-btn <?php echo $rWishlisted ? 'wishlisted' : ''; ?>" data-listing-id="<?php echo $r['id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); toggleCardWishlist(this, <?php echo $r['id']; ?>)" title="<?php echo $rWishlisted ? 'Saved to Wishlist' : 'Add to Wishlist'; ?>">
                            <i class="<?php echo $rWishlisted ? 'fas' : 'far'; ?> fa-heart"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="listing-body">
                    <span class="listing-category"><?php echo htmlspecialchars($r['category_name'] ?? 'Diecast'); ?></span>
                    <h4 class="listing-title"><?php echo htmlspecialchars($r['title']); ?></h4>
                    <p class="listing-price"><?php echo $rSoldOut ? '<span class="price-soldout">SOLD OUT</span>' : 'Rs.' . number_format($r['price'], 0); ?></p>
                    <div class="listing-seller"><?php echo htmlspecialchars($r['seller_name'] ?? 'Seller'); ?> <span class="seller-dot"></span></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<style>
/* Quantity Selector */
input[type=number]::-webkit-inner-spin-button, 
input[type=number]::-webkit-outer-spin-button { 
  -webkit-appearance: none; 
  margin: 0; 
}
.epc-stock-info {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
    padding: 8px 14px;
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 10px;
    font-size: 0.82rem;
    color: #34d399;
    font-weight: 600;
}
.epc-stock-info i {
    font-size: 0.8rem;
    opacity: 0.8;
}
.epc-qty-selector {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    transition: border-color 0.2s;
}
.epc-qty-selector:hover {
    border-color: rgba(255,255,255,0.2);
}
.epc-qty-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
    user-select: none;
}
.epc-qty-dropdown-wrap {
    position: relative;
    flex: 1;
}
.epc-qty-dropdown {
    width: 100%;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    padding: 10px 40px 10px 16px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    color: var(--text-primary, #fff);
    font-size: 0.95rem;
    font-weight: 700;
    font-family: var(--font-sans, 'Inter', sans-serif);
    cursor: pointer;
    outline: none;
    transition: all 0.2s ease;
}
.epc-qty-dropdown:hover {
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.25);
}
.epc-qty-dropdown:focus {
    border-color: var(--accent-red, #e53935);
    box-shadow: 0 0 0 3px rgba(229,57,53,0.15);
}
.epc-qty-dropdown option {
    background: #1a1a2e;
    color: #fff;
    padding: 8px;
}
.epc-qty-arrow {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.7rem;
    color: var(--text-muted);
    pointer-events: none;
    transition: transform 0.2s;
}
.epc-qty-dropdown:focus + .epc-qty-arrow {
    color: var(--accent-red, #e53935);
    transform: translateY(-50%) rotate(180deg);
}

/* Share Button */
.epc-share-row {
    position: relative;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex;
    align-items: center;
    gap: 12px;
}
.btn-share-listing {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    justify-content: center;
    padding: 11px 20px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    color: var(--text-secondary);
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.25s ease;
    font-family: var(--font-sans, 'Inter', sans-serif);
}
.btn-share-listing:hover {
    background: rgba(255,255,255,0.08);
    color: var(--text-primary, #fff);
    border-color: rgba(255,255,255,0.2);
    transform: translateY(-1px);
}
.btn-share-listing:active {
    transform: translateY(0);
}
.btn-share-listing.copied {
    background: rgba(16,185,129,0.12);
    border-color: rgba(16,185,129,0.3);
    color: #10b981;
}
.share-copied-tooltip {
    position: absolute;
    top: -32px;
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: #10b981;
    color: #fff;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 5px 14px;
    border-radius: 8px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}
.share-copied-tooltip::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 50%;
    transform: translateX(-50%);
    width: 0;
    height: 0;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-top: 6px solid #10b981;
}
.share-copied-tooltip.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
</style>

<script>
function shareListingLink() {
    var url = window.location.origin + window.location.pathname + '?id=<?php echo $id; ?>';
    var btn = document.getElementById('btnShareListing');
    var tooltip = document.getElementById('shareCopiedTooltip');

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            showCopiedFeedback(btn, tooltip);
        }).catch(function() {
            fallbackCopy(url, btn, tooltip);
        });
    } else {
        fallbackCopy(url, btn, tooltip);
    }
}

function fallbackCopy(text, btn, tooltip) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        showCopiedFeedback(btn, tooltip);
    } catch (e) {
        alert('Copy this link:\n' + text);
    }
    document.body.removeChild(ta);
}

function showCopiedFeedback(btn, tooltip) {
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fas fa-check"></i> Link Copied!';
    tooltip.classList.add('show');

    setTimeout(function() {
        tooltip.classList.remove('show');
    }, 2000);

    setTimeout(function() {
        btn.classList.remove('copied');
        btn.innerHTML = '<i class="fas fa-share-alt"></i> Share Listing';
    }, 2500);
}

function updateQty(change) {
    const input = document.getElementById('qtyInput');
    if (!input) return;
    let currentVal = parseInt(input.value);
    const max = parseInt(input.getAttribute('max'));
    
    let newVal = currentVal + change;
    if (newVal >= 1 && newVal <= max) {
        input.value = newVal;
    }
}

// === CAROUSEL JS ===
document.addEventListener('DOMContentLoaded', function() {
    const slides = document.querySelectorAll('.carousel-slide');
    if(slides.length <= 1) return; // No carousel needed

    const thumbs = document.querySelectorAll('.carousel-thumb');
    const dots = document.querySelectorAll('.carousel-dot');
    const currentCounter = document.getElementById('carouselCurrent');
    const carouselBox = document.getElementById('listingCarousel');
    
    let currentIndex = 0;
    let autoScrollInterval;
    const intervalTime = 4000; // 4 seconds

    function goToSlide(index) {
        if(index < 0) index = slides.length - 1;
        if(index >= slides.length) index = 0;

        // Remove active class
        slides[currentIndex].classList.remove('active');
        if(thumbs.length > currentIndex) thumbs[currentIndex].classList.remove('active');
        if(dots.length > currentIndex) dots[currentIndex].classList.remove('active');

        currentIndex = index;

        // Add active class
        slides[currentIndex].classList.add('active');
        if(thumbs.length > currentIndex) {
            thumbs[currentIndex].classList.add('active');
            // Scroll thumb into view smoothly
            thumbs[currentIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
        if(dots.length > currentIndex) dots[currentIndex].classList.add('active');
        if(currentCounter) currentCounter.textContent = currentIndex + 1;
    }

    window.carouselNav = function(step) {
        goToSlide(currentIndex + step);
        resetAutoScroll();
    };

    window.carouselGoTo = function(index) {
        goToSlide(index);
        resetAutoScroll();
    };

    function startAutoScroll() {
        autoScrollInterval = setInterval(() => {
            goToSlide(currentIndex + 1);
        }, intervalTime);
    }

    function resetAutoScroll() {
        clearInterval(autoScrollInterval);
        startAutoScroll();
    }

    // Pause on hover
    if(carouselBox) {
        carouselBox.addEventListener('mouseenter', () => clearInterval(autoScrollInterval));
        carouselBox.addEventListener('mouseleave', startAutoScroll);
    }

    // Start initial scroll
    startAutoScroll();
});

</script>

<script>
// Wishlist toggle from related listing cards
function toggleCardWishlist(btn, listingId) {
    <?php if (!isset($_SESSION['user_id'])): ?>
    window.location.href = 'login.php';
    return;
    <?php endif; ?>

    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.5';

    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('listing_id', listingId);

    fetch('api/wishlist.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
            if (data.success) {
                const icon = btn.querySelector('i');
                if (data.wishlisted) {
                    btn.classList.add('wishlisted');
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                    btn.title = 'Saved to Wishlist';
                } else {
                    btn.classList.remove('wishlisted');
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                    btn.title = 'Add to Wishlist';
                }
            }
        })
        .catch(() => {
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
        });
}
</script>

<?php include 'includes/footer.php'; ?>
