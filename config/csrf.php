<?php
/**
 * REDLINE — CSRF Protection
 * Generates and validates per-session CSRF tokens.
 *
 * Usage in forms:
 *   <?php echo csrfField(); ?>
 *
 * Usage in form handlers:
 *   if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) { ... }
 *
 * Usage in AJAX:
 *   Send header: X-CSRF-Token: <token from csrfToken()>
 */

/**
 * Generate or retrieve the current session's CSRF token.
 */
function csrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['_csrf_token'];
}

/**
 * Generate a hidden input field with the CSRF token.
 */
function csrfField() {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Verify a submitted CSRF token against the session token.
 * Returns true if valid, false otherwise.
 */
function verifyCsrfToken($submittedToken) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    
    if (empty($sessionToken) || empty($submittedToken)) {
        return false;
    }
    
    return hash_equals($sessionToken, $submittedToken);
}

/**
 * Verify CSRF for both form submissions and AJAX requests.
 * Checks POST body first, then X-CSRF-Token header.
 */
function verifyCsrfRequest() {
    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return verifyCsrfToken($token);
}
?>
