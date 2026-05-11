<?php
/**
 * REDLINE — Production Error Handler
 * Logs errors to file, returns safe messages to users.
 */

/**
 * Log an error with context. Never exposes details to the client.
 *
 * @param string     $category  Error category (e.g., 'db', 'auth', 'upload')
 * @param string     $message   Internal error message (for log only)
 * @param Exception  $exception Optional exception object
 * @param array      $context   Additional context (user_id, route, etc.)
 */
function logError($category, $message, $exception = null, $context = []) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/error_' . date('Y-m-d') . '.log';
    
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'category'  => $category,
        'message'   => $message,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'uri'       => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'method'    => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ];
    
    if ($exception) {
        $entry['exception'] = [
            'class'   => get_class($exception),
            'message' => $exception->getMessage(),
            'code'    => $exception->getCode(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ];
    }
    
    if (!empty($context)) {
        $entry['context'] = $context;
    }
    
    // Add user ID if available
    if (isset($_SESSION['user_id'])) {
        $entry['user_id'] = $_SESSION['user_id'];
    }
    
    $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Get a safe error message for the client.
 * In development, may include the real message. In production, always generic.
 */
function safeErrorMessage($defaultMessage = 'Something went wrong. Please try again.', $exception = null) {
    if (env('APP_DEBUG', false) && $exception) {
        return $defaultMessage . ' [Debug: ' . $exception->getMessage() . ']';
    }
    return $defaultMessage;
}

/**
 * Check if the application is in production mode.
 */
function isProduction() {
    return env('APP_ENV', 'production') === 'production';
}

/**
 * Check if debug mode is enabled.
 */
function isDebug() {
    return env('APP_DEBUG', false) === true;
}

// ─── Global Error & Exception Handlers (production) ───
if (isProduction()) {
    // Don't display errors to users in production
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    
    // Log errors to file
    ini_set('log_errors', '1');
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    ini_set('error_log', $logDir . '/php_errors_' . date('Y-m-d') . '.log');
}
?>
