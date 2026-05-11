<?php
/**
 * REDLINE — HTTP Security Headers & Session Hardening
 * Include this file early in every request (loaded by config/db.php).
 */

// ─── Session Hardening ───
if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie parameters
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,           // Session cookie (expires on browser close)
        'path'     => '/',
        'domain'   => '',          // Current domain only
        'secure'   => $isSecure,   // Only send over HTTPS in production
        'httponly'  => true,        // No JS access to session cookie
        'samesite' => 'Lax',       // CSRF mitigation
    ]);
    session_start();
}

// ─── Security Headers ───
// Prevent clickjacking
header('X-Frame-Options: DENY');

// Prevent MIME-type sniffing
header('X-Content-Type-Options: nosniff');

// Referrer policy
header('Referrer-Policy: strict-origin-when-cross-origin');

// Permissions policy (restrict sensitive APIs)
header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");

// Remove PHP version exposure
header_remove('X-Powered-By');

// HSTS — Force HTTPS (only set when actually on HTTPS)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ─── Content Security Policy (Enforced) ───
// Note: 'unsafe-inline' is currently required for existing inline scripts/styles to function.
$csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://accounts.google.com https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net",
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://unpkg.com",
    "img-src 'self' data: blob: https:",
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com",
    "connect-src 'self' https://oauth2.googleapis.com https://accounts.google.com",
    "frame-src https://accounts.google.com",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
]);
header("Content-Security-Policy: $csp");

// ─── Strict Content Security Policy (Report-Only) ───
// Generate a cryptographically secure nonce for this request
$cspNonce = base64_encode(random_bytes(16));
// Store in a constant so it can be used across the application.
// Usage in templates: add nonce="[CSP_NONCE]" attribute to script tags
define('CSP_NONCE', $cspNonce);

// This header will NOT block inline styles/scripts, but will log console warnings for any 
// inline execution that doesn't have the nonce. Use this to gradually migrate!
$strictCsp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'nonce-" . CSP_NONCE . "' 'strict-dynamic' https: http:",
    "style-src 'self' 'nonce-" . CSP_NONCE . "'",
    "img-src 'self' data: blob: https:",
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com",
    "connect-src 'self' https://oauth2.googleapis.com https://accounts.google.com",
    "frame-src https://accounts.google.com",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
]);
header("Content-Security-Policy-Report-Only: $strictCsp");

// ─── CORS ───
$allowedOrigin = env('ALLOWED_ORIGIN', 'http://localhost');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Only set CORS headers for API endpoints
$isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
if ($isApiRequest) {
    if ($origin === $allowedOrigin) {
        header("Access-Control-Allow-Origin: $allowedOrigin");
    }
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
    
    // Handle preflight OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
?>
