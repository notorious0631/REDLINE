<?php
/**
 * REDLINE — File-Based Rate Limiter
 * No external dependencies. Uses filesystem storage.
 *
 * Usage:
 *   require_once 'config/rate_limiter.php';
 *   checkRateLimit('login', 5, 900);  // 5 attempts per 15 minutes
 */

/**
 * Check and enforce rate limit. Exits with 429 if limit exceeded.
 *
 * @param string $action   Action identifier (e.g., 'login', 'signup', 'api')
 * @param int    $maxAttempts   Maximum number of attempts in the window
 * @param int    $windowSeconds Window duration in seconds
 * @param string|null $identifier  Custom identifier (defaults to IP)
 */
function checkRateLimit($action, $maxAttempts, $windowSeconds, $identifier = null) {
    $ip = $identifier ?? getClientIp();
    $storageDir = __DIR__ . '/../storage/rate_limits';
    
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    // Create a safe filename from the action and IP
    $key = md5($action . '|' . $ip);
    $file = $storageDir . '/' . $key . '.json';
    
    $now = time();
    $attempts = [];
    
    // Load existing attempts
    if (file_exists($file)) {
        $data = @json_decode(@file_get_contents($file), true);
        if (is_array($data)) {
            // Filter out expired attempts
            $attempts = array_filter($data, function($timestamp) use ($now, $windowSeconds) {
                return ($now - $timestamp) < $windowSeconds;
            });
        }
    }
    
    // Check if limit exceeded
    if (count($attempts) >= $maxAttempts) {
        $oldestAttempt = min($attempts);
        $retryAfter = $windowSeconds - ($now - $oldestAttempt);
        
        http_response_code(429);
        header("Retry-After: $retryAfter");
        
        // Check if it's an API/JSON request
        $isJson = (
            strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
            (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        );
        
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Too many requests. Please try again in ' . ceil($retryAfter / 60) . ' minute(s).',
                'retry_after' => $retryAfter
            ]);
        } else {
            // For HTML pages, store error in session and let the page handle it
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['rate_limit_error'] = 'Too many attempts. Please wait ' . ceil($retryAfter / 60) . ' minute(s) before trying again.';
            }
            // Don't exit for HTML — let the page render the error
            return false;
        }
        exit;
    }
    
    // Record this attempt
    $attempts[] = $now;
    @file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
    
    return true;
}

/**
 * Get the client's real IP address, accounting for proxies.
 */
function getClientIp() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // X-Forwarded-For can contain multiple IPs; take the first
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '127.0.0.1';
}

/**
 * Periodically clean up old rate limit files (call occasionally).
 */
function cleanupRateLimits() {
    $storageDir = __DIR__ . '/../storage/rate_limits';
    if (!is_dir($storageDir)) return;
    
    $files = glob($storageDir . '/*.json');
    $now = time();
    foreach ($files as $file) {
        // Delete files older than 1 hour
        if (($now - filemtime($file)) > 3600) {
            @unlink($file);
        }
    }
}

// Run cleanup ~1% of requests to keep storage lean
if (mt_rand(1, 100) === 1) {
    cleanupRateLimits();
}
?>
