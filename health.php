<?php
/**
 * PrimePrint Health Check Endpoint
 * Path: GET /health.php or GET /health
 * Returns JSON status for monitoring and uptime verification.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$response = [
    'status'      => 'ok',
    'app'         => APP_NAME,
    'version'     => APP_VERSION,
    'environment' => defined('APP_ENV') ? APP_ENV : 'production',
    'timestamp'   => date('c')
];

$dbStatus = 'connected';
try {
    $db = getDBConnection();
    $stmt = $db->query("SELECT 1");
    if (!$stmt) {
        $dbStatus = 'error';
    }
} catch (Throwable $e) {
    $dbStatus = 'disconnected';
    $response['status'] = 'degraded';
}

$response['database'] = $dbStatus;

if ($response['status'] === 'degraded') {
    http_response_code(503);
} else {
    http_response_code(200);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
