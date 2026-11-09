<?php
/**
 * DOCTOR CONNECT - Configuration File
 */

// Error Reporting (Turn off in strict production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'doctor_connect');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Application Constants
define('APP_NAME', 'DoctorConnect');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT_MINS', 30);
define('DEFAULT_JITSI_DOMAIN', 'meet.jit.si');
define('CANCELLATION_WINDOW_HOURS', 2);

// Session Security Configuration (Web requests only)
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    // 30 minutes session cookie lifetime
    ini_set('session.gc_maxlifetime', 1800);
    session_start();
}

// Auto-detect Base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
// Normalize baseUrl to project root
$parts = explode('/', trim($scriptDir, '/'));
$baseParts = [];
foreach ($parts as $p) {
    if (in_array($p, ['patient', 'doctor', 'admin', 'api', 'config', 'includes'])) {
        break;
    }
    if ($p !== '') {
        $baseParts[] = $p;
    }
}
$basePath = !empty($baseParts) ? '/' . implode('/', $baseParts) : '';
define('BASE_URL', rtrim($protocol . $host . $basePath, '/'));
