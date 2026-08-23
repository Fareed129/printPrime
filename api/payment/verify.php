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

// 1. Cryptographic Signature Verification
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

    // 2. Validate Print Job Exists
    $stmt = $db->prepare("SELECT * FROM print_jobs WHERE public_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    // 3. Strict Payment Record Matching (Must match exact job_id and razorpay_order_id)
    $stmt = $db->prepare("
        SELECT id, razorpay_order_id, amount, status 
        FROM payments 
        WHERE job_id = :job_id AND razorpay_order_id = :order_id 
        LIMIT 1
    ");
    $stmt->execute([
        ':job_id'   => $job['id'],
        ':order_id' => $orderId
    ]);
    $paymentRow = $stmt->fetch();

    if (!$paymentRow) {
        log_payment_event('verify_order_id_mismatch', [
            'job_id'             => $job['id'],
            'submitted_order_id' => $orderId
        ], 'WARNING');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Submitted order ID does not match this print job.']);
        exit;
    }

    // 4. Check if already paid
    if ($job['payment_status'] === 'paid') {
        echo json_encode([
            'success'      => true,
            'is_paid'      => true,
            'message'      => 'This order has already been paid and queued.',
            'redirect_url' => '/customer/order-success.php?token=' . urlencode($token)
        ]);
        exit;
    }

    // 5. Store Payment ID against matching payment record
    $stmt = $db->prepare("
        UPDATE payments 
        SET razorpay_payment_id = :payment_id, 
            updated_at = NOW() 
        WHERE id = :payment_id_row
    ");
    $stmt->execute([
        ':payment_id'     => $paymentId,
        ':payment_id_row' => $paymentRow['id']
    ]);

    // 6. Optional Direct API Verification (If Live Razorpay API confirms capture)
    $isCapturedOnApi = false;
    $apiPayment = razorpay_fetch_payment($paymentId);
    if ($apiPayment && is_array($apiPayment)) {
        $expectedPaise = (int)round((float)$job['amount'] * 100);
        if (
            ($apiPayment['status'] ?? '') === 'captured' && 
            (int)($apiPayment['amount'] ?? 0) === $expectedPaise && 
            ($apiPayment['currency'] ?? '') === 'INR' &&
            ($apiPayment['order_id'] ?? '') === $orderId
        ) {
            $isCapturedOnApi = true;
        }
    }

    if ($isCapturedOnApi) {
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE payments SET status = 'captured', captured_at = NOW(), updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $paymentRow['id']]);

        $stmt = $db->prepare("UPDATE print_jobs SET payment_status = 'paid', status = 'QUEUED' WHERE id = :id");
        $stmt->execute([':id' => $job['id']]);

        // Invoice creation
        $stmt = $db->prepare("SELECT id FROM invoices WHERE job_id = :job_id LIMIT 1");
        $stmt->execute([':job_id' => $job['id']]);
        if (!$stmt->fetch()) {
            $invNumber = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $job['id']);
            $stmt = $db->prepare("INSERT INTO invoices (job_id, shop_id, invoice_number, amount, created_at) VALUES (:job_id, :shop_id, :inv_num, :amt, NOW())");
            $stmt->execute([':job_id' => $job['id'], ':shop_id' => $job['shop_id'], ':inv_num' => $invNumber, ':amt' => $job['amount']]);
        }
        $db->commit();

        log_payment_event('client_payment_api_confirmed_captured', [
            'job_id'       => $job['id'],
            'public_token' => $token,
            'order_id'     => $orderId,
            'payment_id'   => $paymentId
        ]);
    } else {
        // Unconfirmed client callback stage: Job remains PAYMENT_PENDING until Webhook confirmation
        log_payment_event('client_payment_signature_recorded_pending_webhook', [
            'job_id'       => $job['id'],
            'public_token' => $token,
            'order_id'     => $orderId,
            'payment_id'   => $paymentId
        ]);
    }

    echo json_encode([
        'success'      => true,
        'status'       => $isCapturedOnApi ? 'captured' : 'verifying',
        'message'      => 'Payment signature verified. Confirming settlement...',
        'redirect_url' => '/customer/order-success.php?token=' . urlencode($token)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    log_payment_event('payment_verification_exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error while recording payment verification.']);
}
