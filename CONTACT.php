<?php
session_start();
require_once 'config/db.php';

$success = '';
$error = '';

if (isset($_SESSION['rate_limit_error'])) {
    $error = $_SESSION['rate_limit_error'];
    unset($_SESSION['rate_limit_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('contact', 3, 3600); // 3 messages per hour per IP

    if (!verifyCsrfRequest()) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        if (empty($name) || empty($email) || empty($message)) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
            $error = 'Input length exceeds maximum allowed.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $message]);
                $success = 'Thank you for reaching out! Our team will get back to you shortly.';
            } catch (PDOException $e) {
                logError('general', 'Contact form DB error', $e);
                $error = 'Something went wrong while submitting your message. Please try again.';
            }
        }
    }
}

$pageTitle       = 'Contact REDLINER — India\'s Diecast Marketplace Support';
$pageDescription = 'Get in touch with the REDLINER team for order support, seller inquiries, or marketplace questions. India\'s trusted diecast collectibles platform.';
include 'includes/header.php';
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "REDLINER",
  "url": "https://www.redliner.in",
  "logo": "https://www.redliner.in/assets/images/logo.png",
  "description": "India's premier marketplace for diecast collectibles — Hot Wheels, Mini GT, Tomica, Matchbox and more.",
  "areaServed": "IN",
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "customer support",
    "availableLanguage": "English",
    "hoursAvailable": "Mo-Fr 09:00-18:00",
    "areaServed": "IN"
  }
}
</script>
<?php

<style>
    .contact-hero {
        padding: 80px 20px 40px;
        text-align: center;
        background: linear-gradient(180deg, rgba(229,57,53,0.05) 0%, transparent 100%);
    }
    .contact-hero h1 {
        font-family: var(--font-display, outfit, sans-serif);
        font-size: 3.5rem;
        font-weight: 900;
        letter-spacing: -1px;
        margin-bottom: 16px;
    }
    .contact-hero p {
        color: var(--text-secondary);
        max-width: 600px;
        margin: 0 auto;
        font-size: 1.1rem;
        line-height: 1.6;
    }
    
    .contact-grid {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 40px;
        max-width: 1100px;
        margin: 0 auto 80px;
        padding: 0 20px;
    }
    
    .contact-info-card {
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.04);
        border-radius: 24px;
        padding: 40px 30px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    }
    .contact-info-item {
        margin-bottom: 32px;
        display: flex;
        gap: 16px;
        align-items: flex-start;
    }
    .contact-info-icon {
        width: 50px;
        height: 50px;
        background: rgba(229,57,53,0.1);
        color: var(--accent-red);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
        transition: transform 0.3s;
    }
    .contact-info-item:hover .contact-info-icon {
        transform: translateY(-4px);
    }
    .contact-info-text h3 {
        font-size: 1.15rem;
        margin: 0 0 6px 0;
        font-weight: 700;
        color: #fff;
    }
    .contact-info-text p {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .contact-form-card {
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.04);
        border-radius: 24px;
        padding: 48px 40px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    }
    
    .form-group-rl { margin-bottom: 24px; }
    .form-label-rl { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 600; }
    .form-control-rl { 
        width: 100%; 
        background: rgba(255,255,255,0.02); 
        border: 1px solid rgba(255,255,255,0.1); 
        color: var(--text-primary); 
        padding: 16px 18px; 
        border-radius: 14px; 
        font-size: 1rem; 
        transition: all 0.3s; 
        font-family: inherit; 
    }
    .form-control-rl:focus { 
        outline: none; 
        border-color: var(--accent-red); 
        background: rgba(255,255,255,0.04); 
        box-shadow: 0 0 0 4px rgba(229,57,53,0.1);
    }
    .form-control-rl::placeholder { color: rgba(255,255,255,0.2); }
    
    @media (max-width: 768px) {
        .contact-grid {
            grid-template-columns: 1fr;
            gap: 24px;
        }
        .contact-hero { padding: 60px 20px 30px; }
        .contact-hero h1 { font-size: 2.5rem; }
        .contact-form-card { padding: 30px 24px; }
    }
</style>

<div class="contact-page">
    <div class="contact-hero" data-aos="fade-down">
        <h1>Contact <span style="color:var(--accent-red);">REDLINE</span></h1>
        <p>Have questions about your order, want to report an issue, or simply want to learn more about our marketplace? Our team is here to proactively help.</p>
    </div>

    <div class="contact-grid">
        <div class="contact-info-card" data-aos="fade-right" data-aos-delay="100">
            <h2 style="font-size: 1.6rem; margin-bottom: 30px; font-family: var(--font-display); font-weight: 800;">Get in touch</h2>
            
            <div style="margin-top: 40px; padding-top: 30px; border-top: 1px solid rgba(255,255,255,0.05);">
                <h3 style="font-size:1rem;color:var(--text-secondary);margin-bottom:12px;">Operating Hours</h3>
                <p style="color:var(--text-muted);font-size:0.9rem;margin:0;">Monday - Friday: 9:00 AM - 6:00 PM<br>Saturday: 10:00 AM - 4:00 PM<br>Sunday: Closed</p>
            </div>
        </div>

        <div class="contact-form-card" data-aos="fade-left" data-aos-delay="200">
            <?php if (!empty($success)): ?>
                <div style="background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); color: #81c784; padding: 18px 20px; border-radius: 14px; margin-bottom: 28px; display: flex; gap: 14px; align-items: center; box-shadow: 0 10px 20px rgba(0,0,0,0.1);">
                    <i class="fas fa-check-circle" style="font-size:1.5rem;"></i>
                    <span style="font-weight: 500; font-size:1.05rem;"><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div style="background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #e57373; padding: 18px 20px; border-radius: 14px; margin-bottom: 28px; display: flex; gap: 14px; align-items: center;">
                    <i class="fas fa-exclamation-circle" style="font-size:1.5rem;"></i>
                    <span style="font-weight: 500; font-size:1.05rem;"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="CONTACT.php" method="POST">
                <?php echo csrfField(); ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group-rl">
                            <label class="form-label-rl">Your Name</label>
                            <input type="text" name="name" class="form-control-rl" placeholder="John Doe" required value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group-rl">
                            <label class="form-label-rl">Email Address</label>
                            <input type="email" name="email" class="form-control-rl" placeholder="john@example.com" required>
                            <span style="display:block; font-size:0.8rem; color:var(--text-muted); margin-top:6px;"><i class="fas fa-info-circle" style="margin-right:4px; opacity:0.6;"></i>We'll respond to your query on this email.</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group-rl">
                    <label class="form-label-rl">How can we help you?</label>
                    <textarea name="message" class="form-control-rl" rows="6" placeholder="Describe your issue or question in detail..." required></textarea>
                </div>
                
                <button type="submit" class="btn-red" style="width: 100%; padding: 18px; border-radius: 14px; font-size: 1.15rem; font-weight: 700; display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 10px; box-shadow: 0 10px 20px rgba(229,57,53,0.25);">
                    Send Message <i class="fas fa-paper-plane" style="font-size: 0.95rem;"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
