<?php
/**
 * PrimePrint API - Verify Razorpay Payment Signature
 * Endpoint: POST /api/payment/verify.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/razorpay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. POST required.']);
    exit;
}

$inputData = json_decode(file_get_contents('php://input'), true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

$token      = trim($inputData['token'] ?? '');
$paymentId  = trim($inputData['razorpay_payment_id'] ?? '');
$orderId    = trim($inputData['razorpay_order_id'] ?? '');
$signature  = trim($inputData['razorpay_signature'] ?? '');

if (empty($token) || empty($paymentId) || empty($orderId) || empty($signature)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required payment verification parameters.']);
    exit;
}

// Cryptographic Signature Verification
$isValidSignature = razorpay_verify_payment_signature($orderId, $paymentId, $signature);
if (!$isValidSignature) {
    log_payment_event('client_payment_signature_failed', [
        'public_token' => $token,
        'order_id'     => $orderId,
        'payment_id'   => $paymentId
    ], 'WARNING');

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment verification failed: Invalid digital signature.']);
    exit;
}

try {
    $db = getDBConnection();

    // 1. Fetch Print Job
    $stmt = $db->prepare("SELECT * FROM print_jobs WHERE public_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    $db->beginTransaction();

    // 2. Update Payment Record to Captured
    $stmt = $db->prepare("
        UPDATE payments 
        SET razorpay_payment_id = :payment_id, 
            status = 'captured', 
            captured_at = NOW(),
            updated_at = NOW() 
        WHERE job_id = :job_id AND (razorpay_order_id = :order_id OR status = 'created')
    ");
    $stmt->execute([
        ':payment_id' => $paymentId,
        ':job_id'     => $job['id'],
        ':order_id'   => $orderId
    ]);

    // 3. Securely Transition Print Job to PAID and QUEUED
    $stmt = $db->prepare("
        UPDATE print_jobs 
        SET payment_status = 'paid', 
            status = 'QUEUED' 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $job['id']]);

    // 4. Generate Invoice Record if not exists
    $stmt = $db->prepare("SELECT id FROM invoices WHERE job_id = :job_id LIMIT 1");
    $stmt->execute([':job_id' => $job['id']]);
    if (!$stmt->fetch()) {
        $invNumber = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $job['id']);
        $stmt = $db->prepare("
            INSERT INTO invoices (job_id, shop_id, invoice_number, amount, created_at)
            VALUES (:job_id, :shop_id, :inv_num, :amt, NOW())
        ");
        $stmt->execute([
            ':job_id'   => $job['id'],
            ':shop_id'  => $job['shop_id'],
            ':inv_num'  => $invNumber,
            ':amt'      => $job['amount']
        ]);
    }

    $db->commit();

    log_payment_event('client_payment_verified_and_queued', [
        'job_id'       => $job['id'],
        'public_token' => $token,
        'order_id'     => $orderId,
        'payment_id'   => $paymentId,
        'amount'       => $job['amount']
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => 'Payment verified and print job queued successfully.',
        'redirect_url' => APP_URL . '/customer/order-success.php?token=' . urlencode($token)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    log_payment_event('payment_verification_exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error while finalizing payment verification.']);
}
