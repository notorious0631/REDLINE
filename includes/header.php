<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentPage = basename($_SERVER['PHP_SELF']);
$cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
$currentRole = isset($_SESSION['role_mode']) ? $_SESSION['role_mode'] : 'buyer';

$unreadNotifications = 0;
$recentNotifications = [];
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unreadNotifications = $stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['user_id']]);
        $recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Google Search Console Verification -->
    <meta name="google-site-verification" content="VteUJUK_aaMhz62Gk2giLo7lFpQBPaAvnxikFQpS4u4">
    <?php
    $baseUrl = getBaseUrl();
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";

    $defaultTitle = "REDLINER — India's Premier Diecast Marketplace";
    $defaultDesc = "Buy and sell Hot Wheels, Mini GT, Tomica, Matchbox and premium diecast collectibles. Verified sellers, fair prices, safe delivery across India.";
    
    $seoTitle = $pageTitle ?? $defaultTitle;
    $seoDesc = $pageDescription ?? $defaultDesc;
    $seoImage = $pageOgImage ?? $baseUrl . "/assets/images/logo.png";
    $seoLogoImage = $baseUrl . "/assets/images/logo.png";
    $seoUrl = $canonicalUrl ?? $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $isHomepage = (basename($_SERVER['PHP_SELF']) === 'index.php');
    ?>
    <title><?php echo htmlspecialchars($seoTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($seoDesc); ?>">
    <meta name="robots" content="index, follow">
    <meta name="author" content="REDLINER">

    <!-- hreflang for India -->
    <link rel="alternate" hreflang="en-IN" href="<?php echo htmlspecialchars($seoUrl); ?>">
    <link rel="alternate" hreflang="en" href="<?php echo htmlspecialchars($seoUrl); ?>">

    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:type" content="<?php echo $isHomepage ? 'website' : 'article'; ?>">
    <meta property="og:site_name" content="REDLINER">
    <meta property="og:locale" content="en_IN">
    <meta property="og:url" content="<?php echo htmlspecialchars($seoUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seoDesc); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($seoImage); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <!-- Logo for Google Knowledge Panel -->
    <meta property="og:logo" content="<?php echo htmlspecialchars($seoLogoImage); ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:site" content="@redliner_in">
    <meta property="twitter:url" content="<?php echo htmlspecialchars($seoUrl); ?>">
    <meta property="twitter:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta property="twitter:description" content="<?php echo htmlspecialchars($seoDesc); ?>">
    <meta property="twitter:image" content="<?php echo htmlspecialchars($seoImage); ?>">
    
    <!-- Canonical & Base URL for Routing -->
    <link rel="canonical" href="<?php echo htmlspecialchars($seoUrl); ?>">
    <base href="<?php echo $baseUrl; ?>/">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo $baseUrl; ?>/assets/images/logo.png">
    <link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>/assets/images/logo.png">

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="//unpkg.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=Poppins:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- AOS Animation CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- MDB UI Kit -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.1.0/mdb.min.css" rel="stylesheet" />
    <!-- Swiper.js -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <!-- Custom CSS (versioned for cache control) -->
    <link rel="stylesheet" href="assets/css/style.css?v=2.5.0">
    <link rel="stylesheet" href="assets/css/mobile-nav.css?v=2.5.0">
    <!-- Eager Theme Load to prevent FOUC -->
    <script>
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    </script>

    <?php if ($isHomepage): ?>
    <!-- WebSite + SearchAction Schema (Homepage only) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "REDLINER",
      "alternateName": "Redliner Diecast Marketplace",
      "url": "<?php echo $baseUrl; ?>/",
      "description": "<?php echo addslashes($defaultDesc); ?>",
      "inLanguage": "en-IN",
      "potentialAction": {
        "@type": "SearchAction",
        "target": {
          "@type": "EntryPoint",
          "urlTemplate": "<?php echo $baseUrl; ?>/browse.php?q={search_term_string}"
        },
        "query-input": "required name=search_term_string"
      }
    }
    </script>
    <!-- Organization + Logo Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "REDLINER",
      "url": "<?php echo $baseUrl; ?>/",
      "logo": {
        "@type": "ImageObject",
        "url": "<?php echo $seoLogoImage; ?>",
        "width": 512,
        "height": 512
      },
      "sameAs": [
        "https://www.instagram.com/redliner.in",
        "https://www.facebook.com/redliner.in"
      ],
      "areaServed": "IN",
      "description": "India's premier marketplace for diecast collectibles — Hot Wheels, Mini GT, Tomica, Matchbox and more."
    }
    </script>
    <?php endif; ?>
