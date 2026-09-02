<?php
/**
 * PrimePrint API — Real-time Customer Order Status Check
 * Endpoint: GET /api/customer/order-status.php?token=PP-XXXX-XXXX
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

$token = trim($_GET['token'] ?? '');
if (empty($token) || strlen($token) > 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid order token.']);
    exit;
}

try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT j.id, j.public_token, j.shop_id, j.file_name, j.page_count, j.copies, 
               j.paper_size, j.color_mode, j.amount, j.status, j.payment_status, 
               j.payment_method, j.cash_approved_at, j.cash_rejected_at, j.cash_rejection_reason,
               j.created_at, s.name AS shop_name, s.phone AS shop_phone
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

    $isPaid = in_array($job['payment_status'], ['paid', 'completed'], true);
    $isCashPending = ($job['payment_method'] === 'CASH' && $job['payment_status'] === 'pending_cash');
    $isCashRejected = ($job['status'] === 'REJECTED' || $job['payment_status'] === 'rejected');

    echo json_encode([
        'success'         => true,
        'token'           => $job['public_token'],
        'status'          => $job['status'],
        'payment_status'  => $job['payment_status'],
        'payment_method'  => $job['payment_method'] ?? 'ONLINE',
        'is_paid'         => $isPaid,
        'is_cash_pending' => $isCashPending,
        'is_rejected'     => $isCashRejected,
        'rejection_reason'=> $job['cash_rejection_reason'],
        'shop_name'       => $job['shop_name'],
        'amount_formatted'=> format_currency($job['amount'])
    ]);

} catch (Throwable $e) {
    error_log("Order status check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to check order status.']);
}
