<?php
require_once 'config/db.php';
$page_title = "Features - Redline";
include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/home.css?v=<?php echo time(); ?>">

<style>
    .features-hero {
        background: linear-gradient(135deg, var(--bg-darker) 0%, var(--bg-dark) 100%);
        padding: 80px 20px;
        text-align: center;
        border-bottom: 1px solid var(--border-color);
    }

    .features-hero h1 {
        font-size: 3rem;
        font-weight: 800;
        margin-bottom: 20px;
        letter-spacing: -1px;
    }

    .features-hero h1 span.accent {
        color: var(--accent-red);
    }

    .features-hero p {
        color: var(--text-secondary);
        font-size: 1.2rem;
        max-width: 600px;
        margin: 0 auto;
        line-height: 1.6;
    }

    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 30px;
        padding: 60px 20px;
        max-width: 1200px;
        margin: 0 auto;
    }

    .feature-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 40px 30px;
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .feature-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        border-color: rgba(229, 57, 53, 0.3);
    }

    .feature-icon {
        width: 70px;
        height: 70px;
        background: rgba(229, 57, 53, 0.1);
        color: var(--accent-red);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 24px;
        transition: all 0.3s ease;
    }

    .feature-card:hover .feature-icon {
        background: var(--accent-red);
        color: white;
    }

    .feature-card h3 {
        font-size: 1.4rem;
        margin-bottom: 16px;
        color: var(--text-primary);
    }

    .feature-card p {
        color: var(--text-secondary);
        line-height: 1.6;
        font-size: 1rem;
    }

    .features-cta {
        text-align: center;
        padding: 60px 20px 80px;
        background: var(--bg-darker);
    }

    .features-cta h2 {
        font-size: 2rem;
        margin-bottom: 24px;
    }

    .features-cta .btn-red {
        font-size: 1.1rem;
        padding: 14px 32px;
    }

    @media (max-width: 768px) {
        .features-hero h1 {
            font-size: 2.5rem;
        }
        .features-grid {
            padding: 40px 20px;
        }
    }
</style>

<div class="features-hero" data-aos="fade-in">
    <h1>Why Choose <span class="accent">Redline?</span></h1>
    <p>India's premier marketplace designed exclusively for diecast collectors. Discover what makes us the best place to buy, sell, and trade.</p>
</div>

<div class="features-grid container-rl">
    
    <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
        <div class="feature-icon">
            <i class="fas fa-car"></i>
        </div>
        <h3>Dedicated Marketplace</h3>
        <p>Built specifically for diecast enthusiasts. Find Hot Wheels, Mini GT, Tomica, Matchbox, and premium lines all in one organized platform.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
        <div class="feature-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h3>Verified Sellers</h3>
        <p>Every seller undergoes a strict KYC verification process. Buy with confidence knowing you are dealing with trusted members of the community.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
        <div class="feature-icon">
            <i class="fas fa-comments"></i>
        </div>
        <h3>Real-time Negotiation</h3>
        <p>Found something you like but want a better deal? Use our built-in chat system to negotiate directly with sellers and agree on a fair price.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
        <div class="feature-icon">
            <i class="fas fa-rupee-sign"></i>
        </div>
        <h3>Instant UPI Payments</h3>
        <p>Seamless and secure transactions. Pay directly via UPI for instant order confirmation without any hidden platform fees.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="450">
        <div class="feature-icon">
            <i class="fas fa-sync-alt"></i>
        </div>
        <h3>Payment Cycle</h3>
        <p>Currently, the seller receives payments directly via UPI. During checkout, the seller's UPI ID is displayed in the payment workflow. The buyer completes the payment and uploads a screenshot as proof. The seller then reviews and confirms the payment — only after confirmation is the order considered placed.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="500">
        <div class="feature-icon">
            <i class="fas fa-truck"></i>
        </div>
        <h3>Order Tracking</h3>
        <p>Stay updated on your purchases. Sellers upload tracking information directly to the platform so you always know where your diecast is.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="600">
        <div class="feature-icon">
            <i class="fas fa-gavel"></i>
        </div>
        <h3>Dispute Resolution</h3>
        <p>In the rare event something goes wrong, our robust dispute system and dedicated admins are here to ensure a fair resolution for everyone.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="900">
        <div class="feature-icon">
            <i class="fas fa-bell"></i>
        </div>
        <h3>Alert System</h3>
        <p>Get email alerts when a seller you follow lists a new item or on restocks of a sold out listing you wishlisted.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="700">
        <div class="feature-icon">
            <i class="fas fa-gift"></i>
        </div>
        <h3>Zero Hosting & Ad Costs</h3>
        <p>Sellers get a full-fledged website with built-in chat — without paying a rupee. Save ₹6,000–10,000/year on hosting, and let REDLINE handle the ads for you.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="800">
        <div class="feature-icon">
            <i class="fas fa-star-half-alt"></i>
        </div>
        <h3>Reviews & Ratings System</h3>
        <p>A fully integrated reviews and ratings system. Sellers can also showcase past customer feedback on their storefront highlights to build trust and credibility.</p>
    </div>

