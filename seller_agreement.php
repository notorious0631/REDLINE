<?php
require_once 'config/db.php';
$page_title = "Seller Agreement - Redliner";
include 'includes/header.php';
?>

<style>
    .legal-hero {
        background: linear-gradient(135deg, var(--bg-darker) 0%, var(--bg-dark) 100%);
        padding: 60px 20px;
        text-align: center;
        border-bottom: 1px solid var(--border-color);
    }
    .legal-hero h1 {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 10px;
        color: var(--text-primary);
    }
    .legal-hero p {
        color: var(--text-secondary);
        font-size: 1.1rem;
    }
    .legal-content {
        max-width: 800px;
        margin: 0 auto;
        padding: 60px 20px;
        color: var(--text-secondary);
        line-height: 1.8;
    }
    .legal-content h2 {
        color: var(--text-primary);
        font-size: 1.5rem;
        margin-top: 40px;
        margin-bottom: 16px;
        font-weight: 700;
    }
    .legal-content h3 {
        color: var(--text-primary);
        font-size: 1.2rem;
        margin-top: 24px;
        margin-bottom: 12px;
        font-weight: 600;
    }
    .legal-content p {
        margin-bottom: 16px;
    }
    .legal-content ul {
        margin-bottom: 24px;
        padding-left: 20px;
    }
    .legal-content li {
        margin-bottom: 8px;
    }
    .highlight-box {
        background: rgba(229,57,53,0.05);
        border: 1px solid rgba(229,57,53,0.2);
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 24px;
    }
    .highlight-box h3 {
        margin-top: 0;
        color: var(--accent-red);
    }
</style>

<div class="legal-hero" data-aos="fade-in">
    <h1>Seller Agreement</h1>
    <p>Last updated: <?php echo date('F j, Y'); ?></p>
</div>

<div class="legal-content container-rl" data-aos="fade-up">
    <div class="highlight-box">
        <h3>Critical Requirements for REDLINER Sellers</h3>
        <p style="margin-bottom:0;">Becoming a seller on REDLINER is a privilege. To maintain a safe and premium marketplace for diecast collectors, all sellers are strictly bound by the rules outlined in this document. Violations may result in permanent suspension of seller privileges.</p>
    </div>

    <h2>1. Commission Structure & Fees</h2>
    <p>REDLINER is committed to supporting the collector community by providing an affordable platform to scale your business.</p>
    <ul>
        <li><strong>Current Commission Rate: 0%</strong>. We currently do not charge any commission on sales made through the platform.</li>
        <li><strong>Hosting & Ads:</strong> Sellers receive a personalized storefront, built-in chat, and exposure through REDLINER's marketing channels at zero cost.</li>
        <li><strong>Payment Processing Fees:</strong> Since REDLINER uses a direct P2P UPI payment model, there are no payment gateway fees. You receive 100% of the sale amount directly into your account.</li>
    </ul>

    <h2>2. Listing Rules and Authenticity</h2>
    <p>Quality control begins at the listing level. Sellers must ensure all listings meet our standard of accuracy.</p>
    <ul>
        <li><strong>Authenticity Guaranteed:</strong> All diecast models must be 100% authentic. Selling counterfeits, unauthorized replicas, or "customs" masquerading as official releases is strictly prohibited.</li>
        <li><strong>Accurate Descriptions:</strong> You must accurately describe the condition of the model and the packaging (e.g., Mint, Near Mint, Card Creased, Blister Cracked, Loose).</li>
        <li><strong>Real Photos:</strong> Stock photos are permitted for brand new, sealed items, but actual photos of the product are strongly encouraged and mandatory for unboxed or damaged items.</li>
        <li><strong>Fair Pricing:</strong> While sellers set their own prices, extreme price gouging (scalping) that harms the community ecosystem is discouraged and may be subject to review.</li>
    </ul>

    <h2>3. Prohibited Items</h2>
    <p>The following items may not be listed or sold on REDLINER:</p>
    <ul>
        <li>Items that are not diecast models or directly related accessories (e.g., dioramas, display cases are allowed; random electronics or clothing are not).</li>
        <li>Counterfeit models or unauthorized recast parts.</li>
        <li>Stolen merchandise.</li>
        <li>Any items promoting hate speech, violence, or illegal activities.</li>
    </ul>

    <h2>4. Fulfillment and Shipping Obligations</h2>
    <p>Prompt shipping and secure packaging are essential to buyer satisfaction.</p>
    <ul>
        <li><strong>Packaging:</strong> Diecast models are fragile collectibles. Sellers must use adequate protective materials (bubble wrap, sturdy boxes) to ensure items arrive in the condition described.</li>
        <li><strong>Dispatch Time:</strong> Sellers must dispatch orders and upload valid tracking information within a reasonable timeframe (typically 2-4 business days) after payment confirmation.</li>
        <li><strong>Tracking Requirement:</strong> Providing a valid tracking number and courier name is mandatory for every shipped order.</li>
    </ul>

    <h2>5. Account Suspension Terms</h2>
    <p>REDLINER maintains a zero-tolerance policy for fraudulent activities.</p>
    <p>A seller's account may be temporarily or permanently suspended for any of the following reasons:</p>
    <ul>
        <li>Failing KYC verification or providing falsified KYC documents.</li>
        <li>Multiple verified buyer disputes regarding non-delivery, damaged goods due to poor packaging, or counterfeit items.</li>
        <li>Accepting payment from a buyer and intentionally failing to dispatch the item (scamming).</li>
        <li>Using abusive, threatening, or inappropriate language towards buyers or REDLINER administrators.</li>
        <li>Attempting to bypass the platform's systems or engaging in malicious activities targeting the REDLINER infrastructure.</li>
    </ul>

    <h2>6. Changes to the Agreement</h2>
    <p>REDLINER reserves the right to modify this Seller Agreement at any time. Significant changes, including any future implementation of platform fees, will be communicated to all active sellers well in advance with a minimum 30-day notice period.</p>
</div>

<?php include 'includes/footer.php'; ?>
