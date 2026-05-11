<?php 
$currentPage = basename($_SERVER['PHP_SELF']);
$cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
?>

<!-- ===== DESKTOP FOOTER ===== -->
<style>
/* New Desktop Footer */
.desktop-footer {
    background: var(--bg-surface);
    border-top: 1px solid var(--border-color);
    padding: 64px 32px 48px;
    margin-top: 48px;
}

.footer-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 40px;
    max-width: 1400px;
    margin: 0 auto;
}

@media (max-width: 992px) {
    .footer-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 768px) {
    .footer-grid {
        grid-template-columns: 1fr 1fr;
    }
    .desktop-footer {
        padding: 48px 16px 32px;
    }
}

.footer-brand {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.footer-col {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.footer-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.footer-link {
    font-size: 0.85rem;
    font-weight: 400;
    color: var(--text-secondary);
    text-decoration: none;
    transition: color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 10px;
}

.footer-link:hover {
    color: var(--text-primary);
}

.footer-link i {
    font-size: 1.1rem;
    color: var(--text-muted);
    transition: color 0.2s ease;
}

.footer-link:hover i {
    color: var(--text-primary);
}
</style>

<!-- ===== DESKTOP FOOTER ===== -->
<footer class="desktop-footer">
    <div class="footer-grid">
        <!-- Logo & Copyright -->
        <div class="footer-brand" style="grid-column: span 1; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <img src="assets/images/logo.png" alt="REDLINER" style="width: 32px; height: 32px; border-radius: 4px;">
                <span style="font-family: var(--font-brand); font-weight: 800; font-size: 1.15rem; color: var(--text-primary); letter-spacing: 0.05em;">REDLINER</span>
            </div>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.5;">India's premier marketplace for diecast collectors. Buy, sell, and trade with confidence.</p>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">© 2026 REDLINER. All rights reserved.</p>
        </div>

        <!-- Quick Links -->
        <div class="footer-col">
            <h4 class="footer-title">Quick Links</h4>
            <a href="features.php" class="footer-link"><i class="fas fa-star" style="font-size:0.8rem;"></i> Features</a>
            <a href="browse.php" class="footer-link"><i class="fas fa-search" style="font-size:0.8rem;"></i> Browse</a>
            <a href="sell.php" class="footer-link"><i class="fas fa-store" style="font-size:0.8rem;"></i> Sell</a>
            <a href="CONTACT.php" class="footer-link"><i class="fas fa-envelope" style="font-size:0.8rem;"></i> Contact Us</a>
        </div>

        <!-- Company & Legal -->
        <div class="footer-col">
            <h4 class="footer-title">Company & Legal</h4>
            <a href="dispute.php" class="footer-link"><i class="fas fa-life-ring" style="font-size:0.8rem;"></i> Help & Support</a>
            <a href="apply_seller.php" class="footer-link"><i class="fas fa-user-plus" style="font-size:0.8rem;"></i> Become a Seller</a>
            <a href="privacy.php" class="footer-link"><i class="fas fa-shield-alt" style="font-size:0.8rem;"></i> Privacy Policy</a>
            <a href="terms.php" class="footer-link"><i class="fas fa-file-contract" style="font-size:0.8rem;"></i> Terms of Service</a>
            <a href="seller_agreement.php" class="footer-link"><i class="fas fa-handshake" style="font-size:0.8rem;"></i> Seller Agreement</a>
        </div>

        <!-- Social Links -->
        <div class="footer-col">
            <h4 class="footer-title">Social Links</h4>
            <a href="https://www.instagram.com/redline251125/" class="footer-link" target="_blank"><i class="fab fa-instagram"></i> Instagram</a>
            <?php if ($ws = getSetting('whatsapp')): ?>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $ws); ?>" class="footer-link" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<!-- ===== FLOATING WHATSAPP BUTTON ===== -->
<?php $waNum = getSetting('whatsapp', ''); if ($waNum): $waClean = preg_replace('/[^0-9]/', '', $waNum); ?>
<a href="https://wa.me/<?php echo $waClean; ?>?text=Hi%20REDLINER%2C%20I%20have%20a%20query!" target="_blank" class="wa-float" id="waFloat" aria-label="Chat on WhatsApp">
    <i class="fab fa-whatsapp"></i>
    <span class="wa-tooltip">Chat with us</span>
</a>
<style>
.wa-float {
    position: fixed;
    bottom: 28px;
    right: 24px;
    z-index: 10000;
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: #25d366;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    box-shadow: 0 4px 18px rgba(37, 211, 102, 0.45), 0 2px 8px rgba(0,0,0,0.2);
    text-decoration: none;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    animation: waPulse 2.5s ease-in-out infinite;
}
.wa-float:hover {
    transform: scale(1.12);
    box-shadow: 0 6px 24px rgba(37, 211, 102, 0.55), 0 4px 12px rgba(0,0,0,0.25);
    animation: none;
}
.wa-float:hover .wa-tooltip {
    opacity: 1;
    transform: translateX(-100%) translateX(-12px) translateY(-50%);
    pointer-events: auto;
}
.wa-tooltip {
    position: absolute;
    top: 50%;
    right: 0;
    transform: translateX(-100%) translateX(-12px) translateY(-50%);
    background: #1a1a2e;
    color: #fff;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease, transform 0.25s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
}
.wa-tooltip::after {
    content: '';
    position: absolute;
    top: 50%;
    right: -6px;
    transform: translateY(-50%);
    border: 6px solid transparent;
    border-left-color: #1a1a2e;
}
@keyframes waPulse {
    0%, 100% { box-shadow: 0 4px 18px rgba(37,211,102,0.45), 0 0 0 0 rgba(37,211,102,0.3); }
    50% { box-shadow: 0 4px 18px rgba(37,211,102,0.45), 0 0 0 12px rgba(37,211,102,0); }
}
/* On mobile, lift above bottom nav */
@media (max-width: 768px) {
    .wa-float {
        bottom: 84px;
        right: 16px;
        width: 52px;
        height: 52px;
        font-size: 1.5rem;
    }
}
</style>
<?php endif; ?>

<?php include __DIR__ . '/coming_soon_modal.php'; ?>

<!-- ===== MOBILE BOTTOM NAV ===== -->
<nav class="bottom-nav" id="bottomNav">
    <div class="bottom-nav-container">
        <a href="index.php" class="bottom-nav-item <?php echo $currentPage == 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="browse.php" class="bottom-nav-item <?php echo $currentPage == 'browse.php' ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i>
            <span>Browse</span>
        </a>
        <a href="sell.php" class="bottom-nav-item <?php echo $currentPage == 'sell.php' ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i>
            <span>Sell</span>
        </a>
        <a href="cart.php" class="bottom-nav-item <?php echo $currentPage == 'cart.php' ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i>
            <span>Cart</span>
            <?php if($cartCount > 0): ?>
                <span class="nav-badge"><?php echo $cartCount; ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo isset($_SESSION['user_id']) ? 'profile.php' : 'login.php'; ?>" class="bottom-nav-item <?php echo in_array($currentPage, ['profile.php', 'login.php']) ? 'active' : ''; ?>">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
    </div>
</nav>

<!-- MDB JS -->
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/7.1.0/mdb.umd.min.js"></script>

<!-- Mobile Navigation Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile Drawer Toggle
    const menuBtn = document.getElementById('mobileMenuBtn');
    const drawer = document.getElementById('mobileDrawer');
    const overlay = document.getElementById('drawerOverlay');
    
    if (menuBtn && drawer && overlay) {
        menuBtn.addEventListener('click', function() {
            drawer.classList.add('active');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        
        overlay.addEventListener('click', closeDrawer);
        
        function closeDrawer() {
            drawer.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDrawer();
        });
    }
    
    // Bottom nav footer spacing
    function handleResize() {
        const footer = document.querySelector('.desktop-footer');
        if (window.innerWidth <= 768) {
            if (footer) footer.style.marginBottom = '72px';
        } else {
            if (footer) footer.style.marginBottom = '0';
        }
    }
    
    handleResize();
    window.addEventListener('resize', handleResize);
});
</script>

<!-- Custom Scripts -->
<script src="assets/js/script.js"></script>
</body>
</html>
