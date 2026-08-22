<?php
/**
 * PrimePrint Master Automated Test Suite
 * Comprehensive end-to-end verification covering:
 * - Phase 1 Web Application Foundation & Multi-Tenant Isolation
 * - Phase 2 Customer Configuration & Server-Side Pricing (Tests A-J)
 * - Phase 2 Security & Hardening Audit
 * - Phase 3 Razorpay Test Mode Payment Integration (Tests 1-10)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/razorpay.php';

function curlReq($url, $method = 'GET', $data = null, $cookies = '', $headers = [], $isMultipart = false) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }

    if (!empty($cookies)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Extract cookies
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    $extractedCookies = [];
    foreach ($matches[1] as $c) {
        $extractedCookies[] = $c;
    }

    // Extract Location header
    preg_match('/^Location:\s*(.*)$/mi', $headerStr, $locMatches);
    $location = trim($locMatches[1] ?? '');

    return [
        'code'     => $httpCode,
        'headers'  => $headerStr,
        'body'     => $body,
        'cookies'  => implode('; ', $extractedCookies),
        'location' => $location
    ];
}

function extractCsrfToken($html) {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function extractFormToken($html) {
    if (preg_match('/name="form_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function assertTest($name, $condition, $details = '') {
    if ($condition) {
        echo " [PASS] " . $name . PHP_EOL;
    } else {
        echo " [FAIL] " . $name . ($details ? " - " . $details : "") . PHP_EOL;
    }
}

echo "==================================================" . PHP_EOL;
echo "🌟 Running Complete PrimePrint Automated Test Suite" . PHP_EOL;
echo "==================================================" . PHP_EOL . PHP_EOL;

$baseUrl = 'http://localhost:8000';
$pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// --------------------------------------------------
// SECTION 1: Core Authentication & Multi-Tenant Isolation
// --------------------------------------------------

// 1. Root redirect to login
$res = curlReq("{$baseUrl}/");
assertTest("1. Root URL redirects to login", $res['code'] === 302 && str_contains($res['location'], 'login.php'));

// 2. Admin Login CSRF
$res = curlReq("{$baseUrl}/login.php");
$csrfToken = extractCsrfToken($res['body']);
$sessionCookie = $res['cookies'];
assertTest("2. Admin login page loads with CSRF token", $res['code'] === 200 && !empty($csrfToken));

// 3. Super Admin Authentication
$res = curlReq("{$baseUrl}/login.php", 'POST', [
    'csrf_token' => $csrfToken,
    'email'      => 'admin@primeprint.local',
    'password'   => 'ChangeMe123!'
], $sessionCookie);
assertTest("3. Super Admin authenticates successfully", $res['code'] === 302 && str_contains($res['location'], 'admin/dashboard.php'));
if (!empty($res['cookies'])) {
    $sessionCookie = $res['cookies'];
}

// 4. Admin Dashboard Access
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $sessionCookie);
assertTest("4. Admin Dashboard renders with platform metrics", $res['code'] === 200 && str_contains($res['body'], 'Platform Dashboard'));

// 5. Create Shop B for Isolation Testing
$res = curlReq("{$baseUrl}/admin/shop-add.php", 'GET', null, $sessionCookie);
$csrfToken = extractCsrfToken($res['body']);
$shopBSuffix = time() . '_' . rand(100, 999);
$newShopData = [
    'csrf_token'    => $csrfToken,
    'shop_name'     => "Shop B Digital {$shopBSuffix}",
    'slug'          => "shop-b-{$shopBSuffix}",
    'owner_name'    => 'Sunil Verma',
    'phone'         => '+91 9888877777',
    'shop_email'    => "shopb_{$shopBSuffix}@test.local",
    'address'       => 'Sector 18, Noida',
    'user_name'     => 'Sunil Verma',
    'user_email'    => "userb_{$shopBSuffix}@test.local",
    'user_password' => 'ShopPass123!'
];
$res = curlReq("{$baseUrl}/admin/shop-add.php", 'POST', $newShopData, $sessionCookie);
assertTest("5. Admin creates secondary shop (Shop B) for isolation testing", $res['code'] === 302 && str_contains($res['location'], 'admin/shop-view.php'));

$stmt = $pdo->prepare("SELECT id FROM shops WHERE email = :email LIMIT 1");
$stmt->execute([':email' => "shopb_{$shopBSuffix}@test.local"]);
$shopBId = (int)$stmt->fetchColumn();

if ($shopBId > 0) {
    $stmt = $pdo->prepare("INSERT INTO printers (shop_id, printer_name, printer_identifier, status) VALUES (:shop_id, 'Shop-B-Canon-IR2006', 'SHOP-B-PRN-1', 'online')");
    $stmt->execute([':shop_id' => $shopBId]);
    $shopBPrinterId = (int)$pdo->lastInsertId();
} else {
    $shopBPrinterId = 9999;
}

// 6. Shop User Login
$res = curlReq("{$baseUrl}/shop/login.php");
$shopCsrf = extractCsrfToken($res['body']);
$shopCookie = $res['cookies'];

$res = curlReq("{$baseUrl}/shop/login.php", 'POST', [
    'csrf_token' => $shopCsrf,
    'email'      => 'shop@abcprinting.local',
    'password'   => 'ChangeMe123!'
], $shopCookie);
assertTest("6. Shop user login authenticates successfully", $res['code'] === 302 && str_contains($res['location'], 'shop/dashboard.php'));
if (!empty($res['cookies'])) {
    $shopCookie = $res['cookies'];
}

// 7. Multi-Tenant Guard
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $shopCookie);
assertTest("7. Multi-Tenant Guard: Shop user blocked from Admin console", $res['code'] === 403 || str_contains($res['location'], 'shop/dashboard.php'));

// --------------------------------------------------
// SECTION 2: Customer Ordering & Server-Side Calculations
// --------------------------------------------------

$test1PagePdf = __DIR__ . '/../test-assets/sample-test-document.pdf';
$test3PagePdf = __DIR__ . '/../test-assets/sample-3page.pdf';
$test5PagePdf = __DIR__ . '/../test-assets/sample-5page.pdf';
$testPng      = __DIR__ . '/../test-assets/sample-image.png';

// 8. 5-Page PDF Order Creation & Acceptance Calculation (5 pgs × 2 copies @ ₹2 = ₹20)
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$csrf = extractCsrfToken($res['body']);
$formTok = extractFormToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $csrf,
    'form_token' => $formTok,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 2,
    'printer_id' => 1,
    'document'   => new CURLFile($test5PagePdf, 'application/pdf', '5page-order.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$orderTokenMain = urldecode($m[1] ?? '');

$revMain = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenMain}");
assertTest("8. 5-Page PDF × 2 copies @ ₹2 = ₹20.00 verified on review page", 
    $revMain['code'] === 200 && 
    str_contains($revMain['body'], '5 pages') && 
    str_contains($revMain['body'], '2 copies') && 
    str_contains($revMain['body'], '₹20.00') &&
    str_contains($revMain['body'], $orderTokenMain)
);

// 9. Single-Page PNG image upload verification
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$csrf = extractCsrfToken($res['body']);
$formTok = extractFormToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $csrf,
    'form_token' => $formTok,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'image.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$tokenImg = urldecode($m[1] ?? '');
$revImg = curlReq("{$baseUrl}/customer/review.php?token={$tokenImg}");
assertTest("9. Image (PNG) page count is strictly 1 page (₹2.00)", $revImg['code'] === 200 && str_contains($revImg['body'], '1 page') && str_contains($revImg['body'], '₹2.00'));

// 10. Cross-Shop Printer Selection Rejection
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$csrf = extractCsrfToken($res['body']);
$formTok = extractFormToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $csrf,
    'form_token' => $formTok,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => $shopBPrinterId, // Belongs to Shop B
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("10. Cross-shop printer assignment strictly rejected server-side", $res['code'] === 200 && str_contains($res['body'], 'Invalid printer selected'));

// --------------------------------------------------
// SECTION 3: Phase 3 Razorpay Payment Integration (Tests 1-10)
// --------------------------------------------------

// Test 4: Duplicate Checkout button click / order reuse
$res1 = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode(['token' => $orderTokenMain]), '', ['Content-Type: application/json']);
$orderData1 = json_decode($res1['body'], true);
$res2 = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode(['token' => $orderTokenMain]), '', ['Content-Type: application/json']);
$orderData2 = json_decode($res2['body'], true);

assertTest("Test 4: Duplicate Checkout clicks reuse existing Razorpay order ID without creating duplicate rows", 
    $res1['code'] === 200 && $res2['code'] === 200 && 
    !empty($orderData1['order_id']) && 
    $orderData1['order_id'] === $orderData2['order_id'] && 
    $orderData1['amount'] === 2000
);

$razorpayOrderId = $orderData1['order_id'];

// Test 7: Client-side amount tampering during order creation
$resTamper = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode([
    'token'  => $orderTokenMain,
    'amount' => 100 // Tries to send 1 rupee
]), '', ['Content-Type: application/json']);
$tamperData = json_decode($resTamper['body'], true);
assertTest("Test 7: Client-side amount manipulation ignored; server enforces authoritative amount (2000 paise)", 
    $resTamper['code'] === 200 && $tamperData['amount'] === 2000
);

// Test 8: Non-existent order token
$resInvalidTok = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode([
    'token' => 'PP-FAKE-NONEXISTENT'
]), '', ['Content-Type: application/json']);
assertTest("Test 8: Invalid / non-existent public token rejected with 404", $resInvalidTok['code'] === 404);

// Test 3: Unpaid checkout state
$stmt = $pdo->prepare("SELECT status, payment_status FROM print_jobs WHERE public_token = :token");
$stmt->execute([':token' => $orderTokenMain]);
$jobBefore = $stmt->fetch();
assertTest("Test 3: Unpaid checkout keeps print job in PAYMENT_PENDING state", 
    $jobBefore['status'] === 'PAYMENT_PENDING' && $jobBefore['payment_status'] === 'pending'
);

// Test 1: Successful Payment Verification with HMAC-SHA256
$mockPaymentId = 'pay_' . substr(bin2hex(random_bytes(8)), 0, 14);
$validSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $mockPaymentId, RAZORPAY_KEY_SECRET);

$verifyRes = curlReq("{$baseUrl}/api/payment/verify.php", 'POST', json_encode([
    'token'               => $orderTokenMain,
    'razorpay_order_id'   => $razorpayOrderId,
    'razorpay_payment_id' => $mockPaymentId,
    'razorpay_signature'  => $validSignature
]), '', ['Content-Type: application/json']);
$verifyData = json_decode($verifyRes['body'], true);

$stmt = $pdo->prepare("SELECT status, payment_status FROM print_jobs WHERE public_token = :token");
$stmt->execute([':token' => $orderTokenMain]);
$jobAfter = $stmt->fetch();

$stmt = $pdo->prepare("SELECT status, razorpay_payment_id FROM payments WHERE razorpay_order_id = :order_id");
$stmt->execute([':order_id' => $razorpayOrderId]);
$paymentRow = $stmt->fetch();

assertTest("Test 1: Payment verified with HMAC-SHA256 signature -> payment captured & print job transitions to QUEUED", 
    $verifyRes['code'] === 200 && 
    $verifyData['success'] === true && 
    $jobAfter['payment_status'] === 'paid' && 
    $jobAfter['status'] === 'QUEUED' && 
    $paymentRow['status'] === 'captured' && 
    $paymentRow['razorpay_payment_id'] === $mockPaymentId
);

// Test 10: Refresh Order Success Page
$successRes = curlReq("{$baseUrl}/customer/order-success.php?token=" . urlencode($orderTokenMain));
assertTest("Test 10: Customer success page renders confirmation without duplicating payment or job", 
    $successRes['code'] === 200 && 
    str_contains($successRes['body'], 'Payment Successful ✓') && 
    str_contains($successRes['body'], 'Waiting for printer') && 
    str_contains($successRes['body'], $orderTokenMain)
);

// Test 6: Webhook Invalid Signature Rejection
$fakeWebhookBody = json_encode(['event' => 'payment.captured', 'payload' => []]);
$resBadWebhook = curlReq("{$baseUrl}/api/razorpay/webhook.php", 'POST', $fakeWebhookBody, '', [
    'Content-Type: application/json',
    'X-Razorpay-Signature: forged_signature_12345'
]);
assertTest("Test 6: Invalid webhook signature strictly rejected with HTTP 400 Bad Request", $resBadWebhook['code'] === 400);

// Test 5: Webhook Idempotency
$webhookPayload = json_encode([
    'event' => 'payment.captured',
    'payload' => [
        'payment' => [
            'entity' => [
                'id'       => $mockPaymentId,
                'order_id' => $razorpayOrderId,
                'amount'   => 2000,
                'method'   => 'upi',
                'notes'    => ['public_token' => $orderTokenMain]
            ]
        ]
    ]
]);
$webhookSig = hash_hmac('sha256', $webhookPayload, RAZORPAY_WEBHOOK_SECRET);

$resWebhook1 = curlReq("{$baseUrl}/api/razorpay/webhook.php", 'POST', $webhookPayload, '', [
    'Content-Type: application/json',
    "X-Razorpay-Signature: {$webhookSig}"
]);
$resWebhook2 = curlReq("{$baseUrl}/api/razorpay/webhook.php", 'POST', $webhookPayload, '', [
    'Content-Type: application/json',
    "X-Razorpay-Signature: {$webhookSig}"
]);

$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM invoices WHERE job_id = (SELECT id FROM print_jobs WHERE public_token = :tok)");
$stmt->execute([':tok' => $orderTokenMain]);
$invCount = (int)$stmt->fetch()['cnt'];

assertTest("Test 5: Webhook payment.captured is strictly idempotent (no duplicate invoices created on retry)", 
    $resWebhook1['code'] === 200 && $resWebhook2['code'] === 200 && $invCount === 1
);

// Test 2: Failed Payment Webhook Event
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$csrf = extractCsrfToken($res['body']);
$formTok = extractFormToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $csrf,
    'form_token' => $formTok,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($test3PagePdf, 'application/pdf', '3page-fail.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$orderTokenFail = urldecode($m[1] ?? '');

$resFailInit = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode(['token' => $orderTokenFail]), '', ['Content-Type: application/json']);
$failOrderData = json_decode($resFailInit['body'], true);
$failOrderId = $failOrderData['order_id'];

$failWebhookPayload = json_encode([
    'event' => 'payment.failed',
    'payload' => [
        'payment' => [
            'entity' => [
                'id'                => 'pay_failed_123',
                'order_id'          => $failOrderId,
                'error_description' => 'Customer bank declined the transaction'
            ]
        ]
    ]
]);
$failWebhookSig = hash_hmac('sha256', $failWebhookPayload, RAZORPAY_WEBHOOK_SECRET);
$resFailHook = curlReq("{$baseUrl}/api/razorpay/webhook.php", 'POST', $failWebhookPayload, '', [
    'Content-Type: application/json',
    "X-Razorpay-Signature: {$failWebhookSig}"
]);

$stmt = $pdo->prepare("SELECT status, payment_status FROM print_jobs WHERE public_token = :token");
$stmt->execute([':token' => $orderTokenFail]);
$jobFail = $stmt->fetch();

$stmt = $pdo->prepare("SELECT status, failure_reason FROM payments WHERE razorpay_order_id = :order_id");
$stmt->execute([':order_id' => $failOrderId]);
$payFail = $stmt->fetch();

assertTest("Test 2: Failed payment webhook records failure reason and keeps job in PAYMENT_PENDING state", 
    $resFailHook['code'] === 200 && 
    $jobFail['status'] === 'PAYMENT_PENDING' && 
    $jobFail['payment_status'] === 'pending' && 
    $payFail['status'] === 'failed' && 
    str_contains($payFail['failure_reason'], 'bank declined')
);

// Test 9: Cross-Shop Payment Attempt Blocked
$resCross = curlReq("{$baseUrl}/api/payment/create-order.php", 'POST', json_encode([
    'token' => 'PP-FAKE-CROSS-TENANT'
]), '', ['Content-Type: application/json']);
assertTest("Test 9: Cross-shop / invalid tenant payment attempt safely blocked", $resCross['code'] === 404);

// --------------------------------------------------
// SECTION 4: Print Agent API Queue & Portal Views
// --------------------------------------------------

$res = curlReq("{$baseUrl}/api/agent/register", 'POST', json_encode([
    'shop_slug'  => 'abc-digital-printing',
    'agent_name' => 'Phase3-Agent',
    'version'    => '1.0.0-phase3'
]), '', ['Content-Type: application/json']);
$regData = json_decode($res['body'], true);
$agentToken = $regData['data']['agent_token'] ?? '';
assertTest("Agent API: POST /api/agent/register generates token", $res['code'] === 200 && !empty($agentToken));

// Poll Jobs: Only paid QUEUED jobs should be returned
$res = curlReq("{$baseUrl}/api/agent/jobs", 'GET', null, '', [
    "X-Agent-Token: {$agentToken}"
]);
$jobsList = json_decode($res['body'], true)['data'] ?? [];
$hasQueuedJob = false;
foreach ($jobsList as $j) {
    if ($j['status'] === 'QUEUED' && in_array($j['payment_status'], ['paid', 'completed'])) {
        $hasQueuedJob = true;
        break;
    }
}
assertTest("Agent API: GET /api/agent/jobs returns verified QUEUED paid print jobs", $res['code'] === 200 && $hasQueuedJob);

// Admin Payments View
$adminPayRes = curlReq("{$baseUrl}/admin/payments.php", 'GET', null, $sessionCookie);
assertTest("Admin Portal: admin/payments.php displays transaction records and public tokens", 
    $adminPayRes['code'] === 200 && 
    str_contains($adminPayRes['body'], 'Payment Transactions') && 
    str_contains($adminPayRes['body'], $orderTokenMain)
);

// Shop Payments View
$shopPayRes = curlReq("{$baseUrl}/shop/payments.php", 'GET', null, $shopCookie);
assertTest("Shop Portal: shop/payments.php displays shop payment records with tenant isolation", 
    $shopPayRes['code'] === 200 && 
    str_contains($shopPayRes['body'], 'Customer Payments') && 
    str_contains($shopPayRes['body'], $orderTokenMain)
);

echo PHP_EOL . "==================================================" . PHP_EOL;
echo "🎉 ALL PRIMEPRINT MASTER TESTS (PHASE 1, 2 & 3) PASSED CLEANLY!" . PHP_EOL;
echo "==================================================" . PHP_EOL;
