<?php
/**
 * PrimePrint API - Create Razorpay Order for Shop Subscription Renewal
 * Endpoint: POST /api/payment/create-subscription-order.php
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
$planId = (int)($input['plan_id'] ?? 0);

try {
    $db = getDBConnection();

    // 1. Fetch Shop
    $stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $shopId]);
    $shop = $stmt->fetch();

    if (!$shop) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Shop record not found.']);
        exit;
    }

    // 2. Fetch Subscription Plan
    if ($planId > 0) {
        $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $planId]);
        $plan = $stmt->fetch();
    } else {
        $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE is_default = 1 AND status = 'active' LIMIT 1");
        $stmt->execute();
        $plan = $stmt->fetch();
        if (!$plan) {
            $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC LIMIT 1");
            $stmt->execute();
            $plan = $stmt->fetch();
        }
    }

    if (!$plan) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'No active subscription plan found.']);
        exit;
    }

    $amount = (float)$plan['price'];
    $amountPaise = (int)round($amount * 100);
    $receipt = 'SUB_' . $shop['id'] . '_' . time();

    // 3. Create Razorpay Order
    $orderRes = razorpay_create_order($amountPaise, $receipt, [
        'shop_id'     => (string)$shop['id'],
        'shop_slug'   => $shop['slug'],
        'plan_id'     => (string)$plan['id'],
        'plan_name'   => $plan['name'],
        'type'        => 'saas_subscription_renewal'
    ]);

    if (!$orderRes['success'] || empty($orderRes['order_id'])) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $orderRes['error'] ?? 'Failed to initialize subscription order on payment gateway.']);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'key_id'     => RAZORPAY_KEY_ID,
        'order_id'   => $orderRes['order_id'],
        'amount'     => $amountPaise,
        'currency'   => 'INR',
        'plan_id'    => (int)$plan['id'],
        'plan_name'  => $plan['name'],
        'shop_name'  => $shop['name'],
        'shop_phone' => $shop['phone'],
        'shop_email' => $shop['email']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error initializing subscription order: ' . $e->getMessage()]);
}
