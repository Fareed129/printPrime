<?php
/**
 * PrimePrint API - Verify Razorpay Subscription Payment & Extend License
 * Endpoint: POST /api/payment/verify-subscription.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/razorpay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. POST required.']);
    exit;
}

if (!is_logged_in() || current_user()['role'] !== 'shop') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Shop login required.']);
    exit;
}

$user = current_user();
$shopId = (int)($user['shop_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$orderId   = trim($input['razorpay_order_id'] ?? '');
$paymentId = trim($input['razorpay_payment_id'] ?? '');
$signature = trim($input['razorpay_signature'] ?? '');
$planId    = (int)($input['plan_id'] ?? 1);

if (empty($orderId) || empty($paymentId) || empty($signature)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Incomplete payment verification payload.']);
    exit;
}

// 1. Verify Razorpay Signature
$expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    log_payment_event('subscription_signature_mismatch', [
        'shop_id'    => $shopId,
        'order_id'   => $orderId,
        'payment_id' => $paymentId
    ], 'ERROR');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment signature verification failed.']);
    exit;
}

try {
    $db = getDBConnection();

    // 2. Fetch Shop & Plan
    $stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $shopId]);
    $shop = $stmt->fetch();

    if (!$shop) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Shop not found.']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $planId]);
    $plan = $stmt->fetch();

    $durationMonths = $plan ? (int)$plan['duration_months'] : 3;
    $amount = $plan ? (float)$plan['price'] : 1499.00;
    $planName = $plan ? $plan['name'] : '3-Month Quarterly Pro';

    // 3. Calculate New Expiry Date
    $currentExpiry = $shop['subscription_expires_at'];
    $baseTime = (!empty($currentExpiry) && strtotime($currentExpiry) > time()) 
        ? strtotime($currentExpiry) 
        : time();

    $daysToAdd = $durationMonths * 30; // 90 days for 3-month plan
    $newExpiry = date('Y-m-d 23:59:59', strtotime("+{$daysToAdd} days", $baseTime));

    // 4. Update Shop Record
    $stmt = $db->prepare("
        UPDATE shops 
        SET plan_id = :plan_id,
            plan_name = :plan_name,
            subscription_status = 'active',
            subscription_expires_at = :expires_at,
            setup_fee_paid = 1
        WHERE id = :id
    ");
    $stmt->execute([
        ':plan_id'   => $planId,
        ':plan_name' => $planName,
        ':expires_at'=> $newExpiry,
        ':id'        => $shopId
    ]);

    // 5. Record Transaction in Ledger
    $stmt = $db->prepare("
        INSERT INTO shop_subscriptions (shop_id, plan_id, amount, payment_method, razorpay_payment_id, razorpay_order_id, starts_at, expires_at, notes)
        VALUES (:shop_id, :plan_id, :amount, 'razorpay', :pay_id, :order_id, :starts_at, :expires_at, :notes)
    ");
    $stmt->execute([
        ':shop_id'   => $shopId,
        ':plan_id'   => $planId,
        ':amount'    => $amount,
        ':pay_id'    => $paymentId,
        ':order_id'  => $orderId,
        ':starts_at' => date('Y-m-d H:i:s', $baseTime),
        ':expires_at'=> $newExpiry,
        ':notes'     => "Self-service renewal via Razorpay ({$planName})"
    ]);

    log_payment_event('subscription_renewed_success', [
        'shop_id'     => $shopId,
        'shop_name'   => $shop['name'],
        'payment_id'  => $paymentId,
        'order_id'    => $orderId,
        'amount'      => $amount,
        'new_expires' => $newExpiry
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => "Your PrimePrint license has been successfully extended until " . date('d M Y', strtotime($newExpiry)) . "!",
        'expires_at'   => $newExpiry,
        'redirect_url' => APP_URL . '/shop/subscription.php?renewed=1'
    ]);

} catch (Exception $e) {
    log_payment_event('subscription_verify_exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error confirming license extension.']);
}
