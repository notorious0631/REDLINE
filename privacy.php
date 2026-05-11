<?php
require_once 'config/db.php';
$page_title = "Privacy Policy - Redliner";
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
    <h1>Privacy Policy</h1>
    <p>Last updated: <?php echo date('F j, Y'); ?></p>
</div>

<div class="legal-content container-rl" data-aos="fade-up">
    <p>Welcome to REDLINER. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our platform. We are committed to protecting your personal information and your right to privacy in accordance with the <strong>Indian Information Technology Act, 2000</strong> and the <strong>Digital Personal Data Protection (DPDP) Act, 2023</strong>.</p>

    <h2>1. Information We Collect</h2>
    <p>We collect personal information that you voluntarily provide to us when registering on the platform, expressing an interest in obtaining information about us or our products and services, or otherwise contacting us.</p>
    <ul>
        <li><strong>Personal Identifiers:</strong> Name, email address, phone number, and shipping/billing addresses.</li>
        <li><strong>KYC Data (Know Your Customer):</strong> For users applying to be sellers, we collect government-issued ID documents (such as Aadhar or PAN) and bank details to prevent fraud and comply with legal requirements.</li>
        <li><strong>Transaction Data:</strong> Details of orders, communications in chats, and reviews.</li>
        <li><strong>Technical Data:</strong> IP addresses, browser types, and usage data automatically collected when interacting with the platform.</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <p>We process your information for purposes based on legitimate business interests, the fulfillment of our contract with you, compliance with our legal obligations, and/or your consent.</p>
    <ul>
        <li>To facilitate account creation and the logon process.</li>
        <li>To manage and complete transactions between buyers and sellers.</li>
        <li>To perform KYC verification for sellers to maintain platform integrity.</li>
        <li>To resolve disputes and troubleshoot problems.</li>
        <li>To respond to legal requests and prevent harm.</li>
    </ul>

    <h2>3. Protection of KYC Data</h2>
    <p>We treat KYC data with the highest level of security and confidentiality, strictly adhering to the DPDP Act 2023.</p>
    <ul>
        <li>KYC documents are securely stored and encrypted.</li>
        <li>Access to KYC data is restricted to authorized administrative personnel solely for the purpose of verification and legal compliance.</li>
        <li>KYC data is never shared with third-party marketers or other users.</li>
    </ul>

    <h2>4. Sharing of Information</h2>
    <p>We only share information with your consent, to comply with laws, to provide you with services, to protect your rights, or to fulfill business obligations. Limited necessary information (like name and shipping address) is shared between buyers and sellers strictly to facilitate the delivery of purchased items.</p>

    <h2>5. Data Retention</h2>
    <p>We will only keep your personal information for as long as it is necessary for the purposes set out in this privacy policy, unless a longer retention period is required or permitted by law (such as tax, accounting, or other legal requirements).</p>

    <h2>6. Your Rights</h2>
    <p>Under the DPDP Act 2023, you have the right to access, correct, update, or request deletion of your personal data. You may also withdraw consent for data processing at any time by contacting our support team, subject to legal and contractual restrictions.</p>

    <h2>7. Contact Us</h2>
    <p>If you have questions or comments about this notice, you may email our Data Protection Officer at support@redliner.com.</p>
</div>

<?php include 'includes/footer.php'; ?>
