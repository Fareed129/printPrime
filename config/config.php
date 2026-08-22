<?php
/**
 * PrimePrint Global Configuration
 */

// Application Constants
define('APP_NAME', 'PrimePrint');
define('APP_VERSION', '1.0.0-phase1');

// Dynamic base URL detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
define('APP_URL', $protocol . $host);

// Database Credentials (Protected in config)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'primeprint_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

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
