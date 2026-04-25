/**
 * REDLINE — Global JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {

    // ===== BUYER / SELLER TOGGLE =====
    const roleToggle = document.getElementById('roleToggle');
    if (roleToggle) {
        roleToggle.addEventListener('click', function() {
            this.classList.toggle('seller-active');
            const labels = this.querySelectorAll('.role-label');
            const isSeller = this.classList.contains('seller-active');

            labels[0].classList.toggle('active', !isSeller); // BUYER
            labels[1].classList.toggle('active', isSeller);  // SELLER

            // Persist via AJAX (optional, no backend endpoint yet)
            // fetch('api/toggle_role.php', { method: 'POST', body: JSON.stringify({ role: isSeller ? 'seller' : 'buyer' }) });
        });
    }

    // ===== SEARCH FUNCTIONALITY =====
    const searchInput = document.getElementById('navSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    window.location.href = 'browse.php?search=' + encodeURIComponent(query);
                }
            }
        });
    }

    // ===== NAVBAR SCROLL EFFECT =====
    const navbar = document.querySelector('.rl-navbar');
    if (navbar) {
        let lastScrollY = 0;
        window.addEventListener('scroll', function() {
            const scrollY = window.scrollY;
            if (scrollY > 100) {
                navbar.style.borderBottomColor = 'rgba(255,255,255,0.05)';
                navbar.style.background = 'rgba(20,20,20,0.95)';
            } else {
                navbar.style.borderBottomColor = '';
                navbar.style.background = '';
            }
            lastScrollY = scrollY;
        });
    }

});
