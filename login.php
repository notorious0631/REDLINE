<?php
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ─── Rate Limiting: 5 login attempts per 15 minutes ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit('login', 5, 900);
}

$error = '';

// Check for rate limit error from session
if (isset($_SESSION['rate_limit_error'])) {
    $error = $_SESSION['rate_limit_error'];
    unset($_SESSION['rate_limit_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ─── CSRF Verification ───
    if (!verifyCsrfRequest()) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (!empty($_POST['g_credential'])) {
        // Handle Google Login Callback
        $jwt = trim($_POST['g_credential']);
        
        if (empty($jwt)) {
            $error = 'Google authentication failed. No token received.';
        } else {
            $verifyUrl = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($jwt);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $verifyUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);  // ✅ Verify SSL in production
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);      // ✅ Verify host
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $curl_err = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                logError('auth', 'Google OAuth cURL failed', null, ['curl_error' => $curl_err]);
                $error = 'Google authentication service unavailable. Please try again.';
            }

            if (empty($error) && $response !== false) {
                $tokenData = json_decode($response, true);
                
                // Verify the token audience matches our client ID
                $expectedClientId = env('GOOGLE_CLIENT_ID', '');
                
                if (isset($tokenData['email']) && isset($tokenData['aud']) && $tokenData['aud'] === $expectedClientId) {
                    $email = $tokenData['email'];
                    $name = $tokenData['name'] ?? explode('@', $email)[0];
                    
                    try {
                        $stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            // Regenerate session ID to prevent session fixation
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            
                            $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$user['id']]);
                            
                            header('Location: index.php');
                            exit;
                        } else {
                            // Auto-Register user
                            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, is_verified, created_at) VALUES (?, ?, ?, 'user', 1, NOW())");
                            $stmt->execute([$name, $email, $randomPassword]);
                            $newUserId = $conn->lastInsertId();
                            
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $newUserId;
                            $_SESSION['user_name'] = $name;
                            $_SESSION['user_email'] = $email;
                            $_SESSION['role'] = 'user';
                            header('Location: index.php');
                            exit;
                        }
                    } catch (PDOException $e) {
                        logError('auth', 'Google login DB error', $e);
                        $error = 'Something went wrong. Please try again.';
                    }
                } else {
                    $error = 'Google authentication failed. Invalid token.';
                }
            }
        }
    } else {
        // ─── Standard Email/Password Login ───
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (mb_strlen($email) > 254) {
            $error = 'Email address is too long.';
        } else {
            try {
                // ─── Account Lockout Check ───
                $stmt = $conn->prepare("SELECT id, name, email, password, role, is_verified, failed_login_attempts, locked_until FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Check if account is locked
                    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                        $minutesLeft = ceil((strtotime($user['locked_until']) - time()) / 60);
                        $error = "Account temporarily locked. Try again in {$minutesLeft} minute(s).";
                    } elseif (password_verify($password, $user['password'])) {
                        // ─── Successful login ───
                        // Reset failed attempts
                        $conn->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
                        
                        if (!$user['is_verified']) {
                            $_SESSION['verify_email'] = $user['email'];
                            header('Location: verify_otp.php');
                            exit;
                        }
                        
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        header('Location: index.php');
                        exit;
                    } else {
                        // ─── Failed login — increment attempts ───
                        $attempts = intval($user['failed_login_attempts'] ?? 0) + 1;
                        $lockUntil = null;
                        if ($attempts >= 5) {
                            $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                            $attempts = 0; // Reset counter after locking
                        }
                        $conn->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")
                             ->execute([$attempts, $lockUntil, $user['id']]);
                        
                        $error = 'Invalid email or password.';
                    }
                } else {
                    // User not found — generic error to prevent user enumeration
                    $error = 'Invalid email or password.';
                }
            } catch (PDOException $e) {
                logError('auth', 'Login DB error', $e);
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/auth.css">

<div class="auth-page-wrapper">
    <form class="form" method="POST" action="login.php" data-aos="fade-up">
        <?php echo csrfField(); ?>
        <div class="auth-logo" style="margin-bottom: 20px; text-align: center;">
            <img src="assets/images/logo.png" alt="REDLINE" style="width: 48px; height: 48px; margin-bottom: 12px; border-radius: 8px;">
            <h1 style="color: black; margin:0; font-size:1.5rem; font-weight:800;">REDLINER</h1>
            <p style="color: gray; margin: 6px 0 0; font-size:0.85rem;">Sign in to your account</p>
        </div>

        <?php if ($error): ?>
            <div class="auth-alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="flex-column">
            <label>Email </label>
        </div>
        <div class="inputForm">
            <svg height="20" viewBox="0 0 32 32" width="20" xmlns="http://www.w3.org/2000/svg"><g id="Layer_3" data-name="Layer 3"><path fill="#888" d="m30.853 13.87a15 15 0 0 0 -29.729 4.082 15.1 15.1 0 0 0 12.876 12.918 15.6 15.6 0 0 0 2.016.13 14.85 14.85 0 0 0 7.715-2.145 1 1 0 1 0 -1.031-1.711 13.007 13.007 0 1 1 5.458-6.529 2.149 2.149 0 0 1 -4.158-.759v-10.856a1 1 0 0 0 -2 0v1.726a8 8 0 1 0 .2 10.325 4.135 4.135 0 0 0 7.83.274 15.2 15.2 0 0 0 .823-7.455zm-14.853 8.13a6 6 0 1 1 6-6 6.006 6.006 0 0 1 -6 6z"></path></g></svg>
            <input type="email" name="email" class="input" placeholder="Enter your Email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        
        <div class="flex-column">
            <label>Password </label>
        </div>
        <div class="inputForm">
            <svg height="20" viewBox="-64 0 512 512" width="20" xmlns="http://www.w3.org/2000/svg"><path fill="#888" d="m336 512h-288c-26.453125 0-48-21.523438-48-48v-224c0-26.476562 21.546875-48 48-48h288c26.453125 0 48 21.523438 48 48v224c0 26.476562-21.546875 48-48 48zm-288-288c-8.8125 0-16 7.167969-16 16v224c0 8.832031 7.1875 16 16 16h288c8.8125 0 16-7.167969 16-16v-224c0-8.832031-7.1875-16-16-16zm0 0"></path><path fill="#888" d="m304 224c-8.832031 0-16-7.167969-16-16v-80c0-52.929688-43.070312-96-96-96s-96 43.070312-96 96v80c0 8.832031-7.167969 16-16 16s-16-7.167969-16-16v-80c0-70.59375 57.40625-128 128-128s128 57.40625 128 128v80c0 8.832031-7.167969 16-16 16zm0 0"></path></svg>        
            <input type="password" name="password" class="input" placeholder="Enter your Password" required id="loginPw">
            <svg viewBox="0 0 576 512" height="1em" xmlns="http://www.w3.org/2000/svg" style="cursor:pointer;" onclick="togglePw()"><path fill="#888" d="M288 32c-80.8 0-145.5 36.8-192.6 80.6C48.6 156 17.3 208 2.5 243.7c-3.3 7.9-3.3 16.7 0 24.6C17.3 304 48.6 356 95.4 399.4C142.5 443.2 207.2 480 288 480s145.5-36.8 192.6-80.6c46.8-43.5 78.1-95.4 93-131.1c3.3-7.9 3.3-16.7 0-24.6c-14.9-35.7-46.2-87.7-93-131.1C433.5 68.8 368.8 32 288 32zM144 256a144 144 0 1 1 288 0 144 144 0 1 1 -288 0zm144-64c0 35.3-28.7 64-64 64c-7.1 0-13.9-1.2-20.3-3.3c-5.5-1.8-11.9 1.6-11.7 7.4c.3 6.9 1.3 13.8 3.2 20.7c13.7 51.2 66.4 81.6 117.6 67.9s81.6-66.4 67.9-117.6c-11.1-41.5-47.8-69.4-88.6-71.1c-5.8-.2-9.2 6.1-7.4 11.7c2.1 6.4 3.3 13.2 3.3 20.3z"></path></svg>
        </div>
        
        <div class="flex-row">
            <div>
            <input type="checkbox">
            <label>Remember me </label>
            </div>
            <a href="forgot_password.php" class="span" style="text-decoration:none;">Forgot password?</a>
        </div>
        <button type="submit" class="button-submit">Sign In</button>
        <p class="p">Don't have an account? <a href="signup.php" class="span" style="text-decoration:none;">Sign Up</a></p>
        <p class="p line">Or With</p>

        <div style="display:flex; justify-content:center; align-items:center;">
            <!-- Google Identity Services Script -->
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <script>
                function handleCredentialResponse(response) {
                    document.getElementById('g_credential').value = response.credential;
                    document.getElementById('google_login_form').submit();
                }
            </script>
            
            <div id="g_id_onload"
                 data-client_id="<?php echo htmlspecialchars(env('GOOGLE_CLIENT_ID', '')); ?>"
                 data-context="signin"
                 data-ux_mode="popup"
                 data-callback="handleCredentialResponse"
                 data-auto_prompt="false">
            </div>
            
            <!-- Standard Google Sign-In Button Render -->
            <div class="g_id_signin"
                 data-type="standard"
                 data-shape="rectangular"
                 data-theme="outline"
                 data-text="signin_with"
                 data-size="large"
                 data-logo_alignment="left">
            </div>
        </div>
    </form>
    
    <!-- Hidden form for Google Login submission (MUST be outside main form) -->
    <form id="google_login_form" method="POST" action="login.php" style="display: none;">
        <input type="hidden" name="g_credential" id="g_credential">
        <?php echo csrfField(); ?>
    </form>
</div>

<script>
function togglePw() {
    const inp = document.getElementById('loginPw');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>

<?php include 'includes/footer.php'; ?>
