<?php
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['verify_email'])) {
    header('Location: signup.php');
    exit;
}

$email = $_SESSION['verify_email'];
$error = '';
$testOtp = null;
if (isDebug()) {
    $testOtp = $_SESSION['signup_otp'] ?? null;
}

if (isset($_SESSION['rate_limit_error'])) {
    $error = $_SESSION['rate_limit_error'];
    unset($_SESSION['rate_limit_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('verify_otp', 5, 900); // 5 attempts per 15 mins

    if (!verifyCsrfRequest()) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $otp = implode('', $_POST['otp'] ?? []);

        if (strlen($otp) !== 6) {
            $error = 'Please enter the full 6-digit code.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id, otp, otp_expiry FROM users WHERE email = ? AND is_verified = 0");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && $user['otp'] === $otp && strtotime($user['otp_expiry']) >= time()) {
                    $stmt = $conn->prepare("UPDATE users SET is_verified = 1, otp = NULL, otp_expiry = NULL WHERE id = ?");
                    $stmt->execute([$user['id']]);

                    unset($_SESSION['verify_email'], $_SESSION['signup_otp']);
                    $_SESSION['otp_verified'] = true;
                    header('Location: login.php');
                    exit;
                } else {
                    $error = 'Invalid or expired OTP. Please try again.';
                }
            } catch (PDOException $e) {
                logError('auth', 'Verify OTP DB error', $e);
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
            <h1>Verify Email</h1>
            <p>Enter the 6-digit code sent to<br><strong><?php echo htmlspecialchars($email); ?></strong></p>
        </div>

        <?php if ($testOtp): ?>
            <div class="auth-alert success">
                <i class="fas fa-info-circle"></i> Test OTP: <strong><?php echo htmlspecialchars($testOtp); ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="auth-alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="verify_otp.php">
            <?php echo csrfField(); ?>
            <div class="otp-inputs">
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" name="otp[]" maxlength="1" pattern="[0-9]" inputmode="numeric" required
                           oninput="otpMove(this, <?php echo $i; ?>)" onkeydown="otpBack(event, <?php echo $i; ?>)">
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn-auth">
                Verify <i class="fas fa-check"></i>
            </button>
        </form>

        <div class="auth-footer">
            <a href="signup.php">← Back to Sign Up</a>
        </div>
    </div>
</div>

<script>
function otpMove(el, idx) {
    el.value = el.value.replace(/[^0-9]/g, '');
    if (el.value && idx < 5) {
        el.parentElement.children[idx + 1].focus();
    }
}
function otpBack(e, idx) {
    if (e.key === 'Backspace' && !e.target.value && idx > 0) {
        e.target.parentElement.children[idx - 1].focus();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