</div>

<div class="features-hero" style="padding: 60px 20px 20px; background: transparent; border-bottom: none; border-top: 1px solid var(--border-color);" data-aos="fade-in">
    <h2 style="font-size: 2.5rem; font-weight: 800; margin-bottom: 16px;">Upcoming <span class="accent">Features</span></h2>
    <p>We are constantly evolving. Here is a sneak peek at what's coming next to Redline.</p>
</div>

<div class="features-grid container-rl" style="padding-top: 20px;">
    
    <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
        <div class="feature-icon" style="background: rgba(79, 195, 247, 0.1); color: #4fc3f7;">
            <i class="fas fa-handshake"></i>
        </div>
        <h3>Escrow Payment System</h3>
        <p>Buyer pays 100% upfront but the seller gets 50% before shipping and the rest after delivery. Buyers feel safe knowing about this payment breakup, and sellers get guaranteed payment.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
        <div class="feature-icon" style="background: rgba(171, 71, 188, 0.1); color: #ab47bc;">
            <i class="fas fa-users"></i>
        </div>
        <h3>Community Chat</h3>
        <p>Connect with other collectors, share your collections, discuss new releases, and build your network in public community chat rooms.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
        <div class="feature-icon" style="background: rgba(255, 183, 77, 0.1); color: #ffb74d;">
            <i class="fas fa-shipping-fast"></i>
        </div>
        <h3>Delivery Service by REDLINE</h3>
        <p>Official REDLINE logistics. Enjoy discounted shipping rates, automated tracking updates, and guaranteed safe handling of your models.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
        <div class="feature-icon" style="background: rgba(229, 57, 53, 0.1); color: #e53935;">
            <i class="fas fa-ban"></i>
        </div>
        <h3>No RTO Issues</h3>
        <p>If a buyer rejects the delivery after two attempts, they get charged a penalty. Sellers face no RTO issues since buyers know only 50% of the amount will be refunded.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="500">
        <div class="feature-icon" style="background: rgba(0, 230, 118, 0.1); color: #00e676;">
            <i class="fas fa-robot"></i>
        </div>
        <h3>AI Negotiation Bot</h3>
        <p>No more time wasted on non-serious buyers — AI will filter them out for you. Simply set a minimum amount you're willing to accept, and AI takes care of the rest.</p>
    </div>

    <div class="feature-card" data-aos="fade-up" data-aos-delay="600">
        <div class="feature-icon" style="background: rgba(255, 112, 67, 0.1); color: #ff7043;">
            <i class="fas fa-camera-retro"></i>
        </div>
        <h3>AI Inventory Creation</h3>
        <p>Just click a single photo of your whole stock and our AI will segregate and create detailed listings from it. No manual work needed.</p>
    </div>

</div>

<div class="features-cta" data-aos="fade-in">
    <h2>Ready to build your collection?</h2>
    <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
        <a href="browse.php" class="btn-red">Browse Listings</a>
        <a href="sell.php" class="btn-outline-white" style="display: inline-block; padding: 12px 24px; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); text-decoration: none; font-weight: 600; transition: all 0.2s;">Start Selling</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
