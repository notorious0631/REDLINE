<?php
session_start();
require_once 'config/db.php';

$sellerId = intval($_GET['id'] ?? 0);

if ($sellerId <= 0) {
    header('Location: browse.php');
    exit;
}

// Fetch seller profile
try {
    $stmt = $conn->prepare("SELECT id, name, store_name, role, avatar, banner, bio, store_location, social_instagram, social_facebook, social_twitter, show_reviews, is_verified, created_at, avg_rating, review_count, free_shipping_threshold FROM users WHERE id = ?");
    $stmt->execute([$sellerId]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seller) {
        header('Location: browse.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: browse.php');
    exit;
}

// Bio editing removed — now handled via seller_dashboard/storefront.php

// Fetch seller stats
$stats = ['listings' => 0, 'sold' => 0, 'followers' => 0];
$isFollowing = false;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'active'");
    $stmt->execute([$sellerId]);
    $stats['listings'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'sold'");
    $stmt->execute([$sellerId]);
    $stats['sold'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_follows WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $stats['followers'] = $stmt->fetchColumn();
    
    if (isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT id FROM user_follows WHERE follower_id = ? AND seller_id = ?");
        $stmt->execute([$_SESSION['user_id'], $sellerId]);
        if ($stmt->fetchColumn()) {
            $isFollowing = true;
        }
    }
} catch (PDOException $e) {}

// GET Parameters for Filtering
$search = trim($_GET['search'] ?? '');
$categoryId = intval($_GET['category'] ?? 0);
$sort = $_GET['sort'] ?? 'newest';

// Build Listings Query
$where = ["l.seller_id = ?", "l.status != 'draft'"];
$params = [$sellerId];

if ($search !== '') {
    $where[] = "l.title LIKE ?";
    $params[] = "%$search%";
}

if ($categoryId > 0) {
    $where[] = "l.category_id = ?";
    $params[] = $categoryId;
}

$whereClause = implode(' AND ', $where);

$orderBy = 'l.created_at DESC';
if ($sort === 'price_low') $orderBy = 'l.price ASC';
elseif ($sort === 'price_high') $orderBy = 'l.price DESC';
elseif ($sort === 'oldest') $orderBy = 'l.created_at ASC';

$listings = [];
try {
    $stmt = $conn->prepare("
        SELECT l.*, c.name AS category_name 
        FROM listings l 
        LEFT JOIN categories c ON l.category_id = c.id 
        WHERE $whereClause
        ORDER BY $orderBy
    ");
    $stmt->execute($params);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Active Categories For This Seller specifically
$activeCategories = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name 
        FROM categories c
        JOIN listings l ON l.category_id = c.id
        WHERE l.seller_id = ? AND l.status != 'draft'
        ORDER BY c.name ASC
    ");
    $stmt->execute([$sellerId]);
    $activeCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch recent reviews
$sellerReviews = [];
try {
    $stmt = $conn->prepare("
        SELECT sr.*, u.name as buyer_name, u.avatar as buyer_avatar
        FROM seller_reviews sr
        JOIN users u ON sr.buyer_id = u.id
        WHERE sr.seller_id = ?
        ORDER BY sr.created_at DESC LIMIT 5
    ");
    $stmt->execute([$sellerId]);
    $sellerReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

// Fetch seller highlights
$sellerHighlights = [];
try {
    $hlStmt = $conn->prepare("
        SELECT sh.*, 
               (SELECT COUNT(*) FROM highlight_images WHERE highlight_id = sh.id) as image_count
        FROM seller_highlights sh
        WHERE sh.seller_id = ? AND (SELECT COUNT(*) FROM highlight_images WHERE highlight_id = sh.id) > 0
        ORDER BY sh.sort_order ASC, sh.created_at DESC
    ");
    $hlStmt->execute([$sellerId]);
    $sellerHighlights = $hlStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sellerHighlights as &$hlItem) {
        $hiStmt = $conn->prepare("SELECT id, image_path FROM highlight_images WHERE highlight_id = ? ORDER BY sort_order ASC, id ASC");
        $hiStmt->execute([$hlItem['id']]);
        $hlItem['images'] = $hiStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($hlItem);
} catch (PDOException $e) {}

// ─── SEO: Dynamic meta for seller profile ───
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/REDLINE';

$sellerDisplayName = !empty($seller['store_name']) ? $seller['store_name'] : $seller['name'];
$pageTitle       = htmlspecialchars($sellerDisplayName) . ' — Verified Diecast Seller | REDLINER';
$bioExcerpt      = !empty($seller['bio']) ? mb_substr(strip_tags($seller['bio']), 0, 140) . '...' : '';
$pageDescription = !empty($bioExcerpt)
    ? $bioExcerpt . ' Browse ' . $stats['listings'] . ' listings on REDLINER.'
    : 'Browse ' . $stats['listings'] . ' diecast listings from ' . htmlspecialchars($sellerDisplayName) . ' — verified seller on REDLINER India.';
$pageOgImage     = !empty($seller['avatar']) ? $baseUrl . '/' . ltrim($seller['avatar'], '/') : $baseUrl . '/assets/images/logo.png';
$canonicalUrl    = $baseUrl . '/seller.php?id=' . $sellerId;
// ─────────────────────────────────────────────

include 'includes/header.php';

// ProfilePage JSON-LD schema
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ProfilePage",
  "name": "<?php echo addslashes(htmlspecialchars($sellerDisplayName)); ?> on REDLINER",
  "url": "<?php echo $canonicalUrl; ?>",
  "mainEntity": {
    "@type": "Person",
    "name": "<?php echo addslashes(htmlspecialchars($seller['name'])); ?>",
    "identifier": "<?php echo $sellerId; ?>",
    <?php if (!empty($seller['bio'])): ?>
    "description": "<?php echo addslashes(htmlspecialchars(strip_tags($seller['bio']))); ?>",
    <?php endif; ?>
    <?php if (!empty($seller['avatar'])): ?>
    "image": "<?php echo htmlspecialchars($pageOgImage); ?>",
    <?php endif; ?>
    "url": "<?php echo $canonicalUrl; ?>"
    <?php if (!empty($seller['review_count']) && $seller['review_count'] > 0): ?>
    ,"aggregateRating": {
      "@type": "AggregateRating",
      "ratingValue": "<?php echo number_format($seller['avg_rating'], 1); ?>",
      "reviewCount": "<?php echo $seller['review_count']; ?>"
    }
    <?php endif; ?>
  }
}
</script>
<?php
?>

<link rel="stylesheet" href="assets/css/browse.css?v=<?php echo time(); ?>"> <!-- Reuse card grid styles exactly -->
<link rel="stylesheet" href="assets/css/seller.css?v=<?php echo time(); ?>">

<div class="modern-seller-page">
    <?php if(!empty($_SESSION['flash_error'])): ?>
        <div class="container-rl" style="padding-top:16px;">
            <div style="background:rgba(229,57,53,0.1); border:1px solid rgba(229,57,53,0.3); color:#e57373; padding:12px 16px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if(!empty($_SESSION['flash_success'])): ?>
        <div class="container-rl" style="padding-top:16px;">
            <div style="background:rgba(76,175,80,0.1); border:1px solid rgba(76,175,80,0.3); color:#81c784; padding:12px 16px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Hero Profile Section -->
    <div class="seller-hero">
        <div class="seller-banner" <?php if(!empty($seller['banner'])) echo 'style="background: url('.htmlspecialchars($seller['banner']).') center/cover;"'; ?>></div>
        
        <div class="container-rl seller-hero-content">
            <div class="seller-avatar-wrapper">
                <div class="seller-avatar" <?php if(!empty($seller['avatar'])) echo 'style="background: url('.htmlspecialchars($seller['avatar']).') center/cover; color:transparent;"'; ?>>
                    <?php if(empty($seller['avatar'])) echo strtoupper(substr($seller['name'] ?? 'U', 0, 1)); ?>
                </div>
            </div>
            
            <div class="seller-details-box">
                <?php 
                    $displayName = !empty($seller['store_name']) ? $seller['store_name'] : $seller['name'];
                    $showSubtitle = !empty($seller['store_name']);
                ?>
                <h1 class="seller-name">
                    <?php echo htmlspecialchars($displayName); ?>
                    <?php if($seller['is_verified']): ?>
                        <i class="fas fa-check-circle verified-badge" title="Verified Seller"></i>
                    <?php endif; ?>
                </h1>
                <?php if($showSubtitle): ?>
                <p style="color:var(--text-secondary);font-size:0.9rem;margin-top:2px;">by <?php echo htmlspecialchars($seller['name']); ?></p>
                <?php endif; ?>
                
                <div class="seller-trust-stats">

                    <div class="trust-stat">
                        <i class="fas fa-calendar-alt"></i> Joined <?php echo date('M Y', strtotime($seller['created_at'])); ?>
                    </div>
                    <div class="trust-stat">
                        <i class="fas fa-tags"></i> <?php echo number_format($stats['listings']); ?> Active Listings
                    </div>
                    <div class="trust-stat">
                        <i class="fas fa-box-open"></i> <?php echo number_format($stats['sold']); ?> Items Sold
                    </div>
                    <div class="trust-stat">
                        <i class="fas fa-users"></i> <span id="followerCountDisplay"><?php echo number_format($stats['followers']); ?></span> Followers
                    </div>
                    <?php if(!empty($seller['review_count']) && $seller['review_count'] > 0): ?>
                    <div class="trust-stat" style="color:#fbbf24;">
                        <i class="fas fa-star"></i> <?php echo number_format($seller['avg_rating'], 1); ?> Rating (<?php echo $seller['review_count']; ?>)
                    </div>
                    <?php endif; ?>
                    <?php if(isset($seller['free_shipping_threshold']) && $seller['free_shipping_threshold'] !== null): ?>
                    <div class="trust-stat" style="color:#34d399;">
                        <i class="fas fa-truck"></i> Free Shipping above ₹<?php echo number_format(floatval($seller['free_shipping_threshold']), 0); ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($seller['bio'])): ?>
                    <p class="seller-bio-text">
                        <?php echo nl2br(htmlspecialchars($seller['bio'])); ?>
                    </p>
                <?php endif; ?>

                <?php 
                $hasSocials = !empty($seller['social_instagram']) || !empty($seller['social_facebook']) || !empty($seller['social_twitter']);
                if ($hasSocials): ?>
                <div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap;">
                    <?php if(!empty($seller['social_instagram'])): ?>
                    <a href="<?php echo htmlspecialchars($seller['social_instagram']); ?>" target="_blank" rel="noopener" style="width:36px;height:36px;border-radius:50%;background:rgba(228,64,95,0.12);display:flex;align-items:center;justify-content:center;color:#E4405F;text-decoration:none;transition:transform 0.2s,background 0.2s;font-size:1rem;" title="Instagram" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(!empty($seller['social_facebook'])): ?>
                    <a href="<?php echo htmlspecialchars($seller['social_facebook']); ?>" target="_blank" rel="noopener" style="width:36px;height:36px;border-radius:50%;background:rgba(24,119,242,0.12);display:flex;align-items:center;justify-content:center;color:#1877F2;text-decoration:none;transition:transform 0.2s;font-size:1rem;" title="Facebook" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <?php endif; ?>
                    <?php if(!empty($seller['social_twitter'])): ?>
                    <a href="<?php echo htmlspecialchars($seller['social_twitter']); ?>" target="_blank" rel="noopener" style="width:36px;height:36px;border-radius:50%;background:rgba(29,161,242,0.12);display:flex;align-items:center;justify-content:center;color:#1DA1F2;text-decoration:none;transition:transform 0.2s;font-size:1rem;" title="Twitter" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                <?php if(isset($_SESSION['user_id']) && $_SESSION['user_id'] != $seller['id']): ?>
                    <a href="negotiate.php?direct_seller_id=<?php echo $seller['id']; ?>&tab=direct" class="btn" style="background:#e53935; color:#fff; border-radius:8px; padding:10px 20px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        <i class="fas fa-comments"></i> Chat with Seller
                    </a>
                    <button id="toggleFollowBtn" onclick="toggleFollow(<?php echo $seller['id']; ?>)" class="btn <?php echo $isFollowing ? 'following' : ''; ?>" style="background:<?php echo $isFollowing ? 'rgba(255,255,255,0.1)' : 'transparent'; ?>; color:<?php echo $isFollowing ? 'var(--text-primary)' : '#e53935'; ?>; border:2px solid <?php echo $isFollowing ? 'transparent' : '#e53935'; ?>; border-radius:8px; padding:8px 20px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all 0.2s;">
                        <?php if ($isFollowing): ?>
                            <i class="fas fa-user-check"></i> Following
                        <?php else: ?>
                            <i class="fas fa-user-plus"></i> Follow Seller
                        <?php endif; ?>
                    </button>
                    <script>
                    function toggleFollow(sellerId) {
                        const btn = document.getElementById('toggleFollowBtn');
                        const countDisp = document.getElementById('followerCountDisplay');
                        btn.style.opacity = '0.7';
                        btn.style.pointerEvents = 'none';
                        
                        const fd = new FormData();
                        fd.append('action', 'toggle');
                        fd.append('seller_id', sellerId);
                        
                        fetch('api/follow.php', {
                            method: 'POST',
                            body: fd
                        })
                        .then(r => r.json())
                        .then(data => {
                            btn.style.opacity = '1';
                            btn.style.pointerEvents = 'auto';
                            if (data.success) {
                                countDisp.textContent = data.count.toLocaleString();
                                if (data.isFollowing) {
                                    btn.style.background = 'rgba(255,255,255,0.1)';
                                    btn.style.color = 'var(--text-primary)';
                                    btn.style.borderColor = 'transparent';
                                    btn.innerHTML = '<i class="fas fa-user-check"></i> Following';
                                    btn.classList.add('following');
                                } else {
                                    btn.style.background = 'transparent';
                                    btn.style.color = '#e53935';
                                    btn.style.borderColor = '#e53935';
                                    btn.innerHTML = '<i class="fas fa-user-plus"></i> Follow Seller';
                                    btn.classList.remove('following');
                                }
                            } else {
                                alert(data.message || 'Error occurred while updating follow status.');
                            }
                        })
                        .catch(() => {
                            btn.style.opacity = '1';
                            btn.style.pointerEvents = 'auto';
                            alert('Network error.');
                        });
                    }
                    </script>
                <?php elseif(isset($_SESSION['user_id']) && $_SESSION['user_id'] == $seller['id']): ?>
                    <a href="seller_dashboard/storefront.php" style="
                        display: inline-flex; align-items: center; gap: 10px;
                        padding: 10px 20px; border-radius: 8px;
                        border: 2px solid #e53935; color: #e53935;
                        background: transparent; text-decoration: none;
                        font-weight: 700; font-size: 0.88rem;
                        font-family: inherit; transition: all 0.25s ease;
                    " onmouseover="this.style.background='rgba(229,57,53,0.08)'; this.style.transform='translateY(-1px)';"
                       onmouseout="this.style.background='transparent'; this.style.transform='translateY(0)';">
                        <i class="fas fa-pen"></i> Edit Storefront
                    </a>
                <?php endif; ?>

                <?php if(!empty($seller['store_location'])): ?>
                    <a href="<?php echo htmlspecialchars($seller['store_location']); ?>" target="_blank" rel="noopener" style="
                        display: inline-flex; align-items: center; gap: 8px;
                        padding: 10px 20px; border-radius: 8px;
                        border: 2px solid rgba(255,255,255,0.12); color: var(--text-primary);
                        background: rgba(255,255,255,0.04); text-decoration: none;
                        font-weight: 700; font-size: 0.88rem;
                        font-family: inherit; transition: all 0.25s ease;
                    " onmouseover="this.style.background='rgba(234,67,53,0.08)'; this.style.borderColor='rgba(234,67,53,0.3)'; this.style.color='#EA4335'; this.style.transform='translateY(-1px)';"
                       onmouseout="this.style.background='rgba(255,255,255,0.04)'; this.style.borderColor='rgba(255,255,255,0.12)'; this.style.color='var(--text-primary)'; this.style.transform='translateY(0)';">
                        <i class="fas fa-map-marker-alt" style="color:#EA4335;"></i> Store Location
                    </a>
                <?php endif; ?>
                
                    <button onclick="shareStorefront()" style="
                        display: inline-flex; align-items: center; gap: 8px;
                        padding: 10px 20px; border-radius: 8px;
                        border: 2px solid rgba(255,255,255,0.12); color: var(--text-primary);
                        background: rgba(255,255,255,0.04); cursor: pointer;
                        font-weight: 700; font-size: 0.88rem;
                        font-family: inherit; transition: all 0.25s ease;
                    " onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.transform='translateY(-1px)';"
                       onmouseout="this.style.background='rgba(255,255,255,0.04)'; this.style.transform='translateY(0)';">
                        <i class="fas fa-share-alt"></i> Share
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($sellerHighlights)): ?>
    <!-- ═══ Instagram-Style Highlights ═══ -->
    <div class="container-rl" style="margin-top: 24px;" data-aos="fade-up">
        <h3 class="inventory-title" style="margin-bottom:16px;">Customer Feedback</h3>
        <div class="sh-highlights-row" id="highlightsRow">
            <?php foreach ($sellerHighlights as $hlIdx => $hl): ?>
            <div class="sh-highlight-item" onclick="openStoryViewer(<?php echo $hlIdx; ?>)">
                <div class="sh-highlight-ring">
                    <div class="sh-highlight-cover">
                        <?php if (!empty($hl['cover_image'])): ?>
                            <img src="<?php echo htmlspecialchars($hl['cover_image']); ?>" alt="<?php echo htmlspecialchars($hl['title']); ?>">
                        <?php else: ?>
                            <i class="far fa-image"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="sh-highlight-label"><?php echo htmlspecialchars($hl['title']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Story Viewer Modal -->
    <div class="story-viewer-overlay" id="storyViewer" onclick="if(event.target===this)closeStoryViewer()">
        <div class="story-viewer-container">
            <div class="story-viewer-header">
                <div class="sv-progress-bar" id="svProgressBar"></div>
                <div class="sv-header-info">
                    <div class="sv-avatar">
                        <?php if(!empty($seller['avatar'])): ?>
                            <img src="<?php echo htmlspecialchars($seller['avatar']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($seller['name'] ?? 'U', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="sv-name"><?php echo htmlspecialchars($seller['name']); ?></div>
                        <div class="sv-highlight-title" id="svTitle"></div>
                    </div>
                    <button class="sv-close" onclick="closeStoryViewer()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="story-viewer-image" id="svImage"></div>
            <div class="sv-nav sv-nav-left" onclick="storyPrev()"></div>
            <div class="sv-nav sv-nav-right" onclick="storyNext()"></div>
            <div class="sv-counter" id="svCounter"></div>
        </div>
    </div>

    <style>
    /* ── Highlights Row ── */
    .sh-highlights-row {
        display: flex; gap: 20px; overflow-x: auto; padding: 8px 4px 16px;
        scrollbar-width: none; -ms-overflow-style: none;
    }
    .sh-highlights-row::-webkit-scrollbar { display: none; }

    .sh-highlight-item {
        display: flex; flex-direction: column; align-items: center; gap: 8px;
        cursor: pointer; flex-shrink: 0; width: 76px;
    }

    .sh-highlight-ring {
        width: 72px; height: 72px; border-radius: 50%; padding: 3px;
        background: linear-gradient(135deg, #e53935, #ff6b6b, #ffb74d);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .sh-highlight-item:hover .sh-highlight-ring {
        transform: scale(1.08);
        box-shadow: 0 4px 20px rgba(229,57,53,0.3);
    }

    .sh-highlight-cover {
        width: 100%; height: 100%; border-radius: 50%; overflow: hidden;
        background: var(--bg-surface, #141414); border: 2px solid var(--bg-surface, #141414);
        display: flex; align-items: center; justify-content: center;
        color: var(--text-muted);
    }
    .sh-highlight-cover img { width: 100%; height: 100%; object-fit: cover; }

    .sh-highlight-label {
        font-size: 0.68rem; color: var(--text-secondary, #b0b0b0);
        text-align: center; max-width: 76px; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis; font-weight: 500;
    }

    /* ── Story Viewer ── */
    .story-viewer-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.95);
        z-index: 10000; align-items: center; justify-content: center;
        backdrop-filter: blur(10px);
    }
    .story-viewer-overlay.active { display: flex; }

    .story-viewer-container {
        position: relative; width: 100%; max-width: 420px; height: 90vh;
        max-height: 750px; border-radius: 16px; overflow: hidden;
        background: #000; box-shadow: 0 20px 60px rgba(0,0,0,0.8);
    }

    .story-viewer-header {
        position: absolute; top: 0; left: 0; right: 0; z-index: 10;
        padding: 12px 16px 10px; background: linear-gradient(180deg, rgba(0,0,0,0.7), transparent);
    }

    .sv-progress-bar {
        display: flex; gap: 3px; margin-bottom: 10px;
    }
    .sv-progress-segment {
        flex: 1; height: 2.5px; background: rgba(255,255,255,0.25);
        border-radius: 2px; overflow: hidden;
    }
    .sv-progress-segment .sv-progress-fill {
        height: 100%; width: 0; background: #fff; border-radius: 2px;
        transition: width 0.1s linear;
    }
    .sv-progress-segment.done .sv-progress-fill { width: 100%; }
    .sv-progress-segment.active .sv-progress-fill {
        animation: svProgressAnim 5s linear forwards;
    }
    @keyframes svProgressAnim { to { width: 100%; } }

    .sv-header-info {
        display: flex; align-items: center; gap: 10px;
    }

    .sv-avatar {
        width: 34px; height: 34px; border-radius: 50%; overflow: hidden;
        background: rgba(255,255,255,0.1); display: flex; align-items: center;
        justify-content: center; font-weight: 700; color: #fff; font-size: 0.8rem;
        flex-shrink: 0;
    }
    .sv-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .sv-name { font-size: 0.82rem; font-weight: 700; color: #fff; }
    .sv-highlight-title { font-size: 0.68rem; color: rgba(255,255,255,0.5); }

    .sv-close {
        margin-left: auto; width: 34px; height: 34px; border-radius: 50%;
        border: none; background: rgba(255,255,255,0.1); color: #fff;
        font-size: 1rem; cursor: pointer; display: flex; align-items: center;
        justify-content: center; transition: all 0.2s;
    }
    .sv-close:hover { background: rgba(255,255,255,0.2); }

    .story-viewer-image {
        width: 100%; height: 100%; display: flex; align-items: center;
        justify-content: center; background: #000;
    }
    .story-viewer-image img {
        width: 100%; height: 100%; object-fit: contain;
        animation: svFadeIn 0.3s ease;
    }
    @keyframes svFadeIn { from { opacity: 0; transform: scale(0.97); } to { opacity: 1; transform: scale(1); } }

    .sv-nav {
        position: absolute; top: 80px; bottom: 50px; width: 40%; cursor: pointer; z-index: 5;
    }
    .sv-nav-left { left: 0; }
    .sv-nav-right { right: 0; }

    .sv-counter {
        position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%);
        font-size: 0.72rem; color: rgba(255,255,255,0.4); font-weight: 600;
    }

    @media (max-width: 480px) {
        .story-viewer-container { max-width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
        .sh-highlight-ring { width: 62px; height: 62px; }
        .sh-highlight-item { width: 66px; }
        .sh-highlights-row { gap: 14px; }
    }
    </style>

    <script>
    (function(){
        const highlights = <?php echo json_encode(array_map(function($h) {
            return [
                'title' => $h['title'],
                'images' => array_map(function($i) { return $i['image_path']; }, $h['images'])
            ];
        }, $sellerHighlights)); ?>;

        let currentHL = 0, currentImg = 0, autoTimer = null;

        window.openStoryViewer = function(hlIdx) {
            currentHL = hlIdx;
            currentImg = 0;
            document.getElementById('storyViewer').classList.add('active');
            document.body.style.overflow = 'hidden';
            renderStory();
        };

        window.closeStoryViewer = function() {
            document.getElementById('storyViewer').classList.remove('active');
            document.body.style.overflow = '';
            clearTimeout(autoTimer);
        };

        window.storyNext = function() {
            clearTimeout(autoTimer);
            const hl = highlights[currentHL];
            if (currentImg < hl.images.length - 1) {
                currentImg++;
                renderStory();
            } else if (currentHL < highlights.length - 1) {
                currentHL++;
                currentImg = 0;
                renderStory();
            } else {
                closeStoryViewer();
            }
        };

        window.storyPrev = function() {
            clearTimeout(autoTimer);
            if (currentImg > 0) {
                currentImg--;
                renderStory();
            } else if (currentHL > 0) {
                currentHL--;
                currentImg = highlights[currentHL].images.length - 1;
                renderStory();
            }
        };

        function renderStory() {
            const hl = highlights[currentHL];
            const img = hl.images[currentImg];

            // Title
            document.getElementById('svTitle').textContent = hl.title;

            // Image
            document.getElementById('svImage').innerHTML = '<img src="' + img + '" alt="">';

            // Counter
            document.getElementById('svCounter').textContent = (currentImg + 1) + ' / ' + hl.images.length;

            // Progress segments
            let progHtml = '';
            for (let i = 0; i < hl.images.length; i++) {
                let cls = '';
                if (i < currentImg) cls = 'done';
                else if (i === currentImg) cls = 'active';
                progHtml += '<div class="sv-progress-segment ' + cls + '"><div class="sv-progress-fill"></div></div>';
            }
            document.getElementById('svProgressBar').innerHTML = progHtml;

            // Auto-advance
            clearTimeout(autoTimer);
            autoTimer = setTimeout(storyNext, 5000);
        }

        // Keyboard nav
        document.addEventListener('keydown', function(e) {
            if (!document.getElementById('storyViewer').classList.contains('active')) return;
            if (e.key === 'ArrowRight' || e.key === ' ') storyNext();
            else if (e.key === 'ArrowLeft') storyPrev();
            else if (e.key === 'Escape') closeStoryViewer();
        });
    })();
    </script>
    <?php endif; ?>
    
    <?php if (!empty($sellerReviews)): ?>
    <div class="container-rl seller-reviews-section" style="margin-top:20px; margin-bottom:20px;" data-aos="fade-up">
        <h3 class="inventory-title" style="margin-bottom:16px;">Recent Reviews</h3>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:20px;">
            <?php foreach($sellerReviews as $rev): ?>
            <div style="background:var(--bg-surface); padding:20px; border-radius:12px; border:1px solid var(--border-color);">
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
                <?php if(!empty($rev['review_image'])): ?>
                    <div style="margin-top:12px;">
                        <img src="<?php echo htmlspecialchars($rev['review_image']); ?>" style="max-height: 120px; border-radius: 8px; border: 1px solid var(--border-color); cursor: pointer; object-fit: cover;" onclick="window.open(this.src, '_blank')" alt="Review Image">
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter & Listings Section -->
    <div class="container-rl seller-inventory-section">
        
        <div class="seller-filter-bar" data-aos="fade-up">
            <h3 class="inventory-title">Seller's Items</h3>
            
            <form class="seller-filter-form" method="GET" action="seller.php">
                <input type="hidden" name="id" value="<?php echo $sellerId; ?>">
                
                <div class="filter-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search this seller's items..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-controls">
                    <select name="category" class="filter-dropdown" onchange="this.form.submit()">
                        <option value="0">All Categories</option>
                        <?php foreach($activeCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="sort" class="filter-dropdown" onchange="this.form.submit()">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                    </select>
                </div>
            </form>
        </div>

        <?php if (!empty($listings)): ?>
            <div class="listings-grid" data-aos="fade-up">
                <?php foreach($listings as $listing):
                    $isSoldOut = ($listing['status'] === 'sold' || intval($listing['stock'] ?? 1) <= 0);
                    $isWishlisted = in_array($listing['id'], $userWishlist);
                ?>
                <a href="<?php echo getListingUrl($listing['id'], $listing['title']); ?>" class="listing-card <?php echo $isSoldOut ? 'listing-card--soldout' : ''; ?>">
                    <div class="listing-img">
                        <?php if(!empty($listing['image'])): ?>
                            <img src="<?php echo htmlspecialchars($listing['image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="no-img"><i class="fas fa-car-side"></i></div>
                        <?php endif; ?>
                        
                        <?php if($isSoldOut): ?>
                            <div class="soldout-overlay">
                                <span class="soldout-badge">SOLD OUT</span>
                            </div>
                            <button type="button" class="card-wishlist-btn <?php echo $isWishlisted ? 'wishlisted' : ''; ?>" data-listing-id="<?php echo $listing['id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); toggleCardWishlist(this, <?php echo $listing['id']; ?>)" title="<?php echo $isWishlisted ? 'Saved to Wishlist' : 'Add to Wishlist'; ?>">
                                <i class="<?php echo $isWishlisted ? 'fas' : 'far'; ?> fa-heart"></i>
                            </button>
                        <?php elseif($listing['condition'] === 'new'): ?>
                            <span class="condition-badge" style="position:absolute; top:10px; right:10px; background:rgba(76,175,80,0.9); color:#fff; padding:4px 8px; border-radius:4px; font-size:0.7rem; font-weight:700; z-index:2;">NEW</span>
                        <?php elseif($listing['condition'] === 'opened'): ?>
                            <span class="condition-badge" style="position:absolute; top:10px; right:10px; background:rgba(255,183,77,0.9); color:#000; padding:4px 8px; border-radius:4px; font-size:0.7rem; font-weight:700; z-index:2;">MINT</span>
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
                        </p>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="browse-empty" style="text-align:center; padding: 100px 20px; background: var(--bg-surface); border-radius: var(--radius); margin-top: 20px;">
                <i class="fas fa-box-open" style="font-size:3rem; color:var(--text-muted); opacity:0.3; margin-bottom:16px;"></i>
                <h3>No items found</h3>
                <p style="color:var(--text-secondary);">Try adjusting your search or filters.</p>
                <?php if($search !== '' || $categoryId > 0): ?>
                    <a href="seller.php?id=<?php echo $sellerId; ?>" class="btn-outline-white" style="margin-top:20px; display:inline-block;">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Wishlist toggle from card (seller page)
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

function shareStorefront() {
    const url = window.location.href;
    const title = '<?php echo addslashes($displayName); ?> on REDLINER';
    if (navigator.share) {
        navigator.share({
            title: title,
            text: 'Check out this seller on REDLINER!',
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

<?php include 'includes/footer.php'; ?>
