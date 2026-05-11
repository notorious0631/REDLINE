<?php
/**
 * REDLINE — Database Connection & Security Bootstrap
 * Loads environment, security headers, rate limiter, CSRF, and error handler.
 */

// ─── Load environment variables ───
require_once __DIR__ . '/env.php';

// ─── Load security modules ───
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/rate_limiter.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/error_handler.php';

// ─── Database Connection ───
$host     = env('DB_HOST', 'localhost');
$db_name  = env('DB_NAME', 'redline');
$username = env('DB_USER', 'root');
$password = env('DB_PASS', '');

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Use real prepared statements
        ]
    );
} catch(PDOException $e) {
    logError('db', 'Database connection failed', $e);
    http_response_code(503);
    die('Service temporarily unavailable. Please try again later.');
}

// ─── Utility: Block WhatsApp/Telegram links ───
function containsBlockedLinks($text) {
    if (empty($text)) return false;
    $pattern = '/(chat\.whatsapp\.com|t\.me|telegram\.me|telegram\.dog)/i';
    return preg_match($pattern, $text) === 1;
}

// ─── Fetch Site Settings Globally ───
$siteSettings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM admin_settings");
    while ($r = $stmt->fetch()) {
        $siteSettings[$r['setting_key']] = $r['setting_value'];
    }
} catch (PDOException $e) {}

function getSetting($key, $default = '') {
    global $siteSettings;
    return $siteSettings[$key] ?? $default;
}

// ─── SEO Helper Functions ───
function generateSlug($string) {
    $string = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
    $string = strtolower(trim($string, '-'));
    return empty($string) ? 'item' : $string;
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    if (basename($path) === 'admin' || basename($path) === 'seller_dashboard' || basename($path) === 'api') {
        $path = dirname($path);
    }
    $path = rtrim($path, '/\\');
    return $protocol . "://" . $host . $path;
}

function getListingUrl($id, $title) {
    return getBaseUrl() . '/listing/' . $id . '/' . generateSlug($title);
}

function getCategoryUrl($slug) {
    return getBaseUrl() . '/category/' . generateSlug($slug);
}
?>
