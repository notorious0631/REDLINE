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
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: 40px;
    max-width: 1400px;
    margin: 0 auto;
}

@media (max-width: 992px) {
    .footer-grid {
        grid-template-columns: 1fr 1fr 1fr;
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
                <img src="assets/images/logo.jpeg" alt="REDLINE" style="width: 32px; height: 32px; border-radius: 4px;">
                <span style="font-family: var(--font-brand); font-weight: 800; font-size: 1.15rem; color: var(--text-primary); letter-spacing: 0.05em;">REDLINE</span>
            </div>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">© 2026 REDLINE. All rights reserved.</p>
        </div>

        <!-- Product -->
        <div class="footer-col">
            <h4 class="footer-title">Product</h4>
            <a href="browse.php" class="footer-link">Features</a>
            <a href="#" class="footer-link">Pricing</a>
            <a href="#" class="footer-link">Testimonials</a>
            <a href="#" class="footer-link">Integration</a>
        </div>

        <!-- Company -->
        <div class="footer-col">
            <h4 class="footer-title">Company</h4>
            <a href="#" class="footer-link">FAQs</a>
            <a href="#" class="footer-link">About Us</a>
            <a href="#" class="footer-link">Privacy Policy</a>
            <a href="#" class="footer-link">Terms of Services</a>
        </div>

        <!-- Resources -->
        <div class="footer-col">
            <h4 class="footer-title">Resources</h4>
            <a href="#" class="footer-link">Blog</a>
            <a href="#" class="footer-link">Changelog</a>
            <a href="#" class="footer-link">Brand</a>
            <a href="#" class="footer-link">Help</a>
        </div>

        <!-- Social Links -->
        <div class="footer-col">
            <h4 class="footer-title">Social Links</h4>
            <a href="<?php echo getSetting('facebook', '#'); ?>" class="footer-link" target="_blank"><i class="fab fa-facebook"></i> Facebook</a>
            <a href="<?php echo getSetting('instagram', '#'); ?>" class="footer-link" target="_blank"><i class="fab fa-instagram"></i> Instagram</a>
            <a href="<?php echo getSetting('youtube', '#'); ?>" class="footer-link" target="_blank"><i class="fab fa-youtube"></i> Youtube</a>
            <a href="<?php echo getSetting('twitter', '#'); ?>" class="footer-link" target="_blank"><i class="fab fa-twitter"></i> Twitter</a>
            <a href="<?php echo getSetting('linkedin', '#'); ?>" class="footer-link" target="_blank"><i class="fab fa-linkedin-in"></i> LinkedIn</a>
            <?php if ($ws = getSetting('whatsapp')): ?>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $ws); ?>" class="footer-link" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>
</footer>

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