</head>
<body>

<!-- ===== MOBILE APPBAR ===== -->
<header class="mobile-appbar">
    <a href="index.php" class="appbar-logo">
        <img src="assets/images/logo.png" alt="REDLINER">
        <span>REDLINER</span>
    </a>
    <div class="appbar-actions">
        <!-- Theme Toggle Switch -->
        <label class="switch theme-switch" title="Toggle Theme" style="margin-left:8px; display:flex; align-items:center;">
          <input type="checkbox" class="theme-toggle-checkbox">
          <span class="slider"></span>
        </label>
        <a href="cart.php" class="appbar-btn" style="position: relative;">
            <i class="fas fa-shopping-cart"></i>
            <?php if($cartCount > 0): ?>
                <span class="cart-badge"><?php echo $cartCount; ?></span>
            <?php endif; ?>
        </a>
        <button class="appbar-btn" id="mobileMenuBtn" aria-label="Open Menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
</header>

<!-- ===== MOBILE DRAWER OVERLAY ===== -->
<div class="mobile-drawer-overlay" id="drawerOverlay"></div>

<!-- ===== MOBILE DRAWER MENU ===== -->
<nav class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-drawer-header">
        <img src="assets/images/logo.png" alt="REDLINER">
        <div class="user-info">
            <?php if(isset($_SESSION['user_id'])): ?>
                <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                <span><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Collector'); ?></span>
            <?php else: ?>
                <h4>Welcome!</h4>
                <span>Sign in to continue</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="mobile-drawer-nav">
        <a href="index.php" class="<?php echo $currentPage == 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Home
        </a>
        <a href="browse.php" class="<?php echo $currentPage == 'browse.php' ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Browse
        </a>
        <a href="cart.php" class="<?php echo $currentPage == 'cart.php' ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i> Cart
            <?php if($cartCount > 0): ?>
                <span style="margin-left: auto; background: var(--accent-red); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;"><?php echo $cartCount; ?></span>
            <?php endif; ?>
        </a>
        <?php if(isset($_SESSION['user_id'])): ?>
        <a href="wishlist.php" class="<?php echo $currentPage == 'wishlist.php' ? 'active' : ''; ?>">
            <i class="fas fa-heart"></i> Wishlist
        </a>
        <?php endif; ?>
        <div class="mobile-drawer-divider"></div>
        <?php if(isset($_SESSION['user_id'])): ?>
            <a href="profile.php" class="<?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> My Profile
            </a>
            <a href="notifications.php" class="<?php echo $currentPage == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i> Notifications
                <?php if($unreadNotifications > 0): ?>
                    <span style="margin-left: auto; background: var(--accent-red); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;"><?php echo $unreadNotifications; ?></span>
                <?php endif; ?>
            </a>
            <?php if(isset($_SESSION['role']) && ($_SESSION['role'] === 'seller' || $_SESSION['role'] === 'admin')): ?>
            <a href="seller_dashboard/" class="<?php echo $currentPage == 'seller_dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-store"></i> Seller Dashboard
            </a>
            <a href="seller.php?id=<?php echo $_SESSION['user_id']; ?>" class="<?php echo $currentPage == 'seller.php' ? 'active' : ''; ?>">
                <i class="fas fa-external-link-alt"></i> View Public Profile
            </a>

            <?php else: ?>
            <a href="apply_seller.php" class="<?php echo $currentPage == 'apply_seller.php' ? 'active' : ''; ?>">
                <i class="fas fa-id-card"></i> Become a Seller
            </a>
            <?php endif; ?>
            <a href="negotiate.php" class="<?php echo $currentPage == 'negotiate.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i> Negotiations
            </a>
            <a href="disputes.php" class="<?php echo $currentPage == 'disputes.php' ? 'active' : ''; ?>">
                <i class="fas fa-life-ring"></i> Support Tickets
            </a>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="mobile-drawer-divider"></div>
                <a href="admin/index.php">
                    <i class="fas fa-cog"></i> Admin Dashboard
                </a>
            <?php endif; ?>
            <div class="mobile-drawer-divider"></div>
            <a href="logout.php" style="color: var(--accent-red);">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        <?php else: ?>
            <a href="login.php" class="<?php echo $currentPage == 'login.php' ? 'active' : ''; ?>">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </a>
            <a href="signup.php" class="<?php echo $currentPage == 'signup.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-plus"></i> Create Account
            </a>
        <?php endif; ?>
    </div>
