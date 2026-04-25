<!-- Coming Soon Modal -->
<div class="cs-modal-overlay" id="comingSoonModal">
    <div class="cs-modal-card">
        <button class="cs-modal-close" onclick="closeComingSoonModal()" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        
        <div class="cs-modal-icon">
            <i class="fas fa-rocket"></i>
        </div>
        
        <h2 class="cs-modal-title">Unified Checkout</h2>
        <div class="cs-modal-badge">COMING SOON</div>
        <p class="cs-modal-desc">
            We're building a secure escrow payment system that will let you checkout all seller carts at once. Stay tuned!
        </p>
        <p class="cs-modal-hint">
            For now, checkout with each seller individually.
        </p>
        
        <button class="cs-modal-btn" onclick="closeComingSoonModal()">
            <i class="fas fa-check"></i> Got it
        </button>
    </div>
</div>

<style>
/* ===== Coming Soon Modal ===== */
.cs-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.35s ease, visibility 0.35s ease;
    padding: 20px;
}

.cs-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.cs-modal-card {
    position: relative;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 48px 36px 36px;
    max-width: 400px;
    width: 100%;
    text-align: center;
    transform: translateY(20px) scale(0.95);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6), 
                0 0 0 1px rgba(255, 255, 255, 0.05) inset;
    overflow: hidden;
}

.cs-modal-overlay.active .cs-modal-card {
    transform: translateY(0) scale(1);
}

/* Animated gradient border glow */
.cs-modal-card::before {
    content: '';
    position: absolute;
    top: -1px;
    left: -1px;
    right: -1px;
    height: 3px;
    background: linear-gradient(90deg, var(--accent-red), #ff6f61, #ff9a44, var(--accent-red));
    background-size: 200% 100%;
    animation: csGradientSlide 3s linear infinite;
    border-radius: 20px 20px 0 0;
}

@keyframes csGradientSlide {
    0% { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
}

.cs-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: var(--text-muted);
    font-size: 0.85rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.cs-modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
}

.cs-modal-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, rgba(229, 57, 53, 0.15), rgba(255, 111, 97, 0.1));
    border: 1px solid rgba(229, 57, 53, 0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: var(--accent-red);
    animation: csFloat 3s ease-in-out infinite;
}

@keyframes csFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}

.cs-modal-title {
    font-family: var(--font-brand, 'Outfit', sans-serif);
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 10px;
    letter-spacing: -0.02em;
}

.cs-modal-badge {
    display: inline-block;
    background: linear-gradient(135deg, var(--accent-red), #ff6f61);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    padding: 5px 14px;
    border-radius: 20px;
    margin-bottom: 16px;
}

.cs-modal-desc {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin: 0 0 8px;
}

.cs-modal-hint {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin: 0 0 24px;
    font-style: italic;
}

.cs-modal-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 32px;
    background: var(--accent-red);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.25s;
    box-shadow: 0 4px 16px rgba(229, 57, 53, 0.3);
}

.cs-modal-btn:hover {
    filter: brightness(1.15);
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(229, 57, 53, 0.4);
}

/* Light theme adjustments */
[data-theme="light"] .cs-modal-card {
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(0, 0, 0, 0.05) inset;
}

@media (max-width: 480px) {
    .cs-modal-card {
        padding: 40px 24px 28px;
    }
}
</style>

<script>
function openComingSoonModal() {
    document.getElementById('comingSoonModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeComingSoonModal() {
    document.getElementById('comingSoonModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close on overlay click
document.getElementById('comingSoonModal').addEventListener('click', function(e) {
    if (e.target === this) closeComingSoonModal();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('comingSoonModal').classList.contains('active')) {
        closeComingSoonModal();
    }
});
</script>
