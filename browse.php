<?php
session_start();
require_once 'config/db.php';
include 'includes/header.php';

// Pre-fetch user's wishlisted listing IDs
$userWishlist = [];
if (isset($_SESSION['user_id'])) {
    try {
        $wlStmt = $conn->prepare("SELECT listing_id FROM wishlists WHERE user_id = ?");
        $wlStmt->execute([$_SESSION['user_id']]);
        $userWishlist = $wlStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

// Get filters
$search = trim($_GET['search'] ?? '');
$categories_filter = isset($_GET['category']) ? (is_array($_GET['category']) ? $_GET['category'] : [$_GET['category']]) : [];
$scales_filter = isset($_GET['scale']) ? (is_array($_GET['scale']) ? $_GET['scale'] : [$_GET['scale']]) : [];
$conditions_filter = isset($_GET['condition']) ? (is_array($_GET['condition']) ? $_GET['condition'] : [$_GET['condition']]) : [];
$prices_filter = isset($_GET['price_range']) ? (is_array($_GET['price_range']) ? $_GET['price_range'] : [$_GET['price_range']]) : [];
$in_stock = isset($_GET['in_stock']) ? 1 : 0;
$sort = $_GET['sort'] ?? 'newest';

// Build query
$where = ["l.status IN ('active','sold')"];
$params = [];

if ($search) {
    $where[] = "l.title LIKE ?";
    $params[] = "%$search%";
}

if (!empty($categories_filter)) {
    $placeholders = implode(',', array_fill(0, count($categories_filter), '?'));
    $where[] = "c.slug IN ($placeholders)";
    foreach ($categories_filter as $c) $params[] = $c;
}

if (!empty($scales_filter)) {
    $placeholders = implode(',', array_fill(0, count($scales_filter), '?'));
    $where[] = "l.scale IN ($placeholders)";
    foreach ($scales_filter as $s) $params[] = $s;
}

if (!empty($conditions_filter)) {
    $placeholders = implode(',', array_fill(0, count($conditions_filter), '?'));
    $where[] = "l.`condition` IN ($placeholders)";
    foreach ($conditions_filter as $c) $params[] = $c;
}

if (!empty($prices_filter)) {
    $priceRangesMap = [
        '0-200'     => [0, 200],
        '200-500'   => [200, 500],
        '500-1000'  => [500, 1000],
        '1000-2000' => [1000, 2000],
        '2000-5000' => [2000, 5000],
        '5000+'     => [5000, null],
    ];
    $orConds = [];
    foreach ($prices_filter as $pr) {
        if (isset($priceRangesMap[$pr])) {
            [$minP, $maxP] = $priceRangesMap[$pr];
            if ($maxP !== null) {
                $orConds[] = "(l.price >= ? AND l.price <= ?)";
                $params[] = $minP;
                $params[] = $maxP;
            } else {
                $orConds[] = "(l.price >= ?)";
                $params[] = $minP;
            }
        }
    }
    if (!empty($orConds)) {
        $where[] = "(" . implode(' OR ', $orConds) . ")";
    }
}

if ($in_stock) {
    $where[] = "l.status = 'active' AND l.stock > 0";
}

if (isset($_GET['mrp']) && $_GET['mrp'] == 1) {
    $where[] = "l.is_mrp = 1";
}

$whereClause = implode(' AND ', $where);

$orderBy = 'l.created_at DESC';
if ($sort === 'price_low') $orderBy = 'l.price ASC';
elseif ($sort === 'price_high') $orderBy = 'l.price DESC';
elseif ($sort === 'oldest') $orderBy = 'l.created_at ASC';

try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name, c.slug AS category_slug, u.name AS seller_name, u.id AS seller_uid, u.avatar AS seller_avatar
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN users u ON l.seller_id = u.id
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT 50
    ");
    $stmt->execute($params);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $listings = [];
}

// Fetch categories for filter pills
$categories = [];
try {
    $stmt = $conn->query("SELECT name, slug FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<link rel="stylesheet" href="assets/css/home.css">
<link rel="stylesheet" href="assets/css/browse.css">

<div class="browse-page container-rl">
    <div class="section-header" data-aos="fade-up">
        <div>
            <div class="section-label">MARKETPLACE</div>
            <h2 class="section-title"><?php echo $search ? 'SEARCH: "'.htmlspecialchars($search).'"' : 'ALL LISTINGS'; ?></h2>
        </div>
    </div>

    <!-- Filter Bar & Sort -->
    <div class="browse-filters" data-aos="fade-up">
        <div class="filter-search">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="search" form="filterForm" placeholder="Search models, brands..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <select name="sort" form="filterForm" class="filter-select" onchange="this.form.submit()">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Sort by: Newest</option>
            <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Sort by: Price (Low → High)</option>
            <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Sort by: Price (High → Low)</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Sort by: Oldest</option>
        </select>
    </div>

    <!-- Mobile Button -->
    <div class="mobile-filter-toggle" onclick="document.getElementById('browseSidebar').classList.add('open'); document.getElementById('mobileOverlay').classList.add('open');">
        <i class="fas fa-sliders-h"></i> Filters & Categories
    </div>

    <div class="mobile-filter-overlay" id="mobileOverlay" onclick="document.getElementById('browseSidebar').classList.remove('open'); this.classList.remove('open');"></div>

    <div class="browse-layout">
        <!-- Sidebar Filters -->
        <aside class="browse-sidebar" id="browseSidebar">
            <div class="filter-close-btn" style="display:none;">
                Filters <i class="fas fa-times" onclick="document.getElementById('browseSidebar').classList.remove('open'); document.getElementById('mobileOverlay').classList.remove('open');"></i>
            </div>
            
            <form id="filterForm" method="GET" action="browse.php">
                <noscript><button type="submit" class="btn-red" style="margin-bottom:16px;">Apply Filters</button></noscript>
                
                <!-- Availability -->
                <div class="filter-group">
                    <div class="filter-title">Availability</div>
                    <div class="filter-options">
                        <label class="browse-toggle-wrapper">
                            <div class="browse-toggle <?php echo $in_stock ? 'on' : ''; ?>">
                                <input type="checkbox" name="in_stock" value="1" onchange="this.form.submit()" <?php echo $in_stock ? 'checked' : ''; ?>>
                                <span class="browse-toggle-slider"></span>
                            </div>
                            <span>In stock only</span>
                        </label>
                    </div>
                </div>

                <!-- Categories -->
                <div class="filter-group">
                    <div class="filter-title">Category / Line</div>
                    <div class="filter-options">
                        <?php foreach($categories as $cat): ?>
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="category[]" value="<?php echo htmlspecialchars($cat['slug']); ?>" onchange="this.form.submit()" <?php echo in_array($cat['slug'], $categories_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span><?php echo htmlspecialchars($cat['name']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Sizes -->
                <div class="filter-group">
                    <div class="filter-title">Scale / Size</div>
                    <div class="filter-options">
                        <?php 
                        $allScales = ['1:12', '1:18', '1:24', '1:32', '1:36', '1:43', '1:64', '1:72', '1:87'];
                        foreach($allScales as $sc): 
                        ?>
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="scale[]" value="<?php echo htmlspecialchars($sc); ?>" onchange="this.form.submit()" <?php echo in_array($sc, $scales_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span><?php echo $sc; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Conditions -->
                <div class="filter-group">
                    <div class="filter-title">Condition</div>
                    <div class="filter-options">
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="condition[]" value="new" onchange="this.form.submit()" <?php echo in_array('new', $conditions_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span>New / Sealed</span>
                        </label>
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="condition[]" value="opened" onchange="this.form.submit()" <?php echo in_array('opened', $conditions_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span>Opened / Mint</span>
                        </label>
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="condition[]" value="used" onchange="this.form.submit()" <?php echo in_array('used', $conditions_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span>Used (Loose)</span>
                        </label>
                    </div>
                </div>
                
                <!-- Prices -->
                <div class="filter-group">
                    <div class="filter-title">Price Range</div>
                    <div class="filter-options">
                        <?php
                        $priceOpts = [
                            '0-200' => '₹0 – ₹200',
                            '200-500' => '₹200 – ₹500',
                            '500-1000' => '₹500 – ₹1,000',
                            '1000-2000' => '₹1,000 – ₹2,000',
                            '2000-5000' => '₹2,000 – ₹5,000',
                            '5000+' => '₹5,000+',
                        ];
                        foreach($priceOpts as $pk => $pl):
                        ?>
                        <label class="custom-checkbox-wrapper">
                            <input type="checkbox" name="price_range[]" value="<?php echo $pk; ?>" onchange="this.form.submit()" <?php echo in_array($pk, $prices_filter) ? 'checked' : ''; ?>>
                            <span class="custom-checkbox"><i class="fas fa-check"></i></span>
                            <span><?php echo $pl; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
            </form>
        </aside>
        
        <!-- Main Area -->
        <main class="browse-main">

    <p class="browse-results-count"><?php echo count($listings); ?> listing<?php echo count($listings) !== 1 ? 's' : ''; ?> found</p>

    <!-- Results Grid -->
    <?php if (!empty($listings)): ?>
        <div class="listings-grid" data-aos="fade-up">
            <?php foreach($listings as $listing):
                $isSoldOut = ($listing['status'] === 'sold' || intval($listing['stock'] ?? 1) <= 0);
                $isWishlisted = in_array($listing['id'], $userWishlist);
            ?>
            <a href="<?php echo getListingUrl($listing['id'], $listing['title']); ?>" class="listing-card <?php echo $isSoldOut ? 'listing-card--soldout' : ''; ?>">
                <div class="listing-img">
                    <?php if(!empty($listing['image'])): ?>
                        <img src="<?php echo htmlspecialchars($listing['image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>">
                    <?php else: ?>
                        <div class="no-img"><i class="fas fa-car"></i></div>
                    <?php endif; ?>

                    <?php if($isSoldOut): ?>
                        <div class="soldout-overlay">
                            <span class="soldout-badge">SOLD OUT</span>
                        </div>
                        <button type="button" class="card-wishlist-btn <?php echo $isWishlisted ? 'wishlisted' : ''; ?>" data-listing-id="<?php echo $listing['id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); toggleCardWishlist(this, <?php echo $listing['id']; ?>)" title="<?php echo $isWishlisted ? 'Saved to Wishlist' : 'Add to Wishlist'; ?>">
                            <i class="<?php echo $isWishlisted ? 'fas' : 'far'; ?> fa-heart"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="listing-body">
                    <span class="listing-category"><?php echo htmlspecialchars($listing['category_name'] ?? 'Diecast'); ?></span>
                    <h4 class="listing-title"><?php echo htmlspecialchars($listing['title']); ?></h4>
                    <p class="listing-price">
                        <?php echo $isSoldOut ? '<span class="price-soldout">SOLD OUT</span>' : 'Rs.' . number_format($listing['price'], 0); ?>
                        <?php if(!$isSoldOut && !empty($listing['is_mrp'])): ?>
                            <span style="font-size:0.55rem; background:rgba(16,185,129,0.15); color:var(--accent-green); padding:2px 4px; border-radius:4px; font-weight:800; text-transform:uppercase; margin-left:4px; vertical-align:middle; border:1px solid rgba(16,185,129,0.3);">MRP</span>
                        <?php endif; ?>
                        <?php if(!$isSoldOut): ?>
                            <div style="font-size:0.65rem; color:var(--text-muted); margin-top:2px;">
                                <?php if(floatval($listing['shipping_fee'] ?? 0) > 0): ?>
                                    + Rs.<?php echo number_format($listing['shipping_fee'], 0); ?> Shipping
                                <?php else: ?>
                                    <span style="color:var(--accent-green); font-weight:700;">FREE SHIPPING</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </p>

                    <div class="listing-seller" onclick="window.location.href='seller.php?id=<?php echo $listing['seller_uid']; ?>'; return false;" style="cursor:pointer; z-index:2; position:relative;" onmouseover="this.style.color='var(--accent-red)'" onmouseout="this.style.color=''">
                        <?php if(!empty($listing['seller_avatar'])): ?>
                            <div style="width:20px;height:20px;border-radius:50%;background:url('<?php echo htmlspecialchars($listing['seller_avatar']); ?>') center/cover;display:inline-block;vertical-align:middle;margin-right:6px;"></div>
                        <?php endif; ?>
                        <span style="vertical-align:middle;"><?php echo htmlspecialchars($listing['seller_name'] ?? 'Seller'); ?></span>
                        <span class="seller-dot" style="vertical-align:middle;"></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="browse-empty" data-aos="fade-up">
            <i class="fas fa-search"></i>
            <h3>No listings found</h3>
            <p>Try adjusting your search or filters.</p>
        </div>
    <?php endif; ?>
        </main>
    </div>
</div>

<script>
// Submit form automatically when the user presses Enter on the search input
document.querySelector('input[name="search"]').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('filterForm').submit();
    }
});

// Wishlist toggle from card
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
