<?php
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

// Fetch categories
$categories = [];
try {
    $stmt = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback categories if DB not set up yet
    $categories = [
        ['name' => 'HW Mainline', 'slug' => 'hw-mainline', 'badge_label' => 'STARTER', 'badge_type' => 'starter'],
        ['name' => 'Premium', 'slug' => 'premium', 'badge_label' => 'POPULAR', 'badge_type' => 'popular'],
        ['name' => 'Treasure Hunt', 'slug' => 'treasure-hunt', 'badge_label' => 'RARE', 'badge_type' => 'rare'],
        ['name' => 'Super Treasure Hunt', 'slug' => 'super-treasure-hunt', 'badge_label' => 'ULTRA RARE', 'badge_type' => 'ultra-rare'],
        ['name' => 'Kaido House', 'slug' => 'kaido-house', 'badge_label' => 'EXCLUSIVE', 'badge_type' => 'exclusive'],
        ['name' => 'Mini GT', 'slug' => 'mini-gt', 'badge_label' => 'DETAIL', 'badge_type' => 'detail'],
        ['name' => 'Majorette', 'slug' => 'majorette', 'badge_label' => 'VALUE', 'badge_type' => 'value'],
        ['name' => 'Tomica', 'slug' => 'tomica', 'badge_label' => 'JDM', 'badge_type' => 'jdm'],
        ['name' => 'Matchbox', 'slug' => 'matchbox', 'badge_label' => 'CLASSIC', 'badge_type' => 'classic'],
    ];
}

