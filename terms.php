<?php
require_once 'config/db.php';
$page_title = "Terms of Service - Redliner";
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
</style>

<div class="legal-hero" data-aos="fade-in">
    <h1>Terms of Service</h1>
    <p>Last updated: <?php echo date('F j, Y'); ?></p>
</div>

<div class="legal-content container-rl" data-aos="fade-up">
    <p>Welcome to REDLINER. By accessing or using our platform, you agree to be bound by these Terms of Service. If you disagree with any part of the terms, you may not access the service.</p>

    <h2>1. General Usage</h2>
    <p>REDLINER is a dedicated marketplace connecting buyers and sellers of diecast models. We provide the infrastructure for negotiation, order management, and dispute resolution.</p>
    <ul>
        <li>Users must be at least 18 years old or use the platform under adult supervision.</li>
        <li>Accounts are non-transferable. You are responsible for maintaining the confidentiality of your account credentials.</li>
        <li>Any form of abusive language, spam, or harassment in the community or private chats will result in immediate suspension.</li>
    </ul>

    <h2>2. Rules for Buyers</h2>
    <p>As a buyer on REDLINER, you agree to the following:</p>
    <ul>
        <li><strong>Commitment to Buy:</strong> Making an offer or proceeding to checkout represents a binding commitment to complete the transaction.</li>
        <li><strong>Payments:</strong> All payments must be made promptly via the agreed-upon UPI methods directly to the seller. Fake payment proofs will result in a permanent ban.</li>
        <li><strong>Delivery Acceptance:</strong> You must provide a valid shipping address. Excessive RTOs (Return to Origin) due to buyer unavailability may lead to penalties.</li>
        <li><strong>Reviews:</strong> Reviews must be honest and based on actual transactional experiences. Retaliatory or false reviews are strictly prohibited.</li>
    </ul>

    <h2>3. Rules for Sellers</h2>
    <p>Sellers are held to high standards to ensure a safe marketplace. <em>(For full seller obligations, please see the <a href="seller_agreement.php" style="color:var(--accent-red);">Seller Agreement</a>)</em>.</p>
    <ul>
        <li>Sellers must ship items exactly as described in their listings.</li>
        <li>Tracking information must be uploaded within the stipulated timeframe after payment confirmation.</li>
        <li>Sellers must maintain clear and respectful communication with buyers at all times.</li>
    </ul>

    <h2>4. Dispute Resolution</h2>
    <p>While we expect most transactions to go smoothly, REDLINER provides a formal dispute resolution center for when things go wrong.</p>
    <ul>
        <li>Disputes must be raised within 3 days of order delivery, or if the order exceeds the ETA significantly.</li>
        <li>Both parties are required to provide evidence (unboxing videos, chat logs, shipping receipts).</li>
        <li>REDLINER administrators act as impartial mediators. The decision made by the administrative team is final and binding on both parties.</li>
    </ul>

    <h2>5. Liability Limits</h2>
    <p>REDLINER acts solely as an intermediary platform facilitating transactions between independent buyers and sellers.</p>
    <ul>
        <li>We do not take ownership of the items at any time and do not guarantee the quality, safety, or legality of the items advertised.</li>
        <li>While we verify our sellers via KYC, REDLINER shall not be held liable for any direct, indirect, incidental, or consequential damages resulting from transactions conducted on the platform.</li>
        <li>In the event of a dispute, our maximum liability is limited strictly to mediating the issue. We do not provide financial compensation for losses incurred due to seller or buyer fraud, though we will permanently ban fraudulent entities and assist law enforcement if required.</li>
    </ul>

    <h2>6. Modifications to the Service and Prices</h2>
    <p>We reserve the right at any time to modify or discontinue the Service (or any part or content thereof) without notice at any time.</p>

    <h2>7. Governing Law</h2>
    <p>These Terms shall be governed and construed in accordance with the laws of India, without regard to its conflict of law provisions.</p>
</div>

<?php include 'includes/footer.php'; ?>
