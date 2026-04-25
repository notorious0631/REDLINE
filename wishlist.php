<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Fetch wishlisted items with listing details
$wishlistItems = [];
try {
    $stmt = $conn->prepare("
        SELECT w.id AS wishlist_id, w.created_at AS wishlisted_at,
               l.id AS listing_id, l.title, l.price, l.image, l.status, l.stock,
               c.name AS category_name,
               u.name AS seller_name, u.id AS seller_id
        FROM wishlists w
        JOIN listings l ON w.listing_id = l.id
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN users u ON l.seller_id = u.id
        WHERE w.user_id = ?
        ORDER BY w.created_at DESC
    ");
    $stmt->execute([$userId]);
    $wishlistItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

include 'includes/header.php';
?>

<style>
/* ── Wishlist Page ── */
.wl-page { padding: 100px 0 80px; min-height: 90vh; }
.wl-inner { max-width: 900px; margin: 0 auto; padding: 0 20px; }

.wl-hero { margin-bottom: 36px; }
.wl-hero h1 {
    font-size: 2rem; font-weight: 900; letter-spacing: -0.5px;
    display: flex; align-items: center; gap: 12px;
}
.wl-hero h1 i { color: #e53935; }
.wl-hero h1 span { color: var(--accent-red); }
.wl-hero p { color: var(--text-muted); font-size: 0.88rem; margin-top: 4px; }

.wl-count-badge {
    background: rgba(229,57,53,0.1); color: #e53935; padding: 4px 12px;
    border-radius: 20px; font-size: 0.78rem; font-weight: 700;
}

/* Item Card */
.wl-card {
    display: flex; align-items: center; gap: 18px;
    padding: 18px 20px;
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    margin-bottom: 12px;
    transition: all 0.25s;
}
.wl-card:hover {
    border-color: rgba(255,255,255,0.12);
    box-shadow: 0 8px 30px rgba(0,0,0,0.2);
    transform: translateY(-2px);
}

.wl-thumb {
    width: 72px; height: 72px; border-radius: 12px; flex-shrink: 0;
    overflow: hidden; background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.06);
    display: flex; align-items: center; justify-content: center;
}
.wl-thumb img { width: 100%; height: 100%; object-fit: cover; }
.wl-thumb i { font-size: 1.5rem; color: var(--text-muted); }

.wl-info { flex: 1; min-width: 0; }
.wl-title {
    font-size: 1rem; font-weight: 700; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 4px;
}
.wl-title a { color: inherit; text-decoration: none; transition: color 0.2s; }
.wl-title a:hover { color: var(--accent-red); }

.wl-meta {
    font-size: 0.78rem; color: var(--text-muted);
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

.wl-price {
    font-size: 1.1rem; font-weight: 800; color: var(--accent-red);
    flex-shrink: 0; margin-right: 8px;
}

.wl-status-pill {
    padding: 3px 10px; border-radius: 20px;
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em;
}
.wl-status-pill.in-stock { background: rgba(76,175,80,0.12); color: #66bb6a; }
.wl-status-pill.sold-out { background: rgba(229,57,53,0.1); color: #ef5350; }

.wl-actions { display: flex; gap: 8px; flex-shrink: 0; align-items: center; }

.wl-btn {
    padding: 8px 16px; border-radius: 10px; font-size: 0.78rem;
    font-weight: 700; border: none; cursor: pointer; font-family: inherit;
    transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none;
}
.wl-btn-view { background: rgba(255,255,255,0.06); color: var(--text-primary); border: 1px solid rgba(255,255,255,0.1); }
.wl-btn-view:hover { background: rgba(255,255,255,0.1); }
.wl-btn-remove { background: rgba(229,57,53,0.08); color: #ef5350; }
.wl-btn-remove:hover { background: rgba(229,57,53,0.15); }

/* Empty */
.wl-empty { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.wl-empty i { font-size: 3.5rem; margin-bottom: 16px; opacity: 0.15; display: block; }
.wl-empty h3 { font-size: 1.1rem; color: var(--text-secondary); margin-bottom: 8px; }

@media (max-width: 640px) {
    .wl-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .wl-actions { width: 100%; justify-content: flex-end; }
    .wl-price { margin-right: 0; }
}
</style>

<div class="wl-page">
<div class="wl-inner">

    <div class="wl-hero" data-aos="fade-up">
        <h1><i class="fas fa-heart"></i> My <span>Wishlist</span></h1>
        <p>Items you're watching — you'll be notified when they're back in stock!
            <?php if (!empty($wishlistItems)): ?>
                <span class="wl-count-badge" style="margin-left:8px;"><?php echo count($wishlistItems); ?> item<?php echo count($wishlistItems) > 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </p>
    </div>

    <?php if (empty($wishlistItems)): ?>
        <div class="wl-empty" data-aos="fade-up">
            <i class="far fa-heart"></i>
            <h3>Your wishlist is empty</h3>
            <p>When an item is sold out, you can add it to your wishlist to get notified when it's back.</p>
            <a href="browse.php" style="margin-top:20px; display:inline-flex; align-items:center; gap:8px; background:var(--accent-red); color:#fff; padding:10px 24px; border-radius:10px; text-decoration:none; font-weight:700; font-size:0.9rem;">
                <i class="fas fa-search"></i> Browse Listings
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($wishlistItems as $item):
            $isBackInStock = ($item['status'] === 'active' && intval($item['stock'] ?? 0) > 0);
        ?>
        <div class="wl-card" data-aos="fade-up" id="wlCard-<?php echo $item['listing_id']; ?>">
            <div class="wl-thumb">
                <?php if (!empty($item['image'])): ?>
                    <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-car-side"></i>
                <?php endif; ?>
            </div>
            <div class="wl-info">
                <div class="wl-title"><a href="listing.php?id=<?php echo $item['listing_id']; ?>"><?php echo htmlspecialchars($item['title']); ?></a></div>
                <div class="wl-meta">
                    <span><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></span>
                    <span>·</span>
                    <span>by <?php echo htmlspecialchars($item['seller_name']); ?></span>
                    <span>·</span>
                    <?php if ($isBackInStock): ?>
                        <span class="wl-status-pill in-stock"><i class="fas fa-check-circle"></i> Back in Stock!</span>
                    <?php else: ?>
                        <span class="wl-status-pill sold-out"><i class="fas fa-clock"></i> Still Sold Out</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wl-price">Rs.<?php echo number_format($item['price'], 0); ?></div>
            <div class="wl-actions">
                <?php if ($isBackInStock): ?>
                    <a href="listing.php?id=<?php echo $item['listing_id']; ?>" class="wl-btn wl-btn-view" style="background:var(--accent-red); color:#fff; border:none;">
                        <i class="fas fa-shopping-bag"></i> Buy Now
                    </a>
                <?php else: ?>
                    <a href="listing.php?id=<?php echo $item['listing_id']; ?>" class="wl-btn wl-btn-view">
                        <i class="fas fa-eye"></i> View
                    </a>
                <?php endif; ?>
                <button class="wl-btn wl-btn-remove" onclick="removeFromWishlist(<?php echo $item['listing_id']; ?>)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
</div>

<script>
function removeFromWishlist(listingId) {
    const card = document.getElementById('wlCard-' + listingId);
    if (!card) return;
    card.style.opacity = '0.5';
    card.style.pointerEvents = 'none';
    
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('listing_id', listingId);
    
    fetch('api/wishlist.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                card.style.transition = 'all 0.3s';
                card.style.transform = 'translateX(100px)';
                card.style.opacity = '0';
                setTimeout(() => {
                    card.remove();
                    // Check if list is empty now
                    if (!document.querySelector('.wl-card')) {
                        location.reload();
                    }
                }, 300);
            } else {
                card.style.opacity = '1';
                card.style.pointerEvents = 'auto';
                alert(data.message || 'Error removing item');
            }
        })
        .catch(() => {
            card.style.opacity = '1';
            card.style.pointerEvents = 'auto';
            alert('Network error');
        });
}
</script>

<?php include 'includes/footer.php'; ?>
