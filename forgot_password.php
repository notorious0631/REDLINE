<?php
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$error = '';
$success = '';
$step = 'email'; // email or reset

if (isset($_SESSION['rate_limit_error'])) {
    $error = $_SESSION['rate_limit_error'];
    unset($_SESSION['rate_limit_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('forgot_pw', 3, 900); // 3 attempts per 15 mins

    if (!verifyCsrfRequest()) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (isset($_POST['step']) && $_POST['step'] === 'reset') {
        $step = 'reset';
        $email = $_POST['email'] ?? '';
        $otp = $_POST['otp'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if (empty($otp) || empty($newPassword) || strlen($newPassword) < 6) {
            $error = 'Please enter the OTP and a password of at least 6 characters.';
            $step = 'reset';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id, otp, otp_expiry FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && $user['otp'] === $otp && strtotime($user['otp_expiry']) >= time()) {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ?, otp = NULL, otp_expiry = NULL WHERE id = ?");
                    $stmt->execute([$hashed, $user['id']]);
                    $success = 'Password reset successfully! You can now sign in.';
                    $step = 'email';
                } else {
                    $error = 'Invalid or expired OTP.';
                }
            } catch (PDOException $e) {
                logError('auth', 'Reset password DB error', $e);
                $error = 'Something went wrong.';
            }
        }
    } elseif (($_POST['step'] ?? '') === 'email') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            $error = 'Please enter your email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $stmt = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE email = ?");
                    $stmt->execute([$otp, $expiry, $email]);
                    
                    // Send OTP via email
                    $subject = 'REDLINER - Password Reset Code';
                    $emailBody = "Hello,\n\n";
                    $emailBody .= "Your password reset code is: $otp\n\n";
                    $emailBody .= "This code expires in 15 minutes.\n\n";
                    $emailBody .= "If you did not request this, please ignore this email.\n\n";
                    $emailBody .= "— REDLINER Team";
                    $headers = "From: noreply@redliner.in\r\nReply-To: noreply@redliner.in\r\nContent-Type: text/plain; charset=UTF-8";
                    @mail($email, $subject, $emailBody, $headers);

                    $_SESSION['reset_email'] = $email;
                    $step = 'reset';
                } else {
                    // Prevent user enumeration
                    $error = 'If that email exists, an OTP has been sent.';
                    $step = 'reset'; // Move to reset step to not reveal user doesn't exist
                }
            } catch (PDOException $e) {
                logError('auth', 'Forgot password DB error', $e);
                $error = 'Something went wrong.';
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
            <h1>Reset Password</h1>
            <p><?php echo $step === 'reset' ? 'Enter the OTP and your new password' : 'Enter your email to reset your password'; ?></p>
        </div>

        <?php if ($success): ?>
            <div class="auth-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="auth-alert error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($step === 'reset'): ?>
            <form method="POST" action="forgot_password.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="step" value="reset">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?>">
                <div class="form-group">
                    <label class="form-label">OTP Code</label>
                    <input type="text" name="otp" class="auth-input" placeholder="6-digit OTP" maxlength="6" required>
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="auth-input" placeholder="Min. 6 characters" required>
                </div>
                <button type="submit" class="btn-auth">Reset Password <i class="fas fa-key"></i></button>
            </form>
        <?php else: ?>
            <form method="POST" action="forgot_password.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="step" value="email">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="auth-input" placeholder="you@example.com" required>
                </div>
                <button type="submit" class="btn-auth">Send Reset Code <i class="fas fa-paper-plane"></i></button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php">← Back to Sign In</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