// Fetch latest listings (including sold-out ones)
$listings = [];
try {
    $stmt = $conn->query("
        SELECT l.*, c.name AS category_name, c.slug AS category_slug, u.name AS seller_name
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN users u ON l.seller_id = u.id
        WHERE l.status IN ('active','sold')
        ORDER BY l.created_at DESC
        LIMIT 8
    ");
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch carousel slides
$slides = [];
try {
    $stmt = $conn->query("SELECT * FROM carousel_slides WHERE status = 'active' ORDER BY sort_order ASC, created_at DESC");
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<!-- Home Page CSS -->
<link rel="stylesheet" href="assets/css/home.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />

<?php if (!empty($slides)): ?>
<!-- ===== HERO CAROUSEL (Zomato-style banner) ===== -->
<section class="rl-hero-banner container-rl" data-aos="fade-in">
    <div class="swiper hero-swiper">
        <div class="swiper-wrapper">
            <?php foreach ($slides as $slide): ?>
            <div class="swiper-slide">
                <a href="<?php echo htmlspecialchars($slide['button_link'] ?: '#'); ?>" class="hero-banner-slide">
                    <?php if ($slide['media_type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars($slide['media_path']); ?>" autoplay loop muted playsinline></video>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($slide['media_path']); ?>" alt="<?php echo htmlspecialchars($slide['headline'] ?: 'Banner'); ?>">
                    <?php endif; ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Swiper('.hero-swiper', {
        effect: 'slide',
        autoplay: {
            delay: 4000,
            disableOnInteraction: false,
        },
        pagination: {
            el: '.swiper-pagination',
            clickable: true,
        },
        loop: <?php echo count($slides) > 1 ? 'true' : 'false'; ?>,
        speed: 600,
        spaceBetween: 16,
        grabCursor: true,
    });
});
</script>

<style>
.rl-hero-banner {
    padding-top: 68px;
    margin-bottom: 0;
}
.rl-hero-banner .hero-swiper {
    border-radius: 16px;
    overflow: hidden;
}
.hero-banner-slide {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 6;
    overflow: hidden;
    border-radius: 16px;
    background: #111;
    text-decoration: none;
}
.hero-banner-slide img,
.hero-banner-slide video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.4s ease;
}
.hero-banner-slide:hover img,
.hero-banner-slide:hover video {
    transform: scale(1.03);
}
.hero-swiper .swiper-pagination {
    bottom: 12px !important;
}
.hero-swiper .swiper-pagination-bullet {
    background: rgba(255, 255, 255, 0.5);
    opacity: 1;
    width: 8px;
    height: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
}
.hero-swiper .swiper-pagination-bullet-active {
    background: var(--accent-red, #e53935);
    width: 22px;
}
@media (max-width: 768px) {
    .rl-hero-banner { padding-top: 58px; padding-left: 8px; padding-right: 8px; }
    .hero-banner-slide { aspect-ratio: 16 / 8; border-radius: 12px; }
    .rl-hero-banner .hero-swiper { border-radius: 12px; }
}
</style>

<?php else: ?>
<!-- ===== FALLBACK HERO SECTION ===== -->
<section class="rl-hero" data-aos="fade-in">
    <div class="hero-bg">
        <img src="assets/images/hero_bg.png" alt="Hot Wheels Diecast Car">
    </div>
    <div class="hero-content">
        <span class="hero-badge">INDIA'S DIECAST MARKETPLACE</span>
        <h1 class="hero-headline">
            <span class="line">COLLECT.</span>
            <span class="line accent">TRADE.</span>
            <span class="line">RACE.</span>
        </h1>
        <p class="hero-subtitle">
            The trusted marketplace for Hot Wheels, Mini GT, Tomica, and premium diecast collectors. 
            Verified sellers. Fair prices. Safe delivery.
        </p>
        <div class="hero-cta" style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="browse.php" class="btn-red">
                BROWSE COLLECTION <i class="fas fa-arrow-right"></i>
            </a>
            <a href="features.php" class="btn-outline-white">
                LEARN MORE
            </a>
            <a href="sell.php" class="btn-outline-white" style="border-color: rgba(255,255,255,0.3);">
                START SELLING
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== TRUST BADGES BAR ===== -->
<section class="trust-bar" data-aos="fade-up">
    <div class="trust-grid">
        <div class="trust-item">
            <div class="trust-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="trust-text">
                <h4>VERIFIED SELLERS</h4>
                <p>KYC verified & trusted</p>
            </div>
        </div>
        <div class="trust-item">
            <div class="trust-icon">
                <i class="fas fa-comments"></i>
            </div>
            <div class="trust-text">
                <h4>NEGOTIATE</h4>
                <p>Chat & make offers</p>
            </div>
        </div>
        <div class="trust-item">
            <div class="trust-icon">
                <i class="fas fa-rupee-sign"></i>
            </div>
            <div class="trust-text">
                <h4>UPI PAYMENTS</h4>
                <p>Direct & instant</p>
            </div>
        </div>
        <div class="trust-item">
            <div class="trust-icon">
                <i class="fas fa-truck"></i>
            </div>
            <div class="trust-text">
                <h4>SAFE DELIVERY</h4>
                <p>Track your order</p>
            </div>
        </div>
    </div>
</section>

<!-- ===== BROWSE BY LINE ===== -->
<section class="browse-section container-rl" data-aos="fade-up">
    <div class="section-header">
        <div>
            <div class="section-label">CATEGORIES</div>
            <h2 class="section-title">BROWSE BY LINE</h2>
        </div>
        <a href="browse.php" class="view-all">VIEW ALL <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="category-grid">
        <?php foreach($categories as $cat): ?>
        <a href="browse.php?category=<?php echo urlencode($cat['slug']); ?>" class="category-card">
            <span class="cat-badge badge-<?php echo htmlspecialchars($cat['badge_type']); ?>">
                <?php echo htmlspecialchars($cat['badge_label']); ?>
            </span>
            <h3 class="cat-name"><?php echo htmlspecialchars($cat['name']); ?></h3>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===== FRESH LISTINGS ===== -->
<section class="listings-section container-rl" data-aos="fade-up">
    <div class="section-header">
        <div>
            <div class="section-label">LATEST</div>
            <h2 class="section-title">FRESH LISTINGS</h2>
        </div>
        <a href="browse.php" class="view-all">VIEW ALL <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="listings-grid">
        <?php if(!empty($listings)): ?>
            <?php foreach($listings as $listing):
                $isSoldOut = ($listing['status'] === 'sold' || intval($listing['stock'] ?? 1) <= 0);
                $isWishlisted = in_array($listing['id'], $userWishlist);
            ?>
            <a href="listing.php?id=<?php echo $listing['id']; ?>" class="card <?php echo $isSoldOut ? 'card--soldout' : ''; ?>" style="text-decoration: none;">
              <section class="card__hero">
                <header class="card__hero-header">
                  <span><?php echo $isSoldOut ? 'SOLD OUT' : 'Rs.' . number_format($listing['price'], 0); ?></span>
                  <div class="card__icon">
                    <?php if ($isSoldOut): ?>
                    <button type="button" class="card-wishlist-btn <?php echo $isWishlisted ? 'wishlisted' : ''; ?>" data-listing-id="<?php echo $listing['id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); toggleCardWishlist(this, <?php echo $listing['id']; ?>)" title="<?php echo $isWishlisted ? 'Saved to Wishlist' : 'Add to Wishlist'; ?>" style="background:none; border:none; cursor:pointer; padding:0;">
                        <i class="<?php echo $isWishlisted ? 'fas' : 'far'; ?> fa-heart" style="font-size:1.1rem; color:<?php echo $isWishlisted ? '#e53935' : 'currentColor'; ?>; transition:color 0.25s;"></i>
                    </button>
                    <?php else: ?>
                    <svg height="20" width="20" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" stroke-linejoin="round" stroke-linecap="round"></path>
                    </svg>
                    <?php endif; ?>
                  </div>
                </header>

                <div style="display: flex; justify-content: center; align-items: center; margin: 20px 0; position:relative;">
                    <?php if(!empty($listing['image'])): ?>
                        <img src="<?php echo htmlspecialchars($listing['image']); ?>" style="width: 100%; height: 180px; object-fit: contain; border-radius: 8px; <?php echo $isSoldOut ? 'opacity:0.5; filter:grayscale(40%);' : ''; ?>" alt="product">
                    <?php else: ?>
                        <div style="width: 100%; height: 180px; background: rgba(0,0,0,0.04); border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i class="fas fa-car" style="color: rgba(0,0,0,0.2); font-size: 3rem;"></i></div>
                    <?php endif; ?>
                    <?php if($isSoldOut): ?>
                    <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
                        <span style="background:rgba(229,57,53,0.95); color:#fff; padding:6px 18px; border-radius:6px; font-size:0.85rem; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; backdrop-filter:blur(4px);">SOLD OUT</span>
                    </div>
                    <?php endif; ?>
                </div>

                <p class="card__job-title" style="margin: 0; padding-right: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center; font-size: 1.3rem;"><?php echo htmlspecialchars($listing['title']); ?></p>
              </section>

              <footer class="card__footer">
                <div class="card__job-summary">
                  <div class="card__job" style="margin-top: 4px;">
                    <p class="card__job-title">
                      <?php echo htmlspecialchars($listing['seller_name'] ?? 'Seller'); ?> <br />
                      <span style="font-size: 0.8em; font-weight: normal; color: #666;"><?php echo htmlspecialchars($listing['category_name'] ?? 'Diecast'); ?></span>
                    </p>
                  </div>
                </div>

                <span class="card__btn"><?php echo $isSoldOut ? 'wishlist' : 'view'; ?></span>
              </footer>
            </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="browse-empty" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                <i class="fas fa-box-open" style="font-size: 3rem; color: var(--text-muted); opacity: 0.4; margin-bottom: 16px; display: block;"></i>
                <h3 style="color: var(--text-secondary); margin-bottom: 8px;">No listings yet</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">Be the first to list your diecast collection!</p>
                <a href="sell.php" class="btn-red" style="display: inline-flex; align-items: center; gap: 8px;">START SELLING <i class="fas fa-arrow-right"></i></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Swiper JS (for future carousels on homepage) -->
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>

<script>
// Wishlist toggle from card (homepage)
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
                    icon.style.color = '#e53935';
                    btn.title = 'Saved to Wishlist';
                } else {
                    btn.classList.remove('wishlisted');
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                    icon.style.color = 'currentColor';
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
