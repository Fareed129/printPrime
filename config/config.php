<?php
/**
 * PrimePrint Global Configuration
 */

// Application Constants
define('APP_NAME', 'PrimePrint');
define('APP_VERSION', '1.0.0-phase3');

// Dynamic base URL detection (Reverse Proxy & Cloudflare Tunnel aware)
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
    (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'))
);

$protocol = $isHttps ? "https://" : "http://";
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
if (str_contains($host, ',')) {
    $host = trim(explode(',', $host)[0]);
}
define('APP_URL', rtrim($protocol . $host, '/'));

// Load .env configuration if present
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}

// Database Credentials (Protected in config)
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'primeprint_db');
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Razorpay Test Mode Credentials (Environment Driven)
define('RAZORPAY_KEY_ID', $_ENV['RAZORPAY_KEY_ID'] ?? getenv('RAZORPAY_KEY_ID') ?: 'rzp_test_sampleKey');
define('RAZORPAY_KEY_SECRET', $_ENV['RAZORPAY_KEY_SECRET'] ?? getenv('RAZORPAY_KEY_SECRET') ?: 'sampleSecret123456');
define('RAZORPAY_WEBHOOK_SECRET', $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? getenv('RAZORPAY_WEBHOOK_SECRET') ?: 'sampleWebhookSecret123456');

// File Upload Settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE_BYTES', 25 * 1024 * 1024); // 25 MB
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png']);
define('ALLOWED_MIME_TYPES', [
    'application/pdf',
    'image/jpeg',
    'image/pjpeg',
    'image/png',
    'image/x-png'
]);

// Allowed Print Job Statuses
define('JOB_STATUS_UPLOADED', 'UPLOADED');
define('JOB_STATUS_PAYMENT_PENDING', 'PAYMENT_PENDING');
define('JOB_STATUS_PAID', 'PAID');
define('JOB_STATUS_QUEUED', 'QUEUED');
define('JOB_STATUS_DOWNLOADING', 'DOWNLOADING');
define('JOB_STATUS_PRINTING', 'PRINTING');
define('JOB_STATUS_PRINTED', 'PRINTED');
define('JOB_STATUS_FAILED', 'FAILED');
define('JOB_STATUS_CANCELLED', 'CANCELLED');

// Session Setup
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}