</nav>

<!-- ===== DESKTOP NAVBAR ===== -->
<nav class="rl-navbar">
    <div class="navbar-inner">
        <!-- Logo -->
        <a href="index.php" class="nav-logo">
            <img src="assets/images/logo.png" alt="REDLINER" class="logo-icon">
            <span class="logo-text">REDLINER</span>
        </a>

        <!-- Search -->
        <div class="nav-search">
            <i class="fas fa-search search-icon"></i>
            <input type="text" placeholder="Search diecast models..." id="navSearchInput">
        </div>

        <!-- Actions -->
        <div class="nav-actions">
            <!-- Theme Toggle -->
            <!-- Theme Toggle Switch -->
            <label class="switch theme-switch" title="Toggle Theme" style="margin-left:14px; display:flex; align-items:center;">
              <input type="checkbox" class="theme-toggle-checkbox">
              <span class="slider"></span>
            </label>

            <a href="browse.php" class="nav-link-item">BROWSE</a>

            <?php if(isset($_SESSION['role']) && ($_SESSION['role'] === 'buyer')): ?>
                <a href="apply_seller.php" class="nav-link-item" style="color:var(--accent-red); font-weight:700;"><i class="fas fa-store"></i> SELL</a>
            <?php elseif(!isset($_SESSION['user_id'])): ?>
                <a href="login.php" class="nav-link-item" style="color:var(--accent-red); font-weight:700;"><i class="fas fa-store"></i> SELL</a>
            <?php else: ?>
                <!-- Seller Dashboard Link -->
                <a href="seller.php?id=<?php echo $_SESSION['user_id']; ?>" class="nav-link-item" style="color:rgba(255,255,255,0.9); font-weight:700; margin-right:12px; border:1px solid rgba(255,255,255,0.15); padding:6px 12px; border-radius:8px; background:rgba(255,255,255,0.05);"><i class="fas fa-user-circle"></i> PUBLIC PROFILE</a>
                <a href="seller_dashboard/" class="nav-link-item" style="color:var(--accent-red); font-weight:700;"><i class="fas fa-chart-line"></i> DASHBOARD</a>


            <?php endif; ?>

            <!-- Cart -->
            <a href="cart.php" class="nav-icon-btn" title="Cart">
                <i class="fas fa-shopping-cart"></i>
                <?php if($cartCount > 0): ?>
                    <span class="badge-count"><?php echo $cartCount; ?></span>
                <?php endif; ?>
            </a>

            <!-- Wishlist -->
            <?php if(isset($_SESSION['user_id'])): ?>
            <a href="profile.php?section=wishlist" class="nav-icon-btn" title="Wishlist">
                <i class="fas fa-heart"></i>
            </a>
            <?php endif; ?>

            <!-- Notifications -->
            <?php if(isset($_SESSION['user_id'])): ?>
            <div class="nav-item dropdown">
                <a href="#" class="nav-icon-btn dropdown-toggle hidden-arrow" id="navbarNotifications" role="button" data-mdb-dropdown-init aria-expanded="false" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if($unreadNotifications > 0): ?>
                        <span class="badge-count" id="notifBadgeCount"><?php echo $unreadNotifications; ?></span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end notif-dropdown-menu" aria-labelledby="navbarNotifications">
                    <li class="notif-header">
                        <span>Notifications</span>
                        <?php if($unreadNotifications > 0): ?>
                        <button type="button" class="btn-mark-read" onclick="markNotificationsRead()">Mark all read</button>
                        <?php endif; ?>
                    </li>
                    <div class="notif-body">
                        <?php if(empty($recentNotifications)): ?>
                            <li class="notif-empty">No new notifications</li>
                        <?php else: ?>
                            <?php foreach($recentNotifications as $notif): ?>
                                <li style="position: relative;">
                                    <a class="dropdown-item notif-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>" href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>">
                                        <div class="notif-icon"><i class="fas fa-info-circle"></i></div>
                                        <div class="notif-text">
                                            <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <span class="notif-time"><?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?></span>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <li><a href="notifications.php" style="display:block;text-align:center;padding:12px;font-size:0.85rem;color:var(--text-primary);font-weight:600;border-top:1px solid rgba(255,255,255,0.05);text-decoration:none;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">View All Notifications</a></li>
                </ul>
            </div>
            <?php else: ?>
            <a href="login.php" class="nav-icon-btn" title="Notifications">
                <i class="fas fa-bell"></i>
            </a>
            <?php endif; ?>

            <!-- Chat/Negotiations -->
            <?php if(isset($_SESSION['user_id'])): ?>
            <a href="disputes.php" class="nav-icon-btn" title="Support Tickets">
                <i class="fas fa-life-ring"></i>
            </a>    
            <a href="negotiate.php" class="nav-icon-btn" title="Negotiations" style="position:relative;">
                <i class="fas fa-comments"></i>
                <span class="chat-badge-count" id="navChatBadge" style="display:none;"></span>
            </a>
            <?php endif; ?>

            <!-- User -->
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="profile.php" class="nav-icon-btn" title="Profile">
                    <i class="fas fa-user"></i>
                </a>
                <a href="logout.php" class="nav-icon-btn" title="Logout" style="color: var(--accent-red);">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            <?php else: ?>
                <a href="login.php" class="nav-icon-btn" title="Sign In">
                    <i class="fas fa-user"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Navbar Spacer -->
