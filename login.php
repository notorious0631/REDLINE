<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config/db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['g_credential'])) {
        // Handle Google Login Callback
        $jwt = trim($_POST['g_credential']);
        
        if (empty($jwt)) {
            $error = 'Google authentication failed. No token received.';
        } else {
            $verifyUrl = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $jwt;
            
            // Setup simple cURL for secure remote API fetching
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $verifyUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For flexible local dev if needed
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Ignore host verification as well for local dev
            $response = curl_exec($ch);
            $curl_err = curl_error($ch);
            curl_close($ch);
            
            // Fallback if cURL fails completely
            if ($response === false) {
                $options = [
                    'http' => ['ignore_errors' => true],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
                ];
                $context = stream_context_create($options);
                $response = @file_get_contents($verifyUrl, false, $context);
                if ($response === false) {
                    $error = 'Google API connection failed. cURL error: ' . $curl_err;
                }
            }

            if (empty($error) && $response !== false) {
                $tokenData = json_decode($response, true);
                
                if (isset($tokenData['email']) && isset($tokenData['aud'])) {
                    $email = $tokenData['email'];
                    $name = $tokenData['name'] ?? explode('@', $email)[0];
                    
                    try {
                        $stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            
                            // If previously unverified, automatically verify them since Google authenticated 
                            $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$user['id']]);
                            
                            header('Location: index.php');
                            exit;
                        } else {
                            // Auto-Register user
                            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, is_verified, created_at) VALUES (?, ?, ?, 'user', 1, NOW())");
                            $stmt->execute([$name, $email, $randomPassword]);
                            $newUserId = $conn->lastInsertId();
                            
                            $_SESSION['user_id'] = $newUserId;
                            $_SESSION['user_name'] = $name;
                            $_SESSION['user_email'] = $email;
                            $_SESSION['role'] = 'user';
                            header('Location: index.php');
                            exit;
                        }
                    } catch (PDOException $e) {
                        $error = 'Google registration error. Please try again.';
                    }
                } else {
                    $apiError = $tokenData['error_description'] ?? ($tokenData['error'] ?? 'Unknown token error');
                    $error = 'Google authentication failed. ' . htmlspecialchars($apiError);
                }
            }
        }
    } else {
        // Standard Email/Password Login
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id, name, email, password, role, is_verified FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    if (!$user['is_verified']) {
                        $_SESSION['verify_email'] = $user['email'];
                        header('Location: verify_otp.php');
                        exit;
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Invalid email or password.';
                }
            } catch (PDOException $e) {
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
        <div class="auth-logo" style="margin-bottom: 20px; text-align: center;">
            <img src="assets/images/logo.jpeg" alt="REDLINE" style="width: 48px; height: 48px; margin-bottom: 12px; border-radius: 8px;">
            <h1 style="color: black; margin:0; font-size:1.5rem; font-weight:800;">REDLINE</h1>
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
                 data-client_id="671068188127-vlovg5cqoshm5g5so5d45r73ijv22jno.apps.googleusercontent.com"
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
            
            <form id="google_login_form" method="POST" action="login.php" style="display: none;">
                <input type="hidden" name="g_credential" id="g_credential">
            </form>
        </div>
    </form>
</div>

<script>
function togglePw() {
    const inp = document.getElementById('loginPw');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>

<?php include 'includes/footer.php'; ?>
