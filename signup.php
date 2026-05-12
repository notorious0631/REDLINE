<?php
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
$error = '';
$success = '';

// Check for rate limit error from session
if (isset($_SESSION['rate_limit_error'])) {
    $error = $_SESSION['rate_limit_error'];
    unset($_SESSION['rate_limit_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('signup', 3, 3600); // 3 signups per hour per IP

    if (!verifyCsrfRequest()) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (mb_strlen($name) > 100 || mb_strlen($email) > 254) {
            $error = 'Input length exceeds maximum allowed.';
        } elseif (!empty($phone) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $error = 'Please enter a valid phone number.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                // Check if email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'An account with this email already exists.';
                } else {
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, otp, otp_expiry) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $phone, $hashedPassword, $otp, $otpExpiry]);

                    $_SESSION['verify_email'] = $email;

                    // Send OTP via email
                    $subject = 'REDLINER - Email Verification Code';
                    $emailBody = "Hello " . htmlspecialchars($name) . ",\n\n";
                    $emailBody .= "Your email verification code is: $otp\n\n";
                    $emailBody .= "This code expires in 15 minutes.\n\n";
                    $emailBody .= "If you did not request this, please ignore this email.\n\n";
                    $emailBody .= "— REDLINER Team";
                    $headers = "From: noreply@redliner.in\r\nReply-To: noreply@redliner.in\r\nContent-Type: text/plain; charset=UTF-8";
                    @mail($email, $subject, $emailBody, $headers);

                    header('Location: verify_otp.php');
                    exit;
                }
            } catch (PDOException $e) {
                logError('auth', 'Signup DB error', $e);
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/auth.css">

<div class="auth-page-wrapper">
    <div class="auth-card" data-aos="fade-up">
        <div class="auth-logo">
            <img src="assets/images/logo.png" alt="REDLINER">
            <h1>REDLINER</h1>
            <p>Create your collector account</p>
        </div>

        <?php if ($error): ?>
            <div class="auth-alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="signup.php">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:var(--accent-red)">*</span></label>
                <input type="text" name="name" class="auth-input" placeholder="John Doe" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address <span style="color:var(--accent-red)">*</span></label>
                <input type="email" name="email" class="auth-input" placeholder="you@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <span style="display:block; font-size:0.78rem; color:#ffb74d; margin-top:6px; opacity:0.85;"><i class="fas fa-info-circle" style="margin-right:4px;"></i>This Email will be used for all order related updates.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="phone" class="auth-input" placeholder="+91 9876543210" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password <span style="color:var(--accent-red)">*</span></label>
                <input type="password" name="password" class="auth-input" placeholder="Min. 6 characters" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password <span style="color:var(--accent-red)">*</span></label>
                <input type="password" name="confirm_password" class="auth-input" placeholder="Re-enter password" required>
            </div>
            <button type="submit" class="btn-auth">
                Create Account <i class="fas fa-user-plus"></i>
            </button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