<div class="navbar-spacer"></div>

<!-- AOS Script -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        AOS.init({
            duration: 700,
            once: true,
            offset: 40
        });
    });

    function markNotificationsRead() {
        fetch('api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mark_read'
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                let badge = document.getElementById('notifBadgeCount');
                if(badge) badge.style.display = 'none';
                document.querySelectorAll('.notif-item.unread').forEach(item => {
                    item.classList.remove('unread');
                    item.classList.add('read');
                });
                let markBtn = document.querySelector('.btn-mark-read');
                if(markBtn) markBtn.style.display = 'none';
            }
        });
    }
</script>

<style>
/* Notification Dropdown Styles */
.notif-dropdown-menu {
    width: 320px;
    padding: 0;
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    overflow: hidden;
    margin-top: 10px !important;
}
.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    font-weight: 700;
    color: var(--text-primary);
    background: rgba(255,255,255,0.02);
}
.btn-mark-read {
    background: transparent;
    border: none;
    color: var(--accent-red);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
}
.btn-mark-read:hover {
    text-decoration: underline;
}
.notif-body {
    max-height: 350px;
    overflow-y: auto;
}
.notif-empty {
    padding: 30px 20px;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.9rem;
}
.notif-item {
    display: flex;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    color: var(--text-primary);
    white-space: normal;
    transition: background 0.2s;
}
.notif-item:last-child {
    border-bottom: none;
}
.notif-item:hover, .notif-item:focus {
    background: rgba(255,255,255,0.05);
    color: var(--text-primary);
}
.notif-item.unread {
    background: rgba(229,57,53,0.05);
}
.notif-item.unread:hover {
    background: rgba(229,57,53,0.1);
}
.notif-item.unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--accent-red);
}
.notif-icon {
    margin-top: 2px;
    color: var(--accent-red);
    font-size: 1.1rem;
}
.notif-text p {
    margin: 0 0 4px 0;
    font-size: 0.85rem;
    line-height: 1.4;
}
.notif-time {
    font-size: 0.7rem;
    color: var(--text-muted);
}
</style>

<?php if(isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) !== 'negotiate.php'): ?>
<script>
(function(){
    // Global chat badge updater (runs on all pages except negotiate.php)
    function updateChatBadge(){
        fetch('api/chat_v2.php?action=unread_count')
            .then(r=>r.json())
            .then(d=>{
                const b=document.getElementById('navChatBadge');
                if(b){
                    if(d.success&&d.count>0){b.textContent=d.count;b.style.display='flex';}
                    else{b.style.display='none';}
                }
            }).catch(()=>{});
    }
    // Global heartbeat for online presence
    function heartbeat(){
        const fd=new FormData();fd.append('action','heartbeat');
        fetch('api/chat_v2.php',{method:'POST',body:fd}).catch(()=>{});
    }
    updateChatBadge();
    heartbeat();
    setInterval(updateChatBadge,15000);
    setInterval(heartbeat,30000);
})();
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const themeCheckboxes = document.querySelectorAll('.theme-toggle-checkbox');
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
    
    themeCheckboxes.forEach(cb => {
        // Set initial state: checkbox checked if light theme
        cb.checked = (currentTheme === 'light');
        
        cb.addEventListener('change', (e) => {
            let theme = e.target.checked ? 'light' : 'dark';
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'dark');
            }
            
            // Sync all checkboxes if multiple exist
            themeCheckboxes.forEach(box => {
                if (box !== e.target) box.checked = e.target.checked;
            });
        });
    });
});
</script>

