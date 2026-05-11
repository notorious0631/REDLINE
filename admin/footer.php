</main><!-- /admin-main -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (toggle) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }
});
</script>
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
    z-index: 9999;
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
}
.wa-float:hover .wa-tooltip {
    opacity: 1;
    transform: translateX(-100%) translateX(-12px) translateY(-50%);
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
@keyframes waPulse {
    0%, 100% { box-shadow: 0 4px 18px rgba(37,211,102,0.45), 0 0 0 0 rgba(37,211,102,0.3); }
    50% { box-shadow: 0 4px 18px rgba(37,211,102,0.45), 0 0 0 12px rgba(37,211,102,0); }
}
</style>
<?php endif; ?>
</body>
</html>
