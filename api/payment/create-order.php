<?php
/**
 * PrimePrint API - Create Razorpay Order
 * Endpoint: POST /api/payment/create-order.php
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

// Support both JSON body and multipart/urlencoded POST data
$inputData = json_decode(file_get_contents('php://input'), true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

$token = trim($inputData['token'] ?? '');

if (empty($token) || strlen($token) > 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid public order token is required.']);
    exit;
}

try {
    $db = getDBConnection();

    // 1. Fetch Print Job & Shop Details
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.status AS shop_status 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        WHERE j.public_token = :token 
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Print order not found or token has expired.']);
        exit;
    }

    if ($job['shop_status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This printing shop is currently inactive.']);
        exit;
    }

    if ($job['payment_status'] === 'paid') {
        echo json_encode([
            'success'      => false,
            'is_paid'      => true,
            'redirect_url' => '/customer/order-success.php?token=' . urlencode($token),
            'message'      => 'This order has already been paid and queued.'
        ]);
        exit;
    }

    // 2. Authoritative Server-Side Price Verification
    $priceResult = calculate_order_price(
        $db, 
        (int)$job['shop_id'], 
        $job['paper_size'], 
        $job['color_mode'], 
        $job['side_mode'], 
        (int)$job['page_count'], 
        (int)$job['copies']
    );

    if (!$priceResult['success']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $priceResult['error']]);
        exit;
    }

    $authoritativeAmount = $priceResult['total_amount'];
    $amountPaise = (int)round($authoritativeAmount * 100);

    // Update job amount if it differed from database calculation
    if ((float)$job['amount'] !== $authoritativeAmount) {
        $stmt = $db->prepare("UPDATE print_jobs SET amount = :amt WHERE id = :id");
        $stmt->execute([':amt' => $authoritativeAmount, ':id' => $job['id']]);
    }

    // 3. Prevent Duplicate Razorpay Orders: Check for existing pending order unless fresh requested
    $forceNew = !empty($inputData['force_new']) || !empty($inputData['fresh']);
    $stmt = $db->prepare("
        SELECT razorpay_order_id, amount 
        FROM payments 
        WHERE job_id = :job_id AND status = 'created' 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':job_id' => $job['id']]);
    $existingPayment = $stmt->fetch();

    $razorpayOrderId = '';
    if (
        !$forceNew &&
        $existingPayment && 
        (float)$existingPayment['amount'] === $authoritativeAmount && 
        !empty($existingPayment['razorpay_order_id']) && 
        str_starts_with($existingPayment['razorpay_order_id'], 'order_')
    ) {
        $razorpayOrderId = $existingPayment['razorpay_order_id'];
    } else {

        // Create new Razorpay order
        $orderRes = razorpay_create_order($amountPaise, 'PP_' . $job['id'], [
            'public_token' => $job['public_token'],
            'shop_id'      => (string)$job['shop_id']
        ]);

        if (!$orderRes['success'] || empty($orderRes['order_id'])) {
            http_response_code(502);
            echo json_encode(['success' => false, 'error' => $orderRes['error'] ?? 'Failed to initialize payment gateway order.']);
            exit;
        }

        $razorpayOrderId = $orderRes['order_id'];

        // Record/Update Payment in Database
        $stmt = $db->prepare("
            INSERT INTO payments (job_id, shop_id, razorpay_order_id, amount, status)
            VALUES (:job_id, :shop_id, :order_id, :amount, 'created')
            ON DUPLICATE KEY UPDATE razorpay_order_id = VALUES(razorpay_order_id), amount = VALUES(amount), status = 'created'
        ");
        $stmt->execute([
            ':job_id'   => $job['id'],
            ':shop_id'  => $job['shop_id'],
            ':order_id' => $razorpayOrderId,
            ':amount'   => $authoritativeAmount
        ]);
    }

    log_payment_event('create_order_endpoint_success', [
        'public_token' => $token,
        'order_id'     => $razorpayOrderId,
        'amount_paise' => $amountPaise
    ]);

    // Return safe checkout parameters (NO SECRETS)
    echo json_encode([
        'success'   => true,
        'key_id'    => RAZORPAY_KEY_ID,
        'order_id'  => $razorpayOrderId,
        'amount'    => $amountPaise,
        'currency'  => 'INR',
        'shop_name' => $job['shop_name'],
        'token'     => $job['public_token'],
        'job_id'    => (int)$job['id']
    ]);

} catch (Exception $e) {
    log_payment_event('create_order_endpoint_exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred while preparing your payment.']);
}
