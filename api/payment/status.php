<?php
/**
 * PrimePrint API - Check Public Order & Payment Status
 * Endpoint: GET /api/payment/status.php?token={public_token}
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. GET required.']);
    exit;
}

$token = trim($_GET['token'] ?? '');

if (empty($token) || strlen($token) > 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid public order token is required.']);
    exit;
}

try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT j.public_token, j.payment_status, j.status AS job_status, j.amount, s.name AS shop_name 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        WHERE j.public_token = :token 
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    $isConfirmed = ($job['payment_status'] === 'paid' && in_array($job['job_status'], ['QUEUED', 'DOWNLOADING', 'PRINTING', 'PRINTED']));

    echo json_encode([
        'success'        => true,
        'token'          => $job['public_token'],
        'payment_status' => $job['payment_status'],
        'job_status'     => $job['job_status'],
        'amount'         => (float)$job['amount'],
        'shop_name'      => $job['shop_name'],
        'is_confirmed'   => $isConfirmed
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error retrieving order status.']);
}
