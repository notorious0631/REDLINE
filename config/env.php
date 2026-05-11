<?php
/**
 * REDLINE — Environment Variable Loader
 * Loads .env file and provides env() helper function.
 * Must be loaded before config/db.php
 */

function loadEnv($path) {
    if (!file_exists($path)) return;
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        // Parse KEY=VALUE
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        
        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        
        // Remove surrounding quotes
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[strlen($value)-1] === '"') ||
            ($value[0] === "'" && $value[strlen($value)-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        
        // Only set if not already defined (system env takes priority)
        if (!isset($_ENV[$key]) && getenv($key) === false) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Get an environment variable with an optional default.
 */
function env($key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    
    // Cast common string booleans
    $lower = strtolower($value);
    if ($lower === 'true') return true;
    if ($lower === 'false') return false;
    if ($lower === 'null') return null;
    
    return $value;
}

// Auto-load .env from project root
$envPath = __DIR__ . '/../.env';
loadEnv($envPath);
?>
